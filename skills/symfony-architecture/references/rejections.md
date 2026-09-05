# Deliberate rejections

Every alternative this standard turned down, and why. It lives here because the whole
suite needs one place to point at.

A rejection that is not written down gets re-introduced by the next person trying to be
helpful — usually with a good argument, because most of these alternatives are defensible.
Several are what other teams do, correctly, for their context. The value is not that these
choices are objectively right; it is that they are *decided*, so nobody re-litigates them
in a pull request at 6pm.

**How to use this.** If someone proposes one of these, do not argue from authority: name
the rejection, give the reason below, and say what this standard does instead. If the
reason genuinely does not apply to the project at hand, that is a fork, not a bug — see
the suite README.

**This file is an index, not the source.** Every section below restates a decision that
belongs to another skill — the Testing rows to `symfony-testing`, the HTTP rows to
`symfony-http`, and so on down the list. When a row and its owning skill disagree, the
owning skill wins and this file is the one to correct.

## The five that carry the rest

Most of the table below is detail. Five rejections generate the shape of the whole
standard, and if a project keeps only these it still recognisably follows it:

1. **No domain events for business consequences.** Consequences are direct calls, so a
   use case is readable in one method and testable with one assertion.
2. **No rules on entities.** One rule with no exceptions is easy to hold, and
   maker-format entities keep every tool working; so rules live in services — which is
   also why services are where the tests point. The price is stated, not hidden: the
   guarantee rests on every write going through the service.
3. **No queries outside repositories.** Named after intent, so the same query exists once
   and can be optimised once.
4. **No entities crossing the service boundary.** Output DTOs, so a mapping change is not
   an API change.
5. **No bundler.** AssetMapper, so the front end stays Twig, Stimulus and Symfony UX —
   with the price stated rather than discovered.

## Architecture

| Rejected | Instead | Why |
|---|---|---|
| Domain events dispatched for business consequences | The service calls its collaborator directly | "What happens when X?" becomes a project-wide search, and the order depends on invisible listener priorities |
| `EventSubscriberInterface` | `#[AsEventListener]` on the class | A static `getSubscribedEvents()` to keep in sync, for no gain |
| Rich entities with `publish()`-style methods | Rules in services, entities in maker format | Simplicity and tooling: one rule with no exceptions, and `make:entity`, Form, fixtures and EasyAdmin keep working. The price is that the guarantee rests on going through the service — bounded by the Input DTO at the HTTP edge, and by `#[Assert]` on the entity where an admin surface bypasses services |
| Kernel event listeners as a first reflex | The dedicated mechanism: `#[WithHttpStatus]`, a ValueResolver, `#[Cache]`, a Voter | A kernel listener runs on everything, far from the code it affects, and debugs badly |
| The Symfony secrets vault on top of a container platform's secret store | The platform store alone, as environment variables | The vault's decryption key would itself be a platform secret: a second store fed by the first. The vault is not rejected on its merits — it is the standard wherever there is no platform store or secrets must be versioned (`symfony-deployment`). What is rejected outright is `.env.local` on a production host |
| `#[Required]` setter injection | Constructor injection | Makes a dependency look optional and the service mutable |
| `ServiceSubscriberInterface` / `#[SubscribedService]` | `#[AutowireLocator]` | Same laziness, dependency visible in the constructor, no static method |
| `#[AutowireInline]` | A normal service definition | A service defined inside another class's attribute is unfindable |
| One DTO for input and output | `src/Dto/Input/`, `Read/`, `Output/` | The merged class accumulates `#[Groups]` and nullable properties until it describes neither side |

## Testing

