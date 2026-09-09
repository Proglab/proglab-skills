# Endpoints JSON

Sérialisation, contrat de réponse, et format d'erreur. Vérifié par rapport à
`vendor/symfony/http-kernel/`, `vendor/symfony/serializer/` et
`vendor/symfony/error-handler/` sur Symfony 8.1. **`vendor/` fait foi devant ce fichier.**

## Un contrôleur séparé, préfixé `api_`

```php
#[Route('/api/books', name: 'api_book_')]
final class ApiBookController extends AbstractController
{
    public function __construct(private readonly ShelfReader $shelf)
    {
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[Serialize]
    public function show(int $id): BookView
    {
        return $this->shelf->book($id);
    }
}
```

Même service que `BookController`, traduction différente. Les garder séparés n'est pas
de la duplication : les deux diffèrent par le format d'erreur, l'absence d'état,
l'authentification (firewall), la mise en cache et les codes de statut, et chacune de ces
différences deviendrait sinon une condition à l'intérieur d'une action partagée.

## `#[Serialize]`

Symfony 8.1. L'action retourne des données ; l'attribut les sérialise et construit la
réponse.

```php
use Symfony\Component\HttpKernel\Attribute\Serialize;
use Symfony\Component\HttpFoundation\Response;

#[Serialize(code: Response::HTTP_CREATED, context: ['groups' => ['book:read']])]
public function create(#[MapRequestPayload] AddBookInput $input): BookView
```

Le constructeur est `(int $code = 200, array $headers = [], array $context = [])`, et
l'attribut ne cible que les méthodes — il n'existe pas de forme au niveau de la classe,
donc il va sur chaque action.

Deux comportements à connaître :

- **Le format vient de l'attribut `_format` de la requête**, avec `json` par défaut —
  pas de `Accept`. Donc ça reste JSON tant qu'une route ne règle pas `format:`. Si tu
  règles `format: 'xml'`, le serializer a besoin d'un encoder pour ce format, sinon la
  réponse est 415.
- **`Content-Type` est dérivé de ce format**, sauf si tu en passes un dans `headers`.

**En dessous de 8.1**, l'action retourne une `Response` et fait le travail elle-même —
mais ne te précipite pas sur `$this->json()` sans lire le piège du float ci-dessous :

```php
return new JsonResponse(
    $serializer->serialize($view, 'json', ['groups' => ['book:read']]),
    json: true,
);
```

## DTO de sortie et attributs du serializer

`src/Dto/Output/`, `final readonly`, construits par le service. **Jamais une entité** —
une entité est un mapping de base de données, donc la sérialiser publie chaque colonne
que tu ajoutes plus tard, entraîne les associations lazy dans la réponse, et fait de
`Groups` la seule chose qui se dresse entre un refactor et une fuite. Les attributs à
connaître vivent dans `Symfony\Component\Serializer\Attribute` (alias de l'ancien espace
de noms `Annotation` depuis 6.4 ; les alias ont été retirés en 8.0, donc utilise
`Attribute` partout).

```php
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

#[Groups(['book:read'])]
final readonly class BookView
{
    public function __construct(
        public int $id,

        #[Groups(['book:list'])]
        public string $title,

        #[SerializedName('isbn13')]
        public string $isbn,

        #[Context([DateTimeNormalizer::FORMAT_KEY => \DateTimeInterface::ATOM])]
        public ?\DateTimeImmutable $lastReadAt,
    ) {
    }
}
```

| Attribut | À utiliser pour |
|---|---|
| `Groups` | Un DTO servant une vue liste et une vue détail. Sur une classe (6.4+) il ajoute ses groupes à chaque propriété, et les groupes au niveau propriété s'empilent par-dessus — ce dont l'exemple ci-dessus dépend |
| `SerializedName` | Le nom sur le fil diffère du nom PHP — renomme ici, pas dans le DTO |
| `Context` | Options de sérialisation par propriété ; `normalizationContext` / `denormalizationContext` pour celles spécifiques à une direction. Répétable, et cadrable avec `groups:` |
| `DiscriminatorMap` | Un payload polymorphe : `#[DiscriminatorMap('kind', ['paper' => PaperBook::class, 'ebook' => EBook::class])]` sur la classe de base, pour que la dénormalisation sache quelle sous-classe construire |

Si un DTO a besoin de trois jeux de groupes pour servir trois endpoints, c'est trois DTO.

## Le piège du float

`json_encode(4.0)` produit `4`. Un `averageRating` qu'un client a parsé comme un float
hier arrive comme un entier aujourd'hui, pour aucune autre raison que la moyenne tombant
sur un nombre rond — et les clients strictement typés cassent dessus. Mesuré sur cet
arbre vendor :

```
json_encode(['average' => 4.0])                        {"average":4}
new JsonResponse(['average' => 4.0])                   {"average":4}
$this->json($dto)                                      {"average":4}
$serializer->serialize($dto, 'json')                   {"average":4.0}
#[Serialize] sur l'action                               {"average":4.0}
```

La divergence a une cause unique. Le contexte par défaut de `JsonEncode` est
`['json_encode_options' => JSON_PRESERVE_ZERO_FRACTION]`, donc le Serializer préserve la
fraction — mais `AbstractController::json()` fusionne
`['json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS]` par-dessus, et cette
constante (15) n'inclut pas le flag. `#[Serialize]` ne passe que le contexte propre de
l'attribut, donc la valeur par défaut survit.

### Correctif 1 — garder le flag

Utilise `#[Serialize]`, qui s'en charge pour toi. Si tu dois construire la réponse à la
main, passe le flag explicitement et garde les flags d'échappement que tu obtenais
gratuitement :

```php
return $this->json($view, context: [
    'json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_PRESERVE_ZERO_FRACTION,
]);
```

