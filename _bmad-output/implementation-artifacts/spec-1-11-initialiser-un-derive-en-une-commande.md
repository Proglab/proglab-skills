---
title: 'Initialiser un dérivé en une commande'
type: 'feature'
created: '2026-09-15'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '87ca260d2559812047a2816457732c34b9d5ba60'
context:
  - '{project-root}/.claude/skills/symfony-proglab-console/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-testing/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-doctrine/SKILL.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** cloner le socle ne donne rien d'utilisable. Il faut aujourd'hui créer la base
à la main, jouer les migrations, puis fabriquer un premier compte par un moyen qui
n'existe pas — aucune commande, aucune fixture. FR-1 promet l'inverse : une seule
commande, et le développeur écrit du spécifique le premier jour.

**Approche :** une commande `app:init`, fine couche de traduction au-dessus d'un service
`DerivativeInitializer` qui porte les trois règles — dans quel état est ce dérivé, un
premier Super admin ne se crée que sur une base sans compte, et les rôles du socle doivent
être là. La commande n'enchaîne que ce qui manque réellement : elle ne crée la base et ne
joue les migrations que quand la table des comptes est absente, puis demande les
identifiants et rapporte ce qu'elle a fait.

## Boundaries & Constraints

**Always :**

- La commande ne porte **aucune règle** : elle lit un état rendu par le service, appelle
  des commandes Doctrine existantes, invite à saisir, met en forme et rend un code de
  sortie. Toute décision testable vit dans `DerivativeInitializer` (AC 4 de la story).
- L'état du dérivé a **trois valeurs**, et elles seules : `Uninitialized` (la table des
  comptes n'existe pas — base absente ou schéma absent), `Empty` (la table existe, aucun
  compte), `Initialized` (au moins un compte existe). « Base déjà peuplée » = `Initialized`.
- Le refus sur `Initialized` est le défaut ; `--force` est la seule façon d'agir quand même,
  et il crée un Super admin **supplémentaire** — c'est l'issue de secours quand plus
  personne ne peut se connecter.
- Les deux commandes Doctrine (`doctrine:database:create --if-not-exists`,
  `doctrine:migrations:migrate`) ne sont lancées **que** sur `Uninitialized`. Sur `Empty` et
  `Initialized` la commande ne touche pas au schéma : elle est idempotente, elle dit ce
  qu'elle a sauté, et aucun DDL n'échappe à la transaction d'isolation des tests.
- Le mot de passe est saisi masqué, jamais passé en option ni en argument — il fuirait dans
  l'historique du shell et dans `ps`.
- **Décision de Fabrice (2026-09-15) — pas de contrôle de robustesse sur le chemin console.**
  `FirstSuperAdminInput` porte `NotBlank` et la borne haute en octets, et rien d'autre. Le
  triplet de la story 1.9 n'est **ni extrait ni dupliqué** : aucun dossier `Validator/`
  n'est créé, `PasswordResetInput` n'est pas touché. La borne haute reste obligatoire —
  sans elle le hacheur lève une `InvalidPasswordException` que rien n'attrape sur ce chemin.
  L'écart assumé est nommé dans les Design Notes et doit être écrit dans le DTO lui-même.
- La commande est **interactive par nature**. Avec `--no-interaction` elle sort en
  `Command::INVALID` en disant qu'elle a besoin d'une saisie, plutôt que de fabriquer un
  compte avec des valeurs par défaut.
- Toute exception métier de cette story vit dans `src/Core/Exception/` et ne franchit
  jamais HTTP : pas d'attribut `#[WithHttpStatus]`.
- Test écrit d'abord et vu rouge (règle 1).

**Never :**

- Aucune fixture Doctrine, aucun `doctrine:schema:update` : le schéma vient des migrations,
  qui sont l'unique source de vérité, et les trois rôles du socle sont déjà semés par
  `Version20260911130140`.
- La commande ne sème **rien** : « charge les rôles par défaut » est tenu par la migration.
  Le service **vérifie** leur présence et échoue bruyamment si le rôle `super_admin`
  manque ; il ne le recrée pas.
- Aucun `--email` / `--password` en option, aucun mode « non interactif utilisable ».
- Aucun verrou (`symfony/lock`) : la commande n'est ni longue ni planifiée.
- Aucune traduction de la sortie console — le précédent `DeploymentCheckCommand` écrit en
  français, en dur, et les catalogues du socle sont pour l'interface.
- Rien sur la roadmap : AC 2 se ferme sur `app_home` tel qu'il existe, et l'Epic 3 le
  remplacera.
- **Décision de Fabrice (2026-09-15) — la surface de rebranding est renvoyée à la story
  1.12.** Ni le favicon absent, ni le `{{ app_name }}` du `<h1>` du gabarit ne sont touchés
  ici : c'est la 1.12 qui documente *de quoi* cette surface est faite, donc c'est elle qui
  décide où vivent les fichiers de marque d'un dérivé. Les deux reports de
  `deferred-work.md` restent ouverts, avec la 1.12 pour seul propriétaire.
