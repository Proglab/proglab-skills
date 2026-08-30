# Caching: HTTP, application, Doctrine

Verified against **Symfony 8.1.5 / doctrine-bundle 3.3.1 / doctrine/orm 3.6.8**.
The order of this file is the order of the decision: do not open it until a measurement
says a specific page or a specific call is slow.

## The rule that comes first

**No cache without a number.** Not a feeling that the page is slow — a profiler
measurement naming the query or the call that costs the time.

The reason is not purity. A cache added before the measurement does two things at once:
it hides the real problem, so nobody looks at it again, and it introduces a whole class
of bugs whose reproduction depends on a timer. "It was fine when I tested it" is the
normal report, and the investigation starts from nothing because the fast path and the
slow path produce identical code paths. Fix the query first; cache what is left.

---

# HTTP caching

## The attribute

`Symfony\Component\HttpKernel\Attribute\Cache` — repeatable, and usable on the class or
the method. Verified constructor surface on 8.1:

```php
#[Cache(
    expires: null,               // strtotime()-compatible string
    maxage: null,                // browser / private cache, seconds or "1 hour"
    smaxage: null,               // shared cache (reverse proxy)
    public: null,                // true → public, false → private, null → untouched
    mustRevalidate: false,
    vary: [],                    // string[]
    lastModified: null,          // \DateTimeInterface|string|Expression|\Closure
    etag: null,                  // string|Expression|\Closure
    maxStale: null,
    staleWhileRevalidate: null,
    staleIfError: null,
    noStore: null,
    if: true,                    // bool|string|Expression|\Closure — apply conditionally
)]
```

Older branches have fewer arguments, and three of the things above are **Symfony 8.1**:
the `if:` condition, the `\Closure` forms of `etag` / `lastModified` / `if`, and the
`request`, `args` and `this` variables inside an expression (http-kernel CHANGELOG 8.1).
What to write instead on 6.4–8.0: put the condition in the controller as an early return,
and keep expressions to the request attributes and resolved controller arguments, which
`CacheAttributeListener` merges into the top-level scope on every version — that is why
the bare `book` below works. The `\Closure` forms are unusable below **PHP 8.5** in any
case, since a closure inside an attribute is a compile-time fatal, so the
ExpressionLanguage string is the portable form everywhere. Open
`vendor/symfony/http-kernel/Attribute/Cache.php` rather than assuming.

## Expiration vs validation

They answer different questions, and only one of them helps a page that is slow to
render.

**Expiration** — "do not ask me again for an hour".

```php
#[Route('/catalogue', name: 'catalog_index', methods: ['GET'])]
#[Cache(smaxage: 3600, public: true)]
```

No request reaches PHP while the entry is fresh. Fastest possible outcome, and the
content is wrong for up to an hour by design. Only for pages where that is acceptable
and stated.

**Validation** — "ask me, I will answer 304 if nothing changed".

```php
#[Route('/livres/{id}', name: 'book_show', methods: ['GET'], requirements: ['id' => '\d+'])]
#[Cache(etag: 'book.getUpdatedAt().getTimestamp()', public: false)]
public function show(Book $book): Response
```

Verified in `CacheAttributeListener`: `etag` and `lastModified` are evaluated on
`kernel.controller_arguments` and, when the request's `If-None-Match` /
`If-Modified-Since` matches, the listener **replaces the controller with one returning
the 304** and stops propagation. Observed: the action never runs and the response body
is zero bytes. That is what makes validation the right tool for an expensive page — the
saving is the rendering, not the bytes.

The string is an **ExpressionLanguage expression**, not a literal: it is evaluated
against the request attributes and the resolved controller arguments (plus `request`,
`args` and `this`), then hashed with `sha256`. So `etag: 'ref'` on an action taking
`string $ref` produces an ETag derived from that argument's value. It requires
`symfony/expression-language`, and a typo is a runtime error on the very page you were
trying to speed up — test the 304 path, do not eyeball it.

The signature also accepts a `\Closure` receiving
`(array $args, Request $request, ?object $controller)`, which would be nicer. Verified:
**you cannot use it from an attribute before PHP 8.5** — closures are not constant
expressions, and PHP 8.4 fails with *Constant expression contains invalid operations* at
parse time. On PHP 8.2–8.4 the expression string is the only form available.

## Two ways to leak or waste

### `public: true` on personal data is a leak

A shared cache stores one response and serves it to everyone. If the page contains a
name, an email, a shelf, an order — anything that varies per user — `public: true` hands
it to the next visitor. There is no configuration that makes this safe; `Vary` on a
cookie is not a defence, because the cookie is not what identifies the sensitivity.

The rule: a response that varies per user is `private`, full stop. `public` is for
pages that would be identical if rendered for a stranger.

### Touching the session silently kills `public`

Verified: `AbstractSessionListener::onKernelResponse()` runs at priority **-1000** — after
the `#[Cache]` attribute is applied — and when the session has been used at all it forces

```
Cache-Control: max-age=0, must-revalidate, private
```

"Used at all" means `Session::getUsageIndex() > 0`: a flash message, a security token
read, a CSRF token, an authenticated firewall. Observed output for a controller carrying
`#[Cache(smaxage: 3600, public: true)]` that read one session key:

```
Cache-Control: max-age=0, must-revalidate, private, s-maxage=3600
```

Contradictory, and nothing is cached. This is a good thing — it is the framework
stopping the leak above — but it is silent, so it costs an afternoon the first time.

If a page genuinely must be publicly cacheable, it must not touch the session at all:
no `addFlash`, no `#[IsGranted]`, no CSRF-protected form on it. Symfony offers an escape
hatch, `AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER` on the response, which
suppresses the override. Reach for it only when you have personally checked that nothing
user-specific is in the body.

## `Vary`

