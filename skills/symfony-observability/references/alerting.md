# Alerts, heartbeats and health checks

The mechanism behind `symfony-async`'s rule — "a message in the failure queue must
raise an alert" — plus the two things a failure listener alone cannot detect: a worker
that is not running, and a dependency that is down. Executed on Symfony 8.1.5 with the
Doctrine transport.

## 1. The failure alert

### What the framework already does

`SendFailedMessageForRetryListener` subscribes to `WorkerMessageFailedEvent` at priority
**100** and, once the retry strategy says no more attempts, writes
`messenger.CRITICAL: Error thrown while handling message … Removing from transport after
3 retries`. `SendFailedMessageToFailureTransportListener` then moves the envelope at
priority **-100**. A listener of your own at the default priority `0` therefore sits
between them: `willRetry()` is already accurate, the envelope has not moved yet.

If a Monolog handler already routes `critical` to a human, the rule is satisfied.

### The listener

```php
#[AsEventListener]
#[WithMonologChannel('alert')]
final readonly class AlertOnMessageSentToFailureTransport
{
    public function __construct(private LoggerInterface $logger) {}

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $envelope = $event->getEnvelope();

        $this->logger->critical('Message moved to the failure transport', [
            'message_class' => $envelope->getMessage()::class,
            'transport' => $event->getReceiverName(),
            'retries' => RedeliveryStamp::getRetryCountFromEnvelope($envelope),
            'exception' => $event->getThrowable(),
        ]);
    }
}
```

Verified: exactly one record for four attempts. `#[AsEventListener]` with no arguments
infers the event from the `__invoke` parameter type. `getThrowable()` returns the
`HandlerFailedException` wrapper, whose `getPrevious()` is your exception; putting it
under the `exception` key is the convention Monolog formatters and error trackers read.

### The sink — where this actually goes wrong

**Do not send it by email.** Measured, with `SendEmailMessage` routed to `async` as this
standard requires: a `symfony_mailer` handler fired, and the very next log line was
`messenger.INFO: Sending message …\SendEmailMessage with async sender using
…\DoctrineTransport`. The alert about the failing queue was queued on the failing queue.
Since the usual reason a message rots is that no worker is running, email is the one
alert guaranteed not to arrive when you need it — and routing `SendEmailMessage`
synchronously is not a fix, because it makes every business email block a request.

Sinks that do not re-enter Messenger:

| Sink | Config | Cost |
|---|---|---|
| `slackwebhook` | `webhook_url`, `channel`, `level: critical`, optional `exclude_fields: ['context.exception']` | A blocking HTTP call inside the failing process. Acceptable at `critical` only |
| `error_log` / `stream` on `php://stderr` | Nothing beyond the recipe | Free. Needs an alert rule on the platform side — that half is infrastructure |
| Error tracker as a `service` handler | `sentry/sentry-symfony`. monolog-bundle 4.0 **removed** the built-in `sentry` and `raven` types | Third-party dependency, best grouping and notification |

### An unreachable alert handler breaks the request

Measured, with the webhook URL pointing at a host that does not resolve: one
`$logger->critical(...)` produced an uncaught `Curl error (code 6): Could not resolve
host` and the response became a **500**. Monolog does not swallow handler exceptions
unless an exception handler is set, so a third-party alert sink becomes a runtime
dependency of every code path that logs. Wrap it in a `fallbackgroup`, which tries its
members in order and stops at the first that does not throw:

```yaml
when@prod:
    monolog:
        handlers:
            alert:
                type: fallbackgroup
                members: ['alert_slack', 'alert_stderr']
                level: critical
            alert_slack:
                type: slackwebhook
                webhook_url: '%env(SLACK_ALERT_WEBHOOK)%'
                channel: '#alerts'
                level: critical
                exclude_fields: ['context.exception']
                nested: true
            alert_stderr:
                type: stream
                path: php://stderr
                level: critical
                nested: true
```

