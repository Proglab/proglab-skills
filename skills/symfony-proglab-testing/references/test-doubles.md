# Test doubles

Quel double choisir, pourquoi PHPUnit se plaint maintenant du mauvais, et les fakes que
Symfony fournit déjà pour que tu n'aies pas à écrire les tiens.

## Sommaire

- [Stub ou mock](#stub-ou-mock)
- [L'avis émis par PHPUnit 11+, et le flag qui le rend décisif](#lavis-émis-par-phpunit-11-et-le-flag-qui-le-rend-décisif)
- [Remplacer un service dans le conteneur](#remplacer-un-service-dans-le-conteneur)
- [Emails](#emails)
- [HTTP sortant](#http-sortant)
- [Messenger](#messenger)
- [Le temps](#le-temps)
- [Notifications](#notifications)

## Stub ou mock

Les deux sont produits par le générateur de doubles de PHPUnit ; la différence est ce
sur quoi tu affirmes.

| | À utiliser quand | L'assertion vit |
|---|---|---|
| **`createStub()`** | Le collaborateur doit seulement *renvoyer quelque chose* pour que le code testé puisse s'exécuter | Dans ton propre `assertSame()` à la fin |
| **`createMock()`** | *L'appel lui-même* est le comportement que tu testes | Dans `expects()` sur le double |

```php
// Stub : le repository n'est que du décor. La règle testée est l'arrondi.
$reviews = self::createStub(ReviewRepository::class);
$reviews->method('averageRatingForBook')->willReturn(4.25);

$view = (new ShelfReader($reviews))->summary(7);

self::assertSame(4.3, $view->averageRating);
```

```php
// Mock : « le livre est persisté exactement une fois » EST l'assertion.
$em = $this->createMock(EntityManagerInterface::class);
$em->expects(self::once())->method('flush');

(new BookRater($books, $em, $mapper))->rate(7, new RateBookInput(4));
```

`createStub()` est une méthode statique en PHPUnit 13 (`self::createStub(...)`) ;
`createMock()` est une méthode d'instance (`$this->createMock(...)`). Les deux
acceptent une `class-string` et sont typées `RealInstanceType&Stub` /
`RealInstanceType&MockObject`, donc déclare les propriétés en intersections et PHPStan
suit :

```php
private BookRepository&MockObject $books;
```

C'est aussi pourquoi **les repositories ne sont pas `final`** dans ce standard : un
repository `final` ne peut pas être doublé, et chaque test unitaire de service aurait
besoin d'une base de données. Tout le reste — services, contrôleurs, DTO, voters,
handlers — est `final`.

## L'avis émis par PHPUnit 11+, et le flag qui le rend décisif

Depuis PHPUnit 11, un mock créé sans aucune expectation déclenche :

```
No expectations were configured for the mock object for App\Repository\ReviewRepository.
Consider refactoring your test code to use a test stub instead.
The #[AllowMockObjectsWithoutExpectations] attribute can be used to opt out of this check.
```

**C'est un avis (notice) PHPUnit, pas un avis PHP**, et cette distinction décide si ta
suite passe au rouge. Mesuré sur PHPUnit 13.3.2 :

| Flag | Code de sortie avec un mock inattendu |
|---|---|
| aucun | `0` — « OK, but there were issues! » |
| `failOnNotice="true"` | `0` — cela couvre les avis PHP, pas ceux de PHPUnit |
| `failOnPhpunitNotice="true"` | `1` |
| `failOnAllIssues="true"` | `1` |

La recipe Symfony fournit `failOnNotice="true"`, qui **ne** capture **pas** ceci. Ajoute
le bon flag à `phpunit.dist.xml` :

```xml
<phpunit
    failOnDeprecation="true"
    failOnNotice="true"
    failOnPhpunitNotice="true"
    failOnWarning="true"
    displayDetailsOnPhpunitNotices="true"
>
```

`displayDetailsOnPhpunitNotices` compte autant que l'échec lui-même : sans lui, tu
obtiens un compte et aucune idée de quel test l'a produit.

La règle qui en découle est simple — **`createMock()` est toujours suivi de
`expects()` ; sinon c'est un `createStub()`.** `#[AllowMockObjectsWithoutExpectations]`
existe pour le rare double qui est légitimement un mock configuré ailleurs ; l'utiliser
pour faire taire l'avis sur un stub ordinaire, c'est échanger une correction d'un mot
contre un mensonge permanent.

## Remplacer un service dans le conteneur

```php
$client = self::createClient();
$client->getContainer()->set(PaymentGateway::class, $fakeGateway);
```

**Frontières externes uniquement.** Une passerelle de paiement, un fournisseur SMS, une
API tierce, tout ce qui facturerait de l'argent ou toucherait le réseau. Tes propres
services ne sont jamais remplacés de cette façon : si un test fonctionnel a besoin que
`BookRater` soit mocké, la règle testée vit dans `BookRater` et a sa place dans un
`TestCase` sans kernel. Le test fonctionnel qui reste affirme le routing, le mapping et
les codes de statut.

Trois mécaniques qui mordent :

- **`set()` après que le service a été instancié lève une exception.** Le message exact
  est `The "App\Service\X" service is already initialized, you cannot replace it`.
  Remplace-le avant la première requête.
- **Un redémarrage du kernel fait perdre le remplacement.** `KernelBrowser` redémarre
  entre les requêtes, donc un service défini avant la requête 1 a disparu à la requête
  2. `$client->disableReboot()` le conserve — voir `references/database-tests.md`.
- **Le service doit encore exister dans le conteneur compilé.** Un service privé que
  rien ne référence est supprimé à la compilation, et `set()` ne peut pas le
  ressusciter. Dans l'environnement de test, `framework.test: true` en conserve la
  plupart ; quand ce n'est pas le cas, la correction est `public: true` dans
  `config/services_test.yaml`, pas une refonte du code.

## Emails

`framework.test: true` remplace le transport du mailer par un qui enregistre. Ne
construis pas de double de mailer : affirme sur ce qui a été enregistré, ce qui prouve
aussi que le message a réellement été construit, rendu et adressé.

```php
$this->client->submitForm('Publier', ['review[body]' => 'Excellent.']);

self::assertQueuedEmailCount(1);

$email = self::getMailerMessage();
self::assertEmailAddressContains($email, 'To', 'author@example.com');
self::assertEmailSubjectContains($email, 'Nouvelle critique');
self::assertEmailHtmlBodyContains($email, 'Excellent.');
```

**`assertEmailCount()` ou `assertQueuedEmailCount()` ?** Ce standard route
`SendEmailMessage` vers le transport asynchrone, donc dans une application normale
l'email est *mis en file*, pas envoyé, et `assertEmailCount(1)` échoue avec un compte
de zéro. Utilise `assertQueuedEmailCount()` sauf si tu sais que le mail est synchrone.
Se tromper sur ce point est le « mon test d'email ne fonctionne pas » le plus courant.

Le piège associé, venant de `symfony-proglab-async` : le contexte d'un `TemplatedEmail`
doit être sérialisable, donc aucune entité Doctrine dedans. Un test fonctionnel qui
affirme sur un email en file est l'endroit où cet échec se manifeste en premier —
prends-le comme le signal de conception qu'il est.

Disponible sur tout `KernelTestCase` (le trait est sur la classe de base, pas
uniquement `WebTestCase`) : `assertEmailCount`, `assertQueuedEmailCount`,
`assertEmailAttachmentCount`, `assertEmailTextBodyContains`,
`assertEmailHtmlBodyContains`, `assertEmailHasHeader`, `assertEmailHeaderSame`,
`assertEmailAddressContains`, `assertEmailSubjectContains`, plus `getMailerMessages()`
/ `getMailerMessage(int $index = 0)`.

## HTTP sortant

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

Passe un tableau de `MockResponse` pour une séquence fixe, ou un callable
`fn (string $method, string $url, array $options) => new MockResponse(...)` quand la
réponse dépend de la requête. `getRequestsCount()` te dit combien d'appels ont été
faits — utile pour prouver qu'une nouvelle tentative a eu lieu, ou non.

Dans un test fonctionnel, remplace le vrai client à la frontière :

```php
$client->getContainer()->set(HttpClientInterface::class, $http);
```

et affirme avec les helpers propres au framework plutôt qu'en inspectant le double :

```php
self::assertHttpClientRequestCount(1);
self::assertHttpClientRequest('https://openlibrary.org/search.json?q=dune', 'GET');
```

Teste toujours la branche d'échec. Un 503, un timeout (`new MockResponse('', ['error'
=> 'Timed out'])`) et un corps malformé sont les trois choses qui arrivent réellement à
une API tierce, et le chemin de code qui les gère est celui que personne n'exerce à la
main.

## Messenger

Route les transports vers la mémoire dans l'environnement de test :

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

`getSent()`, `getAcknowledged()`, `getRejected()` et `reset()` constituent toute
l'API.

Deux choses que cela t'apporte et qu'un bus de messages mocké n'apporte pas : le
message passe réellement par le serializer, donc un payload non sérialisable échoue
ici plutôt qu'en production ; et tu peux affirmer sur l'*enveloppe*, stamps compris.

**Teste le handler séparément, comme une unité.** Un handler est une couche de
traduction — il recharge depuis un repository et appelle un service. Son test affirme
qu'il l'a fait, et que l'exécuter deux fois ne fait pas le travail deux fois, car
Messenger fait des nouvelles tentatives et un handler idempotent est une exigence
plutôt qu'un agrément.

## Le temps

N'appelle jamais `new \DateTimeImmutable()` à l'intérieur d'un service que tu comptes
tester. Injecte `Psr\Clock\ClockInterface` (ou utilise `ClockAwareTrait`, qui
l'autowire via un setter `#[Required]` et te donne `$this->now()`), puis fournis un
`MockClock` :

```php
use Symfony\Component\Clock\MockClock;

$clock = new MockClock('2026-01-15 09:00:00');
$service = new ReadingStreak($readings, $clock);

self::assertSame(3, $service->currentStreak($reader));

$clock->sleep(86400 * 2);          // ou $clock->modify('+2 days')

self::assertSame(0, $service->currentStreak($reader), 'the streak breaks after a gap');
```

`MockClock` implémente la vraie interface, donc il n'y a rien à stubber et rien qui
puisse dériver du comportement de production. Dans un test fonctionnel,
`$container->set(ClockInterface::class, new MockClock('…'))` avant la première requête
fait la même chose pour toute l'application. `Clock::set()` existe pour le code qui
utilise encore la façade statique `Clock` ; préfère l'injection.

Tout ce qui a une expiration, une série (streak), une requête « publié dans les 7
derniers jours » ou une limite de débit en a besoin. Les tests qui calculent la valeur
attendue à partir de `new \DateTimeImmutable()` testent l'horloge contre elle-même et
passent quel que soit le code.

## Notifications

Symétrique au mailer, sur tout `KernelTestCase` : `assertNotificationCount()`,
`assertQueuedNotificationCount()`, `assertNotificationSubjectContains()`,
`assertNotificationTransportIsEqual()`, avec `getNotifierMessages()` /
`getNotifierMessage()` pour inspecter. La même question mise-en-file-contre-envoyé
s'applique, pour la même raison.
