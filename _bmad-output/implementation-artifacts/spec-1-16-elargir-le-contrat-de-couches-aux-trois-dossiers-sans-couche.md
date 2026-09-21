---
title: "Élargir le contrat de couches aux trois dossiers sans couche"
type: 'refactor'
created: '2026-09-20'
status: 'ready-for-dev'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-retro-2026-09-19.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** `deptrac.yaml` ne donne **aucune** couche technique à `Command/`,
`EventListener/` et `Twig/` — zone passée de 0 à 21 % de `src/` en un epic (D1), et
empruntée sciemment : le docblock de `LoginFailureListener` dit qu'il vit là parce que la
couche n'y juge rien. Le test censé l'attraper ne lit que le **côté dépendance** du rapport
(D2) : une classe couverte par sa seule couche de racine ne peut jamais y apparaître. Et
`Dto/Output/` est vide depuis quinze stories pendant que
`DerivativeInitializer::createFirstSuperAdmin()` rend une entité (D4).

**Approche :** élargir le contrat plutôt que l'acter (arbitrage D1/D2 du 2026-09-19) : trois
couches techniques nouvelles avec les dépendances qui leur sont légitimes et sans accès
direct à Doctrine ; un `BoundaryTest` qui lit le côté source du rapport et qu'un bac à sable
prouve capable de rougir ; le premier DTO de `Dto/Output/` ; et les deux docblocks qui
invoquaient le contrat comme raison de leur emplacement réécrits sur leur vraie raison.

Referme `epic-1-retro-item-10` (D1, D2) et `epic-1-retro-item-17` (D4).

## Boundaries & Constraints

**Always :**

- Le test d'abord, rouge avant le code (règle 1 du socle).
- Les collecteurs `Doctrine`, `DoctrineMapping` et `EntityManager` restent ceux de
  `symfony-proglab-quality`, mot pour mot ; les couches ajoutées le sont **en plus**.
- Aucune des trois nouvelles couches n'atteint `Doctrine`, `DoctrineMapping`,
  `EntityManager` ni `Repository`.
- Chaque couche ajoutée est nommée dans **`AnyLayer`** : sans cela, les couches de racine
  (`Core`, `ModuleDemo`, `ModuleBilling`) cessent de les autoriser et la première dépendance
  interne devient une violation.
- Les quatre éditions qu'un module coûte restent quatre : aucune couche ajoutée ne nomme un
  module (`a_declared_module_costs_nothing_else_in_deptrac()`).
- Aucune cible `make` ni aucun job de CI ajouté ; la porte garde ses six catégories
  (`QualityGateParityTest`).

**Décisions tranchées à la rédaction :**

- **`LoginFailureListener` ne bouge pas.** Rendre une réponse sur un échec d'authentification
  est le point d'extension que Symfony prévoit, pas un contournement ; ses trois dépendances
  (Twig, `Response`, `LoginFailureMessage`) deviennent **accordées** à la couche
  `EventListener` au lieu d'être tolérées par l'absence de règle. Le déplacer collisionnerait
  en plus avec la story 1.17, qui réécrit cette classe. Seul son docblock change : la phrase
  « `src/Core/EventListener/` n'a **aucune** couche technique » (l. 31-33) est remplacée par
  ce que la couche autorise désormais.
- **Les méthodes d'état de `UserRepository` ne bougent pas non plus.** `hasAccountTable()` et
  `reopenConnection()` interrogent la **base sur elle-même** — présence d'une table, état de
  la connexion —, et le repository est le seul adaptateur du socle vers la base ; c'est vrai
  indépendamment de deptrac. La lecture métier est déjà ailleurs :
  `DerivativeInitializer::state()` compose les deux faits en `DerivativeState`. Les déplacer
  vers `Service/` exigerait d'ouvrir `Doctrine` à `Service`, c'est-à-dire la régression que
  le contrat existe pour empêcher. Le docblock (l. 21-26) cesse d'invoquer « le contrat m'y
  oblige » et écrit cette raison-là.
