---
title: 'Bloquer les régressions en intégration continue'
type: 'feature'
created: '2026-09-10'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'e86d9c1acb5c41df75367f3922b0c380f5b38acd'
context:
  - '{project-root}/.claude/skills/symfony-proglab-quality/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-testing/SKILL.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** la story 1.1 a rendu la frontière vérifiable, mais rien ne la vérifie — ni
analyse statique, ni style, ni audit, ni exécution de deptrac, ni tâche locale, ni CI. Un
dérivé peut partir chez un client avec une régression que personne n'a vue.

**Approach :** une porte unique en deux façades vérifiant la même liste de cinq catégories —
analyse statique, style, deptrac, tests, `composer audit` : `make qa` en local, une CI
bloquante en ligne. Base de CI sur le moteur de production, jamais SQLite, et isolation
transactionnelle des tests rendue mécaniquement vérifiable.

## Decisions

Tranché avec Fabrice le 2026-09-10, là où l'architecture était muette ou en contradiction :

1. **MySQL 8.4 LTS** — image de la CI et **plancher contractuel** des serveurs clients ;
   MySQL 8.0 est en fin de vie depuis avril 2026 et ne tient pas la fenêtre Symfony 7.4 LTS.
2. **`serverVersion=8.4` partout**, `.env` comme `.env.test`. Le MySQL 8.0.30 de Laragon
   passe en 8.4 côté Fabrice ; le README nomme l'exigence.
3. **Runners GitHub-hébergés** (`runs-on: ubuntu-latest`). Tension assumée avec « aucun
   service externe payant » : gratuit sur dépôt public, facturé au quota sur dépôt privé.

## Boundaries & Constraints

**Always :**
- Les deux façades vérifient la **même liste de catégories**, tenue par un test, pas par
  relecture. Un job CI par catégorie, nommé d'après elle.
- Outils en `require-dev`, lancés depuis `vendor/bin/`, épinglés par `composer.lock` — même
  version en local et en CI, aucune installation globale.
- Schéma de test monté par les migrations, jamais par `doctrine:schema:create`.
- `dama/doctrine-test-bundle` isole chaque test fonctionnel : bundle dans
  `config/bundles.php` **et** extension dans `phpunit.dist.xml` — les deux lignes ou aucune.
- Le socle reste dérivable : rien dans la porte ne suppose un client ou un secret donné.

**Never :**
- Pas de baseline PHPStan ni d'assouplissement de règle pour faire passer du code existant.
- Pas de PHP_CodeSniffer, `local-php-security-checker`, `symfony check:security`,
  `composer-require-checker`, `composer-unused`, `infection`.
- Pas de `--fail-on-uncovered` sur deptrac : `vendor/` n'est couvert par conception.
- Pas de porte accessibilité — elle appartient à la story 1.4 avec son gabarit.
- Pas de déploiement déclenché par la CI, pas de `--no-dev`, pas de matrice de versions.

## I/O & Edge-Case Matrix

| Scénario | Entrée / État | Sortie attendue | Traitement d'erreur |
|---|---|---|---|
| Porte saine | Base de code propre | Cinq catégories passent, sortie 0 | N/A |
| Type faux | Code violant PHPStan | Échec | Job `Static analysis` |
| Style faux | Fichier mal formaté | Échec | Job `Code style`, avec le diff |
| Frontière violée | `Module` vers `Core\Service` | Échec | Job `Layer contract`, nomme la règle |
| Test rouge | Assertion cassée | Échec | Job `Tests` |
| Paquet vulnérable | Avis connu | Échec | Job `Linters and audits` |
| Parité rompue | Catégorie sur une seule façade | Échec | Le test nomme l'orpheline |
| Isolation absente | Extension DAMA sans le bundle | Échec avant la suite | `debug:config` sort en 1 |
| Test qui écrit | Test fonctionnel écrivant en base | Base rendue à son état initial | Transaction annulée |

</frozen-after-approval>

## Code Map

