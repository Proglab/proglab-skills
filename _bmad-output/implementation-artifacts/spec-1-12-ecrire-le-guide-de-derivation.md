---
title: 'Écrire le guide de dérivation'
type: 'feature'
created: '2026-09-16'
status: 'done'
route: 'dispatch'
review_loop_iteration: 1
baseline_commit: '778623fab8360ef0cf481fd0daa6372eb26a21d9'
context:
  - '{project-root}/.claude/skills/symfony-proglab-testing/SKILL.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** aucun document ne dit comment le socle est organisé ni où ajouter un
module. `README.md` couvre la porte de qualité et la boucle locale ; le raisonnement vit
dans `_bmad-output/planning-artifacts/` et dans des commentaires de `config/` et de
`deptrac.yaml`. FR-2 promet une seule source, grâce à laquelle un agent livre du code
conforme sans demander à personne.

**Approche :** un guide unique, `docs/DERIVATION.md`, qui répond à « où va cette classe »
de bout en bout, renvoie aux artefacts BMAD pour le *pourquoi* seulement, et nomme ce
qu'aucun autre fichier ne porte : la convention d'en-tête YAML que la story 3.2 lira, la
sauvegarde comme responsabilité du serveur client, la limite d'AD-4 sur les emails déjà
en file. Un test le garde honnête sur ses faits vérifiables.

## Boundaries & Constraints

**Always :**

- Le guide est autosuffisant pour « où va cette classe » ; les renvois aux AD justifient,
  ils ne complètent pas.
- Chaque fait vérifiable du guide a une source dans le dépôt, et le test garde l'accord.
- Le guide nomme les skills `symfony-proglab-*` et les workflows BMAD par leur nom exact,
  sans recopier leur contenu.
- Ancrages amont nommés : AD-2, AD-4, AD-7, AD-10, AD-13, AD-22, NFR-6, UX-DR-2.
- Le test s'écrit d'abord et se voit rouge, guide absent.
- **`ModuleWiringTest::the_module_root_ships_empty()` ne garde que la livraison du
  socle** : la recette « ajouter un module » dit qu'au premier module, le dérivé supprime
  ce test ; le socle ne change pas (Fabrice, 2026-09-16).
- **Le rebranding (favicon, `h1` en `app_name`, `BaseTemplateTest`) est hors de cette
  story** : découpé vers `deferred-work.md` (Fabrice, 2026-09-16). Le guide décrit la
  surface cible et liste ce reste en limite assumée.

**Never :**

- Aucune modification de `src/`, `config/`, `deptrac.yaml`, `templates/` ni
  `composer.json` : une trouvaille se remonte dans `deferred-work.md`.
- Aucun module d'exemple dans `src/Module/` ; l'exemple est `tests/Fixtures/Module/Demo/`.
- Aucune nouvelle cible `make` ni job CI.
- Le guide ne redit pas le README ; il y renvoie.

</frozen-after-approval>

## Decisions

- **Guide en `docs/DERIVATION.md`**, lien d'entrée depuis `README.md` (`docs/` est déjà
  le `project_knowledge` de `_bmad/bmm/config.yaml`).
- **En-tête FR-14 dans un fichier léger par story**,
  `_bmad-output/implementation-artifacts/stories/{n}-{m}-{slug}.md`, en-tête YAML seul :
  `priority` (`high`/`medium`/`low`), `difficulty` (`S`/`M`/`L`), `assignee` (libre),
  `depends_on` (clés `{n}-{m}`). Absent ⇒ « non renseigné » ; `depends_on` absent ⇒ prête
  dès « à faire ».
- **Surface de rebranding décrite** : deux choses — les variables `oklch` de
  `assets/styles/brand.css`, et la marque graphique (logo de la sidebar, favicon sous
  `assets/brand/` à venir).

## Code Map

