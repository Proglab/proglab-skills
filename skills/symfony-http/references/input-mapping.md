# Mapping the request onto typed arguments

Everything the controller reads from the request, and the version each mechanism needs.
Signatures checked against `vendor/symfony/http-kernel/Attribute/` and
`Controller/ArgumentResolver/RequestPayloadValueResolver.php` on Symfony 8.1.
**`vendor/` is the source of truth over this file.**

## The input DTO

`src/Dto/Input/`, `final`, constraints on the properties. Readonly with promoted
constructor arguments when the serializer hydrates it. When a **FormType** hydrates it,
plain public properties with defaults are the path of least resistance, because the Form
component writes property by property rather than calling a constructor of its own — but a
`readonly` promoted DTO does work, through a **form-level `empty_data` callable** that
builds the object from the submitted children. `forms-and-twig.md` owns that recipe and
has it verified; reach for it when you want one DTO shape serving both entry points.

```php
<?php

declare(strict_types=1);

namespace App\Dto\Input;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateReviewInput
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(min: 10, max: 2000)]
        public string $body,

        #[Assert\Range(min: 1, max: 5)]
        public int $rating,
    ) {
    }
}
```

The constraints live here rather than on the entity because **this is the object the
validator actually sees**. The entity is written by a service from an already-valid DTO;
constraints on it would only fire if something called `validate()` on it, which nothing
does.

## `#[MapRequestPayload]`

Reads `$request->request->all()` if there is one, otherwise the raw body, picks the
format from `Content-Type`, denormalises into the argument's type and validates it.

```php
#[Route('/reviews', name: 'api_review_create', methods: ['POST'])]
#[Serialize(code: Response::HTTP_CREATED)]
public function create(#[MapRequestPayload] CreateReviewInput $input): ReviewView
{
    return $this->reviews->create($input);
}
```

Failure modes, in order of how often they bite:

| What happens | Response |
|---|---|
| No `Content-Type` header | 415 — the resolver cannot choose a format |
| `acceptFormat: 'json'` and the client sent a form | 415 |
| Malformed JSON | 400 |
| A field has the wrong type | 422, with a violation per field |
| Validation fails | 422 (`validationFailedStatusCode`) |
| The body is empty and the argument is nullable or has a default | the argument is `null` — **no error at all** |

That last line is the one that produces silent bugs. `mapWhenEmpty: true` (Symfony 8.1)
denormalises `[]` instead of returning null, so the DTO's own defaults apply and the
constraints run:

```php
public function create(
    #[MapRequestPayload(mapWhenEmpty: true)] CreateReviewInput $input,
): ReviewView
```

Before 8.1 there is no `mapWhenEmpty`. Declaring the argument non-nullable with no default
does make an empty body fail rather than resolve to `null` — but **it fails as a 400, not
as the validation-failed status**. Verified in `RequestPayloadValueResolver`: the
empty-body early return is skipped for such an argument, `$data` stays `''`, and
`deserialize('')` throws `NotEncodableValueException`, which the resolver converts to
`BadRequestHttpException` (matching the "Malformed JSON → 400" row in the table above).
That is a defensible answer — an empty body *is* a malformed request — but it is a
different status from the one `mapWhenEmpty: true` produces, so do not document it as 422
in your API contract.

### A list of items

```php
public function importAll(
    #[MapRequestPayload(type: CreateReviewInput::class)] array $inputs,
): Response
```

`array` without `type:` is a configuration error and the resolver says so. Since 8.1 the
same works variadically, which types each element individually:

```php
public function importAll(#[MapRequestPayload] CreateReviewInput ...$inputs): Response
```

Variadic mapping is **not** supported by `#[MapQueryString]` — it throws a
`LogicException` at resolution time.

## `#[MapQueryString]` and `#[MapQueryParameter]`

`#[MapQueryString]` maps the whole query string onto a DTO. Use it for anything with
more than one parameter, because filters and pagination always grow.

