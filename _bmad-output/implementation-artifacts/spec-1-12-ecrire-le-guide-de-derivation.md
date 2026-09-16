---
title: 'Écrire le guide de dérivation'
type: 'feature'
created: '2026-09-16'
status: 'draft'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '778623fab8360ef0cf481fd0daa6372eb26a21d9'
context:
  - '{project-root}/.claude/skills/symfony-proglab-testing/SKILL.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** le dépôt n'a aucun document qui dise comment le socle est organisé ni où
ajouter un module. `README.md` couvre la porte de qualité et la boucle locale, pas la
dérivation ; le raisonnement vit dans `_bmad-output/planning-artifacts/`, que personne
n'ouvre le premier jour, et dans des commentaires dispersés de `config/` et de
`deptrac.yaml`. FR-2 promet l'inverse : une seule source, et un agent livre du code
conforme sans demander à personne.

**Approche :** un guide unique qui répond à « où va cette classe » de bout en bout, qui
renvoie aux artefacts BMAD pour le *pourquoi* et jamais pour le *quoi faire*, et qui
nomme les trois choses qu'aucun autre fichier ne porte : la convention d'en-tête YAML
que la story 3.2 consommera, la sauvegarde comme responsabilité du serveur client, et la
limite assumée d'AD-4 sur les emails déjà en file. Un test le garde honnête sur ses faits
vérifiables, parce qu'un document que rien ne relit dérive au premier refactor.

## Boundaries & Constraints

**Always :**

- **Le guide est autosuffisant pour la question « où va cette classe ».** Il donne
  l'emplacement, le nom et la forme sans qu'il faille ouvrir un artefact BMAD. Les
  renvois aux AD servent à justifier, pas à compléter.
- **Chaque fait vérifiable du guide a une source dans le dépôt** — les quatre globs,
  les quatre règles deptrac, le coût d'ajout d'un module, les statuts de sprint. Le
  guide cite le fichier, et le test garde l'accord entre les deux.
- **Le guide nomme, il ne recopie pas.** Les skills `symfony-proglab-*` et les workflows
  BMAD sont nommés par leur nom exact et invocables ; leur contenu reste chez eux.
- **Les cinq points d'ancrage amont sont nommés par identifiant** : AD-2 (deux racines),
  AD-7 (frontière deptrac), AD-13 (un module se déclare), AD-10 (contrat BMAD en lecture
  seule), AD-22 (CI et reprise), plus NFR-6 (dérivabilité) et UX-DR-2 (contrat de
  rebranding).
- **Le test s'écrit d'abord et se voit rouge**, sur le fichier de guide encore absent.

**Never :**

- **Aucune modification de `src/`, de `config/` ni de `deptrac.yaml`.** Cette story
  documente un socle qui existe ; elle ne le déplace pas. Si le guide ne peut pas
  décrire une chose sans la changer, c'est une trouvaille à remonter, pas un correctif à
  glisser ici.
- **Aucun module d'exemple dans `src/Module/`** : `ModuleWiringTest::the_module_root_ships_empty()`
  exige qu'il reste vide. L'exemple vers lequel pointer est `tests/Fixtures/Module/Demo/`.
- **Aucune nouvelle cible `make` ni job CI** — la parité des six catégories est testée.
- **Le guide ne redit pas le README** (prérequis locaux, worker, Mailpit, profiler) : il
  y renvoie.

</frozen-after-approval>

## Decisions

- **Emplacement du guide : `docs/DERIVATION.md`, avec un lien d'entrée depuis
  `README.md`.** `docs/` n'existe pas encore mais est déjà le `project_knowledge` de
  `_bmad/bmm/config.yaml` — le créer occupe une place déjà prévue plutôt que d'inventer
  une convention. Le README garde son public « je fais tourner le projet », le guide
  garde le sien « je dérive le socle » ; les deux restent lisibles d'un bloc. Écarté :
  une grande section de `README.md` (déjà 24 ko, deux publics mélangés dans le même
  défilement) et `CONTRIBUTING.md` (le nom promet des règles de contribution *au socle*,
  quand le document parle de construire *sur* lui).

