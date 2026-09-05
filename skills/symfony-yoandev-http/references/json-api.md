# JSON endpoints

Serialisation, the response contract, and the error format. Checked against
`vendor/symfony/http-kernel/`, `vendor/symfony/serializer/` and
`vendor/symfony/error-handler/` on Symfony 8.1. **`vendor/` is the source of truth over
this file.**

## A separate controller, prefixed `api_`

```php
#[Route('/api/books', name: 'api_book_')]
final class ApiBookController extends AbstractController
{
    public function __construct(private readonly ShelfReader $shelf)
    {
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[Serialize]
    public function show(int $id): BookView
    {
        return $this->shelf->book($id);
    }
}
```

Same service as `BookController`, different translation. Keeping them apart is not
duplication: the two differ in error format, statelessness, authentication firewall,
caching and status codes, and each of those differences would otherwise become a
conditional inside a shared action.

## `#[Serialize]`

Symfony 8.1. The action returns data; the attribute serialises it and builds the
response.

```php
use Symfony\Component\HttpKernel\Attribute\Serialize;
use Symfony\Component\HttpFoundation\Response;

#[Serialize(code: Response::HTTP_CREATED, context: ['groups' => ['book:read']])]
public function create(#[MapRequestPayload] AddBookInput $input): BookView
```

The constructor is `(int $code = 200, array $headers = [], array $context = [])`, and the
attribute targets methods only — there is no class-level form, so it goes on every
action.

Two behaviours to know:

- **The format comes from the request's `_format` attribute**, defaulting to `json` —
  not from `Accept`. So it stays JSON unless a route sets `format:`. If you do set
  `format: 'xml'`, the serializer needs an encoder for it or the response is 415.
- **`Content-Type` is derived from that format** unless you pass one in `headers`.

**Below 8.1**, the action returns a `Response` and does the work itself — but do not
reach for `$this->json()` without reading the float trap below:

```php
return new JsonResponse(
    $serializer->serialize($view, 'json', ['groups' => ['book:read']]),
    json: true,
);
```

## Output DTOs and serializer attributes

`src/Dto/Output/`, `final readonly`, built by the service. **Never an entity** — an
entity is a database mapping, so serialising it publishes every column you add later,
drags lazy associations into the response, and makes `Groups` the only thing standing
between a refactor and a leak. The attributes worth knowing live in
`Symfony\Component\Serializer\Attribute` (aliased from the old `Annotation` namespace
since 6.4; the aliases were removed in 8.0, so use `Attribute` everywhere).

```php
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

#[Groups(['book:read'])]
final readonly class BookView
{
    public function __construct(
        public int $id,

        #[Groups(['book:list'])]
        public string $title,

        #[SerializedName('isbn13')]
        public string $isbn,

        #[Context([DateTimeNormalizer::FORMAT_KEY => \DateTimeInterface::ATOM])]
        public ?\DateTimeImmutable $lastReadAt,
    ) {
    }
}
```

| Attribute | Use it for |
|---|---|
| `Groups` | One DTO serving a list view and a detail view. On a class (6.4+) it adds its groups to every property, and property-level ones stack on top — which is what the example above relies on |
| `SerializedName` | The wire name differs from the PHP name — rename here, not in the DTO |
| `Context` | Per-property serialisation options; `normalizationContext` / `denormalizationContext` for direction-specific ones. Repeatable, and scopable with `groups:` |
| `DiscriminatorMap` | A polymorphic payload: `#[DiscriminatorMap('kind', ['paper' => PaperBook::class, 'ebook' => EBook::class])]` on the base class, so denormalisation knows which subclass to build |

If a DTO needs three group sets to serve three endpoints, it is three DTOs.

## The float trap

`json_encode(4.0)` produces `4`. An `averageRating` that a client parsed as a float
yesterday arrives as an integer today, for no reason other than the average landing on a
round number — and strictly typed clients break on it. Measured against this vendor tree:

```
json_encode(['average' => 4.0])                        {"average":4}
new JsonResponse(['average' => 4.0])                   {"average":4}
$this->json($dto)                                      {"average":4}
$serializer->serialize($dto, 'json')                   {"average":4.0}
#[Serialize] on the action                             {"average":4.0}
```

The split has a single cause. `JsonEncode`'s default context is
`['json_encode_options' => JSON_PRESERVE_ZERO_FRACTION]`, so the Serializer keeps the
fraction — but `AbstractController::json()` merges
`['json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS]` over it, and that
constant (15) does not include the flag. `#[Serialize]` passes only the attribute's own
context, so the default survives.

### Fix 1 — keep the flag

Use `#[Serialize]`, which does it for you. If you must build the response by hand, pass
the flag explicitly and keep the escaping flags you were getting for free:

```php
return $this->json($view, context: [
    'json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_PRESERVE_ZERO_FRACTION,
]);
```

