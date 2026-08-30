# Destructive, scheduled and long-running commands

Where a command stops being a convenience and starts being able to cause an incident. One
idea applied three times: make the dangerous thing require a deliberate act, make the
concurrent thing impossible, make the long thing bounded.

**Contents:** [dry run and `--force`](#dry-run-by-default---force-to-act) ·
[why not a confirmation](#why-not-an-interactive-confirmation) ·
[counts](#counts-before-and-after) · [full example](#the-full-example) ·
[testing it](#testing-an-irreversible-command) · [locking](#locking) ·
[lock stores](#choosing-a-lock-store) · [memory](#long-running-commands-and-doctrine-memory)

## Dry run by default, `--force` to act

Any command that deletes, truncates, anonymises, overwrites, re-sends or bulk-updates runs
in **report mode** unless `--force` is passed: it computes exactly what it would do,
prints it, changes nothing, and exits `Command::SUCCESS`.

The default matters more than the flag, because the dangerous invocation is the one nobody
thought about — somebody trying the command for the first time, a copy-pasted line in a
runbook, a scheduler entry written from memory. All of those produce the short form, so
the short form has to be the safe one. `--dry-run` as an opt-in is the same mechanism
pointed the wrong way: it protects the person who remembered, the one person who did not
need protecting.

## Why not an interactive confirmation

`$io->confirm('Delete 1240 shelves?')` looks stronger and is weaker. `--no-interaction` /
`-n` skips it, and that flag is in every CI job, deploy script and Ansible task, because
without it a prompting command hangs forever. A scheduled run has no TTY at all: the
question is not asked, the default answer is taken, nobody sees anything. And it cannot be
tested meaningfully — asserting that a prompt appeared says nothing about what happened
when the prompt was skipped.

`--force` survives all three: being on the command line it is visible in shell history, in
the scheduler definition, in the log line the command prints about its own parameters, and
in the test asserting nothing was deleted without it. Prompting is still fine as a *second*
layer on a human-facing command:

```php
if ($force && $io->isInteractive() && !$io->confirm(\sprintf('Delete %d shelves?', $count), false)) {
    $io->warning('Aborted.');

    return Command::SUCCESS;
}
```

Note the `false` default and the `isInteractive()` guard: friction for a human, invisible to
a machine.

## Counts before and after

Print two numbers, obtained from two independent queries around the operation.

```
Shelves concerned : 1240
Shelves deleted   : 1240
```

The point is the *gap*, not the number. Real causes, all silent: a foreign key with `ON
DELETE` behaviour that removed more than the command's filter selected; a batch loop that
stopped early on an exception a `catch` swallowed; a filter evaluated twice at different
moments; an `orphanRemoval` cascade nobody remembered. None of those raise an error.

Printing one variable twice proves nothing; printing `count()` and then the affected-rows
figure the operation actually returned is what makes the discrepancy visible. Count in the
service, in the same transaction as the delete, and return both numbers in a small result
object — then "concerned equals deleted" is a unit-testable assertion rather than
something a human has to notice in a log.

## The full example

**Written for Symfony 8.1.** Method-level `#[AsCommand]`, `#[Option]`,
`self::runCommand()` and the `ExecutionResult` assertions are all 8.1 additions. The
*pattern* is unchanged on every supported version — dry run by default, `--force` to act,
two counts from two queries, both branches tested; only the plumbing moves. On 7.3–8.0
write the command as an invokable class, on 6.4–7.2 as `extends Command` with
`configure()`/`execute()`, and test with `CommandTester` rather than `runCommand()`. All
three forms are side by side in `writing-commands.md`.

```php
final readonly class PurgeReport
{
    public function __construct(public int $concerned, public int $deleted, public bool $dryRun) {}

    public function isConsistent(): bool
    {
        return $this->dryRun ? 0 === $this->deleted : $this->concerned === $this->deleted;
    }
}

final class ShelfPurger
{
    public function __construct(
        private readonly ShelfRepository $shelves,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function purge(\DateTimeImmutable $before, bool $dryRun = true): PurgeReport
    {
        if ($dryRun) {
            return new PurgeReport($this->shelves->countAbandonedBefore($before), 0, true);
        }

        // Both statements in one transaction, which is what makes the gap between
        // them mean something: a row inserted between the COUNT and the DELETE
        // would otherwise show up as a discrepancy that is not a bug.
        //
        // Note there is no flush(): a bulk DELETE is executed immediately by the
        // DQL executor and returns its affected-row count. Nothing is queued in
        // the unit of work for a flush() to write.
        return $this->em->wrapInTransaction(function () use ($before): PurgeReport {
            $concerned = $this->shelves->countAbandonedBefore($before);
            $deleted = $this->shelves->deleteAbandonedBefore($before);   // affected rows

            return new PurgeReport($concerned, $deleted, false);
        });
    }
}

final class ShelfCommands
{
    use LockableTrait;

    public function __construct(
        private readonly ShelfPurger $purger,
        private readonly ClockInterface $clock,
        LockFactory $lockFactory,
    ) {
        $this->lockFactory = $lockFactory;
    }

    #[AsCommand(name: 'app:shelf:purge', description: 'Delete inactive shelves (reports unless --force)')]
    public function purge(
        SymfonyStyle $io,
        #[Option(description: 'Delete for real. Without it, the command only reports.')] bool $force = false,
        #[Option(description: 'Consider shelves untouched for this many days')] int $days = 365,
    ): int {
        if (!$this->lock('app:shelf:purge')) {
            $io->warning('Another purge is running; skipping this run.');

            return Command::SUCCESS;
        }

        try {
            $before = $this->clock->now()->modify(\sprintf('-%d days', $days));
            $io->writeln(\sprintf('Shelves untouched since %s — mode: %s',
                $before->format('Y-m-d'), $force ? 'DELETE' : 'dry run'));

            $report = $this->purger->purge($before, dryRun: !$force);

            $io->definitionList(
                ['Shelves concerned' => (string) $report->concerned],
                ['Shelves deleted' => (string) $report->deleted],
            );

            if (!$report->isConsistent()) {
                $io->getErrorStyle()->error(\sprintf('%d concerned but %d deleted — investigate.',
                    $report->concerned, $report->deleted));

                return Command::FAILURE;         // the gap is a failure, not a note
            }

            $force
                ? $io->success(\sprintf('%d shelves deleted.', $report->deleted))
                : $io->note(\sprintf('%d would be deleted. Rerun with --force.', $report->concerned));

            return Command::SUCCESS;
        } finally {
            $this->release();
        }
    }
}
```

Everything decidable is in `ShelfPurger` and `PurgeReport`, testable with no console. The
command chooses a message and an exit code.

## Testing an irreversible command

The exception to "a command only gets a smoke test": it is frequently the only entry point
to an operation with no undo, so the guard is worth testing — not the message, the guard.

```php
#[Test]
public function dry_run_deletes_nothing(): void
{
    $before = $this->countShelves();
    $result = self::runCommand('app:shelf:purge');

    $this->assertCommandIsSuccessful($result);
    self::assertSame($before, $this->countShelves());          // the assertion that matters
    self::assertStringContainsString('Rerun with --force', $result->getDisplay());
}

#[Test]
public function force_deletes_exactly_what_it_announced(): void
{
    $survivor = $this->getReference('shelf_active', Shelf::class)->getId();
    $result = self::runCommand('app:shelf:purge', ['--force' => true]);

    $this->assertCommandIsSuccessful($result);
    self::assertSame(0, $this->countAbandonedShelves());
    self::assertNotNull($this->shelves->find($survivor));      // nothing else touched
}
```

Three assertions carry the weight: **nothing changed** without `--force`, **only the
targeted rows changed** with it, and the exit code. Asserting on the wording of the success
message is churn — it will be reworded and the test will fail for no reason. The "nothing
else was touched" assertion is the one people skip and the one that catches a cascade:
seed a row that must survive, and assert it survived.

## Locking

A command scheduled every five minutes that sometimes takes six does not fail — it
overlaps. Two runs delete the same rows, double-send the same digest, or deadlock each
other. Each overlap is small, so the visible symptom arrives hours later as memory or
connection exhaustion, far from the cause.

`LockableTrait` lives in `symfony/console`, but the component it needs does not ship with
it — `composer require symfony/lock`. Skip that and the trait throws at runtime, on the
first execution, with *"To enable the locking feature you must install the symfony/lock
component."*

```php
if (!$this->lock('app:digest:send')) {
    $io->warning('Already running, skipping.');

    return Command::SUCCESS;
}

try {
    $this->digestSender->sendPending();

    return Command::SUCCESS;
} finally {
    $this->release();
}
```

**Return `SUCCESS`, not `FAILURE`, when the lock is held.** The command did the right
thing. A failure here makes a monitored scheduler alert during normal operation, and
alerts that fire during normal operation get muted.

**Release in `finally`**, or an escaping exception leaves the lock held. With the default
flock store the OS releases it when the process dies, which hides the mistake; with a
database or Redis store it does not, and the next run is blocked until the TTL expires
(`createLock()` defaults to 300 seconds, refreshed only while a process is alive to do so).

**Name the lock explicitly.** `$this->lock()` with no argument looks for `#[AsCommand]`
*on the class*. A method-level command family has none, so the trait throws *"Lock name
missing: provide it via LockableTrait::lock(), #[AsCommand] attribute, or by extending
Command class."* Passing the command name as a literal also makes the lock greppable. And
use `blocking: true` only when the second run must eventually happen — blocking a scheduler
is how you get the pile-up the lock was there to prevent.

## Choosing a lock store

`LockableTrait` **does not read `framework.lock` or `LOCK_DSN`**. Left alone it builds its
own `SemaphoreStore` if `sysvsem` is available and a `FlockStore` otherwise — both local to
one machine. Fine on a single server, wrong everywhere else: two application containers,
two cron hosts, a rolling deploy where old and new overlap briefly, each with its own
private lock excluding nobody.

The fix is the one line already in the example above:
`$this->lockFactory = $lockFactory;`. The trait only builds a factory when
`$this->lockFactory` is still null, so assigning the autowired `LockFactory`
(`lock.default.factory`) takes over. It becomes autowirable as soon as `symfony/lock` is
installed; the recipe writes `framework: lock: '%env(LOCK_DSN)%'` and `LOCK_DSN=flock`.

| Store | `LOCK_DSN` | Use when |
|---|---|---|
| Flock | `flock` | One machine only. The default, and the one that quietly does nothing on a second host |
| Semaphore | `semaphore` | One machine, `sysvsem` available |
| PostgreSQL advisory | `postgresql+advisory://…` | The database is already shared — no new infrastructure, locks vanish with the connection |
| Redis | `redis://…` | Redis is already running |

The advisory-lock store fits this standard: Messenger already uses the Doctrine transport,
so the database is the one thing every process shares. Reuse `DATABASE_URL` rather than
introducing Redis for one lock.

Checklist: `symfony/lock` actually in `composer.json`; lock acquired before any work, name
passed explicitly; lock held → `SUCCESS` plus a `warning()`; `release()` in `finally`;
`LockFactory` injected when more than one machine runs the command.

## Long-running commands and Doctrine memory

The identity map keeps every entity the unit of work has seen: over a large batch that is
not a leak but linear growth, ending in `Allowed memory size exhausted`. Two levers, used
together:

```php
// Repository: the query, as always. toIterable() hydrates one row at a time.
/** @return iterable<Review> */
public function iterateStaleReviews(\DateTimeImmutable $before): iterable
{
    return $this->createQueryBuilder('r')
        ->andWhere('r.updatedAt < :before')->setParameter('before', $before)
        ->getQuery()->toIterable();
}

// Service: the batching, so it is unit-testable.
$processed = 0;

foreach ($this->reviews->iterateStaleReviews($before) as $review) {
    $this->archive($review);

    // ++$processed, not the loop key: toIterable() yields 0-based keys, so
    // `0 === $i % 500` flushes and clears on the very first iteration —
    // detaching the entity just archived before a batch has accumulated.
    if (0 === ++$processed % 500) {
        $this->em->flush();
        $this->em->clear();
    }
}

$this->em->flush();       // the last, partial batch
$this->em->flush();
```

- **`clear()` detaches everything**, including entities the caller still holds. A
  reference kept across that boundary becomes a detached object that silently stops
  participating in `flush()`. Re-fetch by id afterwards, or `detach()` the single entity.
- **`toIterable()` does not work with fetch-joined collections** — it hydrates row by row,
  and a joined `to-many` spans several rows. Fetch the collection separately.
- **The SQL logger keeps every query in `dev`.** A long command run there exhausts memory
  for a reason unrelated to entities. Use `--env=prod`, and do not reach for
  `memory_limit=-1`: it turns a bounded failure into an unbounded one.
