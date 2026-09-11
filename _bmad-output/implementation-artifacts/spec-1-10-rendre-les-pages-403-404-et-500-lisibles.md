---
title: 'Rendre les pages 403, 404 et 500 lisibles'
type: 'feature'
created: '2026-09-11'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '8bfe631c5155d56f9880f5b23a2b149c31f7d4c5'
context:
  - '{project-root}/.claude/skills/symfony-proglab-http/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-accessibility/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-testing/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-observability/SKILL.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-proglab-skills-2026-09-09/EXPERIENCE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** le socle n'a aucune page d'erreur. `templates/bundles/` n'existe pas, donc
le `TwigErrorRenderer` ne trouve rien et toute 403, 404 ou 500 servie en production tombe
sur la page nue du `HtmlErrorRenderer` — en anglais, hors gabarit, affichant le code HTTP.
Et rien n'est journalisé : `symfony/monolog-bundle` n'étant pas installé, le service
`logger` n'existe pas, l'`ErrorListener` de Symfony sort sans écrire une ligne, et une 500
en production ne laisse aucune trace nulle part.

**Approach :** écrire quatre templates courts sous
`templates/bundles/TwigBundle/Exception/` — `error403`, `error404`, `error500` et un
`error` générique pour tous les autres statuts — qui héritent du gabarit de base et ne
rendent qu'un `h1`, une phrase qui dit quoi faire ensuite, et un lien de retour à
l'accueil. Aucune des variables du renderer (`status_code`, `status_text`, `exception`)
n'atteint le DOM. Les chaînes viennent du tableau *Voice and Tone*, dans les trois
langues, sous le domaine `messages` en clés `error.*`. Et poser le premier magasin de
journaux du dépôt — `symfony/monolog-bundle` et un `config/packages/monolog.yaml` écrit à
la main — pour que l'`ErrorListener` natif ait enfin un logger à qui parler.

## Boundaries & Constraints

**Always :**
- Les quatre templates héritent de `base.html.twig` et ne remplissent **que** `title` et
  `body` : ni `header`, ni `nav`, ni `footer`, ni `stylesheets`, ni `javascripts`.
- **Aucun détail technique dans le DOM** : ni code HTTP, ni `status_text`, ni nom de
  classe, ni trace, ni identifiant interne. Les variables existent, aucune n'est rendue.
- Un seul `h1` par page, un lien de retour vers `app_home`, et un message qui dit quoi
  faire ensuite plutôt que ce qui a échoué.
- Les trois langues, au mot près depuis le tableau de ton. Clés `error.*` pointées, dans
  le domaine `messages`, présentes et non vides en `fr`, `en` et `nl`.
- **La 500 rend même quand la base est injoignable.** Rien dans son chemin de rendu ne
  touche la base : la locale retombe sur `default_locale` et les catalogues sont des
  fichiers. C'est une propriété à prouver, pas à espérer.
- Le rendu se prouve sur le **vrai chemin** — un client HTTP avec `debug` à faux — et non
  seulement par un rendu direct du template.
- **Une 500 laisse une ligne de journal, dans un canal nommé.** La configuration Monolog
  est écrite à la main et relue, jamais héritée d'une recette.
- Les pages d'erreur entrent dans le plancher d'accessibilité : elles sont soumises aux
  mêmes règles de DOM que toute page du socle, y compris celles ajoutées après cette story.

**Never :**
- Pas de contrôleur d'erreur maison, pas de clé `error_controller`, pas d'écouteur
  d'exception : le `TwigErrorRenderer` natif fait déjà exactement ce travail.
- Pas d'exception métier ni de `#[WithHttpStatus]` : rien dans le socle n'en lève encore.
  La convention est annoncée par `deptrac.yaml`, elle arrive avec la story qui en a besoin.
- Pas de firewall, pas de `security.yaml`, pas d'entité `User` : la story 1.6 les livre en
  parallèle. La 403 est prouvée par une fixture qui lève, jamais par un accès refusé réel.
- Pas de Problem Details RFC 7807, pas de négociation de format : l'API est l'Epic 6.
- Pas de `WebProfilerBundle` : la page de debug de développement ne change pas.
- **Monolog s'arrête au strict nécessaire** : des canaux, un handler par environnement, et
  rien d'autre. Ni processor, ni identifiant de corrélation, ni utilisateur courant, ni
  masquage, ni sink d'alerte, ni heartbeat — tout cela appartient à l'observabilité et
  n'a rien à quoi se raccrocher aujourd'hui.
