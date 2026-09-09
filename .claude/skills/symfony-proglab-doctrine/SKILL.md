---
name: symfony-proglab-doctrine
description: >-
  La couche de persistance d'un projet Symfony : entités au format maker avec mapping par
  attributs, identifiants entiers auto-incrémentés, \DateTimeImmutable et enums natifs ;
  les relations et les pièges de collection qu'elles ouvrent ; les repositories comme seul
  endroit où DQL, QueryBuilder et SQL brut sont autorisés ; les projections SELECT NEW
  dans des DTOs Read ; qui appelle flush() ; et les migrations générées puis relues et
  corrigées à la main. Utilise ce skill dès qu'on demande de créer ou modifier une entité,
  d'ajouter un champ ou une colonne, de stocker ou persister quelque chose, de lier deux
  choses entre elles, d'ajouter une relation ou une clé étrangère, d'écrire ou corriger une
  méthode de repository, d'écrire une requête, de trier ou filtrer une liste depuis la base
  de données, de compter des lignes, de faire retourner moins de données à une requête, de
  créer ou exécuter une migration, de renommer une colonne sans perdre de données, ou pose
  la question « pourquoi ma requête est fausse », « pourquoi c'est null », « pourquoi
  l'enregistrement ne fonctionne pas », « le schéma est désynchronisé », « j'ai perdu des
  données après un déploiement ».
---

# Doctrine

> **Niveau : socle** — une entité, un repository et une migration relue ne sont pas optionnels. Les projections `SELECT NEW` sont la partie à la demande : à utiliser quand une liste hydrate plus qu'elle n'affiche.

Entités, relations, repositories et migrations qui survivent au contact des vraies données.

Tout ici découle d'une seule décision : **les entités sont bêtes**. Elles ont la forme du
maker — propriétés privées, getters et setters, attributs de mapping, rien d'autre. Ce
choix a des conséquences sur toute la pile, donc autant énoncer la conséquence avant les
règles.

`vendor/` fait foi sur tout ce qui est écrit ici. Ce skill a été vérifié avec Doctrine ORM
3.6, DBAL 4.4, doctrine-bundle 3.3 et Symfony 8.1 ; plusieurs API que d'anciens articles de
blog montrent encore n'existent plus.

## L'entité, et ce qu'elle ne peut pas faire

```php
#[ORM\Entity(repositoryClass: BookRepository::class)]
class Book
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: ReadingStatus::class)]
    private ReadingStatus $status;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastReadAt = null;

    // getId(), getStatus(), setStatus(), … générés par make:entity
}
```

Identifiants entiers auto-incrémentés. Rejetés : UUIDv7, ULID, un id interne plus un id
public. Ils résolvent des problèmes que ce standard n'a pas (génération d'id hors ligne,
masquer le nombre de lignes) et ils coûtent de la taille d'index, de la lisibilité dans
les logs, et une discussion à chaque revue.

`\DateTimeImmutable`, jamais `\DateTime`. Une date mutable transmise à un appelant peut
être modifiée dans le dos de l'entité, et le changement est silencieusement persisté au
flush suivant — un bug sans cause visible. Utilise `Types::DATE_IMMUTABLE` ou
`Types::DATETIME_IMMUTABLE` ; les types `date`/`datetime` bruts sont mappés vers
`\DateTime`.

Enums natifs avec `enumType:` pour les ensembles de valeurs fermés, afin que la propriété
soit vraiment typée et que PHPStan puisse raisonner dessus.

**La conséquence, dite clairement : une entité avec un setter pour chaque propriété ne
garantit rien par elle-même.** La forme du maker est conservée parce qu'une règle sans
exception est facile à tenir et parce que `make:entity`, Form, les fixtures et EasyAdmin
en dépendent tous ; le prix est que la garantie repose sur le fait que chaque écriture
passe par un service. N'écris donc pas `publish()`, `rate()` ou `archive()` sur une
entité : une méthode qui protège un chemin à côté d'un setter qui n'en protège aucun n'est
que du décor. Chaque règle métier vit dans un service — l'entité est une ligne typée, le
service porte le comportement, le repository porte le SQL.

**L'unique exception : une surface d'administration qui contourne les services.**
EasyAdmin écrit directement dans l'entité, donc aucune règle de service ne s'applique là.
Traite-la comme une surface de confiance, et quand une règle locale doit malgré tout
tenir dans l'admin, duplique-la sous forme de contrainte `#[Assert]` sur la propriété de
l'entité, en lisant les mêmes constantes que le service et le DTO Input
(`Book::MAX_RATING`). EasyAdmin valide l'entité, donc la contrainte produit une erreur de
formulaire là-bas ; elle reste inerte sur la frontière HTTP, où c'est le DTO Input qui est
validé (`symfony-proglab-http`). Même chose pour `#[UniqueEntity]` : sur le DTO Input pour
l'API, sur l'entité aussi seulement si l'admin édite ce champ.

