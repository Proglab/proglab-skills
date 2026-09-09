---
name: symfony-proglab-frontend
description: >-
  Construire et déboguer le frontend d'une application Symfony avec AssetMapper, Twig,
  Stimulus, Turbo et Symfony UX — sans bundler, sans étape de build Node. Utiliser ce
  skill dès qu'on demande de rendre une page interactive, d'ajouter un filtre ou une
  recherche en direct, d'ajouter une autocomplétion ou un menu déroulant, d'ajouter une
  bibliothèque JavaScript, de styliser une page ou de configurer Tailwind, d'ajouter une
  icône, de construire un composant Twig réutilisable ou un Live Component, d'ajouter
  Turbo Drive, Frames ou Streams, d'écrire un contrôleur Stimulus, d'organiser les
  templates ou les clés de traduction, ou de définir un thème de formulaire. À utiliser
  aussi pour les pannes : « mon JavaScript ne s'exécute pas », « mon JS a cessé de
  fonctionner après un clic sur un lien », « le CSS ne s'est pas mis à jour », « la page
  est lente à charger », « les assets renvoient une 404 en production », « les images
  sont servies par PHP », « importmap:audit échoue » — et chaque fois que la question est
  de savoir *où* doit vivre un comportement, entre un contrôleur Stimulus, un Live
  Component et un composant Twig.
---

# Frontend

> **Niveau : socle dès qu'il y a un frontend** — AssetMapper, Stimulus et la règle du sans-bundler. Les Live Components, Mercure et chaque package UX sont à la demande — à ajouter quand le besoin apparaît, comme l'indiquent les tableaux ci-dessous.

Twig, Stimulus, Turbo et Symfony UX, servis par AssetMapper. Sans bundler.

```bash
symfony console importmap:require chart.js   # ajoute une dépendance JS
symfony console debug:asset-map              # ce qui est réellement mappé
symfony console importmap:audit              # dépendances JS vulnérables
symfony console asset-map:compile            # étape de déploiement — voir plus bas
```

## Le prix du sans-bundler, dit une bonne fois

AssetMapper sert tes fichiers comme des modules ES avec une import map. Il n'y a aucune
étape de compilation, donc **pas de JSX, pas de composants monofichiers Vue ou Svelte,
pas de TypeScript, pas de transpilation avancée**, et pas de tree-shaking d'une
dépendance dont tu n'utilises qu'un coin.

C'est le marché, et il ne se renégocie pas fonctionnalité par fonctionnalité. Si une
demande a réellement besoin d'un framework JavaScript avec une étape de build, dis que
c'est hors périmètre plutôt que d'introduire un bundler en douce — un bundler, c'est une
seconde chaîne d'outils, un second lockfile, un second job CI et une seconde chose qui
casse à la prochaine montée de version de Node. (Pour situer un projet existant
uniquement : **Webpack Encore est désormais l'option historique de Symfony** et
**`symfony/reprise`** est l'intégration officielle Vite/Rsbuild. Aucun des deux n'a sa
place dans un projet déjà sur AssetMapper.)

## Le piège de la production

C'est le point que tout le monde rate, et c'est pour ça que « AssetMapper est lent » est
une croyance répandue. **AssetMapper ne concatène rien** — une page charge des dizaines
de petits fichiers. C'est très bien, meilleur qu'un bundle pour la granularité du cache,
sous deux conditions :

- **HTTP/2 ou HTTP/3.** En HTTP/1.1, le navigateur ouvre six connexions et met le reste
  en file d'attente ; beaucoup de petits fichiers perdent alors face à un bundle, et
  nettement. HTTP/2 est un **prérequis, pas une optimisation**. `symfony server:start`
  le fournit en local. En production, Apache a besoin de `mod_http2` activé *et* de
  `Protocols h2 h2c http/1.1` défini explicitement sur le vhost
  (`symfony-proglab-deployment`) — une configuration HTTP/1.1 nue, Apache ou autre, ne
  te le donne pas gratuitement.