- `.claude/skills/symfony-proglab-quality/assets/` — les quatre gabarits : `Makefile`
  (cible `qa`), `github-workflow-ci.yml` (5 jobs), `phpstan.dist.neon` (niveau `max`),
  `.php-cs-fixer.dist.php`. **Adapter :** ils épinglent PHP 8.4, `mysql:8.0`, `@PHP82Migration`.
- `…quality/SKILL.md:105-115,165-176,254-278` — `composer audit` sans option,
  `--report-uncovered` sans `--fail-on-uncovered`, le piège DAMA, SQLite interdit.
- `…testing/references/database-tests.md:31-51,61-124` — les deux lignes DAMA, la
  vérification `debug:config`, l'ordre de préparation de la base.
- `composer.json:19-27` — manquent PHPStan, php-cs-fixer, DAMA, bundle de migrations.
- `phpunit.dist.xml` — a déjà `failOnPhpunitNotice` ; **aucun bloc `<extensions>`**.
- `config/bundles.php` (3 bundles) et `config/packages/doctrine.yaml` (bloc `when@test` :
  `dbname_suffix`, mapping `FixtureModule`).
- `.env:38` — DSN MySQL `serverVersion=8.0.32` ; `.env.test` n'a pas de `DATABASE_URL`.
- `tests/Core/BoundaryTest.php` — le précédent : tester un fichier de configuration en
  exécutant l'outil dans un bac à sable jetable, avec garde sur `exec()`.
- **Ne pas toucher :** `deptrac.yaml` (déjà bon, il active le job), `src/`, `templates/`,
  `tests/Fixtures/`, `_bmad/`, `.claude/skills/`, `_bmad-output/planning-artifacts/`.

## Tasks & Acceptance

**Execution :**
- [x] `tests/Core/Quality/` — écrire d'abord et voir rouges : parité des catégories
      `Makefile` ↔ workflow ; les deux lignes DAMA ensemble ; base de CI non-SQLite et
      `serverVersion` cohérent entre `.env`, `.env.test` et workflow ; échec réel de PHPStan
      et de php-cs-fixer sur un défaut injecté en bac à sable
- [x] `composer.json` — `require-dev` : `phpstan/phpstan` + ses cinq extensions,
      `php-cs-fixer/shim`, `dama/doctrine-test-bundle`, `doctrine/doctrine-migrations-bundle`
- [x] `phpstan.dist.neon` — niveau `max`, analyse `src` et `tests`, `containerXmlPath` sur le
      conteneur réellement compilé, aucune baseline
- [x] `.php-cs-fixer.dist.php` — gabarit, `@PHP82Migration` remonté au jeu le plus élevé que
      la version installée expose pour PHP 8.5
- [x] `config/bundles.php` + `phpunit.dist.xml` — DAMA en `test` **et** son extension PHPUnit ;
      activer le bundle de migrations
- [x] `config/packages/doctrine_migrations.yaml`, `migrations/` — la CI monte le schéma comme
      la production
- [x] `.env`, `.env.test` — `serverVersion=8.4` et `DATABASE_URL` explicite en test, pour que
      le suffixe de base ne dépende pas du DSN de développement (décision 2)
- [x] `Makefile` — gabarit ; `importmap:audit` retiré de `audit` avec un commentaire nommant
      la story 1.3 (AssetMapper pas encore là)
- [x] `.github/workflows/ci.yml` — gabarit ; PHP 8.5, `mysql:8.4`, `ubuntu-latest`, même
      retrait d'`importmap:audit`
- [x] `README.md` — `make qa`, ce qu'elle vérifie, et l'exigence MySQL 8.4 en local

**Acceptance Criteria :**
- Given le socle cloné, when je lance `make qa`, then les cinq catégories s'exécutent et la
  commande sort en 0 sur une base saine
- Given un défaut dans une catégorie, when la CI s'exécute, then le job de cette catégorie
  échoue, et lui seul
- Given un test fonctionnel qui écrit en base, when la suite tourne deux fois, then la
  seconde passe : rien n'a été laissé en base
