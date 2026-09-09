# DTO : Input, Read, Output

Trois espaces de noms sous `src/Dto/`, parce que trois choses différentes sont
transportées et que les fusionner produit une classe qui a tort aux deux extrémités.

```
src/Dto/Input/     ce qui entre    — validé, façonné par le contrat HTTP
src/Dto/Read/      ce qu'une requête renvoie — brut, façonné par la projection SQL
src/Dto/Output/    ce qui sort     — façonné par le contrat de l'API ou du template
```

## Sommaire

- [Pourquoi pas un seul DTO](#pourquoi-pas-un-seul-dto)
- [Les DTO Input](#les-dto-input)
- [Les DTO Read](#les-dto-read)
- [Les DTO Output](#les-dto-output)
- [Read → Output est une règle métier](#read--output-est-une-règle-métier)
- [Mapper avec ObjectMapper](#mapper-avec-objectmapper)
- [Quand se passer de la couche Read](#quand-se-passer-de-la-couche-read)
- [Symptômes](#symptômes)

## Pourquoi pas un seul DTO

Une classe unique utilisée dans les deux sens finit par porter des contraintes de
validation dénuées de sens en sortie, et des propriétés nullables qui n'existent que
parce que la *création* autorise leur absence. Puis, le jour où l'API doit exposer un
champ calculé qui n'est pas accepté en entrée, quelqu'un l'ajoute et le marque
`#[Ignore]`, et la classe ne décrit plus rien.

Les trois espaces de noms coûtent trois petits fichiers et achètent des contrats qui
peuvent évoluer indépendamment. La forme de sortie de l'API peut rester stable pendant
que la requête qui la nourrit est réécrite ; la forme d'entrée peut accepter un champ que
la sortie ne renvoie jamais.

Les entités ne sont jamais l'un des trois. Une entité dans une réponse JSON sérialise
tout ce que Doctrine a hydraté — c'est pourquoi un `fetch: EAGER` sans rapport sur une
relation change silencieusement la charge utile d'une API.

## Les DTO Input

`src/Dto/Input/`, `final readonly`, propriétés promues dans le constructeur, contraintes
de validation sur les propriétés. Hydraté par `#[MapRequestPayload]` /
`#[MapQueryString]`, ou par le `data_class` d'un FormType. La mécanique du contrôleur
appartient à `symfony-proglab-http` ; ce qui compte ici, c'est que le service reçoit un
objet typé, déjà validé, et jamais une `Request`.

```php
<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Entity\Book;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RateBookInput
{
    public function __construct(
        #[Assert\Range(
            min: Book::MIN_RATING,
            max: Book::MAX_RATING,
            notInRangeMessage: 'A rating is between {{ min }} and {{ max }} stars.',
        )]
        public int $rating,
    ) {
    }
}
```

Référencer `Book::MIN_RATING` depuis le DTO est délibéré : la borne est une *valeur*,
elle vit à un seul endroit sur l'entité, et la contrainte du DTO comme le garde-fou du
service la relisent. La contrainte du DTO donne à l'utilisateur un 422 avec un message ;
le garde-fou du service est celui qui tient réellement, parce que le DTO n'est qu'une
des façons dont le service peut être appelé.

`#[UniqueEntity]` a aussi sa place ici, avec `entityClass:` — sur l'entité elle ne se
déclenche jamais, parce que ce n'est pas l'entité qui est validée. Détails dans
`symfony-proglab-http`.

## Les DTO Read

`src/Dto/Read/`, peuplé par une projection `SELECT NEW` dans un repository. Sa forme est
dictée par ce que la requête peut produire, pas par ce que l'API veut : scalaires bruts,
`\DateTimeImmutable`, nulls façonnés par la base, moyennes `float` à quinze décimales.

```php
<?php

declare(strict_types=1);

namespace App\Dto\Read;

final readonly class ShelfStatsRow
{
    public function __construct(
        public int $bookId,
        public string $title,
        // Les agrégats n'ont aucun type Doctrine à travers lequel se convertir, donc
        // c'est le driver qui décide : SQLite renvoie int/float, PostgreSQL renvoie
        // couramment bigint/numeric sous forme de chaînes via PDO. Déclarez l'union et
        // laissez le service caster — c'est ce que signifient les « valeurs brutes ».
        // Voir symfony-proglab-doctrine.
        public float|string|null $averageRating,   // NULL quand personne ne l'a noté
        public int|string $reviewCount,
        public ?\DateTimeImmutable $lastReadAt,
    ) {
    }
}
```

L'intérêt de cette couche est qu'une projection est bon marché et qu'un graphe
d'entités ne l'est pas : une seule requête renvoyant exactement ces cinq colonnes
remplace un `findAll()` plus une collection en lazy-loading par ligne. Écrire la requête
relève de `symfony-proglab-doctrine` ; la signature du constructeur ci-dessus est le
contrat entre les deux skills.

Les DTO Read ne portent aucune contrainte — rien ne valide une ligne qui sort déjà de
la base de données.

## Les DTO Output

`src/Dto/Output/`, `final readonly`, façonné par le contrat que voit le consommateur.
C'est ce qu'un contrôleur sérialise ou transmet à un template.

```php
<?php

declare(strict_types=1);

namespace App\Dto\Output;

use App\Enum\ReadingStatus;

final readonly class BookView
{
    public function __construct(
        public int $id,
        public string $title,
        public string $author,
        public ReadingStatus $status,
        public ?int $rating,
        public ?string $lastReadAt,        // date ISO, ou null
    ) {
    }
}
```

Une fois cette classe en place, changer la requête, ajouter une colonne ou renommer une
propriété d'entité cesse d'être un changement d'API. C'est tout le retour sur
investissement de ce fichier.

## Read → Output est une règle métier

La traduction d'un DTO Read vers un DTO Output n'est jamais mécanique, et faire comme si
elle l'était place les décisions au mauvais endroit. Arrondir une moyenne, formater une
date, décider si « pas encore de notes » vaut `null` ou `0` sont des décisions produit
aux conséquences visibles — un `0` se lit comme « noté zéro étoile » dans toute UI qui ne
traite pas ce cas à part.

La normalisation vit donc dans le service, dans une méthode privée, et elle est
testable unitairement sans base de données ni kernel :

```php
final readonly class ShelfReader
{
    public function __construct(private BookRepository $books)
    {
    }

    /** @return list<ShelfStatsView> */
    public function stats(): array
    {
        return array_map($this->toView(...), $this->books->findShelfStats());
    }

    private function toView(ShelfStatsRow $row): ShelfStatsView
    {
        return new ShelfStatsView(
            id: $row->bookId,
            title: $row->title,
            // null, pas 0.0 : « jamais noté » et « noté zéro » sont deux faits différents
            averageRating: null === $row->averageRating ? null : round((float) $row->averageRating, 1),
            reviewCount: (int) $row->reviewCount,
            lastReadAt: $row->lastReadAt?->format('Y-m-d'),
        );
    }
}
```

Ce test tient en trois lignes, ne demande aucune fixture, et c'est lui qui échoue quand
quelqu'un « simplifie » l'arrondi. À comparer avec la même logique écrite en Twig, où
elle est intestable et dupliquée dans le contrôleur JSON.

## Mapper avec ObjectMapper

`symfony/object-mapper` supprime la copie manuelle entité → DTO Output. Déclarez la
source sur le DTO Output et injectez `ObjectMapperInterface` :

```php
use App\Entity\Book;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Book::class)]
final readonly class BookView
{
    public function __construct(
        public int $id,
        public string $title,

        #[Map(source: 'lastReadAt', transform: [self::class, 'toIsoDate'])]
        public ?string $lastReadAt,
    ) {
    }

    public static function toIsoDate(?\DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d');
    }
}
```

```php
$view = $this->mapper->map($book, BookView::class);
```

Des faits utiles à connaître, tous vérifiés face au composant :

- **Il lit les propriétés privées via leurs getters.** FrameworkBundle câble
  `property_accessor` dans le service `object_mapper`, si bien qu'une entité au format
  maker se mappe sans être ouverte. Sans le PropertyAccessor, le composant retombe sur
  une lecture directe des propriétés et seules les propriétés publiques fonctionnent.
- **`transform:` accepte un callable ou un id de service**, et reçoit
  `(mixed $value, object $source, ?object $target)`. Un service implémentant
  `TransformCallableInterface` est la version à utiliser quand la transformation a
  besoin d'une dépendance ; une méthode `static` sur le DTO suffit pour du formatage.
- **`Transform\MapCollection`** mappe une propriété itérable élément par élément, avec
  un `targetClass` optionnel.
- **Disponibilité :** le composant est expérimental en Symfony 7.3 et stable à partir
  de la 7.4. En 6.4 il n'existe pas — écrivez un `public static function
  fromEntity(Book $book): self` sur le DTO Output, ou un petit service mapper. La règle
  ne change pas, seul le moyen change : le contrôleur ne voit toujours jamais d'entité.

Limitez les transforms au formatage. Un `transform` qui décide entre `null` et `0`, ou
qui arrondit un score qui compte pour le produit, a déplacé une règle métier dans un
attribut où aucun test unitaire n'ira la chercher — cela relève de la méthode de service
ci-dessus.

## Quand se passer de la couche Read

Si la projection correspond déjà au contrat de l'API, projetez directement dans le DTO
Output et supprimez la classe intermédiaire. Trois DTO quasi identiques empilés par
souci de symétrie, c'est de la cérémonie, et la cérémonie est ce qui fait perdre son
argument à un standard.

Cette couche justifie sa place quand au moins l'une de ces conditions est vraie : la
requête renvoie des valeurs que le contrat n'expose pas telles quelles (moyennes
brutes, nulls de base de données, timestamps epoch) ; la même projection alimente deux
sorties différentes ; ou la normalisation comporte assez de décisions pour mériter son
propre test.

## Symptômes

| Symptôme | Cause | Correction |
|---|---|---|
| Un champ d'API a changé de forme après une modification du mapping Doctrine | Une entité est sérialisée | Introduire le DTO Output |
| `#[Ignore]` ou `#[Groups]` qui s'accumulent sur une seule classe | Un seul DTO utilisé dans les deux sens | Séparer Input et Output |
| `null` affiché comme `0` dans l'UI | La normalisation s'est produite dans Twig, ou dans un `transform` | La déplacer dans le service, avec un test |
| `MappingException: Mapping target not found` | `map()` appelé sans cible et sans `#[Map]` sur la source | Passer explicitement la classe cible |
| Seules certaines propriétés sont mappées | Les noms diffèrent entre la source et la cible | `#[Map(source: '…')]` sur la propriété cible |
| Un DTO Read avec des contraintes de validation | La couche a été confondue avec Input | Les contraintes n'ont leur place que sur Input |