- **`FirstSuperAdminOutput` porte `id` et `email`, rien d'autre** : exactement ce que les
  appelants consomment — le message de succès lit l'adresse, la suite prouve l'écriture par
  l'identifiant. Un DTO Output est un contrat, pas un miroir de la ligne.

**Never :**

- Aucune classe déplacée d'un dossier à l'autre dans cette story (voir Design Notes).
- Pas de nouveau trait ni d'helper de test partagé : la mutualisation des quatre patrons de
  bac à sable appartient à la story 1.19. Le bac à sable ajouté ici réutilise les helpers
  privés existants de `BoundaryTest`.
- On n'ouvre ni `Http` ni `Entity` à `Command` : une commande traduit, elle ne manipule ni
  HTTP ni entité.

</frozen-after-approval>

## Open Questions

Aucune. La seule question ouverte a été tranchée par Fabrice le 2026-09-20 :

- **Le `Twig` accordé à `EventListener` est mécanisé.** Option (a) : une couche vendor
  `Twig` (collecteur `classNameRegex` sur `Twig\`) est ajoutée, et accordée à
  `EventListener`, `TwigExtension` **et** `Service` — ce dernier parce que
  `EmailSender:65` injecte `Twig\Environment`. `Controller` ne la reçoit pas : il rend ses
  gabarits par `#[Template]`, et « qui rend un gabarit à la main » devient une règle
  greppable plutôt qu'un commentaire.

## Code Map

- `deptrac.yaml` -- l. 60-63, le commentaire « Ce que ce fichier ne collecte pas dans une
  couche technique » à effacer ; couches techniques et ruleset `AnyLayer` ; les rulesets
  `Service` et `Controller`, voisins des blocs à écrire.
- `tests/Core/BoundaryTest.php:76` -- `no_class_of_ours_escapes_every_layer()` ne
  `preg_match` que `uncovered dependency on (App\\\S+)`. Le rapport JSON annote pourtant le
  **dépendeur** et sa couche : `App\Core\Command\InitCommand has uncovered dependency on X
  (Core)`. Une classe couverte par une couche technique porte deux messages — `(Controller)`
  **et** `(Core)` ; les cinq classes de `Command/` et `EventListener/` n'en portent qu'un,
  `(Core)`. C'est ce déséquilibre que le test doit lire. Helpers privés déjà là :
  `sandboxWith()` (l. 214), `deptrac()` (l. 236), `clean()` (l. 201), `projectDir()` (l. 261).
- `src/Core/Command/` -- `InitCommand` (Dto\Input, Enum, Exception, Service),
  `DeploymentCheckCommand` (Service) : aucune n'importe d'entité ni de `HttpFoundation`.
  `src/Core/EventListener/` -- `LocaleListener` (Service, Enum, `Request`),
  `LoginLocaleListener` (Entity), `LoginFailureListener` (Security, `Request`, `Response`,
  `Twig\Environment`, `DefaultLoginRateLimiter`). `src/Core/Twig/` -- vide (`.gitkeep`) :
  la couche s'écrit pour le premier arrivant.
- `src/Core/Service/DerivativeInitializer.php:166` -- `createFirstSuperAdmin(): User`,
  `return $user` après le `flush()`.
- `src/Core/Command/InitCommand.php:142,151` -- consomme le retour, puis `$user->getEmail()`.
- `tests/Core/Initialization/DerivativeInitializerTest.php:152,169` -- assertions sur
  l'entité rendue (rôle, activation, langue, `getId()`, hachage) : à relire via
  `UserRepository::findOneByEmail()`, sauf l'identifiant qui reste porté par le DTO.
- `src/Core/Repository/UserRepository.php:21-26` -- le docblock à réécrire.
- `docs/DERIVATION.md:90-92` -- les trois lignes « Aucune couche technique » ; `:81` -- la
  ligne `Service/`, qui promet des DTO Output sans exemple à montrer.
- `tests/Core/Documentation/DerivationGuideTest.php` -- lit la structure de `deptrac.yaml` ;
  `a_declared_module_costs_nothing_else_in_deptrac()` et
  `every_layer_directory_of_the_core_is_named_by_the_guide()` doivent rester verts.

## Tasks & Acceptance

**Execution :**

- [x] `tests/Core/BoundaryTest.php` -- extraire la lecture du rapport dans un helper rendant,
      par classe `App\…`, l'ensemble de ses couches annotées ; réécrire
      `no_class_of_ours_escapes_every_layer()` pour exiger qu'au moins une de ces couches
      figure dans `ruleset.AnyLayer` de `deptrac.yaml` — la liste canonique des couches
      techniques, lue et non recopiée ; garder l'assertion côté dépendance ; ajouter un test
      de bac à sable qui pose une classe dans un dossier sans couche technique et prouve que
      le détecteur la nomme.
- [x] `deptrac.yaml` -- ajouter les couches `Command`, `EventListener` et `TwigExtension`
      (collecteurs `(src|tests/Fixtures)/(Core|Module/[^/]+)/<Dossier>/.*`), leurs rulesets,
      et les nommer dans `AnyLayer` ; remplacer le commentaire annonçant l'absence de couche
      par ce que chacune autorise et refuse.
- [x] `src/Core/Dto/Output/FirstSuperAdminOutput.php` -- créer le premier DTO Output du socle
      (`final readonly`, `int $id`, `string $email`), docblock compris : pourquoi un service
      ne rend pas son entité.
- [x] `src/Core/Service/DerivativeInitializer.php` -- faire rendre `FirstSuperAdminOutput` à
      `createFirstSuperAdmin()` après le `flush()`, sans changer ce qu'elle écrit.
- [x] `src/Core/Command/InitCommand.php` -- consommer le DTO ; la commande cesse de tenir une
      entité, ce que le ruleset `Command` interdit désormais.
- [x] `tests/Core/Initialization/DerivativeInitializerTest.php` -- relire l'état persisté par
      le repository plutôt que sur l'objet rendu, et couvrir le nouveau contrat de retour.
- [x] `src/Core/EventListener/LoginFailureListener.php` -- réécrire l. 31-33 : la couche
      `EventListener` autorise ce dont la classe a besoin, l'argument « aucune couche
      technique » disparaît.
- [x] `src/Core/Repository/UserRepository.php` -- réécrire la raison des deux méthodes d'état
      (adaptateur vers la base, non contrainte de contrat).
- [x] `docs/DERIVATION.md` -- les trois lignes du tableau portent leurs couches réelles ; la
      ligne `Service/` pointe `FirstSuperAdminOutput` comme exemple.

**Acceptance Criteria :**

- Given `deptrac.yaml`, when je lis les couches, then `EventListener/`, `Command/` et `Twig/`
  en ont chacun une, avec les dépendances qui leur sont légitimes — `Service`, `Response`,
  `Twig` — et sans accès direct à Doctrine.
- Given une classe d'`EventListener/` qui porte `EntityManagerInterface` ou `QueryBuilder`,
  when deptrac tourne, then il rend au moins une violation, là où il en rendait zéro.
- Given `BoundaryTest::no_class_of_ours_escapes_every_layer()`, when une classe du socle
  n'est couverte par aucune couche technique, then le test rougit en la nommant : il lit le
  côté source du rapport, et non seulement le côté dépendance.
- Given `LoginFailureListener`, when le contrat élargi s'applique, then ses dépendances sont
  légitimes pour sa couche et son docblock n'invoque plus « aucune couche technique ».
- Given `DerivativeInitializer::createFirstSuperAdmin()`, when `InitCommand` l'appelle, then
  il reçoit un DTO de `Dto/Output/` et non l'entité `User`, et le dossier cesse d'être vide
  après quinze stories.
- Given les méthodes d'état du dérivé de `UserRepository`, when le contrat élargi s'applique,
  then elles restent en place avec une raison écrite qui n'est plus « le contrat m'y oblige ».
- Given la porte de qualité, when `make qa` tourne, then les six catégories passent, deptrac
  à 0 violation, sans qu'aucune cible ni aucun job n'ait été ajouté.

## Implementation Notes

### 2026-09-21 — implémentation

**Fichiers touchés**

- `deptrac.yaml` — trois couches de dossier (`Command`, `EventListener`, `TwigExtension`),
  une couche vendor `Twig` (`classNameRegex` sur `/^Twig\\.*/`), leurs quatre rulesets,
  les quatre noms ajoutés à `AnyLayer`, `Twig` ajouté au ruleset `Service`, et le bloc de
  commentaire « Ce que ce fichier ne collecte pas » remplacé par ce que chaque couche
  autorise et refuse. Les collecteurs `Doctrine`, `DoctrineMapping` et `EntityManager` ne
  sont pas touchés.
- `tests/Core/BoundaryTest.php` — lecture du rapport extraite en quatre helpers privés
  (`messagesOf`, `layersByClass`, `uncoveredDependenciesOfOurs`,
  `classesOutsideEveryTechnicalLayer`, plus `technicalLayers` qui lit `ruleset.AnyLayer`) ;
  `no_class_of_ours_escapes_every_layer()` porte désormais les deux assertions ; bac à
  sable `a_class_in_a_directory_without_a_technical_layer_is_named()` ; quatre cas de
  violation ajoutés au provider.
- `src/Core/Dto/Output/FirstSuperAdminOutput.php` — nouveau.
- `src/Core/Service/DerivativeInitializer.php` — `createFirstSuperAdmin()` rend le DTO.
- `src/Core/Command/InitCommand.php` — consomme `$created->email`.
- `tests/Core/Initialization/DerivativeInitializerTest.php` — relecture par
  `UserRepository::findOneByEmail()` via un helper `persisted()`, plus un test du contrat
  de retour.
- `src/Core/EventListener/LoginFailureListener.php`,
  `src/Core/Repository/UserRepository.php`, `docs/DERIVATION.md` — docblocks et tableau.

**Décisions prises sur ce que le spec ne tranchait pas**

- **Le nom de la couche du dossier `Twig/` est `TwigExtension`.** `Twig` est pris par la
  couche vendor décidée en Open Questions, et deptrac refuse deux couches homonymes. Le
  guide dit les deux noms sur la ligne `Twig/`.
- **Le collecteur vendor est `/^Twig\\.*/`, et ne vise pas `Symfony\Bridge\Twig\*`.**
  `#[Template]` et `TemplatedEmail` sont des ponts du framework, pas le moteur ; les
  inclure aurait fermé `Controller → #[Template]`, c'est-à-dire exactement la forme que la
  décision veut encourager.
- **`createFirstSuperAdmin()` lève un `LogicException` inatteignable** plutôt qu'un
  `?? 0` sur `getId()` après le `flush()` : un identifiant nul rendu en silence ferait
  annoncer un compte que la suite ne retrouverait jamais.
- **Le message de succès d'`app:init` n'affiche pas l'identifiant.** Le DTO le porte parce
  que c'est lui qui prouve l'écriture dans la suite ; l'opérateur, lui, se connecte avec
  l'adresse. Aucun test de sortie console n'a donc bougé.
- **`assertInstanceOf(FirstSuperAdminOutput::class, …)` a été retiré du test** : PHPStan au
  niveau max la déclare toujours vraie (`staticMethod.alreadyNarrowedType`). Le contrat de
  retour est tenu par la signature, pas par cette assertion.

**Rouge d'abord, et la vérification par mutation là où il était impossible**

- `no_class_of_ours_escapes_every_layer()` : rouge avant `deptrac.yaml`, en nommant les
  cinq classes (`DeploymentCheckCommand`, `InitCommand`, `LocaleListener`,
  `LoginFailureListener`, `LoginLocaleListener`).
- `a_class_in_a_directory_without_a_technical_layer_is_named()` : vert dès l'écriture
  (le dossier `Serializer/` n'a pas de couche, avant comme après). Rouge prouvé en
  déplaçant la classe du bac à sable dans `src/Core/Service/` — le détecteur rend alors
  une liste vide, et le test échoue.
- Les quatre nouveaux cas du provider : `deptrac.yaml` était déjà écrit quand ils ont été
  ajoutés, donc rouge prouvé par mutation — en ouvrant `Doctrine`/`EntityManager` à
  `EventListener`, `Entity` à `Command` et `Twig` à `Controller`, les quatre passent au
  rouge ensemble, puis reviennent au vert une fois le fichier restauré.
- `it_returns_the_contract_…()` : rouge sur `FirstSuperAdminOutput` inexistant, puis rouge
  d'assertion, puis vert.

**Surprises et choses vues, volontairement non faites**

- **`src/Core/Contract/` n'a aucune couche technique non plus**, et `CoreContract` n'est
  pas dans `AnyLayer` — c'est délibéré côté frontière (`CoreContract: ~`). Le détecteur
  côté source le nommerait donc le jour où une classe de `Contract/` porterait une
  dépendance vendor non collectée. Aujourd'hui `ModuleDescriptor` n'en porte aucune, donc
  le cas ne se pose pas. Signalé, pas traité : le spec ne nomme que trois dossiers.
- **Le côté source est aveugle à une classe sans dépendance *uncovered*.** Écrit dans le
  docblock du test, avec le relais
  (`DerivationGuideTest::every_layer_directory_of_the_core_is_named_by_the_guide()`).
- **Aucune classe déplacée**, conformément au `Never` du bloc gelé : `LoginFailureListener`
  et les deux méthodes d'état de `UserRepository` restent en place, seuls leurs docblocks
  changent.
- `php-cs-fixer` a importé `LogicException` en tête de `DerivativeInitializer`
  (`global_namespace_import`) — c'est le style du projet, pas un choix de cette story.

**Vérifications exécutées**

- `php vendor/bin/phpunit --filter BoundaryTest` — OK (11 tests, 84 assertions).
- `php vendor/bin/phpunit --filter 'DerivativeInitializerTest|InitCommandTest|FirstSuperAdminLoginTest|DerivationGuideTest'` — OK (96 tests).
- `php vendor/bin/phpunit` — OK (555 tests, 2947 assertions).
- `php vendor/bin/deptrac analyse --config-file=deptrac.yaml --report-uncovered` — Violations 0.
- `php vendor/bin/phpstan analyse` — No errors.
- `git diff --stat Makefile .github/` — vide.

### 2026-09-21 — lot de corrections de revue

Dix points, quatre lentilles adverses. Ce qui a changé par rapport à la première passe :

1. **`TwigExtension` perd `Entity`, gagne `Exception`.** `Entity` sur une couche
   d'extensions Twig mécanisait « une entité atteint un template » — la règle 4 prise à
   revers — et sur un dossier vide, sans inventaire pour le justifier, alors que `Command`
   s'était vu refuser `Entity` avec le même argument. `Exception` manquait là où les cinq
   autres couches l'ont. Répercuté dans `docs/DERIVATION.md`.
2. **`TwigExtension` a désormais son filet.** Elle n'était tenue par rien : supprimer son
   bloc, son ruleset et son nom dans `AnyLayer` laissait la suite verte, et remplacer
   `…/Twig/.*` par `…/TwigTYPO/.*` rendait un rapport identique — parce que le dossier est
   vide. Deux cas ajoutés à `forbiddenDependencies()` posant une classe dans
   `src/Core/Twig/` : `(TwigExtension on Doctrine)` et `(TwigExtension on Http)`. Les deux
   mutations ci-dessus ont été rejouées : elles rougissent maintenant toutes les deux.
3. **`src/Core/Contract/` est exempté du détecteur, nommément et avec sa raison.**
   `technicalLayers()` lit `ruleset.AnyLayer` ; `CoreContract` est une couche de *racine*
   dont le ruleset est `~`, elle n'y figurera jamais — une sonde dans `Contract/` faisait
   donc rougir une classe parfaitement conforme à AD-2. Le détecteur ajoute désormais aux
   couches qui comptent celles dont le ruleset est `~` (`layersRefusingEverything()`), et
   le docblock nomme le correctif à ne jamais faire : ajouter `CoreContract` à `AnyLayer`
   ouvrirait la porte publique à toutes les couches techniques. Couvert par le bac à sable,
   qui porte maintenant deux sondes — `Contract/` hors liste, `Serializer/` dedans — et
   rougit si l'exemption est retirée (vérifié).
4. **Le docblock de `no_class_of_ours_escapes_every_layer()` ne prête plus à
   `DerivationGuideTest` une couverture qu'il n'a pas.** Le relais n'assert qu'une
   sous-chaîne `` `<Dossier>/` `` dans le Markdown ; une ligne de tableau « Aucune couche
   technique » le repasse au vert. C'est écrit, avec le test manquant nommé et reporté.
5. **Les interdits implicites sont écrits.** `Message` est refusé à `Command`,
   `EventListener` et `TwigExtension` — une mise en file est une décision, donc elle vit
   dans le service appelé —, et la couche vendor `Twig` n'est pas accordée à `Message`.
   Dans `deptrac.yaml` (en-tête + commentaires de ruleset) et dans le guide.
6. **`LoginFailureListener`** : « les trois dépendances de cette classe » devient « trois
   couches seulement la jugent », avec l'inventaire, et l'homonymie est levée — la couche
   `Security` de `deptrac.yaml` collecte `src/Core/Security/`, pas
   `Symfony\Component\Security\*`, qui reste toléré par l'absence de règle.
7. **`docs/DERIVATION.md`** : `Twig` ajouté à la colonne de la ligne `Service/` (le guide
   promet en `:74` que la colonne reprend le ruleset), et « Twig (le moteur) » écrit dans
   les trois colonnes concernées pour qu'on ne lise pas « peut dépendre du dossier `Twig/` ».
8. **La phrase de justification de la couche vendor est bornée.** Elle promettait de voir
   « qui rend un gabarit à la main » ; elle voit qui **injecte** `Twig\*`.
   `AbstractController::render()` n'est collecté par aucune couche — `PasswordController`
   en fait trois — et c'est écrit sur place comme un trou assumé.
9. **`persisted()` vide le contexte Doctrine avant de relire.** `findOneBy()` émet bien la
   requête, mais l'hydrateur retrouvait l'entité dans l'identity map et rendait **la même
   instance** que celle construite par le service : les assertions sur le rôle,
   l'activation, la langue et le mot de passe ne lisaient pas les colonnes. Prouvé par une
   sonde temporaire (`assertNotSame` entre la lecture sans `clear()` et celle avec) : sans
   `clear()`, c'est le même objet. À noter — la mutation demandée (retirer `#[ORM\Column]`
   de `User::$enabled`) rougit bien, mais à l'`INSERT`, donc elle ne prouve pas le
   `clear()` ; c'est la sonde d'identité qui le prouve.
10. **Le DTO est renommé `AccountOutput`** — voir `## Spec Change Log`.

### Reporté, sur décision de l'orchestrateur

- Le côté « dépendance » du test n'a aucune sonde capable de le faire rougir (la story 1.19
  mutualise les patrons de bac à sable).
