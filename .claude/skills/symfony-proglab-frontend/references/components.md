# Twig Components et Live Components

Vérifié avec `ux-twig-component` / `ux-live-component` 3.4. Quand une version diffère,
c'est `vendor/` qui l'emporte sur ce document.

## Sommaire

- [Twig Components](#twig-components)
- [Composants anonymes](#composants-anonymes)
- [Les props sont des DTOs](#les-props-sont-des-dtos)
- [Live Components](#live-components)
- [`LiveProp`](#liveprop)
- [Actions](#actions)
- [Le coût du remontage](#le-coût-du-remontage)
- [Les actions live sont de vrais contrôleurs, donc sécurise-les](#les-actions-live-sont-de-vrais-contrôleurs-donc-sécurise-les)
- [Communication entre composants](#communication-entre-composants)
- [Formulaires dans un Live Component](#formulaires-dans-un-live-component)
- [Tests](#tests)
- [Symptômes](#symptômes)

## Twig Components

Un composant, c'est une classe plus un template. Pas d'état, pas d'interactivité — une
fonction qui va des props au HTML.

```php
<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Dto\Output\BookCardView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

#[AsTwigComponent]
final class BookCard
{
    public BookCardView $book;
    public bool $compact = false;

    #[ExposeInTemplate('cover_url')]
    public function coverUrl(): string
    {
        return $this->book->coverPath ?? '/images/book-placeholder.svg';
    }
}
```

```twig
{# templates/components/BookCard.html.twig #}
<article {{ attributes.defaults({ class: 'book-card' }) }}>
    <img src="{{ cover_url }}" alt="">
    <h3>{{ book.title }}</h3>
    {% if not compact %}<p>{{ book.summary }}</p>{% endif %}
</article>
```

```twig
<twig:BookCard :book="book" compact class="book-card--wide" />
```

Les points faciles à rater :

- **Les propriétés publiques sont les props.** `:book="book"` passe une valeur,
  `compact` seul passe `true`, `class="…"` n'est pas une prop — elle atterrit dans
  `attributes`.
- **`attributes.defaults({…})`** fusionne les attributs HTML fournis par l'appelant avec
  tes valeurs par défaut, plutôt que de laisser l'un des deux l'emporter silencieusement.
  Rendre `{{ attributes }}` tel quel fonctionne aussi ; c'est `defaults()` qui rend un
  composant stylable depuis l'extérieur.
- **`#[ExposeInTemplate]`** publie le résultat d'une méthode comme une variable de
  template ordinaire, si bien que le template lit `{{ cover_url }}` plutôt que
  `{{ this.coverUrl }}`. Les templates restent lisibles et le composant garde sa
  logique en PHP.
- La résolution du template pointe par défaut vers
  `templates/components/<Name>.html.twig` ; à surcharger avec
  `#[AsTwigComponent(template: '…')]`. Le namespace et le répertoire viennent de
  `config/packages/twig_component.yaml`.

## Composants anonymes

Un composant sans comportement n'a pas besoin de classe. Un fichier à
`templates/components/Badge.html.twig` est utilisable directement comme
`<twig:Badge label="New" />`, avec `{% props label, tone = 'neutral' %}` en tête
déclarant ses props.

Utilise-le pour tout ce qui n'a aucune logique PHP. Ajouter une classe vide « pour la
cohérence » ajoute un service, une passe de découverte et un fichier, pour aucun
bénéfice.

## Les props sont des DTOs

```twig
<twig:BookCard :book="book" />
```

Ici, `book` est une `BookCardView`, jamais une entité `Book`. Un template Twig qui
détient une entité déclenche du lazy loading pendant le rendu : `book.author.name` dans
une boucle est un N+1 qui n'apparaît dans aucun fichier PHP, et
`book.ratings|length` hydrate chaque ligne de note pour les compter. Les requêtes se
déclenchent à l'intérieur du rendu du template, loin du repository, et aucun test au
niveau service ne peut les voir.

Construis le DTO dans le service (une projection `SELECT NEW`, ou un mapping depuis
l'entité), passe-le en prop, et les besoins en données du composant deviennent une
signature de type plutôt qu'un contrat implicite avec l'ORM.

## Live Components

Un Live Component est un composant Twig dont l'état est sérialisé dans le DOM et qui se
réaffiche lui-même via HTTP quand cet état change.

**Cet état sérialisé est toute la raison d'y recourir.** L'arbitrage dans `SKILL.md`
tient en une seule question — *y a-t-il un état à retenir entre deux interactions ?*
Un filtre qui retient ce sur quoi il filtre, une recherche qui s'affine au fil de la
frappe, un formulaire validé champ par champ, un assistant à l'étape trois : oui, et le
composant est le bon choix. Un bouton qui poste une fois et met à jour trois zones :
non, et la réponse la moins coûteuse est un Turbo Stream renvoyé comme réponse à cette
soumission — pas de classe, pas de cycle de vie, pas d'état dans le DOM. Voir
`references/turbo-and-stimulus.md`.

```php
<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Dto\Output\BookListView;
use App\Service\BookSearch;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class BookList
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: true)]
    public string $query = '';

    #[LiveProp(writable: true)]
    public int $page = 1;

    public function __construct(private readonly BookSearch $search)
    {
    }

    #[LiveAction]
    public function goToPage(#[LiveArg] int $page): void
    {
        $this->page = $page;
    }

    public function getResults(): BookListView
    {
        return $this->search->paginate($this->query, $this->page);
    }
}
```

```twig
<div {{ attributes }}>
    <input type="search" data-model="debounce(400)|query" value="{{ query }}">

    {% for book in this.results.items %}
        <twig:BookCard :book="book" />
    {% endfor %}

    <button data-action="live#action"
            data-live-action-param="goToPage"
            data-live-page-param="{{ this.results.page + 1 }}">Next</button>
</div>
```

`{{ attributes }}` sur l'élément racine est obligatoire — il porte l'état sérialisé et
le contrôleur Stimulus. Sans lui, le composant se rend une fois et ne réagit plus jamais.

## `LiveProp`

Seules les propriétés marquées `#[LiveProp]` font partie de l'état. Tout le reste est
recalculé de zéro à chaque requête, ce qui est généralement ce qu'on veut pour des
données dérivées et jamais ce qu'on veut pour quelque chose que l'utilisateur a modifié.

| Option | Effet |
|---|---|
| `writable: true` | Le navigateur peut la modifier (`data-model`). Sans cela, la prop est en lecture seule depuis le frontend |
| `writable: ['title', 'isbn']` | Seuls ces chemins d'une prop objet sont modifiables — la façon sûre de lier un DTO de type formulaire |
| `url: true` | Reflète la valeur dans un paramètre de requête, si bien qu'une liste filtrée devient partageable par URL et survit à un rechargement |
| `onUpdated: 'method'` | Appelée après que la prop change ; l'ancienne valeur est passée en argument. C'est là qu'on remet `page` à 1 quand `query` change |
| `format: 'Y-m-d'` | Format de sérialisation pour les props de type date |
| `updateFromParent: true` | Un réaffichage du parent pousse la nouvelle valeur dans l'enfant |

**`writable: true` est une frontière de confiance.** La valeur vient du navigateur,
signée contre la falsification mais entièrement choisie par l'utilisateur. Un `$sortBy`
modifiable interpolé dans du DQL est une injection ; un `$userId` modifiable est une
faille d'autorisation. Valide-le, ou rends-le non modifiable et expose une action à
la place.

## Actions

`#[LiveAction]` marque une méthode appelable depuis le template ; `#[LiveArg]` lie un
attribut `data-live-<name>-param` à un paramètre. Des services peuvent être autowirés
comme arguments supplémentaires de la méthode d'action.

`data-model` sur un champ écrit une prop et déclenche un réaffichage. **Débounce
toujours un champ texte** — `debounce(400)|query` — sinon chaque frappe est une requête
HTTP et un rendu complet, et un champ de recherche adossé à une requête lente devient un
test de charge auto-infligé.

Deux attributs à connaître quand le réaffichage résiste :

- `data-live-ignore` — le sous-arbre n'est jamais touché par le morphing. Nécessaire
  autour de tout ce que possède une bibliothèque tierce (une carte, un éditeur de texte
  enrichi), qui serait sinon réinitialisée à chaque rendu.
- `data-live-preserve` — conserve un élément existant à travers les réaffichages au lieu
  de le remplacer, en préservant un état du DOM comme le focus ou la position de
  défilement.

## Le coût du remontage

**Chaque interaction est une requête HTTP complète, et l'objet composant est reconstruit
depuis zéro.** Le constructeur s'exécute, `mount()` s'exécute, les props se réhydratent
depuis le DOM, le template se rend intégralement. Rien ne survit dans une propriété
simple entre deux clics.

La conséquence pratique est que `getResults()` ci-dessus s'exécute à **chaque frappe**.
C'est acceptable quand il s'agit d'une seule requête indexée et paginée ; ça ne l'est
pas quand le composant se ramifie en une douzaine de requêtes ou un appel à une API
externe. Les mitigations, par ordre de préférence : débouncer le champ, paginer plutôt
que tout charger, garder la requête dans une méthode de repository couverte par un test
de comptage de requêtes (voir `symfony-proglab-performance`), et alors seulement
envisager un cache.

La même logique explique la règle du DTO depuis l'autre bout : un `LiveProp` qui
détient une entité est sérialisé dans le DOM et réhydraté à travers l'ORM à chaque
interaction.

Elle explique aussi pourquoi une interaction sans état ne devrait pas être un Live
Component du tout : tu paies le remontage — constructeur, `mount()`, réhydratation,
rendu complet — pour un état que le composant n'a jamais eu. Un seul POST de formulaire
répondu par un Turbo Stream n'en paie aucun.

## Les actions live sont de vrais contrôleurs, donc sécurise-les

Une `#[LiveAction]` est distribuée à travers le kernel HTTP comme un véritable
contrôleur (`_controller` est réécrit en `component::action`). Deux conséquences :

- `#[IsGranted('BOOK_EDIT', 'book')]` sur la méthode d'action fonctionne exactement
  comme sur une action de contrôleur, et `#[IsGranted]` sur la classe du composant les
  couvre toutes.
- À l'inverse, **une action sans aucune vérification est un endpoint publiquement
  accessible.** Elle n'est pas protégée par le fait que le bouton qui la déclenche n'est
  affiché qu'aux administrateurs — la route est `ux_live_component` et n'importe qui
  peut y poster. Traite chaque `#[LiveAction]` comme une route lors de la revue de
  sécurité.

## Communication entre composants

`ComponentToolsTrait` fournit `emit()`, `emitUp()`, `emitSelf()` et
`dispatchBrowserEvent()` ; le composant récepteur déclare
`#[LiveListener('book:saved')]` sur une méthode. À utiliser pour des composants
réellement distincts sur une même page (un panneau de filtres et une liste de
résultats).

Avant d'y recourir, demande-toi si les deux sont vraiment séparés : deux composants
qui changent toujours ensemble sont un seul composant découpé trop tôt, et une paire
d'événements est plus difficile à suivre qu'une prop partagée.

## Formulaires dans un Live Component

Deux niveaux, et le choix compte :

- **`ValidatableComponentTrait`** — des contraintes sur les propres props du composant,
  `validate()` / `validateField()` / `getError()`. Adapté à une petite interaction : un
  champ, un renommage en ligne, une note.
- **`ComponentWithFormTrait`** — enveloppe un vrai `FormType` (implémente
  `instantiateForm()`), fournissant `getFormView()` et la validation live champ par
  champ. Adapté quand un formulaire existe déjà comme classe PHP et que tu veux le
  valider au fil de la frappe. `LiveCollectionTrait` ajoute `addCollectionItem` /
  `removeCollectionItem` pour `CollectionType`.

Le FormType lui-même, son `data_class` et son DTO d'entrée relèvent de
`symfony-proglab-http`. Un Live Component enveloppe un formulaire ; il ne le remplace
pas, et la validation côté serveur reste la seule qui compte.

## Tests

```php
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class BookListTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    #[Test]
    public function it_filters_on_query(): void
    {
        $component = $this->createLiveComponent(BookList::class);

        $component->set('query', 'dune');

        self::assertStringContainsString('Dune', (string) $component->render());
    }
}
```

`createLiveComponent()`, `set()`, `call()` et `render()` exercent le vrai chemin
d'hydratation et de rendu dans un `KernelTestCase`, sans navigateur et sans couche HTTP
— si bien que le comportement interactif est bon marché à tester, et n'a aucune excuse
pour rester la partie non testée de l'interface.

## Symptômes

| Symptôme | Cause |
|---|---|
| Un composant dont les props sont identiques à chaque rendu | Il n'a aucun état entre les interactions. Il voulait être un contrôleur répondant par un Turbo Stream |
| Le composant se rend mais rien ne réagit | `{{ attributes }}` manquant sur l'élément racine |
| Une valeur se réinitialise à chaque clic | La propriété n'est pas une `#[LiveProp]` |
| Taper dans un champ ne fait rien | La prop n'est pas `writable: true` |
| Une requête par frappe | Pas de `debounce(…)` sur le `data-model` |
| `The action "…" is not allowed` | `#[LiveAction]` manquante sur la méthode |
| Un widget tiers se réinitialise à chaque rendu | Enveloppe-le dans `data-live-ignore` |
| Des centaines de requêtes sur une recherche live | Une entité dans un `LiveProp`, ou une requête non paginée dans le chemin de rendu |
| Une action s'exécute pour un utilisateur qui ne devrait pas y accéder | Pas de `#[IsGranted]` — le bouton caché ne protège rien |
