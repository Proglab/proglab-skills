# Entities and relations

Mapping that survives a schema change, and the relation traps that only appear under
load. Verified against Doctrine ORM 3.6 / DBAL 4.4.

## Contents

- [The shape](#the-shape)
- [Columns](#columns)
- [Enums](#enums)
- [Dates](#dates)
- [ManyToOne: the owning side](#manytoone-the-owning-side)
- [OneToMany: the inverse side](#onetomany-the-inverse-side)
- [cascade and orphanRemoval](#cascade-and-orphanremoval)
- [The collection count trap, in detail](#the-collection-count-trap-in-detail)
- [ManyToMany](#manytomany)
- [Attribute reference](#attribute-reference)

## The shape

`make:entity` generates it and it is the shape to keep: private properties, a getter and
a setter per field, `#[ORM\Entity(repositoryClass: …)]` on the class, one mapping
attribute per property. No constructor arguments for mapped fields, no promoted
properties, no domain methods.

Constructor promotion with mapping attributes on the promoted parameters does work in
ORM 3, and it looks tidier. It is not used here for one reason: the maker's
`ClassSourceManipulator` has no notion of promoted properties, so `make:entity` cannot
update a class written that way — every subsequent field is added by hand and the
generator stops being usable on the project. That is a real cost paid for cosmetics.

An entity may hold constants (`Book::MAX_RATING`) — a constant is not an invariant, it is
a value the validator and the service both read.

## Columns

```php
#[ORM\Column(length: 255)]                        // string, VARCHAR(255)
#[ORM\Column(type: Types::TEXT)]                  // string, unbounded
#[ORM\Column]                                     // inferred from the PHP type
#[ORM\Column(nullable: true)]                     // NULL allowed
#[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]   // string in PHP
#[ORM\Column(type: Types::JSON)]                  // array
```

Doctrine infers the type from the PHP property type when `type:` is omitted, which is why
most maker-generated columns carry only `length:` or `nullable:`. Leave it inferred: the
property type is then the single source of truth, and `doctrine:schema:validate
--skip-sync` will tell you when the two disagree.

`Types::DECIMAL` hydrates to a **string**, not a float — deliberately, because a float
cannot represent money. Type the property `string`, and do the arithmetic in a service
with `bcmath` or integer cents.

`unique: true` on `#[ORM\Column]` creates the database constraint. It does not produce a
usable error message; that is what `#[UniqueEntity]` on the input DTO is for, and it
belongs to `symfony-yoandev-http`. Keep both: the constraint is the guarantee, the validator is
the message.

## Enums

```php
enum ReadingStatus: string
{
    case ToRead = 'to_read';
    case InProgress = 'in_progress';
    case Read = 'read';
}

#[ORM\Column(enumType: ReadingStatus::class)]
private ReadingStatus $status;
```

Always a **backed** enum, and back it with strings rather than integers. The stored value
is what a DBA reads in `psql` and what an export contains; `'in_progress'` explains
itself, `2` does not, and reordering cases silently reinterprets every existing row.

The `enumType` conversion applies everywhere the column is selected — including inside a
`SELECT NEW` projection, where the DTO constructor receives the enum instance, not the
string. Type the Read DTO property with the enum.

Adding a case is free. Removing one is a data migration: existing rows still hold the old
value, and hydrating them throws `Doctrine\ORM\Mapping\MappingException` —
`invalidEnumValue`, raised when `ReadingStatus::from()` fails. Migrate the rows in the
same deploy that removes the case, not later.

## Dates

`\DateTimeImmutable` everywhere, with `Types::DATE_IMMUTABLE`,
`Types::DATETIME_IMMUTABLE` or `Types::DATETIMETZ_IMMUTABLE`.

The mutable types are still there and still work, which is the problem: `Types::DATETIME`
hands the caller a `\DateTime` the caller can mutate, and the unit of work sees the
change and writes it at the next flush. Nothing in the stack reports it.

`datetime_immutable` stores no timezone. Store UTC and convert for display, or use
`datetimetz_immutable` and accept that its support varies by platform — PostgreSQL keeps
the offset, MySQL does not.

## ManyToOne: the owning side

```php
#[ORM\ManyToOne(inversedBy: 'reviews')]
#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
private ?Book $book = null;
```

The side holding the foreign key is the **owning** side, and it is the only side Doctrine
looks at when deciding what to write. Setting `$review->setBook($book)` persists the
link; adding to `$book->getReviews()` alone does not. This is the single most common
"my relation is not saved" cause, and it is why the maker generates `addReview()` on the
inverse side with the back-reference already set inside it — keep that code.

**`ManyToOne` has no `nullable` argument.** Its constructor takes only `targetEntity`,
`cascade`, `fetch` and `inversedBy`. Nullability is a property of the join column:

```php
#[ORM\JoinColumn(nullable: false)]
```

Written on the `ManyToOne` it is a fatal `unknown named parameter`; written nowhere, the
column defaults to nullable and the schema quietly allows orphan rows.

`onDelete:` on the join column is a **database-level** cascade: the rows disappear
without Doctrine seeing them, so no lifecycle callback runs and the identity map still
holds the deleted objects for the rest of the request. It is fast and it is the right
tool for high-volume child rows; it is the wrong tool when something must happen on
delete.

## OneToMany: the inverse side

```php
/** @var Collection<int, Review> */
#[ORM\OneToMany(targetEntity: Review::class, mappedBy: 'book')]
private Collection $reviews;

public function __construct()
{
    $this->reviews = new ArrayCollection();
}
```

`mappedBy` names the property on the owning side. The `@var Collection<int, Review>`
annotation is not optional at PHPStan level max — without it every element is `mixed`.

Map the inverse side only when something reads it. A `OneToMany` that exists "for
symmetry" is a collection Doctrine tracks, a lazy proxy it instantiates, and a hydration
someone will trigger by accident.

`targetEntity:` can be omitted when the property type says it — but only for
`ManyToOne`/`OneToOne`, where there is a typed property to read. A `Collection` says
nothing about what it contains, so `OneToMany` and `ManyToMany` always need it.

## cascade and orphanRemoval

| Setting | What it means |
|---|---|
| `cascade: ['persist']` | Persisting the parent persists new children. Useful when children are created with the parent and never alone |
| `cascade: ['remove']` | Removing the parent removes the children **one entity at a time**, in PHP |
| `orphanRemoval: true` | A child removed from the collection is deleted, not just detached |
| `#[ORM\JoinColumn(onDelete: 'CASCADE')]` | The database deletes the children. One statement, no PHP |

`cascade: ['remove']` and `onDelete: 'CASCADE'` solve the same problem at different
layers. Use the database cascade for volume; use the Doctrine cascade when a listener,
a file deletion or an audit entry has to run per child. Setting both is not an error, but
the database wins and the PHP cascade becomes dead weight.

`orphanRemoval` is the one people expect by default and do not get:

```php
$book->removeReview($review);
$em->flush();
```

Without `orphanRemoval: true` this sets `review.book_id` to NULL — or throws a foreign
key violation if the join column is `nullable: false`. With it, the review row is
deleted. Set it when the child cannot exist without the parent, which is the same
condition that makes `#[ORM\JoinColumn(nullable: false)]` correct.

Do not use `cascade: ['all']`. It includes `detach` and `refresh`, which almost nobody
means, and it turns a reviewed decision into a shrug.

## The collection count trap, in detail

`Doctrine\ORM\PersistentCollection::count()` — verified in ORM 3.6 — returns the
persister's count **only** when the association is mapped `fetch: 'EXTRA_LAZY'`.
Otherwise it falls through to `AbstractLazyCollection::count()`, which calls
`initialize()` and hydrates every row.

So all four of these are the same query:

```php
count($book->getReviews())
$book->getReviews()->count()
$book->getReviews()->isEmpty()
{{ book.reviews|length }}
```

In a list template that runs once per row. The fix is a repository method returning the
counts for the whole page in one grouped query, keyed by book id — see
`references/repositories-and-queries.md`.

`->isEmpty()` is not cheaper — it is implemented on top of `count()`. The cheap
alternatives are an `EXISTS` in a repository method, or the count you already fetched.

**`fetch: 'EXTRA_LAZY'`** also makes `contains()`, `containsKey()`, `get()`, `first()`
and `slice()` hit the database directly instead of loading everything. It is the pragmatic retrofit for a
codebase where these calls are spread through templates. The reason it is not the default
here: it makes an O(n) call in a Twig loop invisible, so nobody ever removes it.

## ManyToMany

```php
#[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'books')]
private Collection $tags;
```

The side with `inversedBy` owns the join table and is the only side that writes. As soon
as the association needs an attribute of its own — a position, a date, a note — it stops
being a `ManyToMany` and becomes an entity with two `ManyToOne`s. Discovering this after
the join table has data is a migration; deciding it up front is a five-minute
conversation.

## Attribute reference

Signatures as they exist in ORM 3.6. Anything not listed here is not a valid argument.

| Attribute | Arguments |
|---|---|
| `#[ORM\Entity]` | `repositoryClass`, `readOnly` |
| `#[ORM\Column]` | `name`, `type`, `length`, `precision`, `scale`, `unique`, `nullable`, `insertable`, `updatable`, `enumType`, `options`, `columnDefinition`, `generated`, `index` |
| `#[ORM\GeneratedValue]` | `strategy` (`AUTO`, `SEQUENCE`, `IDENTITY`, `NONE`, `CUSTOM`) |
| `#[ORM\ManyToOne]` | `targetEntity`, `cascade`, `fetch`, `inversedBy` — **no `nullable`** |
| `#[ORM\OneToMany]` | `targetEntity`, `mappedBy`, `cascade`, `fetch`, `orphanRemoval`, `indexBy` |
| `#[ORM\JoinColumn]` | `name`, `referencedColumnName`, `deferrable`, `unique`, `nullable`, `onDelete`, `columnDefinition`, `fieldName`, `options` |
| `#[ORM\Index]` | `name`, `columns`, `fields`, `flags`, `options` — class-level, repeatable |
| `#[ORM\OrderBy]` | `value` (an array: `['publishedAt' => 'DESC']`) |

`fetch` accepts `'LAZY'`, `'EAGER'` or `'EXTRA_LAZY'`. `fetch: 'EAGER'` on a `ManyToOne`
loads the related entity with every single query touching this one, including queries
that never read it — prefer a `JOIN` in the repository method that actually needs it.
