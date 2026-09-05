# Writing a command

The three forms by version, how input is inferred, what to do about output and signals,
and how to test the result.

## Contents

- [The three forms](#the-three-forms) · [Input inference](#input-inference-in-full) ·
  [Injection by type](#what-gets-injected-by-type) · [Exit codes](#exit-codes) ·
  [Output and verbosity](#output-and-verbosity) ·
  [Signals](#signals-stopping-cleanly) · [Testing](#testing)

## The three forms

Same command, three ways. Pick by what the project's `vendor/` supports.

### Symfony 8.1+ — one class per family, `#[AsCommand]` on methods

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\{Argument, AsCommand, Option};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ShelfCommands
{
    public function __construct(
        private readonly ShelfPurger $purger,
        private readonly ClockInterface $clock,
    ) {
    }

    #[AsCommand(name: 'app:shelf:purge', description: 'Remove abandoned shelves')]
    public function purge(
        SymfonyStyle $io,
        #[Option(description: 'Actually delete, instead of reporting')] bool $force = false,
    ): int {
        $report = $this->purger->purge($this->clock->now()->modify('-1 year'), dryRun: !$force);
        $io->success(\sprintf(
            '%d shelves %s.',
            $force ? $report->deleted : $report->concerned,   // deleted is 0 on a dry run
            $force ? 'deleted' : 'concerned',
        ));

        return Command::SUCCESS;
    }

    #[AsCommand(name: 'app:shelf:show', description: 'Show one shelf')]
    public function show(SymfonyStyle $io, #[Argument(description: 'Shelf id')] int $id): int
    {
        return Command::SUCCESS;
    }
}
```

Enforced at container compile time: the class **must not** extend `Command` (doing both
throws *"cannot define a method command when it is a subclass of Command"*), and the
methods must be **public and non-static**. One `Command` object is built per attributed
method, so the two are independent commands that happen to share a constructor.

### Symfony 7.3 – 8.0 — one invokable class per command

```php
#[AsCommand(name: 'app:shelf:purge', description: 'Remove abandoned shelves')]
final class PurgeShelvesCommand
{
    public function __construct(
        private readonly ShelfPurger $purger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Actually delete')] bool $force = false,
    ): int {
        // identical body
    }
}
```

Same rules for `#[Argument]` / `#[Option]`; only the placement of `#[AsCommand]` moves.
Grouping two commands in one class is not possible before 8.1 — write two classes.

### Symfony 6.4 – 7.2 — `extends Command`

```php
#[AsCommand(name: 'app:shelf:purge', description: 'Remove abandoned shelves')]
final class PurgeShelvesCommand extends Command
{
    public function __construct(
        private readonly ShelfPurger $purger,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();          // mandatory, and easy to forget
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $report = $this->purger->purge($this->clock->now()->modify('-1 year'), dryRun: !$force);
        $io->success(\sprintf(
            '%d shelves %s.',
            $force ? $report->deleted : $report->concerned,   // deleted is 0 on a dry run
            $force ? 'deleted' : 'concerned',
        ));

        return Command::SUCCESS;
    }
}
```

Forget `parent::__construct()` and you get a command with no name. This form is **not
deprecated** in 8.1 — the framework's own commands still use it. It is simply more
ceremony for the same result, with the input definition written twice: once in
`configure()`, once in every `getOption()` call, with nothing checking they agree.

## Input inference, in full

Checked against `Symfony\Component\Console\Attribute\Argument` and `…\Option`, and
confirmed by running `--help`.

### Arguments

| Signature | Definition |
|---|---|
| `#[Argument] int $bookId` | required `<book-id>` |
| `#[Argument] string $shelf = 'to-read'` | optional `[<shelf>]`, default shown in help |
| `#[Argument] ?string $shelf` | optional (nullable counts as optional) |
| `#[Argument] array $shelves = []` | `[<shelves>...]`, an array |
| `#[Argument] string ...$shelves` | same, variadic |
| `#[Argument] BookStatus $status` | backed enum: values become completion suggestions |

Untyped, union and intersection types throw. Required arguments come first, as in PHP.

### Options

| Signature | Definition |
|---|---|
| `#[Option] bool $force = false` | `--force`, a flag |
| `#[Option] bool $report = true` | `--report\|--no-report`, negatable |
| `#[Option] ?string $olderThan = null` | `--older-than=OLDER-THAN` |
| `#[Option] int $batchSize = 500` | `--batch-size=BATCH-SIZE [default: 500]` |
| `#[Option] array $tag = []` | `--tag=TAG`, repeatable |
| `#[Option(shortcut: 'f')] bool $force = false` | adds `-f` |

Three errors the container raises, with clear messages: **no default** (*"must declare a
default value"* — an option is optional by definition; if it is required it is an
argument), **nullable with a non-null default** (must be `= null`, or not nullable), and
**nullable `bool` with a boolean default** (nonsensical; pick one).

Names are **kebab-cased from the parameter name**, not from anything you wrote:
`$olderThan` is `--older-than`. Pass `name:` explicitly when you need something else.

Beyond this: `#[MapInput]` (7.4+) binds a whole DTO of arguments and options with
validator constraints; `#[Ask]`, `#[Interact]` (7.4+) and `#[AskChoice]` (8.1+) make a
parameter interactive — useful for a developer-facing tool, useless for anything a
scheduler runs.

## What gets injected by type

In an invokable command or a family method these types are filled in directly, with no
attribute: `SymfonyStyle`, `InputInterface`, `RawInputInterface`, `OutputInterface`,
`Application`, `Command`, `Cursor`. Anything else goes through the argument resolver. Take
`SymfonyStyle` rather than building one — doing it yourself loses the event dispatcher.

## Exit codes

The method must return an `int` — anything else raises a `TypeError` naming the command.

- **`Command::SUCCESS`** (0) — it did what it was asked, *including* a dry run that
  changed nothing and a locked run that skipped itself. Both are the design working.
- **`Command::FAILURE`** (1) — attempted and did not complete. Also what the framework
  returns for a missing required argument and for an uncaught exception, so a wrapper
  script cannot tell "bad invocation" from "job failed" by exit code alone.
- **`Command::INVALID`** (2) — *your* validation rejected a value that parsed fine: a date
  in the future, an unknown shelf, a batch size of zero.

## Output and verbosity

```php
$io->title('Purging shelves');
$io->section('Candidates');
$io->table(['Shelf', 'Books', 'Last activity'], $rows);
$io->definitionList(['Batch size' => 500], ['Dry run' => 'yes']);
foreach ($io->progressIterate($shelves) as $shelf) { /* … */ }
$io->success('1240 shelves deleted.');
$io->warning('3 shelves skipped: still referenced.');
$io->getErrorStyle()->error('Could not reach the search index.');
```

What belongs at each verbosity: **normal** the parameters used, the counts, the outcome;
**`-v`** one line per processed item; **`-vv`** timings, batch boundaries, query counts;
**`-vvv`** payloads. `--silent` (7.2+) suppresses everything including errors, `-q` nearly
everything — put nothing there you would miss. Guard with `$io->isVerbose()`,
`isVeryVerbose()`, `isDebug()` rather than passing `OutputInterface::VERBOSITY_*` to
`writeln()`: a guarded block does not pay to build a message it will not print.

Two habits that pay off once the output lands in a log file. **Echo the effective
parameters on the first line** — months later that log is the only record of what the
scheduler actually passed. And **errors on stderr** via `getErrorStyle()`, which is what
lets `command > report.txt` keep the report and still surface the failure.

## Signals: stopping cleanly

A command a deploy will `SIGTERM` mid-run must finish its current unit of work rather
than die between a `persist()` and a `flush()`.

```php
#[AsCommand(name: 'app:reviews:archive')]
final class ArchiveReviewsCommand extends Command implements SignalableCommandInterface
{
    private bool $shouldStop = false;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->repository->iterateStale() as $review) {
            $this->archiver->archive($review);      // commits its own unit of work

            if ($this->shouldStop) {
                $io->warning('Stopped on signal, work committed.');

                return Command::SUCCESS;
            }
        }

        return Command::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        return [\SIGTERM, \SIGINT];
    }

    public function handleSignal(int $signal, int|false $previous = 0): int|false
    {
        $this->shouldStop = true;

        return false;      // false = do not exit now, let execute() finish its unit
    }
}
```

**This must be the `extends Command` form.** Verified on Symfony 8.1.5: an *invokable*
command implementing `SignalableCommandInterface` never has `handleSignal()` called. The
compiler pass wraps the service in a `Closure` before `setCode()`, and `InvokableCommand`
decides whether a command is signalable by `instanceof` on that closure — which a closure
never satisfies, so `getSubscribedSignals()` returns `[]`.

The failure mode is silent: on `SIGTERM` the process exits **0** with no message, because
`ConsoleSignalEvent` defaults its exit code to `0` and nothing overrode it. A command that
looks like it finished and in fact stopped halfway. Check `vendor/` before assuming this
is still true; until it changes, a signal-handling command is the one class in the project
that extends `Command`, and it is worth a comment saying why.

## Testing

The service carries the rules and gets the real tests (nominal, boundaries, errors); the
command gets one or two proving the wiring holds.

### Symfony 8.1+ — `static::runCommand()`

`ConsoleCommandAssertionsTrait` is already used by `KernelTestCase`, so both it and
`WebTestCase` have `runCommand()` available, and it boots the kernel itself.

```php
final class ShelfCommandsTest extends KernelTestCase       // no bootKernel() needed
{
    #[Test]
    public function it_reports_without_deleting(): void
    {
        $result = self::runCommand('app:shelf:purge', verbosity: OutputInterface::VERBOSITY_VERBOSE);

        $this->assertCommandIsSuccessful($result);
        self::assertStringContainsString('shelves concerned', $result->getDisplay());
    }
}
```

`runCommand(string $name, array $input = [], array $interactiveInputs = [], ?bool
$interactive = null, ?bool $decorated = null, ?int $verbosity = null, array $normalizers
= [])` returns an `ExecutionResult`: public readonly `$statusCode`, plus `getDisplay()`
(both streams combined), `getOutput()` and `getErrorOutput()` separately.

Assertions: `assertCommandIsSuccessful()`, `assertCommandFailed()`,
`assertCommandIsInvalid()`, `assertCommandResultEquals()` — **instance** methods taking
the result, so `$this->assertCommandIsSuccessful($result)`, not `self::`.

### Before 8.1 — `CommandTester`

```php
self::bootKernel();

$application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
$tester = new CommandTester($application->find('app:shelf:purge'));

self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
$tester->assertCommandIsSuccessful();
self::assertStringContainsString('deleted', $tester->getDisplay());
```

Use `Symfony\Bundle\FrameworkBundle\Console\Application`, not the component's: the
component's constructor takes a name string, so passing the kernel gives a `TypeError`
that reads like a bug in the test framework. For a command that asks questions, pass the
answers as the second argument of `run()` (8.1+) or via `$tester->setInputs([...])` before
`execute()`. Database isolation and fixtures: **symfony-yoandev-testing**.
