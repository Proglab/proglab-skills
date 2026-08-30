# Monolog: channels, handlers, processors

Everything below was executed on Symfony 8.1.5 / PHP 8.4 with monolog-bundle 4.0.2 and
monolog 3.10. `vendor/` is the source of truth over this file.

## Inspecting what is actually wired

Four commands, and they answer most questions faster than reading the YAML:

```bash
php bin/console debug:config monolog                              # merged config, per environment
php bin/console debug:container --tag=monolog.channel_logger      # every channel that exists
php bin/console debug:container --tag=monolog.processor           # processors, with channel/handler/priority
php bin/console debug:container --env=prod monolog.logger.alert   # does this channel exist in prod?
```

`debug:config` is per-environment: run it with `--env=prod` before concluding that a
handler is missing.

## Channels

### Declaring

```yaml
monolog:
    channels: ['deprecation', 'business', 'alert']
```

`monolog.channels` creates `monolog.logger.<name>` as a **public** service (the
`LoggerChannelPass` calls `setPublic(true)` on additional channels), which is what
makes `self::getContainer()->get('monolog.logger.alert')` work in a test.

Symfony's own channels, which you filter rather than declare: `doctrine`, `messenger`,
`request`, `security`, `cache`, `http_client`, `mailer`, `console`, `router`, `event`,
`php`, `translation`, `asset_mapper`, `profiler`, `lock`, `workflow`, `debug`.

### Injecting

```php
#[WithMonologChannel('business')]           // Monolog 3.5 + monolog-bundle 3.9
final readonly class PublishReview
{
    public function __construct(private LoggerInterface $logger) {}
}
```

```php
final readonly class ImportShelf
{
    public function __construct(
        #[Target('business')] private LoggerInterface $logger,       // argument-level
        #[Target('alert')] private LoggerInterface $alertLogger,
    ) {}
}
```

All four forms in the SKILL table were verified to yield a logger whose `getName()` is
the channel. On Symfony < 6.4, or a monolog-bundle older than 3.9, fall back to the tag:

```yaml
services:
    App\Service\PublishReview:
        tags: [{ name: 'monolog.logger', channel: 'business' }]
```

### Filtering handlers by channel

```yaml
handlers:
    main:
        type: fingers_crossed
        channels: ['!deprecation', '!doctrine']    # exclusive: everything except these
    business_audit:
        type: stream
        path: php://stdout
        channels: ['business']                     # inclusive: only these
```

A handler with no `channels:` key receives every channel. Mixing `foo` and `!bar` in
one list is a configuration error — a list is either inclusive or exclusive.

## Processors

A processor is any invokable receiving and returning a `Monolog\LogRecord`. Register it
with `#[AsMonologProcessor]` (monolog-bundle 3.8+, autoconfigured), whose four arguments
are `channel`, `handler`, `method` and `priority`.

```php
#[AsMonologProcessor]
final readonly class CorrelationIdProcessor
{
    public function __construct(private CorrelationId $correlationId) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['correlation_id'] = $this->correlationId->get();

        return $record;
    }
}
```

`$record->extra` and `$record->context` are mutable in place; `LogRecord::with(...)`
returns a modified copy and is what you want when replacing a whole array.

**Convention: `context` is what the call site passed, `extra` is what a processor
added.** Keeping them apart is what lets a redacting processor rewrite `context`
without touching the machine-generated fields.

Ordering: processors registered on the logger run before processors registered on a
handler. Monolog's own `PsrLogMessageProcessor` (the `process_psr_3_messages` handler
option) is a handler processor, so a logger-level redactor at any priority still runs
first — measured: a `{password}` placeholder interpolated to `[redacted]`.

Below monolog-bundle 3.8:

```yaml
services:
    App\Logging\CorrelationIdProcessor:
        tags: [{ name: 'monolog.processor' }]
```

### The correlation id, in full

```php
final class CorrelationId implements ResetInterface
{
    private ?string $value = null;

    public function __construct(private readonly RequestStack $requestStack) {}

    public function get(): string
    {
        return $this->value ??= $this->fromRequestOrNew();
    }

    public function set(string $value): void
    {
        $this->value = $value;
    }

    public function reset(): void
    {
        $this->value = null;
    }

    private function fromRequestOrNew(): string
    {
        $incoming = $this->requestStack->getMainRequest()?->headers->get('X-Request-Id');

        return null !== $incoming && 1 === preg_match('/^[A-Za-z0-9._-]{1,64}$/', $incoming)
            ? $incoming
            : bin2hex(random_bytes(8));
    }
}
```