| Rejected | Instead | Why |
|---|---|---|
| Manual `beginTransaction()` / `rollBack()` in `setUp()` / `tearDown()` | `dama/doctrine-test-bundle` | **This one is a reversal.** A transaction opened in `setUp()` dies on the second request of a `WebTestCase`: the kernel reboots, the connection is recreated, `rollBack()` throws `NoActiveTransaction` — and the rows are left committed. DAMA hooks at the driver level from a PHPUnit extension, outside the kernel lifecycle, so it does not (`symfony-testing`) |
| A numeric coverage target | Behavioural coverage: nominal, boundaries, errors, security, side effects | A percentage is satisfied by testing getters |
| `findAll()[0]` in a test using fixtures | `getReference()` with named references, and fixture groups | Ordering is not a contract; the test breaks when an unrelated fixture is added |
| `$container->set()` to replace your own services | Unit-test the rule instead | Mocking your own service in a functional test means the rule is in the wrong layer |
| Hand-rolled mocks for framework boundaries | Native doubles: in-memory mailer, `MockHttpClient`, in-memory Messenger transport, `MockClock` | They assert on real behaviour and survive framework upgrades |
| PHPUnit docblock annotations | Attributes: `#[Test]`, `#[DataProvider]`, `#[CoversClass]` | Annotations are deprecated; attributes are checked by the parser |
| `createMock()` everywhere | `createStub()` unless there is an `expects()` | From PHPUnit 11 a mock with no expectation emits a *PHPUnit* notice, and `failOnPhpunitNotice="true"` turns the suite red — the recipe's `failOnNotice` covers PHP notices only and does not catch it (`symfony-testing`) |

## HTTP

| Rejected | Instead | Why |
|---|---|---|
| One invokable controller class per action | One controller class per resource, class-level route prefix | Five files that share a prefix and a service, for no isolation gained |
| An entity in a JSON response or a template | An Output DTO | The payload silently follows whatever Doctrine hydrated |
| A bare array with pagination in HTTP headers | `items` + `meta` envelope | Headers are invisible in most clients and lost through proxies |
| Cursor pagination | Page / perPage / total / pages | Real cost only pays off at a scale this standard does not assume; it forbids "jump to page 7" |
| Pagerfanta | The envelope above, built in the service | A dependency to expose four integers |
| `#[UniqueEntity]` on the entity | On the Input DTO with `entityClass:` | The entity is not what the HTTP edge validates, so there the constraint never fires. Add it on the entity as well only when an admin surface edits that field (`symfony-doctrine`) |
| Custom exception listeners for API errors | `#[WithHttpStatus]` plus RFC 7807 Problem Details | The status belongs next to the exception's meaning |
| API Platform, for a small hand-written API | Input/Output DTOs, `#[Serialize]`, the envelope above | Full control over a contract you own end to end. **This is a scoping decision, not a judgement** — past a handful of resources, or as soon as a documented OpenAPI contract is needed, redirect to API Platform instead of reimplementing it. The suite does not cover it (`symfony-http`) |

## Doctrine

| Rejected | Instead | Why |
|---|---|---|
| UUIDv7 / ULID / an internal id plus a public one | Auto-increment integer identifiers | Two identifiers to keep consistent, wider indexes, and no requirement here that asks for them |
| DQL, QueryBuilder or SQL outside a repository | Repository methods named after the caller's intent | Otherwise the same query exists in four places and none of them can be optimised |
| `final` repositories | Repositories stay non-`final` | They are doubled in service unit tests. Everything else is `final` |
| `flush()` inside a repository method | The service flushes, once per use case | The transaction boundary must be visible in the method that owns the operation |
| `count($book->getRatings())` | A repository count method | Hydrates the entire collection to produce one integer |
| `schema:update` in production | Generated migrations, read and corrected by hand | The generator does not know about existing data and emits DROP/ADD where a RENAME was needed |
| `\DateTime` | `\DateTimeImmutable` | A mutable date passed to two services is one shared bug |
| String constants for closed value sets | Native enums with `enumType:` | The database and PHP agree, and the IDE knows the cases |
| `#[Assert]` constraints on entities as the general rule | Constraints on the Input DTO; on the entity only for a local rule that must hold on an admin surface such as EasyAdmin, reading the same constants | The entity is not what the HTTP edge validates. An admin that writes without a service is a trusted surface, and an entity constraint is the one guard it applies |

