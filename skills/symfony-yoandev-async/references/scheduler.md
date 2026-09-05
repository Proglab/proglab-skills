# Scheduler

Recurring work, versioned with the code it runs.

## Contents

- [Before installing it](#before-installing-it)
- [The attributes](#the-attributes)
- [A schedule as a class](#a-schedule-as-a-class)
- [Missed runs and restarts](#missed-runs-and-restarts)
- [Locking](#locking)
- [Running it](#running-it)
- [Testing a scheduled task](#testing-a-scheduled-task)
- [Scheduler or crontab](#scheduler-or-crontab)

## Before installing it

```bash
composer require symfony/scheduler
composer require dragonmantank/cron-expression   # only if you use cron expressions
```

**A schedule only runs while a worker is running.** This is the difference that decides
whether Scheduler is an improvement or a regression on a given project:

| | crontab | Scheduler |
|---|---|---|
| Runs because | cron is part of the OS and is always up | `messenger:consume scheduler_default` is up |
| Lives | in `/etc/cron.d`, edited on the server | in `src/`, in the pull request |
| Visible in review | no | yes |
| Survives a deploy | yes, untouched | needs the supervisor to restart the worker |
| Survives the worker crashing | n/a | no — nothing runs until it comes back |

On a project with a process supervisor (see `references/messenger.md`), Scheduler is
better on every line that matters. On a project with no supervisor and no monitoring, a
crontab that calls `bin/console` is more honest, because a dead cron is visible at the OS
level and a dead worker is not. Say which situation you are in before writing the
attribute.

## The attributes

Both live in `Symfony\Component\Scheduler\Attribute` and both target a class or a method.

```php
final readonly class ShelfMaintenance
{
    public function __construct(private ShelfArchiver $archiver) {}

    #[AsCronTask(expression: '0 3 * * *', jitter: 60)]
    public function archiveAbandonedShelves(): void
    {
        $this->archiver->archiveAbandoned();
    }

    #[AsPeriodicTask(frequency: '10 minutes', from: '07:00:00', until: '23:00:00')]
    public function refreshTrendingBooks(): void
    {
        $this->archiver->refreshTrending();
    }
}
```

The parameters, verified against the attribute constructors:

- `AsCronTask(string $expression, ?string $timezone, ?int $jitter, array|string|null $arguments, string $schedule = 'default', ?string $method, array|string|null $transports)`
- `AsPeriodicTask(string|int $frequency, ?string $from, ?string $until, ?int $jitter, array|string|null $arguments, string $schedule = 'default', ?string $method, array|string|null $transports)`

`frequency` accepts a human string (`'10 minutes'`), an integer number of seconds, or a
`\DateInterval`. `jitter` is a number of **seconds** of random delay — set it on anything
that hits an external API, so twenty instances of the application do not call it on the
same second.

`#[AsCronTask]` needs `dragonmantank/cron-expression`; without it, it throws a
`LogicException` naming the package to install. `#[AsPeriodicTask]` has no such
dependency.

**Hashed expressions are the better default for anything not tied to a wall-clock time.**
`#[AsCronTask('#daily')]` picks a stable minute and hour derived from a SHA-256 of the
task's own identity (`@service::method`), so it is the same every run but different from
every other task and from the same task on another application. The full alias list, from
`CronExpressionTrigger::HASH_ALIAS_MAP`, is eleven entries:

```
#hourly  #daily  #weekly  #monthly  #annually  #yearly  #midnight
#weekly@midnight  #monthly@midnight  #annually@midnight  #yearly@midnight
```

The `@midnight` suffix is **part of the alias** — written `#weekly@midnight`, keeping the
leading `#` — and it constrains the hashed hour to 0–2. There is no `#hourly@midnight` or
`#daily@midnight`: `#midnight` *is* the daily-at-night one. A bare `#` in any of the five
positions is randomised over that field's range. Mind the sigil: these are `#`, not the
`@daily` form other cron implementations use — `@daily` is not recognised. Use
`'0 3 * * *'` only when 03:00 genuinely matters.

One constraint if you combine this with the class-based schedule below: a hashed
expression needs the message to be `\Stringable`, because the hash is taken over
`(string) $message`. `RecurringMessage::cron('#daily', $message)` on a plain message class
throws *"A message must be stringable to use \"hashed\" cron expressions."* Attribute-based
tasks are unaffected — `ServiceCallMessage` is already `\Stringable`, which is where the
`@service::method` identity comes from.

**The method is a translation layer**, exactly like a command or a handler: it calls a
service and holds no rule. That is what makes the schedule replaceable — the same
`ShelfArchiver::archiveAbandoned()` can be triggered by a console command, an admin
button, or a test, and none of them go through the scheduler.

## A schedule as a class

Attributes are enough for most projects. Use `#[AsSchedule]` when the schedule itself
needs configuration — locking, state, several tasks sharing a policy:

```php
#[AsSchedule('maintenance')]
final readonly class MaintenanceSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        private LockFactory $lockFactory,
    ) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                RecurringMessage::cron('0 3 * * *', new ArchiveAbandonedShelves()),
                RecurringMessage::every('10 minutes', new RefreshTrendingBooks()),
            )
            ->stateful($this->cache)
            ->lock($this->lockFactory->createLock('maintenance-schedule'))
            ->processOnlyLastMissedRun(true);
    }
}
```

`#[AsSchedule(name: 'maintenance')]` registers the provider under that name, and the
transport becomes `scheduler_maintenance`. The messages produced are ordinary Messenger
messages with ordinary handlers, so the three rules in `SKILL.md` apply unchanged.

A schedule named anything other than `default` needs its own worker, or its name added to
an existing `messenger:consume` invocation. Forgetting this is the most common reason a
task added to a second schedule never fires.

## Missed runs and restarts

By default the scheduler computes the next run from the moment it starts. A worker that
was down for two hours therefore skips whatever should have happened during those two
hours — usually fine, occasionally not.

- `->stateful($cache)` persists the checkpoint in a cache pool, so a restarted worker
  knows where it was and replays what it missed.
- `->processOnlyLastMissedRun(true)` (Symfony 7.2+) caps that replay at one run. Without
  it, a worker restarting after a long outage fires every missed occurrence in a burst —
  for a ten-minute task and a day of downtime, that is 144 messages at once.

Combine both unless you specifically need every missed run. And note that this is
exactly the situation rule 2 exists for: the replayed task must be safe to run again.

## Locking

`->lock()` prevents two workers from running the same schedule concurrently — necessary
as soon as `numprocs` is above 1 or two servers each run a worker. It needs
`symfony/lock` with a **shared** store: a `FlockStore` on separate machines locks
nothing.

Locking the *task* is a different concern and belongs to the command it calls; see
`symfony-yoandev-console` for `LockableTrait` and why a six-minute job scheduled every five
minutes eventually takes the server down.

## Running it

```bash
php bin/console debug:scheduler                    # every schedule, task and next run date
php bin/console messenger:consume scheduler_default --time-limit=3600 --memory-limit=128M
```

The transport name is `scheduler_` plus the schedule name, always. Give it a supervisor
entry of its own rather than merging it into the `async` worker: the scheduler transport
is a generator that emits on a timer, and mixing it with a queue that occasionally has a
thousand pending messages delays the timer behind them.

`debug:scheduler` takes a schedule name as an argument, `--all` to include terminated
recurring messages, `--date` to compute next runs from a given moment, and `--sort` (8.1)
to order by next run date.

## Testing a scheduled task

The task method is a translation layer, so the meaningful test is on the service it
calls, exactly as with a console command. Two things are still worth asserting:

```php
// The task delegates, and holds nothing.
$archiver = $this->createMock(ShelfArchiver::class);
$archiver->expects(self::once())->method('archiveAbandoned');

(new ShelfMaintenance($archiver))->archiveAbandonedShelves();
```

```php
// The schedule declares what you think it declares.
$schedule = self::getContainer()->get(MaintenanceSchedule::class)->getSchedule();

self::assertCount(2, $schedule->getRecurringMessages());
```

The second is a mutation-check case from the testing standard: the attribute or the
`add()` call is declarative, so there is no red-first step. Change the expression, watch
the test go red, change it back — that is the proof the test measures something.

Do not test that the trigger fires at 03:00. That is Symfony's `CronExpressionTrigger`,
it is already tested, and reimplementing the clock in your suite buys nothing.

## Scheduler or crontab

Use Scheduler when the project already runs Messenger workers under a supervisor — which,
on this standard, it does as soon as anything is asynchronous. The schedule then travels
with the code, appears in review, and is identical in every environment.

Keep a crontab when the recurring job must run whether or not the application's workers
are healthy: log rotation, backups, a watchdog that checks the workers themselves. A
scheduler cannot supervise the process it depends on.