Détails de mapping, propriété de la relation, cascade et `orphanRemoval` :
`references/entities-and-relations.md`.

## Les relations, et la ligne qui va tuer une page

Bidirectionnelle quand le côté inverse est réellement utilisé — quand un template ou un
service parcourt vraiment le livre jusqu'à ses avis. Unidirectionnelle sinon ; un
`mappedBy` que personne ne lit est une collection que Doctrine doit gérer pour rien.

Le prix du bidirectionnel est un piège précis :

```php
count($book->getRatings())          // charge chaque ligne de note en mémoire
$book->getRatings()->count()        // pareil
{{ book.ratings|length }}           // pareil, dans un template, dans une boucle
```

`PersistentCollection::count()` initialise la collection sauf si l'association est mappée
`fetch: 'EXTRA_LAZY'`. Sur une liste de 50 livres, ce sont 50 requêtes supplémentaires et
chaque note de la base hydratée en objet, pour afficher un nombre.

**Compte dans le repository plutôt** — `countByBook(Book $book): int` faisant
`->select('COUNT(r.id)')`. Pour une liste, une seule requête groupée retournant les
comptes par livre, pas une requête par ligne. Le test de comptage de requêtes qui le
prouve appartient à `symfony-proglab-performance`.

`fetch: 'EXTRA_LAZY'` fait que `count()` émet un `SELECT COUNT(*)` plutôt que d'hydrater,
et c'est un correctif légitime sur une base de code existante où les appels sont
partout. Ce n'est pas le défaut ici, parce que ça fait paraître gratuit un appel coûteux
et ça cache la requête à la personne qui lit le template.

## Repositories

Le seul endroit de l'application où DQL, QueryBuilder ou SQL brut peuvent apparaître. Pas
une préférence de style : c'est ce qui permet à `deptrac` de faire respecter le contrat de
couches, et ce qui rend une page lente trouvable en grepant un seul répertoire.

```php
/**
 * Pas `final` volontairement : les tests unitaires de service la doublent.
 *
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /** @return list<Review> */
    public function findLatestPublishedForBook(Book $book, int $limit = 10): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.book = :book')
            ->andWhere('r.publishedAt IS NOT NULL')
            ->setParameter('book', $book)
            ->orderBy('r.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
```

Quatre choses dans cet extrait sont des règles :

- **`ManagerRegistry` est `Doctrine\Persistence\ManagerRegistry`**, et
  `ServiceEntityRepository` est `Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository`.
  Se tromper sur ces deux imports est la cause la plus courante d'échec d'autowiring
  Doctrine.
- **Les repositories ne sont pas `final`.** Tout le reste dans ce standard l'est —
  services, contrôleurs, DTOs, voters, handlers — mais un test unitaire de service a
  besoin de doubler son repository, et PHPUnit ne peut pas doubler une classe finale.
  L'unique exception délibérée.
- **`@extends ServiceEntityRepository<Review>` et `@return list<Review>`.** Sans
  l'annotation générique, `find()` retourne `object` et PHPStan au niveau max se plaint à
  chaque site d'appel ; sans l'annotation de retour, `getResult()` est `mixed`.
- **La méthode est nommée d'après l'intention de l'appelant**, pas d'après le mécanisme.
  `findLatestPublishedForBook` se lit au site d'appel ; `findByBookIdOrderedByDate` oblige
  l'appelant à reconstruire le pourquoi, et ment dès que la requête change.

`$this->_em` n'existe plus — il a été retiré avec ORM 3. Utilise la méthode protégée
`$this->getEntityManager()`.

## Retourner moins : SELECT NEW dans un DTO Read

Une page de liste qui hydrate des entités paie pour l'identity map, le change tracking et
chaque colonne de chaque ligne, puis n'utilise que quatre champs. Projette plutôt :

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

Le DTO vit dans `src/Dto/Read/` et porte des valeurs brutes dans la forme que la requête
dicte. Le service normalise Read → Output (arrondi, dates ISO, null contre 0) ; cette
normalisation est une règle métier et est testable unitairement sans base de données. Si
la projection correspond déjà au contrat de l'API, projette directement dans un DTO
Output et saute la couche.

Deux comportements vérifiés qui déterminent le typage du DTO : une colonne mappée avec
`enumType:` arrive dans le constructeur **en tant qu'enum**, pas en tant que chaîne ; et
les colonnes d'agrégat arrivent avec des types PHP dépendants de la plateforme. Détails et
le patron complet : `references/repositories-and-queries.md`.

