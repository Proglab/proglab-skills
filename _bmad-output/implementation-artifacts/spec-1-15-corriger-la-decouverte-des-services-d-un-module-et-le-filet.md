---
title: "Corriger la découverte des services d'un module et le filet de la frontière"
type: 'bugfix'
created: '2026-09-19'
status: 'done'
route: 'dispatch'
review_loop_iteration: 3
baseline_commit: '542eb80ee2c5f4c6b9c10b20b8d7a408d64900d8'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** deux oublis du même mécanisme, trouvés en écrivant le guide (story 1.12,
constats R12 et R6 de `deferred-work.md`), que la 1.12 s'interdisait de corriger.
(1) Le glob des modules (`config/services.yaml:75`) n'exclut que `{Dto,Entity}` là où le
socle en exclut six : le premier module qui livre `Enum/`, `Exception/` ou `Message/`
verra ces classes proposées au conteneur, et `lint:container` tranchera chez le dérivé.
(2) Le filet `UndeclaredModule` ne tient pas ce que ses commentaires promettent : sortir un
module du `must_not` **sans** déclarer son bloc de couche le laisse sans couche de racine,
donc libre d'atteindre `Core`, et deptrac sort en 0.

**Approche :** aligner les deux globs de modules sur la liste du socle, chaque exclusion
justifiée sur place ; le prouver par des classes de fixture réelles ; étendre le contrôle
des quatre éditions de `deptrac.yaml` aux modules d'un dérivé et à l'état interdit
« exclusion sans bloc de couche » ; corriger les deux commentaires qui sur-affirment ;
effacer du guide la limite et la vérification manuelle que le socle fait désormais.

## Boundaries & Constraints

**Always :**

- Le test d'abord, rouge avant le code (règle 1 du socle).
- Les deux globs de modules restent l'un sous l'autre dans le même fichier et portent la
  même liste : c'est là qu'une divergence de forme se voit à l'œil nu (en-tête du fichier).
- Chaque exclusion porte sa raison sur place, dans le style du bloc du socle (l. 53-68).
- Ajouter un module continue de ne modifier aucun fichier de `src/Core/` ni de `config/`
  (AD-13, NFR-6) : cette story y touche une fois, pour tous les modules à venir.
- Aucune cible `make` ni aucun job de CI ajouté ; la porte garde ses six catégories.

**Décisions tranchées à la rédaction :**

- **Liste identique des deux côtés, `Contract` compris.** L'AC tolère un écart justifié ;
  zéro écart vaut mieux — deux listes identiques se comparent à l'œil, et une exclusion
  n'est pas une autorisation : le commentaire dit qu'un module ne livre jamais `Contract/`
  (guide, étape 2) et que l'exclusion empêche l'erreur de devenir un service.
- **Un seul lot** (l'epic en autorise deux ; l'empreinte ne le justifie pas), et les
  classes de fixture vont dans le module `Demo`.

**Never :**

- Pas de nouveau module de fixture, pas de renommage de `Demo` ni de `Billing`, rien sous
  `src/Module/` — il reste vide (`the_module_root_ships_empty()`).
- On ne cherche pas à rendre l'état « exclusion sans bloc de couche » impossible *dans
  deptrac* : il ne valide pas qu'un ruleset nomme une couche déclarée. On le rend visible
  dans la suite.
- Aucune couche ni aucun collecteur modifié dans `deptrac.yaml` : seuls ses commentaires.
- **Pas de correctif sur `app.name` ici** (tranché par Fabrice au checkpoint, option (a)) :
  `config/services.yaml` se rouvre pour les globs, pas pour le trou `APP_NAME` vide. Le
  report de `deferred-work.md` (2026-09-18) reste ouvert jusqu'à la story qui possède les
  emails — un repli `default:` masquerait l'erreur au dérivé mal configuré, et le dire
  dans `app:deployment:check` ouvrirait une commande hors sujet.

## I/O & Edge-Case Matrix

| Scénario | Entrée / État | Comportement attendu | Erreur |
|---|---|---|---|
| Module livrant `Enum/` | enum dans un module | jamais proposé comme service, comme celui du socle | N/A |
| Module livrant `Exception/` | exception métier dans un module | idem — objet construit par l'appelant | N/A |
| Module livrant `Message/` | message dans un module | idem ; un `MessageHandler/` reste un service | N/A |
| Miroir divergent | un seul des deux globs modifié | un test rougit en nommant le glob en retard | N/A |
| Module d'un dérivé | `src/Module/<Nom>/` déclaré | ses quatre éditions deptrac sont vérifiées comme celles d'une fixture | N/A |
| Exclusion sans bloc de couche | `must_not` exclut `Module<Nom>`, couche absente | un test rougit en nommant l'édition manquante | N/A |
| Socle livré tel quel | `src/Module/` vide | rien ne rougit, rien à sauter | N/A |

</frozen-after-approval>

## Code Map

- `config/services.yaml:72-75` — le glob des modules (`exclude: '…/{Dto,Entity}'`) et son
  commentaire d'une ligne. Modèle à suivre juste au-dessus, `:53-70` : le bloc du socle
  écrit la raison de chacune de ses exclusions.
- `config/services.yaml:178-180` — le miroir `when@test` sur `tests/Fixtures/Module/*/`.
- `tests/Core/ModuleWiringTest.php` — propriétaire du câblage par glob (`WebTestCase`) ;
  `the_service_of_a_module_is_discovered_behind_the_core_contract()` est le modèle positif.
