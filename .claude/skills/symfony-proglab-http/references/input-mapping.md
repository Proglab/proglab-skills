# Mapper la requête sur des arguments typés

Tout ce que le contrôleur lit depuis la requête, et la version dont chaque mécanisme a
besoin. Signatures vérifiées par rapport à `vendor/symfony/http-kernel/Attribute/` et
`Controller/ArgumentResolver/RequestPayloadValueResolver.php` sur Symfony 8.1.
**`vendor/` fait foi devant ce fichier.**

## Le DTO d'entrée

`src/Dto/Input/`, `final`, contraintes sur les propriétés. En lecture seule avec des
arguments de constructeur promus quand c'est le serializer qui l'hydrate. Quand un
**FormType** l'hydrate, des propriétés publiques avec des valeurs par défaut sont le
chemin de moindre résistance, car le composant Form écrit propriété par propriété plutôt
que d'appeler un constructeur — mais un DTO promu `readonly` fonctionne aussi, via un
**callable `empty_data` au niveau du formulaire** qui construit l'objet à partir des
enfants soumis. `forms-and-twig.md` possède cette recette et l'a vérifiée ; utilise-la
quand tu veux qu'une seule forme de DTO serve les deux points d'entrée.

```php
<?php

declare(strict_types=1);

namespace App\Dto\Input;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateReviewInput
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(min: 10, max: 2000)]
        public string $body,

        #[Assert\Range(min: 1, max: 5)]
        public int $rating,
    ) {
    }
}
```

Les contraintes vivent ici plutôt que sur l'entité parce que **c'est l'objet que le
validateur voit réellement**. L'entité est écrite par un service à partir d'un DTO déjà
valide ; des contraintes sur elle ne se déclencheraient que si quelque chose appelait
`validate()` dessus, ce que rien ne fait.

## `#[MapRequestPayload]`

Lit `$request->request->all()` si ça existe, sinon le corps brut, choisit le format
d'après `Content-Type`, dénormalise vers le type de l'argument et le valide.

```php
#[Route('/reviews', name: 'api_review_create', methods: ['POST'])]
#[Serialize(code: Response::HTTP_CREATED)]
public function create(#[MapRequestPayload] CreateReviewInput $input): ReviewView
{
    return $this->reviews->create($input);
}
```

Modes d'échec, par fréquence décroissante :

| Ce qui se passe | Réponse |
|---|---|
| Pas de header `Content-Type` | 415 — le resolver ne peut pas choisir de format |
| `acceptFormat: 'json'` et le client a envoyé un formulaire | 415 |
| JSON malformé | 400 |
| Un champ a le mauvais type | 422, avec une violation par champ |
| La validation échoue | 422 (`validationFailedStatusCode`) |
| Le corps est vide et l'argument est nullable ou a une valeur par défaut | l'argument est `null` — **aucune erreur du tout** |

Cette dernière ligne est celle qui produit des bugs silencieux. `mapWhenEmpty: true`
(Symfony 8.1) dénormalise `[]` au lieu de retourner null, ainsi les valeurs par défaut du
DTO s'appliquent et les contraintes s'exécutent :

```php
public function create(
    #[MapRequestPayload(mapWhenEmpty: true)] CreateReviewInput $input,
): ReviewView
```

Avant 8.1 il n'y a pas de `mapWhenEmpty`. Déclarer l'argument non nullable sans valeur
par défaut fait bien échouer un corps vide plutôt que de résoudre à `null` — mais **il
échoue en 400, pas avec le statut de validation échouée**. Vérifié dans
`RequestPayloadValueResolver` : le retour anticipé pour corps vide est court-circuité
pour un tel argument, `$data` reste `''`, et `deserialize('')` lève une
`NotEncodableValueException`, que le resolver convertit en `BadRequestHttpException`
(correspondant à la ligne « JSON malformé → 400 » du tableau ci-dessus). C'est une
réponse défendable — un corps vide *est* une requête malformée — mais c'est un statut
différent de celui que produit `mapWhenEmpty: true`, donc ne le documente pas comme un
422 dans ton contrat d'API.

### Une liste d'éléments

```php
public function importAll(
    #[MapRequestPayload(type: CreateReviewInput::class)] array $inputs,
): Response
```

`array` sans `type:` est une erreur de configuration et le resolver le signale. Depuis
8.1 la même chose fonctionne de façon variadique, ce qui type chaque élément
individuellement :

```php
public function importAll(#[MapRequestPayload] CreateReviewInput ...$inputs): Response
```

Le mapping variadique n'est **pas** supporté par `#[MapQueryString]` — il lève une
`LogicException` au moment de la résolution.

## `#[MapQueryString]` et `#[MapQueryParameter]`

`#[MapQueryString]` mappe toute la query string sur un DTO. Utilise-le dès qu'il y a plus
d'un paramètre, car les filtres et la pagination grandissent toujours.

```php
final readonly class ShelfQuery
{
    public function __construct(
        #[Assert\Positive]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $perPage = 20,
    ) {
    }
}

#[Route('/books', name: 'api_book_index', methods: ['GET'])]
#[Serialize]
public function index(
    #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
    ShelfQuery $query = new ShelfQuery(),
): BookListView
```