- `no_class_of_ours_escapes_every_layer()` ne distingue pas « rien n'échappe » de « rien
  n'a été analysé » : ni plancher sur le nombre de classes vues, ni contrôle du code de
  sortie de deptrac.
- Les trois rulesets promettent douze refus ; quatre sont mécanisés (six avec les deux cas
  `TwigExtension` ajoutés ici).
- La colonne « Peut dépendre de » du guide n'est confrontée à aucun ruleset par un test.
- Le test de confrontation dossiers de `src/Core/` ↔ collecteurs de `deptrac.yaml`, qui
  fermerait l'angle mort du point 4.

## Spec Change Log

### 2026-09-21 — amendement du bloc gelé, décidé par Fabrice

Le bloc `<frozen-after-approval>` nomme le premier DTO Output `FirstSuperAdminOutput`
(section « Décisions tranchées à la rédaction », troisième puce). **Fabrice a renégocié ce
nom le 2026-09-21**, à la suite de la revue : le guide de dérivation demande « un DTO
Output par *forme* de données, pas par endpoint », et `FirstSuperAdminOutput` nommait
l'opération qui le produit, pas ce qu'il porte. C'est le premier `Dto/Output/` du socle et
l'Epic 2 le recopiera.

- **Le DTO s'appelle `App\Core\Dto\Output\AccountOutput`** — un compte réduit à ce qui
  l'identifie. Ses deux champs (`int $id`, `string $email`) et leur justification ne
  changent pas : seule la manière de les nommer change.