- **La compression.** Chaque fichier est petit ; les gains viennent de brotli/zstd/gzip.
  Depuis Symfony 7.3, `framework.asset_mapper.precompress: true` écrit des fichiers
  `.br` / `.zst` / `.gz` à côté de chaque asset compilé, pour que le serveur web les
  serve directement. En 6.4–7.2 cette option n'existe pas — compresse au niveau du
  serveur web. La règle ne change pas, seul le moyen change.

Et l'étape de déploiement qui décide si tout ça compte vraiment :

```bash
php bin/console asset-map:compile
```

Sans elle, `framework.asset_mapper.server` prend le relais et sert chaque asset **à
travers le processus PHP** — un boot complet du kernel par image, par feuille de style,
par module. Le site fonctionne, ce qui est justement le problème : personne ne s'en rend
compte jusqu'à ce que le trafic arrive. (La suite de la séquence de déploiement relève de
`symfony-proglab-deployment`.)

## Ajouter une dépendance JavaScript

```bash
symfony console importmap:require chart.js   # l'épingle dans importmap.php
symfony console importmap:install            # restaure assets/vendor/ à partir de là
symfony console importmap:outdated           # ce qui a changé
symfony console importmap:audit              # vulnérabilités connues
```

`importmap:audit` **a sa place en CI**, à côté de `composer audit`. Sans npm nulle part
dans la chaîne, rien d'autre ne signalera jamais qu'un package JavaScript épinglé a une
faille publiée. C'est la vérification la plus souvent oubliée sur cette stack.

## Où vit le comportement : suivre l'état

Une seule question tranche, à chaque fois : **où vit l'état ?**

| L'état vit… | Utiliser | Parce que |
|---|---|---|
| Dans le navigateur uniquement | **Contrôleur Stimulus** | Rien à demander au serveur : un menu ouvert/fermé, un copier-coller, un compteur de caractères, un glisser-déposer avant sauvegarde |
| Sur le serveur, **retenu d'une interaction à l'autre** | **Live Component** | Le serveur le possède et doit le réafficher *avec* lui : une liste de livres filtrée, une recherche, un formulaire validé au fil de la frappe, un tableau paginé |
| Sur le serveur, avec **rien à retenir** | **Un contrôleur renvoyant un Turbo Stream** | Une soumission, plusieurs zones à rafraîchir, aucune valeur transportée entre les allers-retours — voir l'arbitrage ci-dessous |
| Nulle part — c'est du rendu | **Composant Twig** | Une fonction qui va des props au HTML : un badge, une fiche livre, un affichage de note, une coquille de modale |

Les modes de défaillance que cela évite sont précis : un contrôleur Stimulus qui filtre
en JavaScript duplique une règle qui vit déjà dans un repository, et les deux divergent ;
un Live Component utilisé pour une simple bascule visuelle envoie une requête HTTP pour
ouvrir un menu ; un composant Twig doté d'un état mutable se dote d'un mécanisme de
réaffichage que personne n'a demandé.

## Live Component, réponse en Stream, Stream poussé

Trois outils mettent à jour une partie de page depuis le serveur. **Une seule question
arbitre, et elle se répond sans avoir besoin de goût :**

> **Y a-t-il un état à retenir entre deux interactions ?**
>
> - **Oui** → **Live Component.** L'état, *c'est* le composant.
> - **Non, et l'utilisateur a agi** → **un Turbo Stream renvoyé comme réponse à cette
>   soumission.**
> - **Non, et l'utilisateur n'a pas agi** → **un Turbo Stream poussé** via Mercure.

| L'interaction | Outil | Pourquoi celui-là |
|---|---|---|
| Un filtre qui retient ce sur quoi il filtre, une recherche qui s'affine au fil de la frappe, un formulaire validé champ par champ, un assistant multi-étapes | **Live Component** | La valeur doit survivre à l'aller-retour *et revenir déjà rendue*. C'est exactement ce qu'apporte un `LiveProp` sérialisé |
| Un POST de formulaire après lequel plusieurs zones distinctes doivent changer — la liste gagne une ligne, le compteur augmente, un message flash apparaît | **Réponse en Turbo Stream** (`text/vnd.turbo-stream.html`) | Rien à retenir : une soumission, une réponse, plusieurs cibles. Pas de classe de composant, pas d'état dans le DOM, un seul aller-retour |
| Une notification, un changement fait par un autre utilisateur, la progression d'une tâche asynchrone | **Turbo Stream poussé** via Mercure | Personne n'a cliqué. Il n'y a pas de requête à laquelle répondre, donc c'est au serveur d'en ouvrir une |

