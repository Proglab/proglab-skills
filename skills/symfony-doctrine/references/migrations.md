# Migrations

A generated migration is a draft. What to look for before running it, and what the
generator cannot know. Verified against doctrine/migrations 3.9 and
doctrine-migrations-bundle 4.0.

## Contents

- [The loop](#the-loop)
- [What the generator actually does](#what-the-generator-actually-does)
- [The rename that deletes a column](#the-rename-that-deletes-a-column)
- [A NOT NULL column on a populated table](#a-not-null-column-on-a-populated-table)
- [Type changes](#type-changes)
- [down()](#down)
- [Data migrations](#data-migrations)
- [Transactions, and where they do not exist](#transactions-and-where-they-do-not-exist)
- [Platforms](#platforms)
- [Large tables: what actually locks](#large-tables-what-actually-locks)
- [Reviewing a migration](#reviewing-a-migration)
- [CI and deploy](#ci-and-deploy)

## The loop

```bash
php bin/console make:migration            # or doctrine:migrations:diff
$EDITOR migrations/VersionYYYYMMDDHHMMSS.php
php bin/console doctrine:migrations:migrate
php bin/console doctrine:schema:validate
```

The editor step is not optional and not a formality. It is where the migration stops
being a diff of two schemas and starts being a plan for moving real data.

## What the generator actually does

It introspects the live database, builds the schema the mapping describes, diffs them,
and emits the SQL that turns the first into the second. That is all. It has no access to:

- **the rows.** It cannot know a column has data, so it never hesitates to drop one.
- **your intent.** A property renamed from `name` to `title` is, to a schema diff, one
  column gone and one column appeared.
- **the deploy order.** It assumes the whole change lands at once.
- **any platform but yours.** The SQL is generated for the database it introspected. If
  developers run SQLite and production runs PostgreSQL, the file in `migrations/` is
  SQLite SQL and it will not run in production. Generate migrations against the same
  engine you deploy to — this is the strongest practical argument for having PostgreSQL
  locally.

## The rename that deletes a column

```php
// generated
$this->addSql('ALTER TABLE book ADD title VARCHAR(255) NOT NULL');
$this->addSql('ALTER TABLE book DROP name');
```

This is correct SQL, it passes CI on an empty test database, and every title in
production is gone. When rows exist, the `NOT NULL` on the `ADD` fails first and turns
data loss into a failed deploy — which is luck, not a safety net, and it does not apply
to the nullable case.

The correction is one line:

```php
$this->addSql('ALTER TABLE book RENAME COLUMN name TO title');
```

Same for a renamed table (`ALTER TABLE … RENAME TO …`). Every `DROP` in a generated
migration deserves the question: is this really gone, or is it moving?

## A NOT NULL column on a populated table

```php
// generated — fails the moment the table is not empty
$this->addSql('ALTER TABLE book ADD isbn VARCHAR(20) NOT NULL');
```

Three statements, in this order:

```php
$this->addSql('ALTER TABLE book ADD isbn VARCHAR(20) DEFAULT NULL');
$this->addSql("UPDATE book SET isbn = '' WHERE isbn IS NULL");
$this->addSql('ALTER TABLE book ALTER COLUMN isbn SET NOT NULL');
```

Backfilling with a placeholder is a decision, not a default — sometimes the honest
answer is that the column stays nullable until the data exists. Make that call
explicitly; do not let a failing deploy make it for you at 6pm.

## Type changes

A widening change (`VARCHAR(20)` → `VARCHAR(255)`, `int` → `bigint`) is safe. A narrowing
one truncates or errors depending on the platform, and the generator emits it without
comment. `text` → `varchar(255)`, `datetime` → `date`, `decimal(10,2)` → `decimal(10,0)`
all silently lose information on at least one engine.

Check the data first:

```sql
SELECT COUNT(*) FROM book WHERE LENGTH(isbn) > 20;
```

If the answer is not zero, the migration needs a data step before the type change.

## down()

The generator writes the mechanical inverse. It is right for a column addition and wrong
for anything that destroyed information: the `down()` of a dropped column recreates the
column, empty.

Two honest options. Write a `down()` that genuinely reverses the change when it can, or
call `$this->throwIrreversibleMigrationException('…')` when it cannot. What is not honest
is a `down()` that appears to restore something and restores an empty shell — someone
will run it during an incident and believe it worked.

## Data migrations

`addSql()` takes bound parameters, which is the safe way to write a backfill:

```php
public function up(Schema $schema): void
{
    $this->addSql(
        'UPDATE book SET status = :new WHERE status = :old',
        ['new' => 'in_progress', 'old' => 'reading'],
    );
}
```

For anything that needs to read before writing, `$this->connection` is available
(a `Doctrine\DBAL\Connection`, protected on `AbstractMigration`):

```php
foreach ($this->connection->iterateAssociative('SELECT id, isbn FROM book') as $row) {
    $this->addSql(
        'UPDATE book SET isbn = :isbn WHERE id = :id',
        ['isbn' => normalise($row['isbn']), 'id' => $row['id']],
    );
}
```

Use DBAL, not the entity manager. A migration that hydrates entities depends on today's
mapping, and it will break the day the entity changes again — at which point the
migration no longer replays on a fresh database, which is the one thing a migration must
always do.

A backfill over millions of rows does not belong in a migration at all: the deploy waits
for it and the table may be locked throughout. Ship the schema change in the migration
and the backfill as a console command with `--force` and batching (see
`symfony-console`).

## Transactions, and where they do not exist

`AbstractMigration::isTransactional()` returns `true` by default, and
`doctrine:migrations:migrate --all-or-nothing` wraps the whole run in one transaction.
Both are worth having on PostgreSQL, where DDL is transactional: a failure halfway
through leaves nothing behind.

**They are not independent.** `DbalMigrator` calls `assertAllMigrationsAreTransactional()`
*before* it opens the outer transaction, so under `--all-or-nothing` one migration
returning `isTransactional(): false` throws
`MigrationConfigurationConflict::migrationIsNotTransactional` and **the whole run aborts
without executing anything**. Keep that in mind before opting a migration out (see
"Large tables", below): the opt-out and the flag cannot ship in the same invocation.

**MySQL and MariaDB do not have transactional DDL.** Each `ALTER TABLE` commits
implicitly, so a migration that fails on its third statement leaves the first two
applied and the version row unwritten — the next run replays from the top and fails on
"column already exists". On those engines, keep migrations small enough that a partial
application is diagnosable, and expect to fix up by hand.

## Platforms

DBAL 4 renamed the platform classes: `SQLitePlatform` (was `SqlitePlatform`),
alongside `PostgreSQLPlatform`, `MySQLPlatform`, `MariaDBPlatform`, `SQLServerPlatform`,
`OraclePlatform` in `Doctrine\DBAL\Platforms`. A guard copied from an older project will
fatal on the old name.

```php
$this->abortIf(
    ! $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
    'This migration is written for PostgreSQL.',
);
```

Worth adding when a migration contains platform-specific SQL — a `USING` clause, a
partial index, `jsonb`. Not worth adding to every file: it is noise on a project with one
engine, and the real fix for a mixed setup is to stop having one.

## Large tables: what actually locks

Everything above assumes the table is small enough that a lock is invisible. Past a few
million rows it stops being invisible, and the deploy that took four seconds in staging
takes the site down.

The rule the suite already states — migrations run at deploy, a short interruption is
acceptable — holds up to that point and not beyond. Recognising when you have crossed it
is the whole job of the review.

### What is cheap and what rewrites the table

| Operation | PostgreSQL | MySQL 8 |
|---|---|---|
| `ADD COLUMN` nullable, no default | Metadata only | Instant (INPLACE) |
| `ADD COLUMN` with a constant default | Metadata only since **PG 11** — a full rewrite before | Instant (INSTANT algorithm) |
| `ADD COLUMN` with a volatile default (`now()`, a sequence) | Full rewrite | Full copy |
| `SET NOT NULL` | Full scan to verify | Full copy |
| `ALTER COLUMN TYPE` | Rewrite, except a few widening cases | Full copy, table locked for writes |
| `CREATE INDEX` | Blocks **writes** for the duration | Online since 5.6, but still expensive |
| `DROP COLUMN` | Metadata only | Instant |

The generator knows none of this. It emits correct SQL for an empty database and has no
idea the table has forty million rows.

### `CREATE INDEX CONCURRENTLY`, and why it fights Doctrine

On PostgreSQL, `CONCURRENTLY` builds the index without blocking writes. It is almost
always what you want on a live table — and it **cannot run inside a transaction**.

Doctrine wraps every migration in one by default: `AbstractMigration::isTransactional()`
returns `true`, and `--all-or-nothing` wraps the whole run. So the migration has to opt
out explicitly:

```php
final class Version20260101120000 extends AbstractMigration
{
    // CREATE INDEX CONCURRENTLY cannot run inside a transaction.
    // Opting out means a failure leaves this migration half-applied — which is
    // exactly why it does one thing and nothing else.
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX CONCURRENTLY idx_book_status ON book (status)');
    }
}
```

Three consequences worth stating. The generator will never write this — you add it by
hand. A `CONCURRENTLY` build that fails leaves an **invalid index** behind, which still
costs write performance while serving no reads; find it with
`SELECT * FROM pg_index WHERE indisvalid = false` and drop it before retrying.

And the one that stops a deploy dead: **this migration cannot run under
`--all-or-nothing`.** The pre-flight assertion described above throws on the whole plan,
so nothing is applied at all — including the unrelated migrations queued behind it. Give
a non-transactional migration its own `doctrine:migrations:migrate` invocation without
the flag, or keep it out of the deploy entirely.

### When the migration must leave the deploy

At some point a change cannot run while the deploy waits. The signal is simple: **if you
cannot say how long it takes, it does not belong in the deploy.**

Split it in two, which is the expand/contract shape `symfony-deployment` describes:

1. a migration that only adds — nullable column, index built concurrently — fast, safe,
   reversible, runs with the deploy;
2. a **console command** for the data itself, batched, resumable, `--force`-guarded,
   run when someone is watching (see `symfony-console`).

A backfill written as a batched command has a property a migration cannot have: you can
stop it, look at what it did, and start again where it left off. A migration is
all-or-nothing by design, which is the wrong shape for eight million rows.

Then, a later deploy, the contract step: `SET NOT NULL`, drop the old column. By then the
data is already correct, so the operation is short.

## Reviewing a migration

Read it as a diff, with these questions:

| Look for | Ask |
|---|---|
| `DROP COLUMN` / `DROP TABLE` | Is this gone, or renamed? |
| `NOT NULL` on an `ADD` | Is the table populated? |
| A type change | Can existing values still fit? |
| `DROP INDEX` | Is a query relying on it? |
| Missing `CREATE INDEX` | New foreign key or filter column with no index |
| `down()` | Does it actually reverse, or only look like it? |
| Statement count | Anything long enough to lock the table during deploy? |
| Row count of the target table | Do you know it? If not, you cannot know how long this takes |
| `CREATE INDEX` on a populated table | Should it be `CONCURRENTLY`, with `isTransactional()` returning false? |
| An `UPDATE` with no `WHERE` bound | How many rows, and does the deploy wait for all of them? |

Give the class a real `getDescription()`. `doctrine:migrations:list` prints it, and it is
the only context anyone gets when a migration fails in production at speed.

`--dry-run` and `--write-sql` print the SQL without executing it — useful for reviewing
against a copy of production, and for handing a DBA something to read.

## CI and deploy

```bash
php bin/console doctrine:migrations:up-to-date --fail-on-unregistered
php bin/console doctrine:schema:validate
```

The first fails when a migration exists that the database has not seen — including one
added by a merge, which is exactly the case nobody notices. The second fails when the
mapping and the database have drifted, which is what happens when someone edits an entity
and forgets to generate the migration. It also checks, since ORM 3, that PHP property
types match their Doctrine types; `--skip-sync`, `--skip-mapping` and
`--skip-property-types` narrow it when only one of the three is wanted.

Run migrations in CI **against the previous schema**, not against a freshly created one.
A migration that works on an empty database and fails on real data is the whole failure
mode this file is about, and only a real starting schema catches it.

At deploy, migrations run automatically and without a backward-compatibility constraint —
a short interruption is acceptable if a migration is incompatible with the running code.
The full deploy sequence and where migrations sit in it belong to `symfony-deployment`.

And the rule that has no exception: **`doctrine:schema:update` never runs against
production.** It answers "make this database match the mapping" by whatever means,
including dropping what it does not recognise, and it leaves no record that anything
happened.