- Le suffixe `Output` n'était écrit nulle part — ni dans le guide, ni dans les skills, qui
  n'illustrent que `BookView` et `ShelfStatsRow`. Il est désormais fixé une fois dans la
  puce « DTO » de `docs/DERIVATION.md`, pour que l'Epic 2 n'invente pas sa convention.

Le bloc gelé lui-même n'a pas été édité : c'est cette entrée qui porte l'amendement.

## Review Triage Log

## Design Notes

Skills à charger à l'implémentation : symfony-proglab-standards, symfony-proglab-testing,
symfony-proglab-quality, symfony-proglab-architecture, symfony-proglab-console.

**Les rulesets visés**, dérivés de l'inventaire réel des cinq classes (Code Map) :

```yaml
Command:        [Command, Service, Dto, Enum, Exception, '+AnyRoot']
EventListener:  [EventListener, Service, Dto, Entity, Enum, Exception, Security, Http, '+AnyRoot']
TwigExtension:  [TwigExtension, Service, Dto, Entity, Enum, '+AnyRoot']
```

(plus `Twig` sur `EventListener`, `TwigExtension` et `Service` si l'option (a) de la question
ouverte est retenue). `Command` **n'a pas** `Entity` : c'est ce qui empêche la prochaine
commande de refaire ce que la 1.11 a fait. À savoir : deptrac ne lit que les jetons présents
dans le fichier — `InitCommand` n'importe pas `User` aujourd'hui, donc le retour entité
n'était pas rattrapable par un ruleset ; ce sont le type de retour et le test qui le tiennent.

