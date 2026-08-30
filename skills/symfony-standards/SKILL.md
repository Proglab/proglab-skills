---
name: symfony-standards
description: >-
  Entry point for writing code in a Symfony project: detects the project's versions and
  conventions, states the five rules that apply to everything, and routes to the
  specialised skill for the task at hand. Load this FIRST whenever a request touches a
  Symfony codebase and the right specialised skill is not obvious — a feature described
  in plain language ("let people rate a book", "make a CRUD", "add this to the admin"),
  a vague instruction ("refactor this", "clean this up", "fix this bug", "finish this"),
  a task spanning several areas at once, or the very first change on an unfamiliar
  project. Also load it before starting anything if you are unsure which of
  symfony-architecture, symfony-http, symfony-doctrine, symfony-testing,
  symfony-security, symfony-frontend, symfony-async, symfony-console,
  symfony-performance, symfony-quality, symfony-local-dev, symfony-deployment,
  symfony-observability, symfony-storage or symfony-upgrade applies.
  When the task is plainly inside one of those, load that one directly instead.
---

# Symfony standards — start here

Short on purpose. Detect the project, apply the five rules, load the skill that matches
the work.

## Step 0 — read the project before writing to it

Never assume versions or conventions. Once per session:

```bash
php -v | head -1
php -r '$l=json_decode(file_get_contents("composer.lock"),true);
foreach(array_merge($l["packages"],$l["packages-dev"]??[]) as $p)
  if(preg_match("#^(symfony/(framework-bundle|serializer|form|security-bundle|object-mapper|messenger|scheduler|lock|rate-limiter|asset-mapper|ux-live-component)|doctrine/orm|doctrine/doctrine-fixtures-bundle|phpunit/phpunit|twig/twig)$#",$p["name"]))
    echo str_pad($p["name"],40)." ".$p["version"]."\n";'
ls src/ config/packages/
```

What it tells you:

- **Symfony and PHP versions** → which attributes exist. Every skill states what to write
  instead when one is unavailable; the rule never disappears, only the means change.
- **Which optional components are installed** → what you can lean on without asking.
  Anything missing gets *proposed*, never installed silently.
- **The existing layout of `src/`** → the conventions to follow. If the project already
  groups code by domain, or puts DTOs somewhere else, follow it. Boundaries between
  layers are not negotiable; folder names are.

**`vendor/` outranks everything written in these skills.** Reading the source of an
attribute takes a second and is never wrong; memory and blog posts frequently are.

## The five rules

They apply everywhere, whatever the task.

**1. Write the test first, and watch it fail.** A test that was never red can be green
for the wrong reason — inverted assertion, permissive mock, never executed at all. Where
red-first is impossible, break the code and confirm the test notices.

**2. A controller translates, it does not decide.** HTTP in, service call, response out.
No business rule, no persistence.

**3. No DQL, QueryBuilder or SQL outside a repository.** Queries are named after the
caller's intent, not the mechanism. A service that builds a query cannot be tested
without a database.

**4. DTOs at both edges.** Input DTOs carry the validation; output DTOs carry the
contract. An entity never reaches a template or a JSON response.

**5. Every business rule lives in a service.** Entities hold data and mapping; they hold
no rules. That is the direct consequence of using maker-format entities, and it is
deliberate.

If a change seems too small to deserve a test, it is still covered by rule 1. If a rule
seems wrong for the case at hand, say so and explain why rather than quietly bending it.

## Where to go next

| The work is about | Load |
|---|---|
| Where a class goes, layers, DTOs, services, DI, patterns, why a rule exists | `symfony-architecture` |
| Writing or fixing tests, fixtures, mocks, the TDD loop | `symfony-testing` |
| Controllers, routes, request payloads, JSON responses, forms, Twig pages | `symfony-http` |
| Entities, repositories, queries, relations, migrations | `symfony-doctrine` |
| Login, permissions, voters, CSRF, tokens, hardening | `symfony-security` |
| JavaScript, CSS, Stimulus, Turbo, components, AssetMapper | `symfony-frontend` |
| Background work, emails, queues, scheduled jobs | `symfony-async` |
| Console commands, imports, cleanup scripts | `symfony-console` |
| Something is slow, too many queries, caching | `symfony-performance` |
| PHPStan, deptrac, code style, CI pipeline | `symfony-quality` |
| Running the project locally, Docker services, the dev server | `symfony-local-dev` |
| Shipping to production, migrations at deploy, server settings | `symfony-deployment` |
| Logs, alerting, health checks, knowing what production is doing | `symfony-observability` |
| Uploaded files: where they go, how they are served, orphans | `symfony-storage` |
| Deprecations, updating dependencies, moving to a new Symfony major | `symfony-upgrade` |

Most real tasks touch two or three. A feature request typically means
`symfony-architecture` to decide where the pieces go, `symfony-testing` to start, then
the one matching the surface being built. Load them as you reach them rather than all at
once.

## Before handing back

- [ ] The tests were written first, seen red, and now pass — and you ran them.
- [ ] `vendor/bin/phpunit` result reported as it actually is, failures included.
- [ ] No business rule in a controller, no query outside a repository, no entity crossing
      an edge.
- [ ] Anything you had to decide for the user is stated plainly, not buried.
- [ ] Anything you could not do is named, rather than silently dropped.

The last two matter as much as the code. A change that works but hides a decision is a
change someone will have to reverse-engineer later.