- **Décision de Fabrice (2026-09-15) — la commande s'appelle `app:init`.** Forme à un
  segment, contre les deux de `app:deployment:check` : c'est la première chose que le guide
  de dérivation fera taper, et elle doit être courte.
- Aucune cible `Makefile` ni job de CI : la commande ne vérifie rien, elle agit. Le
  précédent est `worker` / `sync-status`, hors de la table `# qa-category:`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Base absente | DSN vers une base inexistante, `app:init` | Base créée, migrations jouées, rôles vérifiés, identifiants demandés, compte créé, `SUCCESS` | N/A |
| Schéma absent | Base vide déjà créée | La création de base est un no-op annoncé, migrations jouées, puis comme ci-dessus | N/A |
| Schéma présent, aucun compte | Table `user` vide | Ni création ni migration ; identifiants demandés ; compte créé ; la sortie dit que le schéma était à jour | N/A |
| Base peuplée, sans option | Au moins un compte | Rien n'est touché, `FAILURE`, message nommant le nombre de comptes existants et `--force` | Sortie sur stderr |
| Base peuplée, `--force` | Au moins un compte | Un Super admin supplémentaire est créé, avec un avertissement | N/A |
| Email déjà pris | `--force` + adresse existante | Aucun compte créé, `FAILURE`, message nommant l'adresse | Sortie sur stderr |
| Mot de passe refusé | Saisie vide, ou au-delà de `MAX_PASSWORD_LENGTH` octets | La question est reposée ; rien n'est écrit tant que la saisie n'est pas valide | Message de validation affiché |
| Confirmation divergente | Deux saisies différentes | Les deux questions sont reposées | Message affiché |
| `--no-interaction` | Aucun TTY | `INVALID`, message disant que la commande exige une saisie | Sortie sur stderr |
| Rôle `super_admin` absent | Migration non jouée ou table `role` vidée | `FAILURE`, message nommant la migration attendue | `InitializationFailed` attrapée par la commande |

</frozen-after-approval>

## Code Map

- `src/Core/Command/DeploymentCheckCommand.php:33-60` — **le précédent à copier**, et son
  docblock le dit : forme invocable Symfony 7.4 (`#[AsCommand]` sur la classe, `__invoke()`,
  `SymfonyStyle` injecté par type), aucune règle dedans, le code de sortie comme interface,
  les problèmes sur `$io->getErrorStyle()`. Ne pas y toucher.
- `src/Core/Service/DeploymentReadiness.php` — la forme de service attendue : `final
  readonly`, nommé d'après sa responsabilité, rend une donnée et ne décide pas quand il
  tourne.
- `src/Core/Entity/User.php:43-195` — pas de constructeur, setters fluides
  `setEmail/setPassword/setEnabled/setRole/setLanguage`. `language` vaut `SupportedLocale::Fr`
  par défaut, `enabled` vaut `true`, `securityToken` vaut 1. Ne pas ajouter de méthode de
  comportement.
- `src/Core/Entity/Role.php` + `src/Core/Enum/CoreRole.php:25` — `SuperAdmin='super_admin'`,
  `Admin`, `User`. Les deux docblocks désignent déjà « la commande d'initialisation de la
  story 1.11 » comme le consommateur prévu de `code`.
- `migrations/Version20260911130140.php:82-84` — **les trois rôles sont déjà semés par
  migration** (`INSERT INTO role (code, name) VALUES …`). C'est ce qui ferme « charge les
  rôles par défaut ». Ne pas dupliquer ce seed.
- `src/Core/Repository/UserRepository.php` — `findOneByEmail()` seul aujourd'hui. **Les deux
  lectures de cette story s'ajoutent ici** : la couche `Repository` est la seule que deptrac
  autorise à toucher `Doctrine\DBAL\*` (`deptrac.yaml:253-262` — `Service` n'a **pas**
  `Doctrine`). Le nom de table se lit sur `getClassMetadata(User::class)->getTableName()`,
  jamais en dur.
- `src/Core/Repository/RoleRepository.php:31` — `findOneByCode(CoreRole): ?Role`, exactement
  la question du service.
- `src/Core/Dto/Input/PasswordResetInput.php:56-66` — le jumeau HTTP, et le raisonnement
  complet derrière `countUnit: COUNT_BYTES` et la borne haute du hacheur. **Ne pas le
  modifier** : la décision du 2026-09-15 n'extrait ni ne duplique son
  `#[Assert\PasswordStrength]`. Seule la paire `NotBlank` + `Length(max:
  PasswordHasherInterface::MAX_PASSWORD_LENGTH, countUnit: COUNT_BYTES)` se retrouve dans le
  DTO de cette story.