- `tests/Fixtures/Module/Demo/` — `Exception/` existe avec un `.gitkeep` ; `Enum/` et
  `Message/` sont à créer. `DemoWidget` et `DemoDescriptor` donnent la forme des classes.
- `tests/Core/Documentation/DerivationGuideTest.php:133-277` — famille (c), « ce que coûte
  un module dans deptrac.yaml » : cinq tests sur `lesModulesDeDemonstration()` (`:274`, qui
  ne balaie que `tests/Fixtures/Module`) et les helpers `deptracNode()`, `layer()`,
  `rulesetOf()`, `occurrences()`, `directoriesOf()`.
- `deptrac.yaml:33-39` (en-tête, « En oublier une ne rouvre pas la frontière ») et
  `:166-176` (commentaire d'`UndeclaredModule`, « L'oublier ne rouvre rien ») — les deux
  phrases fausses pour le bloc de couche.
- `docs/DERIVATION.md:231` (ligne du tableau « sauf `Dto/` et `Entity/` »), `:250-302`
  (étape 6 ; `:296-301` impose la vérification **manuelle** que le socle fait désormais —
  c'est la seconde « limite », elle est ici et non dans la liste), `:589-596` (la seule
  puce de « Limites assumées » à supprimer).
- `tests/Core/BoundaryTest.php` — `a_module_nobody_declared_is_refused_everything()` : le
  filet vu de l'extérieur, en bac à sable. Il ne change pas.

## Tasks & Acceptance

**Execution :**

- [x] `tests/Core/ModuleWiringTest.php` -- trois tests écrits **avant** le code : aucune
      classe des dossiers `Enum/`, `Exception/` et `Message/` d'un module n'est une
      définition du conteneur, et les deux globs excluent la même liste que le socle --
      la régression ne peut plus revenir en silence. Piège : `has()` répond « non » aussi
      pour un service supprimé comme inutilisé (voir Design Notes).
- [x] `tests/Fixtures/Module/Demo/Enum/…`, `…/Exception/…`, `…/Message/…` -- trois classes
      minimales, et le `.gitkeep` devenu inutile retiré -- sans classe réelle dans ces
      dossiers, le test ci-dessus ne prouve rien.
- [x] `config/services.yaml` -- aligner les deux globs de modules sur
      `{Contract,Dto,Entity,Enum,Exception,Message}` et écrire la raison de chaque
      exclusion -- un module ne propose jamais comme service ce que le socle exclut.
- [x] `tests/Core/Documentation/DerivationGuideTest.php` -- étendre le fournisseur de la
      famille (c) aux modules de `src/Module/`, et ajouter un test qui exige, pour chaque
      exclusion du `must_not` d'`UndeclaredModule`, son bloc de couche, sa ligne de ruleset
      et son nom dans `AnyRoot`, en nommant l'édition manquante -- c'est l'état qui ouvre
      la frontière en silence.
- [x] `deptrac.yaml` -- corriger l'en-tête et le commentaire d'`UndeclaredModule` : oublier
      la ligne de ruleset, le nom dans `AnyRoot` ou l'exclusion ne rouvre rien, mais
      l'exclusion sans bloc de couche laisse le module sans couche de racine, donc libre
      d'atteindre `Core` ; nommer le test qui le tient -- un commentaire qui sur-affirme
      est pire qu'aucun commentaire.
- [x] `docs/DERIVATION.md` -- supprimer la puce de « Limites assumées », corriger la ligne
      du tableau, remplacer la vérification manuelle de l'étape 6 par ce que le socle
      vérifie désormais -- le guide ne décrit plus un manque comblé.

**Acceptance Criteria :**

- Given un module qui livre `Enum/`, `Exception/` ou `Message/`, when le conteneur se
  construit dans les deux environnements de `lint:container`, then ces classes ne sont pas
  proposées comme services, exactement comme celles du socle.
- Given le glob des modules et son miroir `when@test`, when je les compare à celui du
  socle, then ils excluent la même liste de dossiers — au moins `Enum`, `Exception` et
  `Message` en plus de `Dto` et `Entity` — et tout écart qui subsiste est justifié sur
  place, là où le socle écrit déjà la raison de chacune de ses exclusions.
- Given un module de fixture qui livre l'un de ces dossiers, when la suite tourne, then un
  test le prouve, et la régression ne peut plus revenir en silence.
- Given un module retiré du `must_not` d'`UndeclaredModule` sans son bloc de couche, sa
  ligne de ruleset ou son nom dans `AnyRoot`, when la suite tourne, then un test rougit en
  nommant l'édition manquante — y compris pour les modules d'un dérivé.
- Given les commentaires de `deptrac.yaml`, when je les lis, then ils disent ce que le
  filet tient réellement.
- Given `docs/DERIVATION.md`, when je cherche ces deux limites, then elles n'y sont plus et
  la recette « ajouter un module » dit ce que le socle vérifie à la place du lecteur.
- Given la porte de qualité, when `make qa` tourne, then les six catégories passent sans
  qu'aucune cible ni aucun job n'ait été ajouté.

## Implementation Notes

**Boucle TDD, et ce qui a été vu rouge.** Les tests d'abord, dans l'ordre du spec.

- `ModuleWiringTest` : rouge cinq fois avant le changement de `config/services.yaml` —
  les trois classes de fixture étaient bien des définitions du conteneur (le message
  nomme la classe), et les deux globs excluaient `{Dto,Entity}` là où le socle exclut six
  dossiers (le message nomme le glob en retard, un cas de provider par glob). Vert après.
