---
title: 'Installer le kit de composants et poser le thème du socle'
type: 'feature'
created: '2026-09-10'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '9f77411f11fffc24354ef9a410d249bae602583e'
context:
  - '{project-root}/.claude/skills/symfony-proglab-ui/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-frontend/references/assetmapper.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-proglab-skills-2026-09-09/DESIGN.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** le dépôt n'a aucun asset — ni `assets/`, ni AssetMapper, ni Tailwind, ni kit
de composants. Chaque écran des stories 1.4, 1.6, 1.9 et 1.10 devrait inventer ses
couleurs, ses tailles et ses composants, et un dérivé n'aurait aucun point unique où
poser sa marque.

**Approach :** installer la chaîne d'assets sans Node (AssetMapper + Tailwind 4 par
`symfonycasts/tailwind-bundle` + kit shadcn copié par `symfony/ux-toolkit`), puis écrire
le thème complet de `DESIGN.md` en jetons CSS, clair et sombre, dans une feuille que
seul le socle édite, adossée à une feuille de marque où un dérivé ne change que
`--primary` et son logo. Ce que le thème promet est tenu par des tests qui lisent les
feuilles, pas par relecture.

## Decisions

Tranché avec Fabrice le 2026-09-10, là où `DESIGN.md` était muet ou en tension avec le
contrat de dérivation :

1. **Six composants copiés maintenant** — `Button`, `Input`, `Label`, `Alert`, `Badge`,
   `Card` : exactement ce que 1.4, 1.6, 1.9 et 1.10 consommeront. Le reste du kit se
   copie story par story, quand un écran l'utilise.
2. **Le thème est découpé en deux feuilles** — `assets/styles/theme.css` porte le socle
   et n'est jamais édité par un dérivé ; `assets/styles/brand.css` est livré quasi vide
   et ne contient que les variables qu'un dérivé a le droit de redéfinir. `app.css`
   importe Tailwind, le kit, puis les deux dans cet ordre. Écart assumé avec la lettre de
   `DESIGN.md:398-409`, qui désigne `app.css` : le report d'un correctif de thème doit
   rester un `git diff` propre.
3. **Spec conservé entier** malgré ses ~2 900 jetons : l'objectif est unique et le
   découper séparerait des choses qui se vérifient ensemble.

## Boundaries & Constraints

**Always :**
- Aucun bundler, aucune étape Node, aucune dépendance exigeant Symfony 8 (AD-1).
- Un seul kit (`--kit shadcn`) et une seule bibliothèque d'icônes (Tabler via
  `symfony/ux-icons`, `DESIGN.md:643`).
- Chaque rôle de couleur existe en clair **et** en sombre. Le sombre suit
  `prefers-color-scheme` par défaut et se force par la classe `dark` sur `<html>`
  (`EXPERIENCE.md:367-370`) — la bascule d'interface et sa mémorisation appartiennent
  à l'Epic 5.
- Typographie, espacement, rayons et largeurs de contenu sont des jetons nommés, jamais
  des valeurs répétées.
- `binary_version` de Tailwind épinglée en configuration : deux releases du même dérivé
  ne se bâtissent pas avec deux binaires différents (review-versions, constat 1.a).
- `importmap:audit` rejoint la cible `audit` du `Makefile` **et** le job « Linters and
  audits », comme la story 1.2 s'y engage — et la table `INVOCATIONS` du test de parité
  le nomme, sinon la ligne peut disparaître sans rien casser.
- Un défaut du kit sous un seuil WCAG (`--input` 1,26:1, `--ring` ≈ 2,05:1) est conservé
  tel quel et documenté comme choix assumé (`DESIGN.md:432-440`).

**Never :**
- Aucune couleur, taille ou rayon codé en dur dans un template.
- Aucun réglage de marque dans l'administration : la marque est du code.
- Pas de Live Component, pas de Mercure, pas de Turbo (AD-17 ; Turbo arrive avec le
  gabarit en 1.4).