- **Emplacement des quatre champs que FR-14 lira — priorité, difficulté, assignation,
  dépendances : un fichier léger par story sous
  `_bmad-output/implementation-artifacts/stories/{n}-{m}-{slug}.md`, en-tête YAML seul.**
  C'est la lecture la plus littérale d'AD-10 (« l'en-tête YAML du fichier de story ») et
  la forme que `bmad-build` sait déjà lire — son step-01 cherche
  `{spec_folder}/stories/{story_id}-*.md` — donc aucun câblage nouveau, et le fichier
  existe dès le statut `backlog`, avant même qu'une spec ne soit générée. Écarté : le
  frontmatter des specs `spec-{n}-{m}-*.md` (une story `backlog` n'a pas encore de
  fichier spec, donc priorité et dépendances s'afficheraient « non renseigné »
  précisément pour les stories pas encore démarrées, celles que 3.2 doit lister en
  premier) et les quatre champs mappés sous chaque clé de `sprint-status.yaml` (fichier
  régénéré par `bmad-sprint-planning`, s'écarte de la lettre d'AD-10).
  Noms de champs : `priority` (`high` / `medium` / `low`), `difficulty` (`S` / `M` /
  `L`), `assignee` (chaîne libre), `depends_on` (liste de clés `{n}-{m}`). Champ absent
  ⇒ « non renseigné » ; `depends_on` absent ⇒ tâche prête dès que son statut est « à
  faire ».

## Code Map

- `README.md:1-17, 96-136, 300-328, 362-371` — ce que le README couvre déjà : pitch,
  prérequis locaux, tableau des 18 skills, versions. Le guide y renvoie au lieu de le
  redire. **Seule édition autorisée ici : le lien d'entrée vers le guide.**
- `config/services.yaml:49-51` (`_instanceof` → tag `app.module`), `:68-70` (socle),
  `:72-75` (glob module + `exclude {Dto,Entity}`) ; `config/routes.yaml:10-22` ;
  `config/packages/doctrine.yaml:16-45` (`auto_mapping: false` + les deux mappings) ;
  `config/packages/translation.yaml:27-40` — **les quatre globs**, chacun doublé d'un
  miroir `when@test` sur `tests/Fixtures/Module/`. L'invariant « ajouter un module ne
  modifie pas ce fichier » y est déjà écrit en commentaire : le guide le reprend, il ne
  l'invente pas.
- `deptrac.yaml:1-48` (en-tête explicatif : deux dimensions, contrat de couches et
  frontière des racines), `:135-186` (couches de racine, dont le filet
  `UndeclaredModule`), `:324-354` (les quatre règles bloquantes), `:32-34` et `:173` —
  **le coût réel d'un nouveau module : quatre éditions dans ce seul fichier** (bloc de
  couche, ligne de ruleset, nom dans `AnyRoot`, exclusion dans `UndeclaredModule`). C'est
  le fait le plus utile du guide et le plus facile à laisser dériver.
- `src/Core/Contract/ModuleDescriptor.php:18` — `name()`, `translationDomain()` : la
  seule porte publique, consommée par `src/Core/Service/ModuleRegistry.php:23`
  (`#[AutowireIterator('app.module')]`). Ne rien y ajouter.
- `tests/Fixtures/Module/Demo/` — le module d'exemple réel, arborescence miroir complète
  (`Controller/`, `Dto/Input|Output|Read/`, `Entity/DemoWidget.php`, `Repository/`,
  `Service/DemoDescriptor.php`, `translations/demo.{fr,en,nl}.yaml`). C'est vers lui que
  le guide pointe, et `tests/Fixtures/Module/Billing/` est le second exemplaire minimal.
- `tests/Core/ModuleWiringTest.php:33-146` — prouve mapping, routage, découverte et
  câblage de production ; `the_module_root_ships_empty()` l. 139 interdit de peupler
  `src/Module/`.
- `tests/Core/Quality/GateFiles.php:19-28` (`read()`) et `:37-54` (`activeLines()`,
  indexé base 1, commentaires filtrés) — **le lecteur de fichiers du dépôt à réutiliser**,
  ne pas en écrire un autre.
