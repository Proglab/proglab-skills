# Tests qui touchent la base de données

L'isolation, les deux façons de mal la configurer, et les fixtures que plusieurs tests
peuvent partager.

## Sommaire

- [D'abord, vérifier que tu es sur la base de test](#dabord-vérifier-que-tu-es-sur-la-base-de-test) ·
  [Isolation: dama/doctrine-test-bundle](#isolation-damadoctrine-test-bundle) ·
  [Ce que DAMA fait réellement](#ce-que-dama-fait-réellement) ·
  [Ce qui demande encore de l'attention](#ce-qui-demande-encore-de-lattention) ·
  [Pourquoi la transaction manuelle a été abandonnée](#pourquoi-la-transaction-manuelle-a-été-abandonnée) ·
  [Le redémarrage du kernel, et ce qu'il coûte encore](#le-redémarrage-du-kernel-et-ce-quil-coûte-encore) ·
  [Fixtures](#fixtures) ·
  [Les deux règles qui rendent un jeu de données partagé sûr](#les-deux-règles-qui-rendent-un-jeu-de-données-partagé-sûr) ·
  [Charger des fixtures depuis un test](#charger-des-fixtures-depuis-un-test) ·
  [Object mothers](#object-mothers-et-quand-les-préférer) ·
  [Ce qu'un test de repository affirme](#ce-quun-test-de-repository-affirme)

## D'abord, vérifier que tu es sur la base de test

La recipe habituelle place ceci dans `config/packages/test/doctrine.yaml` :

```yaml
when@test:
    doctrine:
        dbal:
            dbname_suffix: '_test%env(default::TEST_TOKEN)%'
```

**Sur SQLite, cela ne fait rien.** DoctrineBundle ajoute le suffixe au paramètre de
connexion `dbname`, et un DSN SQLite n'a pas de `dbname` — il a un `path`. Le nœud de
configuration le dit dans sa propre description : *« this option has no effects for the
SQLite platform »*. La conséquence est silencieuse et coûteuse : toute la suite s'exécute
contre la base de développement, et le premier test qui purge une table emporte tes
données locales avec lui.

Deux façons d'être sûr, toutes deux explicites — une surcharge dans `.env.test`, ou un
DSN sensible à l'environnement dans `.env` qui convient à tous les environnements à la
fois :

```dotenv
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_test.db"          # .env.test
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db"   # .env
```

```bash
php bin/console --env=test debug:config doctrine dbal      # vérifier avant de faire confiance
php bin/console --env=test doctrine:database:create
php bin/console --env=test doctrine:migrations:migrate --no-interaction
```

`php bin/console`, pas `symfony console` : le CLI Symfony injecte `DATABASE_URL` depuis
les conteneurs en cours d'exécution *indépendamment de `--env`*, donc `symfony console
--env=test` cible allègrement la base de développement (`symfony-proglab-local-dev`).

Exécute les migrations plutôt que `schema:create`. Une suite qui construit son schéma à
partir du mapping n'exerce jamais les migrations, et les migrations sont la première
chose que la production exécute.

## Isolation: dama/doctrine-test-bundle

```bash
composer require --dev dama/doctrine-test-bundle
```

La recipe est dans `recipes-contrib`, que Composer n'applique pas de lui-même —
l'installation se termine par `IGNORING dama/doctrine-test-bundle (>=8.3)`. Donc deux
lignes sont à ta charge.

```php
// config/bundles.php
DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true],
```

```xml
<!-- phpunit.dist.xml, en tant qu'enfant direct de <phpunit> -->
<extensions>
    <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
</extensions>
```

`<bootstrap class="…"/>` à l'intérieur de `<extensions>` est la forme PHPUnit 10+, et la
classe est `DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension` — elle implémente
`PHPUnit\Runner\Extension\Extension`, ce que cet élément attend. Tout ce que tu trouves
en ligne utilisant `<extension class="…"/>`, ou enregistrant un listener, est écrit pour
un PHPUnit antérieur à la 10 et ne se chargera pas. Vérifié sur `dama/doctrine-test-bundle`
8.6.0 avec PHPUnit 13.3.2, Symfony 8.1.5, doctrine-bundle 3.3.1, DBAL 4.4.4, ORM 3.6.8,
PHP 8.4.5.

Un test ne contient alors plus aucun code d'isolation :

```php
#[CoversClass(BookRepository::class)]
final class BookRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BookRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(BookRepository::class);
    }
}
```

**Le mode d'échec à connaître avant tout le reste : l'extension sans le bundle.**
PHPUnit démarre l'extension, l'extension demande au driver statique d'ouvrir une
transaction, il n'y a pas de driver statique car le middleware n'a jamais été enregistré
— et chaque test commit. La suite est verte. Mesuré sur la configuration ci-dessus : un
`WebTestCase` effectuant deux requêtes d'écriture a réussi avec trois assertions et a
laissé les deux lignes dans la base de données.

Rien dans la sortie ne le signale, donc vérifie-le mécaniquement. Une commande, code de
sortie 1 quand le bundle est absent, 0 quand il est présent :

```bash
php bin/console --env=test debug:config dama_doctrine_test
```

`symfony-proglab-quality` l'exécute en CI pour exactement cette raison.

## Ce que DAMA fait réellement

Il enregistre un middleware de driver DBAL, et le middleware garde la connexion du
driver dans une propriété `static`. Une extension PHPUnit s'abonne alors à trois
événements : le démarrage du test runner (activer les connexions statiques), la
préparation de chaque test (annuler la transaction du test précédent, en ouvrir une
nouvelle), et la fin du test runner (annuler, désactiver).

Trois conséquences découlent de *l'endroit* où il s'accroche, et elles sont toute la
raison de ce choix :

- **C'est en dehors du cycle de vie du kernel.** Redémarrer le kernel construit un
  nouveau conteneur, un nouvel `EntityManager` et une nouvelle `Connection` DBAL — qui
  demandent tous une connexion au driver et récupèrent la même connexion statique,
  transaction comprise. C'est ce qu'une transaction possédée par ta classe de test ne
  peut pas faire.
- **C'est en dehors de ta classe de test.** Il n'y a pas de `tearDown()` à oublier, pas
  de classe de base à étendre, et rien à faire de travers dans un test qui surcharge
  `setUp()` sans appeler `parent::setUp()`.
- **L'imbrication se fait par savepoint.** La connexion du driver est déjà dans une
  transaction, donc quand DBAL en ouvre une pour un `flush()`, le `StaticConnection` de
  DAMA la traduit en `SAVEPOINT DAMA_TEST` et le `RELEASE` correspondant. La plateforme
  doit supporter les savepoints ou le bundle lève `This bundle only works for database
  platforms that support savepoints.` au moment de la connexion. SQLite est vérifié ici ;
  PostgreSQL, MySQL sur InnoDB et MariaDB les supportent aussi.

## Ce qui demande encore de l'attention

**Un commit à l'intérieur d'un test n'est pas un commit.**
`$em->getConnection()->commit()` libère le savepoint `DAMA_TEST` et rien de plus ; la
transaction externe de DAMA s'annule quand même à la fin du test. Vérifié : un test qui
persiste une ligne, flush et commit explicitement ne laisse rien derrière lui. C'est
normalement ce que tu veux — mais si tu testes du code dont le *but* est que la donnée
survive, le test te ment et continuera de te mentir.

Pour ces cas, le bundle fournit une échappatoire :

```php
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;

#[Test]
#[SkipDatabaseRollback]
public function the_import_survives_a_crash_mid_run(): void
```

Elle fonctionne sur la méthode ou sur la classe (et est héritée d'une classe de test
parente). Le test commit alors réellement — vérifié, la ligne est toujours là après —
ce qui te rend le nettoyage à charge. Écris-le, et garde ces tests rares : chacun est un
trou dans l'isolation de la suite.

**Ne superpose pas une transaction manuelle par-dessus.** Garder l'ancienne paire
`beginTransaction()`/`rollBack()` alors que DAMA est actif fait toujours échouer un
`WebTestCase` à deux requêtes avec `NoActiveTransaction` : l'objet `Connection` sur
lequel tu as appelé `beginTransaction()` appartient au conteneur d'avant le redémarrage,
et il est périmé même si la connexion du driver sous-jacente ne l'est pas. L'isolation
tient — DAMA annule tout — mais le test lève une erreur. La migration consiste à
supprimer ces quatre lignes, pas à les garder « au cas où ».

**Les transactions imbriquées dans le code de production ne posent pas de problème, une
seconde au niveau du driver, si.** DBAL suit sa propre imbrication et n'atteint le
driver qu'au niveau 1, donc `wrapInTransaction()` à l'intérieur d'un service fonctionne
normalement sous DAMA — vérifié, y compris l'assertion que la ligne est lisible à
l'intérieur de la propre transaction de la closure. Ce que le `StaticConnection` du
bundle refuse, c'est un second begin *au niveau du driver* :
`BadMethodCallException: A savepoint is already in use for a nested transaction.` En
pratique cela signifie une chose — ne fabrique pas de transactions à la main dans un
test par-dessus celle de DAMA.

**Le désactiver pour une seule connexion.** Avec plusieurs connexions DBAL, ou une qui
ne doit pas être enveloppée, la configuration prend une map plutôt qu'un booléen :

```yaml
# config/packages/test/dama_doctrine_test.yaml — ou when@test dans n'importe quel fichier de config
dama_doctrine_test:
    enable_static_connection:
        default: true
        legacy: false
```

`enable_static_connection` vaut `true` par défaut pour chaque connexion. Le bundle
expose aussi `enable_static_meta_data_cache` et `enable_static_query_cache` (tous deux
`true` par défaut, et il vaut mieux ne pas y toucher) et `connection_keys` pour le cas où
deux connexions logiques doivent partager une seule connexion statique. Note que la clé
de configuration est `dama_doctrine_test.enable_static_connection` ;
`dama.enable_static_connection` est le paramètre de conteneur dans lequel elle est
compilée, et il est retiré une fois les middlewares enregistrés —
`debug:container --parameter` ne le trouvera pas.

**Le rollback ne remplace pas une base de données propre.** Si une exécution précédente
a commité des lignes — un test `#[SkipDatabaseRollback]`, un processus qui a planté, un
`doctrine:fixtures:load` lancé à la main par quelqu'un — elles sont toujours là. DAMA
n'annule que ce que le test en cours a fait.

## Pourquoi la transaction manuelle a été abandonnée

Ce standard ouvrait autrefois la transaction lui-même :

```php
protected function setUp(): void
{
    self::bootKernel();
    $this->em = self::getContainer()->get(EntityManagerInterface::class);
    $this->em->getConnection()->beginTransaction();   // ce n'est plus le conseil donné
}

protected function tearDown(): void
{
    $this->em->getConnection()->rollBack();
    parent::tearDown();
}
```

Ça se lit bien et ça fonctionne dans un `KernelTestCase`. Ça ne fonctionne pas dans un
`WebTestCase`, et c'est là tout l'argument contre cette approche.

`KernelBrowser::doRequest()` saute le redémarrage uniquement pour la première requête. À
partir de la deuxième, le kernel est arrêté puis redémarré : nouveau conteneur, nouvel
`EntityManager`, nouvelle connexion — et l'ancienne connexion est fermée, alors que
c'est là que vivait la transaction.

Mesuré sur les versions ci-dessus, un `WebTestCase` qui ouvre une transaction dans
`setUp()` et effectue deux requêtes qui insèrent chacune une ligne :

```
Doctrine\DBAL\Exception\NoActiveTransaction: There is no active transaction.
LEAKED ROWS: 1 -> manual-two
```

Lis cette deuxième ligne attentivement, car c'est pire que « l'isolation n'a pas
fonctionné ». La ligne écrite par la requête 1 a **disparu** — elle est morte avec sa
connexion. La ligne écrite par la requête 2 est **commitée** — elle s'est exécutée sur
une connexion neuve sans transaction dessus. Le test perd donc à la fois des données
qu'il a écrites et en laisse derrière lui, dans la même exécution. Une classe de test
qui n'appelle pas `rollBack()` explicitement subit la fuite sans la moindre exception.

`$client->disableReboot()` corrige effectivement le problème, et c'était l'ancien
conseil. C'est une correction qui te demande de te souvenir, dans chaque test
fonctionnel, d'un appel dont le but est invisible depuis son nom — et de t'en souvenir
d'autant plus dans les tests qui font beaucoup de requêtes, qui sont ceux que tu es le
moins susceptible de surveiller. Le bundle supprime l'obligation au lieu de la
documenter.

## Le redémarrage du kernel, et ce qu'il coûte encore

`disableReboot()` n'est plus la façon d'obtenir l'isolation. C'est toujours le bon choix
dans la plupart des tests fonctionnels, pour des raisons qui n'ont rien à voir avec la
base de données :

- **`self::getContainer()` renvoie un conteneur différent à partir de la deuxième
  requête.** Tout ce que tu as récupéré avant la requête — un repository, un transport,
  le message logger du mailer — est périmé, et les assertions lues depuis lui portent
  sur un conteneur que plus personne n'utilise.
- **Les remplacements via `$container->set()` sont perdus au redémarrage**, et après une
  requête ils lèvent `The "App\Service\X" service is already initialized, you cannot
  replace it`. Voir `references/test-doubles.md`.
- **Les doubles en mémoire perdent ce qu'ils ont enregistré.** `assertEmailCount()` et le
  transport Messenger `in-memory://` lisent un état conservé par le conteneur ; un
  redémarrage entre la requête qui envoie et l'assertion qui vérifie signifie affirmer
  sur un enregistreur vide.

```php
protected function setUp(): void
{
    $this->client = self::createClient();
    $this->client->disableReboot();   // identité du conteneur et état des services, pas l'isolation
}
```

Ce que tu perds est réel : l'état est conservé d'une requête à l'autre — la map
d'identité comprise, donc une entité modifiée par une requête reste gérée et reste sale
sur la suivante. Là où un test a besoin d'un kernel vraiment neuf par requête, garde le
redémarrage. Sous DAMA, c'est désormais un choix libre : la connexion et la transaction
survivent dans les deux cas.

## Fixtures

```bash
composer require --dev doctrine/doctrine-fixtures-bundle
```

Une classe de fixture étend `Doctrine\Bundle\FixturesBundle\Fixture`, est
auto-configurée comme service, et — c'est ce qui la rend utilisable depuis plus d'un
test — **implémente `FixtureGroupInterface`**.

```php
// src/DataFixtures/ShelfFixtures.php
// use Doctrine\Bundle\FixturesBundle\{Fixture, FixtureGroupInterface};
// use Doctrine\Persistence\ObjectManager;

final class ShelfFixtures extends Fixture implements FixtureGroupInterface
{
    public const DUNE = 'book_dune';
    public const HORDE = 'book_horde';

    public static function getGroups(): array
    {
        return ['shelf'];
    }

    public function load(ObjectManager $manager): void
    {
        $dune = new Book();
        $dune->setTitle('Dune');
        $dune->setAuthor('Frank Herbert');
        $dune->setStatus(ReadingStatus::Read);
        $dune->setLastReadAt(new \DateTimeImmutable('2026-01-15'));
        $manager->persist($dune);
        $this->addReference(self::DUNE, $dune);

        // … pareil pour self::HORDE

        $manager->flush();
    }
}
```

Les noms de référence vivent dans des constantes, pas dans des littéraux de chaîne
dispersés dans les tests : une faute de frappe dans `getReference('book_dnue', …)` est un
échec à l'exécution dans chaque test qui l'utilise, tandis qu'une faute de frappe dans
`ShelfFixtures::DUNE` est détectée par l'analyse statique.

## Les deux règles qui rendent un jeu de données partagé sûr

Un jeu de données partagé couple les tests entre eux. Deux règles suppriment ce
couplage ; en négliger une seule le fait revenir immédiatement.

**1. Récupérer par référence nommée, jamais par position.**

```php
$book = $this->referenceRepository->getReference(ShelfFixtures::DUNE, Book::class);
```

```php
// Jamais. Ajouter une fixture sans rapport réordonne ceci et casse un test
// qui n'a rien à voir avec le changement.
$book = $repository->findAll()[0];
```

`findAll()[0]` dépend de l'ordre d'insertion, de l'absence d'un `ORDER BY`, et du fait
que personne n'ajoute jamais de ligne au-dessus. Cela produit le pire type d'échec : un
test qui casse quand tu touches une fonctionnalité différente.

Depuis `doctrine/data-fixtures` 2.0, `getReference()` prend la classe comme second
argument obligatoire. En 1.x, elle ne prenait que le nom.

**2. Des groupes, pour qu'un test ne charge que ce dont il a besoin.**

Charger tout le jeu de données pour chaque test rend la suite lente *et* couple chaque
test à chaque fixture. Un groupe par ensemble cohérent — `shelf`, `reviews`, `users` —
et un test charge les groupes qu'il nomme. Quand un test échoue après que tu as ajouté
une fixture, le groupe te dit immédiatement s'il peut être lié ou non.

## Charger des fixtures depuis un test

Il n'y a pas de helper `loadFixtures()` dans le bundle. Construis l'exécuteur toi-même ;
cela fait dix lignes, ça vit dans une seule classe de base, et cela rend explicite le
filtrage par groupe.

```php
// tests/DatabaseTestCase.php
// use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
// use Doctrine\Common\DataFixtures\{Executor\ORMExecutor, ReferenceRepository};

abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected ReferenceRepository $references;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $loader = self::getContainer()->get('doctrine.fixtures.loader');
        \assert($loader instanceof SymfonyFixturesLoader);

        $executor = new ORMExecutor($this->em);
        $executor->execute($loader->getFixtures($this->fixtureGroups()), append: true);

        $this->references = $executor->getReferenceRepository();
    }

    /**
     * @return list<string>
     */
    abstract protected function fixtureGroups(): array;
}
```

Un test déclare alors ses groupes (`protected function fixtureGroups(): array { return
['shelf']; }`) et lit ses données via `$this->references->getReference(…)`.

Pas de transaction ici, et pas de `tearDown()` : DAMA en a ouvert une avant l'exécution
de `setUp()` et l'annule après le test, fixtures comprises.

Pourquoi `append: true` : cela évite la purge. À l'intérieur de la transaction de DAMA,
la base de données est déjà propre au début de chaque test, donc purger n'apporte rien —
et cela coûte quelque chose, car `ORMExecutor` avec `append: false` requiert qu'un
purger ait été défini et, sur MySQL, une purge `TRUNCATE` déclenche un commit implicite.
Ce commit ne se contente plus de terminer ta propre transaction ; il termine celle de
DAMA, qui est l'isolation de toute l'exécution à partir de ce point. Faire un append à
l'intérieur de la transaction annulée est à la fois plus rapide et plus sûr.

`doctrine.fixtures.loader` est un service privé ; il est accessible car
`self::getContainer()` dans l'environnement de test expose les services privés qui ont
survécu à la compilation, et c'est le cas de celui-ci — la commande
`doctrine:fixtures:load` le référence.

## Object mothers, et quand les préférer

Pour un test qui a besoin d'*une seule* entité dans un état spécifique, un groupe de
fixtures est excessif. Une factory statique dans `tests/Fixtures/` —
`BookMother::titled('Solo', new \DateTimeImmutable('2026-03-01'))`,
`BookMother::inProgress()` — se lit mieux, ne couple rien, et nomme l'objet par son rôle
dans le test plutôt que par ses valeurs.

Utilise des fixtures quand plusieurs tests ont besoin du *même* jeu de données et que le
jeu de données est le sujet du test (tri, pagination, agrégats). Utilise une mother
quand le test a besoin d'un seul objet dont le champ pertinent est nommé directement
dans l'appel. Mélanger les deux est normal ; ce qui ne l'est pas, c'est un fichier de
fixtures qui existe pour servir un seul test.

## Ce qu'un test de repository affirme

Un test de repository vaut la peine d'être écrit quand il y a une requête à se tromper :
**le tri**, avec des lignes délibérément insérées dans le désordre et vérifiées par une
vérification par mutation car `ORDER BY … DESC` ne peut pas échouer avant d'exister ;
**le filtrage**, avec au moins une ligne qui doit être exclue, puisqu'une requête sans
cas négatif n'a jamais été testée ; **le cas vide**, renvoyant `[]` et non `null` ; et
**la forme de la projection** quand la méthode utilise `SELECT NEW`, afin que l'arité du
constructeur du DTO de lecture échoue ici plutôt qu'en production.

Ce qu'il ne devrait pas couvrir : les règles métier. `findLatestPublishedForBook()` est
testée pour ce qu'elle sélectionne et dans quel ordre. Savoir si un avis peut être
publié du tout est une règle de service et a sa place dans un `TestCase` sans kernel.