Le raisonnement derrière la première ligne : un aller-retour de Live Component renvoie
*l'état rendu propre au composant* — pas d'identifiants de cible à nommer, pas de
template de fragment à maintenir en phase avec le rendu de page complète, les props
préservées entre les interactions. Cette mécanique coûte une classe de composant, un
cycle de vie à comprendre et une requête HTTP par interaction, et elle ne se rentabilise
que lorsqu'il y a un état à transporter. Un composant dont les props sont identiques à
chaque rendu est un contrôleur avec des étapes en plus.

Le raisonnement derrière la deuxième : répondre à une soumission de formulaire par un
stream est l'usage principal documenté de Turbo lui-même, et c'est sans état par
construction. Tu nommes les cibles et tu écris les fragments — c'est le prix à
payer — mais il n'y a rien à sérialiser dans le DOM, rien à réhydrater et rien à
remonter. Privilégie-le comme choix **par défaut** dès qu'une soumission doit toucher
deux zones qu'une seule navigation Drive ou un seul frame ne peuvent pas couvrir
ensemble. Côté contrôleur, c'est un format de requête et un template :
`references/turbo-and-stimulus.md` contient l'exemple travaillé.

La troisième ligne est celle qui ne change pas, et la coûteuse : **un hub Mercure est un
processus de plus à faire tourner, surveiller et sécuriser**, avec sa propre
configuration JWT et accessible depuis le navigateur. « Ajouter une notification en
direct » est une décision d'infrastructure, pas une modification de template. Dis-le
avant de le proposer.

Deux avertissements survivent à l'arbitrage, parce qu'ils concernent les outils
eux-mêmes plutôt que le choix entre eux :

- **Un Live Component se remonte à chaque interaction.** Constructeur, `mount()`,
  réhydratation des props, rendu complet — à chaque frappe. Un `LiveProp` coûteux, ou un
  chemin de rendu qui se ramifie en une douzaine de requêtes, ne rend pas la page lente,
  il la rend inutilisable.
- **Une `#[LiveAction]` est un endpoint publiquement postable.** La route est
  `ux_live_component` et n'importe qui peut y poster ; le fait que son bouton ne
  s'affiche que pour les administrateurs ne protège rien. Elle a besoin d'un
  `#[IsGranted]` exactement comme une action de contrôleur.

## Les composants prennent des DTOs, jamais des entités

```twig
<twig:BookCard :book="book" />   {# book est une BookCardView, pas une entité Book #}
```

Un template Twig qui détient une entité Doctrine déclenche du lazy loading depuis
l'intérieur du rendu : `book.author.name` dans une boucle est un N+1 que personne ne voit
dans le code, et `book.ratings|length` hydrate chaque ligne de note pour les compter. Ces
requêtes surviennent dans un template, loin de tout repository, invisibles à un test au
niveau service. Passe un DTO — un modèle de lecture construit par le service, avec
exactement les champs que le composant affiche. Pour un Live Component, c'est doublement
contraignant : un `LiveProp` est sérialisé dans le DOM et réhydraté à chaque interaction,
donc une entité à cet endroit signifie un aller-retour ORM par frappe.

**Le piège du Live Component :** chaque interaction est une requête HTTP et le composant
est réinstancié — `mount()` s'exécute à nouveau, les props se réhydratent, les
dépendances sont réinjectées, tout ce qui était retenu dans une propriété simple a
disparu. Traite « combien de requêtes coûte une frappe » comme une vraie question.
Détails : `references/components.md`.

## Turbo

`ux-turbo` est trois choses aux portées différentes :

