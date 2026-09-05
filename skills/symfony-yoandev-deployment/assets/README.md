# Assets

Two ways to keep Messenger workers running. **Pick one.** Two process managers
supervising the same command produce twice the consumers and a queue that looks
half-consumed to each of them.

| File | Install as | For |
|---|---|---|
| `messenger-worker@.service` | `/etc/systemd/system/messenger-worker@.service` | A VM or bare-metal host that already runs systemd |
| `supervisor-messenger.conf` | `/etc/supervisor/conf.d/messenger-worker.conf` | A host without systemd, or a container that must run workers beside the web server |

There is no Dockerfile here on purpose: use
[`dunglas/symfony-docker`](https://github.com/dunglas/symfony-docker) and let Flex keep
it up to date through its `###> recipes ###` markers. A copy in this repository would be
stale within a release.

## What to adapt in both

| Value | Default here | How to get it right |
|---|---|---|
| PHP binary | `/usr/local/bin/php` | `which php` on the target host |
| Project directory | `/app` | Where the release actually lives |
| User | `www-data` | The user that owns `var/`. Never root — a worker that writes cache files as root breaks the next web request |
| Transport names | `async` | The keys under `framework.messenger.transports`, in priority order. `messenger:consume async failed` would also drain the failure queue, which is almost never what you want |
| `APP_ENV`, `APP_DEBUG` | `prod`, `0` | Only needed for values `.env.local.php` does not already carry |
| Concurrency | 2 | Instances enabled (systemd) or `numprocs` (supervisor) |

## The two settings people get wrong

**Restart on clean exit.** `--time-limit` and `--memory-limit` make the worker exit with
status 0, deliberately, between messages. systemd needs `Restart=always` and supervisor
needs `autorestart=true` — not `on-failure`, not `unexpected`. With those, the worker
stops after an hour and nothing ever brings it back; the queue grows and the only symptom
is latency.

**Stop timeout longer than the slowest handler.** `TimeoutStopSec` / `stopwaitsecs` is
how long the manager waits after SIGTERM before SIGKILL. A handler killed mid-run leaves
its message neither acknowledged nor rejected, and what happens to it next is decided by
the transport's visibility timeout rather than by you. 120 seconds is a starting point;
measure your slowest handler.

## Where these fit in a deploy

```bash
php bin/console messenger:stop-workers    # after the new code is live
```

That writes a timestamp into the `cache.messenger.restart_workers_signal` pool — a child
of `cache.app`, which is why the sharing rule below is about `cache.app`. It sends no
signal to any process. Workers read the timestamp between messages,
finish what they are holding, and exit — and the unit files above restart them on the new
code. The stop command and the process manager are two halves of one mechanism: without
the manager, `messenger:stop-workers` just stops your workers.

The deploy job must write to the **same** `cache.app` backend the workers read. Separate
containers with filesystem-backed caches do not share one, and the signal is lost
silently. See `../references/deploy-sequence.md`.

## Locked and scheduled commands

Scheduler tasks (`#[AsCronTask]`, `#[AsPeriodicTask]`) also need a running worker — the
same unit with the scheduler transport in `command`. Long or periodic console commands
need `symfony/lock` regardless of the process manager: a six-minute task started every
five minutes stacks until the host dies. That rule belongs to `symfony-yoandev-console` and
`symfony-yoandev-async`; it is repeated here because a process manager is exactly what turns it
from a risk into an outage.