## Security

| Rejected | Instead | Why |
|---|---|---|
| LexikJWTAuthenticationBundle | Symfony's native `access_token` with an `AccessTokenHandler` | The framework covers it; a bundle adds key management and an upgrade surface |
| Sharing the session between the API and the web app | A stateless firewall with `Authorization: Bearer` | Couples two lifecycles and breaks the moment the API is called from anywhere else |
| Roles for object-level decisions | Roles for zones, Voters for objects | `ROLE_EDITOR` cannot express "the author of this review" |
| `access_control` alone, or `#[IsGranted]` alone | Both | `access_control` is the net nobody forgets; `#[IsGranted]` is the precision |

## Frontend

| Rejected | Instead | Why |
|---|---|---|
| Webpack Encore, Vite, any bundler | AssetMapper only | Stated with its price: no JSX, no single-file components, no advanced transpilation. If a request truly needs a build step, it is out of scope |
| Tailwind via a Node build | `symfonycasts/tailwind-bundle` | Keeps Node out of the toolchain entirely |
| A Live Component for a stateless form submission | A Turbo Stream returned as the response to the POST | A component class and a lifecycle bought for state that never existed. One question decides it: is there anything to remember between two interactions? |
| A Turbo Stream where the interaction has state to remember — a filter, a live search, a wizard | A Live Component | The serialised `LiveProp` is what carries that value across the round trip; a stream leaves you maintaining ids and fragments to fake it |
| An entity, or anything expensive, in a `LiveProp` | A DTO, and a paginated query | Every interaction remounts the component and rehydrates the prop through the ORM |
| A `#[LiveAction]` with no `#[IsGranted]` | The same check a controller action would carry | `ux_live_component` is a public route; hiding the button protects nothing |
| Inline `<script>` in templates | Stimulus controllers | Unfindable, untestable, and blocked by any CSP worth having |
| Business rules in JavaScript | Rules in services | A rule enforced in the browser is a rule not enforced |
| A Twig `AbstractExtension` class | `Twig\Attribute\AsTwigFilter` and friends (package `twig/twig`, not a Symfony namespace) | Less ceremony, and the filter sits next to the code it uses |

## Async

| Rejected | Instead | Why |
|---|---|---|
| Redis or AMQP as the Messenger transport | The Doctrine transport, on the existing database | One less service to run, back up and monitor. Revisit only under measured load |
| Messenger as an in-process command bus (CQRS-style) | A controller calls a service directly | Dispatching everything buys indirection and loses the stack trace |
| An entity inside a message | Identifiers; the handler reloads | A serialised entity is a snapshot, and the database has moved on by the time the handler runs |
| A Doctrine entity in a `TemplatedEmail` context | Scalars, or render the body before sending | The context must be serialisable; an entity there fails at queue time, not at write time |
| A crontab entry | Scheduler (`#[AsCronTask]`, `#[AsPeriodicTask]`) | Versioned with the code it runs. It needs a running worker — say so before installing it |
| A failure queue nobody watches | An alert on any message reaching `failed` | Otherwise the queue is a silent data-loss buffer |

## Console

| Rejected | Instead | Why |
|---|---|---|
| A destructive command that acts by default | Dry run by default, `--force` to act, counts printed before and after | The gap between the two counts is what reveals silent partial deletions |
| Long or scheduled commands without a lock | `symfony/lock` / `LockableTrait` | A 6-minute task scheduled every 5 minutes stacks until the server dies |
| Business logic inside a command | The service holds it; the command is a translation layer with a smoke test | Same rule as a controller |

## Performance

| Rejected | Instead | Why |
|---|---|---|
| HTTP caching by default | `#[Cache]` only where slowness was measured | Cache added early hides the real problem and produces stale-content bugs that are hard to diagnose |
| `public: true` on a response containing personal data | Never | That is a leak through the shared cache |
| TTL-only application caching | `cache.app.taggable` with tag invalidation | A TTL is a guess about when data changed |
| Trusting that a list endpoint has no N+1 | An asserted query count in an integration test | An untested rule is a wish |
| `DebugStack` for counting queries | The profiler's `db` collector, or `doctrine.debug_data_holder` directly | `DebugStack` was removed in DBAL 4. `DoctrineDataCollector::getQueryCount()` does still exist and is the recommended route — see `symfony-performance` |