- Ne rien écrire dans `translations/` au-delà des clés `error.*` — la story 1.6 écrit dans
  les trois mêmes fichiers en parallèle, et toute autre clé ferait un conflit inutile.

## I/O & Edge-Case Matrix

| Scénario | Entrée / État | Comportement attendu | Gestion d'erreur |
|---|---|---|---|
| Page inexistante | GET `/chemin-absent`, `debug` à faux | 404 rendue par `error404.html.twig` : un `h1`, une phrase, un lien vers l'accueil | Aucun code HTTP, aucun `status_text` dans le DOM |
| Accès refusé | Une action lève `AccessDeniedHttpException` | 403 rendue par `error403.html.twig`, message dédié | Rien ne dit *pourquoi* l'accès est refusé |
| Erreur inattendue | Une action lève `\RuntimeException` | 500 rendue par `error500.html.twig`, message générique, **et une ligne écrite dans son canal** | Ni classe, ni message d'exception, ni trace à l'écran — tout cela va au journal |
| 404 et 403 | Statut client | Journalisées au niveau que Symfony leur donne, sans bruit | Une page introuvable n'est pas une panne : elle ne réveille personne |
| Statut sans template dédié | 405, 502, 401… | `error.html.twig` : même gabarit, même message générique que la 500 | Le repli existe, donc jamais la page nue du framework |
| Environnement de développement | `kernel.debug` à vrai | La page de debug reste servie, inchangée | N/A — le renderer Twig n'intervient qu'hors debug |
| Base de données injoignable | La résolution de locale lève sur la requête principale | La 500 rend quand même, en `default_locale` | Le rendu de l'erreur ne rouvre aucune connexion |
| Erreur après `?lang=nl` | Locale `nl` résolue sur la requête principale | La page d'erreur est rendue en néerlandais | La sous-requête d'erreur hérite de la locale, elle ne la recalcule pas |
| Navigation Turbo échouée | Turbo n'obtient aucune réponse exploitable | Repli en navigation pleine page, qui rend la même page | Aucun écran à moitié remplacé |

## Décisions de Fabrice (2026-09-11)

- **Monolog entre ici, et sa configuration est écrite à la main.** Le second critère
  exigeait « journalisée par canal » ; le socle n'avait aucun logger, donc l'`ErrorListener`
  natif sortait sans rien écrire. Aucune story de l'Epic 1 ne possédait Monolog et
  l'observabilité du PRD n'arrive qu'avec le health check de la story 6.4 : différer aurait
  laissé le socle perdre toutes ses 500 pendant cinq epics. La recette Flex seule est
  écartée — une configuration que personne ne relit ne tient le mot « canal » que par
  accident. La story passe de ~30 min à ~1 h, et **elle touche donc `composer.json` et
  `composer.lock`, comme la story 1.6 en parallèle** : le second à fusionner re-résout le
  lock, il ne le règle pas à la main.
- **Les pages d'erreur entrent dans le plancher d'accessibilité.** L'Epic pose que
  l'accessibilité est vérifiée mécaniquement et jamais par relecture ; une page que
  l'utilisateur voit vraiment n'a pas à y échapper sous prétexte qu'aucune route ne la rend.
  Le coût — extraire une source de crawlers dans le fichier de la story 1.4 — est payé une
  fois et profite à toute règle ajoutée ensuite.
- **Un dérivé écrase ces templates en place.** Les quatre fichiers d'erreur font partie de
  la surface de rebranding, au même titre que le logo et les variables de couleur : un ERP
  client qui veut sa propre 404 remplace le template, il n'a besoin d'aucun mécanisme de
  surcharge. Rien dans cette story ne doit donc rendre ces fichiers difficiles à remplacer
  — pas de logique, pas de dépendance à un service, rien qu'un gabarit et des clés.
- **Le spec est gardé entier** malgré ses ~5 300 tokens, comme aux stories 1.4, 1.5 et 1.6 :
  fixtures, tests, traductions, templates et journalisation ne livrent rien de visible
  séparément.

</frozen-after-approval>

## Code Map

