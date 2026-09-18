---
title: 'Refermer la surface de rebranding'
type: 'feature'
created: '2026-09-18'
status: 'done'
route: 'dispatch'
review_loop_iteration: 4
baseline_commit: '5bde3a1244dd1b92e5b832330caf0fcd3ef344f7'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** un dérivé renommé montre deux noms sur sa page d'accueil — le `<title>`
compose `app_name`, le `h1` de `templates/home/index.html.twig` code « Socle ERP » en dur.
Et aucune page ne déclare d'icône : chaque navigateur demande `/favicon.ico` et reçoit un
404. Ce reste a été reporté trois fois (1.4 pour le favicon, 1.5 pour le `h1`, 1.11 pour
l'attribution), puis sorti du découpage de la 1.12 ; `deferred-work.md` en porte
l'historique et le *comment* déjà tranché par Fabrice.

**Approche :** le `h1` passe à `{{ app_name }}` ; une icône vit sous `assets/brand/`,
servie par AssetMapper via `asset()`, ce qui demande d'ajouter `symfony/asset` en
`require` — la fonction Twig `asset()` n'existe pas aujourd'hui (vérifié :
`debug:twig` ne la liste pas, seul `symfony/asset-mapper` est installé). `BaseTemplateTest`
garde les deux au même titre que le `<title>`, et les trois énoncés du contrat de
rebranding (`brand.css`, `README.md`, `docs/DERIVATION.md`) sont réalignés.

## Boundaries & Constraints

**Always :**

- L'icône est remplaçable sans éditer un fichier de `src/Core/` ni de `config/` (NFR-6) :
  un dérivé écrase les fichiers de `assets/brand/`, rien d'autre.
- UX-DR-2 garde **deux** choses rebrandables — les variables `oklch` de `brand.css`, et la
  marque graphique (logo de la sidebar **et** favicon). Le nom du produit reste de la
  configuration, pas une troisième chose. Les trois énoncés disent cela à l'identique.
- Le test d'abord, rouge avant le code (règle 1 du socle).

**Décisions de Fabrice, tranchées le 2026-09-18 :**

- **L'icône par défaut est un carré `rounded-md` plein, sans glyphe**, dans le noir
  `--primary` du socle : cohérent avec le logo de sidebar que posera la 2.3 (DESIGN.md
  l. 403), muet, et surtout remplaçable par simple écrasement du fichier — des initiales
  auraient obligé chaque dérivé à *éditer* le SVG, ce qui casse le critère NFR-6 ci-dessus.
  Le socle ne s'invente pas de glyphe.
- **Le SVG porte un `@media (prefers-color-scheme: dark)` interne** : un carré noir
  disparaît dans un onglet sombre, et le défaut du socle doit être présentable avant
  d'avoir été remplacé.
- **Un repli matriciel accompagne le SVG** (`assets/brand/favicon.png`, second
  `<link rel="icon">`) : Safari ne rend pas les favicons SVG, et laisser Safari retomber
  sur un `/favicon.ico` en 404 laisserait ouvert exactement le bruit que le report de la
  1.4 visait. Le contrat de rebranding énonce donc **deux** fichiers de marque à remplacer
  — la ligne supplémentaire est le prix assumé de refermer la surface partout.

**Renégociation du 2026-09-18, après revue — Fabrice a rouvert ce bloc :**

- **Le `h1` de l'accueil nomme la page, pas le dérivé.** La première rédaction de ce bloc
  demandait `<h1>{{ app_name }}</h1>`, reprise de l'AC de l'epic. Trois lentilles de revue
  ont montré que cela contredit le spine UX — DESIGN.md l. 515 réserve le `h1` au nom de la
  page, EXPERIENCE.md l. 183 donne « Bonjour Marc… » à l'accueil — et que
  `page_focus_controller` place le focus sur ce `h1` après chaque navigation Turbo : un
  lecteur d'écran annoncerait le nom du produit au lieu de la page atteinte (WCAG 2.4.6).
  Le `h1` porte donc `'home.index.title'|trans`, le nom du dérivé restant au `<title>`.
  Le problème d'origine est résolu à l'identique : « Socle ERP » en dur disparaît et la
  page n'affiche plus deux noms. **L'AC correspondante de `epics.md` est amendée en
  conséquence** — c'est la seule divergence que cette story ouvre avec son epic, et elle
  est refermée par cet amendement, pas reportée.
- **L'ordre des deux `<link rel="icon">` se fixe sur une mesure, pas sur une recette.**
  Le SVG livré ne parsait pas (commentaire XML contenant `--`), donc aucune observation
  n'était valide. Le fichier est réparé d'abord, puis la sélection réelle est mesurée dans
  un navigateur — le fichier que le moteur demande — et l'ordre comme l'assertion qui le
  garde citent ce constat. `sizes="32x32"` disparaît du gabarit (il décrit un fichier que
  le dérivé remplace) et le SVG reçoit `sizes="any"`, qui est ce qui rend la sélection
  déterministe plutôt que laissée à l'heuristique.

**Never :**

- Pas de logo de sidebar : troisième pièce de UX-DR-2, elle arrive avec la coque (2.3).
- Aucune cible `make` ni aucun job de CI ajouté ; la porte garde ses six catégories.
- Pas de SVG inliné dans un template : `HardcodedColorTest` scanne `templates/`, et une
  couleur de marque n'est pas un jeton de thème — elle vit dans le fichier de marque.
- Pas de réglage de marque en base ni en administration.
- On ne touche ni le « ≈ 2,05:1 » de `--ring` (report distinct, décision de spine), ni
  `config/services.yaml`, ni `deptrac.yaml` (story 1.15).

## I/O & Edge-Case Matrix

| Scénario | Entrée / État | Comportement attendu | Erreur |
|---|---|---|---|
| Dérivé renommé | `APP_NAME="Menuiserie Dubois"` | `<title>` = « Accueil — Menuiserie Dubois » **et** `h1` = « Accueil » — plus de « Socle ERP » en dur, et un seul nom de produit sur la page | N/A |
| `APP_NAME` vide | `APP_NAME=` dans `.env.local` | le `h1` nomme toujours la page ; aucun titre ne devient vide | N/A |
| Icône remplacée | les deux fichiers de `assets/brand/` écrasés | l'icône change, aucun fichier de `src/Core/` ni `config/` édité | N/A |
| Page sans bloc `title` | gabarit rendu nu | les deux `<link rel="icon">` sont là quand même — ils appartiennent au gabarit, pas à la page | N/A |
| Navigateur sans favicon SVG | Safari | il prend le repli `.png` déclaré ; plus de requête `/favicon.ico` en 404 | N/A |
| Onglet sombre | `prefers-color-scheme: dark` | le carré du socle reste visible — le SVG porte sa propre media query | N/A |

</frozen-after-approval>

## Code Map

- `templates/base.html.twig` — `<head>` l.26-51 (`<title>` composé, blocs `stylesheets` /
  `javascripts`). Son en-tête énumère ce que le gabarit ne porte pas : l'icône s'y ajoute.
- `templates/home/index.html.twig:7` — `<h1>Socle ERP</h1>`, la ligne à remplacer.
- `tests/Core/Template/BaseTemplateTest.php` — modèles à suivre :
  `the_title_is_the_page_name_then_the_derivative_name()` (rend `/`, lit le paramètre
  `app.name`) et `the_derivative_name_comes_from_the_environment()`.
  `the_base_template_carries_no_heading_of_its_own()` reste vraie.
- `tests/Core/Theme/AssetPipelineTest.php:85` — tient déjà `assets/` comme seul chemin
  mappé : test propriétaire de la chaîne d'assets, donc de `symfony/asset`.
- `composer.json` — `symfony/asset-mapper: 7.4.*` en `require`, `symfony/asset` absent ;
  `BundleDependencyTest` ne couvre que les bundles, rien ne garde cette dépendance.
- `assets/styles/brand.css:9-14` — « Deux choses se rebrandent », point 2 = le logo seul.
- `README.md:288-291` — « c'est, avec `brand.css` et le logo, tout ce qu'un rebranding touche ».
- `docs/DERIVATION.md:356-371` (§ Rebranding), `:390` (ligne « Marque »), `:572-580`
  (limite à supprimer).
- `tests/Core/Documentation/DerivationGuideTest.php` — aucune assertion ne porte sur la
  limite supprimée : la retirer est sans coût.
- `config/packages/asset_mapper.yaml` — `paths: [assets/]` : `assets/brand/` est servi sans
  configuration supplémentaire.

## Tasks & Acceptance

**Execution :**

- [x] `tests/Core/Template/BaseTemplateTest.php` -- deux tests écrits **avant** le code : le
      gabarit déclare **deux** `<link rel="icon">` (SVG puis `.png`) dont les `href`
      résolvent vers des assets réels de `assets/brand/`, et le `h1` de l'accueil vaut le
      paramètre `app.name` -- ce test tient la surface de marque au même titre que le
      `<title>`.
- [x] `tests/Core/Theme/AssetPipelineTest.php` -- assertion `symfony/asset` en `require` et
      non `require-dev` -- sous `composer install --no-dev`, toute page tomberait.
- [x] `composer.json` / `composer.lock` -- `composer require symfony/asset` en `7.4.*` --
      la fonction Twig `asset()` n'existe pas sans lui.
- [x] `assets/brand/favicon.svg` -- créer le répertoire de marque et l'icône par défaut :
      carré `rounded-md` plein en `--primary`, sans glyphe, avec son
      `@media (prefers-color-scheme: dark)` interne -- c'est le fichier qu'un dérivé remplace.
- [x] `assets/brand/favicon.png` -- le repli matriciel pour Safari, même carré -- sans lui,
      Safari redemande `/favicon.ico` et reçoit le 404 que cette story ferme.
- [x] `templates/base.html.twig` -- déclarer les deux `<link rel="icon">` dans le `<head>`
      (SVG d'abord, `.png` en repli), avec le commentaire qui dit ce qu'un dérivé remplace --
      toute page du socle passe par ce gabarit.
- [x] `templates/home/index.html.twig` -- `<h1>{{ 'home.index.title'|trans }}</h1>` -- le
      titre visible nomme la page ; le nom du dérivé reste au `<title>` (renégociation du
      2026-09-18). Le test du `h1` suit, et le plancher d'accessibilité rejette un titre
      dont le texte est vide.
- [x] `assets/styles/brand.css` (en-tête) et `README.md` -- nommer le favicon de
      `assets/brand/` dans le point « marque graphique » -- les trois énoncés coïncident.
- [x] `docs/DERIVATION.md` -- § Rebranding : retirer « Ni l'un ni l'autre n'existe encore » ;
      supprimer la limite « La surface de marque n'est pas encore complète » -- le guide ne
      décrit plus un manque comblé.
- [x] `tests/Core/Documentation/DerivationGuideTest.php` -- une assertion qui garde l'accord
      des trois énoncés sur `assets/brand/` -- le socle vérifie ses documents.

**Acceptance Criteria :**

- Given un dérivé qui a renseigné `APP_NAME`, when il ouvre la page d'accueil, then le `h1`
  nomme la page, le `<title>` nomme la page puis le dérivé, « Socle ERP » n'apparaît plus
  en dur et la page n'affiche plus deux noms différents.
- Given `APP_NAME` laissé vide, when la page d'accueil est rendue, then aucun titre — ni le
  `h1`, ni le `<title>` — ne devient vide.
- Given le gabarit de base, when un navigateur charge n'importe quelle page, then il y
  trouve une icône déclarée, servie par AssetMapper depuis `assets/brand/`.
- Given un navigateur qui ne rend pas les favicons SVG, when il charge une page, then il
  trouve le repli `.png` déclaré et ne redemande pas `/favicon.ico`.
- Given un dérivé qui remplace les fichiers d'icône, when il recharge l'application, then
  l'icône change sans qu'aucun fichier de `src/Core/` ni de `config/` ait été édité.
- Given `composer.json`, when je cherche ce qui rend `asset()` disponible, then
  `symfony/asset` est en `require` et non en `require-dev`.
- Given le contrat UX-DR-2, when je le lis dans `brand.css`, `README.md` et
  `docs/DERIVATION.md`, then les trois disent la même chose et la limite « la surface de
  marque n'est pas encore complète » a disparu du guide.
- Given la porte de qualité, when `make qa` tourne, then les six catégories passent sans
  qu'aucune cible ni aucun job n'ait été ajouté.

## Implementation Notes

**Fichiers touchés.**

- `tests/Core/Template/BaseTemplateTest.php` — cinq tests ajoutés, un par ligne de la
  matrice : le dérivé renommé (titre **et** `h1`), les deux `<link rel="icon">` résolus
  dans la chaîne d'assets, les icônes portées par le gabarit rendu nu, la media query
  sombre du SVG, et l'absence de toute mention des fichiers d'icône dans `src/Core/` ou
  `config/`.
- `tests/Core/Theme/AssetPipelineTest.php` — `the_asset_component_is_a_production_dependency()`.
- `tests/Core/Documentation/DerivationGuideTest.php` — cinquième famille d'ancres (e) :
  les trois énoncés du contrat nomment `` `assets/brand/` ``, en `#[DataProvider]` pour
  que l'échec nomme le fichier fautif.
- `composer.json` / `composer.lock` — `symfony/asset` v7.4.8 en `require`.
- `assets/brand/favicon.svg`, `assets/brand/favicon.png` — le carré par défaut et son
  repli (32×32).
- `templates/base.html.twig` — les deux `<link rel="icon">` dans le `<head>`, hors bloc.
- `templates/home/index.html.twig` — `<h1>{{ 'home.index.title'|trans }}</h1>` (corrigé en
  boucle 1 : la première rédaction posait `{{ app_name }}`).
- `assets/styles/brand.css`, `README.md`, `docs/DERIVATION.md` — les trois énoncés
  réalignés ; la limite « la surface de marque n'est pas encore complète » supprimée.

**Décisions que le spec ne tranchait pas.**

- **Le test du `h1` renomme le dérivé le temps du rendu.** Le défaut d'`APP_NAME` est
  « Socle ERP », exactement le texte que le `h1` codait en dur : comparer la page au
  paramètre `app.name` tel quel restait **vert sur le code cassé** — constaté, pas
  supposé. `renderAs('Menuiserie Dubois')` pose `APP_NAME` dans `$_ENV`/`$_SERVER` avant
  d'amorcer le client (le conteneur relit `%env(APP_NAME)%` à l'exécution, rien n'est
  figé à la compilation) et restaure dans un `finally`. C'est aussi la valeur exacte de
  la première ligne de la matrice.
- **Format du PNG : 32×32, dessiné à 8× puis rééchantillonné** par un script GD jetable
  (hors dépôt, `scratchpad`), sinon les coins `rounded-md` sortent en escalier. Rayon de
  8 px sur 32, soit le `--radius-md` du thème sur le carré de 32 px du logo de la 2.3.
- **Les couleurs du SVG sont écrites en hexadécimal** : `#171717` et `#e5e5e5`, les
  conversions exactes des deux `--primary` de `theme.css` (`oklch(0.205 0 0)` et
  `oklch(0.922 0 0)`). Une image n'hérite d'aucune feuille de style, et un favicon est
  rendu hors du document — il n'y a pas d'autre moyen. `HardcodedColorTest` ne scanne que
  `templates/`, `src/` et `assets/controllers/`, donc rien n'est contourné : le fichier de
  marque est justement l'endroit où une couleur de marque a le droit de vivre.
- **`type` sur les deux `<link>`, `sizes` sur le seul SVG** : sans `type="image/svg+xml"`,
  un navigateur doit télécharger le SVG pour savoir qu'il ne sait pas le rendre. La
  première rédaction posait aussi `sizes="32x32"` sur le repli ; la boucle 1 l'a retiré, et
  `BaseTemplateTest` exige désormais son absence.

**Surprises.**

- **L'ordre des deux `<link>` n'est pas neutre, et il n'a pas été vérifié en navigateur.**
  Les recettes publiques déclarent le repli matriciel **en premier** et le SVG ensuite,
  parce que plusieurs moteurs retiennent la *dernière* icône déclarée qu'ils savent rendre.
  Le bloc gelé fixe l'inverse (« SVG d'abord, `.png` en repli »), donc c'est ce qui est
  livré. Le risque, s'il existe, est borné : le carré est identique dans les deux fichiers,
  seule l'adaptation à l'onglet sombre se perdrait sur un moteur qui prendrait le `.png`.
  À trancher sur une vérification réelle dans Chrome et Firefox, pas sur une intuition —
  et l'ordre appartient au bloc gelé, donc à Fabrice.
