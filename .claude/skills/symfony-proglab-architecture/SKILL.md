---
name: symfony-proglab-architecture
description: >-
  Décider où placer le code dans un projet Symfony et le câbler correctement : le contrat
  Contrôleur / Service / Repository / DTO / Entité, les trois espaces de noms de DTO
  (Input, Read, Output), la conception des services et qui appelle flush(), les
  exceptions métier portant leur propre statut HTTP, les appels directs plutôt que les
  événements de domaine, la configuration répartie entre variables d'environnement,
  paramètres app. et constantes de classe, et les attributs d'injection de dépendances
  (#[Autowire], #[AutowireIterator], #[AutowireLocator], #[AsDecorator], #[AsTaggedItem],
  #[Target], #[When], #[Lazy]). Utilise ce skill dès que quelqu'un demande où placer un
  morceau de logique, veut un nouveau service ou DTO, dit qu'un contrôleur a trop grossi,
  veut extraire ou déplacer une classe qui a dépassé sa place, demande comment rendre
  quelque chose configurable ou ajouter un réglage ou une variable d'environnement,
  demande pourquoi un service n'est pas autowiré ou comment injecter l'une de plusieurs
  implémentations, veut une factory, une strategy, un decorator ou un mapping d'objet, ou
  demande « est-ce le bon endroit pour ça ? », « comment structurer cette fonctionnalité ? »,
  « où va la logique de notation ? », « extrais ça », « pourquoi mon exception renvoie un
  500 ? ». Utilise-le aussi pour la liste des alternatives que ce standard a délibérément
  rejetées, et pourquoi. Pour une fonctionnalité décrite en langage naturel, ou un simple
  « refactorise ça » sans cible, `symfony-proglab-standards` route en premier.
---

# Architecture

> **Niveau : socle** — le contrat de couches et les cinq règles vivent ici, et aucune
> n'est optionnelle. Le seul élément à la demande est la configuration deptrac qui
> l'impose mécaniquement (`symfony-proglab-quality`).

Où va le code, et pourquoi la frontière est là où elle est.

Une règle en engendre la plupart des autres : **l'entité ne porte aucune règle, donc le
service porte toutes les règles.** Tout ce qui suit en découle.

## Le contrat de couches

| Couche | Peut faire | Ne peut pas faire |
|---|---|---|
| **Contrôleur** | Lire la requête, appeler un service, choisir une représentation | Règles métier, `EntityManager`, DQL, `flush()` |
| **Service** | Toutes les règles métier, l'orchestration, `flush()` | Construire des requêtes, savoir que HTTP existe, renvoyer des entités vers l'extérieur |
| **Repository** | DQL, QueryBuilder, SQL, `persist()`, `remove()` | Règles métier, `flush()` |
| **DTO** | Transporter des données à travers une frontière, porter des contraintes de validation | Dépendre de Doctrine, contenir une logique au-delà de la normalisation |
| **Entité** | État mappé, getters, setters, constantes de valeur | Invariants, méthodes de domaine façon `publish()` |

Le contrat tient parce qu'il est respecté ; `symfony-proglab-quality` fournit une
configuration deptrac qui le vérifie mécaniquement, pour le jour où un second
développeur ou un agent non supervisé rendra la revue insuffisante. Si une classe ne
rentre dans aucune couche, c'est la classe qui est mal placée — n'élargis pas la
règle.

### Pourquoi les entités sont anémiques, et ce que ça coûte

Les entités sont générées au format maker : propriétés privées, getters, setters. C'est
un compromis délibéré, pas une loi de la nature, et ses termes doivent être énoncés
plutôt que découverts.

**Ce que ça achète.** Une règle sans exception — toute règle vit dans un service — ce
qui est facile à enseigner, facile à relire, et vérifiable par deptrac. Et une friction
nulle avec l'outillage : `make:entity`, le composant Form, les fixtures et EasyAdmin
attendent tous des setters et un constructeur sans argument, et continuent tous de
fonctionner sans modification.

**Ce que ça coûte.** Un setter n'impose rien, donc rien techniquement n'empêche
`$book->setRating(9)` en dehors de `BookRater`. La garantie repose sur le fait que
chaque chemin d'écriture passe par le service. C'est une vraie faiblesse ; elle est
bornée plutôt qu'éliminée :

- La frontière HTTP — le seul chemin que tu n'as pas écrit — est couverte par les
  contraintes du DTO Input (`symfony-proglab-http`).
- Les commandes, les fixtures et les autres services sont du code que tu écris et
  testes ; une règle dont ils ont besoin est un service qu'ils appellent.
- Une surface qui écrit sans passer par un service, EasyAdmin en premier lieu,
  n'applique aucune règle. C'est une surface de confiance. Quand une règle locale doit
  malgré tout y tenir, duplique-la sous forme de contrainte `#[Assert]` sur l'entité,
  en relisant les mêmes constantes — c'est le seul cas où une contrainte a sa place sur
  une entité (`symfony-proglab-doctrine`).

Des entités riches — un constructeur pour les champs obligatoires, `rate()` plutôt que
`setRating()` — combleraient cet écart pour les règles locales. Elles sont rejetées ici
parce qu'elles se paient dans chaque outil listé ci-dessus, et parce que la plupart des
règles dépassent l'entité le jour où elles ont besoin d'une requête. Le raisonnement est
dans `references/rejections.md`.

Donc toute règle vit dans un service, et l'entité est une ligne typée.

```php
// src/Entity/Book.php — des valeurs, pas des règles. Les constantes ont leur place ici : ce sont des données.
public const MIN_RATING = 1;
public const MAX_RATING = 5;

public function setRating(?int $rating): static { $this->rating = $rating; return $this; }
```

```php
// src/Service/BookRater.php — la règle, au seul endroit capable de l'imposer.
final readonly class BookRater
{
    public function __construct(
        private BookRepository $books,
        private EntityManagerInterface $em,
        private ObjectMapperInterface $mapper,
    ) {
    }

    public function rate(int $bookId, RateBookInput $input): BookView
    {
        if (null === $book = $this->books->find($bookId)) {
            throw new BookNotFound($bookId);
        }

        if ($input->rating < Book::MIN_RATING || $input->rating > Book::MAX_RATING) {
            throw new InvalidRating($input->rating);
        }

        $book->setRating($input->rating);
        $this->em->flush();

        return $this->mapper->map($book, BookView::class);
    }
}
```

N'écris `$book->rate(5)` nulle part. Si tu en trouves un, déplace la vérification
dans le service qui l'appelle. (`ObjectMapperInterface` vient de `symfony/object-mapper`
— **expérimental en 7.3, stable en 7.4, absent en 6.4** ; sans lui, un `static
fromEntity()` sur le DTO Output. La règle ne change pas, seul le moyen change —
`references/dtos.md`.)

