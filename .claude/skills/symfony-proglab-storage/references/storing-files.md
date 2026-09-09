# Stocker un fichier

Configurer Flysystem, nommer ce qui va sur le disque, ce que détient l'entité,
supprimer sans laisser d'orphelins, et tester tout cela sans toucher au stockage réel.
Vérifié avec `league/flysystem` 3.35.3, `league/flysystem-bundle` 3.7.1 et
`league/flysystem-async-aws-s3` 3.31.0 aux côtés de Symfony 8.1.5 / PHP 8.4 —
Flysystem suit son propre versionnage, pas celui de Symfony. **`vendor/` fait foi,
davantage que ce fichier.**

## Configuration : un stockage par usage

```yaml
# config/packages/flysystem.yaml
flysystem:
    storages:
        book_covers.storage:
            public_url: '%env(COVERS_PUBLIC_URL)%'
            local:
                directory: '%kernel.project_dir%/public/uploads/covers'

        reading_exports.storage:
            visibility: private
            local:
                directory: '%kernel.project_dir%/var/storage/exports'

when@prod:
    flysystem:
        storages:
            book_covers.storage:
                asyncaws: { client: 'app.s3_client', bucket: '%env(S3_COVERS_BUCKET)%' }
            reading_exports.storage:
                visibility: private
                asyncaws: { client: 'app.s3_client', bucket: '%env(S3_EXPORTS_BUCKET)%', prefix: 'exports' }

when@test:
    flysystem:
        storages:
            book_covers.storage: { memory: ~ }
            reading_exports.storage: { memory: ~ }
```

Deux stockages plutôt qu'un seul avec des sous-répertoires : la décision *public ou
protégé* se prend par usage, et ce fichier est l'endroit où un relecteur doit pouvoir
voir quel bucket est lisible par le monde entier.

Le client S3 est un service que tu déclares — `league/flysystem-bundle` n'en crée
pas :

```yaml
# config/services.yaml
services:
    app.s3_client:
        class: AsyncAws\S3\S3Client
        arguments:
            - region: '%env(S3_REGION)%'
              endpoint: '%env(S3_ENDPOINT)%'
              accessKeyId: '%env(S3_KEY)%'
              accessKeySecret: '%env(S3_SECRET)%'
```

`endpoint` est ce qui permet à cela de fonctionner contre MinIO, Scaleway, OVH ou
Cloudflare R2 — même adaptateur, URL différente. Ces cinq valeurs sont des variables
d'environnement car elles varient d'une machine à l'autre ; les secrets vivent dans
`.env.local` et dans l'environnement du serveur, pas dans le vault. Le fichier généré
par la recette utilise l'ancienne forme `adapter:` / `options:`, qui fonctionne
toujours mais est marquée `DEPRECATED` depuis le bundle 3.5.

### Injecter un stockage

`registerAliasForArgument()` donne à chaque stockage un alias d'autowiring nommé,
donc ces deux formes se résolvent, et passent toutes deux `lint:container` :

```php
public function __construct(
    private FilesystemOperator $bookCoversStorage,                 // from "book_covers.storage"
    #[Target('reading_exports.storage')] private FilesystemOperator $exports,
) {
}
```

Les deux formes fonctionnent, et `#[Target]` est celle à privilégier parce qu'elle est
vérifiée : un `#[Target]` nommant un stockage qui n'existe pas **fait échouer le
build**, avec un message listant les cibles qui existent réellement, donc
`lint:container` détecte la coquille. (La forme par nom d'argument échoue aussi, mais
le nom est invisible — renomme la variable et le stockage change silencieusement.) Le
seul cas où un `#[Target]` incorrect survit à la compilation est un consommateur
atteint uniquement via un service locator paresseux, ce qu'un service utilisant un
stockage n'est normalement pas ; le mécanisme est décrit dans
`symfony-proglab-architecture`, `references/dependency-injection.md`.

**`publicUrl()` et `temporaryUrl()` ne sont pas sur `FilesystemOperator`** — cette
interface n'est que `FilesystemReader` + `FilesystemWriter`. Elles sont déclarées sur
le `League\Flysystem\Filesystem` concret, donc les appeler signifie
`#[Autowire(service: 'book_covers.storage')] private Filesystem $covers`.

### `visibility` n'est pas de la décoration

Vérifié sur l'adaptateur local :

| Configuration | Ce qui est écrit |
|---|---|
| pas de `visibility` | **aucun `chmod` du tout** — le fichier reçoit ce que produit l'umask du processus |
| `visibility: private` | l'adaptateur applique un chmod `0600` au fichier |

