# N+1, jointures, projections et grands jeux de résultats

Vérifié avec **doctrine/orm 3.6.8 / doctrine/dbal 4.4.4 / Symfony 8.1.5 / PHP 8.4**. Tout
le DQL et le code QueryBuilder ci-dessous appartient à un repository — c'est le contrat de
couche, et `deptrac` l'impose une fois adopté (`symfony-proglab-quality`).

## Reconnaître un N+1

La signature est une rafale de requêtes quasi identiques ne différant que par un
paramètre :

```
SELECT ... FROM review WHERE book_id = 1
SELECT ... FROM review WHERE book_id = 2
SELECT ... FROM review WHERE book_id = 3
...
```

Personne ne les a écrites. Elles viennent d'une `PersistentCollection` qu'on touche — un
`foreach`, un `count()`, un `isEmpty()`, un `|length` Twig. Comme elles sont invisibles
depuis le point d'appel, le panneau Doctrine du profiler est le seul moyen honnête de les
voir : il affiche la backtrace pour chaque requête quand `profiling_collect_backtrace` est
actif, ce qui pointe vers la ligne exacte du template.

`symfony-proglab-doctrine` n'autorise une **relation bidirectionnelle** que là où le côté
inverse est réellement lu, et la garde unidirectionnelle sinon. Là où elle existe, c'est
un compromis délibéré : la navigation se lit bien (`$book->getReviews()`), et le prix est
que le lazy loading n'est qu'à un point de distance. La parade n'est pas « éviter les
relations bidirectionnelles », c'est le test mécanique de comptage de requêtes dans
`references/query-counting.md`.

## Correctif 1 — la fetch join

```php
/**
 * @return list<Book>
 */
public function findShelfWithReviews(): array
{
    return $this->createQueryBuilder('b')
        ->leftJoin('b.reviews', 'r')
        ->addSelect('r')                 // ← sans ça, ce n'est pas une jointure *de fetch*
        ->orderBy('b.title', 'ASC')
        ->getQuery()
        ->getResult();
}
```

`->addSelect('r')` est tout l'enjeu. Un `leftJoin` sans lui filtre et trie mais n'hydrate
rien, donc la collection reste paresseuse et le N+1 survit — une jointure qui a l'air
d'être un correctif et n'en est pas un. C'est le premier point à vérifier quand une fetch
join « n'a pas fonctionné ».

`leftJoin`, pas `innerJoin`, à moins qu'un livre sans avis ne doive réellement disparaître
de la liste. Un `innerJoin` raccourcit silencieusement les pages de liste, et le bug est
signalé comme « des livres manquent », pas comme un problème de jointure.

Les jointures conditionnelles utilisent `WITH`, jamais un `WHERE` sur l'alias joint :

```php
->leftJoin('b.reviews', 'r', Join::WITH, 'r.publishedAt IS NOT NULL')
```

Un `WHERE r.publishedAt IS NOT NULL` sur une left join la reconvertit en inner join,
parce que les lignes où `r` est `NULL` échouent à la condition.

## Correctif 2 — compter dans le repository, jamais sur la collection

```php
public function countReviewsFor(Book $book): int
{
    return (int) $this->getEntityManager()
        ->createQuery('SELECT COUNT(r.id) FROM App\Entity\Review r WHERE r.book = :book')
        ->setParameter('book', $book)
        ->getSingleScalarResult();
}
```

Pourquoi ceci plutôt que `count($book->getReviews())`, vérifié dans
`vendor/doctrine/orm/src/PersistentCollection.php` :

```php
public function count(): int
{
    if (! $this->initialized && $this->association !== null
        && $this->getMapping()->fetch === ClassMetadata::FETCH_EXTRA_LAZY) {
        return $persister->count($this) + ...;   // une requête COUNT
    }

    return parent::count();                      // initialise TOUTE la collection
}
```

En dehors de `EXTRA_LAZY`, `count()` hydrate chaque ligne pour retourner un entier. Sur un
livre avec 4 000 avis, ça fait 4 000 objets entités, tous ensuite suivis par l'UnitOfWork
pour le reste de la requête. `isEmpty()` est le même piège — il appelle `count()`.

`EXTRA_LAZY` (`#[ORM\OneToMany(fetch: 'EXTRA_LAZY')]`) fait émettre à exactement six
méthodes une requête ciblée au lieu d'hydrater — `count()`, `contains()`, `containsKey()`,
`get()`, `first()` et `slice()` — et résout effectivement le problème. (`isEmpty()` n'est
pas dans cet ensemble, mais elle délègue à `count()`, donc elle hérite du correctif.) C'est
rejeté ici parce que ça place une requête SQL derrière un appel `count()` dans un template
Twig, là où ni un lecteur, ni un relecteur, ni un analyseur statique ne la trouveront, et
parce que ça viole la règle selon laquelle toutes les requêtes vivent dans les
repositories. À utiliser uniquement sur une base de code héritée où extraire la méthode de
repository n'est pas réaliste — et à signaler dans un commentaire.

## Correctif 3 — ne pas hydrater ce qui ne sera pas utilisé

Une liste affichant quatre colonnes ne devrait pas construire d'entités. Projette dans un
DTO de lecture :

```php
// src/Dto/Read/ShelfRow.php
final readonly class ShelfRow
{
    public function __construct(
        public int $id,
        public string $title,
        public ReadingStatus $status,
        public ?\DateTimeImmutable $lastReadAt,
    ) {
    }
}
```