## Conception des services

- **`final readonly`**, injection par constructeur uniquement. `final` parce que le
  point d'extension est un decorator ou une strategy, pas l'héritage ; `readonly` parce
  qu'un service qui se mute lui-même est un service qui se comporte différemment à la
  deuxième requête. **L'exception que crée cette règle :** une classe concrète `final`
  **ne peut pas être décorée** — `#[AsDecorator]` sur un service dont les consommateurs
  typent la classe concrète échoue à `lint:container`. Donc quand un decorator est
  nécessaire, extrais une interface, aliase l'implémentation vers elle, décore
  l'identifiant de l'interface — plutôt que d'abandonner `final`. Exemple complet dans
  `references/dependency-injection.md`.
- **Une seule responsabilité, nommée d'après elle.** `BookRater`, `ShelfReader`,
  `BookCreator` — pas `BookService`, qui est un dossier déguisé en classe.
- **Le service appelle `flush()`, une fois par cas d'usage.** Le repository fait
  `persist()` et `remove()`. Une frontière de transaction par opération métier, visible
  dans la méthode qui porte l'opération. Un `flush()` dans une méthode de repository
  rend la frontière invisible et transforme deux écritures en deux transactions.
- **Un service ne renvoie jamais une entité à un contrôleur.** Il renvoie un DTO
  Output. Une entité qui s'échappe de la couche service est un N+1 en lazy-loading qui
  attend son template.
