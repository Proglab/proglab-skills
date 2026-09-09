# Durcissement, CSRF, et connexion programmatique des utilisateurs

Limitation du débit, mots de passe, limitation de débit, détails CSRF, connexion
programmatique, déconnexion, usurpation d'identité (impersonation).

## Sommaire

- [Limitation du débit de connexion](#limitation-du-débit-de-connexion)
- [Hachage des mots de passe](#hachage-des-mots-de-passe)
- [Contraintes de mot de passe sur le DTO](#contraintes-de-mot-de-passe-sur-le-dto)
- [Limitation de débit au-delà de la connexion](#limitation-de-débit-au-delà-de-la-connexion)
- [CSRF](#csrf)
- [Connexion programmatique](#connexion-programmatique)
- [Déconnexion](#déconnexion)
- [Usurpation d'identité](#usurpation-didentité)
- [Liste de vérification avant mise en production](#liste-de-vérification-avant-mise-en-production)

## Limitation du débit de connexion

```yaml
    firewalls:
        main:
            login_throttling:
                max_attempts: 5           # par identifiant + IP, par intervalle
                interval: '15 minutes'
```

Nécessite `symfony/rate-limiter` ; sans quoi le conteneur lève une exception à la
compilation, avec un message nommant le package. Deux limiters sont créés en coulisses :
un par paire identifiant+IP à `max_attempts`, et un global par IP à `5 × max_attempts`,
ce qui empêche une seule adresse d'arroser mille comptes avec une tentative chacun.

L'état du limiter vit dans le pool `cache.rate_limiter`. **Sur un déploiement
multi-serveurs, ce pool doit être partagé** (Redis, ou la base de données) — avec
l'adaptateur filesystem par défaut, chaque serveur conserve son propre compteur et la
limite effective se retrouve multipliée par le nombre de serveurs.

La limitation s'applique aux tentatives d'authentification du firewall. Elle ne couvre
**pas** la réinitialisation de mot de passe, l'inscription ou quoi que ce soit d'autre ;
cela nécessite `#[RateLimit]`.

## Hachage des mots de passe

```yaml
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'
```

`auto` se résout vers l'algorithme le plus robuste disponible sur la plateforme, et
évolue avec PHP. Combiné au rehashing à la connexion, les hashes stockés s'améliorent
sans nécessiter de migration :

```php
// dans un service — c'est le service qui flush, jamais le repository (symfony-proglab-architecture)
if ($this->hasher->needsRehash($user)) {
    $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
    $this->em->flush();
}
```

L'environnement de test abaisse le coût — la recipe l'écrit déjà, et cela doit rester à
cet endroit plutôt que d'être réinventé projet par projet :

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

Le hachage est volontairement lent ; un fichier de fixtures qui crée cinquante
utilisateurs au coût de production ajoute des minutes à chaque exécution de tests. Ne
copie jamais ces valeurs en dehors de `when@test`.

`php bin/console security:hash-password` hache une valeur depuis la ligne de commande —
la bonne façon d'amorcer un compte admin, plutôt que de coller un hash trouvé sur un site
web.

## Contraintes de mot de passe sur le DTO

Les contraintes vont sur le **DTO d'entrée**, aux côtés du reste de la validation, jamais
sur l'entité : c'est le DTO qui est validé, et l'entité ne voit jamais que le hash.

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

- **`PasswordStrength`** (Symfony 6.3+) évalue l'entropie plutôt que d'exiger une
  majuscule et un symbole. `STRENGTH_MEDIUM` est la valeur par défaut ;
  `STRENGTH_STRONG` est un plancher raisonnable pour un compte qui possède des données.
  Les règles de composition produisent `Passw0rd!`, qui obtient un mauvais score tout en
  satisfaisant toutes les checklists jamais écrites.
- **`NotCompromisedPassword`** interroge l'API range de haveibeenpwned avec les cinq
  premiers caractères du hash SHA-1 — k-anonymat, le mot de passe ne quitte jamais le
  processus. Cela coûte un appel HTTP à l'inscription et bloque les listes d'identifiants
  que les attaquants utilisent réellement.
- **`Length(max: 4096)`** n'est pas cosmétique : hacher une entrée non bornée est un déni
  de service, et le `UserBadge` de Symfony rejette pour la même raison les identifiants
  dépassant 4096 caractères.

`NotCompromisedPassword` effectue un appel réseau, il doit donc être désactivé dans
l'environnement de test, sans quoi la suite dépend d'une API externe. La recipe l'écrit ;
vérifie que c'est bien présent :

```yaml
when@test:
    framework:
        validation:
            not_compromised_password: false
```

Définis `skipOnError: true` en production si une panne de haveibeenpwned ne doit pas
bloquer l'inscription — un compromis délibéré, qu'il vaut mieux affirmer que découvrir.

## Limitation de débit au-delà de la connexion

`login_throttling` couvre le formulaire de connexion. Il laisse grand ouverts la
réinitialisation de mot de passe (un oracle d'envoi d'emails), l'inscription (comptes
spam), la recherche et l'export (requêtes coûteuses).

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

Les deux moitiés sont nécessaires. L'attribut est
`Symfony\Component\HttpKernel\Attribute\RateLimit` (**Symfony 8.1+**) et il résout le
limiter par le nom déclaré sous `framework.rate_limiter` ; en nommer un qui n'existe pas
lève une `InvalidArgumentException` au moment de la requête — l'échec apparaît dans le
trafic de production, pas en CI, sauf si un test sollicite la route. Sans `key:`, la clé
est IP client + méthode + chemin, ce qui est le bon défaut. **Une chaîne littérale passée
en `key:` est utilisée telle quelle**, jamais évaluée : `key: 'request.getClientIp()'`
donne à tous les appelants un seul bucket partagé, si bien que le limiter a l'air
configuré tout en ne protégeant rien. Encapsule-la — `new
Expression('request.getClientIp()')`. La signature accepte aussi une `\Closure`, mais une
closure écrite dans un attribut provoque une erreur fatale à la compilation avant **PHP
8.5** (*Constant expression contains invalid operations*), donc sur PHP 8.2–8.4 la forme
`Expression` est la seule disponible.
Dépasser la limite lève une `TooManyRequestsHttpException` avec un en-tête `Retry-After`.

**Avant Symfony 8.1**, l'attribut n'existe pas. La règle reste la même, seul le moyen
diffère : injecte la factory générée et consomme explicitement.

```php
public function __construct(
    private readonly RateLimiterFactoryInterface $passwordResetLimiter,
) {}

// dans l'action
if (!$this->passwordResetLimiter->create($request->getClientIp())->consume()->isAccepted()) {
    throw new TooManyRequestsHttpException();
}
```

**Le nom de l'argument, c'est le câblage.** Le limiter déclaré `password_reset` est
aliasé vers `$passwordResetLimiter` ; renomme la propriété et l'autowiring échoue avec
« cannot autowire … no such service ». Confirme le nom exact avec `php bin/console
debug:autowiring RateLimiter`. `RateLimiterFactoryInterface` existe depuis Symfony 7.3 —
avant cela, type la classe concrète `RateLimiterFactory`.

Même mise en garde multi-serveurs que pour le throttling : le pool de stockage doit être
partagé, sans quoi la limite s'applique par serveur.

## CSRF

Le composant Form ajoute et valide `_token` sur chaque formulaire. En dehors des
formulaires :

```php
#[IsCsrfTokenValid('delete-review', tokenKey: '_token')]
#[Route('/{id}', name: 'delete', methods: ['POST'])]
public function delete(Review $review): Response
```

L'attribut existe depuis Symfony 7.1 ; avant cela, appelle
`$this->isCsrfTokenValid('delete-review', $request->request->getString('_token'))` et
lève l'exception toi-même. Depuis la 7.4, il peut lire le token depuis la query string
ou un en-tête via `tokenSource:` ; c'est la forme en-tête qu'utilise un appel AJAX.

**Le CSRF stateless** est le défaut dans les squelettes récents
(`framework.csrf_protection.stateless_token_ids`, Symfony 7.2+). Il remplace un token
stocké en session par un cookie double-submit généré dans le navigateur — ce qui signifie
qu'**il dépend de JavaScript** : le controller Stimulus `csrf_protection` fourni dans
`assets/controllers/` doit être chargé. Retire-le, ou sers le formulaire à un client
sans JS, et chaque soumission échoue à la validation avec pourtant un token d'apparence
valide dans le payload. Si un formulaire doit fonctionner sans JavaScript, retire son id
de token de `stateless_token_ids` et il retombe sur le mécanisme adossé à la session.

**Quand une API JSON a besoin de CSRF :** chaque fois que le navigateur l'authentifie par
cookie. L'attaque fonctionne parce que le navigateur attache les cookies aux requêtes
cross-site de lui-même ; il n'attache jamais d'en-tête `Authorization`. Une API à bearer
token stateless n'a donc besoin d'aucun token CSRF, et un endpoint JSON adossé à une
session, appelé par ton propre front-end, en a besoin exactement comme un formulaire.
`SameSite=Lax` (le défaut du cookie de session Symfony) atténue le risque sans le
remplacer : il ne couvre pas les navigations POST de premier niveau dans tous les
navigateurs, et une politique CORS mal configurée l'annule.

## Connexion programmatique

Après une inscription, ou à l'issue d'un flux magic-link, connecte l'utilisateur sans
passer par le formulaire :

```php
public function __construct(private readonly Security $security) {}

$this->security->login($user);                       // firewall main, authenticator par défaut
$this->security->login($user, 'form_login', 'main'); // explicite
```

`Symfony\Bundle\SecurityBundle\Security::login()` renvoie un `?Response` — non nul quand
l'authenticator dispose d'un success handler qui en a produit un (une redirection) ;
retourne-le le cas échéant. Elle déclenche les vrais événements d'authentification, ce
qui la distingue de `loginUser()` : migration de session, remember-me et listeners de
connexion se déclenchent tous.

Ne fais cela qu'immédiatement après que le compte a été créé ou vérifié, dans la même
requête. Connecter quelqu'un à partir d'un identifiant issu d'une saisie utilisateur,
c'est l'erreur du `userId` dans le payload déguisée en firewall.

## Déconnexion

Configure-la ; ne l'implémente pas.

```yaml
            logout:
                path: app_logout
                target: app_home
```

La route doit exister et se trouver à l'intérieur du firewall, mais son contrôleur n'est
jamais exécuté — le firewall intercepte la requête. Une méthode de contrôleur vide, avec
un commentaire l'indiquant, est la forme idiomatique, et le squelette la génère telle
quelle.

`Security::logout()` existe pour le cas programmatique (la suppression de son propre
compte, par exemple). Elle valide le token CSRF par défaut ; ne passe `false` que
lorsqu'il n'y a aucun token à valider. Pour nettoyer à la déconnexion — révoquer des
tokens d'API, vider un cache — enregistre un listener sur `LogoutEvent` avec
`#[AsEventListener]`, qui est un événement du framework et donc un listener légitime
selon ce standard.

## Usurpation d'identité

```yaml
        main:
            switch_user: true          # ou : { role: ROLE_ALLOWED_TO_SWITCH }
```

Ensuite `/any/url?_switch_user=reader@example.com`, et `?_switch_user=_exit` pour revenir
en arrière.

Protège-la délibérément : le rôle par défaut est `ROLE_ALLOWED_TO_SWITCH`, et il doit
être accordé via `role_hierarchy` au seul personnel support — jamais fondu dans
`ROLE_ADMIN` sans y réfléchir, car c'est la capacité d'agir en tant que n'importe qui.

Deux points à bien traiter :

- **`IS_IMPERSONATOR`** est la vérification pour « cette session est en train d'usurper
  une identité ». Utilise-la pour afficher une bannière persistante (`{% if
  is_granted('IS_IMPERSONATOR') %}`) — un admin en usurpation qui l'oublie est un admin
  sur le point d'envoyer un email en se faisant passer pour quelqu'un d'autre.
- **Les actions destructrices devraient refuser l'usurpation.** Supprimer un compte ou
  changer un mot de passe en cours d'usurpation est indistinguable, dans le journal
  d'audit, de la même action effectuée par le véritable utilisateur.
  `$this->isGranted('IS_IMPERSONATOR')` dans l'action, ou une vérification dans le voter,
  est l'endroit où dire non.

Le token d'usurpation est un `SwitchUserToken` ; `getOriginalToken()->getUser()` donne le
véritable acteur, celui qui doit figurer dans le journal d'audit.

## Liste de vérification avant mise en production

| Vérification | Commande ou emplacement |
|---|---|
| Chaque firewall a un authenticator et la bonne valeur de `stateless` | `php bin/console debug:firewall` |
| `access_control` possède un catch-all, et il est en dernier | `config/packages/security.yaml` |
| Limitation du débit de connexion activée, stockage du rate limiter partagé entre serveurs | firewall + `framework.rate_limiter` |
| Contraintes de mot de passe sur les DTO d'inscription **et** de changement de mot de passe | `src/Dto/Input/` |
| `not_compromised_password: false` uniquement sous `when@test` | `config/packages/validator.yaml` |
| Chaque action protégée a un test fonctionnel vérifiant un 403 pour le mauvais utilisateur | `tests/Controller/` |
| Secrets dans `.env.local` et l'environnement serveur, jamais committés | `.gitignore` |
