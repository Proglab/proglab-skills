---
title: 'Poser le gabarit de base et le plancher d''accessibilité'
type: 'feature'
created: '2026-09-10'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'b0fd8131f32b27cc28449023497177f311b54e57'
context:
  - '{project-root}/.claude/skills/symfony-proglab-accessibility/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-frontend/SKILL.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-proglab-skills-2026-09-09/EXPERIENCE.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Le socle rend aujourd'hui une page nue : `base.html.twig` est le gabarit de
recette, sans lien d'évitement, sans repères de page, sans région d'annonces, sans gestion
du focus. Chaque écran des stories 1.6, 1.9, 1.10 et de l'Epic 2 réinventerait ses propres
règles, et rien n'empêcherait une régression d'accessibilité d'entrer.

**Approach:** Remplacer `base.html.twig` par le gabarit de base unique décrit en
UX-DR-6 — lien « Aller au contenu », repères, `<main id="contenu" tabindex="-1">`, région
live `#annonces` persistante, `<title>` « <page> — <nom du dérivé> » — installer Turbo
Drive et les deux contrôleurs Stimulus `page-focus` et `announce` qui le rendent utilisable
au lecteur d'écran, puis fermer le tout par une **sixième catégorie « Accessibility »** de
la porte de qualité qui vérifie mécaniquement les sept règles sur les pages réellement
rendues et sur les sources.

## Boundaries & Constraints

**Always:**
- Le contrat exact de UX-DR-6 / `EXPERIENCE.md § Accessibility Floor` fait foi, à la
  lettre : `href="#contenu"`, `<main id="contenu" tabindex="-1">`,
  `<div id="annonces" aria-live="polite" class="sr-only" data-turbo-permanent>`, ordre de
  focus bloc d'erreur `role="alert"` → premier `aria-invalid` → Flash → `h1`.
- Le `h1` appartient au template de page, jamais au gabarit. Le gabarit expose le bloc.
- Amélioration progressive : tout ce que le gabarit apporte fonctionne sans JavaScript.
  Les deux contrôleurs n'ajoutent que le confort et ne portent aucune règle métier.
- Le nom du dérivé vient d'une variable d'environnement `APP_NAME` → paramètre `app.name`
  → global Twig. Aucun réglage de marque en base ni en administration.
- Les jetons existants sont réutilisés tels quels : `:focus-visible` et
  `prefers-reduced-motion` sont **déjà** posés dans `theme.css` (l. 313-319, 348-351) — les
  vérifier, jamais les redéclarer.
- La **chaîne d'assets** reste sans Node : ni bundler, ni `package.json` à la racine, ni
  `node_modules` à la racine — `AssetPipelineTest::nothing_in_the_chain_needs_node` (story
  1.3) reste vrai à la lettre. **Exception accordée par Fabrice pendant la story 1.4 : les
  tests ont le droit d'utiliser Node.** Elle sert à couvrir les quatre lignes de la matrice
  qui décrivent du comportement JavaScript, qu'aucun test PHP ne peut prouver. Le
  nécessaire vit donc hors de la racine (`tests/js/`), pour que la règle de la 1.3 ne soit
  ni contournée ni affaiblie, et l'exception est écrite là où elle s'applique.
- Un défaut du kit sous un seuil WCAG est conservé et documenté comme choix assumé, jamais
  consigné en dette (`DESIGN.md § Colors`, principe « défauts shadcn »).

**Décisions arrêtées (questions ouvertes tranchées avant approbation) :**
- **Repères conditionnels.** Le gabarit expose des blocs `nav` et `footer` et ne rend le
  repère que s'il a du contenu : un `<nav>` vide n'est pas un repère, et la Sidebar
  appartient à la story 2.3. **Couture assumée** : la moitié « la page porte
  `<nav aria-label>` » du premier critère de la story ne se referme qu'à la 2.3. Le
  contrôle d'accessibilité exempte donc `<nav>` explicitement, avec le motif écrit dans le
  test — pas en silence.
- **Contraste réellement mesuré.** La règle 2 convertit les valeurs `oklch` de `theme.css`
  en sRGB, calcule la luminance relative et le ratio, et vérifie les quatre combinaisons
  porteuses de sens de `DESIGN.md § Colors` contre leur seuil, en clair et en sombre. Les
  deux défauts shadcn sous seuil (`--ring`, `--input`) sont nommés en exception assumée,
  lue depuis le bloc de commentaire que la story 1.3 a écrit dans `theme.css` — jamais une
  baseline de suppression. C'est ce qui tient la promesse « remesurés après tout
  rebranding » de `EXPERIENCE.md`.
- **Remap typographique reporté à la story 2.3.** La 1.4 n'écrit que deux textes : trop peu
  pour arbitrer entre remapper les paliers Tailwind et éditer chaque composant copié. La
  2.3 pose les premiers écrans denses et tranchera. L'entrée de `deferred-work.md` est
  ré-ancrée sur 2.3, pas refermée.

**Never:**
- Pas de sidebar, pas d'en-tête applicatif, pas de menu de compte, pas de sélecteur de
  thème : la coque est la story 2.3.
- Pas de Live Component, pas de bundler, pas d'étape Node, pas d'axe-core.
- Pas de raccourci clavier global, pas de piège à focus écrit à la main (Dialog et Sheet
  sont au kit, story 2.3).
- Ne pas toucher : `src/Core/Contract/`, `deptrac.yaml`, `phpstan.dist.neon`,
  `.php-cs-fixer.dist.php`, `config/packages/doctrine*.yaml`, `migrations/`, `_bmad/`,
  `.claude/skills/`, `_bmad-output/planning-artifacts/`.
