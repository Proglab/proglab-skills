# Hardening, CSRF, and logging users in from code

Throttling, passwords, rate limiting, CSRF details, programmatic login, logout,
impersonation.

## Contents

- [Login throttling](#login-throttling)
- [Password hashing](#password-hashing)
- [Password constraints on the DTO](#password-constraints-on-the-dto)
- [Rate limiting beyond login](#rate-limiting-beyond-login)
- [CSRF](#csrf)
- [Programmatic login](#programmatic-login)
- [Logout](#logout)
- [Impersonation](#impersonation)
- [Checklist before shipping](#checklist-before-shipping)

## Login throttling

```yaml
    firewalls:
        main:
            login_throttling:
                max_attempts: 5           # per identifier + IP, per interval
                interval: '15 minutes'
```

Needs `symfony/rate-limiter`; without it the container throws at build time with a
message naming the package. Two limiters are created under the hood: one per
identifier+IP pair at `max_attempts`, and a global one per IP at `5 × max_attempts`,
which is what stops a single address spraying one attempt each across a thousand
accounts.

The limiter state lives in the `cache.rate_limiter` pool. **On a multi-server deploy
that pool must be shared** (Redis, or the database) — with the default filesystem
adapter each server keeps its own count and the effective limit is multiplied by the
number of servers.

Throttling applies to the firewall's authentication attempts. It does **not** cover
password reset, registration or anything else; those need `#[RateLimit]`.

## Password hashing

```yaml
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'
```

`auto` resolves to the strongest algorithm available on the platform and changes as PHP
does. Combined with rehashing at login, stored hashes improve without a migration:

```php
// in a service — the service flushes, not the repository (symfony-architecture)
if ($this->hasher->needsRehash($user)) {
    $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
    $this->em->flush();
}
```

The test environment lowers the cost — the recipe already writes this, and it belongs
there rather than being invented per project:

```yaml
when@test:
    security:
        password_hashers:
            Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface:
                algorithm: auto
                cost: 4
                time_cost: 3
                memory_cost: 10
```

Hashing is deliberately slow; a fixtures file creating fifty users at production cost
adds minutes to every test run. Never copy these values outside `when@test`.

`php bin/console security:hash-password` hashes a value from the command line — the
correct way to seed an admin account, instead of pasting a hash from a website.

## Password constraints on the DTO

Constraints go on the **input DTO**, alongside the rest of the validation, never on the
entity: the DTO is what gets validated, and the entity only ever sees the hash.

```php
final class RegistrationInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email = '',

        #[Assert\NotBlank]
        #[Assert\Length(min: 12, max: 4096)]
        #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_STRONG)]
        #[Assert\NotCompromisedPassword]
        public string $plainPassword = '',
    ) {
    }
}
```

- **`PasswordStrength`** (Symfony 6.3+) scores entropy rather than demanding one capital
  and one symbol. `STRENGTH_MEDIUM` is the default; `STRENGTH_STRONG` is a reasonable
  floor for an account that owns data. Composition rules produce `Passw0rd!`, which
  scores badly and satisfies every checklist ever written.
- **`NotCompromisedPassword`** queries the haveibeenpwned range API with the first five
  characters of the SHA-1 hash — k-anonymity, the password never leaves the process.
  It costs one HTTP call at registration and blocks the credential lists attackers
  actually use.
- **`Length(max: 4096)`** is not cosmetic: hashing an unbounded input is a denial of
  service, and Symfony's `UserBadge` rejects identifiers beyond 4096 characters for the
  same reason.

`NotCompromisedPassword` makes a network call, so it must be off in the test environment
or the suite depends on an external API. The recipe writes this; check it is there:

```yaml
when@test:
    framework:
        validation:
            not_compromised_password: false
```

Set `skipOnError: true` in production if a haveibeenpwned outage must not block
registration — a deliberate trade, worth stating rather than discovering.

## Rate limiting beyond login

`login_throttling` covers the login form. It leaves password reset (an email-sending
oracle), registration (spam accounts), search and export (expensive queries) wide open.

```yaml
framework:
    rate_limiter:
        password_reset:
            policy: sliding_window
            limit: 3
            interval: '1 hour'
```

```php
#[RateLimit('password_reset')]
#[Route('/password/reset', name: 'password_reset', methods: ['POST'])]
public function requestReset(#[MapRequestPayload] ResetInput $input): Response
```

Both halves are required. The attribute is
`Symfony\Component\HttpKernel\Attribute\RateLimit` (**Symfony 8.1+**) and it resolves the
limiter by the name declared under `framework.rate_limiter`; naming one that does not
exist throws `InvalidArgumentException` at request time — the failure appears in
production traffic, not in CI, unless a test hits the route. With no `key:` the key is
client IP + method + path, which is the right default. **A plain string passed as `key:`
is used literally**, not evaluated: `key: 'request.getClientIp()'` gives every caller one
shared bucket, so the limiter looks configured and protects nothing. Wrap it —
`new Expression('request.getClientIp()')`. The signature also accepts a `\Closure`, but a
closure written inside an attribute is a compile-time fatal before **PHP 8.5**
(*Constant expression contains invalid operations*), so on PHP 8.2–8.4 the `Expression`
form is the only one available.
Exceeding the limit throws `TooManyRequestsHttpException` with a `Retry-After` header.

**Before Symfony 8.1** the attribute does not exist. The rule is unchanged, the means
differ: inject the generated factory and consume explicitly.

```php
public function __construct(
    private readonly RateLimiterFactoryInterface $passwordResetLimiter,
) {}

// in the action
if (!$this->passwordResetLimiter->create($request->getClientIp())->consume()->isAccepted()) {
    throw new TooManyRequestsHttpException();
}
```

**The argument name is the wiring.** The limiter declared as `password_reset` is aliased
to `$passwordResetLimiter`; rename the property and autowiring fails with "cannot
autowire … no such service". Confirm the exact name with `php bin/console debug:autowiring
RateLimiter`. `RateLimiterFactoryInterface` exists from Symfony 7.3 — before that,
type-hint the concrete `RateLimiterFactory`.

Same multi-server caveat as throttling: the storage pool must be shared, or the limit is
per server.

## CSRF

The Form component adds and validates `_token` on every form. Outside forms:

```php
#[IsCsrfTokenValid('delete-review', tokenKey: '_token')]
#[Route('/{id}', name: 'delete', methods: ['POST'])]
public function delete(Review $review): Response
```

The attribute exists from Symfony 7.1; before that, call
`$this->isCsrfTokenValid('delete-review', $request->request->getString('_token'))` and
throw. From 7.4 it can read the token from the query string or a header via
`tokenSource:`; the header form is what an AJAX call uses.

**Stateless CSRF** is the default in recent skeletons (`framework.csrf_protection.
stateless_token_ids`, Symfony 7.2+). It replaces a session-stored token with a
double-submit cookie generated in the browser — which means **it depends on JavaScript**:
the `csrf_protection` Stimulus controller shipped in `assets/controllers/` must be
loaded. Remove it, or serve the form to a client without JS, and every submission fails
validation with a valid-looking token in the payload. If a form must work without
JavaScript, drop its token id from `stateless_token_ids` and it falls back to the
session-backed mechanism.

**When a JSON API needs CSRF:** whenever the browser authenticates it with a cookie. The
attack works because the browser attaches cookies to cross-site requests by itself; it
does not attach an `Authorization` header. So a stateless bearer-token API needs no CSRF
token, and a session-backed JSON endpoint called by your own front end needs one exactly
like a form. `SameSite=Lax` (Symfony's default session cookie) mitigates but does not
replace it: it does not cover top-level POST navigations in every browser, and a
misconfigured CORS policy undoes it.

## Programmatic login

After registration, or after a magic-link flow, log the user in without a round trip
through the form:

```php
public function __construct(private readonly Security $security) {}

$this->security->login($user);                       // main firewall, default authenticator
$this->security->login($user, 'form_login', 'main'); // explicit
```

`Symfony\Bundle\SecurityBundle\Security::login()` returns a `?Response` — non-null when
the authenticator has a success handler that produced one (a redirect); return it if so.
It runs the real authentication events, which is why it is not `loginUser()`: session
migration, remember-me and login listeners all fire.

Do this only immediately after the account has been created or verified in the same
request. Logging someone in from an identifier taken from user input is the payload-`userId`
mistake wearing a firewall.

## Logout

Configure it; do not implement it.

```yaml
            logout:
                path: app_logout
                target: app_home
```

The route must exist and be inside the firewall, but its controller is never executed —
the firewall intercepts the request. An empty controller method with a comment saying so
is the idiomatic shape, and the skeleton generates it.

`Security::logout()` exists for the programmatic case (deleting one's own account, for
instance). It validates the CSRF token by default; pass `false` only when there is no
token to validate. To clean up on logout — revoking API tokens, clearing a cache —
register a listener on `LogoutEvent` with `#[AsEventListener]`, which is a framework
event and therefore a legitimate listener under this standard.

## Impersonation

```yaml
        main:
            switch_user: true          # or: { role: ROLE_ALLOWED_TO_SWITCH }
```

Then `/any/url?_switch_user=reader@example.com`, and `?_switch_user=_exit` to return.

Guard it deliberately: the default role is `ROLE_ALLOWED_TO_SWITCH`, and it should be
granted through `role_hierarchy` to support staff only — never folded into `ROLE_ADMIN`
without thinking, because it is the ability to act as anyone.

Two things to get right:

- **`IS_IMPERSONATOR`** is the check for "this session is impersonating". Use it to show
  a persistent banner (`{% if is_granted('IS_IMPERSONATOR') %}`) — an impersonating admin
  who forgets is an admin about to send an email as someone else.
- **Destructive actions should refuse impersonation.** Deleting an account or changing a
  password while impersonating is indistinguishable, in the audit log, from the real user
  doing it. `$this->isGranted('IS_IMPERSONATOR')` in the action, or a check in the voter,
  is the place to say no.

The impersonation token is a `SwitchUserToken`; `getOriginalToken()->getUser()` gives the
real actor, which is what belongs in the audit trail.

## Checklist before shipping

| Check | Command or place |
|---|---|
| Every firewall has an authenticator and the right `stateless` value | `php bin/console debug:firewall` |
| `access_control` has a catch-all, and it is last | `config/packages/security.yaml` |
| Login throttling on, rate limiter storage shared across servers | firewall + `framework.rate_limiter` |
| Password constraints on registration **and** password-change DTOs | `src/Dto/Input/` |
| `not_compromised_password: false` under `when@test` only | `config/packages/validator.yaml` |
| Every protected action has a functional test asserting 403 for the wrong user | `tests/Controller/` |
| Secrets in `.env.local` and the server environment, never committed | `.gitignore` |
