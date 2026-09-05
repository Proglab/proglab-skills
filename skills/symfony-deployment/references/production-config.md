# Production configuration

Everything that makes the difference between an application that runs and one that runs
in production. Verified against Symfony 8.1 and `dunglas/symfony-docker`'s current
image; when this page and `vendor/` disagree, `vendor/` is right.

## Contents

- [The two switches](#the-two-switches)
- [php.ini](#phpini)
- [OPcache preloading](#opcache-preloading)
- [`validate_timestamps = 0` and the cost it comes with](#validate_timestamps--0-and-the-cost-it-comes-with)
- [Symfony-level settings](#symfony-level-settings)
- [Environment variables](#environment-variables)
- [Secrets](#secrets)
- [The filesystem](#the-filesystem)
- [Logs](#logs)
- [FrankenPHP worker mode](#frankenphp-worker-mode)

## The two switches

```bash
APP_ENV=prod
APP_DEBUG=0
```

`APP_DEBUG` is not a verbosity setting. With it on, the container is rebuilt whenever a
config file changes, the profiler collects every request, and exceptions render with
source code and environment variables — a performance problem *and* an information leak.

`Dotenv::bootEnv()` derives `APP_DEBUG` when it is unset: `1` unless `APP_ENV` is in the
prod list. So `APP_ENV=prod` alone is already correct — set both anyway, because the
derivation is invisible and someone will eventually add an environment called `staging`
and wonder why the toolbar is there.

## php.ini

`symfony-docker` splits this in two: settings for every environment, and settings only
production can afford. Both are worth reading as a unit.

```ini
; frankenphp/conf.d/10-app.ini — all environments
expose_php = 0
date.timezone = UTC
apc.enable_cli = 1
session.use_strict_mode = 1
zend.detect_unicode = 0

realpath_cache_size = 4096K
realpath_cache_ttl = 600
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 32531
opcache.memory_consumption = 256
opcache.enable_file_override = 1
```

```ini
; frankenphp/conf.d/20-app.prod.ini — production only
opcache.preload_user = www-data
opcache.preload = /app/config/preload.php
opcache.validate_timestamps = 0
```

What each of the non-obvious ones buys:

| Setting | Why |
|---|---|
| `realpath_cache_size = 4096K` | PHP resolves paths through `stat()`. A Symfony app touches thousands of files; the 16K default evicts constantly, so every autoload re-walks the filesystem. This is the cheapest win on the list |
| `opcache.max_accelerated_files = 32531` | The default (~10 000) is below the file count of a real Symfony app plus vendors. Once the table is full, the rest is never cached — compiled on every request, silently. The value is a prime, which is what the setting expects |
| `opcache.memory_consumption = 256` | Same failure, different resource: when the buffer fills, OPcache stops caching. 128M is often not enough with a large vendor tree |
| `opcache.interned_strings_buffer = 16` | Class, method and property names are interned once instead of per-process |
| `session.use_strict_mode = 1` | Refuses session IDs the server never issued — the fix for session fixation |
| `expose_php = 0` | Removes the `X-Powered-By` header. Free |

## OPcache preloading

Preloading compiles the application's classes into shared memory **once, at startup**,
so no request pays for linking them. `config/preload.php` ships with the Symfony recipe
and is three lines:

```php
<?php

if (file_exists(dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php')) {
    require dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php';
}
```

Read the `file_exists()` guard carefully: **if the prod cache has not been warmed,
preloading does nothing and says nothing.** The `.preload.php` file is written by
`cache:clear`/`cache:warmup` when the optional warmers run. This is the concrete reason
warmup belongs in the image build and not in the container's first request.

`opcache.preload_user` is mandatory whenever PHP does not start as root: PHP refuses to
preload otherwise, with a startup warning that is easy to miss in container logs. It must
name the user the server actually runs as (`www-data` in the reference image). Get it
wrong and you lose preloading without losing the application — invisible, which is the
worst kind of failure.

Preloaded classes are frozen for the lifetime of the process, so preloading and
`validate_timestamps = 0` are really one decision.

## `validate_timestamps = 0` and the cost it comes with

With `opcache.validate_timestamps = 0`, PHP stops checking whether a file changed since
it was compiled — one `stat()` per file per request, gone. It is the single largest
OPcache win and it is not optional in production.

**It also means new code is never loaded until the OPcache is dropped.** A purge after
every deploy is therefore mandatory; the only question is who performs it:

| Deployment model | Who purges | What you must do |
|---|---|---|
| New container per release | The container is new, its OPcache is empty | Nothing. This is why the model is worth its constraints |
| PHP-FPM, code updated in place | You | Restart or reload the FPM pool as the last deploy step |
| `releases/` directory + symlink swap | You | New absolute paths dodge OPcache for application files, but not for anything referenced through the stable symlink. Restart anyway |
| Anything with a bind-mounted source | Nobody, ever | Do not run `validate_timestamps = 0` with a bind mount. Edits are ignored until restart, and you will lose an afternoon to it |

`opcache_reset()` from a web request only clears the pool of the process that answered
it; with several FPM workers, that is not a purge. Restart the process manager.

## Symfony-level settings

```yaml
# config/services.yaml
when@prod:
    parameters:
        .container.dumper.inline_factories: true
```

The dumped container becomes a few large files instead of one file per service — fewer
`include`s per request. The trade is a slower, more memory-hungry warmup, which happens
in the build, where nobody is waiting.

**The leading dot matters.** Parameters starting with `.` are *build parameters*: removed
from the compiled container by `RemoveBuildParametersPass` and handed to the dumper,
which is the only place the kernel reads this one from. The undotted
`container.dumper.inline_factories` was deprecated in Symfony 6.3 and on 8.x is not read
at all — it survives as an ordinary parameter, nothing warns, and the optimisation is
off. The dotted form works from 6.3 onwards, so write it that way on every supported
version.

```yaml
# config/packages/translation.yaml
framework:
    enabled_locales: ['en', 'fr']
```

Without it, Symfony compiles a translation catalogue for every locale it can find,
including the ones your bundles ship and you never serve. It also restricts the `_locale`
route requirement to that list, which turns "some crawler is requesting `/zh/books`" from
a 500 into a 404.

One more, only if you deploy into a `releases/` directory rather than a container:

```yaml
# config/packages/cache.yaml
framework:
    cache:
        prefix_seed: relire_ou_pas/%kernel.environment%
```

Without a seed, cache keys are namespaced from `kernel.project_dir` plus the container
class. A release directory changes the project dir on every deploy, so the whole
`cache.app` pool becomes unreachable — a cold cache after each release, with the old
entries still taking space. In a container the path is always `/app`, so the problem does
not arise.

## Environment variables

Three mechanisms, in the order they are resolved:

1. **Real environment variables** — set by the orchestrator, the compose file, the
   systemd unit. These win over everything.
2. **`.env.local.php`**, produced by `composer dump-env prod`. One compiled PHP array,
   included in a single `include`, instead of parsing `.env`, `.env.prod` and
   `.env.local` on every request.
3. **`.env.prod.local` / `.env.local`** — read only when there is no `.env.local.php`,
   or when the compiled file is bypassed (see below).

The precedence is in `Dotenv::populate()`: with `$overrideExistingVars = false`, a name
already present in `$_ENV` is skipped. So `dump-env` is a *compilation of defaults*, not
a lock — you build one image and give it a different `DATABASE_URL` per environment,
which is exactly what makes the image reusable between staging and production.

**The trap.** `bootEnv()` includes `.env.local.php` only if the `APP_ENV` it contains
agrees with the one already in the environment. Dump for `prod`, run with
`APP_ENV=staging`, and the compiled file is discarded in favour
of parsing the `.env` files at runtime — which are not in the production image. The
symptom is a missing variable, and nothing points at the cause. `php bin/console
debug:dotenv` shows which file each value actually came from; run it when a variable is
"set" and not taking effect.

## Secrets

**On the container target, secrets come from the platform's secret store as environment
variables, and Symfony's vault is not layered on top.** The reason is narrow and worth
stating exactly: the vault's decryption key would itself have to be a platform secret,
so the vault would be a second store fed by the first, with nothing gained but a
`secrets:decrypt-to-local` step to forget.

**Off that target, the vault is the standard.** A bare server, a deploy by rsync, a host
with no secret store: there, `.env.local` is a plaintext file that nobody rotates and
that a backup copies, and the vault is what Symfony built for exactly that case.
`secrets:set`, `config/secrets/prod/` committed, the decryption key injected as
`SYMFONY_DECRYPTION_SECRET` on the host and nowhere else. Its decryption key is one
environment variable — no harder to deploy than the DSN — and it is the only mechanism
that lets a secret be versioned, reviewed in a pull request and rotated in a commit.
That is also why a project on a platform may still choose it: when secrets must travel
through the repository, adopt it.

Whichever store is in use, **one, not two.** A value in the vault and the same key in the
platform is how a rotation updates one of them and the application reads the other.

## The filesystem

The reference image runs as `www-data`, with `/app/var` owned by group 0 and `chmod g=u`
so it also works on platforms that assign an arbitrary UID. Everything else in `/app`
should be treated as read-only. `var/` is the only directory the application writes to,
and it now has three distinct roles:

| Directory | Contains | Survives a deploy? |
|---|---|---|
| `var/cache/<env>` | Compiled container, routes, Twig, preload file | No — rebuilt with the image, and *should* be replaced |
| `var/log` | Log files, when a handler writes to one | Irrelevant in a container; log to stderr instead |
| `var/share/<env>` | `cache.app` pools, HTTP cache — data meant to be shared between front-end servers | It should. `APP_SHARE_DIR` exists precisely to let you mount it |

`APP_SHARE_DIR` and `%kernel.share_dir%` arrived in **Symfony 7.4**; the recipe sets
`APP_SHARE_DIR=var/share`. On earlier versions, `getShareDir()` falls back to the cache
directory, which is why `cache.app` used to be wiped by `cache:clear` — and why the
Messenger restart signal could vanish mid-deploy (see `deploy-sequence.md`).

With more than one container, a filesystem-backed `cache.app` is per-replica: cache
invalidation on one leaves the others stale. Point `cache.app` at Redis, Valkey or the
database when you scale. The same applies to sessions and to uploaded files — anything
written to a container's filesystem is gone when the container is replaced, which is
every deploy.

## Logs

The Symfony recipe already does the right thing for a container:

```yaml
when@prod:
    monolog:
        handlers:
            nested:
                type: stream
                path: php://stderr
                level: debug
                formatter: monolog.formatter.json
```

`php://stderr`, JSON formatted, wrapped in a `fingers_crossed` handler so a request that
ends in an error dumps its whole debug trail and a request that succeeds writes nothing.
That is what a container platform collects and indexes. A file in `var/log` is written to
a filesystem that the next deploy deletes and that nothing is watching.

Two things to check rather than assume: that `buffer_size` is set (`50` in the recipe) so
a runaway request cannot buffer unbounded, and that the `deprecation` channel goes
somewhere you will actually look — deprecations are the early warning for the next
Symfony upgrade.

## FrankenPHP worker mode

The reference Caddyfile enables it by default:

```caddyfile
php @frontController {
    worker {
        file ./public/index.php
    }
}
```

The kernel is booted once per worker process and reused across requests: no bootstrap, no
container rehydration, no autoloading, per request. That is where most of FrankenPHP's
advantage over PHP-FPM comes from.

`symfony/runtime` supports it natively — it detects `FRANKENPHP_WORKER` and uses
`FrankenPhpWorkerRunner`, **added in Symfony 7.4**. On 7.3 and earlier,
`composer require runtime/frankenphp-symfony` and set
`APP_RUNTIME=Runtime\FrankenPhpSymfony\Runtime`; the mechanism differs, the rule does
not. Three environment variables govern it:

| Variable | Effect |
|---|---|
| `FRANKENPHP_WORKER` | Truthy → the worker runner is used |
| `FRANKENPHP_LOOP_MAX` | Requests before the worker process restarts. Default `500`; `0` or negative means never. Also settable as the `worker_loop_max` runtime option in `composer.json` |
| `FRANKENPHP_RESET_KERNEL` | Clones the kernel after each request to limit cross-request state leaks. **Symfony 8.1+** |

The thing that bites: **anything static or global survives between requests.** A static
property caching a value for "the current user", a singleton holding a request-scoped
object, a library that memoises per-request state — all correct under PHP-FPM, all wrong
here, and the symptom is one user seeing another user's data. `FRANKENPHP_LOOP_MAX`
bounds the blast radius of a leak; it does not fix one. `FRANKENPHP_RESET_KERNEL` is the
mitigation when you are adopting worker mode on code that was not written for it, at the
cost of most of the benefit — treat it as a migration aid, not a destination.

The production-like check in `symfony-local-dev` runs the same server, but its
`Dockerfile.prod-like` starts `frankenphp php-server` — classic mode, one process per
request, where this class of bug does not appear. To hunt it before production, run that
image with `FRANKENPHP_WORKER=1` and a Caddyfile declaring the `worker` directive; that is
the only local setup in which a cross-request state leak is reproducible.