```php
final readonly class ShelfQuery
{
    public function __construct(
        #[Assert\Positive]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $perPage = 20,
    ) {
    }
}

#[Route('/books', name: 'api_book_index', methods: ['GET'])]
#[Serialize]
public function index(
    #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
    ShelfQuery $query = new ShelfQuery(),
): BookListView
```

**Both query attributes fail with 404 by default.** For an HTML listing that is arguably
right — `?page=999999` is a page that does not exist. For an API it is wrong: the client
sent a malformed request and needs to be told which parameter. Set 422 explicitly on
`api_` routes; the difference between the two controllers is exactly this kind of thing.

Note the default `= new ShelfQuery()`: without it, a request with no query string at all
resolves to `null`, since the resolver returns early on empty input. Alternatives are
`mapWhenEmpty: true` (8.1) or a nullable argument you then have to null-check.

`#[MapQueryParameter]` handles a single value and needs no DTO. It takes PHP filter
constants rather than validator constraints —
`#[MapQueryParameter(filter: \FILTER_VALIDATE_INT, options: ['min_range' => 1])] int $page = 1`.
It resolves backed enums from 6.4 and `Uid` types from 7.3. Two of these on one action
is fine; four means it should have been a DTO.

## `#[MapRequestHeader]`

Symfony 8.1: `#[MapRequestHeader('X-Signature')] string $signature` alongside a
`#[MapRequestPayload]` argument. Missing or invalid gives 400. Before 8.1,
`$request->headers->get('X-Signature')` plus a null check — the rule (validate at the
edge, where the signature declares it, not deep in a service) does not change, only the
means.

## Uploaded files

`#[MapUploadedFile]` (7.1) maps one file and validates it with real constraints:

```php
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

public function uploadCover(
    Book $book,
    #[MapUploadedFile([
        new Assert\File(maxSize: '2M', extensions: ['jpg', 'png']),
        new Assert\Image(maxWidth: 2000),
    ])]
    UploadedFile $cover,
): Response
```

Violations are reported at the argument's own path, so the client gets `cover: …` rather
than an anonymous message. Declare `array $covers` or a variadic for multiple files, and
`?UploadedFile $cover = null` for an optional one.

Since 8.1 a file can also be a **property of the payload DTO**: on a multipart request
`#[MapRequestPayload]` merges `$request->files` into the data before denormalising, so a
DTO holding both the metadata (`public string $label`) and the file
(`#[Assert\File(maxSize: '5M', extensions: ['csv'])] public UploadedFile $file`) is
mapped and validated in one pass. Below 8.1, split it: `#[MapRequestPayload]` for the
fields, `#[MapUploadedFile]` for the file.

## Dynamic validation groups

`validationGroups` accepts a string, an array of strings, a `GroupSequence`, and — from
8.1 — an `Expression` or a `Closure` evaluated against the controller's other arguments.

```php
use Symfony\Component\ExpressionLanguage\Expression;

public function save(
    Book $book,
    #[MapRequestPayload(
        validationGroups: new Expression('args["book"].isPublished() ? ["strict"] : ["draft"]'),
    )]
    SaveBookInput $input,
): Response
```

Three variables are in scope and only three: `args` (the controller's named arguments,
as an array), `request`, and `this` (the controller instance). Writing `book` bare does
not work — it is `args["book"]`. Requires `symfony/expression-language`.

The `\Closure` form in the signature is **not usable on PHP 8.4**: closures are not
constant expressions, so a closure written inside an attribute is a compile-time fatal
error. It becomes available with PHP 8.5. Until then the `Expression` form is the only
one, with the cost that comes with it — a string PHPStan cannot check.

Nesting is rejected loudly — an array may only contain strings, and a `GroupSequence`
must be the top-level value. Below 8.1, pass a fixed group list and let the service apply
the conditional rule; a rule that depends on another object's state was arguably never an
input constraint.

## `#[MapEntity]`, and why `expr:` is rejected

With auto-increment integer identifiers, `Book $book` on a route with `{id}` resolves on
its own. `#[MapEntity]` is for the other case — resolving by something that is not the
primary key:

```php
#[Route('/books/{isbn}', name: 'show', requirements: ['isbn' => '[\d-]{10,17}'])]
public function show(#[MapEntity(mapping: ['isbn' => 'isbn'])] Book $book): array
```