### Fix 2 — take the float out of the contract

The stronger fix, because it does not depend on an encoder flag surviving a refactor:
decide the representation in the output DTO. Either a fixed-precision string, or an
integer in a fixed unit.

```php
final readonly class ShelfStatsView
{
    public function __construct(
        public string $averageRating,   // "4.0" — always one decimal
        public int $totalPagesRead,
    ) {
    }

    public static function from(ShelfStats $stats): self   // ShelfStats is a Read DTO
    {
        return new self(number_format($stats->averageRating, 1, '.', ''), $stats->totalPagesRead);
    }
}
```

That rounding *is* a business decision — how many decimals a rating has — and putting it
in the service that builds the output DTO makes it unit-testable without an HTTP request
or a database. It is exactly the Read → Output normalisation step the architecture calls
for.

## List responses: `items` + `meta`

Every list endpoint returns the same envelope. Pagination in the body, not in headers.

```json
{
  "items": [ … ],
  "meta": { "page": 2, "perPage": 20, "total": 137, "pages": 7 }
}
```

```php
final readonly class BookListView
{
    /** @param list<BookView> $items */
    public function __construct(public array $items, public PageMeta $meta)
    {
    }
}

final readonly class PageMeta
{
    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
        public int $pages,
    ) {
    }
}
```

Rejected alternatives: **pagination in HTTP headers** (many clients discard headers by
the time the data reaches application code, and nothing about `X-Total-Count` is
standard); **cursor pagination** (correct for large mutating datasets, unusable for "go
to page 7" — adopt it deliberately per endpoint, not as the default); **Pagerfanta** (a
dependency with its own view layer and vocabulary, in exchange for `count()` plus
arithmetic — `pages` is `(int) ceil($total / $perPage)`).

The `total` comes from a repository count method, never `count($collection)` — see
`symfony-yoandev-doctrine`.

## Errors: RFC 7807 Problem Details

Symfony's `ProblemNormalizer` already builds the body:

```json
{
  "type": "https://symfony.com/errors/validation",
  "title": "Validation Failed",
  "status": 422,
  "detail": "rating: This value should be between 1 and 5.",
  "violations": [ { "propertyPath": "rating", "title": "…" } ]
}
```

It fires automatically when a `ValidationFailedException` reaches the error handler —
which is what `#[MapRequestPayload]` throws — so validation errors are RFC 7807 without
any code.

**But the media type is wrong, and asking for the right one makes it worse.** With
`Accept: application/json` the body is Problem Details and the `Content-Type` is
`application/json`. With `Accept: application/problem+json` the request format resolves
to `problem`, no encoder supports that format, `SerializerErrorRenderer` catches the
resulting `UnsupportedFormatException` and falls back to the **HTML** error renderer — a
client asking correctly for Problem Details gets an HTML page.

Register an encoder for the format. Autoconfiguration tags it; there is nothing else to
wire:

```php
namespace App\Serializer;

use Symfony\Component\Serializer\Encoder\EncoderInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class ProblemJsonEncoder implements EncoderInterface
{
    public const FORMAT = 'problem';

    public function __construct(private readonly JsonEncoder $inner = new JsonEncoder())
    {
    }

    public function supportsEncoding(string $format): bool
    {
        return self::FORMAT === $format;
    }

    public function encode(mixed $data, string $format, array $context = []): string
    {
        return $this->inner->encode($data, JsonEncoder::FORMAT, $context);
    }
}
```

With it in place, `Accept: application/problem+json` returns
`Content-Type: application/problem+json` and the Problem Details body — verified against
this vendor tree. This is the dedicated mechanism; do not write a kernel exception
listener to reshape error responses.

Two things to check once it works. **`APP_DEBUG=0` in production** — in debug mode
`ProblemNormalizer` adds `class` and `trace` to the body and the renderer adds
`X-Debug-Exception` headers. And **`detail` in production is the status text**, not the
exception message, deliberately: exception messages leak. When a client needs a specific
reason, model it as an exception with `#[WithHttpStatus]` and a status it can act on.

## `#[WithHttpStatus]` on business exceptions

```php
namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(409)]
final class BookAlreadyOnShelf extends \RuntimeException
{
}
```

The service throws it; the controller catches nothing. Details from
`vendor/symfony/http-kernel/EventListener/ErrorListener.php`:

- the attribute is **inherited**, so a base `DomainException` with a status covers its
  subclasses;
- it is **ignored** if the exception already implements `HttpExceptionInterface` — an
  explicit `NotFoundHttpException` wins;
- `headers:` is available for the cases that need one (`Retry-After`, `Allow`).

Pair it with `#[WithLogLevel]` when a 4xx should not be logged as an error. Both are why
this standard has no `try/catch` in controllers and no exception listener: the status is
a property of the failure, declared once next to it, rather than repeated at every call
site and forgotten at the newest one.
