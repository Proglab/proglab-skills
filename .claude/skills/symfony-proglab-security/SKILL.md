---
name: symfony-proglab-security
description: >-
  Authentification et autorisation sur un projet Symfony : l'entité User et les
  fournisseurs d'utilisateurs (user providers), le firewall, l'authentification
  access_token native pour une API JSON avec des tokens opaques révocables, les rôles et
  role_hierarchy pour les zones, les Voters pour tout ce qui dépend de l'objet visé,
  access_control combiné à #[IsGranted], et le durcissement (hardening) — limitation du
  débit de connexion, hachage des mots de passe, vérification de la robustesse et des
  fuites, limitation de débit, CSRF. À utiliser dès qu'on demande d'ajouter une connexion
  ou un formulaire de connexion, d'enregistrer des utilisateurs, de protéger une page ou
  une route, de restreindre ou sécuriser mon API, d'ajouter une authentification par clé
  API ou par token, d'ajouter des rôles ou une zone d'administration, de déterminer qui
  peut faire quoi, de faire en sorte que seul l'auteur puisse modifier ou supprimer son
  propre avis, de cacher un bouton sauf si l'utilisateur peut cliquer dessus, de vérifier
  des permissions, d'écrire un Voter, de connecter un utilisateur depuis le code, de le
  déconnecter, d'usurper l'identité d'un autre utilisateur (impersonation), de stopper les
  tentatives de force brute sur le formulaire de connexion, ou de corriger « Cannot
  resolve argument $user », « Full authentication is required », « Access Denied »,
  « Invalid CSRF token », ou un 401 sur un endpoint qui devrait être ouvert. À utiliser
  aussi avant d'écrire tout endpoint qui lit l'utilisateur courant.
---

# Sécurité

> **Niveau : socle dès que l'application a des utilisateurs** — la vérification du câblage, un firewall, des rôles pour les zones et des voters pour les objets. Le tableau de durcissement est également socle ; `#[RateLimit]` au-delà de la connexion est à la demande.

Qui est à l'origine de la requête, et ce que cela lui permet de faire. Deux questions,
deux mécanismes — et c'est la première que l'on a tendance à sauter.

## Premièrement : la sécurité est-elle réellement câblée ?

Sur un projet fraîchement généré, ce n'est pas le cas. La recipe fournit un firewall
sans authenticator et un provider `users_in_memory` : pas d'entité `User`, aucun moyen de
se connecter, rien que `#[CurrentUser]` puisse résoudre. Trente secondes suffisent pour
vérifier — une liste d'authenticators vide à côté de `users_in_memory` signale l'état de
squelette non modifié :

```bash
php bin/console debug:firewall main          # authenticators, provider, stateless or not
grep -A3 'providers:' config/packages/security.yaml
```

À vérifier avant d'écrire l'endpoint : `mine(#[CurrentUser] User $user)` renvoie une 500
avec *« requires the `$user` argument that could not be resolved »* sans jamais
mentionner un provider manquant, et `?User $user` transforme ce crash en un `null`
silencieux, ce qui est pire.

**Trois éléments sont nécessaires, et aucun n'est optionnel :**

1. une entité implémentant `UserInterface` (plus `PasswordAuthenticatedUserInterface` si
   elle a un mot de passe) ;
2. un provider qui pointe vers elle, référencé par le firewall ;
3. un **authenticator** dessus — `form_login`, `json_login`, `access_token`. Un provider
   se contente de *charger* un utilisateur, il ne l'authentifie jamais : un firewall avec
   un provider et sans authenticator ne laisse entrer personne, silencieusement.

**Dis-le plutôt que de contourner le problème.** Deux échappatoires apparaissent
spontanément ; toutes deux sont pires que la fonctionnalité manquante :

| Échappatoire | Pourquoi elle est refusée |
|---|---|
| Récupérer un `userId` dans le payload de la requête | Ce n'est pas de l'authentification — l'appelant te dit qui il est, et n'importe qui peut envoyer un autre id. Le problème se propage en plus : chaque service en aval fait désormais confiance à une valeur non authentifiée. |
| Ajouter un entity provider *uniquement* pour que `loginUser()` fasse passer les tests fonctionnels au vert | `loginUser()` écrit un token directement dans le token storage et ne fait jamais tourner d'authenticator. La suite passe au vert alors que la production n'a toujours aucun moyen de se connecter — des tests verts sur une fonctionnalité inaccessible, c'est l'erreur la plus coûteuse. |

La réponse honnête est « il faut d'abord une entité User et un mécanisme de connexion,
voici ce que cela implique » — pas un endpoint qui a l'air de fonctionner.

## Les trois éléments

```php
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }
    // getUserIdentifier() retourne la colonne email unique ; le reste est généré par le maker
}
```