- `src/Core/Controller/PasswordController.php:81` — le précédent d'appel à
  `ValidatorInterface` depuis la couche de traduction : le contrôleur valide et rerend, la
  commande validera et reposera la question. Le service reçoit un DTO déjà validé.
- `tests/Core/Security/Accounts.php:34-70` — le modèle direct de la création de compte :
  hachage par l'alias public, rôle résolu par `findOneByCode()` avec un échec bruyant s'il
  manque.
- `tests/Core/Deployment/DeploymentCheckCommandTest.php:47-56` — le motif de test de
  commande du dépôt : `new CommandTester(new Application($kernel)->find('…'))` depuis un
  `KernelTestCase`. `ConsoleCommandAssertionsTrait` / `runCommand()` **n'existent pas** en
  Symfony 7.4 — vérifié dans `vendor/symfony/framework-bundle/Test/`.
- `tests/Core/Quality/SandboxTrait.php:53-60` — le motif `Process` avec son `cwd` du dépôt,
  à reprendre pour le seul test qui lance `bin/console` dans un vrai sous-processus.
- `config/services.yaml:63-65` — `App\Core\` est chargé en autowire avec
  `exclude: {Contract,Dto,Entity,Enum,Message}` : la commande et le service nouveaux sont
  enregistrés **sans rien éditer ce fichier**.
- `config/services.yaml:124-126` — alias public `UserPasswordHasherInterface`, pour les tests.
- `deptrac.yaml:41-45` — `Command/` n'est collecté par **aucune** couche technique : la
  commande n'est contrainte que par sa couche de racine et peut appeler `Application`,
  `ArrayInput`, le service. La discipline est tenue par la revue, pas par l'outil.
- `composer.json:47` — `doctrine/doctrine-migrations-bundle` est en **`require-dev`** alors
  que `config/bundles.php:7` l'enregistre en `['all' => true]`. Sur un dérivé installé en
  `--no-dev`, le noyau ne démarre pas et `app:init` n'existe pas. À déplacer en `require`.
- `config/packages/doctrine.yaml:36-49` — `when@test` ajoute
  `dbname_suffix: _test%env(default::TEST_TOKEN)%` : le sous-processus du test de bout en
  bout devra en tenir compte pour nommer et supprimer sa base jetable.
- `config/packages/security.yaml:50` — `default_target_path: app_home`, ce qui ferme AC 2.
  `/` n'a aucune règle `access_control`. Ne pas modifier ce fichier.
- `Makefile:14-19` + `tests/Core/Quality/QualityGateParityTest.php` — la parité catégories /
  cibles / jobs CI. Cette story n'ajoute ni cible ni job, donc rien ne bouge ici.

## Tasks & Acceptance

**Execution:**

- [x] `composer.json` + `composer.lock` -- déplacer `doctrine/doctrine-migrations-bundle` de
      `require-dev` vers `require`, puis `composer update --lock` -- sans cela un dérivé
      installé en production ne démarre pas, et la commande que cette story livre n'existe
      pas là où elle sert.
- [x] `src/Core/Enum/DerivativeState.php` -- l'enum à trois cas `Uninitialized`, `Empty`,
      `Initialized` -- l'état est le seul vocabulaire partagé entre le service qui décide et
      la commande qui enchaîne.
- [x] `src/Core/Exception/InitializationFailed.php` -- premier fichier du dossier :
      constructeurs nommés pour « rôle du socle absent », « adresse déjà prise », « déjà
      initialisé » -- la commande doit pouvoir rapporter *pourquoi* sans relire un message.
- [x] `src/Core/Repository/UserRepository.php` -- ajouter `hasAccountTable(): bool`
      (introspection de schéma, `DatabaseDoesNotExist` attrapée et **elle seule**, le nom de
      table lu sur les métadonnées) et `hasAnyAccount(): bool` -- seule la couche Repository
      a le droit de toucher DBAL.
- [x] `src/Core/Dto/Input/FirstSuperAdminInput.php` -- email (`NotBlank` + `Email`) et mot de
      passe (`NotBlank` + borne haute en octets, **sans `PasswordStrength`**), docblock disant
      l'écart assumé et pourquoi la borne haute n'est pas un confort -- le DTO porte la
      validation, la commande l'appelle, le service le reçoit déjà valide.
- [x] `tests/Core/Initialization/DerivativeInitializerTest.php` -- **écrit en premier et vu
      rouge** : les trois états, la création du Super admin (rôle, `enabled`, langue, mot de
      passe réellement haché par le hasher du projet), le refus sur adresse prise, l'échec
      sur rôle absent -- c'est là que vivent les règles, donc c'est là que porte la
      couverture.
- [x] `src/Core/Service/DerivativeInitializer.php` -- `state(): DerivativeState` et
      `createFirstSuperAdmin(FirstSuperAdminInput): User` -- le service que l'AC 4 exige.
- [x] `tests/Core/Initialization/InitCommandTest.php` -- **écrit avant la commande** : refus
      sans option sur base peuplée (`FAILURE`), création supplémentaire avec `--force`,
      chemin nominal sur base vide via `setInputs()`, `--no-interaction` en `INVALID`,
      re-saisie après une confirmation divergente -- aucun de ces cas n'atteint l'état
      `Uninitialized`, donc aucun DDL ne casse l'isolation DAMA.
- [x] `src/Core/Command/InitCommand.php` -- `app:init`, invocable, `#[Option] bool $force`,
      les deux commandes Doctrine lancées via `Application` **uniquement** sur
      `Uninitialized`, mot de passe masqué demandé deux fois, rapport final nommant l'état
      de départ, les étapes jouées ou sautées et l'adresse créée.