### Correctif 2 — sortir le float du contrat

Le correctif le plus solide, car il ne dépend pas de la survie d'un flag d'encoder à un
refactor : décide de la représentation dans le DTO de sortie. Soit une chaîne à précision
fixe, soit un entier dans une unité fixe.

```php
final readonly class ShelfStatsView
{
    public function __construct(
        public string $averageRating,   // "4.0" — toujours une décimale
        public int $totalPagesRead,
    ) {
    }

    public static function from(ShelfStats $stats): self   // ShelfStats est un DTO de lecture
    {
        return new self(number_format($stats->averageRating, 1, '.', ''), $stats->totalPagesRead);
    }
}
```

Cet arrondi *est* une décision métier — combien de décimales une note doit avoir — et la
placer dans le service qui construit le DTO de sortie la rend testable unitairement sans
requête HTTP ni base de données. C'est exactement l'étape de normalisation Read → Output
que l'architecture demande.

## Réponses de liste : `items` + `meta`

Chaque endpoint de liste retourne la même enveloppe. Pagination dans le corps, pas dans
les headers.

```json
{
  "items": [ … ],
  "meta": { "page": 2, "perPage": 20, "total": 137, "pages": 7 }
}
```

```php
final readonly class BookListView
{
    /** @param list<BookView> $items */
    public function __construct(public array $items, public PageMeta $meta)
    {
    }
}

final readonly class PageMeta
{
    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
        public int $pages,
    ) {
    }
}
```

Alternatives rejetées : **pagination dans les headers HTTP** (beaucoup de clients
jettent les headers avant que les données n'atteignent le code applicatif, et rien dans
`X-Total-Count` n'est standard) ; **pagination par curseur** (correcte pour de grands
jeux de données mutants, inutilisable pour « aller à la page 7 » — à adopter
délibérément par endpoint, pas comme choix par défaut) ; **Pagerfanta** (une dépendance
avec sa propre couche de vue et son propre vocabulaire, en échange de `count()` plus de
l'arithmétique — `pages` c'est `(int) ceil($total / $perPage)`).

Le `total` vient d'une méthode de comptage du repository, jamais de `count($collection)`
— voir `symfony-proglab-doctrine`.

## Erreurs : Problem Details RFC 7807

Le `ProblemNormalizer` de Symfony construit déjà le corps :

```json
{
  "type": "https://symfony.com/errors/validation",
  "title": "Validation Failed",
  "status": 422,
  "detail": "rating: This value should be between 1 and 5.",
  "violations": [ { "propertyPath": "rating", "title": "…" } ]
}
```

Il se déclenche automatiquement quand une `ValidationFailedException` atteint le
gestionnaire d'erreurs — ce que lève `#[MapRequestPayload]` — donc les erreurs de
validation sont RFC 7807 sans aucun code.

**Mais le media type est faux, et demander le bon empire les choses.** Avec `Accept:
application/json` le corps est du Problem Details et le `Content-Type` est
`application/json`. Avec `Accept: application/problem+json` le format de requête résout
à `problem`, aucun encoder ne supporte ce format, `SerializerErrorRenderer` capture
l'`UnsupportedFormatException` qui en résulte et se replie sur le moteur de rendu
d'erreurs **HTML** — un client demandant correctement du Problem Details reçoit une page
HTML.

Enregistre un encoder pour le format. L'autoconfiguration le tague ; il n'y a rien
d'autre à câbler :

```php
namespace App\Serializer;

use Symfony\Component\Serializer\Encoder\EncoderInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class ProblemJsonEncoder implements EncoderInterface
{
    public const FORMAT = 'problem';

    public function __construct(private readonly JsonEncoder $inner = new JsonEncoder())
    {
    }

    public function supportsEncoding(string $format): bool
    {
        return self::FORMAT === $format;
    }

    public function encode(mixed $data, string $format, array $context = []): string
    {
        return $this->inner->encode($data, JsonEncoder::FORMAT, $context);
    }
}
```

Avec ceci en place, `Accept: application/problem+json` retourne `Content-Type:
application/problem+json` et le corps Problem Details — vérifié sur cet arbre vendor.
C'est le mécanisme dédié ; n'écris pas de listener d'exception du noyau pour reformer les
réponses d'erreur.

Deux choses à vérifier une fois que ça fonctionne. **`APP_DEBUG=0` en production** — en
mode debug `ProblemNormalizer` ajoute `class` et `trace` au corps et le moteur de rendu
ajoute des headers `X-Debug-Exception`. Et **`detail` en production est le texte du
statut**, pas le message de l'exception, délibérément : les messages d'exception fuitent.
Quand un client a besoin d'une raison précise, modélise-la comme une exception avec
`#[WithHttpStatus]` et un statut sur lequel il peut agir.

## `#[WithHttpStatus]` sur les exceptions métier

```php
namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(409)]
final class BookAlreadyOnShelf extends \RuntimeException
{
}
```

Le service la lève ; le contrôleur ne capture rien. Détails d'après
`vendor/symfony/http-kernel/EventListener/ErrorListener.php` :

- l'attribut est **hérité**, donc une `DomainException` de base avec un statut couvre
  ses sous-classes ;
- il est **ignoré** si l'exception implémente déjà `HttpExceptionInterface` — une
  `NotFoundHttpException` explicite l'emporte ;
- `headers:` est disponible pour les cas qui en ont besoin (`Retry-After`, `Allow`).

Associe-le à `#[WithLogLevel]` quand un 4xx ne doit pas être journalisé comme une erreur.
Les deux expliquent pourquoi ce standard n'a ni `try/catch` dans les contrôleurs ni
listener d'exception : le statut est une propriété de l'échec, déclarée une fois juste à
côté, plutôt que répétée à chaque site d'appel et oubliée au plus récent.
