# Symfony CLI: how the wiring actually works

## Contents

- [Service detection](#service-detection)
- [Naming a service the CLI does not recognise](#naming-a-service-the-cli-does-not-recognise)
- [Excluding a service](#excluding-a-service)
- [`symfony console` versus `php bin/console`](#symfony-yoandev-console-versus-php-binconsole)
- [The `--env=test` trap](#the---envtest-trap)
- [Workers and long-running processes](#workers-and-long-running-processes)
- [Per-project configuration](#per-project-configuration)
- [Compose files somewhere else](#compose-files-somewhere-else)

## Service detection

The CLI lists the containers of the current compose project, reads the **exposed
container port** of each, and matches it against a fixed table:

| Container port | Variables exported |
|---|---|
| 5432 (PostgreSQL) | `DATABASE_URL`, `DATABASE_HOST`, `DATABASE_PORT`, … |
| 3306 (MySQL) | same, `DATABASE_` prefix |
| 6379 (Redis) | `REDIS_URL`, … |
| 1025 (mail catcher, SMTP) | `MAILER_DSN`, … |
| 1080 / 8025 (mail catcher, web UI) | `MAILER_WEB_URL` — **not** `MAILER_DSN`. Mailpit uses 8025, which is what `assets/compose.override.yaml` publishes |
| 5672 (RabbitMQ) | `RABBITMQ_…` |
| 9200 (Elasticsearch) | `ELASTICSEARCH_…` |
| 27017 (MongoDB) | `MONGODB_…` |
| 8707 (Blackfire) | `BLACKFIRE_…` |
| 80 (Mercure) | `MERCURE_URL`, `MERCURE_PUBLIC_URL` |

Matching is on the **container** port, not the host port. That is precisely why the
host port is left unspecified: Docker assigns a free one, the CLI finds it, and two
projects coexist without anybody editing a file.

```bash
symfony var:export --debug
```

This is the command that answers "why is my `DATABASE_URL` not what I expect". It
prints every exported variable and where it came from — Docker, `.env`, `.env.local`
or the shell.

## Naming a service the CLI does not recognise

Detection is by port, but a service on a non-standard port, or several services of the
same kind, need a label:

```yaml
services:
  replica:
    image: postgres:16-alpine
    ports: ["5432"]
    labels:
      com.symfony.server.service-prefix: 'REPLICA'
```

Exports `REPLICA_URL`, `REPLICA_HOST` and so on, leaving `DATABASE_` to the primary.

## Excluding a service

Some containers should not become environment variables at all — a database
belonging to another application, an auxiliary tool that happens to listen on a
recognised port:

```yaml
services:
  legacy-db:
    ports: ["3306"]
    labels:
      com.symfony.server.service-ignore: true
```

Without this, an unrelated MySQL container silently becomes the project's
`DATABASE_URL`, and the failure looks like a Doctrine problem.

## `symfony console` versus `php bin/console`

They are not interchangeable on a project set up this way.

| | Docker variables | Use it for |
|---|---|---|
| `symfony console` | injected | Everything touching the dev database, the mailer, Redis |
| `php bin/console` | not injected | Anything targeting the **test** environment |

A `connection refused` that appears with one and not the other is not a Docker
problem — it is this distinction.

## The `--env=test` trap

```bash
# Drops the DEVELOPMENT database, despite --env=test
symfony console doctrine:database:drop --force --env=test
```

The injected Docker variables are applied whatever `--env` says, so they override
`.env.test`. This is documented behaviour and it has destroyed real data.

**Rule: anything aimed at the test database goes through `php bin/console`.** The test
environment reads its own `DATABASE_URL` from `.env.test`, which is the point of
having one.

```bash
php bin/console --env=test doctrine:database:create
php bin/console --env=test doctrine:migrations:migrate --no-interaction
```

Migrations, not `doctrine:schema:create`: a suite that builds its schema from the mapping
never exercises the migrations, and the migrations are the first thing production runs
(`symfony-yoandev-testing`, `references/database-tests.md`). `schema:create` is the stand-in only
on a project that has no migrations yet.

## Workers and long-running processes

The CLI supervises background processes and keeps their logs in the same stream as
the server:

```bash
symfony run -d --watch=config,src,templates \
    symfony console messenger:consume async

symfony server:log      # server, PHP, application and workers together
symfony server:status   # what is running
```

`--watch` restarts the worker when the listed directories change. Without it, a worker
started before your edit keeps running the old code, and you spend twenty minutes
wondering why the fix did nothing.

## Per-project configuration

`.symfony.local.yaml`, committed:

```yaml
http:
    document_root: public/
    passthru: index.php

workers:
    # Started automatically with the server. Handy on a project where nothing
    # works until the messenger worker runs — new joiners get it for free.
    messenger:
        cmd: ['symfony', 'console', 'messenger:consume', 'async', '-vv']
        watch: ['config', 'src']
```

## Compose files somewhere else

If the compose files are not at the project root, both Docker and the CLI need to be
told, with the same values:

```bash
export COMPOSE_FILE=docker/compose.yaml
export COMPOSE_PROJECT_NAME=myapp

docker compose up -d
symfony var:export --debug
```

Set them in one place — a `.env.local` sourced by the shell, or the task runner — and
not by hand each time. Mismatched values are the usual cause of "the containers are
running but nothing is injected".
