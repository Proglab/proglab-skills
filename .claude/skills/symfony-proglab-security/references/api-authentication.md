# Authentification API avec l'authenticator natif access_token

Des tokens opaques révocables en base de données, un firewall stateless,
`Authorization: Bearer`. Aucun bundle.

## Sommaire

- [Pourquoi natif, et pourquoi pas LexikJWT](#pourquoi-natif-et-pourquoi-pas-lexikjwt)
- [Le firewall](#le-firewall)
- [L'entité token](#lentité-token)
- [Émettre un token](#émettre-un-token)
- [Le handler](#le-handler)
- [Révocation, expiration et rotation](#révocation-expiration-et-rotation)
- [Erreurs qui ressemblent à des bugs](#erreurs-qui-ressemblent-à-des-bugs)
- [La tester](#la-tester)
- [OIDC, OAuth2 et CAS](#oidc-oauth2-et-cas)

## Pourquoi natif, et pourquoi pas LexikJWT

Symfony propose l'authentification par bearer token RFC 6750 depuis la **6.2** :
l'authenticator `access_token`, un extracteur de token, et un handler que tu écris
toi-même. Un seul bloc de configuration et une seule classe.

**LexikJWTAuthenticationBundle n'est délibérément pas utilisé.** Pas parce qu'il est
mauvais — il est bien maintenu — mais à cause de ce qu'est un JWT par nature. Un JWT est
*autoportant* : le serveur le valide en vérifiant une signature, sans avoir besoin d'une
recherche. C'est sa force, et c'est aussi son problème :

- **Un JWT ne peut pas être révoqué.** Se déconnecter, changer de mot de passe,
  désactiver un compte : chaque token émis reste valide jusqu'à son expiration. La
  solution habituelle est une denylist en base de données — vérifiée à chaque requête,
  c'est-à-dire exactement la recherche que le JWT était censé éviter, avec en prime un
  bundle et une paire de clés.
- **Le payload est lisible par quiconque le détient.** Du Base64, pas du chiffrement. Les
  rôles et identifiants contenus dans un JWT sont une information publique.
- Il ajoute une dépendance, une paire de clés à générer, distribuer et faire tourner, et
  un second modèle d'authentification à apprendre, en échange d'une propriété stateless
  dont un monolithe adossé à une base de données n'a pas besoin.

Un **token opaque** — des octets aléatoires, dénués de sens en eux-mêmes, recherchés dans
une table — se révoque en supprimant une ligne, ne fuite rien, et ne nécessite aucun
bundle. Le coût est une requête indexée par requête, sur une base de données que la
requête va de toute façon solliciter.

Utilise des JWT quand un token doit être validé par un service qui ne peut pas atteindre
ta base de données : l'API d'une autre équipe, une passerelle en périphérie (edge
gateway). C'est un cas réel, mais ce n'est pas celui-ci.

## Le firewall

```yaml
security:
    firewalls:
        api:
            pattern: ^/api
            stateless: true
            provider: app_users
            access_token:
                token_handler: App\Security\ApiTokenHandler
                token_extractors: header        # la valeur par défaut : Authorization: Bearer …
        main:
            lazy: true
            provider: app_users
            form_login: { login_path: app_login, check_path: app_login }

    access_control:
        - { path: ^/api/login, roles: PUBLIC_ACCESS }
        - { path: ^/api, roles: ROLE_USER }
```

**L'ordre compte.** Les firewalls sont évalués de haut en bas et le premier `pattern` qui
correspond l'emporte, donc le firewall API doit venir avant le catch-all `main`. Place
`main` en premier et chaque requête `/api` passera par le firewall de session à la place,
renvoyant un 302 vers le formulaire de connexion là où le client attendait un 401.

`stateless: true` est ce qui en fait un firewall d'API : aucune session n'est créée,
aucun cookie n'est posé, et le token est revalidé à chaque requête. Sans cela, Symfony
stocke le token authentifié dans la session, et l'API soi-disant « stateless » se met à
dépendre d'un cookie — ce qui réintroduit une exposition au CSRF.

Les alias d'extracteurs intégrés sont `header` (par défaut, `Authorization: Bearer …`),
`query_string` et `request_body`. Préfère l'en-tête : les query strings finissent dans
les logs d'accès, les logs du proxy et les en-têtes `Referer`. N'importe quel service
implémentant `AccessTokenExtractorInterface` peut être nommé à la place.

## L'entité token

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

    /** SHA-256 du token ; le token lui-même n'est jamais stocké. */
    #[ORM\Column(length: 64)]
    private string $hash = '';

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(length: 100)]
    private string $label = '';        // « Pipeline CI », « Application mobile » — à destination de l'utilisateur
}
```

**Stocke le hash, jamais le token.** Un token d'API est un credential au même titre
qu'un mot de passe : un dump de base de données fuité contenant des tokens en clair,
c'est un ensemble de sessions actives fuité. SHA-256 suffit ici, contrairement à bcrypt :
la valeur est constituée de 32 octets aléatoires, pas d'un mot de passe choisi par un
humain, il n'y a donc rien à attaquer par force brute, et la recherche doit rester une
simple requête indexée plutôt qu'une comparaison de hash par ligne.

## Émettre un token

La génération appartient à un service, la valeur en clair étant renvoyée une fois et
jamais plus :

```php
final class ApiTokenIssuer
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return string le token en clair — affiché à l'utilisateur une seule fois */
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

`random_bytes()`, pas `uniqid()`, `rand()` ni `md5(time())` — ces fonctions sont
prévisibles, et un token prévisible n'est pas un token. Le préfixe `rop_` ne coûte rien
et rend les tokens fuités détectables par les scanners de secrets.

## Le handler

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

Points qui comptent :

- L'interface n'a qu'une seule méthode, `getUserBadgeFrom(string): UserBadge`.
  Vérifie-le dans
  `vendor/symfony/security-http/AccessToken/AccessTokenHandlerInterface.php`.
- `#[\SensitiveParameter]` empêche le token d'apparaître dans les stack traces. Sans lui,
  la valeur se retrouve sur la page d'exception, dans `var/log`, et dans le tracker
  d'erreurs éventuellement installé.
- **Une seule `BadCredentialsException` pour chaque échec** — inconnu, expiré, révoqué.
  Un message distinct par cas indique à un attaquant quels tokens existent. La réponse
  côté utilisateur reste 401 dans tous les cas.
- Renvoyer un `UserBadge` avec seulement un identifiant fait charger l'utilisateur par le
  provider du firewall. Ne passe un callable `$userLoader` en second argument que
  lorsque l'utilisateur ne provient pas du provider configuré.
- `ClockInterface` plutôt que `new \DateTimeImmutable()`, pour que l'expiration reste
  testable avec `MockClock`.

## Révocation, expiration et rotation

Tout l'intérêt des tokens opaques : révoquer se résume à `UPDATE api_token SET
revoked_at = NOW()`. Déconnexion, changement de mot de passe et suspension de compte
devraient chacun révoquer les tokens du propriétaire — en appelant explicitement le
service depuis le service qui effectue l'action, comme toute autre conséquence traitée
selon ce standard.

Chaque token reçoit un `expiresAt`. « N'expire jamais » signifie qu'un token collé dans
une variable CI en 2021 est toujours actif. Une commande planifiée supprime les lignes
expirées afin que la table ne grossisse pas indéfiniment.

## Erreurs qui ressemblent à des bugs

| Symptôme | Cause |
|---|---|
| 401 sur chaque requête, token correct | Le firewall API est situé après `main`, donc `/api` ne l'atteint jamais. Vérifie `debug:firewall` |
| 302 vers `/login` au lieu de 401 | Même cause : le firewall de session a matché et son entry point redirige |
| Service `token_handler` introuvable | Le handler n'est pas autowiré (mauvais namespace), ou `token_handler` pointe vers une classe non enregistrée comme service |
| Fonctionne avec `?access_token=` mais pas avec l'en-tête | Un proxy retire `Authorization`. Sur Apache, `SetEnvIf Authorization … ` ou `CGIPassAuth On` |
| Chaque requête ouvre une session | `stateless: true` manquant sur le firewall |
| L'utilisateur est authentifié mais n'a aucun rôle | `UserBadge` a renvoyé un identifiant que le provider ne peut pas charger, et un `$userLoader` a silencieusement construit un utilisateur différent |

## La tester

Le handler est un service ordinaire : teste-le unitairement contre un repository stubbé
et un `MockClock`, en couvrant les cas valide, inconnu, expiré et révoqué. Ce sont quatre
règles, et les quatre sont des règles de sécurité.

Le firewall lui-même mérite un test fonctionnel par résultat possible, en sollicitant un
vrai endpoint :

```php
$client->request('GET', '/api/reviews', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$plain]);
self::assertResponseIsSuccessful();

$client->request('GET', '/api/reviews', server: ['HTTP_AUTHORIZATION' => 'Bearer wrong']);
self::assertResponseStatusCodeSame(401);
```

N'utilise **pas** `loginUser()` ici. Il injecte un token dans le token storage et ne
fait jamais tourner l'authenticator, donc il ne prouve rien sur ce qui est réellement
testé — le firewall et le handler. `loginUser()` sert à tester ce qui se passe *après*
l'authentification.

## OIDC, OAuth2 et CAS

Le même authenticator `access_token` gère nativement les tokens fédérés ; seul le
`token_handler` change. Symfony fournit `oidc` (validation locale de la signature d'un ID
token OIDC), `oidc_user_info` (appel de l'endpoint userinfo du provider), `oauth2`
(introspection de token RFC 7662) et `cas`. Vérifie ce que propose la version installée :

```bash
ls vendor/symfony/security-bundle/DependencyInjection/Security/AccessToken/
```

Donc « il nous faut se connecter avec Google / Keycloak / un fournisseur d'identité »
n'appelle pas non plus un bundle — cela appelle un `token_handler` différent, sous le
même firewall. `oidc` et `oidc_user_info` existent depuis Symfony 6.3, `cas` depuis la
7.1, `oauth2` depuis la 7.3.
