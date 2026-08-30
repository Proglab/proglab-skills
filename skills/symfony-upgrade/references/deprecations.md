# Deprecations

Everything you must fix before a major is announced as a deprecation on the minors that
precede it. This file is about finding them, deciding whose problem each one is, and
knowing when freezing them is honest.

## Four detectors, and none of them is enough alone

| Detector | Sees | Blind to |
|---|---|---|
| PHPUnit | What the tests execute | Every path the tests do not cover |
| Profiler / `var/log/dev.log` | What you clicked | What you did not click |
| The prod `deprecation` channel | What real users do | Nothing, eventually — but it costs a release |
| PHPStan `phpstan-deprecation-rules` | Every call to an `@deprecated` API, executed or not | Deprecations triggered by *configuration* rather than by a call |

The last row is the important pairing. A large share of Symfony deprecations are not
"you called a deprecated method" — they are "your `services.yaml` used an option that is
going away", raised at container compile time, invisible to a static analyser reading
your PHP. And a large share of *your own* dead code is invisible to the runtime
detectors, because nothing exercises it. Use both.

## PHPUnit: the classification

PHPUnit 13 does not just report deprecations, it decides who caused each one, using
`<source>` to define "first-party". From `IssueTrigger`:

- **self** — the deprecation fires in a file under `<source>`, or in test code.
- **direct** — first-party code called into third-party code, which deprecated itself.
- **indirect** — third-party called third-party. You are a bystander.
- **unknown** — the trace gave neither a caller nor a callee it could categorise.

That maps exactly onto what you can do about it:

```
self       → delete the deprecated thing; you own every caller
direct     → change your call site; the fix is in your repository
indirect   → upgrade the dependency, or wait for it. Nothing else works.
```

Measured, PHPUnit 13.3.2, one test per row, on the recipe's `phpunit.dist.xml`:

| Case | Displayed | Exit |
|---|---|---|
| `trigger_deprecation()` in a class under `src/` | yes | `1` |
| a test calls a deprecated method in `vendor/` | yes | `1` |
| a class in `vendor/` calls another class in `vendor/` | **no** | `0` |
| same, `ignoreIndirectDeprecations="false"` | yes | `1` |

Note the third row carefully: **a vendor-to-vendor deprecation does not appear in the
output at all.** It is not listed and counted-but-tolerated; it is filtered before the
printer. If you are trying to answer "how ready are my dependencies for the next major",
the default configuration will tell you they are perfect.

### The two attributes that make it work

**This whole mechanism needs PHPUnit ≥ 11.2** — the self / direct / indirect
classification, the `<source>` attributes below, `<deprecationTrigger>`, and
`--generate-baseline` / `--use-baseline` for deprecations all arrived there. A Symfony 6.4
project still on PHPUnit 9.6 or 10.5 has none of them. What to write instead: install
`symfony/phpunit-bridge` and set `SYMFONY_DEPRECATIONS_HELPER` in `phpunit.dist.xml` —
its `max[self]` / `max[direct]` / `max[indirect]` thresholds express the same three
categories by a different route, including the "fail on mine, tolerate the vendors'"
default this section argues for. The triage rules below do not change; only the tool
reporting them does.

```xml
<source ignoreSuppressionOfDeprecations="true"
        ignoreIndirectDeprecations="true"
        restrictNotices="true"
        restrictWarnings="true"
>
    <include>
        <directory>src</directory>
    </include>

    <deprecationTrigger>
        <method>Doctrine\Deprecations\Deprecation::trigger</method>
        <method>Doctrine\Deprecations\Deprecation::delegateTriggerToBackend</method>
        <function>trigger_deprecation</function>
    </deprecationTrigger>
</source>
```

- **`ignoreSuppressionOfDeprecations="true"` is load-bearing.**
  `symfony/deprecation-contracts` implements `trigger_deprecation()` as
  `@trigger_error($message, \E_USER_DEPRECATED)` — silenced with `@`. Remove this
  attribute and PHPUnit honours the suppression, which means it ignores essentially every
  Symfony deprecation ever raised, silently, while `failOnDeprecation="true"` sits in the
  file looking reassuring.
- **`<include><directory>src</directory>` is what "your code" means here.** If a project
  keeps code outside `src/`, add it, or those files are classified as third-party and
  their deprecations are dropped.
- **`<deprecationTrigger>`** tells PHPUnit that a deprecation reported *at the line of
  `trigger_deprecation()`* should be attributed to that function's caller instead.
  Without it every Symfony deprecation is blamed on
  `deprecation-contracts/function.php`, and the classification collapses.

`symfony-quality` owns the file; this is what the deprecation-related half of it is for.

## The profiler and the dev log

Through the real front controller, a deprecation goes to Monolog's dedicated
`deprecation` channel. Verified on a dev request:

```
[2026-08-30T17:04:43.247873+00:00] deprecation. ... Since app/shelf 1.4: ProbeController is deprecated.
```

In the browser that is the **Logs panel of the profiler**, which has a Deprecation tab
with its own count badge, next to Errors; the toolbar shows the same number. It is the
fastest way to answer "what does *this page* still do wrong", and it covers the paths
nobody wrote a test for.