`ROLE_USER` est ajouté dans le getter, jamais stocké, si bien qu'aucune ligne ne peut en
être dépourvue, et `getUserIdentifier()` doit être stable et unique — c'est ce qui se
retrouve dans la session et dans chaque token. `eraseCredentials()` a été retiré de
`UserInterface` dans Symfony 8.0 (dépréciée depuis la 7.3) ; sur 6.4–7.x, implémente-la
vide. `make:user` génère la forme adaptée à la version installée.

```yaml
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'
    providers:
        app_users:
            entity: { class: App\Entity\User, property: email }
    firewalls:
        dev:
            pattern: ^/(_profiler|_wdt|assets)/
            security: false
        main:
            lazy: true
            provider: app_users
            form_login: { login_path: app_login, check_path: app_login, enable_csrf: true }
            logout: { path: app_logout }
            login_throttling: { max_attempts: 5, interval: '15 minutes' }
```

**Un seul firewall pour l'application**, plus `dev`, qui est une exclusion et non une
frontière. Plusieurs firewalls signifient plusieurs contextes de sécurité, et un
utilisateur authentifié dans l'un est anonyme dans l'autre — le bug qui fait perdre un
après-midi à tout le monde. Le seul second firewall légitime est un firewall
**stateless** pour une API à token, sur son propre préfixe de chemin : mécanisme
différent, et il ne doit jamais créer de session. `password_hashers: auto` choisit le
meilleur algorithme offert par la plateforme et, avec `needsRehash()` à la connexion,
fait migrer les hachages au fil de ses améliorations ; nommer explicitement bcrypt ou
argon2id fige la décision dans un fichier que plus personne ne relit.

## Rôles pour les zones, Voters pour les objets

| Question | Mécanisme |
|---|---|
| « Cette personne a-t-elle le droit d'entrer dans la zone d'administration, dans la section facturation ? » | Un rôle, et `role_hierarchy` |
| « Cette personne peut-elle modifier *cet* avis ? » | Un Voter |

La frontière est de savoir si la réponse dépend de l'objet visé. C'est généralement le
cas, et une réponse en forme de rôle à une question en forme d'objet, c'est exactement
comment naît `ROLE_REVIEW_OWNER` — un rôle par ligne, qu'aucune hiérarchie ne peut
exprimer. **Les contrôleurs et les templates nomment des actions, jamais des rôles :**
`#[IsGranted(ReviewVoter::EDIT, subject: 'review')]`, pas `ROLE_MODERATOR`, afin que la
règle vive dans une seule classe plutôt que d'être recopiée dans chaque contrôleur qui
vérifie un rôle.

