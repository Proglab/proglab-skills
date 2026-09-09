---
name: symfony-proglab-accessibility
description: >-
  S'assurer qu'une interface Symfony respecte les règles de base de l'accessibilité et
  de la conception universelle (WCAG 2.1) : ne jamais coder une information uniquement
  par la couleur, un contraste texte/fond suffisant, des labels de formulaire réels —
  pas seulement un placeholder, des indicateurs de focus visibles (le piège classique de
  `focus:outline-none` en Tailwind sans remplacement), des textes alternatifs pertinents
  sur les images et les icônes, une hiérarchie de titres HTML correcte h1-h6, et ne
  jamais cacher une action derrière le survol seul. Utilise ce skill dès qu'on construit
  un formulaire, un menu, un dialogue, une image, une icône, une action au clavier, dès
  qu'on personnalise le thème Tailwind ou les composants du kit Shadcn
  (`symfony-proglab-ui`), ou que quelqu'un demande de vérifier l'accessibilité, l'UX,
  l'UI, la conformité WCAG, parle de daltonisme, de lecteur d'écran, de navigation au
  clavier, ou dit qu'un utilisateur ne peut pas voir ou utiliser quelque chose.
---

# Accessibilité et conception universelle

> **Niveau : socle** — ces sept règles ne coûtent rien de plus par fonctionnalité une
> fois connues ; elles coûtent cher à rattraper après coup, sur chaque écran déjà
> construit. La vérification outillée (mesure de contraste, tests automatisés) est à la
> demande.

