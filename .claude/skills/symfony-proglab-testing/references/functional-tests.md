# Tests fonctionnels, commandes, voters et validation

Ce qu'il faut affirmer à la frontière HTTP, et comment tester trois choses qui semblent
avoir besoin d'un navigateur mais n'en ont pas besoin.

## Sommaire

- [À quoi sert un test fonctionnel](#à-quoi-sert-un-test-fonctionnel) ·
  [Le squelette](#le-squelette) · [Formulaires, CSRF et le 422](#formulaires-csrf-et-le-422) ·
  [Endpoints JSON](#endpoints-json) · [Authentification](#authentification) ·
  [Commandes console](#commandes-console) · [Voters](#voters) ·
  [Validation de DTO](#validation-de-dto)

## À quoi sert un test fonctionnel

Le câblage. Le routing, le mapping des arguments, les codes de statut, les
redirections, la forme du contrat HTML ou JSON, et le fait qu'une règle a refusé
quelque chose à la frontière.

Pas les règles métier. Si le seul endroit où une règle peut être exercée est une
requête POST, elle est dans le contrôleur et la correction consiste à la déplacer. Ce
qui reste est court et rapide :

```php
$this->client->request('POST', '/livres/'.$id.'/note', ['rating' => '6', '_token' => $token]);

self::assertResponseStatusCodeSame(422);
self::assertNull(self::bookTitled('Dune')->getRating(), 'nothing must be written');
```

Note la seconde assertion. Un 422 prouve la réponse ; il ne prouve pas que l'écriture
n'a pas eu lieu. **Chaque test « ça a été refusé » affirme aussi sur l'effet de bord**,
sinon il passe sur un code qui renvoie 422 après avoir enregistré.

## Le squelette

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

`createClient()` démarre le kernel — appeler `self::bootKernel()` avant lève une
exception. Les options vont dans le premier argument (`['environment' => 'test',
'debug' => false]`), les variables serveur dans le second. `disableReboot()` n'est pas
là pour l'isolation de base de données — la connexion statique de DAMA survit au
redémarrage d'elle-même (`references/database-tests.md`). Elle est là parce que le
redémarrage construit un nouveau conteneur à partir de la deuxième requête, ce qui rend
périmé tout ce que tu as récupéré via `self::getContainer()` et vide les enregistreurs
en mémoire du mailer et de Messenger. Ce test de fumée sur chaque route attrape un
template qui ne compile plus, un service qui ne s'autowire plus, et une route qu'un
refactoring a laissée pointer sur rien.

### Les assertions à connaître

| Famille | Exemples |
|---|---|
| Réponse | `assertResponseIsSuccessful`, `assertResponseStatusCodeSame`, `assertResponseRedirects`, `assertResponseIsUnprocessable`, `assertResponseHeaderSame`, `assertResponseFormatSame` |
| Routing | `assertRouteSame('book_index')`, `assertRequestAttributeValueSame` |
| DOM | `assertSelectorExists`, `assertSelectorCount`, `assertSelectorTextContains`, `assertAnySelectorTextContains`, `assertPageTitleContains`, `assertInputValueSame`, `assertCheckboxChecked`, `assertFormValue` |
| Session | `assertSessionHasFlashMessage('success')`, `assertBrowserHasCookie` |

Elles affichent le corps de la réponse en cas d'échec, ce pour quoi elles surpassent un
`assertSame(200, …)` fait à la main qui te dit qu'un 500 s'est produit sans rien dire
sur le pourquoi.

`assertSelectorTextContains('body', 'Dune')` est faible — elle passe sur n'importe
quelle page mentionnant le mot. Vise l'élément qui porte le sens
(`'[data-book="7"] .title'`), ou compte (`assertSelectorCount(4, '…star.is-on')`), ce
qui attrape un décalage d'un cran dans une boucle.

## Formulaires, CSRF et le 422

Soumets via le DOM plutôt qu'en postant un tableau : cela exerce en une fois les noms
de champs, le token CSRF et le thème de formulaire.

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

**Une soumission invalide renvoie 422, pas 200.** `AbstractController::render()`
détecte un formulaire soumis et invalide parmi ses paramètres et positionne lui-même le
statut ; Turbo a besoin de cela pour réafficher la page avec ses erreurs plutôt que
d'ignorer la réponse. Un test qui affirme 200 sur un formulaire invalide affirme que
Turbo est cassé — donc affirme `assertResponseStatusCodeSame(422)`, le message d'erreur
sur la page, et que rien n'a été écrit.

Pour une action qui modifie l'état sans passer par un formulaire, ce standard utilise
`#[IsCsrfTokenValid]`. Deux tests, pas un :

avec un token lu depuis une page rendue (le cas nominal), et **sans token**. Affirme le
statut exact sur le second : sur un projet sans firewall authentifié, le refus se
manifeste par un `401`, pas un `403`, car la couche sécurité le rapporte comme « non
authentifié » plutôt que « accès refusé ». Affirme ce qui se passe réellement et note
pourquoi, plutôt que de présumer 403.

## Endpoints JSON

```php
$this->client->request('POST', '/api/livres/7/note',
    server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
    content: json_encode(['rating' => 4]),
);

$payload = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
self::assertSame(4, $payload['rating']);
```

**Affirme sur le payload décodé, pas sur la chaîne brute.** Deux raisons :

- `json_encode(['rating' => 4.0])` produit `{"rating":4}` — PHP supprime le `.0`, donc
  une comparaison de chaînes contre `{"rating":4.0}` échoue alors que l'API est
  correcte. Si une valeur est conceptuellement un entier, type la propriété du DTO de
  sortie en `int`.
- Dans l'environnement de test, `APP_DEBUG` est activé, donc une réponse d'erreur porte
  des clés `class` et `trace` supplémentaires, et `assertJsonStringEqualsJsonString()`
  sur tout le corps d'un 4xx échoue à cause d'elles. Affirme sur les clés qui
  t'intéressent.

Donc pour une erreur RFC 7807, affirme sur `$problem['status']` et une sous-chaîne de
`$problem['detail']`, pas sur le document entier. Le `content-type` suit l'en-tête
`Accept` de la requête, donc fixe-le avec `assertResponseHeaderSame()` uniquement là où
l'endpoint le définit délibérément.

Pour un endpoint de liste, le contrat est l'enveloppe `items` + `meta`, et l'assertion
qui vaut la peine d'être écrite porte sur `meta` : `page`, `perPage`, `total`, `pages`.
La page 2 de trois est l'endroit où vivent les décalages d'un cran, et c'est une
requête de plus à vérifier.

Compter les requêtes SQL qu'exécute un endpoint de liste est une préoccupation
différente avec une API différente — voir `symfony-proglab-performance`.

## Authentification

```php
$user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'reader@example.com']);
$this->client->loginUser($user);
```

`loginUser()` injecte un token pré-authentifié sans passer par le formulaire de
connexion — ce que tu veux dans chaque test *sauf* ceux qui portent sur la connexion
elle-même, qui passent par le vrai formulaire. Passe le nom du firewall quand il y en a
plus d'un : `loginUser($user, 'api')`.

**Vérifie que la sécurité est réellement câblée avant d'écrire ces tests.** Sur un
projet squelette avec le provider par défaut `users_in_memory` et aucune entité
implémentant `UserInterface`, `loginUser()` et `#[CurrentUser]` ne peuvent pas se
résoudre. Dis-le plutôt que de contourner le problème.

Chaque route protégée mérite le test négatif : un anonyme obtient une redirection ou un
401, un utilisateur connecté sans le rôle obtient un 403. Un test qui ne visite jamais
la page qu'en tant qu'utilisateur autorisé n'a testé aucune autorisation du tout.

## Commandes console

Le service fait le travail et est testé unitairement ; la commande est une couche de
traduction et reçoit un test de fumée. Symfony 8.1+ :

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
renvoie un `ExecutionResult` portant `statusCode`, `getOutput()`, `getErrorOutput()` et
`getDisplay()`. Les assertions sont `assertCommandIsSuccessful()`,
`assertCommandFailed()`, `assertCommandIsInvalid()` et `assertCommandResultEquals()`.
`$result->dump()` affiche le tout quand un test échoue pour des raisons que le message
n'explique pas.

Avant Symfony 8.1, l'équivalent est `new CommandTester($application->find('app:…'))`,
`execute([...])`, `getDisplay()` et `assertCommandIsSuccessful($tester)` — même règle,
plomberie différente. Les deux tests ci-dessus forment la paire qui compte pour toute
commande destructrice : **dry run par défaut, `--force` pour agir.** Ne tester que le
chemin `--force` laisse le comportement par défaut — celui qui protège la production —
non vérifié.

## Voters

Un voter est une fonction pure de (token, sujet, attribut). Il n'a besoin d'aucun
kernel. Le `ReviewVoter` testé ici est celui que définit `symfony-proglab-security` : il
prend un `AuthorizationCheckerInterface` pour la vérification de modérateur, donc le
test lui fournit un stub.

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

Les entités sont construites comme le reste de la suite les construit — forme maker,
`new` puis des setters, aucun argument de constructeur — pour que le test ne dépende
pas d'un constructeur que l'entité n'a pas.

Trois cas : **accordé** pour l'utilisateur qui le peut, **refusé** pour un utilisateur
qui ne le peut pas, et — celui qu'on oublie — **abstention** (`ACCESS_ABSTAIN`) pour un
attribut que le voter ne supporte pas. Passe `['SOMETHING_ELSE']` et affirme dessus : un
voter qui supporte accidentellement tous les attributs refuse silencieusement des
actions qu'un autre voter était censé autoriser.

`Voter::vote()` prend un quatrième argument optionnel `?Vote $vote` dans les versions
récentes de Symfony ; omets-le. Teste le `vote()` public, pas le `voteOnAttribute()`
protégé — la logique de `supports()` est la moitié du comportement.

## Validation de DTO

Les DTO d'entrée portent les contraintes, ils sont donc testables sans kernel :

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

`enableAttributeMapping()` est disponible en Symfony 7.0+ ; en 6.4 la méthode est
`enableAnnotationMapping()->addDefaultDoctrineAnnotationReader()`.

Affirme sur le chemin de propriété, pas seulement sur le compte : un DTO avec trois
propriétés contraintes produit une violation pour une erreur sur n'importe laquelle
d'entre elles, donc un test qui ne fait que compter passe même quand c'est le mauvais
champ qui a échoué. Deux pièges spécifiques aux contraintes :

- **`Assert\Range` refuse `minMessage` et `maxMessage` quand `min` et `max` sont tous
  les deux définis.** Elle lève `ConstraintDefinitionException: The … constraint can
  not use "minMessage" and "maxMessage" when the "min" and "max" options are both set.
  Use "notInRangeMessage" instead.` L'échec se produit à la construction de l'attribut,
  donc c'est toute la classe de test qui part en erreur plutôt qu'une seule assertion
  qui échoue.
- **`#[UniqueEntity]` a besoin d'une base de données** et c'est la seule contrainte qui
  ne rentre pas dans ce schéma : teste-la dans un `KernelTestCase` contre le validator
  du conteneur. Elle a sa place sur le DTO d'entrée avec `entityClass:` et `fields:
  ['dtoProp' => 'entityField']` — sur l'entité elle ne se déclenche jamais, et un test
  qui « passe » parce que la contrainte est silencieuse est exactement l'échec que
  cette suite existe pour éviter. Vérifie avec une vérification par mutation.
