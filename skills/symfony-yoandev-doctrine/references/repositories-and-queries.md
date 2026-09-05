# Repositories and queries

The only layer allowed to speak SQL, and how to make it return as little as possible.
Verified against Doctrine ORM 3.6 / doctrine-bundle 3.3.

## Contents

- [Anatomy](#anatomy)
- [Naming methods](#naming-methods)
- [persist and remove, never flush](#persist-and-remove-never-flush)
- [QueryBuilder essentials](#querybuilder-essentials)
- [Fetch joins and HIDDEN](#fetch-joins-and-hidden)
- [SELECT NEW projections](#select-new-projections)
- [Aggregates and their PHP types](#aggregates-and-their-php-types)
- [Counting](#counting)
- [Pagination](#pagination)
- [Single results and their exceptions](#single-results-and-their-exceptions)
- [Raw SQL](#raw-sql)
- [Transactions](#transactions)
- [PHPStan](#phpstan)

## Anatomy

```php
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Not `final`: service unit tests double it.
 *
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }
}
```

Those two imports are the ones that get typed wrong: `ManagerRegistry` comes from
`Doctrine\Persistence`, not from `Doctrine\ORM` and not from the DBAL registry;
`ServiceEntityRepository` comes from the **bundle**, not from the ORM. An autowiring
error naming `ManagerRegistry` is almost always one of these.

Inside the class, `$this->getEntityManager()` (protected) and `$this->createQueryBuilder()`
are what you have. `$this->_em` was removed in ORM 3 and is not coming back.

## Naming methods

Name the method after what the caller wanted, not after the SQL it happens to produce.

| Write this | Not this |
|---|---|
| `findLatestPublishedForBook()` | `findByBookOrderedByDateDesc()` |
| `countUnreadForShelf()` | `countByStatusNull()` |
| `findShelfRows()` | `findAllWithJoin()` |

Two reasons. The call site reads as a sentence, and the method keeps telling the truth
when the query changes — adding a `publishedAt IS NOT NULL` clause to
`findByBookOrderedByDateDesc` makes the name a lie, and nobody renames it.

The corollary: **no `findBy(['status' => …])` from a service.** The magic finders and the
array criteria are DQL by another name, and deptrac cannot see them.

## persist and remove, never flush

```php
public function add(Review $review): void
{
    $this->getEntityManager()->persist($review);
}
```

Plus `remove()`, calling `$this->getEntityManager()->remove()`. That is the whole write
API. The service decides when the unit of work is complete and calls `flush()` once. A
repository that flushes makes "create three, then save" cost three transactions, and
removes the only place a rollback could have been decided.

## QueryBuilder essentials

```php
return $this->createQueryBuilder('r')
    ->andWhere('r.author = :author')
    ->andWhere('r.publishedAt >= :since')
    ->setParameter('author', $author)
    ->setParameter('since', $since)
    ->orderBy('r.publishedAt', 'DESC')
    ->getQuery()
    ->getResult();
```

- **`andWhere()` from the first clause**, never `where()`. `where()` silently discards
  everything added before it, which is how a conditional filter deletes the security
  check above it.
- **Always `setParameter()`.** Interpolating into DQL is an injection and it defeats the
  query cache. Entities can be passed directly — Doctrine takes the identifier.
- **DQL names properties, not columns.** `r.publishedAt`, not `r.published_at`. The
  error message for a column name is `has no field or association named`.
- `IN` takes an array parameter directly: `->andWhere('b.id IN (:ids)')->setParameter('ids', $ids)`.

## Fetch joins and HIDDEN

To avoid N+1 when the caller will walk a relation, join **and select** it:

```php
->leftJoin('r.author', 'a')
->addSelect('a')          // without this, each author is a separate query
```

`addSelect('a')` is what makes it a fetch join. The `leftJoin` alone only makes the alias
available to `WHERE`.

`HIDDEN` puts a computed value in `ORDER BY` or `GROUP BY` without adding it to the
result. Without it the hydrator turns each row into `[0 => Book, 'expr' => …]` and the
method no longer returns a `list<Book>`:

```php
->addSelect('CASE WHEN b.lastReadAt IS NULL THEN 1 ELSE 0 END AS HIDDEN never_read')
->orderBy('never_read', 'ASC')
->addOrderBy('b.lastReadAt', 'DESC')
```

## SELECT NEW projections

A list page that hydrates entities pays for the identity map, change tracking and every
column, then renders four fields. Project into a DTO in `src/Dto/Read/` instead:

```php
// src/Dto/Read/ShelfStatsRow.php — the aggregate projection.
// `symfony-yoandev-architecture`, references/dtos.md, owns this class's shape; keep the
// two in step. The plain no-aggregate projection is a different class,
// `ShelfRow` (see `symfony-yoandev-performance`) — same table, different contract.
final readonly class ShelfStatsRow
{
    public function __construct(
        public int $bookId,
        public string $title,
        public float|string|null $averageRating,   // NULL when nobody has rated it
        public int|string $reviewCount,
        public ?\DateTimeImmutable $lastReadAt,
    ) {
    }
}
```

```php
/** @return list<ShelfStatsRow> */
public function findShelfStats(): array
{
    return $this->createQueryBuilder('b')
        ->select(sprintf(
            'NEW %s(b.id, b.title, AVG(r.rating), COUNT(r.id), b.lastReadAt)',
            ShelfStatsRow::class,
        ))
        ->leftJoin('b.reviews', 'r')
        ->groupBy('b.id')
        ->getQuery()
        ->getResult();
}
```

Three things worth knowing, all verified by running it:

- The class name in `NEW` is fully qualified **without a leading backslash**, which is
  exactly what `::class` gives — build it with `sprintf()` rather than hard-coding a
  string no IDE will rename.
- Constructor arguments are positional. There is no named-argument form, and a mismatch
  surfaces as a `TypeError` from the DTO constructor, not as a DQL error.
- **A column mapped with `enumType:` arrives as the enum instance**, not the backed
  value — so project `b.status` into a property typed `ReadingStatus`, never `string`,
  which throws `must be of type string, ReadingStatus given`. The same conversion runs
  for `date_immutable`, which is why `lastReadAt` above is a `\DateTimeImmutable` and not
  a string. Aggregates are the exception: they have no Doctrine type to convert through,
  which is the next section.

Why this beats hydrating entities: no identity map entry, no change-set computation at
the next flush, no lazy proxies waiting to fire a query from a template, and only the
columns you named crossing the wire.

The Read DTO holds raw values in the shape the query dictates. The service normalises
Read → Output — rounding the average, formatting dates as ISO strings, deciding whether
an absent rating is `null` or `0`. That normalisation is a business rule, it belongs in a
service, and it is unit-testable without a database. When the projection already matches
the API contract exactly, project straight into the Output DTO and skip the layer.

## Aggregates and their PHP types

Aggregate columns have no Doctrine type to convert through, so the driver decides. On
SQLite, `COUNT` and `SUM` arrive as `int` and `AVG` as `float` (verified). On PostgreSQL,
`COUNT`/`SUM` return `bigint` and `AVG` returns `numeric`, which PDO commonly hands back
as **strings**. Check on your own platform rather than trusting either.

This is not a detail to guess at. Declare the union (`int|string`, `float|string|null`)
and let the service cast — which is the honest shape for a Read DTO, whose whole job is
to carry raw values. Narrow types are only safe for columns that are mapped entity
fields, where Doctrine did the conversion.

`AVG` over an empty group returns `null`, not `0`. A `leftJoin` plus `GROUP BY` produces
exactly that for every parent with no children, so the Read DTO's property is nullable
and the service decides what "no ratings yet" renders as.

## Counting

```php
public function countPublishedForBook(Book $book): int
{
    return (int) $this->createQueryBuilder('r')
        ->select('COUNT(r.id)')
        ->andWhere('r.book = :book')
        ->andWhere('r.publishedAt IS NOT NULL')
        ->setParameter('book', $book)
        ->getQuery()
        ->getSingleScalarResult();
}
```

The `(int)` cast is both a platform guard and what makes the declared `int` return type
honest to PHPStan — `getSingleScalarResult()` is declared `mixed`.

For a list, one query for the whole page rather than one per row: group by the parent id
and return a map.

```php
/** @return array<int, int> book id => review count */
public function countPublishedByBook(): array
{
    $rows = $this->createQueryBuilder('r')
        ->select('IDENTITY(r.book) AS bookId, COUNT(r.id) AS total')
        ->andWhere('r.publishedAt IS NOT NULL')
        ->groupBy('r.book')
        ->getQuery()
        ->getResult();

    // Cast, for the reason stated two paragraphs up: on PostgreSQL both
    // columns arrive as strings through PDO, and without this the `array<int,
    // int>` annotation is simply false. A keyed map is a contract rather than
    // a raw projection, so it is narrowed here instead of in the service.
    return array_map(intval(...), array_column($rows, 'total', 'bookId'));
}
```

`IDENTITY(r.book)` returns the foreign key without joining or hydrating the parent.
`array_column`'s index argument gives numeric-string keys back as `int` (PHP array-key
coercion), so only the values needed the cast.

`ServiceEntityRepository::count(array $criteria = [])` exists and is fine for
`count([])`. Anything with a condition worth naming deserves a named method, for the same
reason `findBy()` does.

## Pagination

The repository returns the page and the total; the envelope is the caller's business (see
`symfony-yoandev-http` for the `items` + `meta` shape).

```php
->setFirstResult(($page - 1) * $perPage)
->setMaxResults($perPage)
```

`setMaxResults()` combined with a fetch join on a *collection* limits the joined rows,
not the root entities, so a page silently contains fewer books than asked for.
`Doctrine\ORM\Tools\Pagination\Paginator` fixes it by splitting the work up — use it in
exactly that case, and plain `setMaxResults()` otherwise. Budget **three** queries for
it, not two: with `fetchJoinCollection: true` and a `count($paginator)` for the envelope's
`total`, it runs a `COUNT`, a subquery selecting the page's root ids, and the hydrating
query (`symfony-yoandev-performance`, `references/queries.md`). That matters when you write the
query-count assertion. Rejected: Pagerfanta, a dependency for something two integers
already express.

## Single results and their exceptions

| Method | No row | Several rows |
|---|---|---|
| `getOneOrNullResult()` | `null` | `Doctrine\ORM\NonUniqueResultException` |
| `getSingleResult()` | `Doctrine\ORM\NoResultException` | `NonUniqueResultException` |
| `getSingleScalarResult()` | `NoResultException` | `NonUniqueResultException` |

`getOneOrNullResult()` is almost always the one you want: "not found" is a normal outcome
the caller handles, not an exception. After a `COUNT`, `getSingleScalarResult()` never
throws for emptiness — a count of zero is still a row.

## Raw SQL

The escape hatch, still inside a repository:

```php
$rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params);
```

Legitimate for a recursive CTE, a window function DQL cannot express, or a bulk
`UPDATE … WHERE` over a million rows. Two consequences: the result is plain arrays, so map
it into a Read DTO before it leaves the repository; and a raw `UPDATE` bypasses the unit
of work, so entities in memory are stale — run it before loading, or `clear()` after.
Never interpolate: `fetchAllAssociative()`, `executeQuery()` and `executeStatement()` all
take `$params` and `$types`.

## PHPStan

Level max needs three annotations and gives everything else for free:

```php
/** @extends ServiceEntityRepository<Review> */   // on the class
/** @return list<Review> */                        // on every method returning getResult()
/** @var Collection<int, Review> */                // on every mapped collection property
```

`list<T>` rather than `array<T>`: Doctrine returns sequential keys, so it is more precise
and free. `getResult()` is declared `mixed`, so without the annotation every call site
degrades. When an ignore is the right answer:
`symfony-yoandev-quality`'s own `references/phpstan.md`.

## Transactions

One `flush()` is already one transaction. Reach for
`$em->wrapInTransaction(fn () => …)` only when several flushes must succeed or fail
together — a batch import, a state change plus a ledger write. It commits on return and
rolls back on any exception, which is the point: hand-rolled `beginTransaction()` /
`commit()` pairs leak an open transaction the first time someone adds an early `return`.
(Test isolation used to be the one exception; it no longer is —
`dama/doctrine-test-bundle` owns the transaction there now, see `symfony-yoandev-testing`.)

`wrapInTransaction()` works unchanged under DAMA: DBAL nests it with a savepoint inside
the bundle's outer transaction.