- Pas de gabarit de base, pas de lien d'évitement, pas de région d'annonces, pas de
  contrôleur `theme` : c'est AD-16, story 1.4.
- Pas de sidebar ni de logo réel : la coque est la story 2.3. Cette story livre les
  jetons `sidebar*`, pas le composant.
- Pas de porte accessibilité en CI : elle arrive avec le gabarit, story 1.4.
- Aucun jeton inventé. `DESIGN.md` ne donne ni `popover*`, ni `chart-*`, ni
  `sidebar-border` : ils n'entrent pas.

## I/O & Edge-Case Matrix

| Scénario | Entrée / État | Sortie attendue | Traitement d'erreur |
|---|---|---|---|
| Thème complet | `assets/styles/theme.css` | Chaque rôle de `DESIGN.md` est déclaré, clair et sombre | Le test nomme le jeton manquant |
| Variante sombre orpheline | Un rôle déclaré sans son `-dark` | Échec | Le test nomme le rôle |
| Rebranding | `--primary` redéfini dans `brand.css` | Boutons, liens, statut « Terminé » suivent ; `theme.css` intact | N/A |
| Couleur en dur | `style="color:#fff"` ou `text-red-500` dans un template | Échec | Le test nomme le fichier et la ligne |
| Deux kits | Un composant d'un autre kit copié | Échec | Le test nomme le composant |
| Build sans Node | `tailwind:build` sur un poste sans npm | CSS produit | N/A |
| Binaire non épinglé | `binary_version` absente | Échec | Le test nomme le fichier |
| Audit JS | `make audit` | `importmap:audit` s'exécute | Sort en 1 sur un avis connu |

</frozen-after-approval>

## Code Map

- `composer.json:8-23` — ni `symfony/asset-mapper`, ni `symfony/ux-*`, ni
  `symfonycasts/tailwind-bundle`. Versions disponibles vérifiées : asset-mapper 7.4.x,
  UX 3.4.0, tailwind-bundle 1.0.0 — la table Stack de l'architecture, à la lettre.
- `Makefile:66-76` — le commentaire de la cible `audit` nomme cette story et dit ce
  qu'il faut y faire. `# qa-category:` lignes 16-20 : ne pas ajouter de catégorie.
- `.github/workflows/ci.yml:180-186` — même commentaire, même engagement, job « Linters
  and audits ».
- `tests/Core/Quality/QualityGateParityTest.php:98-113` — `INVOCATIONS` ; y ajouter
  `importmap:audit` sous « Linters and audits ». `GateFiles.php` fournit `read()`,
  `recipe()`, `runSteps()` ; `SandboxTrait` fournit le bac à sable jetable.
- `tests/Core/BoundaryTest.php` — le précédent : tester un fichier de configuration en
  le lisant, et l'outil en l'exécutant dans un bac à sable.
- `templates/base.html.twig` — gabarit de la recette, blocs `stylesheets` et
  `javascripts` vides ; y brancher `importmap()` et la feuille, sans plus (1.4 le
  remplace).
- `DESIGN.md` — la matière exacte : rôles et valeurs l.14-79, douze rôles typographiques
  l.80-144, rayons l.145-151, espacement et jetons nommés l.152-174, hauteurs de
  composants l.175-330, contrat de rebranding l.398-409, principe « défauts shadcn »
  l.432-440, paliers Tailwind par défaut l.572-573, mouvement l.647-652.
- **Ne pas toucher :** `src/`, `deptrac.yaml`, `phpstan.dist.neon`,
  `.php-cs-fixer.dist.php`, `config/packages/doctrine*.yaml`, `migrations/`, `_bmad/`,
  `.claude/skills/`, `_bmad-output/planning-artifacts/`.

## Tasks & Acceptance

