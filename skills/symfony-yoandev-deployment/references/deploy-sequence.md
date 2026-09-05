# The deploy sequence

## Contents

- [Build time and release time](#build-time-and-release-time)
- [What the build does](#what-the-build-does)
- [What the release does](#what-the-release-does)
- [Migrations at release](#migrations-at-release)
- [The interruption window, and the pattern that removes it](#the-interruption-window-and-the-pattern-that-removes-it)
- [Workers](#workers)
- [Verifying the deploy](#verifying-the-deploy)
- [Rollback](#rollback)

## Build time and release time

The same list of commands, split by a single question: **does this step need the
database or affect what is currently running?**

| | Build (in the image) | Release (once per deploy) |
|---|---|---|
| Needs a database | no | yes |
| Runs how many times | once per image | once per deploy, not once per replica |
| Failure means | the image is not produced, nothing shipped | production is mid-change — this is where care goes |

Everything that can be moved into the build should be. A step in the build fails in CI,
in front of the person who caused it. The same step at release fails on a live system.

## What the build does

`dunglas/symfony-docker`'s `frankenphp_prod_builder` stage, in order — read its
`Dockerfile` rather than trusting this summary, it is what actually runs:

```dockerfile
COPY --link composer.* symfony.* ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

COPY --link --exclude=frankenphp/ . ./

RUN <<-EOF
	mkdir -p var/cache var/log var/share
	composer dump-autoload --classmap-authoritative --no-dev
	composer dump-env prod
	composer run-script --no-dev post-install-cmd
	if [ -f importmap.php ]; then
		php bin/console asset-map:compile
	fi
EOF
```

Why it is in that order:

- **Manifests first, sources second.** Editing a controller must not reinstall vendors.
  This is the single biggest lever on build time.
- **`--no-autoloader --no-scripts`, then `dump-autoload` after the sources are copied.**
  A classmap generated before the source exists would be empty.
- **`--classmap-authoritative`** goes further than `--optimize-autoloader`: Composer
  stops looking at the filesystem entirely for a class it does not know. Faster, and
  fatal if anything generates classes at runtime — which nothing in this standard does.
- **`composer dump-env prod`** writes `.env.local.php`. After it, `Dotenv` includes one
  compiled PHP array instead of parsing `.env`, `.env.prod`, `.env.local` on every
  request.
- **`post-install-cmd`** runs Flex's `auto-scripts`, which includes `cache:clear` — and
  `cache:clear` warms up unless you pass `--no-warmup`, including the optional warmers
  that write `var/cache/prod/App_KernelProdContainer.preload.php`. That file is what
  `config/preload.php` requires. **No warm prod cache in the image, no preloading**, and
  the failure is silent because `config/preload.php` guards the require with
  `file_exists()`.
- **`asset-map:compile`** writes hashed files into `public/assets/`. Without it, the
  AssetMapper falls back to serving each asset through the PHP front controller, at
  runtime, forever.

Verify the build actually did all of it before trusting the image — run the
production-like check from `symfony-yoandev-local-dev` and look at the network panel.

## What the release does

Everything else, as a job that runs **once**, not in each container's entrypoint:

```bash
php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
# roll out the new containers here
php bin/console messenger:stop-workers
# the process manager restarts the workers on the new image
```

`--all-or-nothing` wraps the whole migration run in one transaction, so a failure on the
third migration does not leave the schema between two states. It is the right default on
PostgreSQL, which has transactional DDL. MySQL does not: DDL commits implicitly there, so
the flag buys much less and a failed run needs manual repair either way.

`symfony-docker`'s entrypoint runs the migration itself when `migrations/` is non-empty.
That is right for a single container and wrong the moment you scale: N replicas start,
N migration runs race for the same lock, N-1 wait, and a slow migration turns into a
startup timeout that the orchestrator answers by killing and restarting the container —
which starts the migration again. Move it to a release job (a Kubernetes `Job`, a
one-shot `docker compose run --rm php`, a step in the pipeline) and let the containers
start clean.

## Migrations at release

Three rules, and the reason for each:

- **One runner.** Doctrine takes a lock, so concurrent runs are safe but not useful;
  what they produce is a queue of containers that look hung.
- **Fail the deploy on a failed migration.** `--no-interaction` makes the command
  non-blocking, not tolerant. Check the exit code and stop — rolling out code against a
  half-migrated schema is worse than not deploying.
- **Log the SQL.** `-vv` prints every statement. When a migration takes twenty minutes
  on production data and two seconds locally, that output is the whole investigation.

A dry run against a copy of production is worth more than any review:

```bash
php bin/console doctrine:migrations:migrate --dry-run -vv
```

If the project uses the Doctrine Messenger transport with `auto_setup=0` — the current
recipe default — the `messenger_messages` table is contributed to the schema by the
transport, so `make:migration` picks it up like any other table. It is not magic that
happens at runtime; if the table is missing, a migration is missing.

## The interruption window, and the pattern that removes it

This standard runs migrations without a backward-compatibility constraint. Between the
migration and the last old container being replaced, **old code is talking to the new
schema.** Seconds, usually. Sometimes a 500 or two.

That is a choice, not an oversight. The alternative — every schema change designed to be
readable by both the old and the new code — makes every rename a multi-release project
and leaves intermediate states in production for as long as nobody finishes the
sequence.

**If you genuinely need zero downtime, deviate on purpose** and use expand/contract:

| Release | Schema | Code |
|---|---|---|
| 1 | Add `published_at` nullable | ignores it |
| 2 | — | writes both `published_at` and the old `is_published` |
| 3 | Backfill `published_at` from existing rows | unchanged |
| 4 | `published_at` NOT NULL | reads `published_at` only |
| 5 | Drop `is_published` | unchanged |

Five deploys, each one safe to roll back, none of them ever incompatible with the code
running beside it. That is the actual price of the guarantee — pay it where it is worth
paying (a checkout, a payment table), not everywhere.

## Workers

### How `messenger:stop-workers` really works

It sends no signal. It writes a timestamp:

```php
$cacheItem = $this->restartSignalCachePool->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);
$cacheItem->set(microtime(true));
$this->restartSignalCachePool->save($cacheItem);
```

Each worker compares that timestamp to its own start time between messages, finishes the
message it is holding, and exits. Three consequences that decide whether it works at all:

- **The pool is `cache.messenger.restart_workers_signal`, a child of `cache.app`.** If
  `cache.app` is the default filesystem adapter, the deploy job and the workers must
  share that directory. In separate containers with separate filesystems, **the signal
  never arrives** and nothing reports it. Point `cache.app` at Redis, Valkey or the
  database and the problem disappears — that is the real reason to move `cache.app` off
  the filesystem in a multi-container deployment.
- **Order matters on Symfony < 7.4.** There, `cache.app` lives in
  `%kernel.cache_dir%/pools/app`, so a `cache:clear` *after* `messenger:stop-workers`
  deletes the signal. Stop the workers last. From 7.4, `APP_SHARE_DIR` (`var/share` in
  the recipe) puts the pool in `%kernel.share_dir%/pools/app`, outside the cache
  directory, and `cache:clear` no longer touches it.
- **Nothing restarts the workers.** The command's own help says so. A process manager
  must do it — which is the point of the next section.

### Running them

Workers are long-lived PHP processes, and long-lived PHP processes leak. Both limits
below are protection, not tuning:

```bash
php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
```

- **`--time-limit`** bounds how long a worker can go on holding a stale container, a
  dead database connection or a Doctrine identity map full of objects nobody needs. An
  hour is a reasonable default.
- **`--memory-limit`** stops it before the kernel's OOM killer does. The killer takes
  the process down mid-message; the worker exits cleanly between messages.

Both make the worker *exit*. The process manager restarts it, and that restart is also
how the worker picks up the new code — which is why a supervised worker and
`messenger:stop-workers` together give you a graceful rolling restart with no dropped
message.

Unit files for systemd and supervisor, with what to adapt: `../assets/README.md`.

### Graceful restart on deploy

```bash
php bin/console messenger:stop-workers   # after the new image is live
```

Workers finish their current message, exit, the manager restarts them on the new code.
Nothing is killed mid-handler, so nothing is half-done. This works because handlers in
this standard are idempotent (see `symfony-yoandev-async`): a message that was retried after a
restart is processed again without doubling its effect.

Do not `kill -9` a worker to force a redeploy. The message it was holding is neither
acknowledged nor rejected, and what happens next depends on the transport's visibility
timeout rather than on anything you decided.

## Verifying the deploy

```bash
php bin/console about                            # env, debug, versions, paths
php bin/console doctrine:migrations:up-to-date   # exit code 0 or the schema is behind
php bin/console lint:container                   # every service can actually be built
php bin/console messenger:stats                  # message count per transport
php bin/console debug:dotenv                     # which file each variable came from
```

Read them like this:

| Check | What a bad answer looks like |
|---|---|
| `about` | `Debug: true`, or `Environment: dev` — `APP_ENV`/`APP_DEBUG` did not reach the process |
| `doctrine:migrations:up-to-date` | Non-zero exit — the release job did not run, or failed and nobody looked |
| `lint:container` | Any error at all. A service that cannot be built fails on the request that needs it, not at boot |
| `messenger:stats` | `failed` above zero, or `async` growing between two calls — nothing is consuming |
| `debug:dotenv` | A production value coming from `.env` instead of the environment |

Then one request from outside, and three things in the browser's network panel: the
status code, an asset served from `/assets/<name>-<hash>.<ext>` (not through PHP), and
HTTP/2. Finally, look at the logs — an empty log after a deploy is a result; a log you
have not looked at is not.

**A failing message in `failed` must raise an alert**, not wait for someone to run
`messenger:failed:show`. That rule belongs to `symfony-yoandev-async`, and a deploy is when it
gets tested.

## Rollback

```bash
# Repin the tag, then recreate. Note: `docker compose pull` takes SERVICE names,
# not image references — `docker compose pull app:a1b2c3d` fails with
# "no such service: app:a1b2c3d".
APP_IMAGE=registry.example.com/app:<previous-sha> docker compose up -d --pull always
```

with `image: ${APP_IMAGE}` on the service in `compose.yaml`. On an orchestrator it is the
same move under another name — `kubectl set image`, or re-applying the previous manifest.
That is the whole procedure — when the preconditions hold.

**What makes rollback possible**

- Immutable, content-addressed tags: `app:a1b2c3d`, one tag per commit, never
  overwritten. `latest` is not a version, it is a moving target.
- The image is built once, in CI, and pushed. If it is built on the production host,
  the previous artefact does not exist any more.
- Migrations that add rather than remove. Rolling code back is instant; rolling data
  back is not possible.
- Configuration outside the image, so the previous image boots with today's DSNs.

**What makes it impossible**

- A destructive migration in the release you want to leave. `down()` recreates the
  column, not its contents — and `down()` has almost certainly never been executed.
- A migration that ran but whose code you are reverting, when the old code cannot read
  the new schema. That is the same window as above, entered deliberately.
- State that only the new version writes: a cache format, a session payload, a queued
  message the old handler cannot deserialize. Draining the queue before rolling back is
  usually enough; a versioned message envelope is the durable answer.

When rollback is not available, say so *before* deploying, not during the incident. A
release that cannot be undone is a release that deserves a maintenance window.