Verified on monolog-bundle 4.0.2: with the same unreachable webhook, the record landed
on stderr and the process exited `0`. `nested: true` keeps the members out of the
top-level handler stack, so they only receive what the group passes them. Note there is
**no `channels:` filter**: `critical` is already the threshold that means "unplanned
failure" (see the level ladder in SKILL.md), so this one handler covers 500s, worker
failures and anything else that reaches it. `exclude_fields` takes dotted paths
(`context.*`, `extra.*`) and is monolog-bundle 3.11+.

`Symfony\Bridge\Monolog\Handler\NotifierHandler` turns records into Notifier
notifications, but monolog-bundle has no `notifier` handler type — it must be wired as a
`service` handler, and its transports are chat/SMS providers this standard has not made
a decision about. Prefer the table above.

## 2. The worker heartbeat

A `WorkerMessageFailedEvent` listener requires a worker. When the worker is dead nothing
fails — messages simply accumulate, and every alert built on failure stays silent. That
is the outage people describe as "we did not notice the queue was stuck".
`WorkerRunningEvent` is dispatched on every loop iteration, **including when the worker
is idle** (once per `sleep`, one second by default). Throttle it and write a timestamp
somewhere the web process can read:

```php
#[AsEventListener]
final class WorkerHeartbeat
{
    public const string CACHE_KEY = 'worker.heartbeat';

    private ?int $lastWrittenAt = null;

    public function __construct(
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.worker_heartbeat_period')] private readonly int $period = 30,
    ) {}

    public function __invoke(WorkerRunningEvent $event): void
    {
        $now = $this->clock->now()->getTimestamp();

        if (null !== $this->lastWrittenAt && $now - $this->lastWrittenAt < $this->period) {
            return;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set($now);
        $item->expiresAfter(3600);
        $this->cache->save($item);

        $this->lastWrittenAt = $now;
    }
}
```

Three things are deliberate, and the first is an exception to a rule this suite states
plainly. **It is `final` but not `final readonly`.** The standard's reason for `readonly`
is that a service which mutates itself behaves differently on the second request; here
the mutation *is* the feature — `$lastWrittenAt` is what throttles a listener that fires
once per worker loop — and the state is per-process, never read by anyone else, and
harmless if lost. That is the only shape of exception worth making: say it, and keep it
to the one property. **It is also not `ResetInterface`** — Messenger's
`ResetServicesListener` resets tagged services after each handled message, and a reset
`$lastWrittenAt` would defeat the throttle. And **`cache.app`, not a file**: the same
constraint `symfony-deployment` documents for `messenger:stop-workers` applies, so with
a filesystem `cache.app` the worker and the web container write to different pools and
the heartbeat is invisible to the health endpoint. Point `cache.app` at Redis, Valkey or
the database as soon as they are separate containers. The period is behaviour that is
the same everywhere, so it is an `app.` parameter, not an env var.

## 3. The queue-depth watchdog

Depth catches what the heartbeat and the failure listener both miss: a worker that is
alive but not keeping up, and a `failed` transport nobody drained. One rule about where
it runs: **not inside the worker it watches.** A Scheduler task
(`#[AsCronTask]`) is consumed by `messenger:consume scheduler_default`, so if the worker
layer is what died, the watchdog died with it. Run it from the platform's own scheduler
(cron, a Kubernetes `CronJob`) or expose it as a health check an external monitor polls.

For a shell-level probe, the console is enough:

```bash
php bin/console messenger:stats async failed --format=json
# {"transports":{"async":{"count":0},"failed":{"count":1}}}
php bin/console messenger:failed:show --stats
# There is 1 message pending in the failure transport.  App\Message\ImportShelf  1
```

`messenger:stats` is 6.2, `--format` is 7.2 (the `text` value was removed in 8.0, use
`txt`); `messenger:failed:show --stats` is 5.4. Both exit `0` regardless of the counts,
so a monitoring script must read the output, not the exit code. In PHP, inject the
transport directly — the Doctrine receiver implements `MessageCountAwareInterface`:

```php
final readonly class FailureQueueHealthCheck implements HealthCheck
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private MessageCountAwareInterface $failed,
    ) {}

    public function name(): string { return 'failure_queue'; }

    public function run(): bool { return 0 === $this->failed->getMessageCount(); }
}
```