- Given une catégorie ajoutée à une seule façade, when la suite tourne, then le test de
  parité échoue en nommant l'orpheline

## Implementation Notes

Cinq décisions prises pendant l'implémentation, là où la spec ou le gabarit du skill
étaient muets ou faux :

1. **La parité passe par une table `# qa-category:` dans le `Makefile`.** Les six
   prérequis de `qa` ne correspondent pas un pour un aux cinq jobs — `lint` et `audit`
   sont deux cibles pour « Linters and audits ». Renommer les cibles aurait cassé le
   contrat de noms que `symfony-proglab-quality` partage avec `castor.php`, donc la
   correspondance est déclarée en commentaires et lue par le test. Trois assertions en
   découlent : aucune cible de `qa` sans catégorie, aucune catégorie sans cible, et
   égalité des ensembles avec les `name:` des jobs.
2. **`.gitattributes` avec `* text=auto eol=lf` est nécessaire, pas cosmétique.**
   `.editorconfig` demande LF, php-cs-fixer l'impose (`line_ending`, via `@PSR12`), et
   `core.autocrlf=true` de Windows réécrit la copie de travail en CRLF : sans ce fichier,
   `make cs-check` échoue sur chaque fichier en local pendant que la CI Linux passe.
3. **Le `Makefile` bascule sur Git Bash sous Windows.** GNU Make appelle `cmd.exe`, qui
   ne sait exécuter ni `vendor/bin/…` ni `grep`. Les outils PHP sont en outre appelés en
   `php vendor/bin/<outil>` des deux côtés, ce qui vaut sur les deux plateformes.
4. **`tests/object-manager.php` boote le kernel en `test`, pas en `dev`.** C'est le seul
   environnement où le mapping `FixtureModule` existe : en `dev`, `phpstan-doctrine` ne
   reconnaissait pas `DemoWidget` comme entité et signalait son `$id` comme un type
   jamais assigné. Aucune règle assouplie, aucune baseline.
5. **Le gabarit `phpstan.dist.neon` du skill est incomplet.**
   `vendor/phpstan/phpstan-phpunit/rules.neon` seul fait échouer PHPStan au démarrage
   (`CoversHelper` introuvable) ; il faut `extension.neon` avec. Corrigé ici, à remonter
   dans `.claude/skills/symfony-proglab-quality/assets/phpstan.dist.neon`.

**Deux entorses au « Ne pas toucher » du Code Map, toutes deux imposées par le « Never :
pas de baseline PHPStan ni d'assouplissement de règle ».** `src/Core/Controller/HomeController.php`
reçoit un `@return array{modules: list<string>}` (niveau max) et
`src/Core/Service/ModuleRegistry.php` voit son attribut de propriété promue passer à la
ligne (php-cs-fixer). Aucune autre modification de contenu sous `src/` ni sous
`tests/Fixtures/` — le reste du diff y est uniquement une normalisation CRLF → LF.

**Le troisième critère d'acceptation n'a pas de sujet aujourd'hui.** « Un test fonctionnel
qui écrit en base » suppose une entité et une migration, et le socle n'en a aucune :
`src/Core/Entity/` est vide et `migrations/` aussi. Le mécanisme est vérifié trois fois
(les deux lignes DAMA tenues ensemble par un test, `debug:config dama_doctrine_test`
ordonné avant la suite dans le job, et la même commande sortant en 1 quand on retire le
bundle) ; le critère lui-même se referme à la story 1.6, avec l'entité `Utilisateur` et
sa migration.

**Deux corrections apportées à la relecture du diff, après le rapport d'implémentation :**

6. **`php_unit_test_annotation` désactivé dans `.php-cs-fixer.dist.php`.** La règle vient
   de `@Symfony:risky` et appartient à l'ère des annotations `@test` : en style `prefix`
   elle renomme des méthodes qui portent déjà l'attribut `#[Test]`. Elle n'avait attrapé
   qu'une méthode sur les sept de `ModuleWiringTest`, laissant le fichier de la story 1.1
   avec un `testThe_production_wiring_of_the_module_root_is_compiled_in` isolé au milieu de
   six noms en snake_case — et elle contredisait `php_unit_method_casing => false`, deux
   lignes plus bas, dont le commentaire dit que `it_does_something()` est intentionnel. Le
   nom d'origine est restauré. **C'est une désactivation de règle, ce que le bloc gelé
   interdit** : elle est présentée à Fabrice au point de contrôle, et non tenue pour
   acquise. L'argument est que la règle est inapplicable à des tests par attributs, pas
   qu'elle gêne.