- `README.md:15-17` (point d'insertion du lien), `:300-328` (tableau des 18 skills) — seule
  édition : le lien vers le guide.
- `config/services.yaml:49-51` (tag `app.module`), `:70` vs `:75` (exclusions socle /
  module) ; `config/routes.yaml:15` (`module_controllers`) ;
  `config/packages/doctrine.yaml:28-33` (mapping `Module`) ;
  `config/packages/translation.yaml:30` — les quatre globs, chacun doublé d'un miroir
  `when@test`.
- `deptrac.yaml:31-37` (coût d'un module en commentaire), `:146-186` (couches de racine,
  filet `UndeclaredModule`), `:195-226` (`AnyRoot`), ruleset `Dto:` (`Dto`, `Enum`,
  `+AnyRoot` — pas `Entity`), `:338-358` (rulesets de racine).
- `src/Core/Contract/ModuleDescriptor.php`, `src/Core/Service/ModuleRegistry.php` — la
  seule porte publique ; ne rien y ajouter.
- `tests/Fixtures/Module/Demo/` (exemple vivant ; **n'a ni `Enum/`, ni `Message/`, ni
  `MessageHandler/`**) et `tests/Fixtures/Module/Billing/`.
- `tests/Core/ModuleWiringTest.php:139-146` — `the_module_root_ships_empty()`.
- `tests/Core/Quality/GateFiles.php:19-28` — lecteur à réutiliser (lève si le fichier
  manque) ; `tests/Core/Theme/ThemeTokensTest.php:21-30` — patron du saut quand un
  artefact BMAD est absent.
- `_bmad-output/implementation-artifacts/sprint-status.yaml:10-15` — bloc
  `# Story Status:`.
- `deferred-work.md` — la dernière entrée (rebranding) est la limite que le guide cite.
- **Première version conservée hors dépôt** : `$HOME/keep-1-12/` sur la machine
  (`DERIVATION.md`, `DerivationGuideTest.php`, en-tête 1-13) — base de ré-implémentation,
  à corriger selon le Spec Change Log.

## Tasks & Acceptance

**Execution :**

- [x] `tests/Core/Documentation/DerivationGuideTest.php` — écrit d'abord, vu rouge.
      `extends TestCase`, lit par `GateFiles`. Quatre familles d'ancres : (a) chaque
      dossier balayé sous `src/Core/` ; (b) les quatre globs, lus dans le YAML parsé à
      leur clé de production, et leurs fichiers nommés ; (c) pour chaque module de
      fixture, les quatre éditions **vérifiées dans la structure parsée de
      `deptrac.yaml`** (couche `Module<Nom>` et collecteur, ruleset
      `['+AnyLayer', 'Module<Nom>', 'CoreContract']`, entrée d'`AnyRoot`, exclusion du
      `must_not` d'`UndeclaredModule`) et nommées dans le guide ; (d) statuts de story et
      quatre champs d'en-tête nommés, **famille sautée proprement si `_bmad-output/` est
      absent**. Le docblock dit exactement quelles ancres sont entre accents graves.
- [x] `docs/DERIVATION.md` — dans l'ordre : public et hors-champ ; deux racines et
      contrat de couches ; où va chaque type de classe (exemple Demo) ; recette « ajouter
      un module » (tests en première étape, quatre éditions deptrac, retrait de
      `the_module_root_ships_empty()`) ; nommage ; rebranding ; ce qu'un dérivé
      configure ; skills et workflows ; en-tête YAML des stories ; sauvegarde et
      restauration ; limites assumées.
- [x] `README.md` — le lien d'entrée vers le guide, rien d'autre.
- [x] `_bmad-output/implementation-artifacts/stories/1-13-rendre-la-deconnexion-joignable-sans-jeton.md`
      — la convention appliquée à une story réelle.
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` — une entrée pour la
      découverte des services des modules (`config/services.yaml:75` n'exclut que
      `{Dto,Entity}`). Ne modifier aucune entrée existante.

**Acceptance Criteria :**

- Given un agent chargé d'ajouter une entité métier, when il lit le seul guide, then il
  en tire emplacement, namespace, forme et couches autorisées sans ouvrir d'artefact BMAD.
- Given un dérivé, when il suit la recette à la lettre sur un module neuf, then `make qa`
  passe.
- Given `vendor/bin/phpunit`, when un dossier de couche, un glob, une édition deptrac
  d'un module de fixture ou un statut change sans que le guide suive, then
  `DerivationGuideTest` rougit en nommant l'ancre perdue.
- Given un dérivé sans `_bmad-output/`, when la suite tourne, then `DerivationGuideTest`
  passe ou se saute, sans erreur.
- Given `make qa`, then les six catégories passent sans cible ni job ajouté.

## Implementation Notes

- **2026-09-16** — Exécution PHP impossible dans l'environnement d'implémentation (ni
  PHP 8.5 ni MySQL). Le rouge puis le vert du test ont été vus en **rejouant ses
  assertions en Python** : 32 cas en erreur sans `docs/`, 32 verts avec le guide, et
  sept mutations (dossier ajouté sous `src/Core/`, glob de production du translator
  retiré, glob de services changé, statut renommé, « cinq éditions » dans
  `deptrac.yaml`, deux ancres retirées du guide) rougies chacune en nommant l'ancre.
  **Restent à exécuter par un humain** : `vendor/bin/phpunit --filter
  DerivationGuideTest`, la suite complète, `make qa`, et la recette « ajouter un
  module » sur un module jetable (AC 2 et 4).
- Les ancres du guide sont cherchées entre accents graves (`review` est déjà dans
  `bmad-code-review`) ; les globs sont lus dans le YAML parsé, à la clé de production,
  pour que le miroir `when@test` ne masque pas une disparition.

- **2026-09-16 (boucle de revue 1)** — Code annulé après deux `intent_gap` (R1, R2),
  tranchés par Fabrice. Une copie de la première version du guide, du test et du fichier
  d'en-tête est conservée hors dépôt pour la ré-implémentation.

- **2026-09-16 (découpage)** — Le rebranding est sorti vers `deferred-work.md` ; la spec
  est régénérée sur le guide seul.

- **2026-09-16 (ré-implémentation après découpage)** — Repartie de `$HOME/keep-1-12/`,
  corrigée selon R3-R11. Toujours sans PHP : rouge et vert vus en **rejouant les
  assertions en Python** (PyYAML). Sans `docs/` : 35 cas en erreur sur 37 ; les deux
  verts sont `a_fixture_module_costs_nothing_else_in_deptrac`, qui ne lit pas le guide
  et dont le rouge a été vu par mutation. Avec le guide : 37 verts. Treize mutations sur
  une copie hors dépôt, chacune rouge en nommant l'ancre : dossier ajouté sous
  `src/Core/`, glob de production du translator retiré (miroir gardé), glob de services
  changé, statut renommé, cinquième édition deptrac (`skip_violations`), module retiré
  d'`AnyRoot`, ruleset amputé, exclusion `must_not` retirée, collecteur changé, trois
  ancres retirées du guide, module de fixture ajouté sans éditions ; sans
  `_bmad-output/`, la famille (d) se saute (2 sautés, 28 verts).
  Choix faits : toutes les ancres cherchées dans le guide sont entre accents graves,
  globs compris (R11 réglé par la forme, pas seulement par le docblock) ; la famille (c)
  ajoute un cinquième test qui compte les occurrences d'un module dans la structure
  parsée (4 noms, 2 collecteurs), pour qu'une cinquième édition rougisse aussi ; la
  famille (d) se saute par un cas sentinelle `null` et `markTestSkipped()`, plutôt que
  par `skipWhenEmpty`, pour que le saut soit visible. Le guide impose l'ordre des
  éditions deptrac (exclusion `must_not` en dernier), parce que deptrac ne valide pas
  qu'un ruleset nomme une couche déclarée (`LayerProvider`). Deux entrées ajoutées à
  `deferred-work.md` : la découverte des services des modules (R12) et les commentaires
  de `deptrac.yaml` (l. 36-39, 173-175) que R6 contredit. **Restent à exécuter par un
  humain** : `vendor/bin/phpunit --filter DerivationGuideTest`, la suite, `make qa`
  (PHPStan niveau max et php-cs-fixer n'ont pas tourné sur le nouveau test), et la
  recette sur un module jetable.

- **2026-09-16 (correctifs de revue du coordinateur)** — Guide : `Stock` interdit comme
  nom de module (`BoundaryTest` l'emploie comme module non déclaré) et remplacé par
  `Catalog` dans les exemples ; nouvelle étape 7, l'échantillon d'une route GET à
  paramètre dans `tests/Core/Accessibility/RouteSamples.php` (ajouté aux fichiers que la
  recette modifie) ; `<page> — <nom du dérivé>` entre accents graves ; pièges de
  migration du skill `symfony-proglab-doctrine` ; restauration réordonnée (corrections
  de comptes, file purgée des messages vers un compte réanonymisé, trafic rouvert en
  dernier), effets ajoutés (anonymisation perdue, compte créé disparu) et dump chiffré
  hors serveur ; `+intl-icu` ne change pas le domaine ; convention d'en-tête et statuts
  présentés comme le contrat de la story 3.2 ; règle d'état « jamais une exclusion
  `must_not` sans son bloc de couche ». Test : le comptage des éditions deptrac porte
  sur les clés et valeurs **égales** au nom de couche et au collecteur, en parcours
  récursif (plus de sous-chaîne) ; les statuts se sautent aussi quand seul
  `sprint-status.yaml` manque. Rejeu Python : 37 verts ; un `ModuleDemographics` ajouté
  à `AnyRoot` ne compte plus pour `Demo` ; une cinquième édition exacte et un collecteur
  doublé rougissent ; sans `sprint-status.yaml`, les statuts se sautent.

## Spec Change Log

- **2026-09-16** — Résolution des deux Open Questions : guide en `docs/DERIVATION.md`
  (lien d'entrée depuis `README.md`) ; en-tête YAML des quatre champs FR-14 dans un
  fichier léger par story sous `_bmad-output/implementation-artifacts/stories/`. Décidé
  par Fabrice. Section renommée `Decisions`.
- **2026-09-16 — boucle de revue 1 (R1, R2 `intent_gap` ; R3-R11 `patch` reportés).**
  Déclencheurs : la recette ne pouvait pas passer `make qa` dans un dérivé
  (`the_module_root_ships_empty()`), et le report rebranding de la 1.11 était ignoré.
  Amendé : bloc gelé (deux décisions de Fabrice), Tasks (famille (c) structurelle,
  garde `_bmad-output/`, gabarits, `BaseTemplateTest`, `deferred-work.md`), AC, Code
  Map, Decisions. État évité : un guide qui promet un `make qa` vert qu'il ne peut pas
  tenir, et un test qui casse la suite d'un dérivé.
  **KEEP** (à reprendre de la première version) : la structure et le ton du guide ; le
  tableau « Où va chaque type de classe » avec sa colonne « Peut dépendre de » tirée du
  ruleset ; l'exemple « ajouter une entité » ; les blocs YAML des quatre éditions deptrac
  (`Module<Nom>`) ; l'inventaire de configuration ; la procédure de restauration en six
  étapes ; les globs lus dans le YAML parsé ; les ancres entre accents graves ; les
  trois limites vraies (AD-4, envoi d'email par un module, `LIFETIME`).
  **Correctifs à intégrer (R3-R11)** : pas de `fromEntity()` sur un DTO — un mapper dans
  `Service/` ; ne pas dire qu'un dérivé peut supprimer `_bmad-output/` (la roadmap Epic 3
  le lit) ; oublier le bloc de couche en ayant ajouté l'exclusion de `UndeclaredModule`
  **ouvre** la frontière — le dire ; la restauration arrête aussi le trafic web et
  signale qu'un mot de passe changé après la sauvegarde revient à l'ancien ; les tests
  du module s'écrivent en première étape de la recette ; le domaine de traduction et le
  dossier `templates/<module>/` ne doivent pas entrer en collision (`messages`,
  `security`, `validators`, `emails`, dossiers du socle) ; `Demo` n'a pas tous les
  dossiers de couche ; le docblock du test dit exactement quelles ancres sont entre
  accents graves.
- **2026-09-16 — découpage à la replanification.** La spec amendée dépassait 8 000
  tokens. Fabrice a choisi de découper : le rebranding (favicon via `symfony/asset`,
  `h1`, `BaseTemplateTest`) part dans `deferred-work.md` ; le bloc gelé, les Tasks et les
  AC sont régénérés pour le guide seul. Les correctifs R3-R11 et le KEEP de l'entrée
  précédente restent valables, sauf ce qui touche aux gabarits.

## Review Triage Log

Revue 1 (2026-09-16) — couches : blind-hunter, edge-case-hunter, verification-gap, proglab-conformance.

| # | Constat (source) | Verdict | Preuve | Route |
|---|---|---|---|---|
| R1 | La recette « ajouter un module » ne peut pas passer `make qa` dans un dérivé : `ModuleWiringTest::the_module_root_ships_empty()` refuse tout dossier sous `src/Module/` (edge-case) | high | `tests/Core/ModuleWiringTest.php:139-146` n'admet que `.gitkeep` ; l'étape 1 de la recette crée `src/Module/<Nom>/`. La contrainte gelée (« `src/Module/` reste vide ») et l'AC 2 se contredisent pour un dérivé ; plusieurs issues possibles. | intent_gap |
| R2 | Le report de la 1.11 (favicon, `<h1>` en `{{ app_name }}`, extension de `BaseTemplateTest`) n'est ni fait ni re-reporté (blind) | medium | `deferred-work.md:887-903` l'attribue à la 1.12 sur décision de Fabrice (2026-09-15) ; l'intention gelée dit « documente, ne déplace pas » et n'en parle pas. | intent_gap |
| R3 | Le guide conseille `static fromEntity()` sur un DTO Output, que deptrac refuse (conformance) | medium | Ruleset `Dto` de `deptrac.yaml` : `Dto`, `Enum`, `+AnyRoot` — pas `Entity`. `docs/DERIVATION.md` contredit son propre tableau. | patch |
| R4 | Famille (c) : le test ne lit que le commentaire d'en-tête de `deptrac.yaml`, jamais sa structure (verification-gap, blind, edge-case) | medium | Les cinq ancres ne sont que dans les commentaires (l. 31-34, 173) ; renommer `AnyRoot` dans le YAML actif laisse le test vert. | patch |
| R5 | `DerivationGuideTest` casse la suite d'un dérivé qui supprime `_bmad-output/`, ce que le guide autorise (verification-gap, blind, edge-case) | medium | `lesStatutsDeStory()` appelle `GateFiles::read()` sans garde ; `GateFiles::read` lève (l. 19-25). De plus la roadmap (Epic 3) lit ces fichiers : la phrase du guide est fausse. | patch |
| R6 | Le guide affirme qu'oublier *l'une* des quatre éditions deptrac ne rouvre rien (edge-case) | medium | Exclure le module de `UndeclaredModule` sans déclarer son bloc de couche le laisse sans couche de racine ; sa couche technique hérite `+AnyRoot` et atteint `Core` sans violation. `deptrac.yaml:173` ne le dit que de l'oubli du `must_not`. | patch |
| R7 | La restauration n'arrête pas le trafic web et ne signale pas qu'un mot de passe changé après la sauvegarde revient à l'ancien (edge-case) | medium | Procédure du guide, étapes 1 et 5 : seul le worker est arrêté ; seuls deux effets sont listés. | patch |
| R8 | La recette place « écrivez les tests d'abord » à l'étape 6, après le code (blind) | medium | Ordre des étapes du guide, en contradiction avec la règle 1 et `symfony-proglab-testing`. | patch |
| R9 | Aucune règle contre les collisions de domaine de traduction (`messages`, `security`, `validators`, `emails`) ni de dossier de templates (blind, edge-case) | low | Domaine = nom du module en minuscules ; un module `Security` fusionnerait ses clés avec le domaine Symfony. Correction : une phrase. | patch |
| R10 | Le guide dit que `Demo` a « tous les dossiers de couche » (edge-case) | low | `tests/Fixtures/Module/Demo/` n'a ni `Enum/`, ni `Message/`, ni `MessageHandler/`. | patch |
| R11 | Le docblock du test dit les ancres cherchées entre accents graves, mais les globs et trois ancres deptrac sont nues (edge-case) | low | `DerivationGuideTest.php`, constantes `MODULE_GLOBS` et `DEPTRAC_MODULE_COST`. Correction directe du docblock. | patch |
| R12 | La limite « découverte des services des modules » n'est remontée que dans le guide, pas dans `deferred-work.md` (blind) | medium | `config/services.yaml:70,75` ; l'intention dit « trouvaille à remonter ». Défaut de `config/`, antérieur à la story. | defer |
| R13 | Le rouge de `DerivationGuideTest` n'a jamais été vu sous PHPUnit ; `make qa` non lancé (conformance, verification-gap, blind) | medium | Implementation Notes : rejeu Python seulement (pas de PHP dans l'environnement). | vérification humaine avant fusion |
| R14 | Nouveaux fichiers en mode `100755` (verification-gap, blind) | false | `core.filemode = false` ; l'index enregistre `100644` (ex. `README.md`). Le mode vient du montage, pas du commit. | rejeté |
| R15 | Statut de la spec (`in-review`) et de `sprint-status.yaml` (`in-progress`) divergent (blind) | false | État transitoire : l'étape 5 synchronise le sprint. | rejeté |
| R16 | La spec dit « treize » dossiers (il y en a 14) ; le Code Map cite `app.css` pour UX-DR-2 (blind, edge-case) | low | Vrai, mais le correctif est une édition de la spec. | rejeté |
| R17 | `bmad-build` lirait le fichier d'en-tête comme spec et l'écraserait (blind, edge-case) | low | Chemin atteignable seulement avec `{spec_folder}/stories.yaml`, absent de ce dépôt ; correctif = édition des Decisions. | rejeté |
| R18 | Famille (b) : côté guide, le glob de services est préfixe de celui des routes, et celui du translator égale celui de Doctrine (verification-gap, edge-case) | low | Vrai ; ne rougit pas si une seule ligne du tableau disparaît. Improbable, et la parade ajoute une logique d'appariement. | rejeté |
| R19 | Famille (b) ne vérifie pas `exclude` de `services.yaml` (blind, edge-case) | low | Vrai ; la spec fixe les quatre familles, l'ajout élargit le contrat. | rejeté |
| R20 | Regex des statuts : chiffres, `_`, majuscules ; ligne vide supprimée entre blocs (edge-case) | low | Improbable dans le fichier généré par BMAD. | rejeté |
| R21 | Valeurs hors liste de `priority`/`difficulty`, règles de `depends_on` présent (edge-case) | low | Le comportement du lecteur relève des AC de la story 3.2 (`epics.md:1085-1089`), pas de la convention. | rejeté |

Revue 2 (2026-09-16, après découpage) — mêmes quatre couches.

| # | Constat (source) | Verdict | Preuve | Route |
|---|---|---|---|---|
| R22 | Un dérivé qui nomme son module `Stock` (l'exemple du guide) fait rougir `BoundaryTest` (edge-case) | high | `tests/Core/BoundaryTest.php:115-129` recopie `deptrac.yaml` et pose `src/Module/Stock` comme module **non déclaré** ; déclaré, il sort `(ModuleStock on Core)`. Le guide n'interdit que `Demo`/`Billing`. | patch |
| R23 | Une route GET paramétrée de module fait échouer `make a11y` sans échantillon dans `RouteSamples` ; la recette n'en parle pas (edge-case) | medium | `AccessibilityFloorTest.php:803-835` balaie toute route `App\` hors fixtures ; `RouteSamples::for()` rend `null` par défaut, échec nommé. | patch |
| R24 | `a_fixture_module_costs_nothing_else_in_deptrac` compte des sous-chaînes : `ModuleDemographics` fait compter `ModuleDemo` en trop (verification-gap, edge-case, blind) | medium | `substr_count` sur le JSON entier ; un nom préfixé est permis par la recette. | patch |
| R25 | `« <page> — <nom du dérivé> »` hors accents graves disparaît au rendu Markdown (blind) | low | Balises HTML inconnues supprimées par les rendus GitHub. Correction directe. | patch |
| R26 | Famille (d) : `_bmad-output/` présent mais `sprint-status.yaml` absent ⇒ erreur du provider (edge-case, blind) | low | `lesStatutsDeStory()` teste le dossier, pas le fichier. Correction directe (tester le fichier). | patch |
| R27 | La relecture des migrations omet les pièges du skill doctrine (renommage généré en DROP+ADD, `NOT NULL` sur table peuplée, type tronquant, `down()` impossible) (conformance) | low | `docs/DERIVATION.md`, exemple d'entité. Correction : une phrase et un renvoi à `symfony-proglab-doctrine`. | patch |
| R28 | Restauration : ordre contradictoire (redésactiver les comptes « avant de rouvrir le trafic » après l'avoir rouvert), effets manquants (anonymisations et créations postérieures), dump non protégé (blind) | medium | Étape 5 du guide ; l'anonymisation (story 4.8) revenue est une fuite de données personnelles. | patch |
| R29 | La règle de collision de domaine ignore le suffixe `+intl-icu` (`security+intl-icu` = domaine `security`) (blind) | low | `translations/security+intl-icu.*.yaml`. Correction : une phrase. | patch |
| R30 | Le guide décrit au présent le rendu « à faire » de la roadmap, qui n'existe pas encore (blind) | low | Lecteur de la story 3.2. Correction : le présenter comme contrat de la 3.2. | patch |
| R31 | « L'exclusion vient en dernier » décrit un ordre d'édition alors que c'est l'état final qui compte (blind) | low | Correction : « jamais d'exclusion sans son bloc de couche ». | patch |
| R32 | Le rouge du test jamais vu sous PHPUnit ; `make qa` non lancé (conformance, verification-gap) | carried — medium | R13, inchangé. | vérification humaine avant fusion |
| R33 | Mode `100755` des nouveaux fichiers (blind) | carried — false | R14 : `core.filemode = false`, `git add` enregistre `100644` pour un fichier neuf. | rejeté |
| R34 | Comparaisons de tableaux sensibles à l'ordre des clés `type`/`value` (edge-case) | low | Vrai, mais improbable (fichier tenu à la main dans un ordre constant) ; la parade ajoute une normalisation. | rejeté |
| R35 | Familles (a) et (d) contrôlées dans un seul sens : une suppression laisse le guide périmé (verification-gap, edge-case) | low | Vrai ; une ligne en trop ne trompe pas un agent sur un emplacement ; parade = inventaire inverse. | rejeté |
| R36 | Faits du guide non gardés (colonne « Peut dépendre de », noms de skills, classes citées, liens) (blind) | low | Les quatre familles sont fixées par la spec ; les liens sont une vérification manuelle. | rejeté |
| R37 | Famille (c) ne vérifie pas les modules réels `src/Module/*` d'un dérivé (blind) | low | Le test garde les faits du guide, pas la config d'un dérivé ; `BoundaryTest` tient le filet. | rejeté |
| R38 | Incohérences internes de la spec (nombre d'entrées différées, lignes deptrac, `review_loop_iteration`) (blind) | low | Correctif = édition de la spec. | rejeté |
| R39 | RFC 7807 remplacée par RFC 9457 (blind) | false | Convention du spine (`Consistency Conventions`) ; le format est inchangé. | rejeté |
| R40 | Bascule des langues en base sans audit ni garde (blind) | low | Limite déjà nommée, écran en story 2.9. | rejeté |
| R41 | `--ring`/`--input` sous seuil contredisent « remesurez 3:1 » (blind) | false | Choix assumé de `DESIGN.md` ; le guide le dit. | rejeté |
| R42 | La limite de découverte des services ne dit pas quoi faire si `lint:container` échoue (blind) | low | L'entrée `deferred-work.md` porte la suite. | rejeté |

## Design Notes

**Ce qu'un test garde d'un document.** Pas la prose, mais les ancres que le guide
partage avec un fichier du dépôt. La famille (c) lit la structure de `deptrac.yaml`, et
non son commentaire, parce qu'un commentaire peut rester juste pendant que la recette
change.

**Pourquoi pointer vers `tests/Fixtures/Module/Demo/`** : il est exécuté à chaque build,
alors qu'un exemple recopié dans un markdown pourrit en silence.

**Ce que la story ne prouve pas** : que la recette est complète pour un vrai module
métier. L'Epic 2 la traversera ; ici, elle se vérifie à la main sur un module jetable.

## Verification

**Commands :**

- `vendor/bin/phpunit --filter DerivationGuideTest` — rouge avant le guide, vert après
- `vendor/bin/phpunit` — suite entière au vert
- `make qa` — six catégories vertes, aucune cible ni job ajouté

**Manual checks :**

- Suivre la recette sur un module jetable (en retirant `the_module_root_ships_empty()`
  comme elle le dit), `make qa`, puis tout restaurer
- Chaque lien du guide pointe sur quelque chose qui existe
