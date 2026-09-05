# DTOs: Input, Read, Output

Three namespaces under `src/Dto/`, because three different things are being carried and
merging them produces a class that is wrong at both ends.

```
src/Dto/Input/     what comes in    — validated, shaped by the HTTP contract
src/Dto/Read/      what a query returns — raw, shaped by the SQL projection
src/Dto/Output/    what goes out    — shaped by the API or template contract
```

## Contents

- [Why not one DTO](#why-not-one-dto)
- [Input DTOs](#input-dtos)
- [Read DTOs](#read-dtos)
- [Output DTOs](#output-dtos)
- [Read → Output is a business rule](#read--output-is-a-business-rule)
- [Mapping with ObjectMapper](#mapping-with-objectmapper)
- [When to skip the Read layer](#when-to-skip-the-read-layer)
- [Symptoms](#symptoms)

## Why not one DTO

A single class used for both directions ends up carrying validation constraints that are
meaningless on output, and nullable properties that only exist because *creation* allows
them to be absent. Then the day the API needs to expose a computed field that is not
accepted on input, someone adds it and marks it `#[Ignore]`, and the class stops
describing anything.

The three namespaces cost three small files and buy contracts that can change
independently. The API's output shape is allowed to be stable while the query behind it
is rewritten; the input shape is allowed to accept a field the output never returns.

Entities are never any of the three. An entity in a JSON response serialises whatever
Doctrine has hydrated — which is why an unrelated `fetch: EAGER` on a relation silently
changes an API payload.

## Input DTOs

`src/Dto/Input/`, `final readonly`, promoted constructor properties, validation
constraints on the properties. Hydrated by `#[MapRequestPayload]` / `#[MapQueryString]`,
or by a FormType's `data_class`. The controller mechanics belong to `symfony-yoandev-http`; what
matters here is that the service receives a typed, already-validated object and never a
`Request`.

```php
<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Entity\Book;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RateBookInput
{
    public function __construct(
        #[Assert\Range(
            min: Book::MIN_RATING,
            max: Book::MAX_RATING,
            notInRangeMessage: 'A rating is between {{ min }} and {{ max }} stars.',
        )]
        public int $rating,
    ) {
    }
}
```

Referencing `Book::MIN_RATING` from the DTO is deliberate: the bound is a *value*, it
lives once on the entity, and both the DTO's constraint and the service's guard read it.
The DTO's constraint gives the user a 422 with a message; the service's guard is the one
that actually holds, because the DTO is only one of the ways the service can be called.

`#[UniqueEntity]` also goes here, with `entityClass:` — on the entity it never fires,
because the entity is not what gets validated. Details in `symfony-yoandev-http`.

## Read DTOs

`src/Dto/Read/`, populated by a `SELECT NEW` projection in a repository. Its shape is
dictated by what the query can produce, not by what the API wants: raw scalars,
`\DateTimeImmutable`, database-shaped nulls, `float` averages with fifteen decimals.

```php
<?php

declare(strict_types=1);

namespace App\Dto\Read;

final readonly class ShelfStatsRow
{
    public function __construct(
        public int $bookId,
        public string $title,
        // Aggregates have no Doctrine type to convert through, so the driver decides:
        // SQLite gives int/float, PostgreSQL commonly hands bigint/numeric back as
        // strings through PDO. Declare the union and let the service cast — that is
        // what "raw values" means. See symfony-yoandev-doctrine.
        public float|string|null $averageRating,   // NULL when nobody has rated it
        public int|string $reviewCount,
        public ?\DateTimeImmutable $lastReadAt,
    ) {
    }
}
```

The point of the layer is that a projection is cheap and an entity graph is not: one
query returning exactly these five columns replaces a `findAll()` plus a lazy-loaded
collection per row. Writing the query is `symfony-yoandev-doctrine`'s subject; the constructor
signature above is the contract between the two skills.

Read DTOs carry no constraints — nothing validates a row that came out of the database.

## Output DTOs

`src/Dto/Output/`, `final readonly`, shaped by the contract the consumer sees. This is
what a controller serialises or hands to a template.

```php
<?php

declare(strict_types=1);

namespace App\Dto\Output;

use App\Enum\ReadingStatus;

final readonly class BookView
{
    public function __construct(
        public int $id,
        public string $title,
        public string $author,
        public ReadingStatus $status,
        public ?int $rating,
        public ?string $lastReadAt,        // ISO date, or null
    ) {
    }
}
```

Once this class exists, changing the query, adding a column or renaming an entity
property stops being an API change. That is the whole return on the file.

## Read → Output is a business rule

The translation from a Read DTO to an Output DTO is never mechanical, and pretending it
is puts decisions in the wrong place. Rounding an average, formatting a date, and
deciding whether "no ratings yet" is `null` or `0` are product decisions with visible
consequences — a `0` reads as "rated zero stars" in every UI that does not special-case
it.

So the normalisation lives in the service, in a private method, and it is unit-testable
without a database or a kernel:

```php
final readonly class ShelfReader
{
    public function __construct(private BookRepository $books)
    {
    }

    /** @return list<ShelfStatsView> */
    public function stats(): array
    {
        return array_map($this->toView(...), $this->books->findShelfStats());
    }

    private function toView(ShelfStatsRow $row): ShelfStatsView
    {
        return new ShelfStatsView(
            id: $row->bookId,
            title: $row->title,
            // null, not 0.0: "never rated" and "rated zero" are different facts
            averageRating: null === $row->averageRating ? null : round((float) $row->averageRating, 1),
            reviewCount: (int) $row->reviewCount,
            lastReadAt: $row->lastReadAt?->format('Y-m-d'),
        );
    }
}
```

That test is three lines, needs no fixtures, and is the one that fails when someone
"simplifies" the rounding. Compare with the same logic written in Twig, where it is
untestable and duplicated in the JSON controller.

## Mapping with ObjectMapper

`symfony/object-mapper` removes the hand-written entity → Output DTO copy. Declare the
source on the Output DTO and inject `ObjectMapperInterface`:

```php
use App\Entity\Book;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Book::class)]
final readonly class BookView
{
    public function __construct(
        public int $id,
        public string $title,

        #[Map(source: 'lastReadAt', transform: [self::class, 'toIsoDate'])]
        public ?string $lastReadAt,
    ) {
    }

    public static function toIsoDate(?\DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d');
    }
}
```

```php
$view = $this->mapper->map($book, BookView::class);
```

Facts worth knowing, all checked against the component:

- **It reads private properties through their getters.** FrameworkBundle wires
  `property_accessor` into the `object_mapper` service, so a maker-format entity maps
  without being opened up. Without the PropertyAccessor the component falls back to
  direct property reads and only public properties work.
- **`transform:` takes a callable or a service id**, and receives
  `(mixed $value, object $source, ?object $target)`. A service implementing
  `TransformCallableInterface` is the version to use when the transformation needs a
  dependency; a `static` method on the DTO is enough for formatting.
- **`Transform\MapCollection`** maps an iterable property item by item, with an optional
  `targetClass`.
- **Availability:** the component is Symfony 7.3 (experimental) and stable from 7.4.
  On 6.4 it does not exist — write a `public static function fromEntity(Book $book):
  self` on the Output DTO, or a small mapper service. The rule does not change, only the
  means: the controller still never sees an entity.

Keep the transforms to formatting. A `transform` that decides `null` versus `0`, or that
rounds a score the product cares about, has moved a business rule into an attribute where
no unit test will look for it — that belongs in the service method above.

## When to skip the Read layer

If the projection already matches the API contract, project straight into the Output DTO
and delete the intermediate class. Three near-identical DTOs stacked for symmetry is
ceremony, and ceremony is how a standard loses the argument.

The layer earns its place when at least one of these is true: the query returns values
the contract does not expose as-is (raw averages, database nulls, epoch timestamps); the
same projection feeds two different outputs; or the normalisation has enough decisions in
it to deserve a test of its own.

## Symptoms

| Symptom | Cause | Fix |
|---|---|---|
| An API field changed shape after a Doctrine mapping change | An entity is being serialised | Introduce the Output DTO |
| `#[Ignore]` or `#[Groups]` piling up on one class | One DTO used in both directions | Split Input and Output |
| `null` shown as `0` in the UI | Normalisation happened in Twig, or in a `transform` | Move it into the service, with a test |
| `MappingException: Mapping target not found` | `map()` called with no target and no `#[Map]` on the source | Pass the target class explicitly |
| Only some properties mapped | Names differ between source and target | `#[Map(source: '…')]` on the target property |
| A Read DTO with validation constraints | The layer was confused with Input | Constraints belong on Input only |