`mapping` is `['route parameter' => 'entity field']` and runs `findOneBy()`. Not found
and not nullable gives a 404; `message:` customises it.

**`expr:` is not used here.** It works — the resolver evaluates the expression with
`repository` and the request attributes in scope — but it puts a query inside a
controller attribute, as a string:

```php
// Rejected.
#[MapEntity(expr: 'repository.findLatestForShelf(shelf, request.query.get("since"))')]
```

Every reason this standard keeps queries in repositories applies, and worse: the string is
invisible to PHPStan and the IDE, deptrac cannot see the dependency it creates, a typo
surfaces at runtime as a 404 rather than an error, and it cannot be unit tested. When the
lookup is not a plain `findOneBy`, call the repository from a service and let the action
take the raw route parameter.

## `#[UniqueEntity]` on the input DTO

`#[UniqueEntity]` on the entity never fires, because nothing validates the entity — the
DTO is what the validator sees. Put it on the DTO with `entityClass:`:

```php
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[UniqueEntity(
    fields: ['isbn' => 'isbn'],
    entityClass: Book::class,
    errorPath: 'isbn',
    message: 'This book is already on a shelf.',
)]
final readonly class ImportBookInput          // serializer-hydrated: promoted, readonly
{
    public function __construct(
        #[Assert\Isbn]
        public string $isbn,
        // …
    ) {
    }
}
```

`fields` is `['DTO property' => 'entity field']`. Two details that decide whether this
works:

- **`errorPath` is not optional in practice.** The validator defaults it to
  `current($fields)` — the *entity* field name, the value of the pair. When the DTO
  property and the entity field have different names the violation lands on a path the
  form or the API cannot map back, and the message disappears from the response.
- **On updates, add `identifierFieldNames`.** Without it, editing a book and resubmitting
  its own ISBN reports a duplicate against itself. The list must exactly match the
  entity's identifiers or the validator throws a `ConstraintDefinitionException` — with
  the auto-increment integer ids this standard uses, that is `['id']`, and the DTO must
  carry the id — so the update DTO adds `identifierFieldNames: ['id']` and a `public int
  $id` property.

The constraint issues a query per validation and is a usability convenience, not a
guarantee — two concurrent requests both pass it. The database unique index is the
guarantee; see `symfony-doctrine`.

## Version fallbacks

| Feature | Since | Write instead |
|---|---|---|
| `#[MapRequestPayload]`, `#[MapQueryString]`, `#[MapQueryParameter]` | 6.3 | A hand-written `ValueResolver` — not `$request->get()` in the action |
| `validationFailedStatusCode` on payload/query-string | 6.4 | Accept the default and document it |
| `#[MapUploadedFile]`, `type:` for lists | 7.1 | `$request->files->get()` plus an explicit `$validator->validate()` |
| `key:` on `#[MapQueryString]`, `Uid` in `#[MapQueryParameter]` | 7.3 | A dedicated DTO for the sub-key |
| `#[MapRequestHeader]` | 8.1 | `$request->headers->get()` in the action signature area |
| `UploadedFile` inside a payload DTO, variadic payloads, `mapWhenEmpty`, expression validation groups | 8.1 | Split the mapping, or a non-nullable argument with no default |

Gate attributes such as `#[IsGranted]` are evaluated **before** payload mapping (the
resolver subscribes at a lower priority on purpose), so an unauthorised request never
reaches denormalisation. Do not add authorisation checks inside a DTO to compensate.

## Where the file goes next

Receiving the upload is where this skill stops. What happens after — public versus
protected storage, Flysystem, the storage key held by the entity, deletion and orphan
files, and serving the file back through an authorisation check — is `symfony-storage`.

One thing worth knowing here, because it surfaces as an HTTP symptom: a request larger
than PHP's `post_max_size` arrives with **`$_POST` and `$_FILES` both empty**, before
Symfony sees anything. A non-nullable `#[MapUploadedFile]` then produces a bare 422 with
an empty message, which reads like a validation bug and is not one.