- `DerivationGuideTest::the_module_of_a_derivative_is_swept_like_a_fixture_one()` : vu
  rouge en retirant `src/Module` de `MODULE_ROOTS`, puis restauré.
- `every_exclusion_of_the_undeclared_module_net_carries_its_three_other_editions()` : ce
  rouge est **impossible dans un dépôt cohérent**, donc vérification par mutation, trois
  fois, sur `ModuleBilling` — bloc de couche retiré, `AnyRoot` retiré, ligne de ruleset
  retirée. Les trois rougissent en nommant l'édition manquante, et le message du bloc de
  couche dit pourquoi cet état-là est le dangereux. `deptrac.yaml` restauré à l'octet près
  (`git diff` vide) avant de continuer.

**Décisions prises que le spec ne tranchait pas.**

1. **Une quatrième classe de fixture, `MessageHandler/RefreshDemoWidgetHandler`**, là où
   la tâche en demandait trois. La ligne « Module livrant `Message/` » de la matrice gelée
   attend aussi « un `MessageHandler/` reste un service » : sans cette classe, cette
   moitié de la ligne n'a aucun test. Elle sert de **contrôle positif** — sans elle, une
   erreur de lecture du dump rendrait les trois autres assertions vertes pour rien.
2. **Le dump lu en processus, pas par `Process`.** Les Design Notes laissaient le choix
   entre `debug:container --show-hidden` lancé par `Process` et le dump XML. C'est le
   second : le paramètre `debug.container.dump` donne le chemin, et
   `ContainerBuilderDebugDumpPass` est enregistrée en `TYPE_BEFORE_REMOVING`
   (`vendor/symfony/framework-bundle/FrameworkBundle.php:219`), donc le dump porte bien
   les définitions avant la passe de suppression. Un `Process` par cas de provider aurait
   coûté quatre démarrages de kernel pour la même information.
3. **`assertTrue(class_exists($class))` en tête du test de non-définition.** Piège
   rencontré en écrivant le test : `X::class` est résolu à la **compilation**, sans
   autoload — le test est donc passé au vert avant même que les classes de fixture
   existent. Sans cette ligne, supprimer une fixture ne ferait rien rougir.
4. **Les cinq tests de la famille (c) renommés** `..._fixture_module_...` →
   `..._declared_module_...`, et `lesModulesDeDemonstration()` →
   `lesModulesDeclares()` : le fournisseur ne balaie plus seulement les fixtures. Aucun
   fichier du dépôt ne référençait ces noms (seuls les specs 1.12 et 1.14, historiques,
   les citent).
5. **Une entrée `status: CLOS` appendée à `deferred-work.md`** pour les reports R12 et R6,
   sur le patron de la story 1.14. Le fichier est append-only et les deux entrées
   d'origine restent en place. Le report `APP_NAME` y est nommé comme restant ouvert, avec
   la raison tranchée par Fabrice — hors périmètre ici.

**Ce qui a été touché, et pourquoi.**

- `config/services.yaml` — les deux globs alignés sur
  `{Contract,Dto,Entity,Enum,Exception,Message}` ; six raisons écrites une fois, sur le
  glob de production, et le miroir renvoie à elles plutôt que de les recopier.
- `tests/Fixtures/Module/Demo/{Enum,Exception,Message,MessageHandler}/` — quatre classes
  minimales ; le `.gitkeep` d'`Exception/` retiré. Le message ne porte **pas**
  `#[AsMessage]` : il n'est routé nulle part et n'est jamais dispatché.
- `tests/Core/ModuleWiringTest.php` — quatre méthodes de test et deux lecteurs privés.
- `tests/Core/Documentation/DerivationGuideTest.php` — `MODULE_ROOTS`, le fournisseur des
  deux racines, `directoriesOf(..., $mayBeEmpty)`, le test de balayage du dérivé, le test
  du filet lu à l'envers, et `exclusionsOfTheNet()` extrait du test existant pour éviter
  la duplication.
- `deptrac.yaml` — **commentaires seuls**, aucune couche ni collecteur modifié (vérifié :
  `vendor/bin/deptrac analyse` donne 0 violation, 0 erreur, comme avant).
- `docs/DERIVATION.md` — ligne du tableau de l'étape 4 corrigée et complétée d'un
  paragraphe sur la liste d'exclusion, vérification manuelle de l'étape 6 remplacée par le
  nom du test qui la fait, puce de « Limites assumées » supprimée.

**Surprises.**

- `deptrac.yaml` est en **CRLF** là où `docs/DERIVATION.md` est en LF ; les remplacements
  y ont été faits par script avec les fins de ligne explicites.
- `src/Module/` vide faisait lever `directoriesOf()` (« ne contient aucun dossier ») : le
  drapeau `$mayBeEmpty` existe pour cette seule racine, et la ligne « Socle livré tel
  quel » de la matrice est exactement ce cas — rien ne rougit, rien n'est sauté.
- MySQL n'était pas démarré au début de la session ; `the_route_of_a_module_is_loaded`
  échouait pour cette raison seule, sans rapport avec la story.

**Ce qui a été vu et volontairement pas fait.** Le glob de `config/routes.yaml` ne vise
que `Controller/`, et ceux de `doctrine.yaml` et `translation.yaml` visent la racine du
module : aucun des trois n'a de liste d'exclusion à aligner. La question « un module
devrait-il pouvoir livrer `Contract/` » n'est pas rouverte : l'exclusion l'empêche de
devenir un service, elle ne décide pas de la frontière.