- **Drive** — intercepte les liens et les formulaires, récupère la page, remplace le
  `<body>`. Actif par défaut une fois installé.
- **Frames** — `<turbo-frame id="…">` ; un lien à l'intérieur ne remplace que lui.
- **Streams** — des fragments (`append`, `replace`, `remove`, …) appliqués à des cibles.
  Ils atteignent la page de deux façons : comme **réponse à une soumission de
  formulaire** (Turbo demande `text/vnd.turbo-stream.html` sur chaque soumission non-GET,
  donc le contrôleur n'a qu'à répondre dans ce format), ou **poussés** via Mercure sans
  qu'aucune interaction n'ait eu lieu.

**Drive change le cycle de vie de la page, et c'est ce qu'il casse.**
`DOMContentLoaded` se déclenche une fois, au premier chargement, et jamais plus — tout
script qui suppose s'exécuter à chaque page cesse de fonctionner après la première
navigation. Turbo compare aussi le `<head>` entre les navigations, donc tout ce qui y
change à chaque requête (un nonce, un horodatage) force un rechargement complet. Le
correctif n'est pas un contournement, c'est la règle ci-dessous : le comportement vit
dans un contrôleur Stimulus, dont le `connect()` s'exécute à chaque insertion, y compris
lors des remplacements de Turbo. Voir `references/turbo-and-stimulus.md`.

## Stimulus

- **Un contrôleur par comportement**, nommé d'après ce qu'il fait, pas d'après où il se
  trouve : `clipboard_controller.js`, `character_counter_controller.js` — pas
  `book_page_controller.js`.
- **Aucun `<script>` inline dans un template.** Il ne s'exécute qu'une fois, ne survit
  pas à une navigation Turbo, ne peut pas être réutilisé, et est invisible pour
  `importmap:audit`. La seule exception est un îlot de données
  `<script type="application/json">` lu par un contrôleur.
- **Aucune règle métier en JavaScript.** Un seuil, un prix, une vérification
  d'éligibilité décidés côté client *vont* diverger de ceux du serveur — et c'est celle
  du serveur qui compte. Le JavaScript peut afficher une règle ; il ne peut pas la
  posséder.

## Tailwind, sans Node

```bash
composer require symfonycasts/tailwind-bundle
symfony console tailwind:init
symfony console tailwind:build --watch      # pendant le développement
```

Le bundle télécharge le binaire autonome de Tailwind — pas de `npm`, pas de
`node_modules` — et enregistre un compilateur AssetMapper, si bien que
`assets/styles/app.css` est traité à la volée en dev et au moment de `asset-map:compile`
en production. Épingle `binary_version` dans
`config/packages/symfonycasts_tailwind.yaml` : non épinglée, une version majeure de
Tailwind arrive sur la prochaine machine qui build. Le thème de formulaire est déclaré
**une seule fois**, globalement :

```yaml
# config/packages/twig.yaml
twig:
    form_themes: ['tailwind_2_layout.html.twig']
```

Jamais `{% form_theme form '…' %}` dans un template. Des thèmes par template, c'est
comment un projet finit avec trois styles de formulaire et aucun moyen de les changer
tous en même temps.

## Packages Symfony UX : à ajouter quand le besoin apparaît

| Package | Le besoin auquel il répond |
|---|---|
| `symfony/ux-icons` | `{{ ux_icon('tabler:book') }}` — n'importe quelle icône, SVG intégré, pas de police d'icônes |
| `symfony/ux-autocomplete` | Indispensable dès qu'un `EntityType` dépasse quelques dizaines d'options : un `<select>` avec 5 000 `<option>` est une page lente et inutilisable |
| `symfony/ux-chartjs` | Chart.js à partir d'un jeu de données construit en PHP |
| `symfony/ux-toggle-password` | L'œil pour afficher/masquer un champ mot de passe |
| `symfony/ux-lazy-image` | Placeholder Blurhash plus lazy loading natif |