- **Un service prend des identifiants et charge lui-même l'entité.** `rate(int
  $bookId, …)`, puis `$this->books->find($bookId) ?? throw new BookNotFound($bookId)`.
  Le service porte le « non trouvé » parce qu'il doit fonctionner de la même façon
  depuis un contrôleur, une commande et un message handler, et que tous trois portent
  un id. Le contrôleur résout l'entité (`Book $book`, l'EntityValueResolver) dans
  exactement un cas : un attribut a besoin de l'objet *avant* que l'action ne s'exécute
  — `#[IsGranted(…, subject: 'book')]` sur un voter. Il passe malgré tout
  `$book->getId()` au service, et le `find()` du service ne coûte aucune requête :
  l'identity map contient déjà la ligne. Deux chemins vers un 404, une règle pour
  décider lequel.
- **Les repositories sont l'exception à `final`** — ils sont doublés dans les tests
  unitaires de service. Tout le reste est `final`.

## Exceptions métier

Les échecs que l'appelant ne peut pas prévenir reçoivent une exception nommée dans
`src/Exception/`, portant sa propre traduction HTTP. Cela garde le contrôleur libre de
tout `try`/`catch` et rapproche le mapping de son sens.

```php
#[WithHttpStatus(Response::HTTP_NOT_FOUND)]
#[WithLogLevel(LogLevel::INFO)]          // un livre manquant n'est pas un incident
final class BookNotFound extends \DomainException
{
    public function __construct(public readonly int $bookId)
    {
        parent::__construct(\sprintf('No book #%d on this shelf.', $bookId));
    }
}
```

Les deux viennent de `Symfony\Component\HttpKernel\Attribute\*`, **depuis la 6.3**, et
les deux sont lus depuis les classes parentes, si bien qu'un `App\Exception\NotFound` de
base se propage à ses enfants. Avant la 6.3, mappe l'exception sous
`framework.exceptions` à la place.

Deux choses qui surprennent, toutes deux vérifiées dans `ErrorListener` :

- **`#[WithHttpStatus]` est ignoré quand l'exception implémente déjà
  `HttpExceptionInterface`.** N'étends pas `NotFoundHttpException` pour ensuite la
  décorer.
- **`framework.exceptions` dans la configuration l'emporte sur l'attribut — sur
  `#[WithLogLevel]` autant que sur `#[WithHttpStatus]`.** Le `log_level` et le
  `log_channel` d'une entrée battent tous deux ce que dit l'attribut, vérifié en 8.1.
  Utilise cette entrée pour mapper les exceptions *tierces* que tu ne peux pas
  annoter ; utilise l'attribut pour les tiennes, et n'attends pas d'un attribut qu'il
  reprenne la main sur une classe que la configuration nomme déjà.

Laisse l'attribut de côté délibérément quand l'exception signifie « un bug a atteint
ce point » — `InvalidRating` ci-dessus devrait être impossible, parce que le
`#[Assert\Range]` du DTO Input l'a déjà rejeté à la frontière HTTP. Un 500 est la bonne
réponse, et un 422 silencieux masquerait le défaut. Dis-le dans un commentaire ;
l'absence d'attribut se lit sinon comme un oubli.

## Appels directs, pas d'événements de domaine

Quand un service a fini son travail et qu'autre chose doit se produire, il appelle le
collaborateur :

```php
$this->em->flush();
$this->notifier->notifyAuthor($review);   // oui
```

Pas `$this->dispatcher->dispatch(new ReviewPublished($review))`.

La raison est la lisibilité en maintenance. Avec un appel direct, toute la conséquence
de la publication d'une critique se lit dans une seule méthode, et le test unitaire
vérifie que le notifier a été appelé. Avec un événement de domaine, répondre à « que se
passe-t-il quand une critique est publiée ? » devient une recherche à l'échelle du
projet, et la réponse dépend de priorités de listener qui n'apparaissent nulle part près
du code. Le dispatcher achète un découplage dont une application unique n'a pas besoin,
et le facture à chaque lecture future.

**`#[AsEventListener]` sert uniquement aux événements du framework** —
`kernel.exception`, `kernel.response`, Doctrine, Messenger, Security. Une classe par
événement, nommée d'après ce qu'elle fait, avec `__invoke()` :

```php
#[AsEventListener]                        // événement déduit du type du paramètre
final readonly class AddNoIndexHeaderOnStaging
{
    public function __invoke(ResponseEvent $event): void { /* … */ }
}
```

La déduction à partir du type déclaré ne demande aucun argument `event:` ; n'en passe
un que pour les événements identifiés par un nom de chaîne. `EventSubscriberInterface`
est rejeté : plus de cérémonie, et une méthode statique à garder synchronisée avec la
classe.

