# The production-like check

## Contents

- [When to run it](#when-to-run-it)
- [Running it](#running-it)
- [What to look at](#what-to-look-at)
- [Bugs this catches that the dev server cannot](#bugs-this-catches-that-the-dev-server-cannot)
- [Why not develop in it](#why-not-develop-in-it)
- [Going to real production](#going-to-real-production)

## When to run it

Not on every commit. Before shipping something where the difference between dev and
prod could matter:

- anything that touched assets, templates or the front end;
- a first deployment, or the first after a Symfony upgrade;
- adding a bundle, a listener, or anything registered in the container;
- a bug that "only happens in production" and needs reproducing;
- before a release you cannot easily roll back.

## Running it

```bash
docker compose -f compose.yaml -f compose.prod-like.yaml up --build
open https://localhost
```

Both `-f` flags matter. Naming files explicitly means `compose.override.yaml` is *not*
loaded — no exposed dev ports, no Mailpit — which is closer to production and also
means emails will fail unless you add a mail service back. That failure is
informative: if the application cannot start without Mailpit, production has the same
problem.

The certificate is self-signed, so the browser warns once. That is expected, and it is
what gives you HTTP/2 locally.

## What to look at

Work through this list rather than clicking around. Each line is something the dev
server cannot tell you.

- [ ] **The application boots at all.** Half the value is here: a bundle registered
      only in dev, a service that is `public` in dev config, an env var that exists
      only in `.env.local`.
- [ ] **Assets are served, not generated.** Open the network panel: files should come
      from `/assets/…` with a content hash in the name. If `asset-map:compile` did not
      run, Symfony generates them per request through PHP.
- [ ] **The protocol is HTTP/2.** Visible in the network panel. AssetMapper serves many
      small files instead of one bundle; over HTTP/1.1 that is slower than a bundle,
      which is how people conclude AssetMapper is slow.
- [ ] **No profiler, no debug toolbar.** If either appears, `APP_ENV` did not take.
- [ ] **Error pages are the real ones.** Trigger a 404 and a 500. A stack trace here
      means debug is on; a blank page means the error template is missing.
- [ ] **Nothing writes outside `var/`.** A read-only filesystem in production will find
      what you missed.
- [ ] **Logs go to stdout**, not only to a file — that is what a container platform
      collects.

## Bugs this catches that the dev server cannot

| Bug | Why dev hides it |
|---|---|
| Missing `asset-map:compile` in the deploy script | Dev serves assets on the fly, so it always works |
| Code relying on the profiler or on `dump()` | Both are loaded in dev |
| A service that is only public in dev config | `$container->get()` keeps working in dev |
| A template referencing an undefined variable | `strict_variables` is often off in prod config, so it fails differently |
| An env var only defined in `.env.local` | Never committed, never present in the image |
| OPcache and a file written at runtime | `validate_timestamps=0` means the change is never picked up |
| Cache warmup failing | Dev warms lazily, one entry at a time |

## Why not develop in it

Every change means a rebuild, or a bind mount that reintroduces the dev behaviour you
were trying to escape. Step debugging needs Xdebug in the image, which changes what
you are measuring. OPcache with `validate_timestamps=0` means edits are ignored until
you rebuild.

The daily loop stays `symfony server:start`. This container answers a specific
question, then you stop it.

## Going to real production

`Dockerfile.prod-like` is deliberately minimal — a single stage, no worker, no
healthcheck, running as root. Enough to answer "does it work in prod conditions", not
enough to run anything.

For actual deployment, use
[`dunglas/symfony-docker`](https://github.com/dunglas/symfony-docker). It is
maintained by the FrankenPHP author, tracks Symfony's recipes, and handles what this
file leaves out: multi-stage builds, a non-root user, healthchecks, worker mode, TLS
with real certificates, and the `###> recipes ###` markers that let Flex keep the
Dockerfile up to date on its own.

Copying that reference into a skill would guarantee it rots. Point at it instead.