Sept règles, issues des [Web Content Accessibility Guidelines (WCAG) 2.1](https://www.w3.org/WAI/WCAG21/quickref/)
du W3C — la référence sur laquelle s'appuie toute conception universelle, pas une
invention de cette suite. Adaptées ici à ce que ce standard construit réellement : des
formulaires Symfony, des templates Twig, des composants Shadcn
(`symfony-proglab-ui`), du Tailwind.

## 1. La couleur seule ne porte jamais une information

Un daltonien ou une personne malvoyante ne distingue pas toujours un bordure rouge
d'une bordure grise. La recette Symfony fait déjà la bonne chose par défaut : un champ
en échec de validation reçoit la classe `is-invalid` **et** un message d'erreur textuel
juste à côté. Le risque n'est pas dans le défaut, il est dans la personnalisation :

```twig
{# Mauvais : seule la bordure rouge signale l'erreur #}
<input class="{{ form.vars.errors|length ? 'border-red-500' : '' }}" ... >

{# Bon : la couleur illustre, le texte et l'icône portent l'information #}
{% if form.vars.errors|length %}
    <input class="border-red-500" aria-invalid="true" aria-describedby="{{ form.vars.id }}_error" ...>
    <p id="{{ form.vars.id }}_error" class="text-red-600 flex items-center gap-1">
        <twig:ux:icon name="tabler:alert-circle" aria-hidden="true" />
        {{ form_errors(form) }}
    </p>
{% endif %}
```

`aria-invalid` et `aria-describedby` relient le champ à son message pour un lecteur
d'écran, pas seulement pour l'œil. Vérifie une page en niveaux de gris (outil `Contrast`
sur macOS, ou le filtre daltonisme des DevTools Chrome) : si une information disparaît,
elle reposait sur la couleur seule.

## 2. Le contraste texte/fond avant le style

Ratio minimum **4,5:1**, ou **3:1** à partir de 24px (19px en gras). Le piège le plus
courant sur cette suite est du Tailwind, pas du CSS exotique : `text-gray-400` ou
`text-gray-300` sur fond blanc pour un texte « discret » passe rarement ce seuil.
Vérifie avec [WebAIM Contrast Checker](https://webaim.org/resources/contrastchecker/)
ou l'onglet Accessibilité des DevTools Chrome, qui affiche le ratio directement sur
l'élément inspecté.

**Un piège propre au kit Shadcn** (`symfony-proglab-ui`) : ses variables de thème
`oklch` par défaut sont conçues pour respecter ce ratio, mais rien ne le garantit
une fois qu'un projet personnalise `--primary` ou `--muted-foreground` pour coller à
une charte graphique. Revérifie le contraste à chaque changement de ces variables dans
`assets/styles/app.css`, pas seulement au moment de l'installation du kit.

## 3. Un vrai label, jamais un placeholder seul

```twig
{# Mauvais : le placeholder disparaît dès la première frappe, et beaucoup de #}
{# lecteurs d'écran ne le lisent pas comme un label #}
{{ form_widget(form.email, {attr: {placeholder: 'Adresse email'}}) }}

{# Bon : un label visible, ou masqué visuellement sans être retiré du DOM #}
{{ form_label(form.email) }}
{{ form_widget(form.email) }}

{# Masquer visuellement sans le retirer pour les lecteurs d'écran #}
{{ form_label(form.email, null, {label_attr: {class: 'sr-only'}}) }}
```

`sr-only` (fournie par Tailwind) rend le label invisible à l'écran tout en le laissant
lisible par un lecteur d'écran — c'est la bonne réponse à « je ne veux pas afficher de
label », pas `label: false` combiné à un placeholder. Le FormType ne porte que la
description des champs (`symfony-proglab-http`) ; le label en fait partie autant que
le type de champ ou sa contrainte.

## 4. Un indicateur de focus visible, toujours

Le piège classique sur cette suite : Tailwind ne retire pas le contour de focus par
défaut, mais un style personnalisé ajouté sans y penser le fait très facilement.

```css
/* Casse la navigation au clavier pour tout le monde */
.btn:focus { outline: none; }

/* Retire l'anneau par défaut du navigateur pour le remplacer par un style cohérent
   avec le thème — jamais pour le supprimer purement et simplement */
.btn:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px var(--ring);
}
```

`:focus-visible` plutôt que `:focus` évite l'anneau disgracieux au clic à la souris
tout en le gardant à la navigation au clavier — c'est le compromis que les navigateurs
eux-mêmes utilisent nativement. Vérifie en appuyant sur Tab depuis le haut de chaque
page : si tu ne peux pas dire quel élément a le focus, personne d'autre ne le peut non
plus. Les composants du kit Shadcn (`symfony-proglab-ui`) le gèrent déjà par défaut ;
le risque est sur tout élément interactif écrit à la main à côté d'eux.

## 5. Un texte alternatif qui décrit, pas qui existe

```twig
{# Décorative : alt vide explicite, jamais l'attribut omis #}
<img src="{{ asset('images/divider.svg') }}" alt="">

{# Porteuse de sens : décrit ce que l'image apporte au contenu, pas son nom de fichier #}
<img src="{{ asset(book.coverUrl) }}" alt="Couverture de « {{ book.title }} »">

{# Icône décorative à côté d'un texte déjà présent : cachée du lecteur d'écran #}
<twig:ux:icon name="tabler:book" aria-hidden="true" /> {{ book.title }}

{# Icône seule cliquable : le sens doit être porté par aria-label, pas par l'icône #}
<button aria-label="Supprimer {{ book.title }}">
    <twig:ux:icon name="tabler:trash" aria-hidden="true" />
</button>
```

La règle qui tranche entre les trois premiers cas : est-ce que l'image ajoute une
information que le texte autour ne donne pas déjà ? Si non, `alt=""` — jamais l'attribut
absent, qui laisse un lecteur d'écran annoncer le nom de fichier. Un bouton icône-seule
(le `size="icon"` du kit Shadcn, `symfony-proglab-ui`) a systématiquement besoin d'un
`aria-label`, parce que rien d'autre sur la page ne porte son intitulé.

## 6. Une hiérarchie de titres qui a un sens, pas un style

`symfony-proglab-frontend` établit un héritage à trois niveaux — `base.html.twig` →
`layout/*.html.twig` → la page. La même discipline s'applique aux titres : **un seul
`<h1>` par page**, porté par le template de page lui-même, jamais par le layout ni par
un composant réutilisé plusieurs fois sur l'écran.

```twig
{# templates/book/show.html.twig #}
{% block content %}
    <h1>{{ book.title }}</h1>
    {# Une carte de critique réutilisée ailleurs commence en h2, jamais en h1 #}
    {% for review in book.reviews %}
        <twig:ReviewCard :review="review" />  {# titre interne en h3, sous le h2 de sa section #}
    {% endfor %}
{% endblock %}
```

Ne saute pas de niveau pour un effet visuel (`h4` parce qu'il est plus petit qu'`h2`
dans la feuille de style) — la taille se règle en CSS, le niveau du titre décrit la
structure du document pour qui la parcourt au clavier ou à la voix, pas pour qui la
regarde.

## 7. Rien d'important ne doit dépendre du survol seul

Un menu, une info-bulle ou une action révélée uniquement par `:hover` ou
`mouseenter`/`mouseleave` n'existe pas pour qui navigue au clavier, au doigt sur un
écran tactile, ou avec un lecteur d'écran.

```js
// controllers/dropdown_controller.js — mauvais : hover seul
static targets = ['menu']
show() { this.menuTarget.classList.remove('hidden') }
// connect() { this.element.addEventListener('mouseenter', () => this.show()) }

// Bon : le clic et le clavier pilotent l'état, le survol reste un bonus visuel
connect() {
    this.element.addEventListener('click', () => this.toggle())
    this.element.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') this.hide()
    })
}
```

Les composants du kit Shadcn qui exposent des attributs à épandre
(`{{ ...dialog_trigger_attrs }}`, voir `symfony-proglab-ui`) câblent déjà clic et
clavier ensemble — le risque réel est le menu ou l'info-bulle écrit à la main à côté
d'eux, avec seulement des classes `hover:` en CSS. Une action secondaire qui doit
rester discrète va dans un menu qui s'ouvre au clic, pas dans un survol.

## Vérifier, sans nouvel outillage

Rien ici ne demande d'ajouter une dépendance :

- **Onglet Accessibilité des DevTools Chrome/Edge** — contraste, arborescence
  d'accessibilité, ordre de tabulation, en un clic, depuis le serveur de dev
  (`symfony-proglab-local-dev`).
- **[axe DevTools](https://www.deque.com/axe/devtools/)**, extension de navigateur —
  audite une page chargée et pointe la règle WCAG précise violée.
- **La touche Tab**, du haut de la page jusqu'en bas, sans souris. Le test le moins
  outillé et le plus révélateur des sept règles ci-dessus.

Un test automatisé (axe-core intégré à un test fonctionnel, par exemple) est un ajout
légitime mais plus lourd — une dépendance de plus, une exécution plus lente — et
appartient au même palier « à la demande » que le reste de l'outillage de
`symfony-proglab-quality` : à introduire quand une régression d'accessibilité a
réellement été constatée en production, pas par anticipation.

## Symptôme → règle enfreinte

| Symptôme | Règle |
|---|---|
| Un utilisateur daltonien ne voit pas qu'un champ est en erreur | 1 — la couleur seule porte l'information |
| Un texte « discret » en `text-gray-400` disparaît sur fond blanc | 2 — contraste sous 4,5:1 |
| Un lecteur d'écran annonce un champ sans dire ce qu'il attend | 3 — placeholder au lieu d'un label |
| Impossible de savoir quel bouton a le focus au clavier | 4 — `outline: none` sans `focus-visible` de remplacement |
| Le lecteur d'écran annonce « image, IMG_04231.jpg » | 5 — attribut `alt` absent ou non descriptif |
| Un bouton icône-seule est annoncé « bouton », sans plus | 5 — `aria-label` manquant |
| La page a trois `<h1>`, ou saute de `h1` à `h4` | 6 — hiérarchie de titres incohérente |
| Un menu ne s'ouvre qu'à la souris | 7 — dépendance au survol seul |

## Fichiers de référence

Aucun pour l'instant : les sept règles ci-dessus, avec leurs adaptations Symfony,
tiennent en un seul fichier délibérément. `symfony-proglab-http` possède le mécanisme
des formulaires (règle 3), `symfony-proglab-frontend` celui des templates et de
Tailwind (règles 2, 4, 6), et `symfony-proglab-ui` les composants Shadcn déjà conformes
par défaut (règles 4, 5, 7) — ce skill dit *pourquoi* chacune de ces règles compte et
où elle casse silencieusement.
