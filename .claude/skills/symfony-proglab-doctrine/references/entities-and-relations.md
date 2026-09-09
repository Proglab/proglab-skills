# Entités et relations

Un mapping qui survit à un changement de schéma, et les pièges de relation qui n'apparaissent
que sous charge. Vérifié avec Doctrine ORM 3.6 / DBAL 4.4.

## Sommaire

- [La forme](#la-forme)
- [Colonnes](#colonnes)
- [Enums](#enums)
- [Dates](#dates)
- [ManyToOne: le côté propriétaire](#manytoone-le-côté-propriétaire)
- [OneToMany: le côté inverse](#onetomany-le-côté-inverse)
- [cascade et orphanRemoval](#cascade-et-orphanremoval)
- [Le piège du comptage de collection, en détail](#le-piège-du-comptage-de-collection-en-détail)
- [ManyToMany](#manytomany)
- [Référence des attributs](#référence-des-attributs)

## La forme

`make:entity` la génère et c'est la forme à conserver : propriétés privées, un getter et
un setter par champ, `#[ORM\Entity(repositoryClass: …)]` sur la classe, un attribut de
mapping par propriété. Aucun argument de constructeur pour les champs mappés, aucune
propriété promue, aucune méthode métier.

La promotion de constructeur avec des attributs de mapping sur les paramètres promus
fonctionne bien avec ORM 3, et ça paraît plus soigné. Ce n'est pas utilisé ici pour une
raison : le `ClassSourceManipulator` du maker n'a aucune notion de propriétés promues,
donc `make:entity` ne peut pas mettre à jour une classe écrite ainsi — chaque champ
suivant est ajouté à la main et le générateur cesse d'être utilisable sur le projet.
C'est un coût réel payé pour du cosmétique.

Une entité peut porter des constantes (`Book::MAX_RATING`) — une constante n'est pas un
invariant, c'est une valeur que le validateur et le service lisent tous les deux.

## Colonnes

```php
#[ORM\Column(length: 255)]                        // string, VARCHAR(255)
#[ORM\Column(type: Types::TEXT)]                  // string, non borné
#[ORM\Column]                                     // déduit du type PHP
#[ORM\Column(nullable: true)]                     // NULL autorisé
#[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]   // string en PHP
#[ORM\Column(type: Types::JSON)]                  // array
```

Doctrine déduit le type à partir du type PHP de la propriété quand `type:` est omis, ce
qui explique pourquoi la plupart des colonnes générées par le maker ne portent que
`length:` ou `nullable:`. Laisse-le déduit : le type de la propriété devient alors la
source unique de vérité, et `doctrine:schema:validate --skip-sync` te dira quand les deux
divergent.

`Types::DECIMAL` s'hydrate en **string**, pas en float — délibérément, parce qu'un float
ne peut pas représenter de l'argent. Type la propriété en `string`, et fais l'arithmétique
dans un service avec `bcmath` ou des centimes entiers.

`unique: true` sur `#[ORM\Column]` crée la contrainte en base de données. Ça ne produit
pas de message d'erreur exploitable ; c'est le rôle de `#[UniqueEntity]` sur le DTO
d'entrée, qui appartient à `symfony-proglab-http`. Garde les deux : la contrainte est la
garantie, le validateur est le message.

## Enums

```php
enum ReadingStatus: string
{
    case ToRead = 'to_read';
    case InProgress = 'in_progress';
    case Read = 'read';
}

#[ORM\Column(enumType: ReadingStatus::class)]
private ReadingStatus $status;
```

Toujours un enum **backed**, et adosse-le à des strings plutôt qu'à des entiers. La
valeur stockée est ce qu'un DBA lit dans `psql` et ce qu'un export contient ;
`'in_progress'` s'explique de lui-même, `2` non, et réordonner les cases réinterprète
silencieusement chaque ligne existante.

La conversion `enumType` s'applique partout où la colonne est sélectionnée — y compris à
l'intérieur d'une projection `SELECT NEW`, où le constructeur du DTO reçoit l'instance de
l'enum, pas la string. Type la propriété du DTO Read avec l'enum.

Ajouter un case est gratuit. En retirer un est une migration de données : les lignes
existantes portent encore l'ancienne valeur, et les hydrater lève
`Doctrine\ORM\Mapping\MappingException` — `invalidEnumValue`, levée quand
`ReadingStatus::from()` échoue. Migre les lignes dans le même déploiement que celui qui
retire le case, pas plus tard.

## Dates

`\DateTimeImmutable` partout, avec `Types::DATE_IMMUTABLE`, `Types::DATETIME_IMMUTABLE`
ou `Types::DATETIMETZ_IMMUTABLE`.

Les types mutables existent toujours et fonctionnent toujours, ce qui est le problème :
`Types::DATETIME` remet à l'appelant un `\DateTime` que l'appelant peut muter, et l'unité
de travail voit le changement et l'écrit au flush suivant. Rien dans la pile ne le
signale.

`datetime_immutable` ne stocke aucun fuseau horaire. Stocke en UTC et convertis pour
l'affichage, ou utilise `datetimetz_immutable` en acceptant que son support varie selon la
plateforme — PostgreSQL conserve le décalage, MySQL non.

## ManyToOne: le côté propriétaire

```php
#[ORM\ManyToOne(inversedBy: 'reviews')]
#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
private ?Book $book = null;
```

Le côté qui porte la clé étrangère est le côté **propriétaire**, et c'est le seul côté que
Doctrine regarde pour décider quoi écrire. Faire `$review->setBook($book)` persiste le
lien ; ajouter à `$book->getReviews()` seul ne le fait pas. C'est la cause la plus
fréquente de « ma relation n'est pas sauvegardée », et c'est pourquoi le maker génère
`addReview()` sur le côté inverse avec la référence retour déjà définie à l'intérieur —
garde ce code.

**`ManyToOne` n'a pas d'argument `nullable`.** Son constructeur ne prend que
`targetEntity`, `cascade`, `fetch` et `inversedBy`. La nullabilité est une propriété de la
colonne de jointure :

```php
#[ORM\JoinColumn(nullable: false)]
```

Écrit sur le `ManyToOne`, c'est une erreur fatale `unknown named parameter` ; écrit nulle
part, la colonne devient nullable par défaut et le schéma autorise silencieusement des
lignes orphelines.

`onDelete:` sur la colonne de jointure est une cascade **au niveau base de données** : les
lignes disparaissent sans que Doctrine les voie, donc aucun callback de cycle de vie ne
s'exécute et l'identity map garde les objets supprimés pour le reste de la requête. C'est
rapide et c'est le bon outil pour des lignes enfants à haut volume ; c'est le mauvais
outil quand quelque chose doit se produire à la suppression.

## OneToMany: le côté inverse

```php
/** @var Collection<int, Review> */
#[ORM\OneToMany(targetEntity: Review::class, mappedBy: 'book')]
private Collection $reviews;

public function __construct()
{
    $this->reviews = new ArrayCollection();
}
```

`mappedBy` nomme la propriété du côté propriétaire. L'annotation
`@var Collection<int, Review>` n'est pas optionnelle au niveau max de PHPStan — sans elle,
chaque élément est `mixed`.

Ne mappe le côté inverse que si quelque chose le lit. Un `OneToMany` qui existe « pour la
symétrie » est une collection que Doctrine surveille, un proxy paresseux qu'il
instancie, et une hydratation que quelqu'un déclenchera par accident.

`targetEntity:` peut être omis quand le type de la propriété le dit — mais seulement pour
`ManyToOne`/`OneToOne`, où il y a une propriété typée à lire. Un `Collection` ne dit rien
de ce qu'il contient, donc `OneToMany` et `ManyToMany` en ont toujours besoin.

## cascade et orphanRemoval

| Paramètre | Ce que ça signifie |
|---|---|
| `cascade: ['persist']` | Persister le parent persiste les nouveaux enfants. Utile quand les enfants sont créés avec le parent et jamais seuls |
| `cascade: ['remove']` | Supprimer le parent supprime les enfants **un à un**, en PHP |
| `orphanRemoval: true` | Un enfant retiré de la collection est supprimé, pas seulement détaché |
| `#[ORM\JoinColumn(onDelete: 'CASCADE')]` | La base de données supprime les enfants. Une instruction, aucun PHP |

`cascade: ['remove']` et `onDelete: 'CASCADE'` résolvent le même problème à des niveaux
différents. Utilise la cascade base de données pour le volume ; utilise la cascade
Doctrine quand un listener, une suppression de fichier ou une entrée d'audit doit
s'exécuter par enfant. Définir les deux n'est pas une erreur, mais c'est la base de
données qui gagne et la cascade PHP devient du poids mort.

`orphanRemoval` est ce que tout le monde attend par défaut et n'obtient pas :

```php
$book->removeReview($review);
$em->flush();
```

Sans `orphanRemoval: true`, ceci met `review.book_id` à NULL — ou lève une violation de
clé étrangère si la colonne de jointure est `nullable: false`. Avec, la ligne de l'avis
est supprimée. Définis-le quand l'enfant ne peut pas exister sans le parent, ce qui est la
même condition qui rend `#[ORM\JoinColumn(nullable: false)]` correct.

N'utilise pas `cascade: ['all']`. Ça inclut `detach` et `refresh`, que presque personne ne
veut, et ça transforme une décision réfléchie en haussement d'épaules.

## Le piège du comptage de collection, en détail

`Doctrine\ORM\PersistentCollection::count()` — vérifié en ORM 3.6 — retourne le compte du
persister **seulement** quand l'association est mappée `fetch: 'EXTRA_LAZY'`. Sinon, ça
retombe sur `AbstractLazyCollection::count()`, qui appelle `initialize()` et hydrate
chaque ligne.

Donc ces quatre-là sont la même requête :

```php
count($book->getReviews())
$book->getReviews()->count()
$book->getReviews()->isEmpty()
{{ book.reviews|length }}
```

Dans un template de liste, ça s'exécute une fois par ligne. Le correctif est une méthode
de repository retournant les comptes pour toute la page en une seule requête groupée,
indexée par id de livre — voir `references/repositories-and-queries.md`.

`->isEmpty()` n'est pas plus économique — c'est implémenté au-dessus de `count()`. Les
alternatives économiques sont un `EXISTS` dans une méthode de repository, ou le compte
déjà récupéré.

**`fetch: 'EXTRA_LAZY'`** fait aussi que `contains()`, `containsKey()`, `get()`,
`first()` et `slice()` interrogent directement la base plutôt que de tout charger. C'est
le correctif pragmatique pour une base de code existante où ces appels sont répandus dans
les templates. La raison pour laquelle ce n'est pas le défaut ici : ça rend un appel O(n)
invisible dans une boucle Twig, donc personne ne le retire jamais.

## ManyToMany

```php
#[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'books')]
private Collection $tags;
```

Le côté avec `inversedBy` possède la table de jointure et est le seul côté qui écrit. Dès
que l'association a besoin d'un attribut propre — une position, une date, une note — elle
cesse d'être un `ManyToMany` et devient une entité avec deux `ManyToOne`. Le découvrir
après que la table de jointure a des données est une migration ; le décider en amont est
une conversation de cinq minutes.

## Référence des attributs

Signatures telles qu'elles existent en ORM 3.6. Tout ce qui n'est pas listé ici n'est pas
un argument valide.

| Attribut | Arguments |
|---|---|
| `#[ORM\Entity]` | `repositoryClass`, `readOnly` |
| `#[ORM\Column]` | `name`, `type`, `length`, `precision`, `scale`, `unique`, `nullable`, `insertable`, `updatable`, `enumType`, `options`, `columnDefinition`, `generated`, `index` |
| `#[ORM\GeneratedValue]` | `strategy` (`AUTO`, `SEQUENCE`, `IDENTITY`, `NONE`, `CUSTOM`) |
| `#[ORM\ManyToOne]` | `targetEntity`, `cascade`, `fetch`, `inversedBy` — **pas de `nullable`** |
| `#[ORM\OneToMany]` | `targetEntity`, `mappedBy`, `cascade`, `fetch`, `orphanRemoval`, `indexBy` |
| `#[ORM\JoinColumn]` | `name`, `referencedColumnName`, `deferrable`, `unique`, `nullable`, `onDelete`, `columnDefinition`, `fieldName`, `options` |
| `#[ORM\Index]` | `name`, `columns`, `fields`, `flags`, `options` — au niveau classe, répétable |
| `#[ORM\OrderBy]` | `value` (un tableau : `['publishedAt' => 'DESC']`) |

`fetch` accepte `'LAZY'`, `'EAGER'` ou `'EXTRA_LAZY'`. `fetch: 'EAGER'` sur un `ManyToOne`
charge l'entité liée à chaque requête touchant celle-ci, y compris les requêtes qui ne la
lisent jamais — préfère un `JOIN` dans la méthode de repository qui en a réellement
besoin.