- **`bin/console --env=prod` servait un conteneur périmé.** Après `composer require`, le
  script `cache:clear` de Composer ne nettoie que l'environnement courant : `debug:twig
  --env=prod` ne listait pas `asset()` tant que `cache:clear --env=prod` n'avait pas
  tourné. Faux négatif d'outillage local, aucune trace dans le dépôt — mais de quoi faire
  croire à une dépendance mal câblée.
- **Deux tests étaient verts dès leur écriture** (la media query du SVG une fois le
  fichier créé, et l'absence de mention hors gabarit, verte par construction). Vérifiés
  par mutation : media query remplacée par `prefers-reduced-motion` → rouge ; `brand/favicon`
  ajouté en commentaire dans `config/packages/twig.yaml` → rouge, avec le fichier nommé.
  Les deux mutations annulées.
- `symfony/asset` n'apporte **aucune recette Flex** : ni fichier de configuration, ni clé
  `framework.assets`. Le composant s'active seul (`canBeDisabled` dès que la classe
  `Package` existe) et `asset_mapper.asset_package` décore le paquet par défaut — c'est ce
  qui fait qu'`asset('brand/favicon.svg')` rend le chemin digéré.

### Boucle de revue 1 — les douze patchs appliqués

**A — le SVG ne parsait pas.** Reproduit avant de corriger : `DOMDocument::loadXML()`
refusait le fichier, « Comment must not contain '--' », lignes 9, 10 et 18. Les noms de
variables CSS sont sortis du commentaire XML et vivent maintenant dans les commentaires
**CSS** de la feuille interne, qui n'ont pas cette contrainte ; le commentaire XML nomme
le piège pour que personne ne le rouvre. Le test ne cherche plus une sous-chaîne : il
parse le fichier et échoue en recopiant les erreurs libxml. Vérifié aussi sur le fichier
**servi** (`curl` sur le chemin digéré) : 200, `image/svg+xml`, parse propre.

**B — le `h1` nomme la page.** `{{ 'home.index.title'|trans }}`, même clé que le bloc
`title`. Le test renomme toujours le dérivé (`renderAs('Menuiserie Dubois')`) et exige
trois choses d'un coup : `h1` = « Accueil », le nom du produit **absent** du `h1`, et
`<title>` = « Accueil — Menuiserie Dubois ».

**C — `APP_NAME=` vide.** Deux tests, pas un : `an_empty_derivative_name_never_empties_the_heading()`
rend la page avec la variable vidée, et la règle 6 du plancher d'accessibilité refuse
désormais tout titre au texte vide, sur toute page. Mutation vérifiée — `<h1></h1>` sur
l'accueil fait échouer la règle 6 en nommant la page.

**D, E — `sizes` et le repli.** `sizes="32x32"` a disparu ; le SVG reçoit `sizes="any"`,
le repli n'en porte aucun. Le test lit l'en-tête réel du fichier (`getimagesize`) :
`IMAGETYPE_PNG`, carré, au moins 16 px. Un SVG renommé `.png` échoue maintenant.

**F — la media query.** Le test parse la feuille interne, isole le bloc
`@media (prefers-color-scheme: dark)`, extrait la règle `fill` de chaque côté, et exige :
même sélecteur des deux côtés, sélecteur qui désigne **un nœud réel** du document, et
deux valeurs distinctes. Les commentaires CSS sont retirés avant l'extraction.

**G — les couleurs contre le thème.** `the_default_icon_carries_the_two_primary_colours_of_the_theme()`
lit `--primary` dans les deux blocs de `theme.css` via `ThemeSheet` et le confronte à une
table de conversion oklch→sRGB explicite. Une valeur absente de la table est un échec qui
dit quoi faire : convertir, inscrire le couple, repeindre l'icône. Recalculer oklch en
sRGB dans un test coûtait plus que la garde ne vaut.

**H — le garde NFR-6 prouve un comportement.** Le chemin public attendu est **recalculé
depuis les octets du fichier** (`hash_file('xxh128')`, base64 URL-safe tronqué à sept),
sans passer par AssetMapper, et comparé au `href` rendu. Écraser un fichier change donc
l'URL, et un `href` figé en dur échoue — mutation vérifiée. La moitié « aucune édition de
`src/Core/` ni `config/` » reste, en seconde assertion du même test. La forme du condensat
appartient à AssetMapper : si elle change, le test rougit et se met à jour ; la propriété
gardée, elle, ne change pas.

**I — les trois énoncés.** Le `#[DataProvider]` croise les trois documents avec trois
ancres (`assets/brand/`, `favicon.svg`, `favicon.png`), et un test distinct garde
l'**absence** de « La surface de marque n'est pas encore complète » dans le guide. Les
deux mutations (ancre retirée de `README.md`, phrase réintroduite dans le guide) échouent.

