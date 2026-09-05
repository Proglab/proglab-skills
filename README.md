# Symfony Skills

An opinionated set of agent skills for writing Symfony applications.

Most coding agents know Symfony. What they do not know is *which* Symfony you want:
they will happily put a `QueryBuilder` in a service, hand you an entity serialised
straight to JSON, and write the tests afterwards — if at all. These skills close
that gap by making the decisions up front, and by explaining *why* each one was
made, so the agent can apply the reasoning to cases the skills never anticipated.

```bash
npx skills add yoanbernabeu/symfony-yoandev-skills
```

Install a single skill instead:

```bash
npx skills add https://github.com/yoanbernabeu/symfony-yoandev-skills/tree/main/skills/symfony-yoandev-testing
```

Or try one without installing anything:

```bash
npx skills use yoanbernabeu/symfony-yoandev-skills@symfony-yoandev-architecture
```

## The skills

Start with `symfony-yoandev-standards`. It is deliberately short: it detects the project,
states the non-negotiable rules, draws the line between the core and what a project
has to earn, and points at whichever skill below is relevant. The others stand on
their own and trigger directly when a task is clearly in their domain, and each says
under its title which tier it belongs to.

| Skill | Covers |
|---|---|
| **symfony-yoandev-standards** | Entry point: project detection, the five rules, routing to the rest |
| **symfony-yoandev-architecture** | Layer contract, DTOs, services, events, configuration, patterns |
| **symfony-yoandev-testing** | TDD loop, unit vs integration vs functional, fixtures, test doubles |
| **symfony-yoandev-http** | Controllers, routing, request payloads, responses, forms, errors |
| **symfony-yoandev-doctrine** | Entities, repositories, queries, relations, migrations |
| **symfony-yoandev-security** | Authentication, authorisation, voters, CSRF, hardening |
| **symfony-yoandev-frontend** | AssetMapper, Stimulus, Turbo, Twig and Live Components, Tailwind |
| **symfony-yoandev-async** | Messenger, Scheduler, Mailer, retries and failure handling |
| **symfony-yoandev-console** | Command families and invokable commands, destructive operations, locking |
| **symfony-yoandev-performance** | N+1 queries, HTTP and application caching, profiling |
| **symfony-yoandev-quality** | PHPStan and php-cs-fixer as the core, deptrac on demand, the CI pipeline — with ready-to-commit config |
| **symfony-yoandev-local-dev** | Docker services, the Symfony CLI dev server, Mailpit, production-like checks |
| **symfony-yoandev-deployment** | Deployment sequence, migrations, production settings |
| **symfony-yoandev-observability** | Monolog channels, correlation ids, what never to log, alerting, health checks |
| **symfony-yoandev-storage** | Where uploaded files go, public vs protected, serving them, orphans |
| **symfony-yoandev-upgrade** | Deprecations, Flex recipe drift, Rector, moving to a new major |

## What these skills decide

Opinionated means some doors are closed on purpose. The main ones, so you can tell
at a glance whether this suite matches how you work:

- **Test first, and see it fail.** A test that was never red proves nothing. Where
  red-first is impossible, break the code and check the test notices.
- **Nothing but translation in a controller.** No business rule, no persistence.
- **No DQL, QueryBuilder or SQL outside a repository.** Queries are named after the
  intent, not the mechanism.
- **DTOs on both sides.** An entity never reaches a template or a JSON response.
- **AssetMapper, no bundler.** Which means the front end stays Twig, Stimulus and
  Symfony UX — no JSX, no single-file components.
- **Messenger for asynchronous work only.** Not as an in-process command bus.
- **Two tiers.** Five core rules that remove decisions and cost nothing per feature;
  everything else — deptrac, query-count tests, Messenger, caches, health checks — is
  switched on by a real need, never pre-emptively. `symfony-yoandev-standards` draws the line.
- **A hand-written JSON API, up to a point.** Past a handful of resources — or the moment
  you need a documented OpenAPI contract — the suite tells you to use API Platform
  instead, and does not cover it.
- **Services in Docker, PHP on the host.** The Symfony CLI wires them together and
  injects the variables; containers are for verifying production, not for daily work.

Full reasoning lives in each skill; `symfony-yoandev-architecture` carries the summary of
every deliberate rejection and why.

## Versions

The skills target modern Symfony (6.4 through 8.x) on PHP 8.2 or later. They do not
assume a version: every skill starts by reading `composer.lock` and adapts, and each
one lists what to write instead when a given attribute or component is not available.

`vendor/` is always treated as the source of truth over anything written here —
which is also the rule the skills apply to themselves.

## Contributions are not accepted

This suite is my own opinionated take on Symfony. Its value comes from being
coherent and decided, not from being a consensus — and a standard assembled by
committee stops being a standard.

So: no pull requests, and issues proposing different defaults will be closed.
Nothing personal, and no judgement on the alternatives — several rejected options
are perfectly defensible, which is precisely why they are documented as deliberate
rejections rather than omissions.

If you disagree, **fork it**. That is the right answer here: the skills are plain
Markdown, every decision is written down with its reasoning, and changing one is a
matter of editing a file. You will end up with your own opinionated suite, which is
worth more to you than mine.

Bug reports — a broken command, a wrong namespace, an API that no longer exists —
are welcome as issues. Those are facts, not opinions.

## Licence

MIT. See [LICENSE](LICENSE).
