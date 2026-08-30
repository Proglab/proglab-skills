# PHPStan at level max on Symfony

The errors you will actually hit, and how to fix them rather than silence them.

## Contents

- [Before anything: warm the container](#before-anything-warm-the-container)
- [Doctrine repositories and generics](#doctrine-repositories-and-generics)
- [Entities that PHPStan thinks are null](#entities-that-phpstan-thinks-are-null)
- [`getResult()` returns mixed](#getresult-returns-mixed)
- [Services fetched from the container in tests](#services-fetched-from-the-container-in-tests)
- [Ignoring an error properly](#ignoring-an-error-properly)

## Before anything: warm the container

```bash
php bin/console cache:warmup --env=dev
```

`phpstan-symfony` reads the compiled container XML to know which service ids exist,
which routes are defined, and what `$this->getUser()` actually returns. Without it the
extension does not error — it degrades. Rules that should have caught a typo in a
service id quietly pass.

If `containerXmlPath` points at a file that does not exist, PHPStan says nothing.
Check the real filename: it embeds the kernel class name, which embeds the project
namespace.

```bash
ls var/cache/dev/*KernelDevDebugContainer.xml
```

## Doctrine repositories and generics

`ServiceEntityRepository` is generic. Without the annotation, every `find()` returns
`object` and level max complains at every call site.

```php
/**
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

Annotate custom methods too. `getResult()` has no idea what it returned:

```php
/**
 * @return list<Review>
 */
public function findLatestPublished(int $limit = 20): array
```

Use `list<T>` rather than `array<T>` when the keys are sequential — which they are,
coming out of Doctrine. It is more precise and it costs nothing.

## Entities that PHPStan thinks are null

The maker-generated shape declares `private ?int $id = null;`, so PHPStan is right
that `$book->getId()` may be null — it genuinely is, before the first flush.

Do not sprinkle `assert()` at call sites. Two honest fixes:

- **Take the entity, not the id**, in the service signature. Passing `Book $book`
  instead of `int $bookId` removes the question entirely, and it is better design.
- **Accept the nullable type** in the DTO and let the mapping deal with it. An output
  DTO built from a persisted entity can declare `public int $id` and be constructed
  after the flush, where the id is guaranteed.

If neither applies, that is the case for an explicit ignore with a reason.

## `getResult()` returns mixed

```php
// PHPStan: cannot call method getTitle() on mixed
$books = $qb->getQuery()->getResult();
```

Annotate the method's return type as above. For a scalar aggregate, cast at the
boundary and say what you expect:

```php
public function countPublishedForBook(int $bookId): int
{
    return (int) $this->createQueryBuilder('r')
        ->select('COUNT(r.id)')
        // ...
        ->getQuery()
        ->getSingleScalarResult();
}
```

The cast is not noise: depending on the driver, `COUNT` comes back as a `string`. The
DTO carrying that value would otherwise get a string where it declared an int, and
`declare(strict_types=1)` would turn that into a runtime error in production rather
than a PHPStan error here.

## Services fetched from the container in tests

```php
// PHPStan: cannot call method findLatest() on object
$repository = self::getContainer()->get(ReviewRepository::class);
```

`get()` is declared as returning `object`. Assign through a typed property, which is
also better test code:

```php
private ReviewRepository $repository;

protected function setUp(): void
{
    self::bootKernel();

    $repository = self::getContainer()->get(ReviewRepository::class);
    \assert($repository instanceof ReviewRepository);
    $this->repository = $repository;
}
```

## Ignoring an error properly

Sometimes the tool is wrong. Generics and Doctrine's dynamic returns are where it
happens most.

```php
// Doctrine's findOneBy() is typed as returning the entity or null, but this
// query is on a unique index and the caller has already checked existence.
/** @phpstan-ignore-next-line */
```

Two rules:

- **Always with a reason on the line above.** If the reason is not worth writing, the
  ignore is not right. This is the single check that stops ignores from spreading.
- **Never in `phpstan.dist.neon` as a global `ignoreErrors` pattern** unless the error
  is genuinely systemic. A pattern silences the error everywhere, including where it
  was about to catch a real bug.

And the one that matters most: **never add to the baseline to silence a new error.**
The baseline exists to freeze debt that predates the standard. Growing it is adding
debt on purpose, which deserves a conversation rather than a commit.