**It does not work from inside a test.** Measured: a `WebTestCase` calling
`$client->enableProfiler()` on an action that triggers a deprecation gets
`countDeprecations() === 0`. Symfony's debug error handler — the piece that turns
`E_USER_DEPRECATED` into a log record — is installed by `symfony/runtime` in the front
controller, and a `WebTestCase` never goes through it; PHPUnit's own handler is what
catches the deprecation instead. So do not write a test that asserts on the profiler's
deprecation count. Assert nothing: `failOnDeprecation` already is the assertion.

### Deprecations that no request triggers

```bash
php bin/console debug:container --deprecations
```

```
 [OK] There are no deprecations in the logs!
```

This reads the deprecations emitted while **compiling the container and warming the
cache** — configuration options, bundle extensions, deprecated service definitions.
Nothing else surfaces those, and they are exactly the category that turns into a fatal
`InvalidConfigurationException` on the next major. Run it against every environment you
ship, not just `dev`: `php bin/console --env=prod debug:container --deprecations`.

### In production

The Monolog recipe already ships the right setup, so verify it rather than build it:
a `deprecation` channel is declared, the main `fingers_crossed` handler excludes it
(`channels: ["!deprecation"]`, so a deprecation does not flush a buffer of debug logs),
and a dedicated handler writes it to `php://stderr` as JSON.

Production is the only detector that sees what your users actually do. On a project with
thin test coverage it is the honest answer to "which deprecated paths are still alive" —
ship one release, wait a week, read the logs.

## Baselines

PHPUnit can freeze the current set:

```bash
vendor/bin/phpunit --generate-baseline phpunit-baseline.xml
vendor/bin/phpunit --use-baseline phpunit-baseline.xml
```

The generating run still exits `1`; the second run exits `0` and prints
`1 issue was ignored by baseline.` The file is per line, with a content hash:

```xml
<?xml version="1.0"?>
<files version="1">
 <file path="src/Legacy/ShelfCounter.php">
  <line number="11" hash="0a9cde23e0c630bdef02750cae963781e0b07e0d">
   <issue><![CDATA[Since app/shelf 1.4: Use "ShelfReader::count()" instead.]]></issue>
  </line>
 </file>
</files>
```

That granularity is the good news and the bad news. Touch the file and the hashes move,
so the baseline needs regenerating — which means a baseline is not a place things can
quietly rot, but also that "regenerate the baseline" becomes a reflex that hides new
debt inside an old file.

**When a baseline is honest:** you have just adopted this standard on a codebase with
hundreds of pre-existing deprecations and you need the suite to go red on *new* ones
tomorrow. The debt is frozen, visible in a committed file, and the rule is the same one
`symfony-quality` applies to the PHPStan baseline — **it may shrink, never grow**.

**When it is procrastination:** you are three months from a major upgrade and the
baseline holds the very deprecations that will become fatal errors. Freezing those buys
nothing; the deadline does not move because you stopped looking at it. The tell is the
content: a baseline full of `Since symfony/… 7.x:` entries is a list of your upgrade
tasks, not a list of things you have decided to tolerate.

Between the two: keep the baseline, and delete entries from it in batches as part of
normal work, so the file is shrinking on its own before the upgrade starts.

## Static detection

`phpstan-deprecation-rules` is already in the standard's `phpstan.dist.neon`. It reports
call sites regardless of coverage:

```
  Line   probe.php
  14     Call to deprecated method count() of class Probe\Shelf:
         since app/shelf 1.4, use countBooks() instead
         🪪  method.deprecated
```

The message carries the `@deprecated` docblock text, which usually names the
replacement. That is the fastest fix loop there is — no test run, no browsing.

Run it through the phpqa image like every other tool (`symfony-quality` owns the
invocation). One image-specific detail worth knowing if you invoke PHPStan by hand: the
extensions live at
`/tools/.composer/vendor-bin/phpstan/vendor/phpstan/phpstan-deprecation-rules/rules.neon`
inside the container, not under the project's `vendor/`, and they are not auto-registered
— a config whose `includes` point at `vendor/phpstan/…` finds nothing there.

## Writing your own

`trigger_deprecation('vendor/package', '1.4', 'message %s', $arg)` comes from
`symfony/deprecation-contracts`, which is already installed transitively. In an
application it is almost always the wrong tool: you own every caller, so you change them
in the same commit and delete the old code. Reach for it only where the callers are
genuinely out of reach — a shared internal package, a public API of your own with other
teams behind it.

If you do use it, remember it costs you a red suite as soon as anything in `src/` calls
it, because that is a *self* deprecation. That is the correct behaviour, and it means a
deprecation in your own code is a task with a deadline rather than a note.

## Symptom, cause, fix

| Symptom | Cause | Fix |
|---|---|---|
| `OK, but there were issues!` and CI fails anyway | The summary line is not the exit code; deprecations exit `1` | Trust the exit code; read the deprecation list above the summary |
| No deprecation ever reported, on a very old project | `ignoreSuppressionOfDeprecations` missing; Symfony's are all `@`-suppressed | Add it to `<source>` |
| Every deprecation blamed on `function.php` | `<deprecationTrigger>` missing | Add the three entries from the recipe |
| Deprecations vanish after moving code out of `src/` | The new directory is not in `<source><include>` | Add the directory |
| Tests green, production log full of deprecations | Untested paths | Read the prod `deprecation` channel; it is the coverage report you did not write |
| A deprecation you cannot find in any PHP file | Raised at container compile time | `debug:container --deprecations`, per environment |
| `composer update` fixed nothing | `extra.symfony.require` pins the minor | See `major-upgrade.md` |
