# Dependency injection and the patterns built on it

Constructor injection with autowiring covers most of an application. This file is about
the cases it cannot resolve on its own, and the three patterns that are worth reaching
for when it cannot.

Every attribute below was checked against a Symfony 8.1 install, and the behaviours are
what a compiled container actually produced — not what a blog post says. When the skill
and `vendor/` disagree, `vendor/` is right.

## Contents

- [The attribute catalogue](#the-attribute-catalogue)
- [Injecting configuration](#injecting-configuration)
- [Choosing between several implementations](#choosing-between-several-implementations)
- [Strategy](#strategy)
- [Decorator](#decorator)
- [Factory](#factory)
- [Attributes deliberately not used](#attributes-deliberately-not-used)
- [Symptoms](#symptoms)

## The attribute catalogue

All of these are `Symfony\Component\DependencyInjection\Attribute\`.

| Attribute | Does | Since |
|---|---|---|
| `#[Autowire]` | Inject a value, service, expression, env var or parameter | 6.1 |
| `#[AutowireIterator]` | Inject all services carrying a tag, as `iterable` | 6.4 |
| `#[AutowireLocator]` | Inject them as a lazy `ServiceProviderInterface` (a PSR-11 container that also lists its keys) | 6.4 |
| `#[AsTaggedItem]` | Set this service's key and priority inside those collections | 5.3 |
| `#[AutoconfigureTag]` | Tag every implementation — put it **on the interface** | 5.3 |
| `#[AsDecorator]` | Wrap another service, keeping its id | 6.1 |
| `#[AutowireDecorated]` | Inject the wrapped service into the decorator | 6.3 |
| `#[Target]` | Pick one named autowiring alias among several candidates | 5.3 |
| `#[AsAlias]` | Register the class under an interface id, or under a `target:` name | 6.3 (`target:` 8.1) |
| `#[When]` / `#[WhenNot]` | Register the class only in (or outside) one environment | 5.3 / 7.2 |
| `#[Exclude]` | Never register this class as a service — class-level only | 6.3 |
| `#[Lazy]` | Instantiate on first use | 7.1 |

Two removals to know about, because both still appear in older code and documentation:
`#[TaggedIterator]` and `#[TaggedLocator]` were **removed in 8.0** in favour of
`#[AutowireIterator]` / `#[AutowireLocator]`, and `#[MapDecorated]` was removed in 7.0 in
favour of `#[AutowireDecorated]`.

Symfony 8.1 also deprecates `defaultIndexMethod` / `defaultPriorityMethod` on tagged
collections: use `#[AsTaggedItem]` instead. Do not write the static methods.

## Injecting configuration

```php
public function __construct(
    #[Autowire(param: 'app.shelf_page_size')] private int $pageSize,
    #[Autowire(env: 'MAILER_DSN')] private string $mailerDsn,
    #[Autowire(env: 'bool:FEATURE_IMPORT')] private bool $importEnabled,
    #[Autowire(service: 'monolog.logger.import')] private LoggerInterface $logger,
) {
}
```

`#[Autowire]` accepts **exactly one** of `value`, `service`, `expression`, `env` or
`param`; two of them throws a `LogicException` at compile time, which is the good
outcome. A parameter is resolved at compile time and baked into the container — a `param`
that does not exist fails the build. An env var stays as `%env(...)%` and is resolved at
runtime, so a missing one fails on the first request instead.

Env var processors (`bool:`, `int:`, `json:`, `default:`, `resolve:`) work inside
`#[Autowire(env: ...)]`. Use them rather than casting in the constructor body — a
`readonly` promoted property has no constructor body to cast in.

## Choosing between several implementations

When one interface has several implementations, autowiring has no way to pick and fails
the build with a message listing the candidates. Two answers.

**`#[Target]` with a named alias** when a specific consumer wants a specific one:

```php
// src/Service/Export/JsonShelfExporter.php
#[AsAlias(ShelfExporter::class, target: 'json shelf exporter')]   // Symfony 8.1+
final readonly class JsonShelfExporter implements ShelfExporter { /* … */ }

// the consumer
public function __construct(
    #[Target('json shelf exporter')] private ShelfExporter $exporter,
) {
}
```

Before 8.1, declare the alias in `config/services.yaml` and keep the attribute on the
consumer — the name is the camel-cased argument name:

```yaml
services:
    App\Service\Export\ShelfExporter $jsonShelfExporter: '@App\Service\Export\JsonShelfExporter'
```

**When a mistyped `#[Target]` is caught, and when it is not.** Measured on 8.1, because
the two cases read as a contradiction until you know which one you are in:

- **Normally it fails the build, loudly.** `AutowirePass` throws
  `Cannot autowire service "…": argument "$e" of method "__construct()" has
  "#[Target('nope')]" but no such target exists.`, usually followed by *"Did you mean to
  target …"* listing the aliases that do exist. `lint:container` fails. This covers every
  ordinary service, Monolog channel loggers included.
- **It goes quiet when the consumer is reachable only through a lazy locator** — a
  controller's argument locator, an `#[AutowireLocator]` collection, a service subscriber.
  Those references are `RUNTIME_EXCEPTION_ON_INVALID_REFERENCE`, so
  `DefinitionErrorExceptionPass` leaves the error for runtime: the container compiles, and
  the `container.error` throws on first instantiation.

So a mistyped target is normally a build failure; if it ever survives `lint:container`,
the consumer is behind a locator. The genuinely silent sibling is
`#[WithMonologChannel('typo')]`, which *creates* the channel rather than failing
(`symfony-observability`), and `#[Autowire(service: '…')]` typed to an interface the
service does not implement, which compiles and throws a `TypeError` on first use.

**A tagged collection** when the consumer wants all of them and picks at runtime — that is
Strategy, below.

## Strategy

One interface, several implementations, and a runtime value that decides which one. The
interface carries the tag, so a new implementation needs no registration anywhere:

```php
#[AutoconfigureTag('app.shelf_exporter')]
interface ShelfExporter
{
    public function export(Shelf $shelf): string;
}

#[AsTaggedItem(index: 'csv', priority: 10)]
final readonly class CsvShelfExporter implements ShelfExporter { /* … */ }

#[AsTaggedItem(index: 'json')]
final readonly class JsonShelfExporter implements ShelfExporter { /* … */ }
```

```php
final readonly class ShelfExportRunner
{
    /**
     * @param ServiceProviderInterface<ShelfExporter> $exporters
     */
    public function __construct(
        #[AutowireLocator('app.shelf_exporter')]
        private ServiceProviderInterface $exporters,
    ) {
    }

    public function export(Shelf $shelf, string $format): string
    {
        if (!$this->exporters->has($format)) {
            throw new UnsupportedExportFormat($format);
        }

        return $this->exporters->get($format)->export($shelf);
    }
}
```

`#[AutoconfigureTag]` on an interface does work — the interface gets an abstract
definition in the container, and its attribute registers an `_instanceof` rule that
applies to every implementing class. The compiled locator ends up keyed exactly by the
`index:` values (`csv`, `json`); without `#[AsTaggedItem]` the key is the service id,
which makes the lookup useless.

**Type-hint `Symfony\Contracts\Service\ServiceProviderInterface`, not
`Psr\Container\ContainerInterface`.** What `#[AutowireLocator]` injects is a
`ServiceLocator`, which implements `ServiceCollectionInterface<T>` and so
`ServiceProviderInterface<T>`. Only that interface is templated — so only it lets
PHPStan know `get()` returned a `ShelfExporter` rather than `mixed` — and only it
declares `getProvidedServices()`, which is how you enumerate the locator's keys. The PSR
interface has neither, so at level max the generic annotation is rejected and every call
on the result is an error.

Choose the injection by how the collection is consumed:

- **`#[AutowireLocator]`** when one of them is selected — it is lazy, so only the chosen
  service is instantiated. This is the usual case.
- **`#[AutowireIterator]`** when all of them run, in order — a chain of validators, a
  pipeline. `priority:` decides the order; higher runs first.
- **`#[AutowireLocator]` again when the fan-out must tolerate a broken member.** The
  iterator constructs each service *as the `foreach` advances*, which is outside any
  `try` you wrote around the call — so one service whose constructor throws takes the
  whole endpoint down with a 500, and the `catch` you added never runs. With the locator,
  `get()` happens inside the loop and inside the `try`, so the broken one is reported and
  the others still run. Measured on a health-check endpoint collecting its checks by tag;
  `symfony-observability` has the worked example.

Do not build a strategy for two branches that will never become three. A `match`
expression in the service is smaller, readable in one place, and honest about the fact
that there are two cases.

## Decorator

Adding behaviour around an existing service — logging, caching, retry, rate limiting —
without touching it and without an `if` in the original:

Services are `final` here, so a decorator cannot extend one: **decoration needs an
interface that consumers typehint.** Introduce it when the need appears, not before.

```php
interface ShelfReader
{
    /** @return list<BookView> */
    public function shelf(): array;
}

#[AsAlias(ShelfReader::class)]
final readonly class DoctrineShelfReader implements ShelfReader { /* … */ }

#[AsDecorator(decorates: ShelfReader::class)]
final readonly class CachedShelfReader implements ShelfReader
{
    public function __construct(
        #[AutowireDecorated] private ShelfReader $inner,
        private CacheInterface $cache,
    ) {
    }

    public function shelf(): array
    {
        return $this->cache->get('shelf', fn () => $this->inner->shelf());
    }
}
```

Decorating the interface id works even though it is an alias — the alias resolves, the
decorator takes its place, and a consumer typehinting `ShelfReader` receives
`CachedShelfReader` with no change anywhere. The original is renamed
`<decorator>.inner` and is reachable only through `#[AutowireDecorated]`.

Decoration is transparent to tagged collections: in a compiled container, a decorated
service keeps its place and its key in the locator, now pointing at the decorator, and
the inner service does not appear a second time. That is what makes it safe to decorate a
strategy implementation.

Two limits worth stating. Decorating changes what every consumer receives, which is
powerful and invisible — `debug:container <id>` is the only place it shows. And a
decorator that adds caching is a performance decision: measure first, and read
`symfony-performance` before adding one.

## Factory

When constructing an object needs a decision, not just dependencies — picking a driver
from configuration, building a value object from several sources.

Prefer a plain service with a `create…()` method returning the object. It is autowired
like anything else, unit-testable, and needs no container feature at all. Reach for a
container factory (`Definition::setFactory()`, or `#[AutowireCallable]`) only when the
*service itself* must be produced by something other than `new`.

`#[Lazy]` is not a factory. It defers instantiation of an expensive service that is often
unused; it does not change how the object is built.

## Attributes deliberately not used

| Not used | Why |
|---|---|
| `#[Required]` (`Symfony\Contracts\Service\Attribute\Required`) | Setter injection. It makes a dependency optional-looking and mutable, which is exactly what `final readonly` is for. Its real use is base classes in bundles inheriting dependencies — this standard does not inherit services. |
| `#[SubscribedService]` / `ServiceSubscriberInterface` | The subscriber contract adds a static method to maintain and hides dependencies behind a container. `#[AutowireLocator]` gives the same laziness with the dependency written in the constructor. |
| `#[AutowireInline]` | Declares a service definition inside a consumer's attribute. Compact, and unfindable: the definition of a service should be where the class is. |

If a third-party bundle uses any of them, leave it. The rule is about code written here.

## Symptoms

| Symptom | Cause | Fix |
|---|---|---|
| `Cannot autowire: argument $x references interface I but no such service exists` | Several implementations, or none registered | `#[Target]` with a named alias, or a tagged collection |
| A tagged locator keyed by FQCN | `#[AsTaggedItem(index:)]` missing | Add it; the tag alone does not name anything |
| A new implementation is ignored by the strategy | `#[AutoconfigureTag]` is on a class, not the interface | Move it to the interface |
| `#[Target('…')] but no such target exists` at build time | The alias is mistyped or was never declared | Read the *"Did you mean to target"* list the message prints |
| A service fails on first use, every check passed | A mistyped `#[Target]` on a consumer reached only through a locator — or an `#[Autowire(service:)]` typed to an interface the service does not implement | Both compile as a `container.error` / `TypeError`; check the name and the type |
| One broken service 500s an endpoint that loops over a tagged collection | `#[AutowireIterator]` builds each service outside your `try` | `#[AutowireLocator]`, and `get()` inside the loop |
| Everything works in dev, a class is missing in prod | `#[When('dev')]`, or the class is excluded in `services.yaml` | `debug:container --env=prod` |
| A decorator is never called | `#[AsDecorator]` names an id nobody injects (e.g. a concrete class while consumers typehint the interface) | Decorate the id that is actually injected |
| `#[Autowire]` throws at compile time | Two of `value`/`service`/`env`/`param` passed | Pass exactly one |