N'en installe aucun par anticipation — chacun ajoute un bundle, un contrôleur Stimulus
et une ligne dans `importmap.php`. Remarque pour la production concernant `ux-icons` : le
téléchargement Iconify à la demande est **activé par défaut**, donc une icône manquante
est récupérée depuis une API tierce au moment du rendu. Lance `ux:icons:lock` pour
importer chaque icône utilisée dans `assets/icons/`, et désactive le mode à la demande
hors dev.

## Conventions Twig

- Noms de templates et de répertoires en **snake_case** : `templates/book/show.html.twig`.
- **Fragments préfixés par `_`** : `_book_row.html.twig`. Le préfixe indique « pas une
  page, pas routable, inclus par autre chose ».
- **Héritage à trois niveaux** : `base.html.twig` (document, `importmap()`, méta) →
  `layout/*.html.twig` (le chrome d'une section) → la page. Les pages qui étendent
  `base` directement dupliquent le chrome ; quatre niveaux deviennent impossibles à
  suivre.
- **Les clés de traduction nomment l'intention, pas le texte** : `book.delete.confirm`,
  pas `Are you sure?`. Format **XLIFF**, un fichier par domaine et par locale — une clé
  qui *est* la phrase anglaise change d'identité le jour où le texte change.
- **`asset()` et `path()`, toujours.** Un `/assets/app.js` codé en dur contourne le
  digest et reste servi périmé pour toujours ; un `/books/12` codé en dur casse quand la
  route bouge.
- Les filtres et fonctions Twig via **`Twig\Attribute\AsTwigFilter`** / `AsTwigFunction` /
  `AsTwigTest` — namespace `Twig\`, venant de `twig/twig` lui-même, pas d'un namespace
  Symfony — plutôt qu'une classe `AbstractExtension`. Twig 3.12+ ; en dessous, écris la
  classe d'extension.

## Quand le frontend se comporte mal

| Symptôme | Cause |
|---|---|
| 404 sur `/assets/…` en production | `asset-map:compile` n'a pas été lancé au déploiement |
| Les assets se chargent mais chaque requête boote PHP | Même cause : `asset_mapper.server` compense |
| Plus lent que l'ancien bundle | HTTP/1.1, ou pas de compression. Vérifie le protocole avant de toucher au code |
| Le JS fonctionne, puis s'arrête après un clic sur un lien | Un `<script>` inline ou un écouteur `DOMContentLoaded` ; Turbo Drive a remplacé le body |
| Un contrôleur Stimulus ne se connecte jamais | Le fichier doit être `name_controller.js` sous `assets/controllers/`, l'élément `data-controller="name"` |
| Les changements CSS n'apparaissent pas | Pas de `tailwind:build --watch` en cours, ou un `public/assets/` périmé d'une compilation précédente |
| Rechargement complet à chaque navigation Turbo | Quelque chose dans `<head>` diffère à chaque requête |
| `importmap:audit` fait échouer le build | Correct : un package JS épinglé a une faille. Fais `importmap:update` dessus |
| Un Live Component « perd » une valeur entre deux clics | La propriété n'est pas un `LiveProp`, donc elle ne fait pas partie de l'état sérialisé |
| Une page de liste déclenche des centaines de requêtes | Une entité a atteint un template. Passe un DTO |
| Une réponse en stream est téléchargée au lieu d'être appliquée | La réponse est sortie en `text/html` — `$request->setRequestFormat(TurboBundle::STREAM_FORMAT)` n'a pas été appelé |
| Une réponse en stream arrive mais rien ne bouge | `targets` prend un **sélecteur CSS**, donc un id nu ne correspond à rien. `targets="#book_list"`, ou `target="book_list"` |

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/assetmapper.md` | Ajouter une dépendance, CSS et Tailwind, CSP, préchargement, déboguer un asset manquant ou périmé, la checklist de production |
| `references/components.md` | Écrire un composant Twig ou Live Component, props et DTOs, formulaires dans un Live Component, tester les composants |
| `references/turbo-and-stimulus.md` | Turbo Drive/Frames/Streams, Mercure, le cycle de vie de la page, écrire et déboguer un contrôleur Stimulus |