- [x] `tests/Core/Initialization/FirstSuperAdminLoginTest.php` -- un `WebTestCase` qui crée
      le compte par le service, se connecte par le formulaire et vérifie l'arrivée sur
      `app_home` -- AC 2 de la story, et la seule preuve que le compte fabriqué par le
      chemin console est bien utilisable par le chemin HTTP.
- [x] `tests/Core/Initialization/FreshDerivativeTest.php` -- le seul test qui prouve AC 1 en
      entier : une base jetable au nom aléatoire, `bin/console app:init` lancé en
      sous-processus (`Process`, `DATABASE_URL` surchargé), assertions sur le code de sortie,
      sur les trois rôles semés et sur le compte créé, base supprimée en `tearDown` même en
      cas d'échec -- hors PHPUnit, donc hors transaction DAMA, donc le DDL est légitime.

**Acceptance Criteria:**

- Given un dérivé fraîchement cloné et une base inexistante, when `php bin/console app:init`
  est lancé, then la seule intervention demandée est la saisie des identifiants, et la
  commande sort en 0.
- Given la même commande relancée juste après, when elle démarre, then elle refuse, nomme le
  nombre de comptes existants et `--force`, et ne touche ni au schéma ni aux comptes.
- Given la porte de qualité, when `make qa` tourne, then les six catégories passent sans
  qu'aucune cible ni aucun job n'ait été ajouté.
- Given `php vendor/bin/deptrac analyse`, when il lit le service, then aucune dépendance vers
  `Doctrine\DBAL\*` n'en sort — l'introspection est restée dans le repository.

## Implementation Notes

Cinq points où le code livré s'écarte de la lettre de la spec, chacun parce que le dépôt ou
`vendor/` disaient autre chose. Les quatre premiers sont vérifiés, pas supposés.

**1. `DatabaseDoesNotExist` seule ne suffit pas — elle n'est jamais levée ici.** La spec
demandait qu'`UserRepository::hasAccountTable()` attrape `DatabaseDoesNotExist` « et elle
seule ». Mesuré sur DBAL 4.4 + `pdo_mysql` + MySQL 8.4 : une base absente produit
l'erreur MySQL **1049**, que `Driver\API\MySQL\ExceptionConverter` convertit en
`ConnectionException` — `DatabaseDoesNotExist` est réservée à l'erreur 1008 (« can't drop
database »). S'en tenir à la lettre aurait fait sortir `app:init` sur une trace au tout
premier scénario de la matrice. Le code attrape donc aussi `ConnectionException`, **mais
uniquement au code 1049** : serveur éteint, mot de passe faux et hôte inconnu continuent de
remonter, pour ne pas se déguiser en « dérivé non initialisé ». Vu rouge par mutation.

**2. `hasAnyAccount(): bool` est devenu `countAccounts(): int`.** La matrice (gelée) exige
que le refus nomme *le nombre* de comptes existants ; un booléen ne le porte pas, et
ajouter un `has…` à côté d'un `count…` ferait deux requêtes pour une question. Le service
expose `accountCount()` par-dessus.

**3. Une méthode de service en plus : `reopenAfterSchemaChange()`, et elle ferme un vrai
bug.** `doctrine:migrations:migrate` enveloppe chaque version dans une transaction ; sur
MySQL le DDL déclenche un *implicit commit* qui détruit transaction et points de sauvegarde
sans que DBAL le sache. Mesuré : `getTransactionNestingLevel()` vaut **4** après les quatre
migrations. Sans remise à zéro, la toute première `flush()` du dérivé échoue sur « SAVEPOINT
DOCTRINE_4 does not exist » — et comme MySQL est alors en autocommit, **le compte est écrit
quand même** : `app:init` sortait en 255, avec une trace, après avoir réussi. Le pire des
demi-succès, et seul `FreshDerivativeTest` pouvait le voir. `close()` est appelé par le
repository, seule couche autorisée à voir DBAL.

