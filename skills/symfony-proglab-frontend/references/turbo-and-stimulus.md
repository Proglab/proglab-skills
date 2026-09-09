# Turbo et Stimulus

## Sommaire

- [Turbo Drive](#turbo-drive)
- [Ce que Drive casse](#ce-que-drive-casse)
- [Turbo Frames](#turbo-frames)
- [Turbo Streams](#turbo-streams)
- [Répondre à une soumission de formulaire par un stream](#répondre-à-une-soumission-de-formulaire-par-un-stream)
- [`target` et `targets` ne sont pas le même attribut](#target-et-targets-ne-sont-pas-le-même-attribut)
- [Streams via Mercure](#streams-via-mercure)
- [`#[Broadcast]`](#broadcast)
- [Stimulus : la forme d'un contrôleur](#stimulus--la-forme-dun-contrôleur)
- [Aucune règle métier en JavaScript](#aucune-règle-métier-en-javascript)
- [Débogage](#débogage)

## Turbo Drive

Installer `symfony/ux-turbo` l'active. Chaque clic sur un lien et chaque soumission de
formulaire sont interceptés, récupérés avec `fetch()`, et le `<body>` de la réponse
remplace l'actuel. La page ne se recharge jamais entièrement, si bien que le contexte
JavaScript, le CSS et toute connexion de longue durée survivent à la navigation.

Désactiver là où c'est justifié :

```twig
<a href="{{ path('legacy_report') }}" data-turbo="false">Report</a>
<form action="…" data-turbo="false">…</form>
```

`data-turbo="false"` sur un élément désactive Drive pour lui et ses descendants. De
vraies raisons de l'utiliser : un téléchargement de fichier, une route qui renvoie une
réponse non HTML, une page sous un système de build d'assets différent.

Une balise qui vaut la peine d'être ajoutée à tout ce qui est sensible à la version dans
`<head>` :

```twig
{{ importmap('app') }}
```

avec `framework.asset_mapper.importmap_script_attributes: { 'data-turbo-track': 'reload' }`.
Turbo force alors un rechargement complet quand l'élément suivi change entre deux
navigations — c'est ainsi qu'un utilisateur présent sur le site depuis avant un
déploiement cesse de faire tourner l'ancien JavaScript contre le nouveau HTML.

**Ne mets pas non plus un `nonce` par requête dans ce même sac d'attributs** — voir
« Ce que Drive casse » ci-dessous, et `assetmapper.md`. Cela transforme chaque
navigation en rechargement complet.

## Ce que Drive casse

Tout ce qui supposait un chargement de page.

| Hypothèse | Réalité avec Drive |
|---|---|
| `DOMContentLoaded` se déclenche à chaque page | Se déclenche **une seule fois**, au tout premier chargement |
| `window.onload` s'exécute à la navigation | Plus jamais |
| Un `<script>` inline dans le body s'exécute quand sa page apparaît | S'exécute la première fois ; ensuite, Turbo peut ou non le réexécuter, et il ne faut compter sur aucun des deux cas |
| Les variables globales se réinitialisent entre les pages | Elles persistent. De même que les timers, les écouteurs et les fuites mémoire |
| `<head>` est ce que la page a envoyé | Turbo compare les éléments marqués `data-turbo-track="reload"` ; si l'un d'eux change, **rechargement complet** |

Cette dernière ligne est celle qui annule silencieusement Drive, et le résumé habituel
qu'on en fait — « Turbo compare le `<head>` » — est faux d'une manière qui t'envoie
sur le mauvais élément. Mesuré dans `turbo.index.js` :
`HeadSnapshot::trackedElementSignature()` concatène le `outerHTML` **uniquement** des
éléments portant `data-turbo-track="reload"`, et une navigation recharge quand cette
chaîne diffère entre deux snapshots. Le contenu non suivi du `<head>` est fusionné, pas
rechargé — un nonce par requête sur une `<meta>` ne coûte rien.

L'échec est donc précis : il survient quand quelque chose de **variable** atterrit sur
un élément **suivi**. La façon dont cette suite tombe dans le piège consiste à mettre un
nonce CSP par requête dans `importmap_script_attributes` tout en suivant aussi ces
scripts — les deux attributs vont sur les mêmes balises `<script>`, la signature change
à chaque réponse, et **chaque navigation devient un chargement de page complet, en
permanence**. Drive a l'air installé et ne fait rien ; le symptôme est « Turbo est
installé mais les pages clignotent toujours ». Choisis : suis les scripts et
transporte le nonce ailleurs, ou mets-leur un nonce et suis à la place une
`<meta name="app-version" content="{{ release_sha }}" data-turbo-track="reload">`, qui
est la chose que tu voulais réellement comparer. Voir `assetmapper.md`,
« Content Security Policy ».

Le correctif pour les quatre premières lignes n'est pas de disséminer des écouteurs
`turbo:load` un peu partout — c'est Stimulus. Le `connect()` d'un contrôleur s'exécute
à chaque fois que son élément entre dans le DOM, y compris après un remplacement de
body par Turbo, et `disconnect()` offre le point d'accroche de nettoyage qu'un
gestionnaire `DOMContentLoaded` n'a jamais eu.

## Turbo Frames

```twig
<turbo-frame id="book_reviews" src="{{ path('review_index', {book: book.id}) }}" loading="lazy">
    <p>Loading reviews…</p>
</turbo-frame>
```

Un frame est un contexte de navigation cloisonné : un lien ou un formulaire **à
l'intérieur** du frame ne remplace que le contenu du frame, à condition que la réponse
contienne un `<turbo-frame>` avec le même id. `loading="lazy"` diffère la récupération
jusqu'à ce que le frame entre dans le viewport au défilement.

Ce pour quoi les frames sont réellement doués : un panneau lourd qui ne doit pas
retarder la page principale (`src` en lazy), l'édition en ligne (le frame remplace une
ligne par un formulaire, puis inversement), et les modales.

Le piège : la réponse **doit** contenir un id de frame correspondant, sinon Turbo
signale « Content missing ». Le contrôleur qui répond à une requête de frame rend
généralement un template de fragment qui enveloppe sa sortie dans le même
`<turbo-frame id="…">`. En Twig, `turbo_is_frame_request()` indique si la requête
courante provient d'un frame, si bien qu'une seule action peut servir à la fois la page
complète et le fragment.

Les frames sont un mécanisme de *mise en page*. Décider « ce lien doit-il ne remplacer
que cette boîte » n'est pas la même question que l'arbitrage Live Component / Stream de
`SKILL.md`, et les deux se combinent : un Live Component peut vivre à l'intérieur d'un
frame.

## Turbo Streams

Un stream est une liste d'opérations appliquées à des éléments de la page courante :

```html
<turbo-stream action="append" targets="#notifications">
    <template><div class="toast">A new review was published</div></template>
</turbo-stream>
```

Actions : `append`, `prepend`, `replace`, `update`, `remove`, `before`, `after`,
`refresh`. Un stream atteint le navigateur de deux façons — **comme réponse à une
soumission de formulaire**, ou **poussé** depuis le serveur via Mercure. `SKILL.md`
arbitre entre ces deux options et un Live Component ; ce fichier en couvre la mécanique.

Vérifié avec `symfony/ux-turbo` 3.4 sur Symfony 8.1.

**Vérifie sur quelle version majeure tu es avant d'utiliser l'un des helpers PHP
ci-dessous.** `ux-turbo` 3.0 exige Symfony ≥ 7.4 et PHP ≥ 8.4, donc un projet situé
n'importe où dans la tranche 6.4–7.3 de cette suite est sur `ux-turbo` 2.x, où plusieurs
d'entre eux n'existent pas :

| Helper | Depuis | Écrire à la place |
|---|---|---|
| `TurboStreamResponse`, `Helper\TurboStream`, `<twig:Turbo:Stream:*>` | 2.21 (actions génériques et personnalisées 2.22) | `$request->setRequestFormat(TurboBundle::STREAM_FORMAT)` et rendre à la main un template d'éléments `<turbo-stream>` — la recette de soumission de formulaire ci-dessous, qui fonctionne sur toutes les versions |
| `turbo_is_frame_request()` | 3.1 | `$request->headers->has('Turbo-Frame')` dans le contrôleur, passé au template comme variable |
| `turbo_stream_from()` | 3.1 | `turbo_stream_listen()`, le nom en 2.x de la même balise |

`TurboBundle::STREAM_FORMAT` et `STREAM_MEDIA_TYPE` sont là depuis la 1.x. Les points
d'entrée depuis PHP :

| Classe / constante | Ce que c'est |
|---|---|
| `Symfony\UX\Turbo\TurboBundle::STREAM_FORMAT` | `'turbo_stream'` — le format de requête Symfony, enregistré pour chaque requête par le `RequestListener` de `ux-turbo` |
| `Symfony\UX\Turbo\TurboBundle::STREAM_MEDIA_TYPE` | `'text/vnd.turbo-stream.html'` |
| `Symfony\UX\Turbo\TurboStreamResponse` | Une `Response` qui définit elle-même ce content type, avec un fluent `append()` / `prepend()` / `replace()` / `update()` / `remove()` / `before()` / `after()` / `refresh()` / `action()` |
| `Symfony\UX\Turbo\Helper\TurboStream` | Les mêmes actions comme méthodes statiques renvoyant la chaîne `<turbo-stream>`, pour quand tu en assembles une à la main |

Et en Twig, le bundle fournit des composants anonymes pour les mêmes actions :
`<twig:Turbo:Stream:Append target="#book_list">…</twig:Turbo:Stream:Append>`, plus
`Prepend`, `Replace` (avec `morph`), `Update`, `Remove`, `Before`, `After`, `Refresh` et
le générique `<twig:Turbo:Stream action="…">`. Ce sont des composants Twig ordinaires —
utilise-les ou écris la balise à la main, ils produisent le même balisage. Note que
la prop se nomme `target` (singulier) et se rend en `targets` : elle prend donc un
**sélecteur** : vérifié, `<twig:Turbo:Stream:Append target="#book_list">` génère
`<turbo-stream action="append" targets="#book_list">`.

## Répondre à une soumission de formulaire par un stream

**Turbo le demande déjà.** Sur chaque soumission non-GET, il ajoute
`text/vnd.turbo-stream.html` à l'en-tête `Accept` — vérifié dans le runtime de Turbo :
le type stream est demandé quand la requête n'est pas sûre, *ou* quand
`data-turbo-stream` est présent sur le formulaire ou sur le submitter. Un formulaire GET
ou un lien a donc besoin de cet attribut explicitement ; un formulaire POST, PUT, PATCH
ou DELETE n'en a pas besoin.

Le contrôleur n'a donc qu'à décider de répondre dans ce format :

```php
#[Route('/books', name: 'book_add', methods: ['POST'])]
public function add(Request $request, BookCreator $creator): Response
{
    $form = $this->createForm(AddBookType::class);
    $form->handleRequest($request);

    if (!$form->isSubmitted() || !$form->isValid()) {
        // 422 + page complète, ce dont Turbo a besoin pour réafficher le formulaire avec les erreurs
        return $this->render('book/new.html.twig', ['form' => $form]);
    }

    $book = $creator->create($form->getData());

    if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return $this->render('book/add.stream.html.twig', [
            'book' => $book,
            'count' => $this->shelf->count(),
        ]);
    }

    return $this->redirectToRoute('book_index', status: Response::HTTP_SEE_OTHER);
}
```

```twig
{# templates/book/add.stream.html.twig #}
<turbo-stream action="append" targets="#book_list">
    <template>{{ include('book/_book_row.html.twig', { book: book }) }}</template>
</turbo-stream>

<turbo-stream action="update" targets="#book_count">
    <template>{{ count }}</template>
</turbo-stream>

<turbo-stream action="prepend" targets="#flashes">
    <template>{{ include('_flash.html.twig', { type: 'success', message: 'book.flash.added'|trans }) }}</template>
</turbo-stream>
```

Trois éléments qui rendent cette forme correcte, tous vérifiés en l'exécutant :

- **C'est `setRequestFormat()` qui définit le content type.** `render()` renvoie une
  `Response` sans `Content-Type`, et `Response::prepare()` le remplit ensuite à partir
  du format de requête. Avec l'appel : `text/vnd.turbo-stream.html; charset=UTF-8`. Sans
  lui : `text/html`, et Turbo ignore le corps de la réponse — le classique « mon stream
  ne fait rien ».
- **Le garde-fou `getPreferredFormat()` garde l'action utilisable sans Turbo.** Vérifié :
  le même POST sans stream dans `Accept` retombe sur la redirection 303, donc le
  formulaire fonctionne toujours avec JavaScript désactivé, depuis un client de test, ou
  depuis curl. Une action qui répond *uniquement* au format stream est cassée pour tous
  les autres.
- **La branche invalide reste un rendu HTML normal en 422**, même quand la requête
  acceptait un stream. Vérifié : 422 avec `text/html`. N'essaie pas d'être malin et de
  streamer les erreurs du formulaire en retour ; la gestion du 422 par Turbo réaffiche
  déjà la page avec elles.

`#[Template]` fonctionne aussi ici — il rend le template dans une `Response` exactement
comme `render()`, si bien qu'une requête dont le format a été mis à `turbo_stream`
revient avec le content type stream. Vérifié : `#[Template('book/add.stream.html.twig')]`
sur une action qui appelle `$request->setRequestFormat(TurboBundle::STREAM_FORMAT)` et
renvoie un tableau produit `text/vnd.turbo-stream.html; charset=UTF-8`. L'attribut ne
sait rien de Turbo ; il ne fait tout simplement pas obstacle.

L'alternative, quand les fragments sont des one-liners qui ne méritent pas un template :

```php
return (new TurboStreamResponse())
    ->append('#book_list', $this->renderView('book/_book_row.html.twig', ['book' => $book]))
    ->update('#book_count', (string) $count)
    ->remove('#empty_state');
```

(Sur PHP 8.4, les parenthèses extérieures sont optionnelles —
`new TurboStreamResponse()->append(…)` s'analyse correctement. En dessous de 8.4, elles
sont obligatoires.)

Préfère le template pour tout ce qui contient du balisage : les fragments vivent alors
à côté des autres templates de la page, et `_book_row.html.twig` est le *même* include
que celui utilisé par la page complète — c'est la seule chose qui empêche les deux
chemins de rendu de diverger.

## `target` et `targets` ne sont pas le même attribut

Turbo les lit différemment, vérifié dans le runtime de Turbo : `target` passe par
`getElementById()` et prend un **id nu**, `targets` passe par `querySelectorAll()` et
prend un **sélecteur CSS** — et peut correspondre à plusieurs éléments.

`TurboStreamResponse` et le helper `TurboStream` génèrent tous les deux `targets`. La
valeur que tu leur passes doit donc être un sélecteur :

```php
$response->append('#book_list', $html);   // ✔ correspond
$response->append('book_list', $html);    // ✘ ne correspond silencieusement à rien
```

C'est la deuxième cause de « le stream est arrivé et rien n'a bougé », après le content
type. Aucune des deux n'échoue bruyamment.

## Streams via Mercure

Le troisième cas — personne n'a cliqué, donc il n'y a pas de requête à laquelle
répondre — a besoin d'un transport. `ux-turbo` fournit un pont Mercure :

```twig
{{ turbo_stream_from('notifications') }}
```

(`turbo_stream_listen()` fonctionne toujours et c'est ce qu'utilise le code plus ancien ;
c'est **déprécié depuis ux-turbo 3.1** au profit de `turbo_stream_from()`. Vérifié dans
`vendor/symfony/ux-turbo/src/Twig/TwigExtension.php`.)

La page s'abonne au topic ; le serveur publie des fragments de stream vers le hub
Mercure ; les navigateurs connectés les appliquent. C'est la forme adaptée pour une
cloche de notification, une bannière « quelqu'un d'autre a modifié cet enregistrement »,
ou une barre de progression alimentée par un handler Messenger. Ce n'*est pas* la forme
adaptée pour « l'utilisateur a soumis un formulaire et trois boîtes doivent se mettre à
jour » — ça, c'est une réponse en stream, ci-dessus, et ça ne nécessite aucun hub.

Sois honnête sur le coût avant de le proposer : **un hub Mercure est un processus de
plus à faire tourner, surveiller et sécuriser**, avec sa propre configuration JWT, et il
doit être accessible depuis le navigateur. Sur un projet qui n'en a pas déjà un, « ajouter
une notification en direct » est une décision d'infrastructure, pas une modification de
template. Dis-le.

## `#[Broadcast]`

```php
#[Broadcast]
class Review { … }
```

Sur une entité Doctrine, ceci publie un stream vers Mercure à chaque insertion, mise à
jour et suppression, en rendant `templates/broadcast/Review.stream.html.twig`.

C'est réellement utile pour un tableau d'administration à mise à jour en direct. C'est
aussi une règle qui vit sur une entité et se déclenche depuis un flush Doctrine — loin
du code qui en est la cause, et en dehors de la ligne « les services portent les règles,
les entités portent les données » que ce standard trace ailleurs. Préfère une
publication explicite depuis le service qui a effectué le changement ; réserve
`#[Broadcast]` aux cas où le besoin est vraiment « chaque changement sur cette table,
peu importe qui l'a fait ».

## Stimulus : la forme d'un contrôleur

```js
// assets/controllers/character_counter_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'count'];
    static values = { max: Number };

    connect() {
        this.update();
    }

    update() {
        const remaining = this.maxValue - this.inputTarget.value.length;
        this.countTarget.textContent = remaining;
        this.countTarget.classList.toggle('is-over', remaining < 0);
    }
}
```

```twig
<div {{ stimulus_controller('character-counter', { max: 280 }) }}>
    <textarea data-character-counter-target="input"
              data-action="input->character-counter#update"></textarea>
    <span data-character-counter-target="count"></span>
</div>
```

- **Le nom de fichier détermine l'identifiant** : `character_counter_controller.js` →
  `character-counter`. Une erreur ici et le contrôleur ne se connecte jamais,
  silencieusement.
- **`connect()` s'exécute à chaque insertion** — premier chargement, navigation Turbo,
  réaffichage d'un Live Component qui introduit l'élément. **`disconnect()` est
  l'endroit où retirer les écouteurs et effacer les timers** ; le sauter, c'est comment
  une application Turbo fuit à travers cinquante navigations.
- Préfère les fonctions Twig `stimulus_controller()` / `stimulus_action()` /
  `stimulus_target()` aux attributs `data-` écrits à la main : elles échappent
  correctement les valeurs et se fusionnent quand plusieurs contrôleurs partagent un
  élément.
- **Un contrôleur par comportement, nommé d'après le comportement.** `clipboard`,
  `character-counter`, `sortable`. Un contrôleur `book-page` accumule chaque interaction
  non liée sur cette page et n'est réutilisable nulle part.
- Marque un contrôleur rarement utilisé comme lazy avec
  `/* stimulusFetch: 'lazy' */` au-dessus de la classe : il n'est alors récupéré que
  lorsqu'un élément correspondant apparaît.

## Aucune règle métier en JavaScript

Un contrôleur Stimulus peut calculer ce qu'il faut *afficher*. Il ne peut pas décider ce
qui est *vrai*.

| Légitime en JS | Relève du serveur |
|---|---|
| Activer le bouton de soumission quand un champ est non vide | Savoir si la valeur est valide |
| Afficher un avertissement au-delà de 280 caractères | Rejeter la soumission au-delà de 280 caractères |
| Formater un nombre pour l'affichage | Calculer le prix |
| Cacher un élément de menu réservé aux administrateurs | Savoir si l'utilisateur peut effectuer l'action |

La raison n'est pas la pureté. Une règle exprimée deux fois diverge dès que l'une des
deux copies est modifiée — et la copie côté client est celle qu'un attaquant modifie. Les
vérifications côté client sont de l'ergonomie ; la copie du serveur est la règle. Si un
seuil doit apparaître aux deux endroits, transmets-le depuis le serveur comme valeur
Stimulus plutôt que de saisir le nombre deux fois.

La même règle tranche la question du `<script>` inline. Un `<script>` dans un template
ne s'exécute qu'une fois, ne survit pas à une navigation Turbo, ne peut pas être testé,
ne peut pas être réutilisé, et est invisible pour `importmap:audit`. La seule forme
acceptable est un îlot de données `<script type="application/json">` lu par un
contrôleur.

## Débogage

| Symptôme | Cause |
|---|---|
| Le contrôleur ne se connecte jamais | Le nom de fichier doit être `<name>_controller.js` dans `assets/controllers/`, l'élément `data-controller="<name>"` |
| Fonctionne au premier chargement, mort après un clic sur un lien | Du code hors d'un contrôleur Stimulus : `<script>` inline, `DOMContentLoaded` |
| Rechargement complet à chaque navigation | Un élément **suivi** diffère à chaque requête — presque toujours un `nonce` par requête assis sur les mêmes balises `<script>` que `data-turbo-track="reload"`. Le contenu non suivi du `<head>` est fusionné et n'est pas en cause |
| Le frame ne se met pas à jour, « content missing » | La réponse n'a pas de `<turbo-frame>` avec le même id |
| Une redirection de formulaire ne fait rien de visible | Turbo a besoin d'une redirection 3xx, ou d'un 422 pour réafficher un formulaire invalide. Un 200 avec le HTML du formulaire n'est pas une réponse Drive valide |
| Une réponse en stream est téléchargée comme fichier, ou affichée comme balisage brut | Elle est sortie en `text/html`. `$request->setRequestFormat(TurboBundle::STREAM_FORMAT)` avant le rendu, ou renvoyer une `TurboStreamResponse` |
| La réponse en stream est correcte et rien ne bouge | `targets` est un sélecteur CSS : `targets="#book_list"`, pas `targets="book_list"`. Ou l'élément n'est réellement pas dans le DOM |
| Un formulaire GET ou un lien n'obtient jamais de stream | Turbo ne demande le type stream que sur les méthodes non sûres ; ajoute `data-turbo-stream` au formulaire ou au submitter |
| Le stream poussé est publié, rien ne se passe | Pas de `turbo_stream_from()` sur la page, le hub est inaccessible, ou le sélecteur ne correspond à rien dans le DOM |
| La mémoire grossit à travers les navigations | Des écouteurs enregistrés dans `connect()` et jamais retirés dans `disconnect()` |
