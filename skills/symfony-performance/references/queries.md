# N+1, joins, projections and large result sets

Verified against **doctrine/orm 3.6.8 / doctrine/dbal 4.4.4 / Symfony 8.1.5 / PHP 8.4**.
All DQL and QueryBuilder code below belongs in a repository — that is the layer contract,
and `deptrac` enforces it once adopted (`symfony-quality`).

## Recognising an N+1

The signature is a burst of near-identical queries differing only by a parameter:

```
SELECT ... FROM review WHERE book_id = 1
SELECT ... FROM review WHERE book_id = 2
SELECT ... FROM review WHERE book_id = 3
...
```

Nobody wrote those. They come from a `PersistentCollection` being touched — a `foreach`,
a `count()`, an `isEmpty()`, a Twig `|length`. Because they are invisible at the call
site, the profiler's Doctrine panel is the only honest way to see them: it prints the
backtrace for each query when `profiling_collect_backtrace` is on, which points at the
exact template line.

`symfony-doctrine` allows a **bidirectional relation** where the inverse side is
genuinely read, and keeps it unidirectional otherwise. Where one exists it is a
deliberate trade: navigation reads well (`$book->getReviews()`), and the price is that
lazy loading is one dot away. The mitigation is not "avoid bidirectional relations", it
is the mechanical query-count test in `references/query-counting.md`.

## Fix 1 — the fetch join

```php
/**
 * @return list<Book>
 */
public function findShelfWithReviews(): array
{
    return $this->createQueryBuilder('b')
        ->leftJoin('b.reviews', 'r')
        ->addSelect('r')                 // ← without this it is not a *fetch* join
        ->orderBy('b.title', 'ASC')
        ->getQuery()
        ->getResult();
}
```

`->addSelect('r')` is the whole point. A `leftJoin` without it filters and orders but
hydrates nothing, so the collection is still lazy and the N+1 survives — a join that
looks like a fix and is not. That is worth checking first when a fetch join "did not
work".

`leftJoin`, not `innerJoin`, unless a book with no review should genuinely disappear
from the list. An `innerJoin` silently shortens list pages, and the bug is reported as
"missing books", not as a join problem.

Conditional joins use `WITH`, never a `WHERE` on the joined alias:

```php
->leftJoin('b.reviews', 'r', Join::WITH, 'r.publishedAt IS NOT NULL')
```

A `WHERE r.publishedAt IS NOT NULL` on a left join converts it back into an inner join,
because rows where `r` is `NULL` fail the condition.

## Fix 2 — count in the repository, never on the collection

```php
public function countReviewsFor(Book $book): int
{
    return (int) $this->getEntityManager()
        ->createQuery('SELECT COUNT(r.id) FROM App\Entity\Review r WHERE r.book = :book')
        ->setParameter('book', $book)
        ->getSingleScalarResult();
}
```

Why this rather than `count($book->getReviews())`, verified in
`vendor/doctrine/orm/src/PersistentCollection.php`:

```php
public function count(): int
{
    if (! $this->initialized && $this->association !== null
        && $this->getMapping()->fetch === ClassMetadata::FETCH_EXTRA_LAZY) {
        return $persister->count($this) + ...;   // one COUNT query
    }

    return parent::count();                      // initialises the ENTIRE collection
}
```

Outside `EXTRA_LAZY`, `count()` hydrates every row to return an integer. On a book with
4 000 reviews that is 4 000 entity objects, all of them then tracked by the UnitOfWork
for the rest of the request. `isEmpty()` is the same trap — it calls `count()`.

`EXTRA_LAZY` (`#[ORM\OneToMany(fetch: 'EXTRA_LAZY')]`) makes exactly six methods issue a
targeted query instead of hydrating — `count()`, `contains()`, `containsKey()`, `get()`,
`first()` and `slice()` — and does solve the problem. (`isEmpty()` is not in that set, but
it delegates to `count()`, so it inherits the fix.) It is rejected here
because it puts a SQL query behind a `count()` call in a Twig template, where no reader,
no reviewer and no static analyser will find it, and because it violates the rule that
all queries live in repositories. Use it only on a legacy codebase where extracting the
repository method is not realistic — and say so in a comment.

## Fix 3 — do not hydrate what you will not use

A list showing four columns should not build entities. Project into a read DTO:

```php
// src/Dto/Read/ShelfRow.php
final readonly class ShelfRow
{
    public function __construct(
        public int $id,
        public string $title,
        public ReadingStatus $status,
        public ?\DateTimeImmutable $lastReadAt,
    ) {
    }
}
```