Et un piège dans l'autre sens : `PortableVisibilityConverter::inverseForFile()`
renvoie `Visibility::PUBLIC` pour *tout* mode de permission qu'il ne reconnaît pas.
Donc `$storage->visibility($key) === 'public'` est une valeur par défaut, pas une
mesure — ne l'utilise pas comme contrôle de sécurité. Le contrôle de sécurité, c'est
dans quel stockage se trouve le fichier.

## Nommer le fichier stocké

N'écris jamais le nom fourni par le client. Pas à cause de la traversée de chemin —
`UploadedFile::move()` fait un basename de l'argument et Flysystem lève
`PathTraversalDetected` — mais parce que l'extension survit, les noms entrent en
collision, et un nom prévisible est une URL devinable.

```php
final readonly class ReadingExportStorage
{
    public function __construct(
        private FilesystemOperator $readingExportsStorage,
        private SluggerInterface $slugger,
    ) {
    }

    public function store(UploadedFile $file): string
    {
        $key = \sprintf(
            '%s/%s-%s.%s',
            (new \DateTimeImmutable())->format('Y/m'),
            $this->slugger->slug(pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME))
                ->lower()
                ->truncate(60),
            bin2hex(random_bytes(8)),
            $file->guessExtension() ?? 'bin',
        );

        $stream = fopen($file->getPathname(), 'r');

        try {
            $this->readingExportsStorage->writeStream($key, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return $key;
    }
}
```

Ce que fait chaque partie :

- **Le préfixe `Y/m`** — empêche un seul répertoire d'atteindre les dizaines de
  milliers d'entrées où un système de fichiers local, et `listContents()`, deviennent
  lents.
- **Le slug du radical original** — lisible dans un listing de bucket et dans les
  logs. Une simple commodité ; rien ne le relit.
- **`random_bytes(8)`** — la partie qui compte vraiment. Elle élimine les collisions
  *et* rend la clé indevinable, ce qui empêche qu'une URL divulguée ne devienne un
  répertoire vers les fichiers de tout le monde. `uniqid()` est basé sur le temps,
  donc prévisible ; ce n'est pas un substitut.
- **`guessExtension()`, pas `getClientOriginalExtension()`** — le premier dérive
  l'extension à partir du type MIME détecté, le second reprend simplement ce que dit
  le client. Il renvoie `null` pour un type qu'il ne connaît pas (vérifié : `null`
  pour `text/x-php`), d'où le `?? 'bin'` — sans repli, un upload hostile produit une
  clé se terminant par un point nu.
- **`writeStream()`, pas `write()`** — `write()` charge tout le fichier dans une
  chaîne PHP, donc un upload de 200 Mo nécessite 200 Mo de `memory_limit`.

`getPathname()` est le chemin temporaire de l'upload, que PHP supprime à la fin de la
requête : c'est une copie, et rien n'a besoin d'être nettoyé ensuite.

## Ce qui va en base de données

```php
#[ORM\ManyToOne(inversedBy: 'readingExports')]
#[ORM\JoinColumn(nullable: false)]
private ?User $owner = null;

#[ORM\Column(length: 255, unique: true)]
private string $storageKey;

#[ORM\Column(length: 255)]
private string $originalName;

#[ORM\Column(length: 100)]
private string $mimeType;

#[ORM\Column]
private int $sizeInBytes;

#[ORM\Column]
private \DateTimeImmutable $uploadedAt;
```

Points qui ne sont pas évidents :

- **`unique: true` sur `storageKey`.** Le suffixe aléatoire rend une collision
  improbable, pas impossible, et l'index transforme « deux lignes partagent
  silencieusement un fichier » en une erreur.
- **Aucun argument `nullable` sur `#[ORM\ManyToOne]`** — il n'existe pas dans ORM 3 ;
  la nullabilité relève de `#[ORM\JoinColumn]`. L'écrire sur le `ManyToOne` est une
  erreur fatale de paramètre nommé inconnu.
- **Stocke `mimeType` depuis `getMimeType()`** (détecté), pas `getClientMimeType()` —
  c'est ce que le téléchargement enverra, donc cela doit être le type que les octets
  ont réellement.
- **Aucun chemin, aucune URL, aucun nom de bucket.** La clé est relative à un stockage
  dont l'emplacement est de la configuration. Stocker
  `https://bucket.s3.../x.pdf` fige l'infrastructure d'aujourd'hui dans chaque ligne.

## Supprimer sans laisser d'orphelins

```php
public function delete(ReadingExport $export): void
{
    $key = $export->getStorageKey();

    $this->exports->remove($export);
    $this->entityManager->flush();

    try {
        $this->readingExportsStorage->delete($key);
    } catch (FilesystemException $e) {
        $this->logger->error('Orphan file left behind', ['key' => $key, 'exception' => $e]);
    }
}
```