**Les listeners de kernel sont un dernier recours.** Cherche d'abord le mécanisme
dédié — `#[WithHttpStatus]`/`#[WithLogLevel]` pour les erreurs, un ValueResolver pour
injecter un argument, `#[Cache]` pour les en-têtes, un Voter pour l'autorisation. Un
listener de kernel s'exécute sur tout, loin du code qu'il affecte, et se débogue mal.
N'en écris un que quand rien d'autre n'existe.

## Configuration : trois emplacements, une question chacun

| Question | Réponse | Où |
|---|---|---|
| Ça diffère selon la machine ou l'environnement ? | DSN, URL de base, clés d'API | variable d'environnement |
| C'est un comportement applicatif, identique partout ? | jours de rétention, feature flag | paramètre préfixé `app.` |
| Ça ne change presque jamais ? | taille de page, bornes de notation | constante de classe |

```php
public function __construct(
    #[Autowire(param: 'app.shelf_page_size')] private int $pageSize,
    #[Autowire(env: 'bool:FEATURE_IMPORT')] private bool $importEnabled,
) {
}
```

`#[Autowire]` accepte exactement un seul de `value`, `service`, `expression`, `env` ou
`param` — en passer deux lève une erreur à la compilation.

**Secrets : `.env.local` en développement, le magasin de secrets de la plateforme en
production, et le coffre de secrets Symfony dès que ni l'un ni l'autre ne couvre le cas.**
La suite déploie une image de conteneur (`symfony-proglab-deployment`), et chaque
plateforme de conteneurs dispose déjà d'un magasin de secrets qui injecte des variables
d'environnement. Là, le coffre serait un second magasin dont la propre clé de
déchiffrement devrait quand même venir du premier ; un seul magasin suffit. Le coffre
n'est **pas** rejeté sur le fond — sa clé de déchiffrement est une seule variable
d'environnement, pas plus difficile à déployer qu'un DSN, et c'est le seul mécanisme
qui permette de versionner et de relire un secret. C'est la réponse dès qu'il n'y a pas
de magasin de plateforme (un serveur nu, un déploiement par rsync), ou dès que des
secrets doivent transiter par le dépôt. Ce qui est rejeté, c'est `.env.local` sur un
hôte de production, et deux mécanismes sur un même projet.

## Injection de dépendances et patterns

L'injection par constructeur couvre presque tout. Quand ce n'est pas le cas, les
attributs sont dans `Symfony\Component\DependencyInjection\Attribute\` — chacun vérifié
face à `vendor/`, avec la version où il est apparu, dans
`references/dependency-injection.md`. Ce fichier couvre aussi le Strategy
(`#[AutowireLocator]` + `#[AsTaggedItem]`), le Decorator (`#[AsDecorator]` +
`#[AutowireDecorated]`), la Factory, et les deux attributs que ce standard n'utilise
délibérément pas : `#[Required]` et `#[SubscribedService]`.

**Les voters sont de l'autorisation, pas de l'architecture.** Ils existent, ils sont le
bon outil pour les permissions au niveau objet, et ils appartiennent à
`symfony-proglab-security`. Ne mets pas une vérification de permission dans un
service « parce que les règles vivent dans les services ».

## Repérer un morceau de code mal placé

| Symptôme | Ce que ça signifie réellement |
|---|---|
| `$em->flush()` dans un contrôleur | Le cas d'usage n'a pas encore de service |
| Un `QueryBuilder` dans un service | La requête a besoin d'une méthode de repository nommée |
| Un service qui type `Request` | Le contrôleur aurait dû passer un DTO Input |
| Un template qui appelle `book.ratings\|length` | Une entité s'est échappée de la couche service |
| Un `if` sur un rôle à l'intérieur d'un service | C'est un Voter (`symfony-proglab-security`) |
| Un constructeur de service avec huit arguments | Deux cas d'usage portant un seul nom de classe |
| `new SomeService(...)` à l'intérieur d'un service | Un collaborateur qui devrait être injecté |

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/dtos.md` | Concevoir des DTO Input / Read / Output, ou normaliser une projection |
| `references/dependency-injection.md` | Injecter tout ce que l'autowiring du constructeur ne peut pas résoudre ; Strategy, Decorator, Factory |
| `references/rejections.md` | Quelqu'un propose une alternative — vérifier si elle a déjà été rejetée, et pourquoi |