7. **`migrations/.gitkeep` supprimé.** Le `migrations/.gitignore` vide de la recette
   Doctrine suffit à faire suivre le répertoire ; deux marqueurs pour le même besoin.

**Les deux appels de Fabrice au point de contrôle, le 2026-09-10 :**

- **`php_unit_test_annotation` reste désactivée.** La règle vise les annotations `@test`,
  que ce code n'utilise pas — il utilise l'attribut `#[Test]`. Inapplicable, donc pas un
  assouplissement au sens du bloc gelé.
- **Le critère d'acceptation n°3 et la ligne de matrice « Test qui écrit » sont reportés à
  la story 1.6.** L'option de créer la table de `DemoWidget` au bootstrap PHPUnit par le
  SchemaTool a été écartée. **La story 1.2 se clôture donc avec un critère d'acceptation
  ouvert**, ainsi que deux lignes de matrice vérifiées par construction plutôt que par un
  test — « Test rouge » (tautologique) et « Paquet vulnérable » (exigerait d'installer un
  paquet réellement vulnérable). Les trois sont consignées dans `deferred-work.md`.

## Spec Change Log

## Review Triage Log

Passe 1 — quatre couches : blind-hunter (14), edge-case-hunter (15), verification-gap (4+2),
proglab-conformance (2). Les trouvailles de verification-gap arrivent pré-vérifiées ; les
autres ont été vérifiées à l'emplacement cité avant verdict.