```php
// src/Repository/BookRepository.php
/**
 * @return list<ShelfRow>
 */
public function findShelfRows(): array
{
    return $this->createQueryBuilder('b')
        ->select(\sprintf('NEW %s(b.id, b.title, b.status, b.lastReadAt)', ShelfRow::class))
        ->orderBy('b.title', 'ASC')
        ->getQuery()
        ->getResult();
}
```

Comportement vérifié de `SELECT NEW` :

- La conversion de type DBAL s'applique, donc une colonne mappée
  `enumType: ReadingStatus::class` arrive sous forme de cas d'enum et une colonne
  `date_immutable` sous forme de `\DateTimeImmutable`. Tu ne parses pas les chaînes à la
  main.
- L'identity map reste **vide** — rien n'est managé, rien n'est suivi pour modification,
  et les objets sont récupérés par le garbage collector normalement.
- Les propriétés de constructeur promues sur une classe `final readonly` fonctionnent ;
  les arguments sont positionnels et doivent correspondre exactement à l'ordre du
  constructeur.

Contraintes à connaître avant d'y recourir : seuls des champs scalaires et d'autres
expressions `NEW` peuvent être passés (pas d'associations, pas de collections), et les
objets résultants ne peuvent pas être persistés — ce qui est le but. `src/Dto/Read/` est
l'endroit où ils vivent ; le service normalise Read → Output. Quand la projection
correspond déjà au contrat de l'API, projette directement dans le DTO Output et saute une
couche qui ne ferait que copier des champs.

## Pagination avec une fetch join

Combiner `->addSelect('r')` avec `setMaxResults(20)` retourne moins de 20 livres, parce
que le LIMIT s'applique à l'ensemble des lignes jointes — un livre avec trois avis
consomme trois lignes. Le paginateur propre à Doctrine gère ça :

```php
use Doctrine\ORM\Tools\Pagination\Paginator;

$query = $this->createQueryBuilder('b')
    ->leftJoin('b.reviews', 'r')->addSelect('r')
    ->orderBy('b.title', 'ASC')
    ->setFirstResult(($page - 1) * $perPage)
    ->setMaxResults($perPage)
    ->getQuery();

$paginator = new Paginator($query, fetchJoinCollection: true);

return new ShelfPage(
    items: iterator_to_array($paginator),
    total: count($paginator),
);
```

Budgète-le dans ton assertion de comptage de requêtes : avec `fetchJoinCollection: true`,
une fetch join paginée coûte **trois** requêtes — un `COUNT`, une sous-requête
sélectionnant les ids racine de la page, et la requête d'hydratation restreinte à ces ids.
C'est le nombre correct, et il ne croît pas avec la taille de la page. C'est une classe
propre à Doctrine, pas un bundle ; le rejet de Pagerfanta par la charte ne s'applique pas
à elle.

## Grands jeux de résultats

`getResult()` construit tout le tableau en mémoire. Pour un export ou un batch, itère :

```php
public function streamAllForExport(): iterable
{
    return $this->createQueryBuilder('b')
        ->orderBy('b.id', 'ASC')
        ->getQuery()
        ->toIterable();
}
```

`toIterable()` hydrate une ligne à la fois depuis un résultat DBAL ouvert. Trois règles
l'accompagnent :

- **Nettoie périodiquement.** Les entités hydratées s'accumulent dans l'UnitOfWork, donc
  la mémoire continue de croître sans un `$em->clear()` toutes les N lignes.
- **`clear()` détache tout.** Toute entité que tu conservais à travers la boucle devient
  périmée — recharge-la par id après le nettoyage, ne réutilise pas l'ancienne référence.
- **N'exécute pas d'autres requêtes sur la même connexion à l'intérieur de la boucle**
  sauf si le driver le supporte ; bufferise les ids et traite-les après.

```php
$i = 0;
foreach ($this->books->streamAllForExport() as $book) {
    $writer->write($this->mapper->map($book, BookRow::class));

    if (0 === ++$i % 500) {
        $this->em->clear();
    }
}
```

Mieux encore, quand l'export n'a besoin que de quelques colonnes : `toIterable()` sur une
projection `SELECT NEW`. Rien n'est managé, donc il n'y a rien à nettoyer et la boucle
reste plate.

## Choisir entre les correctifs

| Situation | Correctif |
|---|---|
| Un template de liste lit une relation de chaque ligne | Fetch join dans la méthode de repository utilisée par la liste |
| Un template affiche « N avis » | Méthode de repository `count…For()` |
| Une liste affiche quelques colonnes | `SELECT NEW` dans un DTO de lecture — pas de jointure nécessaire pour des scalaires |
| Une page de détail a besoin d'un livre plus toutes ses relations | Fetch join, `getSingleResult()` |
| Un export ou un batch sur toute la table | `toIterable()` + `clear()`, idéalement sur une projection |
| Une relation n'est nécessaire que parfois | Une seconde méthode de repository. Deux requêtes honnêtes valent mieux qu'une requête avec un argument booléen |

## Ce qu'il ne faut pas faire

- **Ne pas mettre `fetch: 'EAGER'` sur le mapping** pour corriger une page. Ça s'applique
  partout, y compris sur les pages qui ne voulaient pas de la jointure, et ça transforme un
  problème visible en un problème diffus.
- **Ne pas mettre la page en cache pour masquer le N+1.** Les requêtes s'exécutent quand
  même à chaque cache miss, et le miss survient précisément quand le système est sous
  charge.
- **Ne pas ajouter un index avant de lire le plan.** Le N+1 est un problème de nombre, pas
  de durée ; indexer 60 requêtes rapides laisse 60 allers-retours.
