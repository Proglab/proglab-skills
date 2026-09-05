# API authentication with the native access_token authenticator

Revocable opaque tokens in the database, a stateless firewall, `Authorization: Bearer`.
No bundle.

## Contents

- [Why native, and why not LexikJWT](#why-native-and-why-not-lexikjwt)
- [The firewall](#the-firewall)
- [The token entity](#the-token-entity)
- [Issuing a token](#issuing-a-token)
- [The handler](#the-handler)
- [Revocation, expiry and rotation](#revocation-expiry-and-rotation)
- [Errors that look like bugs](#errors-that-look-like-bugs)
- [Testing it](#testing-it)
- [OIDC, OAuth2 and CAS](#oidc-oauth2-and-cas)

## Why native, and why not LexikJWT

Symfony has shipped RFC 6750 bearer-token authentication since **6.2**: the
`access_token` authenticator, a token extractor, and a handler you write. It is one
config block and one class.

**LexikJWTAuthenticationBundle is deliberately not used.** Not because it is bad — it is
well maintained — but because of what a JWT is. A JWT is *self-contained*: the server
validates it by checking a signature, without a lookup. That is the feature, and it is
also the problem:

- **A JWT cannot be revoked.** Log out, change a password, disable an account, and every
  issued token stays valid until it expires. The usual fix is a denylist in the
  database — checked on every request, which is the lookup JWT existed to avoid, with a
  bundle and a key pair on top.
- **The payload is readable by anyone holding it.** Base64, not encryption. Roles and
  identifiers in a JWT are public information.
- It adds a dependency, a key pair to generate, distribute and rotate, and a second
  authentication model to learn, in exchange for a stateless property that a
  monolith backed by a database does not need.

An **opaque token** — random bytes, meaningless on its own, looked up in a table — is
revocable by deleting a row, leaks nothing, and needs no bundle. The cost is one indexed
query per request, on a database the request is already going to hit.

Use JWTs when a token must be validated by a service that cannot reach your database:
another team's API, an edge gateway. That is a real case, and it is not this one.

## The firewall

```yaml
security:
    firewalls:
        api:
            pattern: ^/api
            stateless: true
            provider: app_users
            access_token:
                token_handler: App\Security\ApiTokenHandler
                token_extractors: header        # the default: Authorization: Bearer …
        main:
            lazy: true
            provider: app_users
            form_login: { login_path: app_login, check_path: app_login }

    access_control:
        - { path: ^/api/login, roles: PUBLIC_ACCESS }
        - { path: ^/api, roles: ROLE_USER }
```

**Order matters.** Firewalls are matched top to bottom and the first `pattern` that
matches wins, so the API firewall must come before the catch-all `main`. Put `main`
first and every `/api` request goes through the session firewall instead, returning a
302 to the login form where the client expected a 401.

`stateless: true` is what makes it an API firewall: no session is created, no cookie is
set, and the token is re-validated on every request. Without it Symfony stores the
authenticated token in the session, and the "stateless" API starts depending on a
cookie — which reintroduces CSRF exposure.

Built-in extractor aliases are `header` (default, `Authorization: Bearer …`),
`query_string` and `request_body`. Prefer the header: query strings end up in access
logs, proxy logs and `Referer` headers. Any service implementing
`AccessTokenExtractorInterface` can be named instead.

## The token entity

```php
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\UniqueConstraint(fields: ['hash'])]
class ApiToken
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'apiTokens')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    /** SHA-256 of the token; the token itself is never stored. */
    #[ORM\Column(length: 64)]
    private string $hash = '';

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(length: 100)]
    private string $label = '';        // "CI pipeline", "Mobile app" — for the user
}
```

**Store the hash, never the token.** An API token is a credential exactly like a
password: a leaked database dump with plaintext tokens is a leaked set of live sessions.
SHA-256 is enough here and bcrypt is not: the value is 32 random bytes, not a
human-chosen password, so there is nothing to brute-force and the lookup must be a
single indexed query rather than one hash comparison per row.

## Issuing a token

The generation belongs in a service, with the plaintext returned once and never again:

```php
final class ApiTokenIssuer
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return string the plaintext token — shown to the user once */
    public function issue(User $owner, string $label, \DateTimeImmutable $expiresAt): string
    {
        $plain = 'rop_'.bin2hex(random_bytes(32));

        $this->tokens->add(
            (new ApiToken())
                ->setOwner($owner)
                ->setHash(hash('sha256', $plain))
                ->setLabel($label)
                ->setExpiresAt($expiresAt)
        );
        $this->em->flush();

        return $plain;
    }
}
```

`random_bytes()`, not `uniqid()`, `rand()` or `md5(time())` — those are predictable, and
a predictable token is no token. The `rop_` prefix costs nothing and makes leaked tokens
detectable by secret scanners.

## The handler

```php
<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\ApiTokenRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->tokens->findOneByHash(hash('sha256', $accessToken));

        if (null === $token || null !== $token->getRevokedAt() || $token->getExpiresAt() <= $this->clock->now()) {
            throw new BadCredentialsException('Invalid API token.');
        }

        return new UserBadge($token->getOwner()->getUserIdentifier());
    }
}
```

Points that matter:

- The interface has exactly one method, `getUserBadgeFrom(string): UserBadge`. Verify it
  in `vendor/symfony/security-http/AccessToken/AccessTokenHandlerInterface.php`.
- `#[\SensitiveParameter]` keeps the token out of stack traces. Without it, the value
  ends up in the exception page, in `var/log`, and in whatever error tracker is
  installed.
- **One `BadCredentialsException` for every failure** — unknown, expired, revoked. A
  distinct message per case tells an attacker which tokens exist. The user-facing answer
  is 401 either way.
- Returning a `UserBadge` with only an identifier makes the firewall's provider load the
  user. Pass a `$userLoader` callable as the second argument only when the user does not
  come from the configured provider.
- `ClockInterface` rather than `new \DateTimeImmutable()`, so expiry is testable with
  `MockClock`.

## Revocation, expiry and rotation

The whole point of opaque tokens: revoking is `UPDATE api_token SET revoked_at = NOW()`.
Logout, password change and account suspension should each revoke the owner's tokens —
call the service explicitly from the service that performs the action, the way any other
consequence is handled in this standard.

Every token gets an `expiresAt`. "Never expires" means a token pasted into a CI variable
in 2021 is still live. A scheduled command deletes rows past expiry so the table does
not grow without bound.

## Errors that look like bugs

| Symptom | Cause |
|---|---|
| 401 on every request, correct token | The API firewall is below `main`, so `/api` never reaches it. Check `debug:firewall` |
| 302 to `/login` instead of 401 | Same cause: the session firewall matched and its entry point redirects |
| `token_handler` service not found | The handler is not autowired (wrong namespace) or `token_handler` was given a class that is not registered as a service |
| Works with `?access_token=` but not with the header | A proxy strips `Authorization`. On Apache, `SetEnvIf Authorization … ` or `CGIPassAuth On` |
| Every request opens a session | `stateless: true` missing on the firewall |
| The user is authenticated but has no roles | `UserBadge` returned an identifier the provider cannot load, and a `$userLoader` silently built a different user |

## Testing it

The handler is a plain service: unit-test it against a stubbed repository and a
`MockClock`, covering valid, unknown, expired and revoked. Those are four rules, and all
four are security rules.

The firewall itself deserves one functional test per outcome, hitting a real endpoint:

```php
$client->request('GET', '/api/reviews', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$plain]);
self::assertResponseIsSuccessful();

$client->request('GET', '/api/reviews', server: ['HTTP_AUTHORIZATION' => 'Bearer wrong']);
self::assertResponseStatusCodeSame(401);
```

Do **not** use `loginUser()` here. It injects a token into the token storage and never
runs the authenticator, so it proves nothing about the thing being tested — the firewall
and the handler. `loginUser()` is for testing what happens *after* authentication.

## OIDC, OAuth2 and CAS

The same `access_token` authenticator handles federated tokens natively; only the
`token_handler` changes. Symfony ships `oidc` (validating an OIDC ID token's signature
locally), `oidc_user_info` (calling the provider's userinfo endpoint), `oauth2` (RFC 7662
token introspection) and `cas`. Check what the installed version offers:

```bash
ls vendor/symfony/security-bundle/DependencyInjection/Security/AccessToken/
```

So "we need to log in with Google / Keycloak / an identity provider" does not call for a
bundle either — it calls for a different `token_handler` under the same firewall.
`oidc` and `oidc_user_info` exist from Symfony 6.3, `cas` from 7.1, `oauth2` from 7.3.