- Ne pas déplacer les composants du kit ni en ajouter dans `templates/components/` : les
  partiels du socle vont dans `templates/layout/`, hors du périmètre de `KitIntegrityTest`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Tabulation initiale | Page rendue, Tab depuis le haut | Premier élément focalisable = « Aller au contenu », `sr-only` hors focus, visible au focus, cible `#contenu` | N/A |
| Premier chargement de session | `page-focus` connecté, aucune navigation antérieure | Aucun déplacement de focus (le navigateur l'a déjà placé) | N/A |
| Navigation Turbo, page ordinaire | Body remplacé, ni erreur ni Flash | Focus sur le `h1` (`tabindex="-1"`) | Pas de `h1` → aucun déplacement, aucune exception |
| Rendu 422 avec erreurs | Bloc `role="alert"` + champs `aria-invalid` | Focus sur le bloc `role="alert"`, et `announce` recopie son texte dans `#annonces` en `assertive` | N/A |
| Flash après redirection 303 | Flash `tabindex="-1"`, pas d'erreur | Focus sur le Flash ; `announce` recopie son texte en `polite` | N/A |
| JavaScript désactivé | Aucun contrôleur ne démarre | Le chemin fonctionne en pleine page ; repères, lien d'évitement et `<title>` restent corrects | N/A |
| `prefers-reduced-motion: reduce` | Transition du socle écrite à la main | Durée nulle : elle lit `--motion-fade` / `--motion-panel`, ramenés à 0 | N/A |
| Régression d'accessibilité introduite | Un template perd son label, saute un niveau de titre, ou supprime le focus | `make a11y` et le job CI « Accessibility » échouent en nommant le fichier et la règle | N/A |

</frozen-after-approval>

## Code Map

- `templates/base.html.twig` — le gabarit de recette à remplacer. Blocs actuels : `title`,
  `stylesheets`, `javascripts`, `importmap`, `body`. `lang`, viewport et `importmap('app')`
  sont déjà branchés et corrects ; le commentaire d'en-tête annonce déjà cette story.
- `templates/home/index.html.twig` + `src/Core/Controller/HomeController.php` — la seule
  page rendue du socle (`app_home`, `GET /`). Le `h1` y est déjà, et son `{% block title %}`
  devra ne porter que le nom de la page, le gabarit ajoutant « — <nom du dérivé> ».
- `assets/stimulus_bootstrap.js` — `startStimulusApp()` seul ; la découverte est
  automatique : `page_focus_controller.js` → `data-controller="page-focus"`. Rien à
  enregistrer à la main.
- `assets/controllers/csrf_protection_controller.js` — écoute déjà `turbo:submit-start` /
  `turbo:submit-end`, aujourd'hui inertes. Ils deviennent actifs avec Turbo.
- `importmap.php` — `app`, `@hotwired/stimulus` 3.2.2, `@symfony/stimulus-bundle`, deux CSS.
  **`@hotwired/turbo` absent** ; `symfony/ux-turbo` 3.4 est dans la table Stack de
  l'architecture (AR-25) et les critères de cette story sont écrits en termes de Turbo.
- `assets/styles/theme.css:313-319` (`prefers-reduced-motion` → `--motion-fade` /
  `--motion-panel` à 0) et `:348-351` (`:focus-visible` : `outline: 2px solid var(--ring)`)
  — le plancher est **déjà posé**. Cette story le vérifie et l'utilise, ne le réécrit pas.
- `assets/styles/app.css` — `@source '../../templates/'`, `'../../src/'`,
  `'../controllers/'` : c'est la liste que le contrôle d'accessibilité doit scanner, lue
  au même endroit que `HardcodedColorTest`.
- `Makefile:14-18` — table `# qa-category: <Nom> = <cible>` ; `:30` `.PHONY` ; `:36`
  prérequis de `qa` ; `:33` le help exige une doc `## ` sur chaque règle.
- `.github/workflows/ci.yml` — cinq jobs, `name:` = catégorie exacte. Le sixième s'ajoute
  après la l. 209.
- `tests/Core/Quality/QualityGateParityTest.php:31-37` (`FLOOR`) et `:101-125`
  (`INVOCATIONS`) — les deux constantes à étendre ; les tests l. 40-89, 128-170 tiennent la
  parité Makefile ↔ CI et l'existence réelle des cibles.
- `tests/Core/Quality/GateFiles.php` — `read()`, `activeLines()`, `job()`, `jobNames()`,
  `runSteps()`, `jobIdsByName()`, `recipe()`, `projectDir()`. Tout est statique.
- `tests/Core/Theme/ThemeSheet.php` — `declarations()`, `values()`, `block($path, $selector)`
  avec `LIGHT` / `SYSTEM_DARK` / `FORCED_DARK`, `activeLines()`. C'est par là qu'on lit les
  valeurs `oklch` pour la mesure de contraste, sans réécrire un parseur.
- `tests/Core/Controller/HomeControllerTest.php` — seul test qui regarde une page rendue
  (`WebTestCase`, `assertSelectorCount(1, 'h1')`). Point d'ancrage du contrôle rendu.
- `tests/Core/BoundaryTest.php` — le précédent de style : `#[Test]`, `#[DataProvider]`,
  providers `Generator` à clés françaises, message d'échec qui nomme le fichier fautif.
- `phpunit.dist.xml` — une seule testsuite `Project Test Suite` → `tests`. Une catégorie de
  porte séparée impose d'y déclarer une seconde testsuite et d'exclure la première.
- `config/services.yaml`, `.env` — `parameters:` est vide ; `APP_NAME` n'existe pas.

## Tasks & Acceptance

**Execution:**
- [x] `tests/Core/Accessibility/AccessibilityFloorTest.php` — écrire d'abord et voir rouge :
      un test par règle, sur les pages réellement rendues (`WebTestCase` sur toute route GET
      du socle) et sur les sources déclarées par `app.css`. Sept règles + repères, lien
      d'évitement, `lang`, `#annonces`, `prefers-reduced-motion`. Message d'échec nommant le
      fichier et la règle enfreinte.
- [x] `tests/Core/Accessibility/Contrast.php` — helper de conversion `oklch` → sRGB →
      luminance relative → ratio, alimenté par `ThemeSheet::block()`. Les quatre
      combinaisons de `DESIGN.md § Colors` en clair et en sombre ; les deux défauts shadcn
      sous seuil lus comme exception nommée depuis le commentaire de `theme.css`.
- [x] `tests/Core/Template/BaseTemplateTest.php` — écrire d'abord et voir rouge : le
      gabarit rend le contrat de UX-DR-6 à la lettre (ordre du DOM, attributs exacts,
      `<title>` composé), et le `h1` n'y est pas.
- [x] `composer.json`, `importmap.php`, `assets/vendor/` — `symfony/ux-turbo` 3.4 ;
      `@hotwired/turbo` épinglé et son vendor commité, comme les autres.
- [x] `.env`, `config/services.yaml`, `config/packages/twig.yaml` — `APP_NAME` →
      `parameters.app.name` → global Twig `app_name`, avec le commentaire qui dit pourquoi
      c'est une variable d'environnement et non un réglage.
- [x] `templates/base.html.twig` — le gabarit : lien d'évitement, `<header>`, `<nav>`,
      `<main id="contenu" tabindex="-1" data-controller="page-focus">`, `<footer>`,
      `#annonces`, `<title>` composé, blocs nommés pour les pages.
- [~] `templates/layout/` — non créé : le gabarit tient en un fichier, et un partiel à un
      seul appelant est un fichier de plus à ouvrir pour lire une page. La règle reste
      posée pour la story 2.3 (`deferred-work.md`).
- [x] `assets/controllers/page_focus_controller.js` — l'ordre de focus à quatre niveaux ;
      ignore le premier chargement de la session ; ne lève rien quand aucune cible n'existe.
- [x] `assets/controllers/announce_controller.js` — recopie du texte dans `#annonces` à
      `connect()`, différée d'un instant ; `assertive` pour une erreur, `polite` sinon.
- [x] `templates/home/index.html.twig` — s'aligner sur le gabarit : `{% block title %}` ne
      porte plus que le nom de la page.
- [x] `Makefile`, `.github/workflows/ci.yml`,
      `tests/Core/Quality/QualityGateParityTest.php`, `phpunit.dist.xml` — sixième catégorie
      « Accessibility » : cible `a11y`, job CI du même nom, `INVOCATIONS` et `FLOOR` étendus,
      testsuite dédiée pour que la porte ne joue pas la suite deux fois.
- [x] `README.md` — la sixième catégorie dans la description de la porte, et la façon de
      vérifier le gabarit à la main (Tab depuis le haut, JavaScript coupé, zoom 400 %).
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` — refermer ou ré-ancrer les
      entrées de la 1.3 que cette story tranche (remap typographique, namespace `--motion-*`,
      composant anonyme du socle).

**Acceptance Criteria:**
- Given le gabarit livré, when une page du socle est rendue, then le premier élément
  focalisable est « Aller au contenu » vers `<main id="contenu" tabindex="-1">`, et la page
  porte `<header>`, `<main>`, un seul `h1` et un `<title>` « <page> — <nom du dérivé> » ;
  `<nav aria-label>` et `<footer>` sont exposés en blocs et rendus dès qu'ils ont du
  contenu — la Sidebar de la story 2.3 les remplira
- Given un dérivé qui change `APP_NAME`, when il rend n'importe quelle page, then le
  `<title>` suit, et aucun fichier de `src/` ni de `config/` n'a été modifié
- Given la porte de qualité, when `make qa` s'exécute, then « Accessibility » en est la
  sixième catégorie, le job CI du même nom existe, et `QualityGateParityTest` reste vert
- Given un template auquel on retire un label, on ajoute un second `h1` ou on pose
  `outline-none` sans remplacement, when `make a11y` s'exécute, then il échoue en nommant le
  fichier et la règle
- Given un zoom à 400 % (320 px de large), when je consulte une page du socle, then la mise
  en page se réorganise sans défilement horizontal du body

## Implementation Notes

**TDD.** Les deux tests ont été écrits d'abord et vus rouges :
`BaseTemplateTest` (10 tests, 3 erreurs + 6 échecs sur le gabarit de recette) et
`AccessibilityFloorTest` (5 échecs sur les repères, le lien d'évitement, la région
d'annonces et le plancher `:focus-visible`). Les douze règles restantes du plancher étaient
vertes dès l'écriture — le socle ne les enfreignait pas — donc chacune a été vérifiée par
mutation : une régression injectée à la main, l'échec observé avec le fichier et la règle
nommés, le fichier restauré. Injections vérifiées : second `h1`, saut de `h1` à `h4`,
`<input>` à `placeholder` seul, `outline-none` sans remplacement, `hover:block` sans
équivalent au focus, `<img>` sans `alt`, `aria-invalid` sans `aria-describedby`, `<nav>`
sans `aria-label`, `--muted-strong` abaissé, « choix assumé » retiré du commentaire de
`theme.css`, `--motion-panel` remis à 120 ms, `data-turbo-permanent` retiré, `lang` retiré,
`tabindex` du `<main>` retiré, ancre du lien d'évitement cassée.

**Turbo.** `symfony/ux-turbo` 3.4.0 installé par sa recette ; `@hotwired/turbo` 8.0.23
épinglé dans `importmap.php` et son vendor commité. La recette a réécrit
`config/bundles.php` en supprimant deux commentaires (DAMA, tailwind_merge) : ils ont été
restaurés. Elle a aussi posé `config/packages/ux_turbo.yaml`, conservé et commenté — voir
`deferred-work.md`.

**Repères conditionnels.** `<header>` est rendu toujours (critère de la story) ; `<nav>` et
`<footer>` sont exposés en blocs et rendus seulement remplis. Le contrôle exempte donc
`<nav>` avec son motif écrit dans la constante `NAV_EXEMPTION` du test, et non en silence.

**Contraste.** `tests/Core/Accessibility/Contrast.php` refait la chaîne complète
`oklch` → Oklab → sRGB linéaire → luminance → ratio. Recoupé avec le tableau de
`DESIGN.md § Colors` : 19,79 / 4,73 / 7,15 / 4,76 en clair contre ≈ 19 / 4,74 / ≈ 7,2 /
4,77 annoncés. Un seul écart réel : le tableau donne `--ring` à ≈ 2,05:1 sur blanc pour un
hex `#b5b5b5`, alors que `oklch(0.708 0 0)` vaut `#a1a1a1` et mesure 2,59:1. C'est le hex
approché du spine qui est faux, pas la conversion — et `--ring` est de toute façon une
exception assumée. Aucune valeur du thème n'a été touchée.

**L'exception assumée, sans baseline.** Les deux défauts shadcn sous seuil ne sont pas
listés dans le test : celui-ci mesure, et n'excuse un jeton que si un commentaire de
`theme.css` le nomme en tête de ligne à côté de « choix assumé ». Retirer ce commentaire
rend le test rouge. Vérifié par mutation.

**Sixième catégorie.** Cible `a11y`, job CI « Accessibility », `FLOOR` et `INVOCATIONS`
étendus. `phpunit.dist.xml` déclare une seconde testsuite et un `defaultTestSuite`, pour que
`make test` et le job « Tests » ne rejouent pas le plancher. Le motif d'aide du `Makefile`
a dû passer de `[a-zA-Z_-]+` à `[a-zA-Z0-9_-]+` : sans le chiffre, `a11y` n'apparaissait
dans aucune liste.

**`templates/layout/` n'a pas été créé.** Le gabarit tient en un fichier ; un partiel à un
seul appelant est un fichier de plus à ouvrir pour lire une page. La règle reste posée pour
la story 2.3 (voir `deferred-work.md`).

**Deux textes en français en clair** — « Aller au contenu » et « Navigation principale »
(libellé par défaut du bloc `nav_label`). Les catalogues arrivent à la story 1.5 ; y
renvoyer maintenant afficherait une clé.

**Les deux contrôleurs Stimulus, testés — l'exception Node, bornée.** Quatre des huit
lignes de la matrice d'edge cases décrivent du comportement JavaScript et n'étaient
couvertes par rien : « Premier chargement de session », « Navigation Turbo, page
ordinaire », « Rendu 422 avec erreurs » et « Flash après redirection 303 ». L'exception
que le bloc gelé accorde — *les tests ont le droit d'utiliser Node, la chaîne d'assets
non* — a été employée pour les fermer, et l'entrée correspondante de `deferred-work.md`
a été retirée.

*Outillage.* Le runner de Node (`node --test`, aucune dépendance) et **jsdom** pour le
DOM. Stimulus n'est pas installé depuis npm : le harnais résout
`import … from '@hotwired/stimulus'` vers `assets/vendor/@hotwired/stimulus/`, le fichier
commité qu'`importmap.php` épingle, par un crochet `module.registerHooks()`. Les tests
exercent donc **le** Stimulus servi, pas une seconde copie qui dériverait un jour. Tout le
nécessaire vit sous `tests/js/` — `package.json`, `package-lock.json`, `node_modules/`
(ignoré), `support/harness.js`, deux fichiers de test —, la racine n'a rien, et
`AssetPipelineTest::nothing_in_the_chain_needs_node()` n'a pas été touché.

*Ce que le harnais modélise.* `mount()` monte une page, installe les globales du
navigateur, démarre Stimulus et **collecte** les exceptions que `handleError` avale
d'ordinaire — sans quoi « ne lève rien » serait vert sur un contrôleur qui explose.
`navigate()` reproduit ce que fait Turbo Drive : le `<body>` est remplacé et l'élément
`data-turbo-permanent` de la nouvelle page est **remplacé par** le nœud déjà en place,
apparié sur son `id`. Ce détail n'est pas cosmétique : se contenter de rajouter l'ancien
nœud laissait deux `#annonces` dans le document, et le contrôleur écrivait dans celui que
personne ne surveillait. jsdom n'implémentant pas `scrollIntoView`, le harnais le bouchonne
en l'**enregistrant**, ce qui en fait une assertion plutôt qu'une tolérance. Chaque montage
réimporte le contrôleur avec une chaîne de cache-bust : `page_focus_controller.js` porte un
drapeau de portée module (`hasNavigated`), et c'est exactement la distinction testée.

*Couverture.* 21 tests. `page-focus` : les quatre lignes de la matrice, l'ordre des quatre
sélecteurs de `FOCUS_ORDER` un cran à la fois (les cibles sont écrites dans le DOM à
l'envers de leur priorité, pour qu'un « premier du document » échoue), le `tabindex="-1"`
posé quand il manque et **non** réécrit quand il est là, l'absence de déplacement et
d'exception sans cible, l'appel à `scrollIntoView`. `announce` : `assertive` sur
`role="alert"` et `polite` sinon, la valeur explicite qui l'emporte sur le rôle, le délai
(région vide juste après `connect()`, remplie après), le vidage avant écriture prouvé sur
**deux messages identiques à la suite** — la région doit passer par le vide, sinon la
seconde annonce n'existe pas —, le message vide, la région absente, et l'annulation par
`disconnect()`.

*Vérification par mutation.* Les contrôleurs existaient avant les tests, donc le rouge
d'abord était impossible. Treize régressions injectées une à une, chacune vue rouge puis
restaurée : garde du premier chargement retiré ; `FOCUS_ORDER` inversé ; `tabindex` non
posé ; `tabindex` existant écrasé ; garde « aucune cible » retiré ; `scrollIntoView`
retiré ; vidage de la région retiré ; écriture immédiate sans délai ; priorité forcée à
`polite` ; `priorityValue` ignorée ; `clearTimeout` de `disconnect()` retiré ; garde
« message vide » retiré ; garde « région absente » retiré.

*Raccordement à la porte.* Pas de septième catégorie : ces tests **sont** du comportement
d'accessibilité et rejoignent « Accessibility ». `make a11y` et le job CI du même nom
lancent `node --test "tests/js/**/*.test.js"`, et la constante `INVOCATIONS` de
`QualityGateParityTest` nomme ce fragment — retiré d'une seule des deux façades, le test
de parité échoue en la désignant (vérifié dans les deux sens). La CI ajoute
`actions/setup-node@v4` avec le cache npm clé sur `tests/js/package-lock.json`, et
`npm ci` plutôt qu'`install` : il refuse un lockfile qui a dérivé du manifeste, comme
`composer validate --strict` côté PHP.

*L'exception, bornée mécaniquement.* `AssetPipelineTest::the_node_exception_is_confined_to_the_test_directory()`
est le pendant de la règle qu'il borne, et il est écrit juste à côté d'elle. Il balaie le
dépôt et exige que **tout** artefact Node — `package.json`, les trois lockfiles,
`node_modules` — soit sous `tests/js/`, ce qui refuse aussi bien `assets/package.json` que
`src/Module/Truc/node_modules/` ; il exige que le manifeste et le lockfile de `tests/js/`
soient réellement là ; et il refuse que `importmap.php`, la configuration d'AssetMapper, la
cible de build Tailwind ou le moindre fichier d'`assets/` mentionne `tests/js`. La flèche
ne va que dans un sens. Vérifié par quatre mutations : `package.json` à la racine, un autre
sous `assets/`, un `@source '../../tests/js/'` ajouté à `app.css`, et le lockfile retiré —
les quatre rouges, avec le fichier nommé.

*Ce que ces tests ne prouvent toujours pas.* jsdom n'a pas de moteur de mise en page : ni
l'ordre de tabulation réel, ni le rendu à 320 px, ni ce qu'un lecteur d'écran annonce
vraiment. Les trois vérifications manuelles du README restent le complément assumé. Le
harnais enregistre aussi les contrôleurs à la main (`page-focus`, `announce`) plutôt que
par le loader de `@symfony/stimulus-bundle` : la convention de nommage fichier →
identifiant n'est donc pas exercée ici.

**Passe de correctifs — les vingt trouvailles routées `patch` de la relecture.** Chacune est
couverte par un test vu rouge sans le correctif ; quand le test existait déjà, la
vérification s'est faite par mutation, en cassant le code et en observant l'échec.

*Le plancher, dans ses propres trous.* La règle 4 ne lisait **aucune** feuille de style :
`@source` dit à Tailwind où chercher des classes, pas où vivent les règles CSS, et
`assets/styles/` n'y figurait donc pas — un `outline: none` écrit dans `theme.css` passait
la porte. Les trois feuilles sont désormais lues à côté des sources déclarées, commentaires
neutralisés pour qu'un commentaire qui *énonce* la règle ne l'enfreigne pas. Le remplacement
est cherché dans la **portée** qui porte la suppression, plus dans le fichier entier : la
liste de classes pour un utilitaire Tailwind, la règle CSS — sélecteur compris — pour une
déclaration. En CSS, le sélecteur ne compte pas comme remplacement : `:focus-visible {
outline: none }` est la suppression la mieux déguisée, ce qu'exige le contrôle est un
`box-shadow` ou un second `outline` qui dessine. Et c'est maintenant le **dernier** bloc
`:focus-visible` de `theme.css` qui doit dessiner un contour, puisque c'est lui qui gagne.
La règle 6 compare le premier titre à `h1` au lieu de l'ignorer — une page `h3` puis `h1`
passait. Les `href` de lien d'évitement et les `aria-describedby` sont résolus en XPath sur
un littéral, jamais en sélecteur CSS : `#form[email]_error` **levait** une
`SyntaxErrorException` et la catégorie sortait en erreur au lieu de nommer l'infraction.
`pages()` filtre enfin sur ce qu'il faut exclure — `App\Tests\` — au lieu d'inclure
`App\Core\` : le socle existe pour être dérivé, et le filtre par inclusion mettait tout
`src/Module/*/`, donc tous les écrans du client, hors du plancher. Vérifié en posant un
module sonde sous `src/Module/`, vu couvert, puis retiré. Enfin, `Contrast::parse()` refuse
un `oklch()` translucide au lieu de le mesurer comme opaque : le ratio d'une couleur à
alpha dépend de ce qu'il y a derrière, et un ratio faux **en silence** est le pire mode
d'échec d'un contrôle de contraste. `tests/Core/Accessibility/ContrastTest.php` tient ce
refus.

*Les deux contrôleurs.* `page-focus` ne pose plus `tabindex="-1"` sur une cible déjà
focalisable : sur le champ `aria-invalid` d'un rendu 422 — un `<input>` — il le **sortait de
l'ordre de tabulation**, cassant la règle 4 par le contrôleur censé la servir. Il ignore la
prévisualisation de cache de Turbo (`data-turbo-preview`, le body remplacé deux fois par
visite) et ne refait plus défiler la page sur une visite de restauration, où Turbo a déjà
remis la position. La contrainte « le Flash et le bloc d'erreur vivent dans `<main>` » —
conséquence de la portée `this.element` — est écrite dans le contrôleur, répétée dans
`base.html.twig` à l'endroit que la story 1.6 remplira, et tenue par un test.
`announce` compose au lieu d'écraser : les messages connectés s'inscrivent dans un registre,
la région est réécrite à partir de tous, et `assertive` l'emporte dès qu'un seul le mérite.
La priorité se **recalcule** à chaque connexion et déconnexion, ce qui la ramène à `polite`
quand le dernier message s'en va — la région porte `data-turbo-permanent`, et une seule
erreur laissait jusqu'ici toute la session en `assertive`. Le harnais modélise pour cela les
deux détails manquants de Turbo : `turbo:visit` avec son action, et le rendu de
prévisualisation. Cinq mutations, cinq rouges. Les tests JavaScript passent de 21 à 27.

*Le câblage que rien ne retenait.* Quatre tests neufs dans `AssetPipelineTest` : que
`controllers.json` active encore `turbo-core` — le seul interrupteur qui démarre Turbo, dont
le passage à `false` rendait `data-turbo-permanent` et `page-focus` inertes avec six
catégories vertes ; qu'`app.js` importe encore `stimulus_bootstrap.js` et que celui-ci
appelle `startStimulusApp()` — retirer cette ligne tuait les deux contrôleurs **et** Turbo
sans un seul rouge ; que le glob que les deux façades lancent désigne réellement des
fichiers, `node --test` sortant en 0 sur un glob vide ; et que le plancher de version Node
soit lu partout. L'élément Mercure de la recette n'est plus auto-importé : il était
téléchargé et évalué sur chaque page pour une fonctionnalité absente de la stack.
`controllers.json` étant du JSON, l'explication vit dans le test qui tient la règle, faute
de pouvoir vivre dans le fichier comme celle de `config/packages/ux_turbo.yaml`.

*La porte, la version de Node, le gabarit.* `make a11y` n'installe plus les dépendances npm
qu'à retard constaté — `node_modules/.package-lock.json` absent ou plus vieux que le
lockfile —, ce qui rend à `make qa` la capacité de tourner hors ligne comme le reste de la
porte, et il dit clairement quoi faire quand `node` manque au lieu d'un « npm: not found ».
Le plancher de version est déclaré une seule fois, dans l'`engines.node` de
`tests/js/package.json`, rendu contraignant par `engine-strict=true`, lu par la CI via
`node-version-file` et nommé par le README — les trois endroits divergeaient (rien, 22, 24)
alors que le harnais exige 22.15. Le `<title>` a sa garde : une page qui n'écrit pas son
bloc obtient le seul nom du dérivé, plus « — Socle ERP », et la forme est exigée sur toute
page rendue. `lang` transpose la locale Symfony en étiquette BCP 47 — `fr_BE` devient
`fr-BE`, inatteignable avant la story 1.5 et donc testé en rendant le gabarit nu sur une
locale régionale plutôt qu'en attendant qu'un dérivé le découvre.

*Une phrase corrigée.* Les deux fichiers de test JavaScript affirmaient que « les
contrôleurs existaient avant ces tests, donc le rouge d'abord était impossible ». C'est
faux : contrôleurs et tests sont nés dans le même changement, et écrire les contrôleurs
d'abord était un choix, pas une contrainte. Les deux en-têtes le disent maintenant, et
disent que le repli de la règle 1 — la vérification par mutation — a été appliqué à la
place. La même inexactitude subsiste plus haut dans ces notes, à la ligne « Vérification par
mutation » : ces notes sont en ajout seul, et c'est ici qu'elle se corrige.

*Deux reports consignés* dans `deferred-work.md` : le favicon absent, qui appartient à la
surface de rebranding des stories 1.11 et 1.12 ; et l'erreur du spine sur `--ring`
(≈ 2,05:1 annoncé pour un hex approché faux, 2,59:1 mesuré), que le bloc gelé interdit de
corriger ici.

## Spec Change Log

## Review Triage Log

Passe 1 — quatre couches : blind-hunter (14), edge-case-hunter (15), verification-gap
(4 + 2, pré-vérifiées), proglab-conformance (3). Les doublons entre couches sont fusionnés
sur cause commune. Chaque trouvaille non pré-vérifiée a été rouverte à l'emplacement cité
avant verdict.

| # | Couche | Trouvaille | Verdict | Évidence | Route |
|---|---|---|---|---|---|
| 1 | vgap + edge + blind | La règle 4 ne lit aucune feuille de style : `finderFor()` filtre sur `*.twig`, `*.php`, `*.js` et ne balaie que `templates/`, `src/`, `assets/controllers/` | `high` | Vérifié aux deux endroits. Un `outline: none` ajouté à `theme.css` ou `app.css` passe la porte, et `rule_4_the_theme_declares_the_focus_visible_floor` n'observe que le **premier** bloc `:focus-visible` par `preg_match`. C'est le trou exactement là où la règle 4 casse le plus souvent | patch |
| 2 | edge | `$replaced` de la règle 4 est calculé par fichier : un fichier qui contient `:focus-visible` quelque part excuse tout `outline-none` ailleurs dans le même fichier | `medium` | Vérifié l. 338 : la recherche porte sur le fichier entier recollé. Un `outline-none` ajouté à un composant du kit passerait | patch |
| 3 | edge | `page-focus` pose `tabindex="-1"` sur une cible nativement focalisable — le premier champ `aria-invalid` est un `<input>` | `high` | Vérifié : la garde ne teste que l'absence d'attribut. Après un rendu 422, le champ en erreur **sort de l'ordre de tabulation** — la règle 4 cassée par le contrôleur censé la servir | patch |
| 4 | blind + edge | La priorité de `#annonces` est collante : `aria-live` passe à `assertive` et n'est jamais remis à `polite` | `high` | Vérifié l. 52, et la région porte `data-turbo-permanent` : une seule erreur laisse la session entière en `assertive`. `BaseTemplateTest` affirme « elle ne naît jamais assertive », vrai seulement après un rechargement complet | patch |
| 5 | blind + edge | `<title>` rend « — Socle ERP » dès qu'une page n'écrit pas son bloc | `high` | Vérifié l. 30 du gabarit : aucune garde. Les pages d'erreur de la story 1.10 sont nommément visées par le commentaire de `twig.yaml`, et aucun test n'exige un titre non vide | patch |
| 6 | vgap | Rien ne vérifie que `assets/controllers.json` active encore `turbo-core` — le seul interrupteur qui démarre Turbo | `high` | Pré-vérifié : passer `enabled` à `false` rend `data-turbo-permanent` et `page-focus` inertes, et les six catégories restent vertes. Le comportement le plus structurant de la story n'a aucun test qui puisse échouer | patch |
| 7 | vgap | Rien ne vérifie que `assets/app.js` importe encore `stimulus_bootstrap.js`, ni que celui-ci appelle `startStimulusApp()` | `high` | Pré-vérifié : `the_javascript_entrypoint_pulls_the_stylesheet_in` n'assure que l'import CSS. Retirer la ligne 12 tue les deux contrôleurs **et** Turbo, sans un seul rouge | patch |
| 8 | vgap | `node --test "tests/js/**/*.test.js"` sort en 0 quand le glob ne matche rien | `high` | Pré-vérifié et reproduit par la couche : sur un glob vide, `node --test` affiche `tests 0` et sort 0. Un renommage ou un déplacement transforme la moitié JS de la catégorie en no-op vert | patch |
| 9 | blind | La règle 6 ne compare jamais le **premier** titre à `h1` : le niveau précédent part à 0 et la garde l'ignore | `medium` | Vérifié l. 431-436. Une page qui rend `h3` puis `h1` compte un seul `h1`, ne déclenche aucun saut, et passe | patch |
| 10 | conf + blind | L'élément Mercure de la recette `ux-turbo` est auto-importé sur chaque page, `mercure-turbo-stream` étant pourtant désactivé | `medium` | Vérifié : `@symfony/ux-turbo/mercure_stream_source_element.js` est dans `debug:asset-map`, et l'`autoimport` d'un contrôleur activé est collecté par le générateur du bundle. Mercure est au niveau « à la demande » du standard, sans déclencheur ici — et c'est le seul reliquat de recette laissé sans un mot | patch |
| 11 | blind + edge | `make qa` exige désormais le réseau à chaque exécution : `a11y` lance `npm install` sans condition | `medium` | Vérifié `Makefile:84`. Le reste de la porte tourne hors ligne ; celle-ci ne le peut plus. `tailwind:build` y est aussi joué une seconde fois | patch |
| 12 | blind + edge | Le plancher de version Node n'est écrit nulle part et les trois endroits qui le nomment divergent | `medium` | Vérifié : pas d'`engines` dans `tests/js/package.json`, README « Node 22 ou plus récent », CI épinglée sur 24, et `harness.js` documente lui-même que le crochet exige 22.15+. Un poste en 22.0-22.14 échoue sur une erreur de type sans explication | patch |
| 13 | blind + edge | `page-focus` ne se garde ni de la prévisualisation de cache de Turbo, ni des visites de restauration | `medium` | Vrai : Turbo remplace le body deux fois par visite (`data-turbo-preview`), et `scrollIntoView` défait la restauration de défilement au retour arrière. Le harnais ne modélise qu'un seul remplacement, donc la classe de bug est hors de sa portée | patch |
| 14 | vgap | `AccessibilityFloorTest::pages()` exige un `_controller` commençant par `App\Core\`, donc tout écran d'un module de dérivé est hors du plancher | `medium` | Vérifié l. 587 : les modules de démonstration vivent sous `App\Tests\Fixtures\Module\` et seraient déjà écartés par un filtre `App\Tests\`. Le socle existe pour être dérivé : exclure `src/Module/*/` vide le plancher chez le client | patch |
| 15 | blind + edge | Le contrôleur `announce` écrase le message précédent quand deux messages coexistent sur une page | `medium` | Vrai, et aujourd'hui inatteignable : rien ne rend `data-controller="announce"` dans le socle. La story 1.6 livre le premier Flash et le premier rendu 422 — elle rencontrerait le défaut le jour même | patch |
| 16 | edge | Un `href` de lien d'évitement ou un `aria-describedby` non conforme à la syntaxe CSS fait **lever** le plancher au lieu de nommer une infraction | `medium` | Vrai : le fragment est passé tel quel au sélecteur. La catégorie « Accessibility » sortirait en erreur, pas en échec de règle — le message ne dirait plus quoi corriger | patch |
| 17 | edge | `Contrast::parse()` ignore l'alpha : `oklch(L C H / 10%)` est mesuré comme opaque | `medium` | Vérifié : le motif capture la partie alpha sans jamais l'utiliser. `--border` en porte un aujourd'hui et n'est dans aucune paire mesurée, mais un rebranding avec translucide obtiendrait un ratio faux **en silence** — le pire mode d'échec pour un contrôle de contraste | patch |
| 18 | blind | La contrainte « le Flash et le bloc d'erreur doivent vivre dans `<main>` » n'est écrite nulle part | `medium` | Vrai : `#firstTarget()` interroge `this.element`, qui est le `<main>`. Un Flash rendu dans le `<header>` ne serait jamais trouvé, et le contrôleur retomberait en silence sur le `h1` | patch |
| 19 | conf | Les deux contrôleurs ont été écrits avant leurs tests, et la justification écrite dans les deux fichiers de test est inexacte | `medium` | Vrai : contrôleurs et tests sont nouveaux dans le même diff — l'ordre était un choix, pas une contrainte. Le repli de la règle 1 a été appliqué sérieusement (13 mutations), mais la phrase « le rouge d'abord était impossible » est fausse | patch |
| 20 | blind | `lang` recopie la locale Symfony telle quelle : `fr_BE` au lieu de `fr-BE` | `low` | Vrai, et inatteignable avant la story 1.5. Le correctif est une correction directe d'un caractère, donc non rejeté malgré le niveau | patch |
| 21 | blind + edge | Le favicon de la recette disparaît sans remplacement : chaque page déclenche un `/favicon.ico` en 404 | `low` | Vrai. Mais le socle n'a jamais eu de favicon de marque — seulement un espace réservé de la recette — et le favicon appartient à la surface de rebranding (logo), que les stories 1.11 et 1.12 possèdent. Écart préexistant révélé, pas créé | defer |
| 22 | blind | L'erreur du spine sur `--ring` (≈ 2,05:1 annoncé, 2,59:1 mesuré) ne laisse aucune trace hors du spec | `low` | Vrai et vérifié par le calcul. `_bmad-output/planning-artifacts/` est sur la liste « ne pas toucher » du bloc gelé, et l'erreur est antérieure à cette story | defer |
| 23 | edge | Un échec sur une page rendue nomme la route (`/ (app_home)`), jamais le fichier de template | `low` | Vrai. Avec une seule page, la route désigne le template sans ambiguïté ; le correctif demande de remonter le template rendu depuis le profiler, soit plus qu'une correction directe | rejeté |
| 24 | vgap | Rien n'assure que chaque règle documentée apparaît dans `make help` | `low` | Vrai : `QualityGateParityTest` tient la parité catégorie ↔ cible ↔ job, jamais la recette `help`. Cacher une cible du help ne casse rien d'exécutable, et le correctif ajoute un test | rejeté |
| 25 | blind | `sprint-status.yaml` dit `in-progress` pendant que le spec dit `in-review` | `low` | Vrai : la synchronisation du suivi appartient à l'étape de présentation, pas à la relecture. Même verdict qu'à la story 1.3 | rejeté |

Aucune trouvaille ne route en `intent_gap` ni en `bad_spec` : aucune ne remonte au bloc gelé,
et aucune ne demande de redériver le code. Vingt correctifs, deux reports, trois rejets.


## Design Notes

**Pourquoi Turbo entre ici.** Trois des huit critères de la story sont écrits en termes de
navigation Turbo — `data-turbo-permanent`, `connect()` rejoué à chaque remplacement de body,
focus après remplacement. Sans Turbo, ils ne sont pas vérifiables et les deux contrôleurs
seraient du code écrit contre un mécanisme absent. `symfony/ux-turbo` 3.4 est dans la table
Stack de l'architecture (AR-25) et aucune story antérieure ne l'a installé.

**Pourquoi une catégorie de porte plutôt qu'un simple test.** Le contrat de l'epic dit
« vérifié par un test automatisé en CI, jamais par relecture », la story 1.3 a nommé cette
story comme celle qui l'ajoute « comme sixième catégorie de la porte », et le contexte
d'epic dit que la 1.2 « absorbe ensuite le contrôle d'accessibilité automatisé posé par la
1.4 ». Une catégorie nommée est ce qui rend l'échec lisible : « Accessibility » rouge dit
quoi corriger, un test perdu dans la suite ne le dit pas.

**Ce que le contrôle peut et ne peut pas prouver.** Il n'y a pas de navigateur ici : ni
l'ordre de tabulation réel, ni le rendu à 320 px, ni le comportement d'un lecteur d'écran ne
sont mesurables. Ce qui l'est : la structure du DOM rendu (repères, titres, labels,
`alt`/`aria-label`, `aria-describedby`), les sources (`outline-none` sans remplacement,
`hover:` qui révèle sans équivalent au focus), et les valeurs du thème. Les règles 1 et 7
reçoivent donc un contrôle **étroit et nommé** plutôt qu'une preuve complète — l'écart doit
être écrit dans le test lui-même, pas découvert plus tard.

## Verification

**Commands:**
- `php bin/console lint:twig templates/` — valide
- `php bin/console debug:asset-map` — `@hotwired/turbo` et les deux contrôleurs sont dans la map
- `vendor/bin/phpunit` — suite au vert, après avoir été vue rouge
- `make a11y` — vert ; puis, sur une régression injectée à la main, rouge en nommant le fichier
- `make qa` — les six catégories passent

**Manual checks:**
- Tab depuis le haut de `/` : « Aller au contenu » apparaît en premier et devient visible au focus
- JavaScript coupé dans le navigateur : la page reste complète et navigable
- Zoom 400 % (320 px CSS) : aucun défilement horizontal du body