## Qui appelle flush()

**Le service, une fois par cas d'usage.** Le repository fait `persist()` et `remove()` et
s'arrête là.

```php
$book = (new Book())->setTitle($input->title);   // forme du maker : pas d'arguments de constructeur
$this->books->add($book);          // repository : persist() seulement
$this->entityManager->flush();     // service : un flush, un cas d'usage
$this->notifier->notifyShelfOwner($book);
```

Un repository qui flush retire la décision au seul objet qui sait si l'unité de travail
est terminée — et ça fait que « ajouter trois livres puis sauver » coûte trois
transactions. Un service qui flush deux fois dans une méthode a généralement deux cas
d'usage dedans.

`$entityManager->wrapInTransaction(callable $func)` quand plusieurs flushes doivent
vraiment réussir ou échouer ensemble (un import par lot, un changement d'état plus une
écriture de grand livre). Pas par défaut : un seul `flush()` est déjà atomique.

## Les migrations sont du code, et sont relues comme du code

```bash
php bin/console make:migration          # ou doctrine:migrations:diff
# relis le fichier, corrige-le, puis :
php bin/console doctrine:migrations:migrate
```

**Le fichier généré est un brouillon, jamais un livrable.** Le générateur compare deux
schémas. Il ne sait rien des lignes déjà présentes dans la table, donc une propriété
renommée ressort comme `DROP COLUMN` plus `ADD COLUMN` — ce qui est du SQL correct, passe
la CI, et détruit le contenu de la colonne en production. Le correctif est une ligne
retouchée à la main (`ALTER TABLE … RENAME COLUMN …`), et rien d'autre qu'un humain
lisant le fichier ne le repérera.

Ça vaut aussi pour une nouvelle colonne `NOT NULL` sur une table peuplée, pour un
changement de type qui tronque silencieusement, et pour `down()`, que le générateur écrit
comme l'inverse mécanique, que cet inverse soit possible ou non.

**Jamais `doctrine:schema:update` en production.** Ça répond à « fais correspondre la
base à la mapping », ce qui est une question différente de « comment aller du schéma
existant, avec ses données, à celui que je veux ». Corriger une migration générée, les
migrations de données et les pièges de plateforme : `references/migrations.md`.

## Quand quelque chose ne va pas

| Symptôme | Cause |
|---|---|
| Les changements ne sont pas sauvegardés | Personne n'a appelé `flush()`, ou le service a flush avant de muter |
| Une page de liste déclenche des centaines de requêtes | `count($x->getItems())` dans une boucle, ou une relation parcourue dans un template |
| `Cannot autowire … ManagerRegistry` | Import de `Doctrine\ORM\…` ou du registre DBAL au lieu de `Doctrine\Persistence\ManagerRegistry` |
| `getId()` est null après la sauvegarde | Lecture avant `flush()` ; l'identifiant est assigné par l'insertion |
| Une date a changé toute seule | `\DateTime` au lieu de `\DateTimeImmutable`, mutée par un appelant |
| `nullable` ignoré sur un `ManyToOne` | `ManyToOne` n'a pas d'argument `nullable` — il appartient à `#[ORM\JoinColumn]` |
| Schéma désynchronisé, aucune migration en attente | Mapping édité sans générer de migration. Lance `doctrine:schema:validate` |
| Supprimer un parent lève une erreur de clé étrangère | `cascade: ['remove']` ou `orphanRemoval` manquant, ou c'est l'autre côté qui est propriétaire |
| Un enfant supprimé revient | `orphanRemoval` non défini : retirer de la collection ne fait que désassocier la référence |
| Données perdues après un déploiement | Un `DROP`/`ADD` généré a été livré là où un `RENAME` était nécessaire |

`php bin/console doctrine:schema:validate` est le contrôle qui vérifie que la mapping
correspond toujours à la base. Il valide la mapping, l'état de synchronisation, et —
depuis ORM 3 — que les types de propriétés PHP correspondent à leurs types Doctrine. Il a
sa place en CI, où une migration oubliée fait échouer le build plutôt que le déploiement.

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/entities-and-relations.md` | Ajouter un champ ou une relation, choisir cascade / `orphanRemoval` / le côté propriétaire, mapper un enum ou une date |
| `references/repositories-and-queries.md` | Écrire ou corriger une méthode de repository, projections, agrégats, pagination, annotations PHPStan |
| `references/migrations.md` | Avant d'exécuter une migration générée, renommer ou reconstituer une colonne, déployer un changement de schéma |