`#[Cache(vary: ['Accept-Language'])]` tells the shared cache to store one entry per value
of that header. Every entry added to `Vary` multiplies the cache entries, so a `Vary` on
`User-Agent` effectively disables the cache. Keep it to headers that genuinely change
the body.

## Where the shared cache lives

`smaxage` only means something if something is actually caching: a real reverse proxy
(Varnish, or the CDN in front of the app — the production answer), or Symfony's own
`framework.http_cache.enabled: true`, whose `trace_level: short` adds an
`X-Symfony-Cache` header showing hit/miss and is how you check the headers do what you
think. Without either, `#[Cache(smaxage: …)]` is a header nobody reads.

---

# Application cache

## It goes in a decorator, not in the service

```php
#[AsDecorator(ShelfReading::class)]
final readonly class CachedShelfReader implements ShelfReading
{
    public function __construct(
        #[AutowireDecorated] private ShelfReading $inner,
        private TagAwareCacheInterface $cache,
    ) {
    }

    /** @return list<ShelfRow> */
    public function shelf(int $userId): array
    {
        return $this->cache->get(
            'shelf.'.$userId,
            function (ItemInterface $item) use ($userId): array {
                $item->tag(['shelf', 'shelf.'.$userId]);
                $item->expiresAfter(3600);

                return $this->inner->shelf($userId);
            },
        );
    }
}
```

Why a decorator rather than three lines inside `ShelfReader`:

- The business service stays unit-testable with no cache anywhere near it, which is what
  keeps its tests fast and its failures readable.
- The cache is removable by deleting one class. Cache code woven into a method is never
  removed, because nobody can tell what still depends on it.
- The decorator is itself testable: give it a stub `ShelfReading` and an
  `ArrayAdapter`, assert the inner service was called once for two reads.

**The cost, and it is real.** This suite makes services `final`, and you cannot decorate
a final concrete class. Verified — `lint:container` refuses it outright:

```
[ERROR] Invalid definition for service "App\Controller\BookController":
        argument 1 of "App\Controller\BookController::__construct()" accepts
        "App\Service\ShelfReader", "App\Service\CachedShelfReader" passed.
```

So introducing a cache is also the moment you extract an interface (`ShelfReading`),
make `ShelfReader` implement it, alias the interface, and repoint consumers. Do that
deliberately; do not "solve" it by removing `final`.

```yaml
# config/services.yaml — needed once there are two implementations
services:
    App\Service\ShelfReading: '@App\Service\ShelfReader'
```

`#[AutowireDecorated]` needs Symfony **6.3+** (`#[AsDecorator]` itself is 6.1). On 6.1–6.2
it was `#[MapDecorated]`, removed in 7.0; on anything older, declare the decoration in
YAML with `decorates:` and inject `'@.inner'`.

## Tags, not TTL alone

`TagAwareCacheInterface` autowires to `cache.app.taggable` with no configuration —
verified in both `test` and `prod` containers.

A TTL says "this may be wrong for up to an hour". A tag says "this is wrong now":

```php
// in the service that writes
$this->books->add($book);
$this->em->flush();
$this->cache->invalidateTags(['book.'.$book->getId(), 'shelf']);
```

Keep a TTL as well. It is the safety net for the invalidation call you will forget to
add to the third write path.

Practical notes:

- **Key characters.** `ItemInterface::RESERVED_CHARACTERS` is `` {}()/\@: `` — a key
  containing any of them throws. Build keys from ids and slugs, never from raw user
  input or a URL.
- **Cache DTOs, not entities.** A serialised entity is a detached snapshot; putting it
  back in front of Doctrine causes lazy-loading errors and stale relations.
- **Stampede protection is on by default** through `$beta` in `get()`: entries are
  recomputed slightly before expiry by a single request rather than by all of them at
  once. Leave it alone unless the callback is expensive enough to justify tuning.
- **`cache.app` is data**; **`cache.system` is derived from code** (metadata, DQL
  parsing) and is wiped by a deploy. Never put data in `cache.system`.

---

# Doctrine's own caches

## Query cache — yes, in prod

Caches the DQL → SQL parse. It costs nothing and is invalidated by a deploy, so its pool
is `cache.system`.

```yaml
when@prod:
    doctrine:
        orm:
            query_cache_driver:
                type: pool
                pool: doctrine.system_cache_pool

    framework:
        cache:
            pools:
                doctrine.system_cache_pool:
                    adapter: cache.system
```

## Result cache — per query, opt-in

```php
return $this->createQueryBuilder('b')
    ->select(\sprintf('NEW %s(b.id, b.title, b.status, b.lastReadAt)', ShelfRow::class))
    ->getQuery()
    ->enableResultCache(3600, 'shelf_rows')      // lifetime, explicit cache id
    ->getResult();
```

The pool is `cache.app`, because this holds data:

```yaml
when@prod:
    doctrine:
        orm:
            result_cache_driver: { type: pool, pool: doctrine.result_cache_pool }
    framework:
        cache:
            pools:
                doctrine.result_cache_pool: { adapter: cache.app }
```

Two warnings. Passing an explicit id is what lets you delete the entry later — with the
generated hash you cannot invalidate anything. And a result cache returning **entities**
gives you managed objects rebuilt from cached rows; if a service then modifies and
flushes one, the behaviour is subtle. Result-cache projections, not entities.

## Second-level cache — not used

Doctrine's second-level cache caches entities themselves, per entity, with its own
region configuration and its own invalidation semantics. It is rejected here: every
problem it solves is better solved by fixing the query or by an explicit application
cache in a decorator, and its failure mode — an entity that is quietly out of date
inside the ORM — is much harder to diagnose than a stale DTO in `cache.app`.

## Metadata cache

Already on `cache.system` from the recipe. Nothing to do.
