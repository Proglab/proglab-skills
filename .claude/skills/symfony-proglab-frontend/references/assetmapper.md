# AssetMapper en pratique

## Sommaire

- [Le modèle en un paragraphe](#le-modèle-en-un-paragraphe)
- [`importmap.php` est le fichier qui compte](#importmapphp-est-le-fichier-qui-compte)
- [Ce que `importmap()` génère réellement](#ce-que-importmap-génère-réellement)
- [Ajouter, mettre à jour et supprimer des dépendances](#ajouter-mettre-à-jour-et-supprimer-des-dépendances)
- [CSS](#css)
- [Tailwind sans Node](#tailwind-sans-node)
- [Contrôleurs Stimulus tiers](#contrôleurs-stimulus-tiers)
- [Content Security Policy](#content-security-policy)
- [Préchargement](#préchargement)
- [La checklist de production](#la-checklist-de-production)
- [Débogage](#débogage)

## Le modèle en un paragraphe

Chaque fichier sous un chemin mappé (`assets/` par défaut) reçoit un **chemin
logique** — `styles/app.css`, `controllers/search_controller.js`.
`asset('styles/app.css')` le résout en un chemin public **digéré**,
`/assets/styles/app-3d9f1c2.css`, où le digest est un hash du contenu. Modifie le
fichier, le digest change, l'URL change, les caches s'invalident d'eux-mêmes. C'est
toute l'histoire du versionnement : aucun manifeste à maintenir, aucune query string de
cache-busting, et `Cache-Control: immutable` est sûr sur `/assets/`.

Le JavaScript est servi comme des modules ES natifs. Les imports nus (`import { Chart }
from 'chart.js'`) sont résolus par une **import map** dans le `<head>`, que le
navigateur lit avant d'exécuter quoi que ce soit. Pas de bundling, pas de réécriture de
ton code source.

## `importmap.php` est le fichier qui compte

```php
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    'chart.js' => ['version' => '4.4.1'],
    'chart.js/auto' => ['version' => '4.4.1'],
];
```

Trois formes d'entrée, et la différence vaut d'être connue :

| Forme | Signification |
|---|---|
| `'path' => './assets/…'` | Un fichier du projet. Rien n'est téléchargé |
| `'version' => '4.4.1'` | Un package distant, **téléchargé dans `assets/vendor/`**. Jamais récupéré depuis un CDN à l'exécution |
| `'entrypoint' => true` | Peut être passé à `importmap()` dans un template. Tout ce qu'il importe est préchargé |

Que `assets/vendor/` soit commité ou non est une préférence de projet ; dans les deux
cas, `importmap:install` le reconstruit à partir de `importmap.php`, et cela s'exécute
automatiquement comme script Composer `post-install`. `importmap.php` est donc le
fichier à lire en revue de code — `assets/vendor/` est une sortie générée.

Des points d'entrée additionnels (un bundle d'administration, une feuille de style
d'impression) se déclarent de la même façon et se rendent avec
`{{ importmap(['app', 'admin']) }}`.

## Ce que `importmap()` génère réellement

Pas une seule balise script. Dans l'ordre :

1. `<link rel="stylesheet">` pour chaque entrée CSS de la map ;
2. `<script type="importmap">` avec le mapping nom → URL ;
3. un petit script inline qui charge le **polyfill `es-module-shims`** *seulement si*
   `HTMLScriptElement.supports('importmap')` est faux ;
4. `<link rel="modulepreload">` pour chaque module accessible depuis le point d'entrée ;
5. `<script type="module">import 'app';</script>`.

Deux conséquences sur lesquelles on trébuche. Le polyfill, si `es-module-shims` n'est
pas lui-même dans `importmap.php`, retombe sur une **URL de CDN jspm.io** (avec
`integrity` et `crossorigin` définis). Si une requête tierce depuis tes pages est
inacceptable, soit `importmap:require es-module-shims` pour l'héberger toi-même, soit
définis `framework.asset_mapper.importmap_polyfill: false` — tout navigateur qui
compte prend en charge les import maps depuis des années.

Et la liste de modulepreload signifie que **tout le graphe de dépendances du point
d'entrée est déclaré dans `<head>`**. C'est ce qui rend beaucoup de petits fichiers
rapides, et c'est aussi pourquoi un point d'entrée qui importe transitivement tout va à
l'encontre de l'objectif.

## Ajouter, mettre à jour et supprimer des dépendances

```bash
importmap:require chart.js                 # épingle + télécharge
importmap:require chart.js --path=./assets/vendor/chart.js   # épingle un fichier local à la place
importmap:outdated                         # ce qui a changé, avec la nouvelle version
importmap:update chart.js                  # ou sans argument : tout
importmap:remove chart.js
importmap:audit                            # avis de sécurité — à mettre dans la CI
```

`importmap:audit` interroge la base d'avis de sécurité de GitHub pour les versions
épinglées. C'est la seule vérification de vulnérabilité dont tu disposes côté
JavaScript, puisqu'il n'y a pas de `npm audit` sur cette stack. Elle a sa place dans le
job CI à côté de `composer audit` ; le skill `symfony-proglab-quality` câble les deux.

## CSS

Le CSS est importé depuis le JavaScript :

```js
// assets/app.js
import './styles/app.css';
```

AssetMapper détecte l'import, ajoute le fichier à la map, et `importmap()` génère un
véritable `<link rel="stylesheet">` pour lui — le CSS n'est pas injecté par du
JavaScript sur le chemin critique, il n'y a donc pas de flash de contenu non stylé.

À l'intérieur du CSS, les références `url()` sont réécrites automatiquement vers des
chemins digérés, et `@import` fait entrer le fichier importé dans la map. Une URL
qu'AssetMapper ne peut pas résoudre est régie par `missing_import_mode` :

```yaml
framework:
    asset_mapper:
        missing_import_mode: strict      # dev : échoue bruyamment
when@prod:
    framework:
        asset_mapper:
            missing_import_mode: warn    # prod : ne pas faire tomber le site pour une faute de frappe
```

Garde `strict` en dev. `warn` partout signifie qu'un import cassé part silencieusement
en production et se découvre grâce à un utilisateur.

## Tailwind sans Node

`symfonycasts/tailwind-bundle` télécharge le binaire CLI autonome de Tailwind dans
`var/` et enregistre un compilateur AssetMapper sur le fichier d'entrée configuré.

```yaml
# config/packages/symfonycasts_tailwind.yaml
symfonycasts_tailwind:
    binary_version: 'v4.1.11'     # épingle-la
    # input_css vaut par défaut %kernel.project_dir%/assets/styles/app.css
```

```bash
symfony console tailwind:init             # une fois
symfony console tailwind:build --watch    # pendant le développement
symfony console tailwind:build --minify   # fait pour toi par asset-map:compile
```

`--watch` doit être en cours d'exécution pour que les modifications CSS apparaissent ;
« mes classes Tailwind ne font rien » est presque toujours un watcher manquant, ou un
nom de classe construit par concaténation de chaînes que le scanner de Tailwind ne peut
pas voir dans le code source du template.

Épingle `binary_version`. Non épinglé, le bundle télécharge le dernier CLI, ce qui veut
dire qu'une version majeure de Tailwind peut arriver sur un runner CI sans cache — avec
un format de configuration différent — un jour où personne n'a touché à un seul fichier
CSS.

Le thème de formulaire se place dans `config/packages/twig.yaml`
(`twig.form_themes: ['tailwind_2_layout.html.twig']`), une fois, jamais par template.

## Contrôleurs Stimulus tiers

`assets/controllers.json` est écrit par les recipes UX et contrôle quels contrôleurs
embarqués sont activés, et comment ils se chargent :

```json
{
    "controllers": {
        "@symfony/ux-live-component": {
            "live": { "enabled": true, "fetch": "eager" }
        }
    }
}
```

`"fetch": "lazy"` diffère le chargement d'un contrôleur jusqu'à ce qu'un élément
l'utilisant apparaisse dans le DOM. Pour un contrôleur utilisé sur une page
d'administration sur quarante, c'est la différence entre le livrer à tout le monde et le
livrer aux personnes qui en ont besoin. Vos propres contrôleurs dans
`assets/controllers/` sont aussi chargeables en lazy, avec un commentaire
`/* stimulusFetch: 'lazy' */` au-dessus de la classe.

## Content Security Policy

L'import map, le chargeur de polyfill et le point d'entrée sont des éléments
`<script>` inline, donc une CSP sans `unsafe-inline` a besoin d'un nonce :

```yaml
framework:
    asset_mapper:
        importmap_script_attributes:
            nonce: '%csp_nonce%'
```

**Ceci entre frontalement en collision avec la recipe `data-turbo-track`, et la
collision est silencieuse.** `importmap_script_attributes` est un unique sac
d'attributs : mets `nonce` *et* `'data-turbo-track': 'reload'` dedans et les deux
atterrissent sur les mêmes éléments `<script>`. Turbo compare les éléments suivis par
leur `outerHTML` — vérifié : il ne compare **que** les éléments marqués
`data-turbo-track="reload"`, pas tout le `<head>` — donc un nonce qui change à chaque
réponse fait que la signature ne correspond jamais et **chaque navigation devient un
rechargement complet de la page**. Drive est installé et inerte, ce qui est le pire des
deux mondes.

Choisis délibérément :

- **Mets un nonce sur les scripts, suis autre chose.** Place
  `data-turbo-track="reload"` sur un `<meta name="app-version" content="{{ release_sha }}">`.
  C'est ce que tu voulais comparer au départ — la release déployée, pas le nonce.
- **Suis les scripts, fais venir le nonce d'ailleurs** — un hash `script-src`, ou un
  nonce appliqué en dehors de `importmap_script_attributes`.

Le rendu de Symfony propage déjà le nonce à l'exécution pour le script de polyfill
plutôt que de l'inliner, donc celui-là est correct dans les deux cas ; les balises
importmap et point d'entrée continuent, elles, de rendre l'attribut à chaque requête. Le
contenu non suivi du `<head>` qui varie à chaque requête ne coûte rien — seuls les
éléments suivis sont comparés.

## Préchargement

Avec `symfony/web-link` installé, `importmap()` définit aussi des en-têtes
`Link: <…>; rel=preload` pour les modules préchargés, afin que le navigateur puisse
commencer à les récupérer depuis les en-têtes de réponse, avant même d'avoir analysé le
corps HTML. Combiné aux balises `<link rel="modulepreload">` et au multiplexage HTTP/2,
c'est le mécanisme qui rend « beaucoup de petits fichiers » compétitif face à un bundle.
Retire l'un des trois et ça cesse de l'être.

## La checklist de production

```bash
php bin/console asset-map:compile
```

- **Lance-la à chaque déploiement.** Si elle est sautée, `framework.asset_mapper.server`
  sert chaque asset via un boot complet du kernel PHP. Le site fonctionne ; il paie
  simplement une requête PHP pour chaque image.
- **Sers `public/assets/` depuis le serveur web**, avec un `Cache-Control` long et
  `immutable`. Sûr parce que le nom de fichier contient le digest du contenu.
- **HTTP/2 ou HTTP/3, non négociable.** En HTTP/1.1, le modèle « beaucoup de petits
  fichiers » perd face à un bundle. Vérifie avec `curl -I --http2` plutôt que de
  supposer.
- **La compression.** Symfony 7.3+ :

  ```yaml
  framework:
      asset_mapper:
          precompress: true
  ```

  `asset-map:compile` écrit alors `.br`, `.zst` et `.gz` à côté de chaque asset. Les
  formats effectivement produits dépendent de ce qui est disponible : brotli et zstd
  nécessitent soit l'extension PHP correspondante, soit le binaire `brotli` / `zstd` sur
  le `PATH`. **Installe-les dans l'image de build**, car l'échec est silencieux dans la
  forme la plus courante : avec `precompress: true` et sans `formats` explicite, un
  compresseur manquant est signalé via `$logger->warning()` et non dans la sortie de la
  commande, donc le build réussit et tu te retrouves avec du gzip, sans rien à
  l'écran. Épingler `formats: ['br', 'zst', 'gz']` explicitement transforme cela en
  `RuntimeException` — ce qui vaut le coup précisément parce que ça fait échouer le
  build plutôt que la page. `assets:compress` (ce nom-là, pas `asset-map:compress`)
  exécute la même compression en tant qu'étape autonome. En 6.4–7.2 cette option
  n'existe pas ; compresse au niveau du serveur web.
- **`APP_ENV=prod` avant de compiler**, pour que la config compilée et les digests
  correspondent à ce que l'application demandera à l'exécution.

## Débogage

| Symptôme | Cause |
|---|---|
| `Unable to find asset "…"` | Le chemin logique est incorrect. `debug:asset-map` les liste tous |
| Un asset renvoie une 404 seulement en production | `asset-map:compile` non lancé, ou `public/assets/` non déployé |
| CSS/JS périmés après un déploiement | `public/assets/` d'une compilation précédente, ou un CDN qui met en cache le chemin *non digéré* |
| `Failed to resolve module specifier` | Un import nu manquant dans `importmap.php` — faites `importmap:require` dessus |
| Tout fonctionne en dev, rien en prod | `missing_import_mode: warn` a avalé un import cassé au moment de la compilation. Lis la sortie de la compilation |
| Une image est servie par PHP | Même étape de compilation manquante ; `asset_mapper.server` répond à la place |
| Un package JS semble vulnérable mais rien n'échoue | `importmap:audit` n'est pas en CI |