Not every transport can count: `messenger:stats` reports `scheduler_default` as
uncountable, because `SchedulerTransport` does not implement the interface. Pointing the
check at one of those is **not** caught by `lint:container` — it reports the container as
fine and the `TypeError` arrives on first instantiation, at runtime. Same shape as the
`#[Target]` trap in `symfony-architecture`; the controller below survives it.

## 4. Health checks

```php
#[AutoconfigureTag('app.health_check')]
interface HealthCheck
{
    public function name(): string;

    public function run(): bool;
}
```

```php
final class HealthController
{
    /**
     * `getProvidedServices()` lives on Symfony\Contracts\Service\ServiceProviderInterface,
     * not on Psr\Container\ContainerInterface — and it is the templated one, so this
     * type-hint is what makes `$check->run()` resolve at PHPStan level max.
     *
     * @param ServiceProviderInterface<HealthCheck> $checks
     */
    public function __construct(
        #[AutowireLocator('app.health_check')] private readonly ServiceProviderInterface $checks,
    ) {}

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $results = [];
        $healthy = true;

        foreach (array_keys($this->checks->getProvidedServices()) as $id) {
            try {
                $check = $this->checks->get($id);
                $name = $check->name();
                $ok = $check->run();
            } catch (\Throwable) {
                $name = $id;
                $ok = false;
            }

            $results[$name] = $ok ? 'ok' : 'failed';
            $healthy = $healthy && $ok;
        }

        return new JsonResponse(
            ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $results],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
```

Verified end to end: `{"status":"ok","checks":{"database":"ok","failure_queue":"ok","worker":"ok"}}`
with 200, and after one message was left in the failure transport,
`{"status":"degraded",…,"failure_queue":"failed",…}` with 503. Four details that decide
whether it is worth anything:

- **A locator, not `#[AutowireIterator]`.** With an iterator each check is constructed by
  the `foreach` itself, *outside* any `try` around `run()` — measured: a check whose
  constructor threw a `TypeError` produced a 500 for the whole endpoint. With the
  locator, `get()` happens inside the `try`, and the same broken check came back as
  `{"App\\Health\\FailureQueueHealthCheck":"failed"}` with 503.
- **503, not 200 with a body saying "degraded".** Orchestrators read the status code and
  nothing else.
- **`#[Serialize]` cannot express this**: its `code` is fixed at attribute level, so the
  response cannot depend on the result. Return a `JsonResponse` directly.
- **`access_control` must let it through.** A health endpoint behind the firewall
  redirects the probe to a login form, a 302 the orchestrator reads as unhealthy — or
  worse, as healthy.

### Liveness versus readiness

They answer different questions, and conflating them causes restart loops.

| Probe | Question | Include | Exclude |
|---|---|---|---|
| **Liveness** | Is this process wedged? | Nothing but "PHP answered" | Every dependency. A database outage restarting all your containers makes the outage worse |
| **Readiness** | Should traffic go here? | Database, cache, anything a request needs | Things the *platform* should alert on rather than drain traffic for |
| **Monitoring** | Is the system healthy? | Failure-queue depth, worker heartbeat, third-party APIs | — |

The failure queue and the worker heartbeat belong to the third row: a full failure queue
is a page for a human, not a reason to stop routing HTTP traffic to a web container. Put
them on a separate route so the readiness probe does not take the site down because a
worker is behind.

### The database check

```php
public function run(): bool
{
    return 1 === (int) $this->connection
        ->executeQuery($this->connection->getDatabasePlatform()->getDummySelectSQL())
        ->fetchOne();
}
```

`getDummySelectSQL()` (DBAL 4, `AbstractPlatform`) returns the platform's portable
`SELECT 1` — Oracle needs `FROM DUAL`. It also does more than `isConnected()`: it forces
a real round trip, which catches a connection the driver still believes in. This is the
one place in the standard where SQL lives outside a repository, deliberately — it is not
a query about the domain, it is a connectivity probe. Keep it in a dedicated `Health\`
service so the exception to the rule stays visible.