**J, K — ce que le guide promettait de faux.** « Écraser les deux fichiers suffit » est
devenu « aucun fichier de code n'est à modifier », avec deux points de vigilance nommés :
remplacer les deux fichiers **ensemble**, et rejouer `asset-map:compile` — le chemin
public portant un condensat du contenu, l'ancienne icône reste servie sinon. La même
correction est faite dans le commentaire du gabarit, dans `README.md` et dans
`brand.css`.

**L — `deferred-work.md`.** Entrée de clôture ajoutée en fin de fichier, sur le modèle de
l'entrée `:46-47` : ce qui est livré, la divergence assumée sur le `h1` avec l'AC
d'origine, et ce qui reste ouvert (l'ordre des `<link>`, le logo de sidebar).

**Arbitrages.**

- **La suite « Accessibility » ne tourne pas avec `--filter`.** `phpunit.dist.xml` l'exclut
  de la suite par défaut : `--filter AccessibilityFloorTest` affiche « OK » sans avoir rien
  exécuté. C'est ce qui a failli faire passer le patch C pour vérifié. Les deux suites sont
  donc lancées séparément — `php vendor/bin/phpunit` puis
  `php vendor/bin/phpunit --testsuite Accessibility`.
- **MySQL était arrêté** en cours de session ; l'instance 8.4.3 de Laragon a été relancée
  avec **son propre** `my.ini` (`datadir: D:/laragon/data/mysql-8.4`), jamais le datadir
  `mysql-8` historique.