### Boucle de revue 1 — huit patchs appliqués

**P1 (bloquant).** La sonde `the_module_of_a_derivative_is_swept_like_a_fixture_one()`
créait `src/Module/DerivationGuideProbe` dans l'arbre réel. Le mtime de `src/Module/`
périmait une `DirectoryResource` du conteneur ; `Kernel::$freshCache` mémorisant la
fraîcheur par processus, le dump restait périmé pour tout le run et `EmailFailurePolicyTest`
tombait sur `Bundle::$container must not be accessed before initialization`. Corrigé en
rendant la racine balayée **injectable** : `directoriesOf($path, $mayBeEmpty, $base)` et
`modulesUnder($base)`, `lesModulesDeclares()` n'étant plus que `modulesUnder(projectDir())`.
La sonde monte les **deux** racines d'un dérivé imaginaire dans
`var/documentation/<hex>` — le patron de `SandboxTrait` — et rien n'est écrit sous `src/`.
La preuve ne bouge pas : `modulesUnder()` lit `MODULE_ROOTS`, donc retirer `src/Module` de
la constante rougit toujours la sonde (revérifié). `make test` : **rouge 2/2 avant, vert
2/2 après**.

**P2.** L'en-tête de `deptrac.yaml` disait « le module reste attrapé par
`UndeclaredModule` » pour les trois oublis. Vrai du seul oubli de l'exclusion. Le
paragraphe distingue maintenant les trois cas, sur la formulation déjà juste du guide
(`docs/DERIVATION.md`, étape 6) : sans l'exclusion, c'est le filet ; sans la ligne de
ruleset, c'est sa propre couche de racine qui n'autorise plus rien ; sans le nom dans
`AnyRoot`, aucune couche technique ne voit sa racine. Le commentaire de la couche
(`:182-184`) n'a pas été touché, il était correct.

**P3.** Le message d'échec de `no_data_class_of_a_module_is_offered_to_the_container()`
renvoyait à `lint:container`. Faux, et le docbloc vingt lignes plus haut disait déjà
pourquoi : services privés inutilisés, retirés par la passe de suppression que
`ContainerLintCommand` conserve, donc `lint:container` sort en 0. Le message dit
maintenant que ce test est le seul endroit qui voit le défaut.

**P4.** `excludedDirectoriesOf()` ne comparait que la liste `{…}` et jetait le chemin :
`exclude: '../src/Module/{…}'`, sans le segment d'étoile, restait vert alors que plus
aucune exclusion ne tombe sur un module. L'assertion porte désormais sur le glob entier,
le chemin attendu étant le **`resource` du bloc lui-même** (`discoveryBlock()`), et la
liste celle du socle — accolades et ordre compris. Mutation jouée avec exactement la
sonde du reviewer : rouge, diff des deux chaînes.

**P5.** `lesExclusionsDuFiletDesModulesNonDeclares()` levait dans son `foreach` ; PHPUnit
matérialisant le fournisseur en entier, une exclusion hors forme emportait **tous** les
cas. Le fournisseur rend maintenant `[$exclusion, ?string $module]`, et c'est la première
assertion du test qui nomme la forme attendue. Les autres exclusions restent vérifiées.

**P6.** Le collecteur `(src|tests/Fixtures)/Module/%s/.*` ne porte pas la racine : un
dérivé créant `src/Module/Billing/` partagerait la couche `ModuleBilling` avec la fixture
et passerait les cinq tests de la famille (c) sans une ligne de `deptrac.yaml`. Nouveau
test `a_module_name_belongs_to_one_root_only()` — le plus petit correctif, et il ne touche
ni les couches ni les collecteurs, que le bloc gelé protège. Vérifié rouge en créant
`src/Module/Billing/` le temps d'une exécution filtrée, puis retiré et cache vidé.

**P7.** Le docbloc du test inverse affirmait que les quatre méthodes forward « ne peuvent
rien dire de l'état inverse ». Plus vrai depuis que `lesModulesDeclares()` balaie
`src/Module/` : un module *qui existe* est déjà tenu par
`the_layer_block_of_every_declared_module_is_the_one_the_guide_prescribes()`. Ce que le
test inverse ferme en propre est l'**exclusion orpheline** — module renommé ou supprimé,
exclusion restée. Dit dans le docbloc et dans les deux reprises de `deptrac.yaml`.

**P8.** Inventaire du module de démonstration mis à jour dans `docs/DERIVATION.md` :
`Exception/` quitte la liste des dossiers vides, `Enum/`, `Message/` et `MessageHandler/`
sont nommés avec leur fichier, et le paragraphe qui suit dit ce que ces quatre classes
prouvent. La liste d'exclusion est désormais **ancrée** et non plus seulement en prose :
`the_exclusion_list_of_the_module_glob_is_named_by_the_guide()` exige que le guide porte
la valeur exacte de `services.App\Module\.exclude`, ligne entière et non six noms —
mutation vérifiée. Un septième dossier ajouté à `config/services.yaml` rougit donc le
guide.

**Vérifications.** `make test` vert deux fois de suite (534 tests) ; suite
« Accessibility » verte (22 tests) ; `phpstan` sans erreur ; `php-cs-fixer` sans
correction ; `deptrac` 0 violation / 0 erreur et un diff qui ne contient que des lignes de
commentaire ; `lint:container` vert dans les deux environnements ; `lint:yaml` vert. Aucun
reliquat : `src/Module/` ne contient que son `.gitkeep`, et `var/documentation/` est
ignoré par git au même titre que `var/boundary/` et `var/quality/`.