**4. Le masquage du mot de passe est conditionné au fait que l'entrée soit un terminal.**
`setHidden(true)` ne cache rien par lui-même : il demande au *terminal* de couper son écho.
Quand l'entrée est un tube ou un `CommandTester`, il n'y a aucun écho à couper — et sous
Windows c'est même bloquant : `QuestionHelper::getHiddenResponse()` y lance
`hiddeninput.exe`, qui ignore le flux fourni et attend la console réelle (vérifié : la
suite se fige). `InitCommand::readsFromATerminal()` est donc exactement la condition sous
laquelle le masquage a un effet. `setHiddenFallback(false)` ferme l'autre bout : sur un
vrai terminal où le masquage échouerait, la commande s'arrête au lieu d'écho-er.

**5. Un fichier de test en plus : `tests/Core/Dto/FirstSuperAdminInputTest.php`.** Les règles
d'un DTO se testent sans noyau ni console, comme le fait déjà `PasswordResetInputTest` ; ce
fichier épingle en plus, par un test, l'**absence** délibérée de `PasswordStrength` — pour
qu'une relecture la lise comme une décision et non comme un oubli. Les deux bornes hautes
sont **bien** atteignables depuis la console et y sont aussi exercées par
`InitCommandTest` : `QuestionHelper::doReadInput()` lit octet par octet
(`fread($inputStream, 1)`) et ne tronque aucune ligne.

**6. Deux bornes hautes, et non une.** `$email` n'en avait aucune alors que
`User::$email` est un `varchar(180)` : une adresse bien formée de 200 caractères passait la
validation, passait le contrôle d'unicité, et faisait lever « Data too long » à `flush()` —
le mode de panne que la borne du mot de passe existe précisément pour fermer. Les deux ne
comptent pas la même chose : octets pour le mot de passe (`CheckPasswordLengthTrait`),
points de code pour l'adresse (une colonne `utf8mb4` compte des caractères). Le nombre est
recopié dans le DTO, parce que `deptrac.yaml` interdit à la couche `Dto` de voir `Entity` ;
un test le confronte au mapping de l'entité par réflexion pour qu'ils ne divergent pas.

**7. Le mot de passe n'est plus rogné.** `Question` rogne par défaut, chemin masqué compris :
un mot de passe encadré d'espaces était enregistré **différent de ce qui avait été tapé**, et
la page de connexion l'aurait refusé ensuite sans rien pouvoir expliquer. Seul le saut de
ligne est retiré, parce que `doReadInput()` le conserve. L'adresse, elle, reste rognée.

**Ce qui reste hors de portée d'un test automatisé :** que la saisie du mot de passe soit
réellement invisible sur un vrai TTY. Le chemin masqué n'est exerçable qu'à la main, sur un
terminal.

**6. Deux lignes de la matrice n'étaient couvertes par aucun test, ajoutées à la
vérification.** L'audit de matrice les a trouvées, et les deux sont vues rouges par
mutation :

- *« Schéma absent — base vide déjà créée »* (ligne 2) : `FreshDerivativeTest::
  an_existing_but_empty_database_is_migrated_rather_than_recreated()` crée la base avant de
  lancer `app:init`, puis exige que la sortie annonce la création **sautée** et que le
  compte atterrisse bien sur cette base-là. C'est le cas de l'hébergeur qui fournit une base
  vide sans donner le droit de faire `CREATE DATABASE`, et c'est la seule chose qui
  distingue `--if-not-exists` d'un oubli : retirer l'option fait sortir la commande en
  échec, et le test en rouge.
- *« Rôle `super_admin` absent »*, vue **depuis la commande** (dernière ligne) :
  `InitCommandTest::a_missing_core_role_is_reported_as_a_failure_rather_than_a_trace()`.
  `DerivativeInitializerTest` prouvait que le service lève ; rien ne prouvait que la
  commande attrape plutôt que de rendre une trace et un code 255. Le test exige aussi que
  **rien ne soit demandé** — la vérification précède les questions. Retirer l'appel à
  `verifyCoreRoles()` le fait sortir en erreur.

## Spec Change Log

## Review Triage Log

Passe 1 — quatre couches : blind-hunter (13), edge-case-hunter (7), verification-gap
(2 pré-vérifiées + 3 autres), proglab-conformance (3). Chaque trouvaille non pré-vérifiée a
été rouverte à l'emplacement cité, et `vendor/` consulté, avant verdict.

