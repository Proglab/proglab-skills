# Tests that touch the database

Isolation, the two ways to configure it wrong, and fixtures several tests can share.

## Contents

- [First: make sure you are on the test database](#first-make-sure-you-are-on-the-test-database) ·
  [Isolation: dama/doctrine-test-bundle](#isolation-damadoctrine-test-bundle) ·
  [What DAMA actually does](#what-dama-actually-does) ·
  [What still needs care](#what-still-needs-care) ·
  [Why the manual transaction was dropped](#why-the-manual-transaction-was-dropped) ·
  [The kernel reboot, and what it still costs you](#the-kernel-reboot-and-what-it-still-costs-you) ·
  [Fixtures](#fixtures) ·
  [The two rules that make a shared dataset safe](#the-two-rules-that-make-a-shared-dataset-safe) ·
  [Loading fixtures from a test](#loading-fixtures-from-a-test) ·
  [Object mothers](#object-mothers-and-when-to-prefer-them) ·
  [What a repository test asserts](#what-a-repository-test-asserts)

## First: make sure you are on the test database

The usual recipe puts this in `config/packages/test/doctrine.yaml`:

```yaml
when@test:
    doctrine:
        dbal:
            dbname_suffix: '_test%env(default::TEST_TOKEN)%'
```

**On SQLite it does nothing.** DoctrineBundle appends the suffix to the `dbname`
connection parameter, and a SQLite DSN has no `dbname` — it has a `path`. The
configuration node says so in its own description: *"this option has no effects for the
SQLite platform"*. The consequence is silent and expensive: the whole suite runs
against the development database, and the first test that purges a table takes your
local data with it.

Two ways to be certain, both explicit — an override in `.env.test`, or an
environment-aware DSN in `.env` that is right for every environment at once:

```dotenv
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_test.db"          # .env.test
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db"   # .env
```

```bash
php bin/console --env=test debug:config doctrine dbal      # verify before trusting
php bin/console --env=test doctrine:database:create
php bin/console --env=test doctrine:migrations:migrate --no-interaction
```

`php bin/console`, not `symfony console`: the Symfony CLI injects `DATABASE_URL` from
the running containers *regardless of `--env`*, so `symfony console --env=test` happily
targets the development database (`symfony-yoandev-local-dev`).

Run the migrations rather than `schema:create`. A suite that builds its schema from the
mapping never exercises the migrations, and the migrations are the first thing
production runs.

## Isolation: dama/doctrine-test-bundle

```bash
composer require --dev dama/doctrine-test-bundle
```

The recipe is in `recipes-contrib`, which Composer does not apply on its own — the
install ends with `IGNORING dama/doctrine-test-bundle (>=8.3)`. So two lines are yours.

```php
// config/bundles.php
DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true],
```

```xml
<!-- phpunit.dist.xml, as a direct child of <phpunit> -->
<extensions>
    <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
</extensions>
```

`<bootstrap class="…"/>` inside `<extensions>` is the PHPUnit 10+ form, and the class is
`DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension` — it implements
`PHPUnit\Runner\Extension\Extension`, which is what that element expects. Anything you
find online using `<extension class="…"/>`, or registering a listener, is written for a
PHPUnit older than 10 and will not load. Verified on `dama/doctrine-test-bundle` 8.6.0
with PHPUnit 13.3.2, Symfony 8.1.5, doctrine-bundle 3.3.1, DBAL 4.4.4, ORM 3.6.8, PHP
8.4.5.

A test then contains no isolation code at all:

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

**The failure mode to know before anything else: the extension without the bundle.**
PHPUnit boots the extension, the extension asks the static driver to begin a
transaction, there is no static driver because the middleware was never registered — and
every test commits. The suite is green. Measured on the setup above: a `WebTestCase`
doing two writing requests passed with three assertions and left both rows in the
database.

Nothing in the output says so, so check it mechanically. One command, exit 1 when the
bundle is missing, 0 when it is there:

```bash
php bin/console --env=test debug:config dama_doctrine_test
```

`symfony-yoandev-quality` runs it in CI for exactly this reason.

## What DAMA actually does

It registers a DBAL driver middleware, and the middleware keeps the driver connection in
a `static` property. A PHPUnit extension then subscribes to three events: the test runner
starting (turn static connections on), every test preparing (roll back the previous
test's transaction, open a new one), and the test runner finishing (roll back, turn them
off).

Three consequences follow from *where* it hooks, and they are the whole reason for the
choice:

- **It is outside the kernel lifecycle.** Rebooting the kernel builds a new container, a
  new `EntityManager` and a new DBAL `Connection` — all of which ask the driver for a
  connection and get the same static one back, transaction and all. This is what a
  transaction owned by your test class cannot do.
- **It is outside your test class.** There is no `tearDown()` to forget, no base class to
  extend, and nothing to get wrong in a test that overrides `setUp()` without calling
  `parent::setUp()`.
- **Nesting is by savepoint.** The driver connection is already inside a transaction, so
  when DBAL opens one for a `flush()`, DAMA's `StaticConnection` translates it into
  `SAVEPOINT DAMA_TEST` and the matching `RELEASE`. The platform must support savepoints
  or the bundle throws `This bundle only works for database platforms that support
  savepoints.` at connect time. SQLite is verified here; PostgreSQL, MySQL on InnoDB and
  MariaDB support them too.

## What still needs care

**A commit inside a test is not a commit.** `$em->getConnection()->commit()` releases the
`DAMA_TEST` savepoint and nothing more; DAMA's outer transaction still rolls back when
the test ends. Verified: a test that persists a row, flushes and commits explicitly
leaves nothing behind. That is normally what you want — but if you are testing code whose
*point* is that the data survives, the test is lying to you and will keep lying.

For those, the bundle ships an opt-out:

```php
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;

#[Test]
#[SkipDatabaseRollback]
public function the_import_survives_a_crash_mid_run(): void
```

It works on the method or on the class (and is inherited from a parent test class). The
test then genuinely commits — verified, the row is still there afterwards — which makes
the cleanup yours again. Write it, and keep these tests rare: each one is a hole in the
suite's isolation.

**Do not layer a manual transaction on top.** Keeping the old
`beginTransaction()`/`rollBack()` pair while DAMA is active still fails a two-request
`WebTestCase` with `NoActiveTransaction`: the `Connection` object you called
`beginTransaction()` on belongs to the container from before the reboot, and it is stale
even though the underlying driver connection is not. Isolation holds — DAMA rolls
everything back — but the test errors. Migration means deleting those four lines, not
keeping them "just in case".

**Nested transactions in production code are fine, a second driver-level one is not.**
DBAL tracks its own nesting and only reaches the driver at level 1, so
`wrapInTransaction()` inside a service works normally under DAMA — verified, including
the assertion that the row is readable inside the closure's own transaction. What the
bundle's `StaticConnection` refuses is a second *driver-level* begin:
`BadMethodCallException: A savepoint is already in use for a nested transaction.` In
practice that means one thing — do not hand-roll transactions in a test on top of DAMA's.

**Turning it off for one connection.** With several DBAL connections, or one that must
not be wrapped, the config takes a map instead of a boolean:

```yaml
# config/packages/test/dama_doctrine_test.yaml — or when@test in any config file
dama_doctrine_test:
    enable_static_connection:
        default: true
        legacy: false
```

`enable_static_connection` defaults to `true` for every connection. The bundle also
exposes `enable_static_meta_data_cache` and `enable_static_query_cache` (both default
`true`, both worth leaving alone) and `connection_keys` for the case where two logical
connections must share one static connection. Note the config key is
`dama_doctrine_test.enable_static_connection`; `dama.enable_static_connection` is the
container parameter it compiles into, and it is removed once the middlewares are
registered — `debug:container --parameter` will not find it.

**The rollback is not a substitute for a clean database.** If a previous run committed
rows — a `#[SkipDatabaseRollback]` test, a crashed process, a `doctrine:fixtures:load`
someone ran by hand — they are still there. DAMA only undoes what the current test did.

## Why the manual transaction was dropped

This standard used to open the transaction itself:

```php
protected function setUp(): void
{
    self::bootKernel();
    $this->em = self::getContainer()->get(EntityManagerInterface::class);
    $this->em->getConnection()->beginTransaction();   // no longer the advice
}

protected function tearDown(): void
{
    $this->em->getConnection()->rollBack();
    parent::tearDown();
}
```

It reads well and it works in a `KernelTestCase`. It does not work in a `WebTestCase`,
and that is the whole case against it.

`KernelBrowser::doRequest()` skips the reboot for the first request only. From the second
onwards the kernel is shut down and booted again: new container, new `EntityManager`, new
connection — and the old connection is closed, which is where the transaction was living.

Measured on the versions above, a `WebTestCase` that begins a transaction in `setUp()`
and performs two requests that each insert a row:

```
Doctrine\DBAL\Exception\NoActiveTransaction: There is no active transaction.
LEAKED ROWS: 1 -> manual-two
```

Read that second line carefully, because it is worse than "the isolation did not work".
The row written by request 1 is **gone** — it died with its connection. The row written
by request 2 is **committed** — it ran on a fresh connection with no transaction on it.
So the test both loses data it wrote and leaves data behind, in the same run. A test
class that does not call `rollBack()` explicitly gets the leak with no exception at all.

`$client->disableReboot()` does fix it, and that was the old advice. It is a fix that
asks you to remember, in every functional test, a call whose purpose is invisible from
its name — and to remember it hardest in the tests that make many requests, which are the
ones you are least likely to be watching. The bundle removes the requirement instead of
documenting it.

## The kernel reboot, and what it still costs you

`disableReboot()` is no longer how you get isolation. It is still the right call in most
functional tests, for reasons that have nothing to do with the database:

- **`self::getContainer()` returns a different container from the second request
  onwards.** Anything you fetched before the request — a repository, a transport, the
  mailer's message logger — is stale, and assertions read from it are assertions about a
  container nobody is using any more.
- **`$container->set()` replacements are dropped by the reboot**, and after a request
  they throw `The "App\Service\X" service is already initialized, you cannot replace it`.
  See `references/test-doubles.md`.
- **The in-memory doubles lose what they recorded.** `assertEmailCount()` and the
  `in-memory://` Messenger transport read state held by the container; a reboot between
  the request that sent and the assertion that checks means asserting on an empty
  recorder.

```php
protected function setUp(): void
{
    $this->client = self::createClient();
    $this->client->disableReboot();   // container identity and service state, not isolation
}
```

What you give up is genuine: state carries over between requests — the identity map
included, so an entity a request modified is still managed and still dirty on the next
one. Where a test needs a truly fresh kernel per request, keep the reboot. Under DAMA
that is now a free choice: the connection and the transaction survive either way.

## Fixtures

```bash
composer require --dev doctrine/doctrine-fixtures-bundle
```

A fixture class extends `Doctrine\Bundle\FixturesBundle\Fixture`, is autoconfigured as
a service, and — this is what makes it usable from more than one test —
**implements `FixtureGroupInterface`**.

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

        // … same for self::HORDE

        $manager->flush();
    }
}
```

Reference names live in constants, not in string literals scattered across tests: a
typo in `getReference('book_dnue', …)` is a runtime failure in every test that uses it,
while a typo in `ShelfFixtures::DUNE` is caught by static analysis.

## The two rules that make a shared dataset safe

A shared dataset couples tests to each other. Two rules remove the coupling; skipping
either of them brings it straight back.

**1. Fetch by named reference, never by position.**

```php
$book = $this->referenceRepository->getReference(ShelfFixtures::DUNE, Book::class);
```

```php
// Never. Adding an unrelated fixture reorders this and breaks a test that
// has nothing to do with the change.
$book = $repository->findAll()[0];
```

`findAll()[0]` depends on insertion order, on the absence of an `ORDER BY`, and on
nobody ever adding a row above it. It produces the worst kind of failure: a test that
breaks when you touch a different feature.

Since `doctrine/data-fixtures` 2.0, `getReference()` takes the class as a mandatory
second argument. On 1.x it took only the name.

**2. Groups, so a test loads only what it needs.**

Loading the whole dataset for every test makes the suite slow *and* couples every test
to every fixture. One group per coherent set — `shelf`, `reviews`, `users` — and a test
loads the groups it names. When a test fails after you added a fixture, the group tells
you immediately whether it could possibly be related.

## Loading fixtures from a test

There is no `loadFixtures()` helper in the bundle. Build the executor yourself; it is
ten lines, it lives in one base class, and it makes the group filtering explicit.

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

A test then declares its groups (`protected function fixtureGroups(): array { return
['shelf']; }`) and reads its data through `$this->references->getReference(…)`.

No transaction here, and no `tearDown()`: DAMA opened one before `setUp()` ran and rolls
it back after the test, fixtures included.

Why `append: true`: it skips the purge. Inside DAMA's transaction the database is already
clean at the start of every test, so purging buys nothing — and it costs something,
because `ORMExecutor` with `append: false` requires a purger to have been set and, on
MySQL, a `TRUNCATE` purge issues an implicit commit. That commit does not just end your
own transaction any more; it ends DAMA's, which is the isolation for the whole run from
that point on. Appending inside the rolled-back transaction is both faster and safer.

`doctrine.fixtures.loader` is a private service; it is reachable because
`self::getContainer()` in the test environment exposes private services that survived
compilation, and this one does — the `doctrine:fixtures:load` command references it.

## Object mothers, and when to prefer them

For a test that needs *one* entity in a specific state, a fixture group is overkill.
A static factory in `tests/Fixtures/` — `BookMother::titled('Solo', new
\DateTimeImmutable('2026-03-01'))`, `BookMother::inProgress()` — reads better, couples
nothing, and names the object by its role in the test rather than by its values.

Use fixtures when several tests need *the same* dataset and the dataset is the point
(sorting, pagination, aggregates). Use a mother when the test needs one object whose
relevant field is named right there in the call. Mixing both is normal; what is not
normal is a fixture file that exists to serve a single test.

## What a repository test asserts

A repository test is worth writing when there is a query to get wrong: **the ordering**,
with rows deliberately inserted out of order and verified by a mutation check because
`ORDER BY … DESC` cannot fail before it exists; **the filtering**, with at least one row
that must be excluded, since a query with no negative case has never been tested; **the
empty case**, returning `[]` and not `null`; and **the projection shape** when the
method uses `SELECT NEW`, so the Read DTO's constructor arity fails here rather than in
production.

What it should not cover: business rules. `findLatestPublishedForBook()` is tested for
what it selects and in which order. Whether a review may be published at all is a
service rule and belongs in a `TestCase` with no kernel.