### Boucle de revue 2 — neuf patchs appliqués

**P9.** `definitionIdsOfTheTestContainer()` comptait les définitions **refusées** comme
des définitions : `FileLoader::addContainerExcludedTag()` enregistre la classe écartée,
abstraite et taguée `container.excluded`, et une classe abstraite reçoit en plus un id
préfixé `.abstract.`. Le helper filtre désormais les deux. Vérifié par mutation :
`#[Exclude]` posé sur `RefreshDemoWidgetHandler` fait rougir
`the_message_handler_of_a_module_stays_a_service()`, alors qu'il restait vert avant.

**P10.** La garde demandée existe, mais **pas** sous la forme `hasParameter()` :
l'extension Symfony de PHPStan prouve que ce paramètre existe (elle lit le conteneur de
dev, où `kernel.debug` est vrai), donc `!hasParameter(…)` est signalé
`booleanNot.alwaysFalse` et l'ignorer aurait demandé une suppression que le socle
interdit. Le chemin est donc **composé** de `kernel.build_dir` et
`kernel.container_class` — les deux existent dans tous les environnements, et leur
composition est exactement celle que `FrameworkExtension` donne à `debug.container.dump`.
La garde devient `is_file()`, qui est la condition honnête. Vérifié :
`APP_DEBUG=0 phpunit` rend le message « … Ce test demande `APP_DEBUG=1`. » au lieu d'une
`ParameterNotFoundException`.

