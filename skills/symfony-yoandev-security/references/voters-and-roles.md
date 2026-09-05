# Authorisation: roles, Voters, access_control

Deciding what an authenticated user may do — and proving it with tests.

## Contents

- [role_hierarchy](#role_hierarchy)
- [access_control, in order](#access_control-in-order)
- [The Voter contract](#the-voter-contract)
- [Making the voter cheap: supportsAttribute and supportsType](#making-the-voter-cheap-supportsattribute-and-supportstype)
- [Unit-testing a voter without a kernel](#unit-testing-a-voter-without-a-kernel)
- [Testing the rule end to end](#testing-the-rule-end-to-end)
- [is_granted in Twig](#is_granted-in-twig)
- [Checking permissions inside a service](#checking-permissions-inside-a-service)
- [Expressions, and when to stop using them](#expressions-and-when-to-stop-using-them)

## role_hierarchy

Roles delimit **zones**. `ROLE_ADMIN` means "may enter the admin area", not "may edit
review 42".

```yaml
security:
    role_hierarchy:
        ROLE_MODERATOR: [ROLE_USER]
        ROLE_ADMIN:     [ROLE_MODERATOR]
        ROLE_SUPER_ADMIN: [ROLE_ADMIN, ROLE_ALLOWED_TO_SWITCH]
```

A user granted `ROLE_ADMIN` is granted everything below it, so the entity stores one
role per person rather than a list that drifts. The hierarchy is expanded by the
`RoleHierarchyVoter`, which is why role checks must go through the authorization checker:
`$token->getRoleNames()` returns the raw stored roles and knows nothing about the
hierarchy.

From Symfony 7.4 there is a command for reading it back — useful once the graph is more
than three lines:

```bash
php bin/console debug:security:role-hierarchy
```

Signs the hierarchy has become the wrong tool: a role named after a resource
(`ROLE_REVIEW_42`), a role granted per row, or a role whose name contains a verb
(`ROLE_CAN_EDIT_OWN_REVIEW`). All three are voter-shaped questions.

## access_control, in order

```yaml
    access_control:
        - { path: ^/api/login, roles: PUBLIC_ACCESS }
        - { path: ^/api,       roles: ROLE_USER }
        - { path: ^/admin,     roles: ROLE_ADMIN }
        - { path: ^/reviews,   roles: ROLE_USER, methods: [POST, PUT, DELETE] }
        - { path: ^/,          roles: PUBLIC_ACCESS }
```

**Only the first matching rule applies** — not all of them. `^/` last, `PUBLIC_ACCESS`
exceptions above the zone they punch through. A rule placed after the catch-all is dead
code that looks like protection, which is the worst kind of security bug: it reads as
covered in review.

`PUBLIC_ACCESS` is explicit and worth using: it says "open on purpose" where an absent
rule says "nobody thought about this URL".

Other keys, all matched with the path: `methods`, `ips`, `host`, `route`,
`requires_channel: https`, and `allow_if` for an expression. Verify what the installed
version accepts in
`vendor/symfony/security-bundle/DependencyInjection/MainConfiguration.php`.

`access_control` runs before the controller, so it cannot see the object being acted on.
That is the boundary between it and `#[IsGranted]`, not a limitation to work around.

## The Voter contract

```php
abstract protected function supports(string $attribute, mixed $subject): bool;

abstract protected function voteOnAttribute(
    string $attribute,
    mixed $subject,
    TokenInterface $token,
    ?Vote $vote = null,      // Symfony 7.3+ — absent before, and adding it is fatal
): bool;
```

Copy it from `vendor/symfony/security-core/Authorization/Voter/Voter.php` in the project
you are working on. A signature that does not match the parent is a `Fatal error:
Declaration of … must be compatible with …` at container compile time, which looks like
a cache problem and is not.

Two behaviours that surprise people:

- **`supports()` returning false is an abstention — and an abstention is not a grant.**
  When every voter abstains, `AffirmativeStrategy` returns its
  `allowIfAllAbstainDecisions` flag, which **defaults to `false`**; the security bundle
  keeps that default (`allow_if_all_abstain` is `defaultFalse()`). So the decision is
  **denied**. Verified by execution: one voter whose `supports()` always returns `false`
  gives `AccessDecisionManager::decide()` → `false`. The symptom of a voter that never
  matches is therefore a **403 nobody can explain**, not a silent open door — and the fix
  is to make `supports()` match the attribute and subject you are actually passing, never
  to add a second voter that grants. (What *does* fail open is a URL no `access_control`
  rule covers, which is half of why this standard insists on both mechanisms.)
- **`voteOnAttribute()` returning false denies.** There is no third value; abstaining is
  expressed only through `supports()`.

`$vote?->addReason('…')` (7.3+) attaches an explanation that surfaces in the profiler's
security panel and in `AccessDecision::getMessage()`, which `#[IsGranted]` uses as the
exception message when no `message:` is given. Written once, it saves the hour spent
adding `dump()` to a voter later. Keep reasons free of information the user should not
have — they can reach a 403 page.

## Making the voter cheap: supportsAttribute and supportsType

Every voter is asked about every decision. `CacheableVoterInterface`, which the abstract
`Voter` already implements with permissive defaults, lets Symfony skip yours entirely:

```php
public function supportsAttribute(string $attribute): bool
{
    return \in_array($attribute, [self::EDIT, self::DELETE], true);
}

public function supportsType(string $subjectType): bool
{
    return is_a($subjectType, Review::class, true);
}
```

Worth adding once a project has more than a handful of voters, or when one of them does
a query. Both answers are cached per attribute and per subject type.

## Unit-testing a voter without a kernel

A voter is a pure decision object: no kernel, no database, no `WebTestCase`. This is the
cheapest security test in the codebase and there is no excuse for skipping it.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Review;
use App\Entity\User;
use App\Security\Voter\ReviewVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(ReviewVoter::class)]
final class ReviewVoterTest extends TestCase
{
    #[Test]
    public function theAuthorMayEditTheirOwnReview(): void
    {
        $author = new User();
        $review = (new Review())->setAuthor($author);

        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(false);   // not a moderator

        $voter = new ReviewVoter($checker);
        $token = new UsernamePasswordToken($author, 'main', $author->getRoles());

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $review, [ReviewVoter::EDIT]),
        );
    }
}
```

Why it is shaped like that:

- **Call `vote()`, not `voteOnAttribute()`.** `vote()` is public, runs `supports()` first
  and returns the `ACCESS_*` constant. Testing the protected method through reflection
  skips the half of the logic that most often has the bug.
- **`UsernamePasswordToken` is a real object**, cheap to build, and avoids mocking
  `TokenInterface`. Native doubles beat hand-rolled ones.
- **`createStub()`, not `createMock()`**, for the authorization checker: there is no
  expectation on it. From PHPUnit 11 a mock with no `expects()` emits a *PHPUnit* notice,
  which `failOnPhpunitNotice="true"` turns into a failure — the recipe ships
  `failOnNotice` only, and that one covers PHP notices, not PHPUnit's (`symfony-yoandev-testing`).
- Type-hinting `AuthorizationCheckerInterface` in the voter rather than the `Security`
  helper is what makes this stub trivial.

Cover the whole truth table — author, moderator, stranger, anonymous, and each attribute
— not just the happy path. A voter is where "nominal case, boundaries, error cases,
security rules" is literally the specification.

## Testing the rule end to end

The unit test proves the decision. One functional test per protected action proves it is
*wired*: an attribute can be missing, and a voter nobody calls always says yes.

```php
$client->loginUser($someoneElse);
$client->request('GET', '/reviews/'.$review->getId().'/edit');
self::assertResponseStatusCodeSame(403);
```

Here `loginUser()` is the right tool: authentication is not what is under test.

## is_granted in Twig

```twig
{% if is_granted(constant('App\\Security\\Voter\\ReviewVoter::EDIT'), review) %}
    <a href="{{ path('review_edit', {id: review.id}) }}">{{ 'review.edit'|trans }}</a>
{% endif %}
```

`constant()` keeps the single source of truth, at the cost of a long line. The pragmatic
alternative is the literal `is_granted('REVIEW_EDIT', review)`; if you take it, the
functional test above is what stops the string and the constant drifting apart.

Hiding a button is presentation, never protection — the URL still exists. The action
itself carries `#[IsGranted]`, and `access_control` covers the zone. Twig is the third
layer, not the only one.

## Checking permissions inside a service

Services rarely need to. Authorisation belongs at the entry point, where there is a
request and a user; a service invoked from a console command or a Messenger handler has
neither, and a permission check there fails for the wrong reason.

When it is genuinely needed, inject `AuthorizationCheckerInterface` and let the caller
handle the refusal, rather than throwing `AccessDeniedException` from deep in a service
where the HTTP meaning of the exception is invisible.

## Expressions, and when to stop using them

```php
#[IsGranted(new Expression('is_granted("ROLE_MODERATOR") or user === subject.getAuthor()'), subject: 'review')]
```

Expressions work (`symfony/expression-language` required) and are fine for a one-off
combination. Once the condition needs a second clause, it is a voter: a string in an
attribute is not type-checked, not covered by PHPStan, and cannot be unit-tested. From
Symfony 7.3 `#[IsGranted]` also accepts a closure receiving an `IsGrantedContext`, which
at least gives real PHP — but the same rule applies. Any logic worth reading twice
belongs in a class with a name.
