# Counting SQL queries in a test

Everything here was executed against **Symfony 8.1.5 / doctrine-bundle 3.3.1 /
doctrine/dbal 4.4.4 / doctrine/orm 3.6.8 / PHPUnit 13.3.2**. `vendor/` is the source of
truth over this file; the last section says exactly what to grep to re-verify.

## What no longer exists

Most of what a search engine returns for "symfony count queries test" is dead code.

| Found in blog posts | Status |
|---|---|
| `Doctrine\DBAL\Logging\DebugStack` | **Removed in DBAL 4.** It was already deprecated in DBAL 3.2 when the middleware API landed. |
| `$connection->getConfiguration()->setSQLLogger(new DebugStack())` | Gone with it. `SQLLogger` no longer exists. |
| `$collector->getQueryCount()` | **Alive and correct.** It is on `Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector`, which doctrine-bundle's `DoctrineDataCollector` extends. Do not replace it with `count($collector->getQueries())` — that returns an array *per connection*. |

What replaced `DebugStack`: a DBAL `Driver\Middleware`
(`Doctrine\Bundle\DoctrineBundle\Middleware\DebugMiddleware`) wrapping every statement
and pushing a `Symfony\Bridge\Doctrine\Middleware\Debug\Query` into a shared collecting
object registered as **`doctrine.debug_data_holder`**
(`Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder`, extending
`Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder`).

Both counting routes below read from that same holder. They differ only in whether you
go through the profiler.

## Route 1 — the profiler, for endpoint tests

This is the default. It measures what the *request* did, which is the thing the test is
about.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * Counts the SQL queries one HTTP request executes.
 */
trait CountsQueries
{
    /** @var list<string> */
    private array $lastQueries = [];

    protected function countQueriesFor(KernelBrowser $client, string $method, string $url): int
    {
        // Everything the test itself did — fixtures, seeding — is still in the holder
        // before the first request of the test. See "The four traps" below.
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder, 'Doctrine profiling is off.');
        $holder->reset();

        $client->enableProfiler();
        $client->request($method, $url);

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'No profile: is framework.profiler enabled under when@test?');

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $this->lastQueries = [];
        foreach ($collector->getQueries() as $queriesForConnection) {
            foreach ($queriesForConnection as $query) {
                $this->lastQueries[] = (string) $query['sql'];
            }
        }

        return $collector->getQueryCount();
    }

    /**
     * The SQL of the last measured request, for assertion messages. Without it a
     * failure says "expected 2, got 14" and you start the investigation from zero.
     */
    protected function queryLog(): string
    {
        return "\n  - ".implode("\n  - ", $this->lastQueries);
    }
}
```

Used in a `WebTestCase`:

```php
#[Test]
public function the_shelf_costs_two_queries_whatever_its_size(): void
{
    $this->seed(1);
    $small = $this->countQueriesFor($this->client, 'GET', '/livres');

    $this->seed(9);
    $large = $this->countQueriesFor($this->client, 'GET', '/livres');

    self::assertSame(2, $small, 'shelf with one book:'.$this->queryLog());
    self::assertSame($small, $large, 'the query count grew with the row count:'.$this->queryLog());
}
```

**Why two assertions.** The first pins today's cost, so a regression is visible in the
diff of the test rather than hidden. The second is the actual definition of N+1: the
count must not depend on the number of rows. A single upper bound
(`assertLessThan(10, $count)`) catches neither a jump from 2 to 9 nor the case where
profiling is off entirely.

## Route 2 — the holder directly, for repository and service tests

No HTTP request, no profiler, no web-profiler-bundle needed. Use this to test a
repository method in isolation, which is where a fetch join belongs anyway.

```php
$holder = self::getContainer()->get('doctrine.debug_data_holder');
self::assertInstanceOf(DebugDataHolder::class, $holder);
$holder->reset();

$rows = $this->repository->findShelfRows();

$count = array_sum(array_map('count', $holder->getData()));
self::assertSame(1, $count);
```

`getData()` returns `array<connectionName, list<array{sql, params, types, executionMS}>>`,
hence the `array_sum(array_map('count', …))`. That is exactly what `getQueryCount()`
does on the collector.

Prefer route 1 for anything a user can hit: the endpoint is what regresses, and the
regression usually comes from the template rather than from the repository.

## The four traps

### 1. `APP_DEBUG=0` makes a bad test green

Doctrine's `profiling` option defaults to `%kernel.debug%`. With `APP_DEBUG=0` the
`DebugMiddleware` is not registered at all, and — verified — both routes return **0**.
An `assertLessThan(10, $count)` then passes on a page doing 400 queries.

Two defences, use both:

- assert an exact count with `assertSame()`, never only an upper bound;
- the `assertInstanceOf(DebugDataHolder::class, …)` in the trait fails loudly when the
  service has been optimised away.

If a project deliberately runs its test suite with `APP_DEBUG=0`, pin profiling on for
the test environment instead of giving up the check:

```yaml
# config/packages/doctrine.yaml
when@test:
    doctrine:
        dbal:
            profiling: true