**P11.** Nouveau `the_core_glob_excludes_under_its_own_resource()` : l'oracle obéit à la
règle qu'il impose. Mutation `exclude: '../src/Core/*/{…}'` : le nouveau test rougit, et
les deux cas de `every_module_glob_excludes_what_the_core_excludes()` restent verts —
c'est-à-dire exactement le trou décrit.

**P12.** La clé du cas porte son rang avant sa valeur. Vérifié en dupliquant la ligne
`must_not` de `Billing` : la suite garde ses 53 cas et fait rougir
`a_declared_module_costs_nothing_else_in_deptrac`, là où une clé dupliquée aurait donné
« The key … has already been defined » puis « No tests found in class ».

**P13 et P14.** La première tentative — passer la racine en argument du fournisseur — est
**impossible** : PHPUnit 13 refuse un fournisseur qui déclare un paramètre, fût-il
optionnel (« Data Provider method … expects an argument »). Et la variante « comparer
`lesModulesDeclares()` à `modulesUnder(projectDir())` » est vacue tant que `src/Module/`
est vide — mesuré, la mutation restait verte. La racine balayée est donc une propriété,
`self::$sweptRoot`, que `directoriesOf()` lit et qu'un unique helper `sweeping()` détourne
le temps d'une sonde, avec remise à zéro en `finally`. Les deux sondes interrogent
maintenant le **code réellement câblé** : `lesModulesDeclares()` pour P13,
`moduleNamesSharedByBothRoots()` pour P14. Quatre mutations vérifiées — `MODULE_ROOTS`
sans `src/Module`, et le fournisseur ramené à `directoriesOf('tests/Fixtures/Module')` —
les deux sondes rougissent dans les deux cas.

**P15.** Le test inverse exige d'abord qu'une des deux racines porte un répertoire du même
nom, **avant** de parler d'édition manquante : une exclusion orpheline demande de
supprimer quatre lignes, pas d'en écrire une. Mutation : une exclusion `Ghost` ajoutée au
`must_not` rougit en la nommant orpheline.

**P16.** `DemoWidgetNotFound` porte `#[WithHttpStatus(404)]` et
`#[WithLogLevel(LogLevel::INFO)]`. **Statut en littéral** : `Response::HTTP_NOT_FOUND`
fait échouer deptrac (`Exception` ne voit pas `Http`), et le docbloc de la classe comme la
ligne `Exception/` du tableau du guide le disent maintenant. La ligne du guide nomme aussi
la seule dérogation — un échec qui ne franchit jamais HTTP écrit son absence, patron de
`App\Core\Exception\InitializationFailed`.

**P17.** La ligne `Message/` du tableau du guide dit que le routage vit sur la classe
(`#[AsMessage('async')]`) et ce que coûte son absence : un handler exécuté en synchrone
dans la requête. Le docbloc de `RefreshDemoWidget` et le paragraphe du guide qui désigne
ces quatre classes comme « la forme à recopier » nomment explicitement l'attribut comme
**la seule chose à ne pas recopier** de cette fixture, et pourquoi.

**P18.** Nouveau `every_directory_of_the_demonstration_module_is_named_by_the_guide()`,
sur le patron exact de son voisin `src/Core/`, plus `` `translations/` `` ajouté à
l'inventaire du guide pour que le balayage passe. Mutation : un `Projection/` déposé dans
`Demo/` rougit en le nommant. **Limite écrite dans le docbloc** : l'ancre cherche
`` `<Dossier>/` `` n'importe où dans le guide, donc un dossier que le tableau des couches
nomme déjà — `Form/`, le cas cité en revue — passerait sans rejoindre l'inventaire.
Ancrer la liste elle-même figerait la forme du guide, ce que ce fichier s'interdit
explicitement.

**Vérifications.** `make test` vert deux fois (549 tests) ; « Accessibility » vert
(22 tests) ; `phpstan` sans erreur et sans suppression ; `php-cs-fixer` sans correction ;
`deptrac` 0 violation / 0 erreur ; `lint:container` vert dans les deux environnements ;
`lint:yaml` et `composer validate --strict` verts. `src/Module/` ne contient que son
`.gitkeep`.

**Un incident à connaître.** Pendant la mutation de P9, un `git checkout --` lancé sur un
fichier encore non suivi a vidé `RefreshDemoWidgetHandler.php` ; le fichier a été
réécrit à l'identique et la suite revérifiée. Aucun autre fichier n'a été touché.

### Boucle de revue 3 — trois patchs appliqués

**P19.** La limite que la boucle 2 avait écrite en P18 était plus étroite que la réalité :
le tableau des couches nomme **treize des quatorze** dossiers de `Demo/`, donc une ancre
cherchée dans tout le guide était verte quoi qu'il arrive, et `Form/` n'était pas
l'exception mais la règle. Pire, les lignes ajoutées en P8 (`` `Enum/DemoWidgetStatus.php` ``
et consorts) ne satisfaisaient **aucune** ancre, faute de contenir `` `Enum/` `` fermé.
L'option retenue est la première proposée : l'ancre est cherchée dans le **bloc
d'inventaire** seul, extrait par `demonstrationInventory()` à partir de
`INVENTORY_OPENING` (« Il contient : ») jusqu'à la ligne vide qui ferme la liste, et elle
est **ouvrante** (`` `Enum/ ``) pour accepter les trois formes qui vivent dans la liste :
le dossier seul, le dossier suivi d'un fichier, le dossier suivi d'un sous-dossier. Le
test est renommé `every_directory_of_the_demonstration_module_is_listed_by_the_guide()`.

C'est une contrainte de forme imposée au guide — la seule de ce fichier — et elle est
assumée dans le docbloc de la constante, avec un message d'échec qui la nomme si le bloc
disparaît.

Trois mutations, dont les deux du reviewer rejouées à l'identique : bloc d'inventaire vidé
sauf `translations/` → **13 rouges sur 14** (c'était 13 verts sur 14) ; régression exacte
d'avant P8 → `Enum/`, `Message/` et `MessageHandler/` rouges ; `Demo/Form/` créé →
rouge, alors que le tableau des couches nomme `Form/`. À noter, et c'est honnête : dans la
seconde mutation `Exception/` reste vert, parce que le dossier **est** listé, rangé parmi
les « (vides) ». Le test garde la présence d'un dossier dans l'inventaire, pas la
justesse de la prose qui l'accompagne.

**P20.** L'en-tête de `deptrac.yaml` attribuait à `BoundaryTest` la configuration
« trois éditions présentes, exclusion manquante », que
`a_module_nobody_declared_is_refused_everything()` n'exerce pas — il monte un module qui
n'a aucune des quatre. Le paragraphe dit maintenant ce que ce test couvre réellement (la
couche elle-même), que l'oubli de la seule exclusion place la classe dans deux couches de
racine à la fois, et qu'**aucun test ne l'exerce**. Aucun cas ajouté à `BoundaryTest`,
c'est consigné en report.

**P21.** La ligne `Message/` du tableau renvoyait à un « bloc `routing:` de
`config/packages/messenger.yaml` » qui n'existe pas — le fichier écrit littéralement
« Aucun `routing:` ici ». La règle est réécrite telle qu'elle est : le fichier n'en écrit
aucun, et un bloc de routage n'y serait justifié que pour un message tiers.

**Vérifications.** `make test` vert (549 tests) ; « Accessibility » vert (22 tests) ;
`phpstan` sans erreur ; `php-cs-fixer` sans correction restante ; `deptrac` 0 violation /
0 erreur ; `lint:container` vert dans les deux environnements ; `lint:yaml` et
`composer validate --strict` verts.

**Pas fait, sur consigne** : l'assertion qui exigerait `#[AsMessage]` sur toute classe des
`Message/` des deux racines. La règle que le guide énonce désormais n'est effectivement
tenue par personne et `RefreshDemoWidget` en est le contre-exemple assumé ; c'est une
surface de vérification nouvelle, consignée en report.

## Spec Change Log

## Review Triage Log

**Boucle 1** (4 lentilles + porte). Porte rouge : `make test`.

- **patch** -- `DerivationGuideTest::the_module_of_a_derivative_is_swept_like_a_fixture_one()`
  crée `src/Module/DerivationGuideProbe` dans l'arbre réel : le mtime de `src/Module/`
  périme une `DirectoryResource` du conteneur, `Kernel::$freshCache` mémorise la fraîcheur
  par processus, et `EmailFailurePolicyTest` tombe ensuite sur
  `Bundle::$container must not be accessed before initialization`. Preuve : `make test`
  rouge 2/2, `phpunit` nu vert ; la CI enchaîne les mêmes étapes. Même racine que le
  reliquat invisible pour git (le `finally` ne couvre pas un processus tué) et que la règle
  du bac à sable de `BoundaryTest`.
- **patch** -- `deptrac.yaml:36-39` : la raison écrite est fausse pour 2 de ses 3 cas.
  Oublier la ligne de ruleset ou le nom dans `AnyRoot` n'« attrape pas le module par la
  couche `UndeclaredModule` » : il en est exclu. Preuve : variantes jouées en bac à sable,
  violations émises par la couche du module, jamais par le filet. `docs/DERIVATION.md:298-302`
  dit déjà la version juste.
- **patch** -- `ModuleWiringTest:129-131` promet « `lint:container` tranchera chez le
  dérivé » : faux, ces classes sont des services privés inutilisés, supprimés avant
  `DefinitionErrorExceptionPass` (`ContainerLintCommand.php:122-131` conserve les passes de
  suppression). Le docbloc du même test, 20 lignes plus haut, dit déjà le contraire.
- **patch** -- `ModuleWiringTest::excludedDirectoriesOf()` ne lit que `{...}` et jette le
  préfixe : `exclude: '../src/Module/{...}'` sans `*/` garde le test vert alors que plus
  aucune exclusion ne porte. Preuve : sonde `YamlFileLoader`, `Entity\DemoWidget` redéfini.
- **patch** -- `lesExclusionsDuFiletDesModulesNonDeclares()` lève dans la boucle : une seule
  entrée hors forme fait disparaître **tous** les cas, y compris ceux bien formés (PHPUnit
  matérialise le fournisseur en entier).
- **patch** -- `COLLECTOR` ne porte pas la racine : deux modules homonymes de part et
  d'autre produisent deux cas d'assertions identiques, et `src/Module/Billing/` coûterait
  zéro édition. Le commentaire `:369-370` est vrai pour la clé du cas, faux pour ce qu'il
  vérifie.
- **patch** -- le docbloc `:232-245` (« elles ne peuvent rien dire de l'état inverse »),
  repris dans `deptrac.yaml:44-48` et `:186-193` : depuis que le balayage couvre
  `src/Module/`, les quatre méthodes forward tiennent déjà le cas d'un module réel. Le test
  inverse ferme l'exclusion orpheline, pas tout l'état.
- **patch** -- `docs/DERIVATION.md:125` range `Exception/` parmi les dossiers vides et
  `:129` dit que `Demo` n'a « ni `Enum/`, ni `Message/`, ni `MessageHandler/` » : les quatre
  sont ajoutés par cette story, trois sections plus haut dans le même diff. Et la liste des
  six dossiers (`:231`, `:236-241`) n'est énoncée qu'en prose : la famille (b) n'ancre que
  la clé `resource`.
- **defer** -- quatre noms de méthodes de test cités dans des fichiers livrés
  (`config/services.yaml:78`, `deptrac.yaml:46` et `:191`, `docs/DERIVATION.md:309`) que
  rien ne fait rougir au renommage. Preuve : ce diff renomme lui-même cinq méthodes et le
  fournisseur sans qu'aucun test ne bronche. L'ancrage générique est une surface nouvelle,
  pas une correction triviale.
- **defer** -- « deptrac sort en 0 » est écrit trois fois et exercé nulle part : le nouveau
  test ne lit que la structure du YAML. Preuve : bac à sable avec exclusion `ModuleStock`
  sans bloc de couche -> `Violations: 0`, `EXIT=0`. Une preuve comportementale appartient à
  `BoundaryTest` ; les Design Notes ont tranché que ce contrôle-ci se lit dans le fichier.
- **defer** -- le pool de fichiers `cache.rate_limiter` survit aux runs et fait échouer
  `LoginThrottlingTest` / `PasswordResetThrottlingTest` en réexécution. Préexistant :
  `config/packages/rate_limiter.yaml` date de la story 1.9 (`ef315f5`) et est absent du diff.
- **rejeté** -- `deptrac --report-uncovered` gagne deux lignes du fait des fixtures. La
  porte ne mesure pas ce compteur : `deptrac analyse` rend 0 violation, 0 erreur.

**Boucle 2** (4 lentilles + porte). Porte verte : `make qa` en 0 trois fois, dont à cache
froid dans l'ordre de la CI. Les constats portent sur ce que les patchs de la boucle 1 ont
introduit.

- **patch** -- `definitionIdsOfTheTestContainer()` ramasse les placeholders
  `container.excluded` que Symfony enregistre pour dire qu'une classe a été *refusée*
  (`FileLoader.php:395-410`) : `#[Exclude]` sur le handler tuerait le seul câblage
  asynchrone d'un module et le contrôle positif resterait vert.
