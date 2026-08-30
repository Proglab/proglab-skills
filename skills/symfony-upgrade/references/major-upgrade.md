# The major-version jump

An ordered procedure. The order is the whole value: every step leaves you with an
application that boots, and a failure at step *n* is caused by step *n*, not by the six
things you changed at once.

Do it on a branch, one commit per step. If a step cannot be committed on its own, it is
too big.

## 0. Know what you are jumping from

```bash
php bin/console about
```

If `Long-Term Support` says `No` and you are planning a major jump, **stop and go to the
last minor of the current major first**. It is the version that carries the deprecation
notices for everything the next major removes; jumping from the middle of a major means
meeting those removals as fatal errors with nothing in between to bisect against.

Going 6.2 → 7.0 is a rewrite of unknown size. Going 6.2 → 6.4 → 7.0 is two small changes
and a list of deprecations. Same destination, incomparable risk.

## 1. Check the dependencies before you touch anything

A single bundle without a compatible release blocks the entire jump, and finding that out
on day two is the classic way to lose a week.

```bash
composer why-not symfony/framework-bundle 9.0
```

```
Package "symfony/framework-bundle" could not be found with constraint "9.0", results below will most likely be incomplete.
__root__                            dev-main requires symfony/framework-bundle (8.1.*)
doctrine/doctrine-bundle            3.3.1    requires symfony/framework-bundle (^6.4 || ^7.0 || ^8.0)
symfony/web-profiler-bundle         v8.1.5   requires symfony/framework-bundle (^7.4|^8.0)
twig/extra-bundle                   v3.24.0  requires symfony/framework-bundle (^5.4|^6.4|^7.0|^8.0)
```

Read it as a compatibility matrix. Every row whose constraint does not include your
target is a package that must move first — and `__root__` is your own `composer.json`,
which is step 3. The warning on the first line is normal when the target version does not
exist yet; the rows below it are still the constraints you need.

Third-party bundles lag the framework by weeks or months. Check the ones that matter
before committing to a date; for each blocker, the question is whether an upgrade exists,
is in progress, or is never coming. `composer why <package>` tells you who pulled it in,
which sometimes reveals that nothing you wrote depends on it at all.

`composer outdated --direct` gives the smaller, more actionable picture: your own
requirements only, not the hundred transitive packages you never chose.

## 2. Get green on the last minor

Non-negotiable, and it is more than "the tests pass":

- the test suite is green, deprecations included (see `deprecations.md`);
- `composer audit` reports nothing;
- `composer recipes -o` reports nothing (see `recipes.md`);
- `php bin/console --env=prod debug:container --deprecations` is clean.

That last one catches the configuration-level deprecations that no test executes and that
turn into `InvalidConfigurationException` the moment the major lands.

Then run the suite once with `ignoreIndirectDeprecations="false"` in `phpunit.dist.xml`.
Do not commit that change — it is a one-off audit, and what it prints is your
dependencies' readiness, not your own work.

## 3. Bump the constraints

Two places, and missing the first is why "I ran `composer update` and nothing happened"
is such a common report.

**`extra.symfony.require`.** Flex installs a package filter that hides every `symfony/*`
release outside this constraint from the resolver. While it says `8.1.*`, Composer will
not even consider 8.2.

```json
"extra": {
    "symfony": {
        "allow-contrib": false,
        "require": "8.1.*"
    }
}
```

**The `symfony/*` lines themselves**, which Flex wrote pinned to the same minor:

```json
"symfony/asset": "8.1.*",
"symfony/console": "8.1.*",
"symfony/framework-bundle": "8.1.*",
```

Both move together. Everything not written by Flex — `symfony/flex` itself (`^2`),
`symfony/ux-*` (`^3.4`), `doctrine/*`, `twig/*` — is on its own release cycle and is not
part of this edit.

### Trying it without committing to it

`SYMFONY_REQUIRE` overrides `extra.symfony.require` from the environment, which is how you
probe a version without editing anything — and how a CI matrix tests against the next
minor before it is adopted:

```bash
SYMFONY_REQUIRE=8.2.* composer update "symfony/*" --dry-run --no-scripts
```

The first line of output confirms the filter took effect, echoing the constraint you
passed:

```
Restricting packages listed in "symfony/symfony" to "8.2.*"
```

If that line is absent, the variable did not reach Composer and you are testing nothing.

If you have not also relaxed the per-package `8.1.*` constraints, this reports conflicts
rather than a plan — the root requirements and the filter are contradicting each other.
That is informative in itself, and it is why the two edits above belong together.

## 4. Resolve

```bash
composer update "symfony/*" --with-all-dependencies
```

`--with-all-dependencies` (`-W`) is almost always required: a major bump drags transitive
packages that are locked to versions the new major rejects, and without it Composer
refuses and tells you so.

