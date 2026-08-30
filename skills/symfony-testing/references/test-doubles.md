# Test doubles

Which double to reach for, why PHPUnit now complains about the wrong one, and the fakes
Symfony already ships so you do not have to write your own.

## Contents

- [Stub or mock](#stub-or-mock)
- [The notice PHPUnit 11+ emits, and the flag that makes it matter](#the-notice-phpunit-11-emits-and-the-flag-that-makes-it-matter)
- [Replacing a service in the container](#replacing-a-service-in-the-container)
- [Emails](#emails)
- [Outbound HTTP](#outbound-http)
- [Messenger](#messenger)
- [Time](#time)
- [Notifications](#notifications)

## Stub or mock

Both are produced by PHPUnit's double generator; the difference is what you are
asserting.

| | Use it when | Assertion lives |
|---|---|---|
| **`createStub()`** | The collaborator only has to *return something* so the code under test can run | In your own `assertSame()` at the end |
| **`createMock()`** | The *call itself* is the behaviour you are testing | In `expects()` on the double |

```php
// Stub: the repository is scenery. The rule under test is the rounding.
$reviews = self::createStub(ReviewRepository::class);
$reviews->method('averageRatingForBook')->willReturn(4.25);

$view = (new ShelfReader($reviews))->summary(7);

self::assertSame(4.3, $view->averageRating);
```

```php
// Mock: "the book is persisted exactly once" IS the assertion.
$em = $this->createMock(EntityManagerInterface::class);
$em->expects(self::once())->method('flush');

(new BookRater($books, $em, $mapper))->rate(7, new RateBookInput(4));
```

`createStub()` is a static method in PHPUnit 13 (`self::createStub(...)`);
`createMock()` is an instance method (`$this->createMock(...)`). Both accept a
`class-string` and are typed `RealInstanceType&Stub` / `RealInstanceType&MockObject`,
so declare properties as intersections and PHPStan follows along:

```php
private BookRepository&MockObject $books;
```

This is also why **repositories are not `final`** in this standard: a `final` repository
cannot be doubled, and every service unit test would need a database. Everything else —
services, controllers, DTOs, voters, handlers — is `final`.

## The notice PHPUnit 11+ emits, and the flag that makes it matter

Since PHPUnit 11, a mock created with no expectation at all triggers:

```
No expectations were configured for the mock object for App\Repository\ReviewRepository.
Consider refactoring your test code to use a test stub instead.
The #[AllowMockObjectsWithoutExpectations] attribute can be used to opt out of this check.
```

**It is a PHPUnit notice, not a PHP notice**, and that distinction decides whether your
suite goes red. Measured on PHPUnit 13.3.2:

| Flag | Exit code with one unexpected mock |
|---|---|
| none | `0` — "OK, but there were issues!" |
| `failOnNotice="true"` | `0` — this covers PHP notices, not PHPUnit's |
| `failOnPhpunitNotice="true"` | `1` |
| `failOnAllIssues="true"` | `1` |

The Symfony recipe ships `failOnNotice="true"`, which does **not** catch this. Add the
right one to `phpunit.dist.xml`:

```xml
<phpunit
    failOnDeprecation="true"
    failOnNotice="true"
    failOnPhpunitNotice="true"
    failOnWarning="true"
    displayDetailsOnPhpunitNotices="true"
>
```

`displayDetailsOnPhpunitNotices` matters as much as the failure: without it you get a
count and no idea which test produced it.

The rule that follows is simple — **`createMock()` is always followed by `expects()`;
otherwise it is a `createStub()`.** `#[AllowMockObjectsWithoutExpectations]` exists for
the rare double that is legitimately a mock configured elsewhere; using it to silence
the notice on an ordinary stub is trading a one-word fix for a permanent lie.

## Replacing a service in the container

```php
$client = self::createClient();
$client->getContainer()->set(PaymentGateway::class, $fakeGateway);
```

**External boundaries only.** A payment gateway, an SMS provider, a third-party API,
anything that would charge money or hit the network. Your own services are never
replaced this way: if a functional test needs `BookRater` mocked, the rule under test
lives in `BookRater` and belongs in a `TestCase` with no kernel. The functional test
that remains asserts routing, mapping and status codes.

Three mechanics that bite:

- **`set()` after the service has been instantiated throws.** The exact message is
  `The "App\Service\X" service is already initialized, you cannot replace it`. Replace
  before the first request.
- **A kernel reboot drops the replacement.** `KernelBrowser` reboots between requests,
  so a service set before request 1 is gone by request 2. `$client->disableReboot()`
  keeps it — see `references/database-tests.md`.
- **The service must still exist in the compiled container.** A private service that
  nothing references is removed at compile time, and `set()` cannot resurrect it. In
  the test environment `framework.test: true` keeps most of them; when it does not, the
  fix is `public: true` in `config/services_test.yaml`, not a redesign of the code.

## Emails

`framework.test: true` swaps the mailer's transport for one that records. Do not build
a mailer double: assert on what was recorded, which also proves the message was
actually built, rendered and addressed.

```php
$this->client->submitForm('Publier', ['review[body]' => 'Excellent.']);

self::assertQueuedEmailCount(1);

$email = self::getMailerMessage();
self::assertEmailAddressContains($email, 'To', 'author@example.com');
self::assertEmailSubjectContains($email, 'Nouvelle critique');
self::assertEmailHtmlBodyContains($email, 'Excellent.');
```

**`assertEmailCount()` or `assertQueuedEmailCount()`?** This standard routes
`SendEmailMessage` to the async transport, so in a normal application the email is
*queued*, not sent, and `assertEmailCount(1)` fails with a count of zero. Use
`assertQueuedEmailCount()` unless you know the mail is synchronous. Getting this wrong
is the single most common "my email test does not work".

The related trap, from `symfony-async`: a `TemplatedEmail` context must be
serialisable, so no Doctrine entities in it. A functional test asserting on a queued
email is where that failure surfaces first — take it as the design signal it is.

Available on any `KernelTestCase` (the trait is on the base class, not only
`WebTestCase`): `assertEmailCount`, `assertQueuedEmailCount`, `assertEmailAttachmentCount`,
`assertEmailTextBodyContains`, `assertEmailHtmlBodyContains`, `assertEmailHasHeader`,
`assertEmailHeaderSame`, `assertEmailAddressContains`, `assertEmailSubjectContains`,
plus `getMailerMessages()` / `getMailerMessage(int $index = 0)`.

## Outbound HTTP

```php
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$http = new MockHttpClient([
    new MockResponse(json_encode(['items' => [['title' => 'Dune']]]), [
        'http_code' => 200,
        'response_headers' => ['content-type' => 'application/json'],
    ]),
    new MockResponse('', ['http_code' => 503]),
]);

$catalogue = new OpenLibraryCatalogue($http);
```

Pass an array of `MockResponse` for a fixed sequence, or a callable
`fn (string $method, string $url, array $options) => new MockResponse(...)` when the
response depends on the request. `getRequestsCount()` tells you how many calls were
made — useful to prove a retry happened, or did not.

In a functional test, replace the real client at the boundary:

```php
$client->getContainer()->set(HttpClientInterface::class, $http);
```

and assert with the framework's own helpers rather than by inspecting the double:

```php
self::assertHttpClientRequestCount(1);
self::assertHttpClientRequest('https://openlibrary.org/search.json?q=dune', 'GET');
```

Always test the failure branch. A 503, a timeout (`new MockResponse('', ['error' =>
'Timed out'])`) and a malformed body are the three things that actually happen to a
third-party API, and the code path that handles them is the one nobody exercises by
hand.

## Messenger

Route the transports to memory in the test environment:

```yaml
# config/packages/messenger.yaml
when@test:
    framework:
        messenger:
            transports:
                async: 'in-memory://'
```

```php
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

$transport = self::getContainer()->get('messenger.transport.async');
\assert($transport instanceof InMemoryTransport);

$this->client->request('POST', '/livres/7/export');

self::assertCount(1, $transport->getSent());
self::assertInstanceOf(ExportShelf::class, $transport->getSent()[0]->getMessage());
```

`getSent()`, `getAcknowledged()`, `getRejected()` and `reset()` are the whole API.

Two things this buys you that a mocked message bus does not: the message really goes
through the serializer, so a non-serialisable payload fails here instead of in
production; and you can assert on the *envelope*, including stamps.

**Test the handler separately, as a unit.** A handler is a translation layer — it
reloads from a repository and calls a service. Its test asserts that it did, and that
running it twice does not do the work twice, because Messenger retries and an
idempotent handler is a requirement rather than a nicety.

## Time

Never call `new \DateTimeImmutable()` inside a service you intend to test. Inject
`Psr\Clock\ClockInterface` (or use `ClockAwareTrait`, which autowires it through a
`#[Required]` setter and gives you `$this->now()`), then feed a `MockClock`:

```php
use Symfony\Component\Clock\MockClock;

$clock = new MockClock('2026-01-15 09:00:00');
$service = new ReadingStreak($readings, $clock);

self::assertSame(3, $service->currentStreak($reader));

$clock->sleep(86400 * 2);          // or $clock->modify('+2 days')

self::assertSame(0, $service->currentStreak($reader), 'the streak breaks after a gap');
```

`MockClock` implements the real interface, so there is nothing to stub and nothing that
can drift from the production behaviour. In a functional test,
`$container->set(ClockInterface::class, new MockClock('…'))` before the first request
does the same for the whole application. `Clock::set()` exists for code that still uses
the static `Clock` facade; prefer injection.

Anything with an expiry, a streak, a "published in the last 7 days" query or a rate
limit needs this. Tests that compute the expected value from `new \DateTimeImmutable()`
are testing the clock against itself and pass regardless of the code.

## Notifications

Symmetrical to the mailer, on any `KernelTestCase`: `assertNotificationCount()`,
`assertQueuedNotificationCount()`, `assertNotificationSubjectContains()`,
`assertNotificationTransportIsEqual()`, with `getNotifierMessages()` /
`getNotifierMessage()` to inspect. The same queued-versus-sent question applies, for
the same reason.