`ResetInterface` is autoconfigured to `kernel.reset`, which Messenger's
`ResetServicesListener` calls after each handled message — without it a worker reuses
one id for its whole life. The regex on the incoming header matters: the value ends up
in log files and, if you echo it back, in a response header.

The Messenger half, registered as a bus middleware:

```php
final readonly class CorrelationIdMiddleware implements MiddlewareInterface
{
    public function __construct(private CorrelationId $correlationId) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle(
                $envelope->with(new CorrelationIdStamp($this->correlationId->get())),
                $stack,
            );
        }

        if (($stamp = $envelope->last(CorrelationIdStamp::class)) instanceof CorrelationIdStamp) {
            $this->correlationId->set($stamp->correlationId);
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
```

```yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware: ['App\Messenger\CorrelationIdMiddleware']
```

`ReceivedStamp` is what distinguishes the two directions: absent on dispatch, present
when the worker replays the envelope. The stamp is serialised with the message, so it
survives the transport.

### Who is logged in

`Symfony\Bridge\Monolog\Processor\TokenProcessor` adds `extra.token` with
`authenticated`, `roles` and `user_identifier`. It is not registered by default:

```yaml
services:
    Symfony\Bridge\Monolog\Processor\TokenProcessor:
        tags: [{ name: 'monolog.processor' }]
```

`user_identifier` is usually an email address. That is personal data in every log line
— see `sensitive-data.md` before enabling it.

`WebProcessor` (also from the bridge) adds URL, IP, method and referrer, and takes an
`$extraFields` constructor argument restricting which of them are recorded.

## fingers_crossed

```yaml
handlers:
    main:
        type: fingers_crossed
        action_level: error          # the trigger
        handler: nested              # where the flush goes
        excluded_http_codes: [404, 405]
        buffer_size: 50
        channels: ['!deprecation']
    nested:
        type: stream
        path: php://stderr
        level: debug
        formatter: monolog.formatter.json
```

| Option | Effect |
|---|---|
| `action_level` | Level that releases the buffer. `error` in the recipe |
| `buffer_size` | Records kept while buffering. `0` is unbounded — a memory leak on a long request |
| `stop_buffering` | Default `true`: once triggered, everything else passes through for the rest of the process |
| `passthru_level` | Records at this level or above are written even when nothing ever triggers. Set it to `error` if you want errors in a CLI process that never reached `critical` |
| `excluded_http_codes` | Response statuses that do **not** trigger. Was `excluded_404s`, removed in monolog-bundle 4.0 |

`FingersCrossedHandler::reset()` calls `flushBuffer()`, which with no `passthru_level`
**discards** the buffer and returns to buffering. The handler is tagged `kernel.reset`,
so a worker starts each message clean and a successful message writes nothing.

The nested handler is at `level: debug` on purpose: the buffer is only worth flushing if
it contains the debug trail. Raising it to `error` makes the whole mechanism pointless.

## Environments

| Environment | Shape | Why |
|---|---|---|
| `dev` | plain `stream` at `debug`, plus `console` | You want every line, immediately |
| `test` | `fingers_crossed` into a stream | A green test writes nothing; a failing one leaves a trail |
| `prod` | `fingers_crossed` → stream on `php://stderr`, JSON formatter | Owned by `symfony-deployment` — verify it, do not rewrite it |

The `deprecation` channel gets its own prod handler in the recipe, unbuffered. Keep it:
deprecations are the early warning for the next Symfony upgrade, and they never reach
`error`, so `fingers_crossed` would swallow them forever.

## Asserting that something was logged

A rule that is only in a log line is still a rule. Unit-test it with a `TestHandler`:

```php
#[Test]
public function it_alerts_once_the_retries_are_exhausted(): void
{
    $handler = new TestHandler();
    $listener = new AlertOnMessageSentToFailureTransport(new Logger('alert', [$handler]));

    $listener(new WorkerMessageFailedEvent(
        new Envelope(new ImportShelf(12, '/tmp/shelf.csv')),
        'async',
        new \RuntimeException('the API was down'),
    ));

    self::assertTrue($handler->hasRecordThatContains(
        'Message moved to the failure transport',
        Level::Critical,
    ));
}
```

Verified green on PHPUnit 13.3.2. `TestHandler` also offers per-level magic methods
(`hasCriticalRecords()`, `hasCriticalThatContains()`, `hasCriticalThatMatches()`,
`hasCriticalThatPasses()`) and `getRecords()` — asserting `getRecords() === []` is how
you prove the *quiet* path, which is the half people forget.

In a functional test, fetch the public channel logger and push a `TestHandler` onto it:
`self::getContainer()->get('monolog.logger.alert')->pushHandler($handler)`.