- **L'ordre des deux `<link>` n'a pas été touché**, comme demandé : le SVG parse
  maintenant, la mesure en navigateur peut être faite.

### Boucle de revue 2 — quatre patchs, et un constat que je n'ai pas tranché

**M — le repli est hors garde de couleur, écart assumé.** Pas de lecture de pixel : la
porte tourne sur un PHP sans extension d'image (`ci.yml` installe
`ctype, iconv, intl, pdo_mysql`), et `ext-gd` pour vérifier un carré l'imposerait à chaque
serveur client ; un décodeur PNG écrit à la main (chunks + `zlib_decode` + dé-filtrage)
coûterait une cinquantaine de lignes de décodage d'image dans une suite de tests. Le test
est donc renommé `the_default_svg_icon_carries_the_two_primary_colours_of_the_theme()`, son
docblock dit l'écart en toutes lettres, et ses **deux** messages d'échec nomment le PNG et
demandent de le régénérer — le seul instant où l'oubli peut se produire est celui où ce
test devient rouge. Le commentaire de `favicon.svg` le redit. Entrée de report ajoutée à
`deferred-work.md`, avec le pixel sondé, les deux coûts et le déclencheur (story 2.3, qui
ajoutera une troisième pièce de marque).

**N — la forme du titre, des deux côtés.** Le gabarit compose maintenant
`[page_title, app_name]|filter(…)|join(' — ')` : le séparateur n'existe que s'il a deux
moitiés à séparer. Le test exige `<title>` = « Accueil » exactement sur un `APP_NAME`
vidé, et la règle miroir du plancher refuse désormais un séparateur en **queue** comme en
tête. Mutation vérifiée : un `<title>` en « … — » fait échouer la règle sur huit pages.
`config/services.yaml` n'est pas touché (bloc gelé) ; le pied des emails, préexistant aux
stories 1.8/1.9, part en report avec sa preuve et les trois options qui le trancheraient.

