# Repositories et requêtes

La seule couche autorisée à parler SQL, et comment lui faire retourner le moins de choses
possible. Vérifié avec Doctrine ORM 3.6 / doctrine-bundle 3.3.

## Sommaire

- [Anatomie](#anatomie)
- [Nommer les méthodes](#nommer-les-méthodes)
- [persist et remove, jamais flush](#persist-et-remove-jamais-flush)
- [Les bases de QueryBuilder](#les-bases-de-querybuilder)
- [Fetch joins et HIDDEN](#fetch-joins-et-hidden)
- [Projections SELECT NEW](#projections-select-new)
- [Agrégats et leurs types PHP](#agrégats-et-leurs-types-php)
- [Compter](#compter)
- [Pagination](#pagination)
- [Résultats uniques et leurs exceptions](#résultats-uniques-et-leurs-exceptions)
- [SQL brut](#sql-brut)
- [Transactions](#transactions)
- [PHPStan](#phpstan)

## Anatomie

```php
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Pas `final` : les tests unitaires de service la doublent.
 *
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }
}
```

Ces deux imports sont ceux qu'on se trompe le plus souvent à taper : `ManagerRegistry`
vient de `Doctrine\Persistence`, pas de `Doctrine\ORM` ni du registre DBAL ;
`ServiceEntityRepository` vient du **bundle**, pas de l'ORM. Une erreur d'autowiring
nommant `ManagerRegistry` en est presque toujours la cause.

À l'intérieur de la classe, `$this->getEntityManager()` (protégé) et
`$this->createQueryBuilder()` sont ce dont tu disposes. `$this->_em` a été retiré en ORM 3
et ne reviendra pas.

## Nommer les méthodes

Nomme la méthode d'après ce que voulait l'appelant, pas d'après le SQL qu'elle produit.

| Écris ceci | Pas ceci |
|---|---|
| `findLatestPublishedForBook()` | `findByBookOrderedByDateDesc()` |
| `countUnreadForShelf()` | `countByStatusNull()` |
| `findShelfRows()` | `findAllWithJoin()` |

Deux raisons. Le site d'appel se lit comme une phrase, et la méthode continue de dire
la vérité quand la requête change — ajouter une clause `publishedAt IS NOT NULL` à
`findByBookOrderedByDateDesc` fait mentir le nom, et personne ne le renomme.

Le corollaire : **aucun `findBy(['status' => …])` depuis un service.** Les finders
magiques et les critères sous forme de tableau sont du DQL déguisé, et deptrac ne peut pas
les voir.

## persist et remove, jamais flush

```php
public function add(Review $review): void
{
    $this->getEntityManager()->persist($review);
}
```

Plus `remove()`, qui appelle `$this->getEntityManager()->remove()`. C'est toute l'API
d'écriture. Le service décide quand l'unité de travail est terminée et appelle `flush()`
une fois. Un repository qui flush fait que « créer trois, puis sauver » coûte trois
transactions, et retire le seul endroit où un rollback aurait pu être décidé.

## Les bases de QueryBuilder

```php
return $this->createQueryBuilder('r')
    ->andWhere('r.author = :author')
    ->andWhere('r.publishedAt >= :since')
    ->setParameter('author', $author)
    ->setParameter('since', $since)
    ->orderBy('r.publishedAt', 'DESC')
    ->getQuery()
    ->getResult();
```

- **`andWhere()` dès la première clause**, jamais `where()`. `where()` supprime
  silencieusement tout ce qui a été ajouté avant lui, ce qui est comment un filtre
  conditionnel efface le contrôle de sécurité placé au-dessus.
- **Toujours `setParameter()`.** Interpoler dans du DQL est une injection et ça
  désactive le cache de requêtes. Les entités peuvent être passées directement — Doctrine
  prend l'identifiant.
- **Le DQL nomme des propriétés, pas des colonnes.** `r.publishedAt`, pas
  `r.published_at`. Le message d'erreur pour un nom de colonne est
  `has no field or association named`.
- `IN` accepte un tableau en paramètre directement :
  `->andWhere('b.id IN (:ids)')->setParameter('ids', $ids)`.

## Fetch joins et HIDDEN

Pour éviter le N+1 quand l'appelant va parcourir une relation, joins-la **et
sélectionne-la** :

```php
->leftJoin('r.author', 'a')
->addSelect('a')          // sans ça, chaque auteur est une requête séparée
```

`addSelect('a')` est ce qui en fait un fetch join. Le `leftJoin` seul ne fait que rendre
l'alias disponible pour `WHERE`.

`HIDDEN` place une valeur calculée dans `ORDER BY` ou `GROUP BY` sans l'ajouter au
résultat. Sans lui, l'hydrateur transforme chaque ligne en `[0 => Book, 'expr' => …]` et
la méthode ne retourne plus un `list<Book>` :

```php
->addSelect('CASE WHEN b.lastReadAt IS NULL THEN 1 ELSE 0 END AS HIDDEN never_read')
->orderBy('never_read', 'ASC')
->addOrderBy('b.lastReadAt', 'DESC')
```

## Projections SELECT NEW

Une page de liste qui hydrate des entités paie pour l'identity map, le change tracking et
chaque colonne, puis n'affiche que quatre champs. Projette plutôt dans un DTO dans
`src/Dto/Read/` :

```php
// src/Dto/Read/ShelfStatsRow.php — la projection d'agrégat.
// `symfony-proglab-architecture`, references/dtos.md, possède la forme de cette classe ; garde les
// deux synchronisées. La projection simple sans agrégat est une classe différente,
// `ShelfRow` (voir `symfony-proglab-performance`) — même table, contrat différent.
final readonly class ShelfStatsRow
{
    public function __construct(
        public int $bookId,
        public string $title,
        public float|string|null $averageRating,   // NULL quand personne ne l'a noté
        public int|string $reviewCount,
        public ?\DateTimeImmutable $lastReadAt,
    ) {
    }
}
```

```php
/** @return list<ShelfStatsRow> */
public function findShelfStats(): array
{
    return $this->createQueryBuilder('b')
        ->select(sprintf(
            'NEW %s(b.id, b.title, AVG(r.rating), COUNT(r.id), b.lastReadAt)',
            ShelfStatsRow::class,
        ))
        ->leftJoin('b.reviews', 'r')
        ->groupBy('b.id')
        ->getQuery()
        ->getResult();
}
```

Trois choses à savoir, toutes vérifiées en les exécutant :

- Le nom de classe dans `NEW` est pleinement qualifié **sans antislash au début**, ce qui
  est exactement ce que donne `::class` — construis-le avec `sprintf()` plutôt que de
  coder en dur une chaîne qu'aucun IDE ne saura renommer.
- Les arguments du constructeur sont positionnels. Il n'existe pas de forme par argument
  nommé, et une erreur d'appariement remonte comme un `TypeError` du constructeur du DTO,
  pas comme une erreur DQL.
- **Une colonne mappée avec `enumType:` arrive comme l'instance d'enum**, pas comme la
  valeur backed — projette donc `b.status` dans une propriété typée `ReadingStatus`,
  jamais `string`, sous peine de lever `must be of type string, ReadingStatus given`. La
  même conversion s'applique pour `date_immutable`, ce qui explique pourquoi
  `lastReadAt` ci-dessus est un `\DateTimeImmutable` et non une chaîne. Les agrégats sont
  l'exception : ils n'ont aucun type Doctrine par lequel passer, ce qui fait l'objet de la
  section suivante.

Pourquoi c'est meilleur que d'hydrater des entités : aucune entrée d'identity map, aucun
calcul de change-set au flush suivant, aucun proxy paresseux prêt à déclencher une requête
depuis un template, et seules les colonnes nommées transitent sur le réseau.

Le DTO Read porte des valeurs brutes dans la forme que la requête dicte. Le service
normalise Read → Output — arrondir la moyenne, formater les dates en chaînes ISO, décider
si une note absente est `null` ou `0`. Cette normalisation est une règle métier, elle a sa
place dans un service, et elle est testable unitairement sans base de données. Quand la
projection correspond déjà exactement au contrat de l'API, projette directement dans le
DTO Output et saute la couche.

## Agrégats et leurs types PHP

Les colonnes d'agrégat n'ont aucun type Doctrine par lequel passer, donc c'est le driver
qui décide. Sur SQLite, `COUNT` et `SUM` arrivent en `int` et `AVG` en `float` (vérifié).
Sur PostgreSQL, `COUNT`/`SUM` retournent du `bigint` et `AVG` retourne du `numeric`, que
PDO remet couramment sous forme de **chaînes**. Vérifie sur ta propre plateforme plutôt
que de faire confiance à l'une ou l'autre.

Ce n'est pas un détail à deviner. Déclare l'union (`int|string`, `float|string|null`) et
laisse le service caster — ce qui est la forme honnête pour un DTO Read, dont tout le
rôle est de porter des valeurs brutes. Les types étroits ne sont sûrs que pour les
colonnes qui sont des champs d'entité mappés, où Doctrine a fait la conversion.

`AVG` sur un groupe vide retourne `null`, pas `0`. Un `leftJoin` plus `GROUP BY` produit
exactement ça pour chaque parent sans enfant, donc la propriété du DTO Read est nullable
et le service décide de ce que « pas encore de note » affiche.

## Compter

```php
public function countPublishedForBook(Book $book): int
{
    return (int) $this->createQueryBuilder('r')
        ->select('COUNT(r.id)')
        ->andWhere('r.book = :book')
        ->andWhere('r.publishedAt IS NOT NULL')
        ->setParameter('book', $book)
        ->getQuery()
        ->getSingleScalarResult();
}
```

Le cast `(int)` est à la fois une protection de plateforme et ce qui rend le type de
retour déclaré `int` honnête aux yeux de PHPStan — `getSingleScalarResult()` est déclaré
`mixed`.

Pour une liste, une seule requête pour toute la page plutôt qu'une par ligne : groupe par
id du parent et retourne une carte.

```php
/** @return array<int, int> id du livre => nombre d'avis */
public function countPublishedByBook(): array
{
    $rows = $this->createQueryBuilder('r')
        ->select('IDENTITY(r.book) AS bookId, COUNT(r.id) AS total')
        ->andWhere('r.publishedAt IS NOT NULL')
        ->groupBy('r.book')
        ->getQuery()
        ->getResult();

    // Cast, pour la raison énoncée deux paragraphes plus haut : sur PostgreSQL les deux
    // colonnes arrivent comme des chaînes via PDO, et sans ça l'annotation `array<int,
    // int>` est tout simplement fausse. Une carte indexée est un contrat plutôt qu'une
    // projection brute, donc elle est restreinte ici plutôt que dans le service.
    return array_map(intval(...), array_column($rows, 'total', 'bookId'));
}
```

`IDENTITY(r.book)` retourne la clé étrangère sans joindre ni hydrater le parent.
L'argument d'index de `array_column` redonne les clés numériques sous forme de chaînes en
`int` (coercition des clés de tableau PHP), donc seules les valeurs avaient besoin du
cast.

`ServiceEntityRepository::count(array $criteria = [])` existe et convient pour
`count([])`. Tout ce qui porte une condition qui mérite un nom mérite une méthode nommée,
pour la même raison que `findBy()`.

## Pagination

Le repository retourne la page et le total ; l'enveloppe est l'affaire de l'appelant (voir
`symfony-proglab-http` pour la forme `items` + `meta`).

```php
->setFirstResult(($page - 1) * $perPage)
->setMaxResults($perPage)
```

`setMaxResults()` combiné à un fetch join sur une *collection* limite les lignes jointes,
pas les entités racines, donc une page contient silencieusement moins de livres que
demandé. `Doctrine\ORM\Tools\Pagination\Paginator` corrige ça en découpant le travail —
utilise-le exactement dans ce cas, et un simple `setMaxResults()` sinon. Budgète **trois**
requêtes pour ça, pas deux : avec `fetchJoinCollection: true` et un `count($paginator)`
pour le `total` de l'enveloppe, ça exécute un `COUNT`, une sous-requête sélectionnant les
ids racines de la page, et la requête d'hydratation (`symfony-proglab-performance`,
`references/queries.md`). Ça compte quand tu écris l'assertion de comptage de requêtes.
Rejeté : Pagerfanta, une dépendance pour ce que deux entiers expriment déjà.

## Résultats uniques et leurs exceptions

| Méthode | Aucune ligne | Plusieurs lignes |
|---|---|---|
| `getOneOrNullResult()` | `null` | `Doctrine\ORM\NonUniqueResultException` |
| `getSingleResult()` | `Doctrine\ORM\NoResultException` | `NonUniqueResultException` |
| `getSingleScalarResult()` | `NoResultException` | `NonUniqueResultException` |

`getOneOrNullResult()` est presque toujours celle qu'il faut : « non trouvé » est un
résultat normal que l'appelant gère, pas une exception. Après un `COUNT`,
`getSingleScalarResult()` ne lève jamais pour cause de vide — un compte de zéro reste une
ligne.

## SQL brut

L'échappatoire, toujours à l'intérieur d'un repository :

```php
$rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params);
```

Légitime pour une CTE récursive, une fonction de fenêtrage que le DQL ne peut pas
exprimer, ou un `UPDATE … WHERE` en masse sur un million de lignes. Deux conséquences : le
résultat est un tableau brut, donc convertis-le en DTO Read avant qu'il ne quitte le
repository ; et un `UPDATE` brut contourne l'unité de travail, donc les entités en mémoire
sont périmées — exécute-le avant le chargement, ou `clear()` après. N'interpole jamais :
`fetchAllAssociative()`, `executeQuery()` et `executeStatement()` prennent tous `$params`
et `$types`.

## PHPStan

Le niveau max a besoin de trois annotations et donne tout le reste gratuitement :

```php
/** @extends ServiceEntityRepository<Review> */   // sur la classe
/** @return list<Review> */                        // sur chaque méthode retournant getResult()
/** @var Collection<int, Review> */                // sur chaque propriété de collection mappée
```

`list<T>` plutôt que `array<T>` : Doctrine retourne des clés séquentielles, c'est donc
plus précis et gratuit. `getResult()` est déclaré `mixed`, donc sans l'annotation chaque
site d'appel se dégrade. Quand un ignore est la bonne réponse :
`references/phpstan.md` de `symfony-proglab-quality` lui-même.

## Transactions

Un seul `flush()` est déjà une transaction. Ne recours à
`$em->wrapInTransaction(fn () => …)` que quand plusieurs flushes doivent réussir ou
échouer ensemble — un import par lot, un changement d'état plus une écriture de grand
livre. Ça valide au retour et annule sur toute exception, ce qui est le but : des paires
`beginTransaction()` / `commit()` écrites à la main laissent fuiter une transaction
ouverte dès que quelqu'un ajoute un `return` prématuré. (L'isolation des tests était
autrefois l'unique exception ; ce n'est plus le cas — `dama/doctrine-test-bundle` possède
désormais la transaction à cet endroit, voir `symfony-proglab-testing`.)

`wrapInTransaction()` fonctionne sans changement sous DAMA : DBAL l'imbrique avec un
savepoint à l'intérieur de la transaction externe du bundle.