- `tests/Core/Theme/AssetPipelineTest.php:495-503` — **le précédent exact** : asserter
  qu'un fait vérifié ailleurs est bien *nommé* dans un markdown.
  `tests/Core/Theme/ThemeTokensTest.php:19-33` — le patron inverse (recopier la liste
  dans le test, annoter les lignes de la source) et pourquoi il est assumé. Aucun test du
  dépôt ne parse la *structure* d'un markdown ; n'en faites pas le premier.
- `Makefile:14-19` + `tests/Core/Quality/QualityGateParityTest.php` — la parité
  catégories / cibles / jobs. Cette story n'ajoute ni cible ni job : le test part dans
  `make test`.
- `_bmad-output/implementation-artifacts/sprint-status.yaml:1-31` (les statuts d'epic, de
  story, de rétrospective) et `:38-52` (les clés réelles, **accentuées en français**).
- `ARCHITECTURE-SPINE.md` (`_bmad-output/planning-artifacts/architecture/architecture-proglab-skills-2026-09-09/`)
  `:74` AD-2, `:126-131` AD-4 « Limite énoncée » (l'envoi en file part à l'ancienne
  adresse ; corollaire : un message au transport d'échec portant un compte anonymisé est
  abandonné, pas rejoué), `:184` AD-7, `:247-273` AD-10, `:335-348` AD-13, `:482-494`
  AD-22 (sauvegarde : responsabilité du serveur client, procédure de restauration,
  exécutée une fois avant mise en service), `:549-567` Consistency Conventions,
  `:711` (un dérivé livré est maintenu mais non resynchronisé ; report par `git diff`).
- `_bmad-output/planning-artifacts/epics.md:89` NFR-6, `:136` UX-DR-2 (rebranding =
  variables `oklch` de `app.css`, au minimum `--primary` / `--primary-foreground`, plus
  le logo), `:1086` l'AC de la story 3.2 qui consommera l'en-tête.
- `.claude/skills/` — 18 dossiers `symfony-proglab-*` et les `bmad-*`. Les noms exacts se
  lisent ici ; `README.md:300-328` en porte déjà le tableau.

## Tasks & Acceptance

**Execution :**

- [ ] `tests/Core/Documentation/DerivationGuideTest.php` — **écrit en premier et vu
      rouge**, le fichier du guide n'existant pas encore. `extends TestCase`, lit par
      `GateFiles::read()`. Quatre familles d'ancres, et elles seules : (a) les treize
      dossiers de couche réellement présents sous `src/Core/` sont nommés dans le guide,
      (b) les quatre fichiers de configuration porteurs d'un glob `src/Module/*` sont
      nommés, chacun vérifié aussi côté `config/` pour que le test rougisse si un glob
      disparaît, (c) les quatre éditions de `deptrac.yaml` qu'un module coûte sont
      nommées, (d) les cinq valeurs de statut de story de `sprint-status.yaml` et les
      quatre champs de l'en-tête YAML sont nommés. Le test ne lit ni titres ni tableaux :
      il cherche des chaînes.
- [ ] `docs/DERIVATION.md` — le document lui-même. Il couvre, dans cet ordre : à qui il
      s'adresse et ce qu'il ne couvre pas ;
      les deux racines et le contrat de couches ; où va chaque type de classe, avec le
      renvoi à `tests/Fixtures/Module/Demo/` comme exemple vivant ; **la recette
      complète « ajouter un module »**, globs inclus et les quatre éditions de
      `deptrac.yaml` ; les conventions de nommage ; le rebranding (UX-DR-2) ; l'inventaire
      de ce qu'un dérivé configure (nom et marque, SMTP, langues actives, `DEFAULT_URI`,
      durées de jetons) ; les skills `symfony-proglab-*` et les workflows BMAD à
      invoquer, par leur nom ; la convention d'en-tête YAML des stories ; la sauvegarde
      et sa restauration ; les limites assumées.