Read what it refuses. Composer's conflict output names the exact package and constraint;
it is tedious but it is never vague:

```
- symfony/form[v8.1.0, ..., v8.1.5] require symfony/var-exporter ^8.1 -> found
  symfony/var-exporter[...] but these were not loaded, likely because it conflicts
  with another require.
```

Resist `--ignore-platform-reqs` and hand-edited `composer.lock`. Both produce an install
that resolves and then fails at runtime, which is strictly worse than not resolving.

## 5. Run everything

In this order, because each answers a different question:

1. `php bin/console cache:clear` — does the container still compile? Most removals
   surface here, before a single test runs.
2. `php bin/console --env=prod debug:container --deprecations` — configuration.
3. The test suite.
4. `composer recipes -o`, then update what it names (`recipes.md`). The new major's
   recipe folders are frequently the clearest statement of what changed.
5. The application itself, in a browser, on the paths tests do not cover.

## 6. Rector, for the mechanical part

Rector is in the phpqa image; nothing goes into `composer.json`.

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/project -w /project \
  jakzal/phpqa:1.125.1-php8.4-alpine rector process --dry-run --no-progress-bar
```

`--dry-run` exits `2` when it would change something and prints a diff per file with the
rules that produced it. Drop `--dry-run` to apply.

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    // One flag at a time — see "The discipline" below. Run with symfony alone,
    // review, commit; then uncomment doctrine; then phpunit.
    ->withComposerBased(symfony: true /* , doctrine: true, phpunit: true */);
```

`withComposerBased()` binds each rule to the version of the package actually installed,
which is why there are no version-numbered sets any more: `SymfonySetList` holds
`COMPOSER_BASED`, `SYMFONY_CODE_QUALITY`, `SYMFONY_CONSTRUCTOR_INJECTION`, `CONFIGS` and
a deprecated `ANNOTATIONS_TO_ATTRIBUTES` — and nothing version-numbered. Any example
naming `SymfonySetList::SYMFONY_64` predates this and will fatal on an undefined
constant.

**The discipline**, and it is not optional:

- **One concern per run, one commit each.** Enable `symfony: true` alone, review, commit.
  Then `doctrine`. Then `phpunit`. A commit mixing three rule sets across two hundred
  files cannot be reviewed and cannot be partially reverted.
- **Never blind.** `--dry-run` first, always. Rector rewrites what it can prove; it does
  not know your intent. It will convert a docblock into an attribute correctly and it
  will not notice that the method should not exist.
- **Keep the convenience flags out of upgrade commits.** Adding `->withImportNames()` to
  the config above turned a one-file diff into a four-file diff, rewriting fully-qualified
  class names in tests that had no upgrade change in them. Useful; separate commit.
- **Formatting is php-cs-fixer's job.** Rector emits fully-qualified names in generated
  attributes (`#[\PHPUnit\Framework\Attributes\Test]`). Run the style tool afterwards
  rather than asking Rector to be one.
- **The test suite is the acceptance criterion.** Green before, green after, per run.

What Rector does badly: anything requiring judgement. Restructuring a controller,
choosing between a Voter and a role, deciding an entity should not have setters. Do not
expect it to produce the architecture in `symfony-architecture`; expect it to remove the
tedium so you have attention left for the parts that matter.

## When a dependency has nowhere to go

Sooner or later step 1 says no. `composer audit --abandoned=report` names packages whose
maintainers have declared themselves done; `--abandoned=fail` makes that a build failure
once you have decided you care.

In rough order of how much future they buy:

**Replace it with the framework.** Check this first, and check it seriously. Symfony has
absorbed a decade of bundles — pagination, JWT-ish token authentication, rate limiting,
scheduling, UUIDs, HTTP clients, mocking clocks. A dependency added in 2019 to fill a gap
is often filling a gap that no longer exists. This is the only option that leaves you
with less code than you started with.

**Replace it with a maintained alternative.** Real work, bounded, and it ends.

**Fork it.** Sometimes the honest answer for a small package: vendor it into the project,
delete what you do not use, own it. Say out loud that you now maintain it — a fork
nobody has agreed to maintain is an abandoned dependency with extra steps.

**Pin the major and stop.** You have not solved anything; you have chosen a date on which
this becomes urgent. Legitimate only when that date is written down and the package is
genuinely peripheral.

Whatever you pick, the failure mode is the same: nobody decides, the upgrade is
postponed, and two years later the jump is three majors wide. **A blocked dependency is a
decision, not a delay.**

## Rollback

The branch is the rollback, which is the reason for one commit per step. If the upgrade
reaches production and fails there, the sequence is `symfony-deployment`'s — and it is
worth checking before you start that rolling back the application also rolls back
anything the migrations did, because that part is not free (`symfony-doctrine`).