**O — « et nulle part ailleurs » était faux.** `app_name` alimente aussi
`templates/emails/base.html.twig:50` et `:61`. Les deux phrases de la boucle 1 sont
réécrites dans `README.md` et `docs/DERIVATION.md` — le `<title>` **et** les emails —, et
la même exclusivité a été retirée du commentaire de `templates/home/index.html.twig`, qui
la portait aussi. La propriété visée tient sans elle : le `h1` ne porte pas le nom du
produit.

**P — le spec décrivait le code d'avant.** `review_loop_iteration` à `2`, la tâche du `h1`
cochée, et deux énoncés des « Fichiers touchés » / « Décisions » corrigés en place plutôt
qu'ajoutés en contradiction — ils décrivaient `{{ app_name }}` et un `sizes` sur les deux
`<link>`, tous deux morts depuis la boucle 1. La correction est signalée dans les deux
énoncés (« corrigé en boucle 1 »). L'entrée de `deferred-work.md` justifie désormais le
report de l'ordre des `<link>` au passé : le blocage est levé, seule la mesure reste.

**Un constat que je signale sans le corriger.** Le garde de couleur du SVG — patch G de la
boucle 1, donc de mon fait — est écrit comme une règle sur un fichier que le contrat de
rebranding **invite un dérivé à remplacer**. Chez un dérivé qui pose son propre logo,
`theme.css` est inchangé (il n'est jamais édité) et le test exigera que son logo porte le
`--primary` du socle : rouge sur un dérivé parfaitement conforme. Étendre la même garde au
PNG aurait doublé le problème, c'est l'autre raison pour laquelle je ne l'ai pas fait. Le
corriger est une décision de conception, pas un correctif de revue : il faut dire à quoi
s'applique la garde (« tant que l'icône est celle du socle » ? « tant que `brand.css` ne
redéfinit pas `--primary` » ?), et toute réponse conditionnelle rend la garde muette chez
le dérivé. Aucun dérivé n'existe aujourd'hui, donc rien n'échoue ; à trancher avant le
premier.

### Boucle de revue 3 — la garde bornée, et ce qui n'a pas pu être mesuré

**1 — la garde de couleur connaît son périmètre, et elle couvre enfin le PNG.**
*(Ce paragraphe décrivait un montage par condensat du fichier sous test ; la boucle 4 l'a
remplacé par une borne tirée du dépôt. Corrigé ici pour que cette section ne décrive pas du
code mort — le détail du changement est dans la section « Boucle de revue 4 ».)*

La garde ne s'applique qu'au carré par défaut du socle : sans borne, elle exigerait d'un
dérivé que **son** logo porte le `--primary` du socle, et ferait rougir la porte d'un
dérivé parfaitement conforme au contrat de rebranding.

**L'extension au PNG est devenue bon marché, donc je l'ai prise.** `FALLBACK_DIGEST` épingle
le contenu du repli ; `FALLBACK_PAINTED_FOR` dit pour quelle valeur de `--primary` ce
contenu a été peint. Recolorer `--primary` casse le couple et la porte réclame la
régénération — **sans lire un seul pixel**, donc sans `ext-gd` sur les serveurs clients. Ce
que le couple ne prouve pas est écrit dans le docblock et dans `deferred-work.md` : que les
pixels *étaient* de cette couleur à l'épinglage reste une affirmation humaine (932 pixels à
`0x171717`), et recopier la nouvelle valeur dans la constante reste un chemin vers le vert
plus court que régénérer le fichier. L'oubli n'est donc pas rendu impossible ; il est rendu
**visible**, au moment exact où il se produirait.

Mutation vérifiée : `--primary` recoloré dans `theme.css` → les **deux** tests échouent,
chacun avec son message (« La couleur ne correspond plus au thème »).

**2 — l'affichage effectif des deux icônes n'a pas été mesuré, et la mesure est
abandonnée.** L'ordre reste celui du bloc gelé : SVG d'abord, repli ensuite.

*Ce qui a été tenté, et pourquoi cela n'a pas tranché* — c'est le fait à garder, pas un
« non vérifié » :

- le navigateur intégré à l'outillage **ne demande jamais de favicon** : aucune observation
  possible de ce côté ;
- sur Chrome, en rechargement forcé, le journal d'accès de Laragon montre **les deux**
  fichiers téléchargés. Un `GET` ne prouve pas un affichage, et le journal ne porte pas de
  `User-Agent` permettant de départager les moteurs.

*Le seul fait établi*, sur six chargements observés : **le SVG est demandé à chaque fois,
il n'est jamais écarté.**

Les assertions sont ramenées à ce qui est su — deux icônes déclarées, le SVG d'abord, le
SVG avec `sizes="any"`, le repli sans `sizes` — et plus aucun message ne prétend traduire
une préférence de moteur. Le commentaire du gabarit porte la même mise en garde, avec
l'instruction de ne pas réécrire ces deux lignes sur une intuition. Ce qu'il reste à faire
un jour est dans `deferred-work.md` : une **observation visuelle**, dont un onglet sombre,
puisque c'est là que le choix entre les deux fichiers se voit.

### Boucle de revue 4 — la borne vient du dépôt, et le tri est fait

La revue a démonté le montage de la boucle 3 et elle avait raison sur les quatre points.
Ils convergeaient vers une seule correction, et c'est celle qui est appliquée.

