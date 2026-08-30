# Tooling configuration

Ready-to-commit files. Copy them to the project root, adapt the parts marked
below, and commit them — these belong in version control, not in someone's shell
history.

| File | Goes to | Purpose |
|---|---|---|
| `phpstan.dist.neon` | project root | Static analysis at level max, with the Symfony and Doctrine extensions |
| `deptrac.yaml` | project root | The layer contract, enforced |
| `.php-cs-fixer.dist.php` | project root | Code style |
| `phpunit.dist.xml` | project root | The recipe's file plus `failOnPhpunitNotice` and the DAMA extension |
| `castor.php` | project root | Task entry point (default) |
| `alternatives/Makefile` | project root | Task entry point, only if Castor is not an option |
| `github-workflow-ci.yml` | `.github/workflows/ci.yml` | The pipeline |

## Castor by default

`castor.php` is the entry point. Tasks are real PHP — typed arguments, IDE
completion, conditionals that stay readable as the task list grows — which is what
a Makefile stops offering somewhere around the tenth target.

```bash
castor          # list every task
castor qa       # the full gate
```

Install it from [castor.jolicode.com](https://castor.jolicode.com).

`alternatives/Makefile` exposes the same task names, for the case where Castor
genuinely cannot be installed. **Commit one or the other, never both** — two entry
points always drift apart, and then nobody knows which one CI actually runs.

### The image tag is pinned

`jakzal/phpqa:1.125.1-php8.4-alpine`, not `:alpine`. The floating tag is rebuilt
regularly, so a pipeline that was green yesterday can fail today **with no code change**
— PHPStan gained a rule, php-cs-fixer changed a default. Pinning turns that into a
deliberate act: bump the tag in its own commit, read what changed, fix it once.

Match the `-phpX.Y-` part to the project's PHP version, so the analyser sees the same
language the application runs on.

Either way, the tools themselves run from the
[`jakzal/phpqa`](https://github.com/jakzal/phpqa) image. That is a deliberate
choice: PHPStan, php-cs-fixer and deptrac pull dependency ranges that regularly
conflict with the application's own, and the conflict always surfaces at the
worst possible moment. Keeping them out of `composer.json` removes the problem
rather than managing it.

## What to adapt

**`deptrac.yaml`** — the layer names must match the project's directories. If it
groups code by domain (`src/Billing/…`) rather than by technical role, rewrite
the collectors; the boundaries are the point, not the folder names.

**`phpstan.dist.neon`** — `containerXmlPath` must match the kernel class name,
which contains the project's namespace. Check the real filename in
`var/cache/dev/`. The `objectManagerLoader` line needs a
`tests/object-manager.php` returning the entity manager; drop the line if the
project has no Doctrine.

**`phpunit.dist.xml`** — overwrite the file the Symfony recipe generated, or
merge the two additions into it. The `<testsuites>` and `<source>` paths assume
`tests/` and `src/`. The `<extensions>` block registers
`dama/doctrine-test-bundle`, so it needs
`composer require --dev dama/doctrine-test-bundle` **and** a
`DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true]`
line in `config/bundles.php` — the bundle's recipe is a contrib one and Composer
prints `IGNORING` instead of applying it. Delete the whole `<extensions>` block
on a project without Doctrine. What you must not do is keep the block and skip
the `bundles.php` line: the extension then finds no connection to wrap, every
test commits, and the suite stays green while it leaks rows.

The two attributes the recipe does not ship are deliberate.
`failOnPhpunitNotice="true"` is what turns the mock-without-expectations notice
red — `failOnNotice` alone does not, measured on PHPUnit 13.3.2 as exit `0`
against exit `1`. `displayDetailsOnPhpunitNotices="true"` makes the report name
the test instead of only counting it.

**`github-workflow-ci.yml`** — the database service must match production. The
file ships with PostgreSQL 16; testing on one engine and shipping on another
means the differences show up in production instead of in CI. Its test job runs
`debug:config dama_doctrine_test` before the suite, which is the only mechanical
check that isolation is really on.

## Adopting this on an existing codebase

Level max on a codebase that has never seen PHPStan produces thousands of
errors, and the usual outcome is that the tool gets removed a week later.

```bash
castor stan-baseline    # or: make stan-baseline
```

Commit `phpstan-baseline.neon` and uncomment the `includes` line. New code is
written at level max; the existing debt is frozen. The rule that makes this work:
**the baseline may shrink, never grow.** A pull request that adds entries to it
is adding debt on purpose, and that should be an explicit conversation rather
than a silent commit.

The same applies to deptrac: start with `--report-uncovered` without
`--fail-on-uncovered`, fix the violations domain by domain, then make it
blocking.
