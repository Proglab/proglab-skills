# Messenger in detail

Transports, retries, workers, and the commands you will actually type.

## Contents

- [The full configuration](#the-full-configuration)
- [Creating the table](#creating-the-table)
- [Retryable versus permanent failures](#retryable-versus-permanent-failures)
- [Delaying a message](#delaying-a-message)
- [Running workers in production](#running-workers-in-production)
- [The commands](#the-commands)
- [Testing dispatch and handling](#testing-dispatch-and-handling)
- [When to leave Doctrine](#when-to-leave-doctrine)

## The full configuration

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        failure_transport: failed

        transports:
            async_high:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options: { queue_name: high }
                retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2, jitter: 0.3 }
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options: { queue_name: default }
                retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2, jitter: 0.3 }
            failed: 'doctrine://default?queue_name=failed'

        routing:
            Symfony\Component\Mailer\Messenger\SendEmailMessage: async_high

when@test:
    framework:
        messenger:
            transports:
                async_high: 'in-memory://'
                async: 'in-memory://'
```

Notes that matter:

- **Email goes to the high-priority transport.** A signup confirmation competing with a
  nightly export is the exact problem prioritised queues exist to solve.
- **Application messages use `#[AsMessage('async')]`** rather than the `routing:` block.
  Third-party messages (`SendEmailMessage`) have to be routed here, because you cannot
  put an attribute on someone else's class. On Symfony 6.4–7.1, everything goes in
  `routing:`.
- **`when@test` overrides the DSN, not the transport names.** Keeping the names identical
  means the routing under test is the routing in production.
- Symfony 8.1 deprecates the nested `senders:` key under `routing:`. Write a flat list.

## Creating the table

With `auto_setup=0` the transport never issues DDL. Two ways to get the table, and only
one of them belongs in a repository:

```bash
php bin/console make:migration          # the table is in the generated schema — commit this
php bin/console messenger:setup-transports   # imperative; fine locally, not a deploy step
```

Doctrine's `MessengerTransportDoctrineSchemaListener` adds `messenger_messages` to the
schema Doctrine generates, so the diff picks it up like any entity table. Review the
migration as you would any other (see `symfony-yoandev-doctrine`).

## Retryable versus permanent failures

The retry strategy cannot tell "the payment API returned 503" from "this shelf was
deleted". The handler has to, by the exception it lets escape.

| Situation | Throw | Outcome |
|---|---|---|
| The referenced row no longer exists | `UnrecoverableMessageHandlingException` | Straight to `failed`, no retries |
| Invalid payload, a message shape you no longer support | `UnrecoverableMessageHandlingException` | Same |
| HTTP timeout, deadlock, connection reset | anything else | 3 retries with backoff |
| A rate limit with a known reset time | `RecoverableMessageHandlingException` | Retried, optionally after `$retryDelay` ms |

`RecoverableMessageHandlingException` has a trap. Its constructor is
`(string $message, int $code, ?\Throwable $previous, ?int $retryDelay, bool $forceRetry = true)`
and **`forceRetry` defaults to `true`**, which retries regardless of `max_retries` — an
infinite loop against an endpoint that is permanently returning 429. Pass
`forceRetry: false` unless you have specifically decided otherwise. The parameter arrived
in Symfony 8.1 (with `RecoverableExceptionInterface::forceRetry()`); before that the
forced retry was unconditional, so on 6.4–8.0 use a plain exception and let the
configured strategy bound the attempts.

## Delaying a message

```php
$this->bus->dispatch(new SendReadingReminder($readingId), [new DelayStamp(3_600_000)]);
```

Milliseconds. The Doctrine transport stores `available_at` and the worker skips the row
until then, so the delay costs nothing while it waits.

This is not a scheduler. `DelayStamp` is "do this once, later"; a recurring job is
`references/scheduler.md`.

## Running workers in production

A worker is a long-running PHP process, which PHP was not designed to be. Three things
follow, and skipping any of them produces a specific outage:

```bash
php bin/console messenger:consume async_high async \
    --time-limit=3600 \
    --memory-limit=128M \
    --limit=1000 \
    -v
```

- **`--memory-limit`** — Messenger resets services between messages, which handles
  Doctrine's identity map, but not extension-level allocations, static caches in
  libraries, or heap fragmentation. Without a limit the process is OOM-killed
  mid-message at an unpredictable moment; with one it exits cleanly *between* messages
  and the supervisor restarts it. Set it below the container limit.
- **`--time-limit`** — the same insurance against slow leaks and stale connections.
- **A supervisor.** Both limits only work because something restarts the process. A
  worker with `--memory-limit` and no supervisor is a worker that stops after an hour.

`supervisor`:

```ini
[program:messenger-async]
command=php /srv/app/bin/console messenger:consume async_high async --time-limit=3600 --memory-limit=128M
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
startsecs=0
stopwaitsecs=20
```

`systemd`:

```ini
[Service]
ExecStart=/usr/bin/php /srv/app/bin/console messenger:consume async_high async --time-limit=3600 --memory-limit=128M
Restart=always
RestartSec=1
TimeoutStopSec=20
```

`stopwaitsecs` / `TimeoutStopSec` is the graceful-restart budget: Messenger stops on
`SIGTERM` **after finishing the message in flight**. Too short and the supervisor
`SIGKILL`s a handler halfway through — the message is redelivered after
`redeliver_timeout` (3600s by default), and idempotency is what saves you.

`numprocs` above 1 is safe with Doctrine because of `SKIP LOCKED`.

**On deploy, `messenger:stop-workers` is not optional.** It sets a cache flag; each
worker finishes its current message and exits, and the supervisor starts one on the new
code. Skip it and workers keep running the previous release — deserialising new messages
with old handlers, which is where "it works locally" bugs come from. The full ordering
is in `symfony-yoandev-deployment`.

## The commands

| Command | Use |
|---|---|
| `messenger:consume <t1> <t2>` | Run a worker; receivers are drained in the order given |
| `messenger:stats` | Pending count per transport — the first thing to check |
| `messenger:failed:show` | List the failure transport; `messenger:failed:show <id>` shows one with its exception, `--stats` counts by class |
| `messenger:failed:retry` | Requeue after fixing the cause; interactive by default |
| `messenger:failed:remove` | Drop a message that will never succeed (`--class-filter` since 7.3) |
| `debug:messenger` | Which handler handles which message, per bus |
| `messenger:setup-transports` | Create transport infrastructure by hand |

## Testing dispatch and handling

**The handler, with no framework at all.** It is a translation layer, so the test is a
constructor call and an assertion that the service was invoked.

```php
#[CoversClass(ImportShelfHandler::class)]
final class ImportShelfHandlerTest extends TestCase
{
    #[Test]
    public function itImportsTheShelfItWasGivenTheIdOf(): void
    {
        $shelf = new Shelf();
        $shelves = $this->createStub(ShelfRepository::class);
        $shelves->method('find')->willReturn($shelf);

        $importer = $this->createMock(ShelfImporter::class);
        $importer->expects(self::once())->method('import')->with($shelf, '/tmp/shelf.csv');

        (new ImportShelfHandler($shelves, $importer))(new ImportShelf(12, '/tmp/shelf.csv'));
    }
}
```

`createStub()` for the repository (no expectation), `createMock()` only where there is an
`expects()` — from PHPUnit 11 a mock with no expectation emits a *PHPUnit* notice, and
this standard runs `failOnPhpunitNotice="true"` (the recipe's `failOnNotice` covers PHP
notices only and does not catch it — see `symfony-yoandev-testing`).

**The service that dispatches.**

```php
$bus = $this->createMock(MessageBusInterface::class);
$bus->expects(self::once())
    ->method('dispatch')
    ->with(self::callback(static fn (ImportShelf $m): bool => 12 === $m->shelfId))
    ->willReturn(new Envelope(new ImportShelf(12, '/tmp/shelf.csv')));
```

Without `willReturn()`, the mock returns `null` against a `: Envelope` return type and
the test dies with a `TypeError` that points at Symfony's code.

**Functionally, through the kernel**, using the in-memory transport declared under
`when@test`:

```php
$client->request('POST', '/shelves/12/import', [], ['file' => $upload]);

$transport = self::getContainer()->get('messenger.transport.async');
\assert($transport instanceof InMemoryTransport);

self::assertCount(1, $transport->getSent());
self::assertInstanceOf(ImportShelf::class, $transport->getSent()[0]->getMessage());
```

`getSent()` returns `Envelope[]`, not messages. `getAcknowledged()` and `getRejected()`
exist too, and `reset()` clears all three.

Do not write a test that boots a real worker. If you want to prove the handler runs
against a real database, call the handler yourself inside a `KernelTestCase` — same
coverage, no process, no timing.

## When to leave Doctrine

Signals, in the order they appear: `messenger:stats` regularly showing a backlog while
workers are healthy; the queue table growing faster than autovacuum reclaims it; workers
polling often enough to show up in slow-query logs; a need for fan-out to several
consumers.

Redis (`symfony/redis-messenger`, `MESSENGER_TRANSPORT_DSN=redis://localhost:6379/messages`)
removes the load from the business database and is the smaller step. AMQP
(`symfony/amqp-messenger`) adds exchanges, routing keys and broker-side dead-lettering,
and adds a broker to operate.

Either way the migration is: deploy the new transport alongside, route new messages to
it, keep a worker on the old one until `messenger:stats` reports zero, remove it. The
messages and handlers do not change — which is the payoff for rule 1 and rule 3.