**La borne n'est plus le fichier sous test, c'est `_bmad-output/`.** Trois défauts
tombaient ensemble avec le condensat comme borne : il se **retirait en vert** (ni
`failOnSkipped` ni `displayDetailsOnSkippedTests` dans `phpunit.dist.xml` — vérifié) alors
que mon docblock affirmait qu'il « faisait échouer » ; il figeait le **fichier entier**,
donc corriger une virgule dans l'avertissement que cette story venait d'écrire *dans*
`favicon.svg` désarmait la garde sans qu'un `fill` ait bougé ; et il ne pouvait pas
distinguer « un dérivé a posé sa marque » de « quelqu'un a retouché le socle ». Le signal
de dépôt n'a aucun des trois : dans le socle il est présent, donc un défaut **échoue** ;
chez un dérivé il est absent, donc la description du carré par défaut se tait. C'est le
patron de `DerivationGuideTest`, qui saute ses statuts de story pour exactement la même
raison. `skipUnlessTheDefaultIconsAreUnderTest()` remplace `skipUnlessStillTheDefaultIcon()`,
et `DEFAULT_ICON_DIGESTS` disparaît — le condensat du SVG ne gardait rien que la lecture
des `fill` ne garde déjà.

**Le tri, qui était le cœur du sujet.** Je n'avais borné que deux tests sur quatre, et les
deux autres imposaient au fichier remplacé une feuille `<style>` interne, un bloc `@media`,
une règle `fill` de chaque côté et un PNG carré. La revue a sondé un logo de dérivé
plausible — couleurs en attributs, pas de `<style>` — et `iconFills()` rendait `0` : le
dérivé conforme rougissait toujours, depuis un autre endroit.