- `templates/bundles/TwigBundle/Exception/` — **n'existe pas**. `@Twig` ne résout que vers
  `templates/bundles/TwigBundle/` (le bundle n'embarque aucune vue), donc ces quatre
  fichiers sont l'unique point d'extension. `TwigErrorRenderer::findTemplate()` essaie
  `error{status}.html.twig` puis `error.html.twig` : **aucun repli par famille**, pas de
  `error4xx`.
- `vendor/symfony/twig-bridge/ErrorRenderer/TwigErrorRenderer.php` — décide tout : les
  templates ne sont utilisés que si `kernel.debug` est faux (`isDebug()`), et reçoivent
  `exception` (un `FlattenException`), `status_code`, `status_text`.
- `vendor/symfony/framework-bundle/Resources/config/web.php:140-147` — l'`exception_listener`
  reçoit `service('logger')->nullOnInvalid()`, et
  `vendor/symfony/http-kernel/EventListener/ErrorListener.php:167-178` sort sur
  `if (!$logger = ...) return;`. **Installer Monolog suffit à faire écrire ce listener :
  aucun code applicatif n'est à écrire pour journaliser.** Le même fichier sait déjà router
  par canal et lire `#[WithLogLevel]`.
- `composer.json` + `config/bundles.php` + `config/packages/` — ni `symfony/monolog-bundle`,
  ni `monolog.yaml`, ni la moindre occurrence de `LoggerInterface` dans `src/`. Tout est à
  poser. **`composer.json` et `composer.lock` sont aussi touchés par la story 1.6.**
- `templates/base.html.twig:37-44` — la garde `page_title|trim is not empty` a été écrite
  **nommément pour cette story** : une page sans bloc `title` rend `<title>Socle ERP</title>`
  seul. `<main id="contenu" tabindex="-1">` l. 96, `header` inconditionnel l. 72, `nav`
  l. 78 et `footer` l. 100 conditionnels (un bloc vide ne rend pas le repère). Ne pas
  toucher ce fichier.
- `templates/home/index.html.twig` — le précédent de page courte à copier : `extends`,
  `block title` traduit, `block body` ouvert par `<h1>`.
- `config/packages/twig.yaml:4-8` — `app_name` est un global Twig lu depuis `APP_NAME` :
  le `<title>` de la 500 tient donc sans base de données. Ne pas modifier.
- `src/Core/EventListener/LocaleListener.php:60-63` — sort sur `!isMainRequest()` : la
  sous-requête d'erreur ne recalcule pas la locale, elle hérite de celle de la requête
  principale. C'est ce qui rend la page d'erreur traduite **et** insensible à une base
  injoignable. Ne pas toucher (la story 1.6 modifie ce fichier en parallèle).
- `translations/messages.{fr,en,nl}.yaml` — trois clés aujourd'hui (`layout.*`,
  `home.index.title`). `CatalogParityTest` exige une parité **exacte et bidirectionnelle**
  des clés aplaties, non vides, dans les trois locales. Fichiers partagés avec la 1.6.
- `tests/Fixtures/Module/Demo/Controller/DemoWidgetController.php` — le précédent de
  contrôleur de fixture. `config/routes.yaml:19-23`, `config/services.yaml:43-58` et
  `deptrac.yaml` câblent déjà `tests/Fixtures/Module/*/` par glob : **aucun fichier de
  `config/` n'est à modifier** pour ajouter des actions qui lèvent.
- `tests/bootstrap.php` + `phpunit.dist.xml:19` — `APP_ENV=test` sans `APP_DEBUG`, donc
  **debug vaut 1 en test** : un client ordinaire verrait la page de debug. Il faut
  `self::createClient(['debug' => false])`.
- `tests/Core/Template/BaseTemplateTest.php:261-279` — `renderBareTemplate()` : le motif
  maison pour rendre un template hors requête (`bootKernel`, `request_stack`, `twig`).