## Quality, deployment, local development

| Rejected | Instead | Why |
|---|---|---|
| PHP_CodeSniffer alongside php-cs-fixer | php-cs-fixer as the only style tool | Two formatters that disagree correct each other in a loop |
| `local-php-security-checker` | `composer audit` | Archived; its own repository points at the replacement |
| `symfony check:security` | `composer audit` | Works, but duplicates a check built into Composer since 2.4 |
| Quality tools installed per project | The `jakzal/phpqa` Docker image | One pinned version of every tool, identical locally and in CI, extensions included. Not because of dependency conflicts: only php-cs-fixer has any, PHPStan and deptrac are self-contained phars and are acceptable in `require-dev` (`symfony-quality`) |
| Both a Makefile and a `castor.php` | `castor.php`, and the Makefile only where Castor genuinely cannot be installed — one of the two, never both | Two task runners drift apart, and then nobody knows which one CI runs. Castor is the default because tasks are real PHP; the Makefile is a fallback, not an equal option (`symfony-quality`) |
| PHPStan level 5 while writing fully typed code | Level max, with the Symfony and Doctrine extensions | Paying the annotation cost without collecting the benefit |
| Testing on SQLite, shipping on PostgreSQL | A CI database service matching production | The differences then surface in production instead of in CI |
| Backward-compatible migrations as a hard constraint | Migrations run automatically at deploy | A short interruption is cheaper than designing every schema change for two code versions at once. The one consequence to own: a release whose migration drops data has no rollback, and `symfony-deployment` says so before deploying rather than splitting every change |
| Daily development inside the production-like container | Services in Docker, PHP on the host | You lose instant reload and step debugging and pay a rebuild per change |
| Pinning host ports in `compose.override.yaml` | Publish the container port only | Docker picks a free port, the Symfony CLI discovers it, and two projects run side by side |

## When a rejection stops applying

Some of these are contextual, and pretending otherwise turns a standard into dogma. The
honest triggers for revisiting one:

| Rejection | Revisit when |
|---|---|
| Doctrine Messenger transport | The queue table becomes a contention point under measured load, or you need fan-out to several consumers |
| Auto-increment identifiers | Identifiers must be generated offline or by a client, or leaking row counts is a real concern |
| No HTTP cache | A public page's slowness has been measured, and the response contains nothing personal |
| Page/perPage pagination | A list grows past the point where `OFFSET` is cheap, and deep pages are actually requested |
| No bundler | The product genuinely needs a JS framework with a build step — at which point say it is out of scope for this suite rather than smuggling one in |
| The platform store instead of the vault | There is no platform store (bare server, rsync deploy), or secrets must be versioned and reviewed in the repository — then the vault, wholesale |

Three that do not have a trigger, because the reason does not weaken with scale: rules on
entities, queries outside repositories, and entities crossing the service boundary. Those
get worse as a project grows, not better.

## Rejections that are really deferrals

`#[Required]`, `EventSubscriberInterface`, `#[SubscribedService]` and Pagerfanta all
work. If a project already uses one, leave it: migrating a working setup buys nothing and
costs a diff nobody wanted to review. The rule is about code written from here on.

The manual test transaction is the exception to that exception. It is not a preference
that lost — it is broken in a way that is invisible until it costs you a debugging
afternoon, so a project that has it should migrate rather than leave it.

The exception is anything the table marks as archived or removed —
`local-php-security-checker`, `DebugStack`, `#[TaggedIterator]`, `#[MapDecorated]`,
PHPUnit docblock annotations. Those are not preferences; the code is on a path to
breaking, and pointing that out is a bug report, not an opinion.
