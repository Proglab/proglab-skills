# PHPStan au niveau max sur Symfony

Les erreurs que tu vas réellement rencontrer, et comment les corriger plutôt que les
faire taire.

## Sommaire

- [Avant tout : réchauffer le conteneur](#avant-tout--réchauffer-le-conteneur)
- [Repositories Doctrine et generics](#repositories-doctrine-et-generics)
- [Entités que PHPStan croit nulles](#entités-que-phpstan-croit-nulles)
- [`getResult()` retourne mixed](#getresult-retourne-mixed)
- [Services récupérés du conteneur dans les tests](#services-récupérés-du-conteneur-dans-les-tests)
- [Ignorer une erreur correctement](#ignorer-une-erreur-correctement)

## Avant tout : réchauffer le conteneur

```bash
php bin/console cache:warmup --env=dev
```

`phpstan-symfony` lit le XML du conteneur compilé pour savoir quels ids de service
existent, quelles routes sont définies, et ce que `$this->getUser()` retourne
réellement. Sans cela l'extension ne produit pas d'erreur — elle se dégrade. Des
règles qui auraient dû attraper une faute de frappe dans un id de service passent
silencieusement.

Si `containerXmlPath` pointe vers un fichier qui n'existe pas, PHPStan ne dit rien.
Vérifie le vrai nom de fichier : il intègre le nom de la classe du kernel, qui
intègre lui-même le namespace du projet.

```bash
ls var/cache/dev/*KernelDevDebugContainer.xml
```

## Repositories Doctrine et generics

`ServiceEntityRepository` est générique. Sans l'annotation, chaque `find()` retourne
`object` et le niveau max se plaint à chaque point d'appel.

```php
/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }
}
```

Annote aussi les méthodes personnalisées. `getResult()` n'a aucune idée de ce qu'il a
retourné :

```php
/**
 * @return list<Review>
 */
public function findLatestPublished(int $limit = 20): array
```

Utilise `list<T>` plutôt que `array<T>` quand les clés sont séquentielles — ce qui
est le cas en sortie de Doctrine. C'est plus précis et cela ne coûte rien.

## Entités que PHPStan croit nulles

La forme générée par le maker déclare `private ?int $id = null;`, donc PHPStan a
raison de dire que `$book->getId()` peut être null — c'est réellement le cas, avant
le premier flush.

Ne saupoudre pas d'`assert()` aux points d'appel. Deux corrections honnêtes :

- **Prends l'entité, pas l'id**, dans la signature du service. Passer `Book $book`
  plutôt que `int $bookId` supprime la question à la racine, et c'est une meilleure
  conception.
- **Accepte le type nullable** dans le DTO et laisse le mapping s'en occuper. Un DTO
  de sortie construit à partir d'une entité persistée peut déclarer `public int $id`
  et être construit après le flush, moment où l'id est garanti.

Si aucune des deux ne s'applique, c'est le cas d'un ignore explicite avec une
raison.

## `getResult()` retourne mixed

```php
// PHPStan : cannot call method getTitle() on mixed
$books = $qb->getQuery()->getResult();
```

Annote le type de retour de la méthode comme ci-dessus. Pour un agrégat scalaire,
transtype à la frontière et exprime ce que tu attends :

```php
public function countPublishedForBook(int $bookId): int
{
    return (int) $this->createQueryBuilder('r')
        ->select('COUNT(r.id)')
        // ...
        ->getQuery()
        ->getSingleScalarResult();
}
```

Le transtypage n'est pas du bruit : selon le driver, `COUNT` revient sous forme de
`string`. Le DTO qui porte cette valeur récupérerait sinon une string là où il a
déclaré un int, et `declare(strict_types=1)` transformerait cela en erreur à
l'exécution en production plutôt qu'en erreur PHPStan ici.

## Services récupérés du conteneur dans les tests

```php
// PHPStan : cannot call method findLatest() on object
$repository = self::getContainer()->get(ReviewRepository::class);
```

`get()` est déclaré comme retournant `object`. Assigne via une propriété typée, ce
qui est aussi du meilleur code de test :

```php
private ReviewRepository $repository;

protected function setUp(): void
{
    self::bootKernel();

    $repository = self::getContainer()->get(ReviewRepository::class);
    \assert($repository instanceof ReviewRepository);
    $this->repository = $repository;
}
```

## Ignorer une erreur correctement

Parfois l'outil se trompe. C'est surtout avec les generics et les retours
dynamiques de Doctrine que cela arrive.

```php
// Doctrine's findOneBy() is typed as returning the entity or null, but this
// query is on a unique index and the caller has already checked existence.
/** @phpstan-ignore-next-line */
```

Deux règles :

- **Toujours avec une raison à la ligne au-dessus.** Si la raison ne vaut pas la
  peine d'être écrite, l'ignore n'est pas justifié. C'est la seule vérification qui
  empêche les ignores de se propager.
- **Jamais dans `phpstan.dist.neon` comme motif `ignoreErrors` global**, sauf si
  l'erreur est vraiment systémique. Un motif fait taire l'erreur partout, y compris
  là où elle était sur le point d'attraper un vrai bug.

Et celle qui compte le plus : **n'ajoute jamais à la baseline pour faire taire une
nouvelle erreur.** La baseline existe pour geler la dette antérieure au standard.
La faire grandir revient à ajouter de la dette délibérément, ce qui mérite une
conversation plutôt qu'un commit.