| # | Couche | Trouvaille | Verdict | Évidence | Route |
|---|---|---|---|---|---|
| 1 | blind + vgap | `FreshDerivativeTest` exige un droit MySQL de niveau serveur que la CI ne donne pas : l'image n'accorde à `app` que `app_test.*` | `high` | Vérifié : `.github/workflows/ci.yml:47-49,63` monte `MYSQL_USER: app` / `MYSQL_DATABASE: app_test` et connecte la suite en `app:app`. `CREATE DATABASE proglab_init_…` échouerait en « Access denied » — et cette classe est la **seule** preuve d'AC 1, du chemin `Uninitialized`, de `--if-not-exists` et du correctif de connexion | patch |
| 2 | blind + edge | `FirstSuperAdminInput::$email` n'a aucune borne de longueur, quand `User::$email` est `#[ORM\Column(length: 180)]` | `medium` | Vérifié `src/Core/Entity/User.php:57`. Une adresse bien formée de 200 caractères passe `NotBlank`, `Email` et le contrôle d'unicité, puis lève « Data too long » **dans `flush()`** : trace et 255. C'est exactement le mode de panne que le docblock du DTO invoque pour justifier la borne du mot de passe — argument appliqué à une moitié seulement | patch |
| 3 | blind + edge | La revendication « `QuestionHelper::readInput()` lit par `fgets($stream, 4096)` » est fausse, et une ligne de la matrice a été sautée sur sa foi | `medium` | Réfutée dans `vendor/` : `symfony/console` v7.4.18 ne contient aucun `fgets` dans `QuestionHelper.php` — `doReadInput()` lit octet par octet (`fread($inputStream, 1)`, l. 618). La borne haute **est** atteignable depuis la console, donc la ligne « au-delà de `MAX_PASSWORD_LENGTH` » doit être couverte dans `InitCommandTest`, et les deux docblocks disent le contraire de ce que fait le code | patch |
| 4 | edge | `Question` est `trimmable` par défaut : un mot de passe à espace initial ou final est enregistré rogné, donc différent de celui qui a été tapé | `medium` | Vérifié `vendor/symfony/console/Question/Question.php:39` (`private bool $trimmable = true`) et `Helper/QuestionHelper.php:110,134,176` (`trim()` sur les trois chemins, masqué compris). Le compte créé refuserait ensuite la connexion HTTP avec le mot de passe réellement saisi | patch |
| 5 | blind | Rien ne prouve que les erreurs partent sur stderr, alors que la colonne « Error Handling » de la matrice gelée l'affirme sur quatre lignes | `medium` | Vérifié : toutes les assertions lisent `$tester->getDisplay()`, et `CommandTester` fusionne les deux flux sans `capture_stderr_separately`. `SymfonyStyle::getErrorStyle()` rend `$this` quand la sortie n'est pas un `ConsoleOutputInterface` — c'est le cas ici : les tests passent identiquement avec ou sans `getErrorStyle()` | patch |
| 6 | blind + vgap | Le déplacement de `doctrine/doctrine-migrations-bundle` vers `require` n'est vérifié par rien | `medium` | Pré-vérifié par la couche vgap et reproduit : remettre la ligne en `require-dev` laisse toute la porte verte — aucun job n'installe en `--no-dev`, et `lint:container --env=prod` construit contre l'arbre de dev. Le dépôt a pourtant le précédent exact (`KitIntegrityTest:38-56` lit `composer.json`). La panne que cette tâche ferme — un noyau qui ne démarre pas en production — reviendrait en silence | patch |
| 7 | vgap + conformance | La discrimination « 1049 et lui seul » d'`UserRepository::hasAccountTable()` n'est épinglée par aucun test | `medium` | Pré-vérifié : `DerivativeInitializerTest:49` **double** `hasAccountTable()` et ne traverse jamais les `catch` ; `FreshDerivativeTest` n'exerce que la branche 1049. Remplacer le bloc par un `catch (ConnectionException) { return false; }` aveugle laisse la suite verte — et un dérivé aux identifiants tournés s'entendrait annoncer « base absente » avant de tenter de la créer. La couche vgap proposait `defer` ; le harnais de sous-processus existant rend le test bon marché | patch |
| 8 | blind + vgap | `an_existing_but_empty_database_is_migrated_rather_than_recreated()` assert la chaîne anglaise `'already exists'`, prose amont de Doctrine | `low` | Vrai et vérifié `vendor/doctrine/doctrine-bundle/src/Command/CreateDatabaseDoctrineCommand.php:88`. Une reformulation amont ferait rougir un comportement correct. Correctif direct : une table témoin posée avant l'appel, qui doit survivre — ce qui prouve la non-recréation sans dépendre d'aucune formulation | patch |
| 9 | blind | Le raisonnement `hash_equals()` est faux : la fonction sort sur une différence de longueur, elle n'est pas aveugle à la longueur | `low` | Vrai. Les deux opérandes sont ici le même secret tapé deux fois par le même opérateur sur son propre terminal, sans observateur distant. Le commentaire enseigne quelque chose d'inexact sur la fonction ; correctif direct sur le texte | patch |
| 10 | conformance | `src/Core/Exception/` n'a pas rejoint la liste d'exclusion de l'autowiring, contre le précédent de `Message` (story 1.8) | `low` | Vrai et vérifié `config/services.yaml:65`. Effet pratique nul — le service privé inutilisé est supprimé à la compilation —, mais la convention est écrite dans le fichier lui-même. Correctif direct : un mot dans une liste | patch |
| 11 | blind | Les exigences d'environnement de `FreshDerivativeTest` ne sont déclarées nulle part, ni dans « Ce qu'il faut avoir en local » ni ailleurs | `low` | Vrai : la classe a besoin d'un compte MySQL habilité à créer et supprimer une base. Correctif direct, et la même lacune que le constat 1 vue du côté poste de travail | patch |
| 12 | blind | Le service fait confiance à un DTO qu'il ne valide pas — `\assert()` disparaît en production | `low` | Vrai au pied de la lettre, et c'est la forme **voulue** du dépôt : `PasswordController:81` valide et `PasswordReset` reçoit un DTO déjà valide, exactement comme ici. Valider dans le service dupliquerait la validation dans deux couches et contredirait la convention écrite | rejeté |
| 13 | blind | L'adresse déjà prise n'est découverte qu'après le mot de passe tapé deux fois, et tout est jeté | `low` | Vrai. Mais la ligne « Email déjà pris » de la matrice **gelée** fixe `FAILURE`, pas une nouvelle question : le comportement livré est celui que l'humain a approuvé. Et le correctif ajouterait une méthode publique au service | rejeté |
| 14 | blind | La même question est posée cinq fois à la base par exécution (`state()` recalculé dans le garde, `accountCount()` relu) | `low` | Vrai et vérifié. Trois requêtes triviales de plus, une fois par installation d'un dérivé. Le correctif change une signature publique pour un coût que personne ne mesurera | rejeté |
| 15 | blind | `reopenConnection()` — le correctif du pire bug de cette story — n'a aucun test direct | `low` | Vrai, et le test proposé est infaisable : sous DAMA la connexion **est** la transaction d'isolation, et `getTransactionNestingLevel()` n'y vaut pas 0. `FreshDerivativeTest` le tient rouge de bout en bout, ce qui est la seule façon de l'exercer | rejeté |
| 16 | blind | Le `README` documenterait encore le chemin manuel que cette story remplace | `false` | Réfuté : `README.md:123-124` sont des commandes `--env=test` qui montent la **base de test** de la suite — `app:init` ne les remplace pas et ne doit pas. Le README n'a aucune section de démarrage applicatif à rendre obsolète. La part vraie — l'exigence locale non déclarée — est le constat 11 | rejeté |
| 17 | blind | Le repli de masquage est silencieux : sur une entrée non-TTY, rien ne signale que le mot de passe n'est pas masqué | `low` | Vrai. Sans TTY il n'y a ni écho ni écran où lire quoi que ce soit : il n'y a rien à divulguer. Le correctif ajoute une branche et une note pour un risque qui n'existe pas | rejeté |
| 18 | blind | `InitializationFailed` justifie l'absence de `#[WithHttpStatus]` mais se tait sur `#[WithLogLevel]` | `low` | Vrai au pied de la lettre. Le paragraphe écrit — « ces échecs ne franchissent jamais HTTP » — couvre les deux attributs par la même raison ; l'énumérer serait redire la même chose | rejeté |
| 19 | blind | `sprint-status.yaml` dit `in-progress` quand le spec dit `in-review` | `false` | Le diff est pris en cours de workflow : l'étape de relecture passe le spec en `in-review`, et l'étape de présentation synchronise le fichier de sprint. Les deux convergent avant le commit | rejeté |
| 20 | edge | Une migration qui **lève** au lieu de rendre un code non nul court-circuite le message taillé de `prepareSchema()` | `low` | Vrai : `Command::run()` n'attrape pas, seul `Application::run()` le fait. Mais l'exception d'une migration cassée porte la ligne SQL fautive — la remplacer par « le schéma n'a pas pu être préparé » retirerait à l'opérateur la seule information utile | rejeté |
| 21 | edge | `--allow-no-migration` masque le cas « migrations enregistrées, tables supprimées » : `verifyCoreRoles()` tombe alors sur une exception SQL brute | `low` | Vrai, et il faut que quelqu'un ait supprimé les tables à la main sans toucher `doctrine_migration_versions`. Le correctif ajoute une méthode publique au service et une branche pour un état jamais démontré atteignable | rejeté |
| 22 | edge | Sans TTY mais sans `--no-interaction`, `isInteractive()` reste vrai : une entrée vide finit en `MissingInputException` | `low` | Vrai sur Windows seulement — ailleurs `Application::configureIO()` éteint l'interactivité par `posix_isatty`, et le chemin `INVALID` s'ouvre proprement. Le cas restant est `app:init < nul` sur un poste Windows | rejeté |
| 23 | edge | Les Tasks du spec annoncent `hasAnyAccount(): bool` et « `DatabaseDoesNotExist` et elle seule », que le code ne suit pas | `low` | Vrai, et les deux écarts sont écrits dans les Implementation Notes avec leur mesure. Le correctif consiste à éditer le spec de ce build — écarté par la règle de triage | rejeté |
| 24 | vgap | `InitCommand` n'entoure pas `state()` d'un `try/catch` : des identifiants faux finissent en exception non rattrapée, code 255 | `low` | Vrai, et voulu : le relancement du constat 7 existe précisément pour qu'une panne de connexion ne se déguise pas en « dérivé non initialisé ». Le message DBAL — « Access denied for user… » — est plus utile que toute phrase générique qu'on écrirait à la place | rejeté |
| 25 | conformance | La commande enchaîne six appels de service et une séquence qui porte le comportement, contre « un seul appel de service » du skill console | `low` | Vrai, et c'est le design que l'humain a approuvé : le bloc gelé, les Design Notes et le docblock de la classe le posent et l'argumentent tous les trois. La couche le qualifie elle-même d'écart déclaré. Le rouvrir demanderait de rouvrir l'intention | rejeté |