**Execution :**
- [x] `tests/Core/Theme/` — écrire d'abord et voir rouges : complétude des jetons contre
      la liste de `DESIGN.md`, aucune variante sombre orpheline, aucune couleur en dur
      dans `templates/`, kit unique et bibliothèque d'icônes unique, `binary_version`
      épinglée
- [x] `composer.json` — `symfony/asset-mapper`, `symfony/stimulus-bundle`,
      `symfony/ux-twig-component`, `symfony/ux-icons`, `symfonycasts/tailwind-bundle` ;
      `symfony/ux-toolkit` en `require-dev` (c'est un générateur, pas une dépendance)
- [x] `config/packages/symfonycasts_tailwind.yaml` — `binary_version` épinglée,
      `input_css` explicite
- [x] `config/packages/framework.yaml`, `twig_component.yaml`, `ux_icons.yaml` —
      `asset_mapper` (`missing_import_mode` strict en dev), namespace des composants,
      icônes verrouillées dans `assets/icons/` et téléchargement à la demande coupé hors
      dev
- [x] `assets/styles/theme.css` — tout le thème de `DESIGN.md` en jetons, clair et sombre,
      avec le bloc de commentaire qui documente `--input` et `--ring` comme choix assumés
- [x] `assets/styles/brand.css` — livré quasi vide : les seules variables qu'un dérivé
      redéfinit, chacune commentée avec sa valeur du socle et le rappel de remesurer le
      contraste après changement
- [x] `assets/styles/app.css` — Tailwind, les feuilles du kit, puis `theme.css` et
      `brand.css` dans cet ordre, et rien d'autre
- [x] `assets/app.js`, `importmap.php`, `assets/vendor/` — entrée JS, importmap, vendor
      commité pour un déploiement sans réseau
- [x] `templates/` — brancher la feuille et l'importmap dans `base.html.twig` ;
      `ux:install --kit shadcn` pour `Button`, `Input`, `Label`, `Alert`, `Badge`, `Card`
      (décision 1), relus une fois copiés
- [x] `Makefile`, `.github/workflows/ci.yml`,
      `tests/Core/Quality/QualityGateParityTest.php` — `importmap:audit` sur les deux
      façades, commentaire de dette retiré, `INVOCATIONS` mise à jour
- [x] `.gitignore`, `README.md` — sorties de compilation ignorées, `tailwind:build --watch`
      documenté dans la boucle de développement

**Acceptance Criteria :**
- Given un poste sans npm ni node, when je lance `php bin/console tailwind:build`, then
  le CSS est produit sans étape Node
- Given le thème posé, when un dérivé change `--primary` et `--primary-foreground` en
  clair et en sombre, then tout le reste hérite et aucun autre fichier n'est modifié
- Given `make qa`, when la porte s'exécute, then `importmap:audit` en fait partie et les
  cinq catégories restent au vert
- Given un défaut du kit sous un seuil WCAG, when je relis `assets/styles/app.css`, then
  il est conservé et son commentaire le nomme comme choix assumé

## Implementation Notes

**Le sombre est écrit deux fois, délibérément.** Un `@media` ne peut pas entrer dans une
liste de sélecteurs : suivre `prefers-color-scheme` *et* se laisser forcer par la classe
`dark` demande deux blocs identiques. `light-dark()` aurait supprimé la copie, mais le kit
utilise massivement les modificateurs d'opacité (`bg-primary/80`, `ring-foreground/10`),
qui compilent en `color-mix()` — imbriquer `light-dark()` dedans n'est pas vérifiable sans
navigateur, et un thème qui casse sur les translucides casse partout. La copie est donc
assumée et `the_two_dark_blocks_never_diverge()` lui interdit de dériver.

**`@custom-variant dark` couvre les deux chemins.** Le kit propose
`@custom-variant dark (&:is(.dark *))`, qui ignore `prefers-color-scheme`. La version du
socle déclare les deux, et la sortie de `tailwind:build` confirme que chaque utilitaire
`dark:` est émis en double — `@media (prefers-color-scheme: dark) … :where(:not(.light, .light *))`
et `:where(.dark, .dark *)`.

**`source(none)` dans `app.css`.** La détection automatique de Tailwind 4 remonte jusqu'à
la racine du dépôt, qui porte aussi `.claude/skills/` et `_bmad-output/` — où des exemples
de documentation écrivent `text-red-500` et `text-gray-400`. Sans la coupure, la feuille
livrée embarquait les palettes que le socle refuse. Les trois sources réelles
(`templates/`, `src/`, `assets/controllers/`) sont déclarées explicitement.

**Trois paquets Twig en plus de la liste du spec.** `twig/extra-bundle`,
`twig/html-extra` et `tales-from-a-dev/twig-tailwind-extra` : les composants du kit
appellent `html_cva` et `tailwind_merge` à chaque rendu. La recette du troisième vit dans
`recipes-contrib`, que ce projet n'exécute pas, donc sa ligne de `config/bundles.php` est
écrite à la main — `ComponentRenderingTest` échoue en huit points si elle disparaît.

**Le kit n'a aucune configuration où l'épingler.** `symfony/ux-toolkit` n'expose pas
d'extension : le choix du kit ne vit que dans un `--kit` tapé en ligne de commande. Le
test le prouve donc depuis les fichiers copiés — appartenance au catalogue shadcn, et
absence de tout token de classe propre à un kit rival, calculé depuis `vendor/`.

**Écarts kit / DESIGN.md relevés à la relecture des six composants, non corrigés.** Les
hauteurs (`h-8` = 32 px, `h-9` = 36 px contre les 38 px de `DESIGN.md`), les rayons
(`rounded-lg` = 10 px sur Button et Input, contre `{rounded.md}` = 8 px), la taille du
Badge (`h-5` / `text-xs` contre 26 px / 13 px) et son `rounded-4xl` (contre
`{rounded.full}`) viennent du kit. Aucun ne code une couleur en dur ; tous relèvent du
principe « défauts du kit conservés », et les jetons `--spacing-control-height`,
`--spacing-badge-height` et `--radius-md` existent pour que la story 1.4 les referme sur
des écrans réellement rendus plutôt qu'à l'aveugle.

**`asset_mapper` est dans son propre fichier.** La recette de `symfony/asset-mapper` crée
`config/packages/asset_mapper.yaml` plutôt que d'écrire dans `framework.yaml` ; le spec
nommait le second. C'est la même configuration `framework.asset_mapper`, au bon endroit.

**Couture ouverte.** `twig_component.defaults` est une table namespace → répertoire, sans
glob : un module du client qui aura besoin d'un composant **avec classe** devra ajouter
une ligne à `config/packages/twig_component.yaml`. C'est le seul point de câblage des deux
racines qui ne se fasse pas par glob. Rien n'est écrit d'avance.

**Ajouts à la vérification, après relecture du diff.** Trois choses manquaient.

1. *La porte ne construisait pas le thème.* En environnement de test, le compilateur du
   bundle Tailwind s'efface quand la feuille compilée manque — `strict_mode` vaut `false`
   en test — et `@import 'tailwindcss'` retombe alors sur AssetMapper, qui ne connaît pas
   cet asset et échoue en `missing_import_mode: strict`. Sur un clone frais, donc sur
   chaque runner de CI, tout test fonctionnel qui rend une page tombait. La suite était
   verte ici parce qu'un `var/tailwind/app.built.css` traînait du développement.
   `php bin/console tailwind:build` entre dans la cible `test` et dans le job « Tests »,
   et `INVOCATIONS` le nomme pour qu'il ne puisse pas disparaître d'une seule façade.
   Reproduit en supprimant `var/cache` et la feuille compilée avant de relancer.
2. *Deux lignes de la matrice n'avaient aucun test.* « Build sans Node » et
   « Rebranding » étaient vérifiées par raisonnement, pas par exécution.
   `nothing_in_the_chain_needs_node()` refuse tout `package.json`, lockfile npm,
   `node_modules` ou configuration de bundler ;
   `the_stylesheet_is_really_compiled_by_the_standalone_binary()` supprime la sortie,
   lance réellement le binaire et vérifie que la feuille produite porte un jeton du socle
   et une règle issue des templates — le seul test de ce répertoire qui exécute quelque
   chose ; `every_color_role_is_exposed_inline_so_a_rebrand_cascades()` verrouille le mot
   qui fait tenir tout le contrat de rebranding : `@theme inline`. Sans lui, Tailwind fige
   les valeurs à la compilation et redéfinir `--primary` dans `brand.css` ne repeint rien.
   Les trois vérifiés par mutation.
3. *Un commentaire de la story 1.2 avait disparu.* Une recette Flex a réécrit
   `config/bundles.php` et emporté le bloc qui explique pourquoi la ligne DAMA est écrite
   à la main et qu'elle va par paire avec `phpunit.dist.xml`. Restauré.

**Tour de correctifs après relecture (20 entrées `patch` du triage).** L'agent
d'implémentation n'étant pas ré-adressable dans cette session, ils ont été appliqués
directement, puis la porte relancée depuis un état froid.

Les deux qui comptent :

- *Les icônes Tabler auraient toutes été rendues pleines.* `default_icon_attributes`
  portait `fill: currentColor`, et `Icon::withAttributes()` fusionne les défauts
  **par-dessus** les attributs du SVG source — donc par-dessus le `fill="none"` d'une
  icône de trait. Aucune icône n'étant encore posée, rien ne l'aurait signalé avant la
  story 1.4. Le `fill` est retiré : le SVG dit déjà `currentColor` sur son `stroke`.
- *Le déploiement documenté avortait.* Le README donnait `asset-map:compile` seul. Le
  bundle Tailwind ne s'accroche à aucun événement de compilation : il intercepte la
  feuille d'entrée et sert son résultat, et `TailwindBuilder:153` lève « Built Tailwind
  CSS file does not exist » hors test. La séquence est corrigée, et la promesse
  « aucun réseau sortant » avec elle — `assets/vendor/` est commité, le binaire Tailwind
  se télécharge.

Le reste, par famille. **Kit** : `Card:Title` et `Alert:Title` portaient
`cn-font-heading`, une classe que le kit référence et ne définit nulle part — remplacée
par les rôles `text-heading` et `text-subheading` du socle ; les deux exposent maintenant
une prop `as`, comme `Button` et `Badge`, pour que la story 1.4 puisse leur donner le
`h2` et le `h3` que `DESIGN.md` documente. **Thème** : `--text-stat--font-variant-numeric`
n'était pas émis (Tailwind ne lit que trois modificateurs sur un `--text-*`) — retiré, et
le commentaire renvoie à l'utilitaire natif `tabular-nums` ; `--radius-full` ajouté ;
`--input` était annoncé rebrandable par `theme.css` sans l'être ni dans `REBRANDABLE` ni
dans `brand.css` ; le commentaire du `:focus-visible` prétendait couvrir tous les
éléments interactifs alors que `@layer utilities` passe après `@layer base` et que
`outline-none` du kit le remplace réellement. **Vérification** : le vendor JavaScript est
maintenant prouvé fichier par fichier contre `importmap.php` et `installed.php`, et non
plus par une ligne de `.gitignore` que `importmap:install` contourne à chaque
`composer install` ; `@custom-variant dark` est observé sur la sortie compilée ;
`bg-primary` doit y sortir en `var(--primary)`, ce qui fait passer la ligne « Rebranding »
de la matrice du raisonnement à l'exécution ; `HomeControllerTest` regarde une page
réellement rendue ; `HardcodedColorTest` scanne les trois `@source` et non les seuls
templates ; la garde « kit rival » refuse une signature vide ; le test de compilation
restaure la feuille si le build échoue. **Divers** : `<meta name="viewport">`,
`GITHUB_TOKEN` sur `importmap:audit`, et `property_info.yaml` documenté.

Les cinq mutations qui comptent ont été rejouées : `@custom-variant` ramené au one-liner
du kit, marque redéfinie en clair seulement, dépendance JavaScript décommitée,
`importmap()` retiré du gabarit, `@theme inline` ramené à `@theme` — chacune fait échouer
le test qui la vise, et lui seul.

Huit trouvailles sont consignées en report plutôt que corrigées, dont trois que la story
1.4 rencontrera de front : les rôles typographiques que le kit ne consomme pas,
`--sidebar-accent-foreground` que `DESIGN.md` ne donne pas, et le premier composant
anonyme propre au socle, que `KitIntegrityTest` refusera.

## Spec Change Log

## Review Triage Log

Passe 1 — quatre couches : blind-hunter (16), edge-case-hunter (15), verification-gap
(3 + 5), proglab-conformance (2). Les trouvailles de verification-gap arrivent
pré-vérifiées ; les autres ont été vérifiées à l'emplacement cité avant verdict.

| # | Couche | Trouvaille | Verdict | Évidence | Route |
|---|---|---|---|---|---|
| 1 | blind | `default_icon_attributes: fill: currentColor` écrase le `fill="none"` des icônes Tabler | `high` | Vérifié dans `Icon.php:143` — `[...$this->attributes, ...$attributes]` : le défaut prime sur l'attribut du SVG source. Chaque icône de trait serait rendue pleine, à partir de la story 1.4 | patch |
| 2 | blind + conf | `cn-font-heading` de `Card/Title.html.twig` n'est définie nulle part | `medium` | Vérifié : absente de `theme.css`, `brand.css`, des feuilles du kit et de la sortie compilée. Les titres de carte tombent sur `text-base` au lieu de `--text-heading` | patch |
| 3 | conf | `Card:Title` et `Alert:Title` rendent un `<div>` sans prop `as`, là où `Button` et `Badge` en exposent une | `medium` | Vérifié dans les quatre fichiers ; `DESIGN.md` les documente comme `h2` et `h3`. Sans `as`, la story 1.4 ne peut pas leur donner un niveau de titre | patch |
| 4 | edge | `--text-stat--font-variant-numeric` n'est pas un modificateur Tailwind : rien n'est émis | `medium` | Reproduit ici : `text-stat` posée sur un template puis rebuild — `.text-stat` sort en font-size / line-height / font-weight seulement | patch |
| 5 | edge | Le déploiement documenté avorte : `asset-map:compile` sans `tailwind:build` préalable | `medium` | Vérifié : aucun listener d'événement dans le bundle, et `TailwindBuilder:153` lève « Built Tailwind CSS file does not exist » — `strict_mode` vaut true hors test | patch |
| 6 | edge | « aucun réseau sortant » est une sur-promesse : le binaire Tailwind se télécharge au premier build | `medium` | Vrai. Le README corrige sa formulation ; la question du serveur client réellement coupé du réseau appartient à la story 3.1 | patch + defer |
| 7 | blind | `outline-none` de Button/Input bat le `:focus-visible` du socle, contrairement à ce que dit le commentaire | `medium` | Vérifié dans la sortie compilée : l'ordre déclaré ligne 3 met `utilities` après `base`. Le remplacement `focus-visible:ring-3` existe — la règle 4 est tenue, c'est le commentaire qui est faux | patch |
| 8 | vgap | `theme.css` dit qu'un dérivé change `--input` dans `brand.css` ; `REBRANDABLE` ne le contient pas et `brand.css` ne l'offre pas | `medium` | Vérifié aux trois endroits : un dérivé qui suit le commentaire fait échouer `the_brand_sheet_ships_empty…` | patch |
| 9 | vgap + blind | `assets/vendor/` n'est prouvé que par une ligne de `.gitignore` | `medium` | Pré-vérifié par la couche, confirmé : `composer.json:85` ajoute `importmap:install` aux auto-scripts, donc la CI reconstruit le répertoire depuis le réseau et un vendor non commité reste vert | patch |
| 10 | vgap | `@custom-variant dark` — la ligne porteuse du sombre à deux chemins — n'est observée par aucun test | `medium` | Pré-vérifié : la remplacer par le one-liner du kit ne fait échouer aucune assertion ; les deux formes existent aujourd'hui dans la sortie compilée | patch |
| 11 | vgap | Rien de rendu ne prouve que la feuille et l'import map atteignent une page | `medium` | Pré-vérifié : `the_base_template_renders_the_importmap` fait une recherche de sous-chaîne dans le fichier source ; un bloc surchargé sans `parent()` passerait | patch |
| 12 | blind + vgap | `HardcodedColorTest` ne scanne que `templates/`, alors que `app.css` déclare aussi `src/` et `assets/controllers/` en `@source` | `medium` | Vérifié dans les deux fichiers ; `assets/controllers/` est déjà peuplé | patch |
| 13 | edge + vgap | Le test de compilation supprime la feuille sans la restaurer si le build échoue | `medium` | Réel, et c'est du code ajouté à la relecture du diff : une panne de téléchargement transformerait un échec en cascade sur tout test fonctionnel ultérieur | patch |
| 14 | edge | La garde « kit rival » de `KitIntegrityTest:93` peut porter sur une signature vide | `medium` | Vrai : rien n'assure que la signature calculée depuis `vendor/` est non vide, et un contrôle vide passe sur tout | patch |
| 15 | blind + edge | `importmap:audit` sans `GITHUB_TOKEN` : 60 requêtes/h par IP sur runner partagé | `medium` | Vrai. La porte deviendrait rouge pour une raison sans rapport avec le changement | patch |
| 16 | blind | `templates/base.html.twig` n'a pas de `<meta name="viewport">` | `medium` | Vrai, et le fichier est touché par cette story. Sans lui, un navigateur mobile rend à 980 px et les paliers responsive sont inertes. Même raisonnement que le `lang` déjà présent | patch |
| 17 | blind + conf | `config/packages/property_info.yaml` entre sans un mot d'explication | `low` | Vrai : seul fichier de configuration du diff sans commentaire d'en-tête. Reliquat de recette Flex. Correction directe | patch |
| 18 | edge | `rounded.full` (9999 px) de `DESIGN.md` n'a pas de jeton | `low` | Vrai ; `--radius-full` absent de `theme.css` et de `NAMED_TOKENS`. Ajout d'une ligne | patch |
| 19 | edge | Un dérivé peut ne redéfinir sa marque que dans l'un des deux blocs sombres | `medium` | Vrai, et c'est exactement le piège que le commentaire de `brand.css` annonce. Le test manque | patch |
| 20 | blind | `tailwind:build` est la seule étape de « Tests » sans `--env=test`, alors que son commentaire parle du comportement en test | `low` | Vrai et sans conséquence — la sortie ne dépend pas de l'environnement. Le commentaire se corrige | patch |
| 21 | blind | `--sidebar-accent` est livré sans `--sidebar-accent-foreground` | `medium` | Vrai, mais `DESIGN.md` ne donne pas ce jeton et les Boundaries interdisent d'en inventer un. La story 2.3 le rencontrera | defer |
| 22 | blind | Les douze rôles typographiques ne sont consommés par aucun composant du kit | `medium` | Vrai : les six composants emploient `text-xs` / `text-sm` / `text-base`. Le remap complet est une refonte, pas un correctif | defer |
| 23 | blind | `asset-map:compile` n'est exercé par aucune façade : le chemin `when@prod` n'est jamais parcouru | `medium` | Vrai. Ajouter une étape change la forme de la porte | defer |
| 24 | edge | Le motif hexadécimal de `HardcodedColorTest` attrape `href="#add"` | `low` | Vrai — trois caractères hexadécimaux suffisent. Aucune occurrence aujourd'hui ; le correctif demande un contexte de couleur et un mécanisme de suppression | defer |
| 25 | edge | `KitIntegrityTest` refusera le premier composant anonyme propre au socle | `medium` | Vrai. Le correctif est une liste d'exceptions, qui n'a rien à exclure aujourd'hui | defer |
| 26 | blind | Le binaire Tailwind est retéléchargé à chaque exécution du job « Tests », sans `actions/cache` | `low` | Vrai ; le correctif ajoute un bloc de cache à maintenir | defer |
| 27 | blind | `--motion-fade` et `--motion-panel` ne sont dans aucun namespace Tailwind | `low` | Vrai : aucun utilitaire généré. Sans conséquence — ils se lisent en valeur arbitraire, et c'est ce que fera la story 1.4 | defer |
| 28 | blind + edge | Les composants du kit codent en dur des tailles et des rayons, contre la Boundary « aucune taille ou rayon codé en dur » | `medium` | Réel, mais le correctif consiste à amender le bloc gelé du spec — rejet explicitement prescrit. Porté à la présentation | rejeté |
| 29 | blind | `sprint-status.yaml` dit `in-progress` pendant que le spec dit `in-review` | `low` | Vrai : la synchronisation du suivi appartient à l'étape de présentation, pas à la relecture | rejeté |
| 30 | edge | Un jeu d'icônes passé par variable échapperait au contrôle de bibliothèque unique | `low` | Spéculatif, et le correctif interdirait toute composition dynamique | rejeté |
| 31 | edge | La liste d'artefacts Node est figée et limitée à la racine | `low` | Un bundler arrive avec son `package.json`, déjà refusé ; le correctif scanne tout le dépôt pour un gain nul | rejeté |
| 32 | edge | `KitIntegrityTest::componentsOf()` sans `depth` fait entrer des noms de répertoires dans le catalogue | `low` | Vrai, et corrigé avec la trouvaille 14 — même fichier, même cause | patch |
| 33 | edge | Une orthographe voisine dans `.gitignore` re-ignorerait `assets/vendor/` | `low` | Vrai, et refermé par la trouvaille 9 : vérifier les fichiers plutôt que la ligne d'exclusion | patch |
| 34 | edge | La ligne « Rebranding » de la matrice n'est vérifiée que par lecture de source | `medium` | Vrai : le correctif observe la cascade sur la sortie compilée — `bg-primary` doit sortir en `var(--primary)` | patch |
| 35 | vgap | `HomeControllerTest` n'observe aucun asset | `medium` | Doublon de 11 | patch |

## Design Notes

**Pourquoi tester une feuille de style.** Un thème ne casse pas, il se troue : un rôle
ajouté sans sa variante sombre, une couleur écrite en dur dans un écran pressé, un
second kit installé « juste pour ce composant ». Les trois se lisent dans des fichiers,
donc trois tests les tiennent — le précédent est `BoundaryTest`, qui teste `deptrac.yaml`
en le lisant.

**Ce que la story ne peut pas prouver.** Un contraste se mesure sur des pixels rendus, et
il n'y a pas de navigateur ici. Les ratios de `DESIGN.md` sont repris tels quels ; la
vérification automatisée du plancher WCAG appartient à la story 1.4, qui l'ajoute comme
sixième catégorie de la porte.

## Verification

**Commands :**
- `composer install` — aucun conflit, aucun paquet exigeant Symfony 8
- `php bin/console tailwind:build` — CSS produit, sans npm
- `php bin/console debug:asset-map` — la feuille et l'entrée JS sont dans la map
- `php bin/console lint:twig templates/` — valide
- `vendor/bin/phpunit` — suite au vert, après avoir été vue rouge
- `make qa` — les cinq catégories passent, `importmap:audit` inclus