```php
// src/Repository/BookRepository.php
/**
 * @return list<ShelfRow>
 */
public function findShelfRows(): array
{
    return $this->createQueryBuilder('b')
        ->select(\sprintf('NEW %s(b.id, b.title, b.status, b.lastReadAt)', ShelfRow::class))
        ->orderBy('b.title', 'ASC')
        ->getQuery()
        ->getResult();
}
```

Verified behaviour of `SELECT NEW`:

- DBAL type conversion runs, so a column mapped `enumType: ReadingStatus::class` arrives
  as the enum case and a `date_immutable` column as a `\DateTimeImmutable`. You do not
  parse strings by hand.
- The identity map stays **empty** — nothing is managed, nothing is dirty-checked, and
  the objects are garbage-collected normally.
- Promoted constructor properties on a `final readonly` class work; arguments are
  positional and must match the constructor order exactly.

Constraints to know before reaching for it: only scalar fields and other `NEW`
expressions may be passed (no associations, no collections), and the resulting objects
cannot be persisted — which is the point. `src/Dto/Read/` is where they live; the service
normalises Read → Output. When the projection already matches the API contract, project
straight into the Output DTO and skip a layer that would only copy fields.

## Pagination with a fetch join

Combining `->addSelect('r')` with `setMaxResults(20)` returns fewer than 20 books,
because the LIMIT applies to the joined row set — one book with three reviews consumes
three rows. Doctrine's own paginator handles it:

```php
use Doctrine\ORM\Tools\Pagination\Paginator;

$query = $this->createQueryBuilder('b')
    ->leftJoin('b.reviews', 'r')->addSelect('r')
    ->orderBy('b.title', 'ASC')
    ->setFirstResult(($page - 1) * $perPage)
    ->setMaxResults($perPage)
    ->getQuery();

$paginator = new Paginator($query, fetchJoinCollection: true);

return new ShelfPage(
    items: iterator_to_array($paginator),
    total: count($paginator),
);
```

Budget it in your query-count assertion: with `fetchJoinCollection: true` a paginated
fetch join is **three** queries — a `COUNT`, a subquery selecting the page's root ids,
and the hydrating query restricted to those ids. That is the correct number, and it does
not grow with the page size. This is Doctrine's own class, not a bundle; the charter's
rejection of Pagerfanta does not apply to it.

## Large result sets

`getResult()` builds the whole array in memory. For an export or a batch job, iterate:

```php
public function streamAllForExport(): iterable
{
    return $this->createQueryBuilder('b')
        ->orderBy('b.id', 'ASC')
        ->getQuery()
        ->toIterable();
}
```

`toIterable()` hydrates one row at a time from an open DBAL result. Three rules come
with it:

- **Clear periodically.** Hydrated entities accumulate in the UnitOfWork, so memory
  still grows without `$em->clear()` every N rows.
- **`clear()` detaches everything.** Any entity you were holding across the loop becomes
  stale — reload by id after clearing, do not reuse the old reference.
- **Do not run other queries on the same connection inside the loop** unless the driver
  supports it; buffer the ids and handle them after.

```php
$i = 0;
foreach ($this->books->streamAllForExport() as $book) {
    $writer->write($this->mapper->map($book, BookRow::class));

    if (0 === ++$i % 500) {
        $this->em->clear();
    }
}
```

Better still, when the export only needs a few columns: `toIterable()` on a `SELECT NEW`
projection. Nothing is managed, so there is nothing to clear and the loop stays flat.

## Choosing between the fixes

| Situation | Fix |
|---|---|
| A list template reads a relation of every row | Fetch join in the repository method the list uses |
| A template displays "N reviews" | Repository `count…For()` method |
| A list shows a handful of columns | `SELECT NEW` into a read DTO — no join needed for scalars |
| A detail page needs one book plus its full relations | Fetch join, `getSingleResult()` |
| An export or batch over the whole table | `toIterable()` + `clear()`, ideally on a projection |
| A relation is needed only sometimes | A second repository method. Two honest queries beat one query with a boolean argument |

## What not to do

- **Do not set `fetch: 'EAGER'` on the mapping** to fix one page. It applies everywhere,
  including the pages that did not want the join, and it turns one visible problem into a
  diffuse one.
- **Do not cache the page to hide the N+1.** The queries still run on every cache miss,
  and the miss is exactly when the system is under load.
- **Do not add an index before reading the plan.** N+1 is a count problem, not a duration
  problem; indexing 60 fast queries leaves 60 round trips.