Aucune trouvaille ne route en `bad_spec` ni en `intent_gap` : aucune ne demande de redériver
le code, et la seule qui remonte au bloc gelé — le constat 13 — y trouve la décision
explicite de Fabrice. Onze correctifs, aucun report, quatorze rejets.


## Design Notes

**Pourquoi trois états et pas quatre.** Distinguer « la base n'existe pas » de « la base
existe mais est vide » demanderait au service de lire une exception DBAL, donc au code de
règle de connaître Doctrine — ce que `deptrac.yaml:253-262` interdit. Et cela n'achèterait
rien : `doctrine:database:create --if-not-exists` est déjà un no-op sur une base existante.
Les deux cas fusionnent donc en `Uninitialized`, et la commande annonce ce que Doctrine lui
répond plutôt que de le prédire.

**Pourquoi la commande enchaîne des sous-commandes plutôt que le service.** Jouer les
migrations depuis un service exigerait de piloter `DependencyFactory`, `PlanCalculator` et
`Migrator` à la main : une trentaine de lignes qui réimplémentent `doctrine:migrations:migrate`
et qu'il faudrait maintenir contre l'amont. Enchaîner les commandes existantes est de la
plomberie console, au même titre que la mise en forme de la sortie — et le déploiement de la
story 3.1 jouera les mêmes. Ce que l'AC 4 exige, c'est qu'aucune *décision* ne vive dans la
commande : elle en prend une seule, `match($state)`, et cet état lui est rendu tout fait.