- [ ] `README.md` — une seule addition : le lien d'entrée vers le guide, placé là où un
      arrivant le voit. Ne rien réécrire d'autre.
- [ ] La convention d'en-tête appliquée à au moins une story réelle, sous
      `_bmad-output/implementation-artifacts/stories/{n}-{m}-{slug}.md` — sans quoi elle
      reste théorique et la story 3.2 n'a aucune donnée sur laquelle se construire.

**Acceptance Criteria :**

- Given un agent chargé d'ajouter une entité métier, when il lit le seul guide, then il
  en tire l'emplacement, le namespace, la forme de l'entité et les couches qu'il a le
  droit d'appeler, sans ouvrir un artefact BMAD.
- Given le guide, when on en suit la recette « ajouter un module » à la lettre sur un
  module neuf, then `make qa` passe — aucune étape manquante, aucune règle deptrac
  oubliée.
- Given `vendor/bin/phpunit`, when un dossier de couche, un glob de `config/` ou un
  statut de `sprint-status.yaml` change sans que le guide suive, then
  `DerivationGuideTest` rougit en nommant l'ancre perdue.
- Given `make qa`, when il tourne, then les six catégories passent sans qu'aucune cible
  ni aucun job n'ait été ajouté.

## Implementation Notes

## Spec Change Log

- **2026-09-16** — Résolution des deux Open Questions : guide en `docs/DERIVATION.md`
  (lien d'entrée depuis `README.md`) ; en-tête YAML des quatre champs FR-14 dans un
  fichier léger par story sous `_bmad-output/implementation-artifacts/stories/`. Décidé
  par Fabrice. Section renommée `Decisions`.

## Review Triage Log

## Design Notes

**Ce qu'un test peut garder d'un document, et ce qu'il ne peut pas.** Il ne peut pas
garder la prose : un guide reste juste ou devient faux pour des raisons qu'aucune
assertion n'attrape. Il peut garder les **ancres** — les quelques faits que le guide
partage avec un fichier du dépôt et qui bougent ensemble ou pas du tout. Les quatre
familles retenues sont exactement celles-là : un dossier de couche ajouté, un glob
supprimé, une règle deptrac déplacée, un statut renommé. Toute assertion plus large
rendrait le test fragile sans rien prouver de plus ; toute assertion plus étroite
laisserait passer la dérive qui coûte cher.

**Pourquoi le guide pointe vers `tests/Fixtures/Module/Demo/` plutôt que de livrer un
module d'exemple.** `ModuleWiringTest:139` exige que `src/Module/` parte vide, et c'est
juste : un dérivé ne doit pas commencer par supprimer du code. Le module de démo des
fixtures est meilleur qu'un exemple écrit dans le guide, parce qu'il est exécuté par la
suite à chaque build — un exemple recopié dans un markdown, lui, pourrit en silence.

**Pourquoi l'inventaire de configuration entre dans le périmètre.** Les AC 4 et 5 de la
story parlent déjà à celui qui installe un dérivé, pas seulement à celui qui code dedans.
La réconciliation amont (`reviews/reconcile-prd-brief.md:198`) note que l'inventaire de
ce qu'un dérivé doit configurer n'est écrit nulle part et désigne le guide comme le seul
endroit prévu pour le porter. Il y va, sous forme de liste courte renvoyant au README pour
le détail local.

**Ce que cette story ne peut pas prouver.** Que la recette « ajouter un module » est
complète : seule la première story d'un module métier — Epic 2 — la traversera pour de
vrai. L'AC correspondante se vérifie ici à la main, sur un module jetable.

## Verification

**Commands :**

- `vendor/bin/phpunit --filter DerivationGuideTest` — vu rouge avant le guide, vert après
- `vendor/bin/phpunit` — suite entière au vert
- `make qa` — les six catégories passent, aucune cible ni job ajouté

**Manual checks :**

- Suivre la recette « ajouter un module » à la lettre sur un module jetable, lancer
  `make qa`, puis supprimer le module : aucune étape manquante, aucune règle deptrac
  oubliée
- Chaque lien du guide vers un fichier du dépôt ou un skill pointe sur quelque chose qui
  existe