- `tests/Core/Accessibility/AccessibilityFloorTest.php` — `pages()` l. 656-698 (découverte
  par la `RouteCollection`, mémoïsée, `assertResponseIsSuccessful()` l. 692, filtre `App\`
  hors `App\Tests\` l. 672-688 : **ne pas y toucher**). Les **neuf** règles qui lisent un
  DOM sont l. 48, 79, 119, 149, 184, 225, 347, 450, 488 ; les sept autres lisent des
  fichiers et ne sont pas concernées. Helpers réutilisables : `elementsOf` (l. 706),
  `hasElementWithId` (l. 745), `hasAccessibleName` (l. 781), `report` (l. 1067). La garde
  anti-balayage-à-vide est l. 619. L. 140-147 cite déjà cette story.
- `.github/workflows/ci.yml` + `Makefile` — **ne bougent pas** : aucune catégorie, cible ni
  commande n'est ajoutée. La cible `lint` lance déjà `lint:yaml config/` et
  `lint:twig templates/`, donc `monolog.yaml` et les quatre templates sont lintés d'office.
- `tests/Core/Quality/QualityGateParityTest.php` — parité catégories / cibles Make / jobs
  CI. Un fichier de test ajouté dans un testsuite existant ne l'affecte pas : **ni le
  `Makefile` ni `.github/workflows/ci.yml` ne bougent**.
- `config/routes/framework.yaml` — `/_error/{code}` n'est montée **qu'en `dev`** : elle
  sert la vérification manuelle, jamais les tests.

## Tasks & Acceptance

**Execution:**
- [x] `tests/Fixtures/Module/Demo/Controller/DemoErrorController.php` -- deux actions de
      fixture qui lèvent `AccessDeniedHttpException` et `\RuntimeException` -- sans firewall
      ni exception métier, c'est le seul moyen de provoquer une vraie 403 et une vraie 500
      à travers la pile HTTP complète.
- [x] `tests/Core/Template/ErrorPageTest.php` -- **écrit en premier et vu rouge** : couvre
      chaque ligne de la matrice via `createClient(['debug' => false])`, plus le balayage
      qui interdit tout détail technique dans le DOM des quatre templates -- c'est la seule
      preuve que le renderer natif est réellement atteint.
- [x] `translations/messages.{fr,en,nl}.yaml` -- les clés `error.*` (titre, message et
      libellé de retour pour 403, 404 et le cas générique), dans les trois langues --
      source française au mot près du tableau de ton ; parité exacte exigée par la porte.
- [x] `templates/bundles/TwigBundle/Exception/error404.html.twig` + `error403.html.twig` --
      pages courtes héritant du gabarit, un `h1`, une phrase, un lien vers `app_home` --
      les deux erreurs que l'utilisateur rencontre en naviguant.
- [x] `templates/bundles/TwigBundle/Exception/error500.html.twig` + `error.html.twig` --
      même forme, message générique partagé -- le second ferme le trou des statuts sans
      template dédié, que le renderer ne rattrape par aucune famille.
- [x] `tests/Core/Template/ErrorPageTest.php` -- un cas qui rend la 500 **avec la base
      coupée** -- la propriété la plus facile à casser plus tard, et la seule qui compte
      vraiment le jour où elle sert.
- [x] `composer.json` + `config/bundles.php` + `config/packages/monolog.yaml` --
      `symfony/monolog-bundle` et une configuration écrite à la main : les canaux du socle,
      un handler par environnement, `main` en `fingers_crossed` hors développement -- sans
      logger, l'`ErrorListener` natif n'écrit rien et le second critère ne peut pas se
      fermer.
- [x] `tests/Core/Observability/ErrorLoggingTest.php` -- un handler de test en `when@test`,
      et l'assertion qu'une 500 écrit une ligne dans son canal quand une 404 n'en écrit
      aucune d'erreur -- « journalisée par canal » doit être une propriété vérifiée, pas un
      fichier de configuration qu'on espère juste.
- [x] `tests/Core/Accessibility/AccessibilityFloorTest.php` -- extraire de `pages()` une
      source de crawlers commune et y verser les quatre templates d'erreur rendus hors
      requête, sans toucher ni au filtre de routes ni à `assertResponseIsSuccessful()` --
      les neuf règles de DOM couvrent alors les pages d'erreur, y compris celles qu'une
      story future ajoutera.

**Acceptance Criteria:**
- Given un dérivé en production, when un statut d'erreur quelconque est servi, then la
  réponse porte le gabarit du socle et jamais la page nue du framework.
- Given la porte de qualité, when une des clés `error.*` manque dans une des trois langues,
  then « Tests » échoue en nommant la locale et la clé.
- Given le plancher d'accessibilité, when une page d'erreur perd son `h1` ou son `lang`,
  then la catégorie « Accessibility » échoue en nommant le template fautif.
- Given `lint:twig` et `lint:yaml`, when les quatre templates et `monolog.yaml` sont lintés,
  then ils sont valides.

## Implementation Notes

**Deux interrupteurs au lieu d'un pour atteindre le vrai chemin.** La Code Map annonçait
`self::createClient(['debug' => false])` comme suffisant. Il ne l'est pas sur Symfony 7.4 :
`error_handler.error_renderer.default` est construit par
`RuntimeModeErrorRendererSelector::select()` à partir de `%kernel.runtime_mode.web%`, qui
retombe sur `container.runtime_mode` — que le conteneur compilé calcule en `web=0` dès que
`PHP_SAPI` vaut `cli`, donc **toujours** sous PHPUnit. Sans `APP_RUNTIME_MODE=web=1`, c'est
le `CliErrorRenderer` qui répond : il dumpe la classe et la trace, et le test aurait prouvé
l'inverse de ce qu'il croyait. Le réglage est posé dans le `setUp()` du seul fichier qui en
a besoin, `tests/Core/Template/ErrorPageTest.php`, et pas dans `phpunit.dist.xml` : la
journalisation, elle, ne dépend pas du renderer.

**Le conteneur non-debug n'est jamais vérifié en fraîcheur.** `ConfigCache` sans
métadonnées : `var/cache/test/` garde le conteneur non-debug compilé avant que
`templates/bundles/TwigBundle/` existe, `@Twig` n'y a alors aucun chemin, et les quatre
pages sortent en page nue. La CI ne voit jamais ce piège (clone frais), une machine de
développement si. `ErrorPageTest::the_renderer_can_actually_find_the_four_templates()` le
nomme explicitement plutôt que de laisser neuf tests échouer sur « pas de `h1` ».

**Rendre la base injoignable demande de faire taire DAMA.** `StaticDriver::connect()` indexe
ses connexions sur `dama.connection_key` — le *nom* de la connexion, jamais ses paramètres :
réécrire `DATABASE_URL` ne changeait rien, la connexion déjà ouverte était resservie et la
page rendait 200. Les connexions statiques sont donc rendues le temps de ce seul test
(`StaticDriver::setKeepStaticConnections(false)`), et remises aussitôt.

**Une 404 ne peut pas être traduite par `?lang=`, et c'est structurel.** `RouterListener` a
la priorité 32, `LocaleListener` la priorité 20 : sur une adresse inconnue, le routeur lève
**avant** que la locale soit résolue, et la page sort en `default_locale`. La ligne « Erreur
après `?lang=nl` » de la matrice suppose une locale déjà résolue — vrai une fois le routage
passé (403, 500), faux pour une 404. Le test porte donc sur la 403, et l'écart est écrit
dans son docblock. Le fermer demanderait de résoudre la locale avant le routeur ; personne
n'en a besoin aujourd'hui.

**Trois titres décidés ici.** Le tableau *Voice and Tone* donne le corps des messages au mot
près, mais aucun `h1` : les pages d'erreur y sont « spine-only ». Les trois titres —
« Accès non autorisé », « Page introuvable », « Une erreur est survenue » — sont donc une
décision de cette story, écrite sous ses propres règles, et gelée par
`ErrorPageTest::FRENCH`. De même, le libellé de retour est « Retour à l'accueil » et non
« Retour à la roadmap » du tableau : la roadmap est la page d'accueil de l'Epic 3, et le
lien pointe `app_home` dans les deux cas.

**Le `dev` journalise tout, y compris une 404.** Le handler de développement est un `stream`
non bufférisé — c'est ce que demande la contrainte « `main` en `fingers_crossed` hors
développement ». Une 404 y laisse donc bien une ligne `request.ERROR`, qui est le niveau que
Symfony lui donne. La propriété « une page introuvable ne réveille personne » vit dans
`fingers_crossed` + `excluded_http_codes`, donc en `test` et en `prod`, et c'est là qu'elle
est vérifiée.

**403 est dans `excluded_http_codes`, au même titre que 404.** `ErrorListener` journalise une
`AccessDeniedHttpException` en `error` — exactement `action_level` — donc sans elle dans la
liste chaque accès refusé viderait les cinquante lignes du buffer. La ligne « 404 et 403 » de
la matrice met les deux statuts ensemble, et `when@test` reprend désormais **mot pour mot**
les filtres de `when@prod` (`channels` compris) : une forme de test qui diverge d'un seul
filtre ne prouve plus la forme de production.

**Le `Makefile` et la CI bougent d'une ligne, contre ce que dit la Code Map.** Le bloc
`when@prod` de `monolog.yaml` n'était vérifié que par lecture du YAML : renommer le handler
`nested` sans sa référence laissait la porte verte et la production muette.
`php bin/console lint:container --env=prod` est ajouté **dans la cible `lint` et le job
« Linters and audits » qui existent déjà** — aucune catégorie créée, donc `QualityGateParityTest`
reste satisfait, et le fragment y est épinglé pour qu'on ne puisse pas le retirer des deux
façades en silence.

**Le canal `request` n'est pas déclaré, et c'est volontaire.** Il vient de Symfony
(`web.php` tague `exception_listener` avec `['channel' => 'request']`). `monolog.channels` ne
porte que `deprecation`, pour son handler de production non bufférisé. Aucun canal
applicatif n'est posé : rien dans `src/` n'écrit encore une ligne.

## Spec Change Log

## Review Triage Log

Passe 1 — quatre couches : blind-hunter (14), edge-case-hunter (13), verification-gap
(2 pré-vérifiées + 1 autre), proglab-conformance (0 infraction, 1 écart déclaré). Chaque
trouvaille non pré-vérifiée a été rouverte à l'emplacement cité avant verdict.

| # | Couche | Trouvaille | Verdict | Évidence | Route |
|---|---|---|---|---|---|
| 1 | blind + edge + vgap | 403 absent d'`excluded_http_codes` : chaque accès refusé vide les 50 lignes du buffer, en test comme en prod | `medium` | Vérifié, et prouvé empiriquement par la couche vgap (deux enregistrements atteignent `monolog.handler.testing` sur `/demo/errors/forbidden`, aucun sur la 404). `ErrorListener::resolveLogLevel()` rend `error` pour toute `HttpExceptionInterface` < 500, et `HttpCodeActivationStrategy` ne neutralise que les codes listés. La ligne « 404 et 403 » de la matrice gelée promet l'inverse | patch |
| 2 | blind + edge + vgap | `a_refused_access_wakes_nobody()` ne vérifie pas ce que son nom annonce : seul `Level::Critical` est assert, là où son jumeau 404 assert aussi `getRecords() === []` | `medium` | Vérifié l. 109-127 : une activation de `fingers_crossed` vide des lignes `error` et `info`, jamais des `critical`. L'assertion écrite ne peut donc pas voir le vidage qu'elle prétend interdire | patch |
| 3 | blind + edge | `when@test` se dit « la forme de production » mais filtre `!event` quand `when@prod` filtre `!deprecation` : les deux listes sont disjointes et aucun test ne les regarde | `low` | Vérifié l. 67 et 95. En test une dépréciation entre dans le buffer et part avec la première erreur ; en prod elle en est exclue. Correction directe, plus une assertion sur `channels` | patch |
| 4 | vgap (pré-vérifiée) | Le bloc `when@prod` — le seul dont parle le second critère — n'est vérifié que par lecture YAML : renommer `nested` ou filtrer `!request` laisse toute la suite verte | `medium` | Pré-vérifié et reproduit : `handlersOf()` lit le fichier, jamais le conteneur, et rien dans `make qa` ni la CI ne compile `prod` (`grep` sur `--env=prod` ne rend qu'un commentaire) | patch |
| 5 | blind | `ErrorPageTest::TEMPLATES` est une liste en dur alors que le plancher balaie le répertoire : un cinquième template échapperait aux deux règles de source | `medium` | Vérifié : `AccessibilityFloorTest::errorPages()` balaie, `ErrorPageTest` énumère. C'est exactement l'écart que le commentaire d'`ERROR_TEMPLATES` se félicite d'avoir fermé côté plancher | patch |
| 6 | blind + edge | `the_500_renders_with_the_database_unreachable()` lit `$restore` dans `$_SERVER` seul mais écrit ou `unset` dans `$_ENV` **et** `$_SERVER` | `low` | Vrai. Le Dotenv du dépôt peuple les deux, donc le mauvais état n'est pas atteignable aujourd'hui — mais la correction est directe, une lecture repliée sur les deux tableaux | patch |
| 7 | edge | `setUp()` écrase `APP_RUNTIME_MODE` et `tearDown()` l'`unset` sans avoir sauvegardé une valeur préexistante | `low` | Vrai, même forme que la précédente et même fichier : sauvegarde manquante avant écrasement. Correction directe | patch |
| 8 | edge | `no_error_template_renders_a_variable_of_the_renderer()` balaie la source brute, commentaires Twig compris : un commentaire contenant « exception » ferait échouer la porte avec un message qui parle du DOM | `low` | Vérifié l. 210-224. Aucun template ne le déclenche aujourd'hui, mais le message enverrait corriger le mauvais fichier. Correction directe : retirer les `{# … #}` avant la recherche | patch |
| 9 | blind | `the_debug_page_is_untouched_in_development()` n'assert qu'une absence : il passerait sur un corps vide ou une réponse tronquée | `low` | Vérifié l. 231-243. Le test ne protège pas ce qu'il annonce ; une assertion positive est une correction directe | patch |
| 10 | edge | `errorPages()` construit le nom logique depuis `getFilename()` alors que `finderFor()` descend récursivement et accepte aussi `*.php` et `*.js` | `low` | Vérifié l. 939-945 : le filtre existe mais n'exclut ni les sous-répertoires ni les deux autres extensions. Un partial rangé dans un sous-dossier ferait **échouer** toute la suite Accessibility au lieu de la faire rapporter | patch |
| 11 | blind | En néerlandais, le `h1` de la 500 et la première phrase du paragraphe sont identiques au caractère près | `medium` | Vérifié : `title: Er is iets misgegaan` et `message: "Er is iets misgegaan. Probeer het opnieuw…"`. Le fr et l'en distinguent les deux. Un utilisateur néerlandophone lit deux fois la même phrase, sur une page qui en compte trois | patch |
| 12 | blind | `error.not_found.message` dit ce qui a échoué, pas quoi faire ensuite | `false` | Réfuté par le contrat UX : `EXPERIENCE.md:216` donne « Cette page n'existe pas. » **au mot près**, et la règle *Always* du spec exige justement le mot près. Le « ensuite » est porté par le lien de retour, que la même ligne du contrat impose | rejeté |
| 13 | edge | Le libellé de retour livré est « Retour à l'accueil » quand `EXPERIENCE.md:211,215,216` écrit « Retour à la roadmap » pour les trois pages | `low` | Vrai et vérifié. Écart déclaré trois fois (catalogue fr, constante de test, Implementation Notes) et défendable — la roadmap est la page d'accueil de l'Epic 3, pas celle de l'Epic 1, et un libellé qui nomme une page inexistante serait faux aujourd'hui. **La décision appartient à Fabrice** : elle est portée à l'étape de présentation plutôt que réglée ici, et le correctif est une chaîne dans trois fichiers | humain |
| 14 | edge | `path('app_home')` dans les quatre templates : un dérivé qui renomme la route ferait lever le renderer d'erreur | `low` | Vrai, et sans importance : un dérivé qui supprime `app_home` casse la moitié du socle bien avant ses pages d'erreur. Le correctif demande une garde conditionnelle dans chacun des quatre fichiers — précisément ce que la décision « un dérivé remplace ces fichiers en place » veut éviter | rejeté |
| 15 | edge | `symfony.lock` enregistre `monolog.yaml` comme fichier de recette : `composer recipes:update` pourrait écraser la configuration écrite à la main | `low` | Vrai en théorie. `recipes:update` est une opération explicite qui affiche son diff et demande confirmation, jamais un écrasement silencieux ; et le correctif — mentir au lock ou déclarer la recette ignorée — casserait Flex pour un risque qu'aucune commande de la porte ne déclenche | rejeté |
| 16 | blind | Le repli générique n'est exercé que par un 405, jamais par un 502 | `low` | Vrai. Le chemin prouvé est le même — `findTemplate()` ne connaît que « statut dédié » ou « générique » —, et un 502 n'ajouterait qu'une route de fixture pour reparcourir une branche déjà couverte | rejeté |
| 17 | blind + edge | Le texte générique est faux pour un 401 (« Réessayez » plutôt que « connectez-vous ») | `low` | Vrai, et inatteignable : aucun firewall n'existe, donc rien ne produit un 401. Le cas arrive mécaniquement avec la story 1.6 | defer |
| 18 | edge | 429 devrait rejoindre `excluded_http_codes` | `low` | Vrai le jour où un 429 existe. `symfony/rate-limiter` n'entre qu'à la story 1.7, qui est nommément exclue de celle-ci | defer |
| 19 | blind | En production, `php://stderr` n'a aucune destination : sans `catch_workers_output` sous PHP-FPM, le flux est perdu et la story qui pose le premier magasin de journaux se referme sur une production muette | `medium` | Vrai et vérifié : rien dans le dépôt ne route ce flux, et la story de déploiement (3.1) n'existe pas encore. Antérieur à cette story au sens où aucune cible de déploiement n'existe — mais c'est elle qui crée l'obligation | defer |
| 20 | blind | `the_configuration_declares_one_handler_per_environment()` ne vérifie ni l'exclusion de 405, ni que `dev` n'est **pas** bufférisé, ni `php://stderr`, ni le formateur JSON, ni le handler `deprecation` | `low` | Vrai. Le constat 4 route déjà la vérification de `prod` vers une vérification qui **exécute** le conteneur ; épaissir en plus le contrôle textuel serait payer deux fois pour la même garantie | rejeté |
| 21 | blind + vgap | Aucune assertion positive sur la journalisation des statuts client : personne ne prouve qu'une ligne `error` existe pour une 404 | `low` | Vrai. La matrice dit « journalisées au niveau que Symfony leur donne » ; c'est le comportement de `ErrorListener`, non modifié par cette story, et le correctif figerait un détail de Symfony que rien ici ne possède | rejeté |
| 22 | blind | Rien ne vérifie que `APP_RUNTIME_MODE=web=1` a réellement pris | `low` | Vrai. Mais `the_renderer_can_actually_find_the_four_templates()` couvre déjà le piège jumeau, et si le mode d'exécution ne prenait pas, les dix tests de rendu échoueraient — bruyamment, et sur le fichier qui porte l'explication en tête | rejeté |
| 23 | blind | Les catalogues `en` et `nl` ne portent aucun renvoi vers l'en-tête de raisonnement du catalogue `fr` | `low` | Vrai et cosmétique : les trois fichiers sont côte à côte dans un même répertoire, et le fr est nommément la langue source | rejeté |
| 24 | blind | La vérification « `var/log/` : une 500 laisse une ligne » du spec ne vaut qu'en `dev` | `low` | Vrai, et le correctif consiste à éditer le spec de ce build — écarté par la règle de triage. Le fond est couvert par le constat 19 | rejeté |
| 25 | edge | Le spec annonce « un handler par environnement » quand le fichier en pose 1, 2 puis 3 | `low` | Vrai au pied de la lettre, et le correctif édite le spec de ce build. « Un handler par environnement » y désigne une destination décidée par environnement, ce que le fichier fait | rejeté |
| 26 | conformance | Le handler de production est posé sans processor de masquage, là où `symfony-proglab-observability` le demande dès le premier déploiement | `low` | Écart **déclaré**, pas contourné : écrit dans l'en-tête du fichier, dans les Design Notes, et gelé par `no_processor_adds_anything_to_the_line_yet()`. Le socle n'a ni utilisateur connecté ni formulaire, et `doctrine.dbal.logging` suit `kernel.debug` | rejeté |

Aucune trouvaille ne route en `bad_spec` : aucune ne demande de redériver le code. Le
constat 13 est le seul qui remonte au bloc gelé ; il porte sur une chaîne, son correctif
est d'une ligne dans trois fichiers, et le rendre à l'humain sans annuler un travail vert
est plus fidèle à l'intention que de rejouer le build pour un libellé. Dix correctifs,
trois reports, treize rejets.

## Design Notes

**Pourquoi aucun contrôleur d'erreur.** Le réflexe est d'écrire un `ErrorController` et de
le déclarer en `framework.error_controller`. Il n'apporterait rien ici : le
`TwigErrorRenderer` résout déjà le template par statut, passe déjà les variables, et un
contrôleur maison ajouterait une classe à tenir, une couche deptrac à choisir et un point
de panne supplémentaire sur le chemin le plus fragile de l'application — celui qui doit
répondre quand tout le reste a échoué. La règle 2 dit qu'un contrôleur traduit ; ici il n'y
a rien à traduire.

**Le template générique n'est pas du zèle.** `findTemplate()` n'a pas de repli par
famille : sans `error.html.twig`, un 405 ou un 502 sort par le `HtmlErrorRenderer` nu, qui
affiche « Oops, an Error Occurred » et le code HTTP — exactement ce que le premier critère
interdit. Quatre fichiers, pas trois.

**Ce que cette story ne peut pas prouver.** La vraie 403 — celle d'un utilisateur
authentifié devant une zone fermée — n'existera qu'avec le firewall de la story 1.6 et les
permissions de l'Epic 2. Ici, seul le rendu du template est prouvé, déclenché par une
fixture. L'écart est réel et doit être écrit dans le test lui-même.

**« Sans donnée personnelle » est tenu par ce qu'on n'écrit pas.** Aucun processor n'est
posé : la ligne de journal d'une 500 porte ce que Symfony y met — message d'exception,
classe, requête — et rien de plus. C'est suffisant tant que le socle n'a ni utilisateur
connecté ni formulaire. Dès que l'un des deux existe, le masquage et l'identifiant de
corrélation deviennent nécessaires, et ils appartiennent à la story d'observabilité : la
tenir pour acquise ici serait une erreur, et le test de journalisation doit le dire.

## Verification

**Commands:**
- `vendor/bin/phpunit` -- suite au vert, après avoir été vue rouge
- `php bin/console lint:twig templates/` -- les quatre templates sont valides
- `php bin/console lint:yaml translations/ config/` -- valide
- `php bin/console debug:config monolog` -- les canaux et les handlers déclarés sont ceux
  du fichier, dans chaque environnement
- `php bin/console debug:translation fr --domain=messages` -- aucune clé manquante
- `make qa` -- les six catégories passent

**Manual checks:**
- `APP_ENV=dev` : `/_error/404`, `/_error/403`, `/_error/500` rendent les trois pages ;
  `/_error/502` rend la générique
- Le code source d'une page d'erreur ne contient ni le code HTTP, ni « Exception », ni un
  nom de classe
- `/_error/404?lang=nl` : la page est en néerlandais
- `var/log/` : une 500 provoquée à la main y laisse une ligne ; une 404 n'y laisse aucune
  erreur