| Ce qui décrit le **défaut du socle** — borné | Ce que le socle garantit à **toute** icône — jamais borné |
|---|---|
| media query sombre et règle `fill` unique de chaque côté | SVG bien formé (un logo qui ne parse pas n'affiche rien, chez le dérivé aussi) |
| couleurs du SVG = les deux `--primary` du thème | en-tête PNG réel et dimension ≥ 16 px |
| forme carrée du repli, et son couple condensat / couleur épinglée | `type` et `sizes` des deux `<link>` — le gabarit, qu'un dérivé n'édite pas |
| | les deux `href` qui résolvent, et qui suivent le contenu des fichiers |

L'assertion « carré » a donc quitté `the_two_icons_are_declared_as_what_they_really_are()`
pour le test borné du repli ; le test du XML bien formé est renommé
`the_svg_icon_is_well_formed_xml()`, parce qu'il ne parle plus du seul défaut du socle.

**`FALLBACK_PAINTED_FOR` ne sur-affirme plus.** Le couple compare une constante du test à
`theme.css` ; le PNG n'y entre que par un condensat écrit à la main. Quand `--primary`
change, le chemin le plus court vers le vert est de recopier la nouvelle valeur — la garde
resterait armée en affirmant une couleur que le fichier ne porte pas. Le docblock et
l'entrée de `deferred-work.md` disent désormais ce que le couple prouve — « le repli n'a pas
bougé depuis un épinglage humain » — et l'entrée repasse de CLOS à **PARTIELLEMENT CLOS**,
avec ce qui reste ouvert et son déclencheur.

**Trois mutations vérifiées.** `--primary` recoloré → les deux gardes **échouent** (elles
ne se retirent pas). Un commentaire retouché dans `favicon.svg` → condensat du fichier
changé, suite inchangée, 22 tests verts. Logo de dérivé sans `<style>` et `_bmad-output/`
masqué → **3 tests se taisent** avec leur message, les 19 autres passent, dont le parse XML
du logo et l'en-tête du PNG.

## Spec Change Log

- **2026-09-18, après la boucle de revue 1 — renegociation du bloc gelé par Fabrice.**
  Le `h1` de l'accueil nomme désormais la page (`'home.index.title'|trans`) et non le
  dérivé. Cause : trois lentilles de revue ont montré que `<h1>{{ app_name }}</h1>`
  contredit DESIGN.md l. 515 et l'ordre de focus Turbo (EXPERIENCE.md l. 560-565), et qu'il
  rendait un `<h1></h1>` sur `APP_NAME=` vide. L'AC de l'epic a été amendée dans
  `epics.md` pour ne pas laisser deux énoncés divergents.
- **2026-09-18, même boucle — l'ordre des deux `<link rel="icon">` passe de la recette à
  la mesure.** Cause : le SVG livré ne parsait pas, donc l'ordre gelé reposait sur une
  hypothèse qu'aucune observation ne pouvait valider. `sizes="32x32"` est retiré du
  gabarit, `sizes="any"` ajouté au SVG.

## Review Triage Log

Boucle 1 — quatre lentilles (`bugs`, `edge-cases`, `verification-gap`, `standards`) et la
porte. Porte **verte** avant triage : 511 tests, PHPStan max, deptrac 0 violation, style
0/106, audit propre, a11y 22+27. Seize constats, tous consignés.

| # | Constat | Lentille(s) | Verdict | Preuve |
|---|---|---|---|---|
| 1 | `assets/brand/favicon.svg` n'est pas du XML bien formé : le commentaire d'en-tête contient `--` trois fois | bugs | **patch** | vérifié à la main : libxml `level=3 code=80` lignes 9, 10, 18 « Comment must not contain '--' » ; expat « not well-formed, line 9 ». Aucun navigateur ne rend cette icône, donc la media query sombre ne s'applique nulle part |
| 2 | Le `h1` de l'accueil nomme le produit, pas la page ; le focus Turbo l'annonce | bugs, edge-cases, standards | **intent_gap — tranché par Fabrice le 2026-09-18** | DESIGN.md:515, EXPERIENCE.md:183 et :560-565 contre l'AC de l'epic. Décision : le `h1` nomme la page, l'AC de l'epic est amendée |
| 3 | `APP_NAME=` vide rend `<h1></h1>` — état impossible avant ce diff | edge-cases | **patch** | `config/services.yaml:17` déclare `app.name: '%env(APP_NAME)%'` sans processeur `default:`, vérifié ; `AccessibilityFloorTest` règle 6 ne lit que les niveaux, jamais le texte. Le verdict 2 supprime la cause pour le `h1` ; le plancher gagne l'assertion manquante |
| 4 | Le repli PNG est monochrome et n'a pas de variante sombre ; deux critères poussent le moteur vers lui | bugs, edge-cases, verification-gap | **intent_gap — tranché par Fabrice le 2026-09-18** | PNG sondé : 32×32, une seule couleur `#171717`. Les deux mécanismes demandés (media query interne, repli matriciel) s'excluent. Décision : réparer le SVG, **mesurer** la sélection réelle en navigateur, fixer l'ordre sur le constat |
| 5 | `sizes="32x32"` figé dans le gabarit sur un fichier que le dérivé remplace | bugs, edge-cases, standards | **patch** | `templates/base.html.twig:66` vérifié ; `getimagesize` donne 32×32 aujourd'hui mais aucun test ne relie l'attribut au fichier. Un dérivé en 180×180 devrait éditer le gabarit — hors des « deux fichiers, et rien d'autre » |
| 6 | « Écraser les deux fichiers suffit » est faux en production sans `asset-map:compile` | bugs, standards | **patch** | `asset()` résout par le manifeste compilé (`AssetMapper.php:23-41`) ; `grep asset-map docs/DERIVATION.md` ne renvoie rien, l'étape n'est écrite que dans `README.md:268-269` |
| 7 | Quatre entrées de `deferred-work.md` décrivent encore l'état que la story referme | standards | **patch** | fichier append-only (`:3`) ; précédents de clôture par ajout `:46-47`. Le diff ne le touche pas, alors qu'il supprime la limite correspondante du guide |
| 8 | Un remplacement partiel (un seul des deux fichiers) donne deux marques selon le navigateur, sans bruit | edge-cases | **patch** | les deux `<link>` sont indépendants ; `MapperAwareAssetPackage::getUrl()` retombe silencieusement. Le guide doit rendre le geste partiel visible |
| 9 | Le garde NFR-6 était déjà vert avant la story : il constate une absence, pas un comportement | verification-gap | **patch** | `git grep brand/favicon` sur la baseline et sur HEAD : rien dans `src/Core` ni `config` dans les deux cas. Le test passe à l'identique sur l'arbre de base |
| 10 | Le repli n'est garanti que par l'extension de son nom | verification-gap | **patch** | `BaseTemplateTest:286` n'assert que `assertStringEndsWith('.png')` ; `publishedBrandAssets()` n'ouvre jamais le fichier. Un SVG renommé `.png` passerait |
| 11 | Le test de la media query sombre ne cible pas l'élément dessiné et tolère des couleurs inversées | verification-gap | **patch** | `str_contains` + comptage d'hexadécimaux sur le fichier entier, commentaire compris. `circle { }` ou deux `fill` permutés resteraient verts |
| 12 | Les hexadécimaux du SVG dupliquent `--primary` dans un angle mort du scan | verification-gap, standards | **patch** | `HardcodedColorTest:104-120` scanne `templates`, `src`, `assets/controllers` — jamais `assets/brand/`. Conversions justes aujourd'hui, garde absente |
| 13 | `DerivationGuideTest` réduit l'accord des trois énoncés à une sous-chaîne ; la limite retirée et `favicon.png` ne sont pas ancrés | verification-gap | **patch** | une seule `assertStringContainsString` par fichier (`:378-410`) ; aucun `assertStringNotContainsString` dans le fichier |
| 14 | L'assertion d'ordre des `<link>` fige une hypothèse que le spec dit non vérifiée | verification-gap | **couvert par le verdict 4** | même racine : l'ordre devient un constat mesuré, et l'assertion citera la mesure |
| 15 | `deptrac` rapporte 213 tokens « uncovered » et 31 chevauchements de couches | porte | **defer** | préexistant et structurel, `--report-uncovered` n'échoue pas ; la story n'ajoute aucune classe sous `src/`. Appartient à la 1.15, qui possède `deptrac.yaml` |
| 16 | Faux négatif d'outillage : `debug:twig --env=prod` ne listait pas `asset()` avant `cache:clear` | story-dev | **rejeté** | cache périmé en local, rien dans le dépôt ; `lint:container --env=prod` passe. Aucun mauvais résultat ne se produit |

Boucle 2 — lentille `bugs` rejouée sur le diff corrigé, porte **verte** (522 tests, 2847
assertions). Cinq constats, tous nés des patchs de la boucle 1 — donc de vrais effets de
bord de nos propres corrections.

| # | Constat | Verdict | Preuve |
|---|---|---|---|
| 17 | Le patch G garde la couleur du SVG mais laisse `favicon.png`, qui duplique la même valeur, hors garde | **patch** | 932 pixels à `0x171717` sondés dans le repli. Écart écrit dans le docblock et dans les deux messages d'échec, plutôt que comblé : `ci.yml` n'installe aucune extension d'image |
| 18 | Le test de `APP_NAME=` vide n'exigeait que « non vide » : « Accueil — » passait | **patch** | le `<title>` est désormais composé par jointure, le séparateur n'existe que s'il a deux moitiés ; la règle miroir du plancher refuse le séparateur en queue comme en tête |
| 19 | `app_name` vide traverse aussi le pied des emails | **defer** | `templates/emails/base.html.twig:50` et `:61` datent des stories 1.8/1.9 : préexistant, non causé par cette story. Report écrit avec ses trois options |
| 20 | « le nom du dérivé va dans le `<title>`, et nulle part ailleurs » — faux, et le guide se contredisait dix-huit lignes plus bas | **patch** | phrase ajoutée par la boucle 1 dans `README.md` et `docs/DERIVATION.md` ; `app_name` alimente le gabarit d'email, ce que `docs/DERIVATION.md:406` écrivait déjà |
| 21 | Les deux fichiers de marque étaient dans l'index en `intent-to-add` : un commit ordinaire les aurait laissés dehors | **patch — hors périmètre de l'implémentation** | `git ls-files -s assets/brand/` donnait le blob vide `e69de29…` pour les deux. Causé par le `git add -N` qui produit le diff de revue, pas par le code. Index nettoyé |
| 22 | Le spec décrivait encore le code d'avant la boucle 1 sur quatre énoncés, et `review_loop_iteration` était resté à `0` | **patch** | `:195`, `:217`, la tâche du `h1`, et le champ de boucle. Réalignés en place |

Boucle 3 — un constat remonté par l'implémentation sur son propre patch, et la clôture de
la mesure.

| # | Constat | Verdict | Preuve |
|---|---|---|---|
| 23 | Le garde de couleur du patch G rougirait chez le premier dérivé conforme : il exige le `--primary` du socle sur un fichier que le contrat invite à remplacer | **intent_gap — tranché par Fabrice le 2026-09-18** | un test du socle qui punit un dérivé conforme casse NFR-6. Décision : la garde est conditionnée à « l'icône est encore celle du socle », mesuré par condensat, et se tait ailleurs en disant pourquoi |
| 24 | L'ordre des deux `<link>` ne peut pas être mesuré par les moyens disponibles | **intent_gap — tranché par Fabrice le 2026-09-18 : ordre assumé** | le navigateur intégré ne demande aucun favicon (13 requêtes, aucune sur `brand/`) ; sur Chrome, le log d'accès montre les **deux** fichiers téléchargés en rechargement forcé, et le format commun n'a pas de `User-Agent`. Un `GET` ne prouve pas un affichage. Seul fait établi, et le seul écrit : le SVG est demandé aux six chargements observés, jamais écarté. L'assertion est ramenée à ce constat ; la mesure d'affichage reste ouverte et demande une observation visuelle sur onglet sombre |

Boucle 4 — lentille `verification-gap` sur le montage de la boucle 3. Quatre constats, une
seule correction : **la borne vient d'un signal du dépôt, pas des octets du fichier sous
test.**

| # | Constat | Verdict | Preuve |
|---|---|---|---|
| 25 | La garde ne faisait pas échouer : `markTestSkipped()` laisse la suite verte et n'affiche aucun message, alors que le docblock et `deferred-work.md` affirmaient l'inverse | **patch** | `phpunit.dist.xml` n'a ni `failOnSkipped` ni `displayDetailsOnSkippedTests` ; sonde exécutée : `EXIT=0`, message non restitué |
| 26 | Le condensat figeait le fichier entier, commentaires compris : corriger une virgule dans l'avertissement désarmait la garde | **patch** | le condensat couvrait les ~1 400 octets de commentaires de `favicon.svg`. Condensat du SVG supprimé : la lecture des `fill` garde déjà ce qu'il prétendait garder |
| 27 | **La décision 23 n'était pas tenue** : deux des quatre tests lisant l'icône n'étaient pas bornés et imposaient au fichier remplacé une feuille `<style>`, un bloc `@media`, une règle `fill` unique et un PNG carré | **patch** | sonde sur un logo de dérivé plausible (`fill` en attributs, sans `<style>`) : `iconFills()` rend `0`, porte rouge sur une installation conforme. Les propriétés sont désormais triées — le défaut du socle est borné, ce qui est dû à toute icône ne l'est jamais |
| 28 | `FALLBACK_PAINTED_FOR` sur-affirmait : le couple se remet au vert en éditant une constante, sans régénérer le PNG | **patch documentaire** | le test compare une constante à `theme.css` ; le PNG n'entre que par un condensat écrit à la main. L'entrée passe de `CLOS` à `PARTIELLEMENT CLOS` et dit ce que le couple prouve — le repli n'a pas bougé depuis un épinglage humain — et ce qu'il ne prouve pas |

**Porte de clôture : verte.** 523 tests / 2850 assertions, a11y 22 + 27 Node, PHPStan max, deptrac 0 violation, style 0/106, audit propre. Aucun test `skipped` ni `incomplete` dans les deux suites, vérifié avec affichage forcé ; les trois tests bornés exécutent bien leurs 23 assertions. Vert confirmé depuis `var/cache/test/` physiquement supprimé.

**Deux observations laissées à Fabrice, non traitées ici.** `phpunit.dist.xml` n'a ni
`failOnSkipped` ni `failOnIncomplete` : aujourd'hui aucun test ne se retire, mais c'est la
relecture qui tient cette promesse, pas la porte — la resserrer serait un durcissement de
la porte, hors périmètre de cette story. Et l'ordre des deux `<link>` reste à mesurer par
observation visuelle sur onglet sombre (report dédié dans `deferred-work.md`,
déclencheur story 2.3).

## Design Notes

Skills à charger à l'implémentation : symfony-proglab-standards, symfony-proglab-testing,
symfony-proglab-frontend, symfony-proglab-ui, symfony-proglab-accessibility,
symfony-proglab-quality.

**Pourquoi `asset()` et pas `public/favicon.ico`.** Un fichier dans `public/` éviterait la
dépendance, mais sort de la chaîne d'assets (pas de digest) et `public/` n'est pas le
répertoire de marque que le guide annonce. Le choix `assets/brand/` + `asset()` est celui de
Fabrice (`deferred-work.md`) et son prix est accepté par le contexte d'epic : « le gabarit
appelant `asset()` en production, le composant d'asset est une dépendance de production ».

**Ce que le test doit prouver pour l'icône.** Pas la présence de la balise — un `href` mort
la passerait — mais que le chemin rendu correspond à un fichier réel sous `assets/brand/`.

**Un seul `h1`.** Le gabarit n'a pas le droit d'en porter : le nouveau test lit le `h1` de
la page d'accueil rendue, pas la source du gabarit.

## Verification

**Commands :**

- `php vendor/bin/phpunit --filter BaseTemplateTest` -- attendu : rouge avant le code sur
  les deux nouveaux tests, vert après.
- `php vendor/bin/phpunit --filter 'AssetPipelineTest|DerivationGuideTest|HomeControllerTest'`
  -- attendu : vert, aucune régression sur la chaîne d'assets ni sur le guide.
- `php bin/console debug:asset-map | grep brand` -- attendu : le fichier de `assets/brand/`
  apparaît avec son chemin public.
- `make qa` -- attendu : les six catégories vertes, `QualityGateParityTest` inclus.

**Manual checks :**

- Lancer l'application avec `APP_NAME="Menuiserie Dubois"` dans `.env.local` : l'onglet
  porte l'icône, le `h1` et le `<title>` portent le même nom.