L'ordre est tout l'enjeu. **La ligne d'abord, le fichier ensuite :** un `flush()` qui
échoue ne supprime rien, et une suppression de fichier qui échoue laisse un
orphelin — invisible, coûteux en stockage, inoffensif. L'ordre inverse produit une
ligne pointant vers un fichier qui n'existe plus, ce qui casse chaque page qui le
rend. **L'échec est journalisé, pas relevé :** le cas d'usage a réussi, et faire
échouer la requête parce qu'un bucket a eu un incident laisserait l'utilisateur dans
l'incapacité de supprimer quoi que ce soit.

Une nouvelle tentative par requête n'est pas la réponse ; un balayage l'est. Une
commande liste ce que détient le stockage, soustrait les clés que connaît la base de
données, et supprime ce qui reste — en rapportant par défaut et en agissant seulement
avec `--force` :

```php
// Repository : SELECT e.storageKey FROM … — une seule colonne, pas d'hydratation.
$known = array_flip($this->exports->findAllStorageKeys());
$cutoff = $this->clock->now()->modify('-1 day')->getTimestamp();

foreach ($this->readingExportsStorage->listContents('', FilesystemReader::LIST_DEEP) as $item) {
    if (!$item->isFile() || isset($known[$item->path()])) {
        continue;
    }

    if (null === $item->lastModified() || $item->lastModified() >= $cutoff) {
        continue;   // too recent, or unknown age — leave it alone
    }

    // report, and delete only when --force was given
}
```

Trois éléments qui rendent cela sûr plutôt que dangereux :

- **Le seuil `lastModified()`** exclut les fichiers écrits entre le moment où la
  liste des clés a été lue et maintenant. Sans lui, le balayage supprimerait des
  uploads en cours.
- **`lastModified()` est `?int`.** Un adaptateur incapable de rapporter un âge renvoie
  `null`, et `null < $cutoff` vaut `true` en PHP — traiter l'inconnu comme ancien est
  la façon dont un balayage supprime un bucket entier. Ignore-le explicitement.
- **La liste des clés est un `SELECT` d'une seule colonne**, transformée en table de
  correspondance, pas un `findAll()` hydratant chaque entité, et — conformément à la
  charte — écrite comme une méthode de repository nommée d'après l'intention.

La mécanique de la commande (dry run par défaut, `LockableTrait` et sa mise en garde
multi-hôte, le test de fumée) relève de `symfony-proglab-console` ; la rendre
périodique relève de `symfony-proglab-async`.

## Tester sans toucher au stockage réel

**Tests fonctionnels et d'intégration : remplace l'adaptateur, pas le service.** Le
bloc `when@test` ci-dessus fait pointer les deux stockages vers
`league/flysystem-memory`. Le service testé reste inchangé et rien n'atteint le
disque :

```php
$file = new UploadedFile(__DIR__.'/../Fixtures/export.pdf', 'Rapport (final).pdf', test: true);
$key = $storage->store($file);

self::assertSame(file_get_contents($file->getPathname()), stream_get_contents($storage->readStream($key)));
```

Le cinquième argument d'`UploadedFile`, `$test`, est ce qui rend cela possible : il
désactive la vérification `is_uploaded_file()`, donc un fichier de fixture se comporte
comme un vrai upload. Sans lui, chaque test d'upload échoue avec « The file … was not
uploaded due to an unknown error. »

Comme le stockage objet est une véritable frontière externe, `$container->set()`
serait aussi légitime ici — mais il n'y a aucune raison de l'utiliser : la
surcharge de configuration ne nécessite aucun code et survit au redémarrage du
conteneur qu'effectue `KernelBrowser` entre les requêtes.

**Tests unitaires : double l'interface.** `FilesystemOperator` est une interface,
donc le service est testable sans aucun conteneur :

```php
$fs = $this->createMock(FilesystemOperator::class);
$fs->expects(self::once())->method('writeStream');

$key = (new ReadingExportStorage($fs, new AsciiSlugger()))->store($file);

self::assertMatchesRegularExpression('#^\d{4}/\d{2}/rapport-final-[0-9a-f]{16}\.pdf$#', $key);
```

`createMock()` parce qu'il y a un `expects()` ; `createStub()` là où il n'y en a
pas — un mock sans attente émet une notice PHPUnit, et `phpunit.dist.xml` a besoin de
`failOnPhpunitNotice="true"` pour que cela devienne rouge (`symfony-proglab-quality`).

Vérifie le *format* de la clé, pas seulement qu'une clé a été renvoyée. Cette regex
est la règle qui empêche le nom de fichier du client d'atterrir sur le disque, et
c'est exactement ce qu'un refactoring relâche discrètement.