```

### 2. The first request of a test does not reboot the kernel

`KernelBrowser::doRequest()` skips the shutdown/boot cycle for the first request,
because `createClient()` already booted the kernel. Consequence, verified: the fixtures
your `setUp()` just inserted are **still in the data holder** and get counted.

Symptom: the first measurement in a test file is inflated (5 instead of 1) and every
later one is right. The `$holder->reset()` at the top of the trait is the fix, and it is
also correct for the later requests, where the reboot has already emptied the holder.

### 3. `enableProfiler()` covers exactly one request

From the method's own docblock: *"Enables the profiler for the very next request."* The
flag is cleared inside `doRequest()`. Measuring two requests means calling it twice —
which the trait does, since it wraps one request.

### 4. `getProfile()` returns `false`, not an exception

It returns `false` when there is no response yet or when the container has no `profiler`
service. That happens on a project that removed `symfony/web-profiler-bundle`, or one
whose `config/packages/web_profiler.yaml` does not enable the profiler under `when@test`.
The stock recipe does:

```yaml
when@test:
    framework:
        profiler:
            collect: false      # enabled, but only collects when enableProfiler() asks
```

`collect: false` is not "profiler off" — it is exactly the mode this technique relies on.
If the bundle is genuinely absent, use route 2, which does not need it.

## Choosing the number to assert

Start by running the test with a deliberately wrong expectation and read the SQL from
`queryLog()`. Then assert what you see, minus what you can remove.

A typical HTML list endpoint on this standard:

| Query | Legitimate? |
|---|---|
| `SELECT … FROM book …` | Yes — the list itself |
| `SELECT COUNT(*) FROM book` | Yes, if the response is paginated with a `meta.total` |
| `SELECT … FROM review WHERE book_id = ?` × N | **No** — this is the N+1 |
| `SELECT … FROM messenger_messages …` | Yes, if the action dispatches an async message |
| `"START TRANSACTION"` / `"COMMIT"` | Counted. Every `flush()` is one transaction, and the collector logs both ends of it |

That last row matters, and it changed with the isolation mechanism. Under
`dama/doctrine-test-bundle` the collector shows a plain `"START TRANSACTION"` /
`"COMMIT"` pair around each `flush()` — DAMA's own transaction lives at the driver level,
below the debug middleware, so its savepoints never reach the log. Measured on
doctrine-bundle 3.3.1 / DBAL 4.4.4: one `flush()` inserting one row logs exactly three
entries, `"START TRANSACTION"`, the `INSERT`, `"COMMIT"`.

If a test still opens its own outer transaction — a `#[SkipDatabaseRollback]` test doing
its own cleanup, say — the same `flush()` logs `SAVEPOINT DOCTRINE_2` /
`RELEASE SAVEPOINT DOCTRINE_2` instead, because DBAL nests it. Either way: assert the
number you actually observe rather than the number you think is elegant.

## Re-verifying against vendor

Four commands, and they take less time than being wrong:

```bash
# the collector method really is there
grep -n 'function getQueryCount' vendor/symfony/doctrine-bridge/DataCollector/DoctrineDataCollector.php

# the holder service is declared by this version of doctrine-bundle
grep -n 'debug_data_holder' vendor/doctrine/doctrine-bundle/config/middlewares.php

# profiling is on in the test environment
php bin/console debug:container doctrine.debug_data_holder --env=test

# DebugStack is gone ("No such file or directory" on DBAL 4 = expected).
# Name the file, do not list the directory: on DBAL 4 the directory still
# exists and prints Connection/Driver/Middleware/Statement, which reads as
# though the class survived.
ls vendor/doctrine/dbal/src/Logging/DebugStack.php
```

## Older versions

The rule never changes; only the plumbing does.

- **DBAL 3.2+ / doctrine-bundle 2.6+** — identical to the above. The middleware and
  `doctrine.debug_data_holder` are already there.
- **DBAL 3.0–3.1, or doctrine-bundle < 2.6** — `DebugStack` still works:
  `$stack = new DebugStack(); $connection->getConfiguration()->setSQLLogger($stack);`
  then `count($stack->queries)`. Deprecated, but it is what those versions have.
- **Any version** — the profiler route (`getCollector('db')->getQueryCount()`) has
  worked unchanged across all of them, which is another reason to prefer it.
