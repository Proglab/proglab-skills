# Functional tests, commands, voters and validation

What to assert at the HTTP boundary, and how to test three things that look like they
need a browser but do not.

## Contents

- [What a functional test is for](#what-a-functional-test-is-for) ·
  [The skeleton](#the-skeleton) · [Forms, CSRF and the 422](#forms-csrf-and-the-422) ·
  [JSON endpoints](#json-endpoints) · [Authentication](#authentication) ·
  [Console commands](#console-commands) · [Voters](#voters) ·
  [DTO validation](#dto-validation)

## What a functional test is for

Wiring. Routing, argument mapping, status codes, redirects, the shape of the HTML or
JSON contract, and the fact that a rule refused something at the boundary.

Not business rules. If the only place a rule can be exercised is a POST request, it is
in the controller and the fix is to move it. What stays behind is short and fast:

```php
$this->client->request('POST', '/livres/'.$id.'/note', ['rating' => '6', '_token' => $token]);

self::assertResponseStatusCodeSame(422);
self::assertNull(self::bookTitled('Dune')->getRating(), 'nothing must be written');
```

Note the second assertion. A 422 proves the response; it does not prove the write did
not happen. **Every "it was refused" test asserts on the side effect too**, otherwise it
passes on code that returns 422 after saving.

## The skeleton

```php
// use PHPUnit\Framework\Attributes\{DataProvider, Test};
// use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\WebTestCase};

final class BookControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    #[Test]
    #[DataProvider('pages')]
    public function every_page_loads(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    public static function pages(): \Generator
    {
        yield 'shelf' => ['/'];
        yield 'add a book' => ['/livres/nouveau'];
    }
}
```

`createClient()` boots the kernel — calling `self::bootKernel()` first throws. Options
go in the first argument (`['environment' => 'test', 'debug' => false]`), server
variables in the second. `disableReboot()` is not there for database isolation — DAMA's
static connection survives the reboot on its own (`references/database-tests.md`). It is
there because the reboot builds a new container from the second request onwards, which
makes anything you fetched from `self::getContainer()` stale and empties the in-memory
mailer and Messenger recorders. That smoke test over every route catches a template
that stopped compiling, a service that no longer autowires, and a route a refactor left
pointing at nothing.

### The assertions worth knowing

| Family | Examples |
|---|---|
| Response | `assertResponseIsSuccessful`, `assertResponseStatusCodeSame`, `assertResponseRedirects`, `assertResponseIsUnprocessable`, `assertResponseHeaderSame`, `assertResponseFormatSame` |
| Routing | `assertRouteSame('book_index')`, `assertRequestAttributeValueSame` |
| DOM | `assertSelectorExists`, `assertSelectorCount`, `assertSelectorTextContains`, `assertAnySelectorTextContains`, `assertPageTitleContains`, `assertInputValueSame`, `assertCheckboxChecked`, `assertFormValue` |
| Session | `assertSessionHasFlashMessage('success')`, `assertBrowserHasCookie` |

They print the response body on failure, which is why they beat a hand-rolled
`assertSame(200, …)` that tells you a 500 happened and nothing about why.

`assertSelectorTextContains('body', 'Dune')` is weak — it passes on any page mentioning
the word. Target the element carrying the meaning (`'[data-book="7"] .title'`), or count
(`assertSelectorCount(4, '…star.is-on')`), which is what catches an off-by-one in a loop.

## Forms, CSRF and the 422

Submit through the DOM rather than posting an array: it exercises the field names, the
CSRF token and the form theme at once.

```php
$this->client->request('GET', '/livres/nouveau');

$this->client->submitForm('Ajouter à ma bibliothèque', [
    'add_book[title]' => 'La Horde du Contrevent',
    'add_book[author]' => 'Alain Damasio',
    'add_book[pageCount]' => '704',
]);

self::assertResponseRedirects('/');
$this->client->followRedirect();
self::assertSelectorTextContains('[data-book]', 'La Horde du Contrevent');
```

**An invalid submission returns 422, not 200.** `AbstractController::render()` detects
a submitted-and-invalid form among its parameters and sets the status itself; Turbo
needs that to re-render the page with its errors instead of ignoring the response. A
test asserting 200 on an invalid form is asserting that Turbo is broken — so assert
`assertResponseStatusCodeSame(422)`, the error message on the page, and that nothing
was written.

For a state-changing action that does not go through a form, this standard uses
`#[IsCsrfTokenValid]`. Two tests, not one:

with a token read from a rendered page (the nominal case), and **without a token**.
Assert the exact status on the second: on a project with no authenticated firewall the
refusal surfaces as `401`, not `403`, because the security layer reports it as "not
authenticated" rather than "access denied". Assert what actually happens and write down
why, rather than assuming 403.

## JSON endpoints

```php
$this->client->request('POST', '/api/livres/7/note',
    server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
    content: json_encode(['rating' => 4]),
);

$payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
self::assertSame(4, $payload['rating']);
```

**Assert on the decoded payload, not on the raw string.** Two reasons:

- `json_encode(['rating' => 4.0])` produces `{"rating":4}` — PHP drops the `.0`, so a
  string comparison against `{"rating":4.0}` fails while the API is correct. If a value
  is conceptually an integer, type the output DTO property `int`.
- In the test environment `APP_DEBUG` is on, so an error response carries extra `class`
  and `trace` keys, and `assertJsonStringEqualsJsonString()` on the whole body of a 4xx
  fails on those. Assert the keys you care about.

So for an RFC 7807 error, assert `$problem['status']` and a substring of
`$problem['detail']`, not the whole document. The `content-type` follows the request's
`Accept` header, so pin it with `assertResponseHeaderSame()` only where the endpoint
sets it deliberately.

For a list endpoint the contract is the `items` + `meta` envelope, and the assertion
worth writing is on `meta`: `page`, `perPage`, `total`, `pages`. Page 2 of three is
where off-by-ones live, and it is one extra request to check.

Counting the SQL queries a list endpoint runs is a different concern with a different
API — see `symfony-yoandev-performance`.

## Authentication

```php
$user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'reader@example.com']);
$this->client->loginUser($user);
```

`loginUser()` injects a pre-authenticated token without going through the login form —
what you want in every test *except* the ones about logging in, which go through the
real form. Pass the firewall name when there is more than one: `loginUser($user, 'api')`.

**Check that security is actually wired before writing these.** On a skeleton project
with the default `users_in_memory` provider and no entity implementing `UserInterface`,
`loginUser()` and `#[CurrentUser]` cannot resolve. Say so instead of working around it.

Every protected route deserves the negative test: anonymous gets a redirect or a 401, a
logged-in user without the role gets a 403. A test that only ever visits the page as an
authorised user has not tested authorisation at all.

## Console commands

The service does the work and is unit-tested; the command is a translation layer and
gets a smoke test. Symfony 8.1+:

```php
final class PurgeShelfCommandTest extends KernelTestCase
{
    #[Test]
    public function it_reports_what_it_would_delete_without_deleting(): void
    {
        self::bootKernel();

        $result = self::runCommand('app:shelf:purge');

        $this->assertCommandIsSuccessful($result);
        self::assertStringContainsString('3 livres seraient supprimés', $result->getDisplay());
        self::assertSame(3, $this->countBooks(), 'a dry run deletes nothing');
    }

    #[Test]
    public function force_actually_deletes(): void
    {
        self::bootKernel();

        $this->assertCommandIsSuccessful(self::runCommand('app:shelf:purge', ['--force' => true]));
        self::assertSame(0, $this->countBooks());
    }
}
```

`runCommand(string $name, array $input = [], array $interactiveInputs = [], ?bool $interactive = null, …)`
returns an `ExecutionResult` carrying `statusCode`, `getOutput()`, `getErrorOutput()`
and `getDisplay()`. The assertions are `assertCommandIsSuccessful()`,
`assertCommandFailed()`, `assertCommandIsInvalid()` and `assertCommandResultEquals()`.
`$result->dump()` prints the whole thing when a test fails for reasons the message does
not explain.

Before Symfony 8.1 the equivalent is `new CommandTester($application->find('app:…'))`,
`execute([...])`, `getDisplay()` and `assertCommandIsSuccessful($tester)` — same rule,
different plumbing. The two tests above are the pair that matters for any destructive
command: **dry run by default, `--force` to act.** Testing only the `--force` path
leaves the default behaviour — the one that protects production — unverified.

## Voters

A voter is a pure function of (token, subject, attribute). It needs no kernel. The
`ReviewVoter` under test is the one `symfony-yoandev-security` defines: it takes an
`AuthorizationCheckerInterface` for the moderator check, so the test hands it a stub.

```php
#[Test]
public function only_the_author_may_edit_their_review(): void
{
    $author = (new User())->setEmail('reader@example.com');
    $review = (new Review())->setAuthor($author)->setContent('Excellent.');

    $checker = $this->createStub(AuthorizationCheckerInterface::class);
    $checker->method('isGranted')->willReturn(false);   // nobody here is a moderator
    $voter = new ReviewVoter($checker);

    $token = new UsernamePasswordToken($author, 'main', $author->getRoles());
    self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $review, [ReviewVoter::EDIT]));

    $other = (new User())->setEmail('other@example.com');
    $otherToken = new UsernamePasswordToken($other, 'main', $other->getRoles());
    self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($otherToken, $review, [ReviewVoter::EDIT]));
}
```

Entities are built the way the rest of the suite builds them — maker shape, `new` then
setters, no constructor arguments — so the test does not depend on a constructor the
entity does not have.

Three cases: **granted** for the user who may, **denied** for a user who may not, and —
the one people forget — **abstained** (`ACCESS_ABSTAIN`) for an attribute the voter does
not support. Pass `['SOMETHING_ELSE']` and assert it: a voter that accidentally supports
every attribute silently denies actions another voter was supposed to allow.

`Voter::vote()` takes an optional fourth `?Vote $vote` argument in recent Symfony
versions; leave it out. Test the public `vote()`, not the protected `voteOnAttribute()`
— the `supports()` logic is half the behaviour.

## DTO validation

Input DTOs carry the constraints, so they are testable without a kernel:

```php
final class RateBookInputTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[Test]
    #[DataProvider('acceptedRatings')]     // yields 1 and 5, the two bounds
    public function it_accepts_a_rating_between_one_and_five(int $rating): void
    {
        self::assertCount(0, $this->validator->validate(new RateBookInput($rating)));
    }

    #[Test]
    #[DataProvider('rejectedRatings')]     // yields 0 and 6, one step outside each bound
    public function it_refuses_a_rating_outside_the_range(int $rating): void
    {
        $violations = $this->validator->validate(new RateBookInput($rating));

        self::assertCount(1, $violations);
        self::assertSame('rating', $violations[0]->getPropertyPath());
    }
}
```

`enableAttributeMapping()` is Symfony 7.0+; on 6.4 the method is
`enableAnnotationMapping()->addDefaultDoctrineAnnotationReader()`.

Assert on the property path, not only on the count: a DTO with three constrained
properties produces one violation for a mistake on any of them, so a counting test
passes when the wrong field failed. Two traps specific to constraints:

- **`Assert\Range` refuses `minMessage` and `maxMessage` when both `min` and `max` are
  set.** It throws `ConstraintDefinitionException: The … constraint can not use
  "minMessage" and "maxMessage" when the "min" and "max" options are both set. Use
  "notInRangeMessage" instead.` The failure happens at attribute construction, so the
  whole test class errors out rather than one assertion failing.
- **`#[UniqueEntity]` needs a database** and is the one constraint that does not fit this
  pattern: test it in a `KernelTestCase` against the container's validator. It belongs
  on the input DTO with `entityClass:` and `fields: ['dtoProp' => 'entityField']` — on
  the entity it never fires, and a test that "passes" because the constraint is silent
  is exactly the failure this suite exists to prevent. Verify with a mutation check.