| # | Couche | Trouvaille | Verdict | Évidence | Route |
|---|---|---|---|---|---|
| 1 | vgap | Les deux défauts injectés sont des règles du cœur PHPStan : les 4 `includes` de jeux de règles peuvent disparaître, suite verte | `medium` | Démontré par la couche, puis reproduit ici : retirer `phpstan-strict-rules` fait maintenant échouer le test | patch |
| 2 | vgap | Le bac à sable de `CodeStyleTest` n'écrit que dans `src/` : réduire le finder à `src/` laisse tout vert | `medium` | Démontré ; `tests/` et `migrations/` cessaient d'être vérifiés en silence | patch |
| 3 | vgap | La parité ne compare que des noms : retirer `--dry-run` du job Code style le rend vert à jamais | `high` | Reproduit ici par mutation : le test le refuse désormais en nommant la catégorie | patch |
| 4 | vgap | L'isolation DAMA n'est vérifiée que par déclaration ; `enable_static_connection: false` la couperait sans un mot | `medium` | `Configuration.php:13,27-28` — nœud à `true` par défaut, `debug:config` sortirait quand même en 0 | defer |
| 5 | vgap | Le commentaire de `phpstan.dist.neon` prétend qu'un `containerXmlPath` faux fait « se taire » PHPStan | `low` | Faux : il avorte en sortie 1. Commentaire corrigé | patch |
| 6 | vgap | Les `#` dans le corps de la recette `audit` sont échoés par make comme des commandes | `low` | Vu dans ma propre sortie de `make qa` | patch |
| 7 | blind | `.editorconfig` impose `indent_style = space` sans bloc `[Makefile]` ni `[*.{yml,yaml}]` | `medium` | Vérifié : le fichier n'a que `[*]` et `[*.md]` — un éditeur conforme casse le Makefile | patch |
| 8 | blind | `config/packages/doctrine.yaml` garde `#server_version: '8.0.32'` | `medium` | Vérifié lignes 5-7 : la seule trace de 8.0 restante, là où un lecteur cherche | patch |
| 9 | blind | Le garde-fou anti-SQLite ne scanne pas `doctrine.yaml`, où un repli s'écrirait | `medium` | Le bloc `when@test` y porte déjà `dbname_suffix` ; un `dbal.url:` suffirait | patch |
| 10 | blind | La cible `test` ne fait pas ce que fait le job Tests (garde-fou, migrations) | `high` | Vérifié : `test:` ne lançait que phpunit — les deux façades divergeaient sur le point central de la story | patch |
| 11 | blind | La parité n'assure ni l'existence des cibles ni la présence d'un `run:` | `medium` | Même cause que #3 | patch |
| 12 | blind | `migrations/` hors de `paths:` PHPStan et du finder, dans la story qui l'introduit | `medium` | Vérifié dans les deux fichiers | patch |
| 13 | blind | `checkUninitializedProperties: false` est un assouplissement global pour des entités inexistantes | `false` | Le réglage vient mot pour mot du gabarit du skill et vise les entités hydratées par Doctrine ; le « Never » du bloc gelé vise l'assouplissement destiné à faire passer du code existant en échec, ce qui n'est pas le cas | rejeté |
| 14 | blind | Rien dans le dépôt ne rend la CI bloquante — c'est la branch protection GitHub | `medium` | Vérifié : le workflow ne déclare que ses déclencheurs. Le correctif n'est pas du code | defer |
| 15 | blind | Aucun `timeout-minutes`, aucun `concurrency` alors que la décision 3 assume la facturation | `medium` | Vérifié dans le workflow | patch |
| 16 | blind | `composer validate --strict` absent : une dérive `composer.json`/`.lock` n'échoue nulle part | `medium` | `composer install` se contente d'avertir | patch |
| 17 | blind | Le témoin de niveau est plus faible que son docblock, aucun défaut n'exerce les `includes` | `medium` | Même cause que #1 | patch |
| 18 | blind | Le bac à sable existe en double, `projectDir()` en triple | `low` | Vérifié : `BoundaryTest`, `SandboxTrait`, `GateFiles`. Un exemplaire retiré ; `BoundaryTest` laissé intact (fichier de la story 1.1) | patch |
| 19 | blind | `sprint-status.yaml` dit `in-progress` pendant que la spec dit `in-review` | `low` | Vrai : la synchronisation du suivi appartient à l'étape de présentation, pas à la relecture | patch |
| 20 | blind | La liste des critères d'acceptation ne marque pas le critère n°3 comme reporté | `medium` | Réel, mais le correctif consiste à éditer la spec de ce build — rejet explicitement prescrit. Porté à la présentation à la place | rejeté |
| 21 | edge | `warmContainer()` : glob jamais satisfait si `APP_DEBUG=0` en dev | `low` | L'échec serait bruyant (PHPStan avorte), pas silencieux ; le correctif ajoute une garde | rejeté |
| 22 | edge | Un second `<bootstrap>` dans `<extensions>` casserait la regex DAMA | `low` | Spéculatif, et le correctif affaiblirait l'ancrage | rejeté |
| 23 | edge | Un service non-base (redis) dans le job Tests ferait échouer le test de moteur | `low` | Le correctif proposé (assertContains) affaiblirait la vérification | rejeté |
| 24 | edge | CRLF : un `\r` capturé dans le `serverVersion` | `false` | Les deux DSN sont entre guillemets, et la regex s'arrête au guillemet fermant : le `\r` n'est jamais capturé. `.gitattributes` referme le reste | rejeté |
| 25 | edge | Un `qa:` coupé par une contre-oblique ferait passer des cibles pour orphelines | `low` | Hypothétique — `qa:` tient sur une ligne | rejeté |
| 26 | edge | Un job hors catégorie (check agrégé) ferait échouer la parité | `low` | Probable dès que la branch protection sera posée (#14) ; consigné plutôt que corrigé par anticipation | defer |
| 27 | edge | PHP sous `migrations/`, `config/`, `bin/`, `public/` hors analyse et hors style | `medium` | Même cause que #12 ; `migrations/` corrigé, les trois autres sont hors du périmètre du gabarit maison | patch |
| 28 | edge | `make qa` sur un clone sans schéma `app_test` | `medium` | Même cause que #10 | patch |
| 29 | edge | Le healthcheck MySQL peut répondre au serveur d'initialisation temporaire | `medium` | Défaut connu de l'image ; `--health-start-period` absent | patch |
| 30 | edge | `object-manager.php` : `bootEnv` en `APP_ENV=dev` alors que le kernel boote en `test` | `low` | Vrai : `.env.test.local` n'était jamais chargé. Correctif d'une ligne | patch |
| 31 | edge | `sandboxWith()` appelé deux fois abandonne le premier bac à sable | `low` | Vrai, correctif direct de deux lignes | patch |
| 32 | edge | `php_unit_test_annotation` désactivée : une méthode en `/** @test */` ne tournerait jamais | `low` | PHPUnit 13 ignore de toute façon les métadonnées en docblock, donc la règle n'était pas un filet ; tout le code utilise `#[Test]` ; désactivation tranchée par Fabrice | rejeté |
| 33 | edge | Une violation de couche rougit `Tests` **et** `Layer contract`, contre « et lui seul » | `medium` | Vérifié : `BoundaryTest::the_delivered_code_base_has_no_layer_violation` lançait deptrac sur le vrai dépôt. Test retiré ; le test « uncovered » reste, seul endroit qui échoue dessus | patch |
| 34 | edge | Aucun test fonctionnel n'écrit en base (critère n°3) | `medium` | Doublon de #4 | defer |
| 35 | edge | La CI n'est pas bloquante sans branch protection | `medium` | Doublon de #14 | defer |
| 36 | conf | Le finder php-cs-fixer réduit à `src/` + `tests/` laisse `config/`, `public/` hors style | `low` | La couche affirme que la recette livre `->in(__DIR__)` ; le gabarit du skill livre bien `src` + `tests`. La déviation alléguée est fausse ; le trou `migrations/` est réel et traité en #12 | rejeté |
| 37 | conf | Règle 1 : l'isolation est activée sans qu'aucun test n'exerce le rollback | `medium` | Doublon de #4, déjà tranché par Fabrice | defer |

## Design Notes

**Pourquoi tester des fichiers de configuration.** Une porte de qualité ne se casse pas, elle
se désaligne, et personne ne l'exécute en développement. Les trois désalignements coûteux sont
connus : une catégorie ajoutée d'un seul côté, l'extension DAMA sans son bundle (suite verte
qui écrit réellement en base), un moteur de test qui dérive de la production. Tous trois se
lisent dans des fichiers, donc trois tests les tiennent. Précédent : `BoundaryTest`.

**La parité porte sur les catégories, pas sur les commandes.** Un `Makefile` enchaîne des
cibles, un workflow déclare des jobs : les formes diffèrent par nature. Le test lit les
prérequis de `qa` et les `name:` des jobs, et affirme la même liste des deux côtés.

**`importmap:audit` manque volontairement.** Le standard l'associe à `composer audit` — sans
npm, rien d'autre ne signale une dépendance JavaScript vulnérable. AssetMapper arrive à la
story 1.3 ; la commande échouerait aujourd'hui. Retirée avec un commentaire qui nomme la
story, jamais gardée derrière une condition : une garde silencieuse est exactement ce que ce
standard reproche au reste de l'outillage.

**Limite assumée :** l'exécution réelle du workflow demande un runner GitHub. Un agent local
vérifie syntaxe, parité et comportement des outils, pas le vert d'un job ; le premier `push`
referme l'écart.

## Verification

**Commands :**
- `composer install` — aucun conflit, aucun paquet exigeant Symfony 8
- `php bin/console --env=test debug:config dama_doctrine_test` — sortie 0
- `vendor/bin/phpunit` — suite au vert, après avoir été vue rouge
- `make qa` — les cinq catégories s'exécutent, la porte sort en 0
- `php -l .php-cs-fixer.dist.php` et `php bin/console lint:yaml .github/workflows/` — valides
