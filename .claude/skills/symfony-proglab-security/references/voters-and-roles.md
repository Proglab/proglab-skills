# Autorisation : rôles, Voters, access_control

Décider ce qu'un utilisateur authentifié a le droit de faire — et le prouver par des
tests.

## Sommaire

- [role_hierarchy](#role_hierarchy)
- [access_control, dans l'ordre](#access_control-dans-lordre)
- [Le contrat du Voter](#le-contrat-du-voter)
- [Rendre le voter économe : supportsAttribute et supportsType](#rendre-le-voter-économe--supportsattribute-et-supportstype)
- [Tester un voter unitairement sans kernel](#tester-un-voter-unitairement-sans-kernel)
- [Tester la règle de bout en bout](#tester-la-règle-de-bout-en-bout)
- [is_granted dans Twig](#is_granted-dans-twig)
- [Vérifier des permissions dans un service](#vérifier-des-permissions-dans-un-service)
- [Les expressions, et quand arrêter de les utiliser](#les-expressions-et-quand-arrêter-de-les-utiliser)

## role_hierarchy

Les rôles délimitent des **zones**. `ROLE_ADMIN` signifie « peut entrer dans la zone
d'administration », pas « peut modifier l'avis 42 ».

```yaml
security:
    role_hierarchy:
        ROLE_MODERATOR: [ROLE_USER]
        ROLE_ADMIN:     [ROLE_MODERATOR]
        ROLE_SUPER_ADMIN: [ROLE_ADMIN, ROLE_ALLOWED_TO_SWITCH]
```

Un utilisateur auquel `ROLE_ADMIN` est accordé reçoit tout ce qui se trouve en dessous,
ce qui permet à l'entité de stocker un seul rôle par personne plutôt qu'une liste qui
dérive avec le temps. La hiérarchie est développée par le `RoleHierarchyVoter`, c'est
pourquoi les vérifications de rôle doivent passer par l'authorization checker :
`$token->getRoleNames()` renvoie les rôles bruts stockés et ne connaît rien de la
hiérarchie.

Depuis Symfony 7.4, une commande permet de la relire — utile dès que le graphe dépasse
trois lignes :

```bash
php bin/console debug:security:role-hierarchy
```

Les signes que la hiérarchie n'est plus le bon outil : un rôle nommé d'après une
ressource (`ROLE_REVIEW_42`), un rôle accordé par ligne, ou un rôle dont le nom contient
un verbe (`ROLE_CAN_EDIT_OWN_REVIEW`). Ces trois cas sont des questions en forme de
voter.

## access_control, dans l'ordre

```yaml
    access_control:
        - { path: ^/api/login, roles: PUBLIC_ACCESS }
        - { path: ^/api,       roles: ROLE_USER }
        - { path: ^/admin,     roles: ROLE_ADMIN }
        - { path: ^/reviews,   roles: ROLE_USER, methods: [POST, PUT, DELETE] }
        - { path: ^/,          roles: PUBLIC_ACCESS }
```

**Seule la première règle qui correspond s'applique** — pas toutes. `^/` en dernier, les
exceptions `PUBLIC_ACCESS` placées avant la zone qu'elles percent. Une règle placée après
le catch-all est du code mort qui a l'air d'une protection, ce qui est le pire genre de
bug de sécurité : il donne l'illusion d'être couvert en revue de code.

`PUBLIC_ACCESS` est explicite et vaut la peine d'être utilisé : il dit « ouvert
délibérément » là où une règle absente dit « personne n'a réfléchi à cette URL ».

Autres clés, toutes évaluées conjointement au chemin : `methods`, `ips`, `host`,
`route`, `requires_channel: https`, et `allow_if` pour une expression. Vérifie ce
qu'accepte la version installée dans
`vendor/symfony/security-bundle/DependencyInjection/MainConfiguration.php`.

`access_control` s'exécute avant le contrôleur, il ne peut donc pas voir l'objet visé par
l'action. C'est la frontière entre lui et `#[IsGranted]`, pas une limitation à
contourner.

## Le contrat du Voter

```php
abstract protected function supports(string $attribute, mixed $subject): bool;

abstract protected function voteOnAttribute(
    string $attribute,
    mixed $subject,
    TokenInterface $token,
    ?Vote $vote = null,      // Symfony 7.3+ — absent avant, et l'ajouter est fatal
): bool;
```

Copie-le depuis `vendor/symfony/security-core/Authorization/Voter/Voter.php`, dans le
projet sur lequel tu travailles. Une signature qui ne correspond pas à celle du parent
produit une `Fatal error: Declaration of … must be compatible with …` à la compilation du
conteneur, qui ressemble à un problème de cache mais n'en est pas un.

Deux comportements qui surprennent :

- **`supports()` qui renvoie false est une abstention — et une abstention n'est pas une
  autorisation.** Quand tous les voters s'abstiennent, `AffirmativeStrategy` renvoie son
  flag `allowIfAllAbstainDecisions`, qui **vaut false par défaut** ; le security bundle
  conserve ce défaut (`allow_if_all_abstain` est `defaultFalse()`). La décision est donc
  **refusée**. Vérifié à l'exécution : un voter dont `supports()` renvoie toujours false
  fait renvoyer `false` à `AccessDecisionManager::decide()`. Le symptôme d'un voter qui
  ne matche jamais est donc un **403 inexplicable**, pas une porte ouverte silencieuse —
  et la correction consiste à faire matcher `supports()` avec l'attribut et le subject
  réellement transmis, jamais à ajouter un second voter qui autoriserait. (Ce qui
  *échoue* réellement ouvert, c'est une URL qu'aucune règle `access_control` ne couvre —
  c'est en partie pour cela que ce standard insiste sur les deux mécanismes.)
- **`voteOnAttribute()` qui renvoie false refuse.** Il n'existe pas de troisième valeur ;
  l'abstention ne s'exprime que via `supports()`.

`$vote?->addReason('…')` (7.3+) attache une explication qui remonte dans le panneau
sécurité du profiler et dans `AccessDecision::getMessage()`, que `#[IsGranted]` utilise
comme message d'exception quand aucun `message:` n'est fourni. Écrit une fois, cela
économise l'heure passée plus tard à ajouter des `dump()` dans un voter. Garde ces
raisons exemptes de toute information que l'utilisateur ne devrait pas avoir — elles
peuvent atterrir sur une page 403.

## Rendre le voter économe : supportsAttribute et supportsType

Chaque voter est sollicité pour chaque décision. `CacheableVoterInterface`, que le
`Voter` abstrait implémente déjà avec des valeurs par défaut permissives, permet à
Symfony de sauter complètement le vôtre :

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

Cela vaut la peine d'être ajouté dès qu'un projet compte plus d'une poignée de voters, ou
quand l'un d'eux effectue une requête. Les deux réponses sont mises en cache par attribut
et par type de subject.

## Tester un voter unitairement sans kernel

Un voter est un objet de décision pur : pas de kernel, pas de base de données, pas de
`WebTestCase`. C'est le test de sécurité le moins coûteux de toute la base de code, et
rien ne justifie de le sauter.

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
        $checker->method('isGranted')->willReturn(false);   // pas un modérateur

        $voter = new ReviewVoter($checker);
        $token = new UsernamePasswordToken($author, 'main', $author->getRoles());

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $review, [ReviewVoter::EDIT]),
        );
    }
}
```

Pourquoi il est construit ainsi :

- **Appelle `vote()`, pas `voteOnAttribute()`.** `vote()` est publique, exécute d'abord
  `supports()` et renvoie la constante `ACCESS_*`. Tester la méthode protégée par
  réflexion saute la moitié de la logique où se cache le plus souvent le bug.
- **`UsernamePasswordToken` est un objet réel**, peu coûteux à construire, qui évite de
  mocker `TokenInterface`. Les doublures natives valent mieux que celles faites main.
- **`createStub()`, pas `createMock()`**, pour l'authorization checker : aucune attente
  ne pèse dessus. Depuis PHPUnit 11, un mock sans `expects()` émet une notice *PHPUnit*,
  que `failOnPhpunitNotice="true"` transforme en échec — la recipe ne fournit que
  `failOnNotice`, qui couvre les notices PHP, pas celles de PHPUnit
  (`symfony-proglab-testing`).
- Typer `AuthorizationCheckerInterface` dans le voter plutôt que le helper `Security` est
  ce qui rend ce stub trivial.

Couvre toute la table de vérité — auteur, modérateur, inconnu, anonyme, et chaque
attribut — pas seulement le happy path. Un voter est l'endroit où « cas nominal, cas
limites, cas d'erreur, règles de sécurité » constitue littéralement le cahier des
charges.

## Tester la règle de bout en bout

Le test unitaire prouve la décision. Un test fonctionnel par action protégée prouve
qu'elle est *câblée* : un attribut peut manquer, et un voter que personne n'appelle dit
toujours oui.

```php
$client->loginUser($someoneElse);
$client->request('GET', '/reviews/'.$review->getId().'/edit');
self::assertResponseStatusCodeSame(403);
```

Ici, `loginUser()` est le bon outil : ce n'est pas l'authentification qui est testée.

## is_granted dans Twig

```twig
{% if is_granted(constant('App\\Security\\Voter\\ReviewVoter::EDIT'), review) %}
    <a href="{{ path('review_edit', {id: review.id}) }}">{{ 'review.edit'|trans }}</a>
{% endif %}
```

`constant()` conserve une source unique de vérité, au prix d'une ligne longue.
L'alternative pragmatique est le littéral `is_granted('REVIEW_EDIT', review)` ; si tu
la choisis, c'est le test fonctionnel ci-dessus qui empêche la chaîne et la constante
de diverger.

Cacher un bouton relève de la présentation, jamais de la protection — l'URL existe
toujours. L'action elle-même porte `#[IsGranted]`, et `access_control` couvre la zone.
Twig est la troisième couche, pas la seule.

## Vérifier des permissions dans un service

Les services en ont rarement besoin. L'autorisation appartient au point d'entrée, là où
existent une requête et un utilisateur ; un service invoqué depuis une commande console
ou un handler Messenger n'a ni l'un ni l'autre, et une vérification de permission à cet
endroit échoue pour la mauvaise raison.

Quand c'est réellement nécessaire, injecte `AuthorizationCheckerInterface` et laisse
l'appelant gérer le refus, plutôt que de lever une `AccessDeniedException` depuis les
tréfonds d'un service où le sens HTTP de l'exception est invisible.

## Les expressions, et quand arrêter de les utiliser

```php
#[IsGranted(new Expression('is_granted("ROLE_MODERATOR") or user === subject.getAuthor()'), subject: 'review')]
```

Les expressions fonctionnent (`symfony/expression-language` requis) et conviennent pour
une combinaison ponctuelle. Dès que la condition a besoin d'une seconde clause, c'est un
voter qu'il faut : une chaîne dans un attribut n'est pas type-checkée, pas couverte par
PHPStan, et ne peut pas être testée unitairement. Depuis Symfony 7.3, `#[IsGranted]`
accepte aussi une closure recevant un `IsGrantedContext`, ce qui offre au moins du vrai
PHP — mais la même règle s'applique. Toute logique qui mérite d'être relue deux fois a sa
place dans une classe qui porte un nom.