**Ce que le côté source ne voit pas, et qu'il faut écrire dans le docblock du test :** une
classe sans **aucune** dépendance vendor n'apparaît pas du tout dans `--report-uncovered`. Le
relais existe déjà — `DerivationGuideTest::every_layer_directory_of_the_core_is_named_by_the_guide()`
tient la liste des dossiers, et le tableau du guide nomme désormais leur couche.

**Effet sur les stories 1.17 à 1.21.** Aucune classe n'est déplacée, et c'est délibéré : la
1.17 réécrit `LoginFailureListener`, la 1.19 mutualise les patrons de bac à sable de
`BoundaryTest`, les 1.20 et 1.21 reprennent `docs/DERIVATION.md`. Cette story ne touche de
ces trois fichiers que ce que son propre changement rend faux.

## Verification

**Commands :**

- `php vendor/bin/phpunit --filter BoundaryTest` -- attendu : rouge avant la modification de
  `deptrac.yaml` sur le nouveau test, vert après.
- `php vendor/bin/deptrac analyse --config-file=deptrac.yaml --report-uncovered` -- attendu :
  `Violations: 0`, et plus aucune classe `App\…` annotée d'une seule couche de racine.
- `php vendor/bin/phpunit --filter 'DerivativeInitializerTest|InitCommandTest|FirstSuperAdminLoginTest|DerivationGuideTest'`
  -- attendu : vert.
- `make qa` -- attendu : les six catégories passent ; `git diff Makefile .github/` reste vide.