**Les deux attributs de query échouent en 404 par défaut.** Pour une liste HTML c'est
plutôt justifié — `?page=999999` est une page qui n'existe pas. Pour une API c'est faux :
le client a envoyé une requête malformée et doit être informé de quel paramètre. Règle
422 explicitement sur les routes `api_` ; la différence entre les deux contrôleurs est
exactement ce genre de chose.

Remarque la valeur par défaut `= new ShelfQuery()` : sans elle, une requête sans aucune
query string résout à `null`, car le resolver retourne tôt sur une entrée vide. Les
alternatives sont `mapWhenEmpty: true` (8.1) ou un argument nullable qu'il faut ensuite
vérifier.

`#[MapQueryParameter]` gère une seule valeur et n'a besoin d'aucun DTO. Il prend des
constantes de filtre PHP plutôt que des contraintes de validateur —
`#[MapQueryParameter(filter: \FILTER_VALIDATE_INT, options: ['min_range' => 1])] int
$page = 1`. Il résout des enums adossés depuis 6.4 et des types `Uid` depuis 7.3. Deux
d'entre eux sur une action, ça passe ; quatre, ça aurait dû être un DTO.

## `#[MapRequestHeader]`

Symfony 8.1 : `#[MapRequestHeader('X-Signature')] string $signature` aux côtés d'un
argument `#[MapRequestPayload]`. Manquant ou invalide donne 400. Avant 8.1,
`$request->headers->get('X-Signature')` plus une vérification de nullité — la règle
(valider en périphérie, là où la signature le déclare, pas au fond d'un service) ne
change pas, seul le moyen change.

## Fichiers uploadés

`#[MapUploadedFile]` (7.1) mappe un fichier et le valide avec de vraies contraintes :

```php
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

public function uploadCover(
    Book $book,
    #[MapUploadedFile([
        new Assert\File(maxSize: '2M', extensions: ['jpg', 'png']),
        new Assert\Image(maxWidth: 2000),
    ])]
    UploadedFile $cover,
): Response
```

Les violations sont rapportées sur le chemin propre de l'argument, donc le client reçoit
`cover: …` plutôt qu'un message anonyme. Déclare `array $covers` ou un variadique pour
plusieurs fichiers, et `?UploadedFile $cover = null` pour un fichier optionnel.

Depuis 8.1 un fichier peut aussi être une **propriété du DTO de payload** : sur une
requête multipart `#[MapRequestPayload]` fusionne `$request->files` dans les données
avant la dénormalisation, donc un DTO portant à la fois les métadonnées (`public string
$label`) et le fichier (`#[Assert\File(maxSize: '5M', extensions: ['csv'])] public
UploadedFile $file`) est mappé et validé en un seul passage. En dessous de 8.1, sépare :
`#[MapRequestPayload]` pour les champs, `#[MapUploadedFile]` pour le fichier.

## Groupes de validation dynamiques

`validationGroups` accepte une chaîne, un tableau de chaînes, une `GroupSequence`, et —
depuis 8.1 — une `Expression` ou une `Closure` évaluée par rapport aux autres arguments
du contrôleur.

```php
use Symfony\Component\ExpressionLanguage\Expression;

public function save(
    Book $book,
    #[MapRequestPayload(
        validationGroups: new Expression('args["book"].isPublished() ? ["strict"] : ["draft"]'),
    )]
    SaveBookInput $input,
): Response
```

Trois variables sont en portée, et seulement trois : `args` (les arguments nommés du
contrôleur, sous forme de tableau), `request`, et `this` (l'instance du contrôleur).
Écrire `book` tout court ne fonctionne pas — c'est `args["book"]`. Nécessite
`symfony/expression-language`.

La forme `\Closure` dans la signature n'est **pas utilisable sur PHP 8.4** : les
closures ne sont pas des expressions constantes, donc une closure écrite à l'intérieur
d'un attribut est une erreur fatale à la compilation. Elle devient disponible avec PHP
8.5. D'ici là, la forme `Expression` est la seule, avec le coût qui l'accompagne — une
chaîne que PHPStan ne peut pas vérifier.

L'imbrication est rejetée bruyamment — un tableau ne peut contenir que des chaînes, et
une `GroupSequence` doit être la valeur de premier niveau. En dessous de 8.1, passe une
liste de groupes fixe et laisse le service appliquer la règle conditionnelle ; une règle
qui dépend de l'état d'un autre objet n'était sans doute jamais une contrainte d'entrée.

## `#[MapEntity]`, et pourquoi `expr:` est rejeté

Avec des identifiants entiers auto-incrémentés, `Book $book` sur une route avec `{id}`
se résout tout seul. `#[MapEntity]` sert pour l'autre cas : résoudre par autre chose que
la clé primaire :

```php
#[Route('/books/{isbn}', name: 'show', requirements: ['isbn' => '[\d-]{10,17}'])]
public function show(#[MapEntity(mapping: ['isbn' => 'isbn'])] Book $book): array
```

`mapping` est `['paramètre de route' => 'champ de l'entité']` et lance `findOneBy()`.
Non trouvé et non nullable donne un 404 ; `message:` le personnalise.

**`expr:` n'est pas utilisé ici.** Ça fonctionne — le resolver évalue l'expression avec
`repository` et les attributs de la requête en portée — mais ça place une requête à
l'intérieur d'un attribut de contrôleur, sous forme de chaîne :

```php
// Rejeté.
#[MapEntity(expr: 'repository.findLatestForShelf(shelf, request.query.get("since"))')]
```

Toutes les raisons pour lesquelles ce standard garde les requêtes dans les repositories
s'appliquent, et en pire : la chaîne est invisible pour PHPStan et l'IDE, deptrac ne peut
pas voir la dépendance qu'elle crée, une faute de frappe se manifeste à l'exécution
comme un 404 plutôt qu'une erreur, et ça ne peut pas être testé unitairement. Quand la
recherche n'est pas un simple `findOneBy`, appelle le repository depuis un service et
laisse l'action prendre le paramètre de route brut.

## `#[UniqueEntity]` sur le DTO d'entrée

`#[UniqueEntity]` sur l'entité ne se déclenche jamais, car rien ne valide l'entité — le
DTO est ce que voit le validateur. Place-le sur le DTO avec `entityClass:` :

```php
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[UniqueEntity(
    fields: ['isbn' => 'isbn'],
    entityClass: Book::class,
    errorPath: 'isbn',
    message: 'This book is already on a shelf.',
)]
final readonly class ImportBookInput          // hydraté par le serializer : promu, readonly
{
    public function __construct(
        #[Assert\Isbn]
        public string $isbn,
        // …
    ) {
    }
}
```

`fields` est `['propriété du DTO' => 'champ de l'entité']`. Deux détails décident si ça
fonctionne :

- **`errorPath` n'est pas optionnel en pratique.** Le validateur le règle par défaut à
  `current($fields)` — le nom du champ de l'*entité*, la valeur de la paire. Quand la
  propriété du DTO et le champ de l'entité ont des noms différents, la violation atterrit
  sur un chemin que le formulaire ou l'API ne peuvent pas remapper, et le message
  disparaît de la réponse.
- **Sur les mises à jour, ajoute `identifierFieldNames`.** Sans lui, éditer un livre et
  resoumettre son propre ISBN signale un doublon contre lui-même. La liste doit
  correspondre exactement aux identifiants de l'entité ou le validateur lève une
  `ConstraintDefinitionException` — avec les ids entiers auto-incrémentés que ce standard
  utilise, c'est `['id']`, et le DTO doit porter l'id — donc le DTO de mise à jour ajoute
  `identifierFieldNames: ['id']` et une propriété `public int $id`.

La contrainte exécute une requête par validation et est une commodité d'usage, pas une
garantie — deux requêtes concurrentes la passent toutes les deux. L'index unique en base
de données est la garantie ; voir `symfony-proglab-doctrine`.

## Replis de version

| Fonctionnalité | Depuis | Écrire à la place |
|---|---|---|
| `#[MapRequestPayload]`, `#[MapQueryString]`, `#[MapQueryParameter]` | 6.3 | Un `ValueResolver` écrit à la main — pas `$request->get()` dans l'action |
| `validationFailedStatusCode` sur payload/query-string | 6.4 | Accepter la valeur par défaut et la documenter |
| `#[MapUploadedFile]`, `type:` pour les listes | 7.1 | `$request->files->get()` plus un `$validator->validate()` explicite |
| `key:` sur `#[MapQueryString]`, `Uid` dans `#[MapQueryParameter]` | 7.3 | Un DTO dédié pour la sous-clé |
| `#[MapRequestHeader]` | 8.1 | `$request->headers->get()` dans la zone de la signature de l'action |
| `UploadedFile` à l'intérieur d'un DTO de payload, payloads variadiques, `mapWhenEmpty`, groupes de validation par expression | 8.1 | Séparer le mapping, ou un argument non nullable sans valeur par défaut |

Les attributs de garde comme `#[IsGranted]` sont évalués **avant** le mapping du
payload (le resolver s'abonne à une priorité plus basse, exprès), donc une requête non
autorisée n'atteint jamais la dénormalisation. N'ajoute pas de vérifications
d'autorisation à l'intérieur d'un DTO pour compenser.

## Où le fichier va ensuite

Recevoir l'upload, c'est là que ce skill s'arrête. Ce qui se passe après — stockage
public ou protégé, Flysystem, la clé de stockage portée par l'entité, la suppression et
les fichiers orphelins, et resservir le fichier avec une vérification d'autorisation —
relève de `symfony-proglab-storage`.

Une chose à savoir ici, car ça se manifeste comme un symptôme HTTP : une requête plus
grande que le `post_max_size` de PHP arrive avec **`$_POST` et `$_FILES` tous deux
vides**, avant même que Symfony ne voie quoi que ce soit. Un `#[MapUploadedFile]` non
nullable produit alors un 422 nu avec un message vide, ce qui se lit comme un bug de
validation et n'en est pas un.