**Le branchement n'est pas un contournement du test.** Ne jouer le DDL que sur
`Uninitialized` est d'abord le bon comportement — une commande relancée ne doit rien
retoucher, et le rapport doit dire ce qu'elle a sauté. Que cela laisse les tests
fonctionnels hors de portée du DDL, et donc hors de portée de l'`implicit commit` MySQL qui
viderait la transaction de DAMA, est une conséquence heureuse, pas la raison.

**`--force` inverse le patron du skill console, et c'est la story qui le demande.** Le
standard veut un dry-run par défaut sur une commande destructive. Ici le défaut sûr n'est
pas « rapporter » mais « agir sur une base vide, refuser sur une base peuplée » — parce que
le danger n'est pas la perte de données, c'est la création silencieuse d'un compte à tous
les droits sur un dérivé en service. L'écart est déclaré, pas contourné.

**L'écart de robustesse est déclaré, pas oublié.** Le mot de passe du premier Super admin
n'est pas mesuré, là où la page de réinitialisation refuse tout ce qui est sous 80 bits
d'entropie. C'est un choix de Fabrice : le seul appelant de ce chemin est la personne qui
installe le dérivé, devant son propre terminal, et un refus incompréhensible pendant une
installation n'a pas la page de secours qu'a la réinitialisation. La borne haute, elle,
reste — elle n'est pas une politique mais une protection contre une `InvalidPasswordException`
levée par le hacheur, que rien n'attrape ici. Le DTO doit porter ce raisonnement, faute de
quoi la première relecture le lira comme un oubli et « corrigera » vers `PasswordStrength`.

**Ce que cette story ne peut pas prouver.** Que le premier Super admin *voie la roadmap* :
l'Epic 3 la pose. AC 2 se ferme sur la page d'accueil telle qu'elle existe, et le test le
dit en toutes lettres.

## Verification

**Commands:**
- `vendor/bin/phpunit` -- suite au vert, après avoir été vue rouge
- `php bin/console list app` -- `app:init` apparaît avec sa description
- `php bin/console lint:container` et `--env=prod` -- le service et la commande se résolvent
- `composer validate --strict` -- le lock suit le déplacement de dépendance
- `php vendor/bin/deptrac analyse --config-file=deptrac.yaml --report-uncovered` -- zéro violation
- `php vendor/bin/phpstan analyse` -- niveau max, zéro erreur
- `make qa` -- les six catégories passent

**Manual checks:**
- Sur une base jetable (`DATABASE_URL` pointant vers un nom inexistant) : `app:init` crée,
  migre, demande, crée le compte, sort en 0 ; relancé, il refuse en nommant `--force`
- La saisie du mot de passe n'apparaît pas à l'écran, et l'adresse créée est la seule donnée
  personnelle du rapport final