- **patch** -- `getParameter('debug.container.dump')` sans garde : sous `APP_DEBUG=0`, quatre
  cas meurent sur `ParameterNotFoundException` au lieu d'afficher le message prévu.
- **patch** -- le bloc du socle sert d'oracle à deux assertions sans que son propre chemin
  soit tenu (`excludedDirectoriesOf()` ne garde que les accolades). Relevé par deux
  lentilles indépendamment.
- **patch** -- la clé du fournisseur d'exclusions est la valeur de l'exclusion : un doublon
  ou une valeur vide invalide le fournisseur **entier** (`DataProvider.php:189-194`,
  `:223-231`, reproduit sur PHPUnit 13.3.3). Or dupliquer la ligne `must_not` est le
  copier-coller que l'étape 6 du guide prescrit.
- **patch** -- depuis P1 la sonde consommait `modulesUnder($sandbox)` : `lesModulesDeclares()`,
  le fournisseur réellement câblé aux cinq tests, n'était plus couvert. Deux lentilles.
- **patch** -- `a_module_name_belongs_to_one_root_only()` comparait les racines réelles :
  `src/Module/` étant vide, l'assertion passait inconditionnellement.
- **patch** -- le test inverse ne vérifiait pas qu'un répertoire porte le module nommé :
  l'exclusion orpheline complète, le seul cas qu'il ferme en propre, était verte.