`subject: 'review'` nomme l'**argument du contrôleur** ; c'est l'objet résolu que le
voter reçoit. C'est le seul cas où un contrôleur résout une entité (`Review $review`,
l'EntityValueResolver) au lieu de transmettre un id au service : le voter s'exécute avant
l'action, donc l'objet doit exister avant que le service ne soit appelé. L'action passe
tout de même `$review->getId()` au service, qui le recharge gratuitement depuis
l'identity map (`symfony-proglab-architecture`). Omets `subject:` et le voter est
appelé avec `null`, `supports()` renvoie false, tous les voters s'abstiennent — et une
décision où tout le monde s'abstient est **refusée**, car `allow_if_all_abstain` vaut
false par défaut (vérifié à l'exécution). Le symptôme est donc un 403 sur une action qui
aurait dû être autorisée, et la cause un attribut qui semble pourtant correct. Cela
échoue dans le bon sens (fermé), mais reste un bug.

L'attribut ne remplace pas `access_control` ; les deux échouent différemment.
`access_control` est le **filet** — une règle par zone d'URL, appliquée avant que le
contrôleur ne soit résolu, si bien qu'une nouvelle action dans un contrôleur protégé est
couverte sans que personne n'ait à y penser ; les règles s'exécutent de haut en bas et
**seule la première correspondance s'applique**, donc `^/` va en dernier. `#[IsGranted]`
est la **précision** — cette action, cet objet, et le seul des deux capable d'exprimer
« l'auteur ». Seul `#[IsGranted]` fuit le jour où quelqu'un ajoute une méthode et oublie
l'attribut ; seul `access_control` est totalement incapable d'exprimer la notion de
propriété.

## Le Voter

```php
<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Review;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<self::EDIT|self::DELETE, Review> */
final class ReviewVoter extends Voter
{
    public const EDIT = 'REVIEW_EDIT';
    public const DELETE = 'REVIEW_DELETE';

    public function __construct(private readonly AuthorizationCheckerInterface $checker)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::DELETE], true) && $subject instanceof Review;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!($user = $token->getUser()) instanceof User) {
            return false;
        }

        if ($this->checker->isGranted('ROLE_MODERATOR')) {
            return true;
        }

        if ($subject->getAuthor() !== $user) {
            $vote?->addReason('Only the author may act on their own review.');

            return false;
        }

        return self::DELETE !== $attribute || !$subject->isPublished();
    }
}
```

Le quatrième paramètre `?Vote $vote = null` existe depuis **Symfony 7.3** ; sur 6.4–7.2,
la signature s'arrête à `TokenInterface $token`, et ajouter ce paramètre provoque une
erreur fatale de signature. Lis
`vendor/symfony/security-core/Authorization/Voter/Voter.php` avant de l'écrire — ce
fichier est la source de vérité, pas celui-ci. `$vote?->addReason()` est ce que le
profiler et `AccessDecision::getMessage()` affichent, transformant « Access Denied » en
une vraie phrase. Les vérifications de rôle passent par `AuthorizationCheckerInterface`,
jamais par `$token->getRoleNames()` : le token contient les rôles bruts et
`role_hierarchy` est appliqué par le checker, donc lire le token fait que `ROLE_ADMIN`
cesse d'impliquer `ROLE_MODERATOR`. Typer l'interface plutôt que `Security` permet aussi
de stubber le voter sans kernel.

## Durcissement

Chaque ligne répond à une attaque précise ; aucune n'est optionnelle.

| Mesure | Sans elle |
|---|---|
| `login_throttling` sur le firewall | Le formulaire de connexion devient un oracle de devinette de mot de passe en ligne. Nécessite `symfony/rate-limiter` |
| `password_hashers: auto` | Un algorithme figé par la première personne qui a écrit le fichier |
| `#[Assert\PasswordStrength]` sur les DTO d'inscription et de changement de mot de passe | Des comptes sécurisés par `azerty123` |
| `#[Assert\NotCompromisedPassword]` | Le credential stuffing fonctionne dès le premier jour |
| `#[RateLimit]` au-delà de la connexion | Réinitialisation de mot de passe, inscription, recherche et export sont tous exploitables, et `login_throttling` ne couvre aucun d'eux |

`#[RateLimit]` est `Symfony\Component\HttpKernel\Attribute\RateLimit`, ajouté dans
**Symfony 8.1**. Il nécessite `symfony/rate-limiter` **et** un limiter déclaré sous
`framework.rate_limiter` dont il référence le nom — seul, il lève « Rate limiter … does
not exist » au moment de la requête. Avant 8.1, injecte le `RateLimiterFactoryInterface`
généré et consomme-le explicitement.

## CSRF

Le composant Form s'en charge : chaque formulaire reçoit un champ `_token`, validé à la
soumission — rien à écrire. En dehors des formulaires (un lien POST, un appel fetch, un
bouton de suppression en ligne), place `#[IsCsrfTokenValid('delete-review', tokenKey:
'_token')]` sur l'action à côté de son `#[Route]` — Symfony 7.1+ ; avant cela, appelle
`isCsrfTokenValid()` dans le contrôleur.

**Une API JSON n'a besoin de CSRF que si elle s'authentifie par cookie**, car le CSRF
existe pour contrer le fait que le navigateur attache les cookies aux requêtes cross-site
de lui-même. Une API stateless qui lit `Authorization: Bearer …` n'est pas exposée — le
navigateur n'ajoute jamais cet en-tête tout seul. Un endpoint JSON adossé à une session,
appelé depuis ton propre front-end, l'est absolument.

## Quand ça ne se comporte pas comme prévu

| Symptôme | Cause |
|---|---|
| `Cannot resolve argument $user` sur `#[CurrentUser]`, ou `?User $user` toujours nul | Pas de user provider, ou la route n'est pas derrière un firewall authentifié. Vérifie `debug:firewall` |
| `Full authentication is required` | `access_control` a fait correspondre une règle qu'une requête anonyme ne peut satisfaire — souvent `^/` placé avant `^/login` |
| Access Denied alors que le voter devrait autoriser | `subject:` manquant sur `#[IsGranted]`, donc le voter a reçu `null` et `supports()` a répondu non |
| Le voter n'est jamais appelé | `supports()` renvoie false : mauvaise chaîne d'attribut, ou classe de subject inattendue |
| 401 sur un endpoint qui devrait être public | Nécessite une règle `PUBLIC_ACCESS` explicite placée avant le catch-all |
| `Invalid CSRF token` sur un formulaire qui en possède un | Le CSRF stateless (par défaut depuis la 7.2) nécessite que son controller Stimulus soit chargé dans le navigateur |
| `ROLE_ADMIN` cesse d'impliquer les rôles en dessous | Rôles lus directement sur le token au lieu de passer par l'authorization checker |
| L'utilisateur de test se connecte mais l'application renvoie 403 | `loginUser()` contourne les authenticators ; les rôles viennent de l'entité, pas du firewall |

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/api-authentication.md` | Une API authentifiée par token : `access_token`, tokens opaques révocables en base de données, le handler, comment la tester |
| `references/voters-and-roles.md` | Écrire ou tester unitairement un Voter, concevoir `role_hierarchy`, l'ordre d'`access_control`, `is_granted` dans Twig |
| `references/hardening.md` | Limitation du débit de connexion, contraintes de mot de passe, limitation de débit, détails CSRF, connexion programmatique, déconnexion et usurpation d'identité |
