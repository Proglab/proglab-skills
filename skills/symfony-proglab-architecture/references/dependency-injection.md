# L'injection de dépendances et les patterns qui s'appuient dessus

L'injection par constructeur avec autowiring couvre la majeure partie d'une application.
Ce fichier traite des cas qu'elle ne peut pas résoudre seule, et des trois patterns vers
lesquels il vaut la peine de se tourner quand elle ne le peut pas.

Chaque attribut ci-dessous a été vérifié face à une installation Symfony 8.1, et les
comportements sont ceux qu'un conteneur compilé produit réellement — pas ce que dit un
article de blog. Quand le skill et `vendor/` divergent, `vendor/` a raison.

## Sommaire

- [Le catalogue des attributs](#le-catalogue-des-attributs)
- [Injecter de la configuration](#injecter-de-la-configuration)
- [Choisir entre plusieurs implémentations](#choisir-entre-plusieurs-implémentations)
- [Strategy](#strategy)
- [Decorator](#decorator)
- [Factory](#factory)
- [Attributs délibérément non utilisés](#attributs-délibérément-non-utilisés)
- [Symptômes](#symptômes)

## Le catalogue des attributs

Tous appartiennent à `Symfony\Component\DependencyInjection\Attribute\`.

| Attribut | Fait | Depuis |
|---|---|---|
| `#[Autowire]` | Injecte une valeur, un service, une expression, une variable d'environnement ou un paramètre | 6.1 |
| `#[AutowireIterator]` | Injecte tous les services portant un tag, sous forme d'`iterable` | 6.4 |
| `#[AutowireLocator]` | Les injecte sous forme de `ServiceProviderInterface` paresseux (un conteneur PSR-11 qui liste aussi ses clés) | 6.4 |
| `#[AsTaggedItem]` | Définit la clé et la priorité de ce service à l'intérieur de ces collections | 5.3 |
| `#[AutoconfigureTag]` | Tague chaque implémentation — à placer **sur l'interface** | 5.3 |
| `#[AsDecorator]` | Enrobe un autre service, en conservant son id | 6.1 |
| `#[AutowireDecorated]` | Injecte le service enrobé dans le decorator | 6.3 |
| `#[Target]` | Choisit un alias d'autowiring nommé parmi plusieurs candidats | 5.3 |
| `#[AsAlias]` | Enregistre la classe sous un id d'interface, ou sous un nom `target:` | 6.3 (`target:` 8.1) |
| `#[When]` / `#[WhenNot]` | N'enregistre la classe que dans (ou en dehors d')un environnement | 5.3 / 7.2 |
| `#[Exclude]` | N'enregistre jamais cette classe comme service — au niveau classe uniquement | 6.3 |
| `#[Lazy]` | Instancie à la première utilisation | 7.1 |

Deux suppressions à connaître, parce que les deux apparaissent encore dans du code et de
la documentation plus anciens : `#[TaggedIterator]` et `#[TaggedLocator]` ont été
**supprimés en 8.0** au profit de `#[AutowireIterator]` / `#[AutowireLocator]`, et
`#[MapDecorated]` a été supprimé en 7.0 au profit de `#[AutowireDecorated]`.

Symfony 8.1 déprécie aussi `defaultIndexMethod` / `defaultPriorityMethod` sur les
collections taguées : utilise `#[AsTaggedItem]` à la place. N'écris pas ces méthodes
statiques.

## Injecter de la configuration

```php
public function __construct(
    #[Autowire(param: 'app.shelf_page_size')] private int $pageSize,
    #[Autowire(env: 'MAILER_DSN')] private string $mailerDsn,
    #[Autowire(env: 'bool:FEATURE_IMPORT')] private bool $importEnabled,
    #[Autowire(service: 'monolog.logger.import')] private LoggerInterface $logger,
) {
}
```

`#[Autowire]` accepte **exactement un seul** de `value`, `service`, `expression`, `env`
ou `param` ; en passer deux lève une `LogicException` à la compilation, ce qui est le
bon résultat. Un paramètre est résolu à la compilation et figé dans le conteneur — un
`param` qui n'existe pas fait échouer le build. Une variable d'environnement reste sous
la forme `%env(...)%` et est résolue à l'exécution, si bien qu'une variable manquante
échoue à la première requête plutôt qu'au build.

Les processeurs de variables d'environnement (`bool:`, `int:`, `json:`, `default:`,
`resolve:`) fonctionnent à l'intérieur de `#[Autowire(env: ...)]`. Utilise-les plutôt
qu'un cast dans le corps du constructeur — une propriété promue `readonly` n'a pas de
corps de constructeur où caster.

## Choisir entre plusieurs implémentations

Quand une interface a plusieurs implémentations, l'autowiring n'a aucun moyen de
choisir et fait échouer le build avec un message listant les candidats. Deux réponses.

**`#[Target]` avec un alias nommé** quand un consommateur particulier en veut un
particulier :

```php
// src/Service/Export/JsonShelfExporter.php
#[AsAlias(ShelfExporter::class, target: 'json shelf exporter')]   // Symfony 8.1+
final readonly class JsonShelfExporter implements ShelfExporter { /* … */ }

// le consommateur
public function __construct(
    #[Target('json shelf exporter')] private ShelfExporter $exporter,
) {
}
```

Avant la 8.1, déclare l'alias dans `config/services.yaml` et garde l'attribut sur le
consommateur — le nom est celui de l'argument en camelCase :

```yaml
services:
    App\Service\Export\ShelfExporter $jsonShelfExporter: '@App\Service\Export\JsonShelfExporter'
```

**Quand un `#[Target]` mal orthographié est détecté, et quand il ne l'est pas.** Mesuré
sur la 8.1, parce que les deux cas se lisent comme une contradiction tant qu'on ne sait
pas dans lequel on se trouve :

- **Normalement, ça fait échouer le build, bruyamment.** `AutowirePass` lève
  `Cannot autowire service "…": argument "$e" of method "__construct()" has
  "#[Target('nope')]" but no such target exists.`, généralement suivi d'un *« Did you
  mean to target … »* listant les alias qui existent réellement. `lint:container`
  échoue. Ceci couvre tout service ordinaire, y compris les channel loggers Monolog.
- **Ça devient silencieux quand le consommateur n'est atteignable qu'à travers un
  locator paresseux** — l'argument locator d'un contrôleur, une collection
  `#[AutowireLocator]`, un service subscriber. Ces références sont
  `RUNTIME_EXCEPTION_ON_INVALID_REFERENCE`, si bien que `DefinitionErrorExceptionPass`
  laisse l'erreur pour l'exécution : le conteneur compile, et le `container.error` lève
  une exception à la première instanciation.

Donc une cible mal orthographiée est normalement un échec de build ; si elle survit à
`lint:container`, c'est que le consommateur est derrière un locator. Le véritable
sibling silencieux est `#[WithMonologChannel('typo')]`, qui *crée* le channel plutôt que
d'échouer (`symfony-proglab-observability`), ainsi qu'un `#[Autowire(service: '…')]`
typé sur une interface que le service n'implémente pas, qui compile et lève une
`TypeError` à la première utilisation.

**Une collection taguée** quand le consommateur les veut toutes et choisit à
l'exécution — c'est le Strategy, ci-dessous.

## Strategy

Une interface, plusieurs implémentations, et une valeur d'exécution qui décide laquelle.
L'interface porte le tag, si bien qu'une nouvelle implémentation n'a besoin d'aucun
enregistrement où que ce soit :

```php
#[AutoconfigureTag('app.shelf_exporter')]
interface ShelfExporter
{
    public function export(Shelf $shelf): string;
}

#[AsTaggedItem(index: 'csv', priority: 10)]
final readonly class CsvShelfExporter implements ShelfExporter { /* … */ }

#[AsTaggedItem(index: 'json')]
final readonly class JsonShelfExporter implements ShelfExporter { /* … */ }
```

```php
final readonly class ShelfExportRunner
{
    /**
     * @param ServiceProviderInterface<ShelfExporter> $exporters
     */
    public function __construct(
        #[AutowireLocator('app.shelf_exporter')]
        private ServiceProviderInterface $exporters,
    ) {
    }

    public function export(Shelf $shelf, string $format): string
    {
        if (!$this->exporters->has($format)) {
            throw new UnsupportedExportFormat($format);
        }

        return $this->exporters->get($format)->export($shelf);
    }
}
```

`#[AutoconfigureTag]` sur une interface fonctionne vraiment — l'interface obtient une
définition abstraite dans le conteneur, et son attribut enregistre une règle
`_instanceof` qui s'applique à chaque classe qui l'implémente. Le locator compilé finit
par être indexé exactement par les valeurs `index:` (`csv`, `json`) ; sans
`#[AsTaggedItem]`, la clé est l'id du service, ce qui rend la recherche inutilisable.

**Type `Symfony\Contracts\Service\ServiceProviderInterface`, pas
`Psr\Container\ContainerInterface`.** Ce que `#[AutowireLocator]` injecte est un
`ServiceLocator`, qui implémente `ServiceCollectionInterface<T>` et donc
`ServiceProviderInterface<T>`. Seule cette interface est générique — donc seule elle
permet à PHPStan de savoir que `get()` a renvoyé un `ShelfExporter` plutôt qu'un `mixed`
— et seule elle déclare `getProvidedServices()`, qui permet d'énumérer les clés du
locator. L'interface PSR n'a ni l'un ni l'autre, donc au niveau max l'annotation
générique est rejetée et chaque appel sur le résultat devient une erreur.

Choisis l'injection selon la façon dont la collection est consommée :

- **`#[AutowireLocator]`** quand un seul d'entre eux est sélectionné — il est
  paresseux, donc seul le service choisi est instancié. C'est le cas habituel.
- **`#[AutowireIterator]`** quand tous s'exécutent, dans l'ordre — une chaîne de
  validateurs, un pipeline. `priority:` décide l'ordre ; le plus élevé s'exécute en
  premier.
- **`#[AutowireLocator]` à nouveau quand le fan-out doit tolérer un membre cassé.**
  L'iterator construit chaque service *au fur et à mesure que le `foreach` avance*, ce
  qui se situe en dehors de tout `try` que tu aurais écrit autour de l'appel — donc un
  service dont le constructeur lève une exception fait tomber tout le point d'entrée
  avec un 500, et le `catch` que tu as ajouté ne s'exécute jamais. Avec le locator,
  `get()` se produit à l'intérieur de la boucle et à l'intérieur du `try`, si bien que
  le service cassé est signalé et les autres continuent de s'exécuter. Mesuré sur un
  endpoint de health-check qui collecte ses vérifications par tag ;
  `symfony-proglab-observability` en donne l'exemple complet.

Ne construis pas une strategy pour deux branches qui ne deviendront jamais trois. Une
expression `match` dans le service est plus petite, lisible en un seul endroit, et
honnête sur le fait qu'il n'y a que deux cas.

## Decorator

Ajouter un comportement autour d'un service existant — journalisation, cache, retry,
limitation de débit — sans le toucher et sans `if` dans l'original :

Les services sont `final` ici, donc un decorator ne peut pas en étendre un :
**la décoration a besoin d'une interface que les consommateurs typent.**
Introduis-la quand le besoin apparaît, pas avant.

```php
interface ShelfReader
{
    /** @return list<BookView> */
    public function shelf(): array;
}

#[AsAlias(ShelfReader::class)]
final readonly class DoctrineShelfReader implements ShelfReader { /* … */ }

#[AsDecorator(decorates: ShelfReader::class)]
final readonly class CachedShelfReader implements ShelfReader
{
    public function __construct(
        #[AutowireDecorated] private ShelfReader $inner,
        private CacheInterface $cache,
    ) {
    }

    public function shelf(): array
    {
        return $this->cache->get('shelf', fn () => $this->inner->shelf());
    }
}
```

Décorer l'id de l'interface fonctionne même si c'est un alias — l'alias se résout, le
decorator prend sa place, et un consommateur qui type `ShelfReader` reçoit
`CachedShelfReader` sans aucun changement où que ce soit. L'original est renommé
`<decorator>.inner` et n'est atteignable qu'à travers `#[AutowireDecorated]`.

La décoration est transparente pour les collections taguées : dans un conteneur
compilé, un service décoré conserve sa place et sa clé dans le locator, pointant
désormais vers le decorator, et le service interne n'apparaît pas une seconde fois.
C'est ce qui rend sûr le fait de décorer une implémentation de strategy.

Deux limites qui méritent d'être dites. Décorer change ce que chaque consommateur
reçoit, ce qui est puissant et invisible — `debug:container <id>` est le seul endroit
où ça se voit. Et un decorator qui ajoute du cache est une décision de performance :
mesure d'abord, et lis `symfony-proglab-performance` avant d'en ajouter un.

## Factory

Quand construire un objet nécessite une décision, pas seulement des dépendances —
choisir un driver depuis la configuration, construire un value object à partir de
plusieurs sources.

Préfère un service simple avec une méthode `create…()` qui renvoie l'objet. Il est
autowiré comme n'importe quel autre, testable unitairement, et n'a besoin d'aucune
fonctionnalité du conteneur. Ne recours à une factory de conteneur
(`Definition::setFactory()`, ou `#[AutowireCallable]`) que quand c'est le *service
lui-même* qui doit être produit par autre chose que `new`.

`#[Lazy]` n'est pas une factory. Il diffère l'instanciation d'un service coûteux
souvent inutilisé ; il ne change pas la façon dont l'objet est construit.

## Attributs délibérément non utilisés

| Non utilisé | Pourquoi |
|---|---|
| `#[Required]` (`Symfony\Contracts\Service\Attribute\Required`) | Injection par setter. Ça donne à une dépendance une apparence optionnelle et mutable, ce qui est exactement ce à quoi sert `final readonly`. Son véritable usage est celui des classes de base dans les bundles qui héritent de dépendances — ce standard n'hérite pas de services. |
| `#[SubscribedService]` / `ServiceSubscriberInterface` | Le contrat subscriber ajoute une méthode statique à maintenir et cache les dépendances derrière un conteneur. `#[AutowireLocator]` offre la même paresse avec la dépendance écrite dans le constructeur. |
| `#[AutowireInline]` | Déclare une définition de service à l'intérieur de l'attribut d'un consommateur. Compact, et introuvable : la définition d'un service devrait être là où se trouve la classe. |

Si un bundle tiers utilise l'un d'eux, laisse-le tel quel. La règle porte sur le code
écrit ici.

## Symptômes

| Symptôme | Cause | Correction |
|---|---|---|
| `Cannot autowire: argument $x references interface I but no such service exists` | Plusieurs implémentations, ou aucune enregistrée | `#[Target]` avec un alias nommé, ou une collection taguée |
| Un locator tagué indexé par le FQCN | `#[AsTaggedItem(index:)]` manquant | Ajoute-le ; le tag seul ne nomme rien |
| Une nouvelle implémentation est ignorée par la strategy | `#[AutoconfigureTag]` est sur une classe, pas sur l'interface | Déplace-le sur l'interface |
| `#[Target('…')] but no such target exists` à la compilation | L'alias est mal orthographié ou n'a jamais été déclaré | Lis la liste *« Did you mean to target »* affichée par le message |
| Un service échoue à la première utilisation, toutes les vérifications passées | Un `#[Target]` mal orthographié sur un consommateur atteint uniquement via un locator — ou un `#[Autowire(service:)]` typé sur une interface que le service n'implémente pas | Les deux compilent sous forme de `container.error` / `TypeError` ; vérifie le nom et le type |
| Un service cassé fait tomber en 500 un endpoint qui boucle sur une collection taguée | `#[AutowireIterator]` construit chaque service en dehors de ton `try` | `#[AutowireLocator]`, avec `get()` à l'intérieur de la boucle |
| Tout fonctionne en dev, une classe manque en prod | `#[When('dev')]`, ou la classe est exclue dans `services.yaml` | `debug:container --env=prod` |
| Un decorator n'est jamais appelé | `#[AsDecorator]` nomme un id que personne n'injecte (par ex. une classe concrète alors que les consommateurs typent l'interface) | Décore l'id réellement injecté |
| `#[Autowire]` lève une exception à la compilation | Deux des paramètres `value`/`service`/`env`/`param` ont été passés | N'en passe qu'un seul |