- **patch** -- `DemoWidgetNotFound` ne portait ni `#[WithHttpStatus]` ni `#[WithLogLevel]`
  alors que le guide l'érige en « forme à recopier » : un dérivé aurait obtenu un 500 au
  lieu d'un 404 et des `critical` sur chaque 404 métier. Statut écrit en littéral, le
  ruleset `Exception` interdisant `Response::HTTP_*`.
- **patch** -- même schéma pour `RefreshDemoWidget` et `#[AsMessage('async')]` : le guide dit
  désormais pourquoi la fixture ne le porte pas.
- **patch** -- l'inventaire du module de démonstration restait recopié à la main, contre la
  règle « balayés, pas recopiés » que la classe pose elle-même.
- **corrigé par l'orchestrateur** -- les trois entrées de `deferred-work.md` étaient en prose
  libre alors que le fichier est une liste `- source_spec:`, et celle sur le throttling
  proposait une purge de pool que `LoginThrottlingTest:80-83` et
  `PasswordResetThrottlingTest:47-50` font déjà en `setUp()`. Réécrites au bon format, le
  diagnostic faux retiré.

**Boucle 3** (2 lentilles ciblées + porte). Porte verte : `make qa` en 0 à froid, à chaud et
en ordre aléatoire. Les deux écarts déclarés par l'implémentation ont été vérifiés
indépendamment et tiennent : le chemin composé désigne le même fichier au caractère près,
et `self::$sweptRoot` est toujours restaurée (les fournisseurs sont matérialisés avant
l'exécution, les sondes consomment leur générateur dans le `try`).

- **patch** -- l'ancre d'inventaire de P18 cherchait `` `<Dossier>/` `` dans tout le guide,
  or le tableau des couches nomme déjà 13 des 14 dossiers de `Demo/` : le test ne pouvait
  pas rougir sur la dérive annoncée, et la régression exacte d'avant P8 serait restée
  verte. La limite écrite dans le docbloc était plus étroite que la réalité. Relevé par
  les deux lentilles.
- **patch** -- `deptrac.yaml:40-42` attribuait à `BoundaryTest` l'oubli de la seule exclusion,
  que ce test n'exerce pas : son module de bac à sable n'a **aucune** des quatre éditions.
- **patch** -- `docs/DERIVATION.md:85` décrivait un bloc `routing:` que
  `config/packages/messenger.yaml` n'a pas.
- **defer** -- la règle `#[AsMessage('async')]` que le guide énonce désormais n'est tenue par
  aucune assertion, et `RefreshDemoWidget` en est le contre-exemple assumé. Report écrit.
- **rejeté** -- la suite exige `APP_DEBUG=1` sans le déclarer. L'épingler dans
  `phpunit.dist.xml` rendrait inatteignable le message d'erreur que P10 existe pour
  produire ; la garantie implicite est acceptée.
- **rejeté** -- deux échecs de `LoginThrottlingTest` et `PasswordResetTest` sous `phpunit`
  nu. Préexistants et hors périmètre : bascule de fenêtre fixe sous recompilation du
  conteneur, et feuille Tailwind absente faute du `tailwind:build` que `make test` fait.
  Les deux causes sont consignées dans le report existant.

**Limite assumée à la clôture.** L'ancre d'inventaire garde la présence d'un dossier dans
la liste, pas la justesse de la prose qui l'accompagne : `Exception/` rangé à tort parmi les
dossiers vides resterait vert. L'ancrer demanderait de figer la structure des puces du
guide, ce que la classe s'interdit.

## Design Notes

Skills à charger à l'implémentation : symfony-proglab-standards, symfony-proglab-testing,
symfony-proglab-quality (deptrac, `lint:container`, porte de qualité),
symfony-proglab-architecture (frontière des deux racines, contenu d'un dossier de couche).

**Prouver une non-définition, pas une non-résolution.** `getContainer()->has(X)` répond
« non » pour une classe exclue **et** pour un service privé supprimé comme inutilisé — or
les trois classes de fixture seront justement inutilisées. La preuve porte donc sur les
définitions : `php bin/console --env=test debug:container --show-hidden` lancé par
`Process` (le style de `BoundaryTest`), ou le dump de débogage du conteneur, qui liste bien
les classes globées inutilisées (vérifié à la rédaction sur
`var/cache/test/App_KernelTestDebugContainer.xml`). Dire dans le test pourquoi ce n'est pas
`has()`, sinon le prochain le simplifiera.

**Où vit le contrôle des quatre éditions.** Il reste dans `DerivationGuideTest` (c) plutôt
que de migrer vers `BoundaryTest` : les helpers YAML, le vocabulaire des quatre éditions et
le fournisseur y sont déjà, et c'est l'étape 6 du guide qu'il tient mot pour mot.
`BoundaryTest` garde ce qui s'observe en lançant deptrac ; ce contrôle-ci s'observe en
lisant le fichier.

## Verification

**Commands :**

- `php vendor/bin/phpunit --filter ModuleWiringTest` -- rouge avant le changement de
  `config/services.yaml`, vert après.
- `php vendor/bin/phpunit --filter DerivationGuideTest` -- vert ; la famille (c) passe sur
  les fixtures et n'a rien à balayer sous `src/Module/`.
- `php bin/console lint:container` puis `php bin/console lint:container --env=prod` --
  aucune erreur dans les deux environnements.
- `make qa` -- les six catégories vertes, aucune cible ni job ajouté.
