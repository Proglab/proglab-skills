---
title: 'Se connecter avec son email et son mot de passe'
type: 'feature'
created: '2026-09-11'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '8bfe631c5155d56f9880f5b23a2b149c31f7d4c5'
context:
  - '{project-root}/.claude/skills/symfony-proglab-security/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-http/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-testing/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-accessibility/SKILL.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-proglab-skills-2026-09-09/EXPERIENCE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** le socle n'a aucune sécurité câblée — ni entité `User`, ni
`config/packages/security.yaml`, ni `symfony/security-bundle` dans `composer.json`. Rien
ne distingue un visiteur d'un utilisateur, et les cinq epics suivantes supposent toutes
qu'un compte existe, porte un rôle et une langue, et peut être désactivé.

**Approach :** poser les entités `User` et `Role`, semer les trois rôles du socle, et
livrer un unique firewall `main` à `form_login` natif sur `/login` — une Card centrée qui
hérite du gabarit de base. L'échec réaffiche le formulaire en **422** avec un message
générique unique dans un bloc `role="alert" tabindex="-1"`, un compte désactivé reçoit son
message dédié **avant** toute vérification du mot de passe, et la connexion mémorise la
langue du compte en session pour que la chaîne de résolution existante la serve ensuite.

## Boundaries & Constraints

**Always :**
- **Un seul firewall applicatif** (`main`) plus `dev`. Le firewall `/api` sans état est
  l'affaire de l'Epic 6 et n'entre pas ici.
- `password_hashers: auto` sur `PasswordAuthenticatedUserInterface`. Aucun algorithme
  nommé en dur (AD *absent* — c'est la lecture du standard qui tranche).
- **Le refus ne renseigne jamais sur le mot de passe.** Le contrôle « compte désactivé »
  est un `checkPreAuth`, donc il tombe identiquement que le mot de passe soit bon ou faux.
- **CSRF activé sur le formulaire de connexion** (`enable_csrf: true`), par jeton en
  session. AD-18 ne souffre aucune exception, même si le critère est rédigé en 1.7.
- Tout fonctionne **sans JavaScript** (AD-17) : le formulaire est une soumission pleine
  page, le sélecteur de langue est fait de liens.
- Les chaînes françaises sont celles du tableau *Voice and Tone*, **au mot près**, et sont
  livrées dans les trois langues.
- `/login` est balayé automatiquement par le plancher d'accessibilité (`pages()` découvre
  toute route `App\` en GET sans paramètre) : il doit rendre 200 et satisfaire les seize
  règles sans qu'aucune exemption soit ajoutée.
- Aucun code ne teste un nom de rôle (AD-8) : cette story ne livre **aucune** décision
  d'autorisation.

**Never :**
- Pas de `login_throttling`, pas de compteur d'échecs, pas de message « Patientez N
  secondes » — c'est la story 1.7, et `symfony/rate-limiter` n'entre pas ici.
- Pas de permissions, pas de grille, pas de voter, pas d'`access_control` au-delà de ce
  qu'exige l'accès anonyme à `/login` — Epic 2.
- Pas de jeton de sécurité de session (AD-9), pas d'`EquatableInterface` : rien dans cette
  story ne l'incrémente, et une mécanique que rien ne déclenche n'est vérifiée par rien.
  Elle arrive avec la première story qui désactive un compte ou change un mot de passe.
- Pas de `symfony/form` ni de `symfony/validator` : `form_login` lit `_username` et
  `_password` bruts sur la requête, aucun contrôleur ne traite l'entrée, donc il n'y a ni
  DTO d'entrée ni FormType. **C'est la règle 4 lue strictement, pas contournée.**
- Pas d'écriture d'audit (Epic 4), pas de 2FA (Epic 5), pas de réinitialisation de mot de
  passe (story 1.9), pas de page 403 (story 1.10).
- Pas de `stateless_token_ids` : le CSRF sans état exige son contrôleur Stimulus, ce qui
  contredit AD-17. Le défaut de Symfony (jeton en session) est le bon ici, et cela referme
  la dette ouverte de `deferred-work.md`.

## I/O & Edge-Case Matrix

| Scénario | Entrée / État | Comportement attendu | Gestion d'erreur |
|---|---|---|---|
| Connexion réussie | Compte actif, email et mot de passe justes | 302 vers `/` ; la langue du compte est écrite en session ; la page suivante est rendue dans cette langue | N/A |
| Mot de passe faux | Compte actif, mot de passe faux | **422** sur `/login`, formulaire réaffiché, email conservé, « Email ou mot de passe incorrect. » dans un unique bloc `role="alert" tabindex="-1"` | Aucun champ `aria-invalid` |
| Email inconnu | Aucun compte pour cette adresse | Réponse **strictement identique** à la ligne précédente — même statut, même message, même DOM | Aucune fuite d'existence |
| Compte désactivé, mot de passe juste | `enabled = false` | 422, « Ce compte est désactivé. Adressez-vous à votre administrateur. » | Aucune session ouverte |
| Compte désactivé, mot de passe faux | `enabled = false` | **Même réponse que la ligne précédente** : le contrôle est antérieur à la vérification du mot de passe | Rien ne révèle que le mot de passe était faux |
| Jeton CSRF absent ou périmé | POST sans `_csrf_token` valide | 422, message générique, aucune session ouverte | Traité comme un échec d'authentification |
| Déjà connecté | Session ouverte, GET `/login` | 302 vers `/` | N/A |
| `/login` en GET | Anonyme | 200, une seule `h1`, `<nav aria-label="Langue">` de liens `?lang=`, aucun bloc d'alerte | N/A |
| `?lang=nl` sur `/login` | `nl` active | Page en néerlandais, choix mémorisé en session ; après connexion, la langue du compte écrase ce choix | N/A |
| Mot de passe en base | Compte créé par le code | Jamais lisible, jamais réversible, préfixe d'algorithme conforme à `auto` | N/A |

## Décisions de Fabrice (2026-09-11)

- **La langue du compte est une colonne enum `SupportedLocale`, pas une relation.** AD-14
  l'emporte sur l'ERD du spine (l. 632), qui dessinait `LANGUAGE ||--o{ USER`. L'enum reste
  la seule autorité sur les langues supportées, la table `language` garde son unique rôle —
  dire *quelles langues sont offertes* — et aucun chargement d'utilisateur ne porte de
  jointure. Une langue désactivée que porte encore un compte se replie au rendu, comme
  n'importe quel autre repli de la story 1.5. **C'est la première des contradictions amont
  tranchée dans le code ; elle vaut pour tout l'Epic 5.**
- **`/logout` est livré ici.** Quatre lignes — `logout: { path: app_logout }` et une route
  vide — et aucun écran. Le firewall est refermé d'un seul coup plutôt que rouvert par la
  story 2.3, et la connexion devient vérifiable à la main sans vider ses cookies. La route
  manque à la table AD-6 : c'est la table qui est incomplète, pas la story.
- **`Role` porte un `code` en plus de son `name`.** L'enum `CoreRole` donne aux trois rôles
  du socle une prise stable ; les rôles qu'un administrateur créera à l'Epic 2 n'en portent
  pas (`code` nullable et unique). Le renommage reste libre, la migration et la commande
  d'initialisation de la story 1.11 ne cherchent jamais sur un libellé, et « le Super admin
  ne se supprime pas » devient exprimable. **Aucune décision d'autorisation ne s'adosse à
  ce code** — AD-8 tient.
- **Le spec est gardé entier** malgré ses ~7 900 tokens. Ses six couches — entités,
  migration, firewall, écran, traductions, tests — ne livrent rien de visible séparément ;
  découper reproduirait le faux découpage écarté à la story 1.5.

</frozen-after-approval>

## Code Map

- `composer.json` — **rien de la sécurité n'est installé** : ni `symfony/security-bundle`,
  ni `security-core/http`, ni `password-hasher`, ni `security-csrf`, ni `form`, ni
  `validator`. `symfony/security-bundle:7.4.*` et `symfony/security-csrf:7.4.*` suffisent à
  cette story. Aucun job de CI n'installe d'extension supplémentaire : contrairement à la
  1.5 (`ext-intl`), **`.github/workflows/ci.yml` ne bouge pas**.
- `config/packages/security.yaml` — **absent**. Fichier neuf, écrit à la main : la recette
  Flex pose un provider `users_in_memory` qu'il ne faut pas garder.
- `config/bundles.php` — `SecurityBundle` à ajouter (Flex le fait ; vérifier).
- `src/Core/Entity/Language.php` — **le précédent d'entité** : `#[ORM\Entity]` **sans
  `repositoryClass:`** (deptrac l'interdit, docblock l. 25-37), colonne enum via
  `enumType:`, setters retournant `static`, aucune méthode de comportement. `User` et
  `Role` le copient.
- `src/Core/Repository/LanguageRepository.php` — le précédent de repository : `extends
  ServiceEntityRepository` **non `final`**, `@extends ServiceEntityRepository<X>`,
  requête nommée d'après l'intention (`findActiveCodes()`).
- `src/Core/Enum/SupportedLocale.php` — `Fr`/`En`/`Nl`, `codes()`, `fallback()`. La langue
  du compte s'y adosse.
- `src/Core/EventListener/LocaleListener.php` — `PRIORITY = 20`, `SESSION_KEY = '_locale'`,
  chaîne `?lang=` → session → première langue active. **La ligne 81 porte le commentaire
  « la story 1.6 insérera ici le barreau langue du compte » : ce commentaire doit être
  remplacé**, pas honoré tel quel — voir Design Notes. `SESSION_KEY` est la clé qu'écrit
  le listener de connexion réussie.
- `src/Core/Service/ActiveLocales.php` — `all()`, `has()`, `first()`, `reset()`. C'est ce
  que le sélecteur de langue de `/login` doit lire.
- `deptrac.yaml` — la couche `Http` n'attrape que `Symfony\Component\HttpFoundation\*` ;
  tout `Symfony\Component\Security\*` n'est dans **aucune** couche technique et
  `--fail-on-uncovered` n'est pas utilisé. Conséquences dures :
  `Security:` n'autorise que `Security, Entity, Dto, Enum` — **un handler d'échec qui
  construit une `Response` ne peut pas vivre dans `src/Core/Security/`** ;
  `Controller:` n'autorise pas `Doctrine` ; `EventListener/`, `Command/` et `Twig/` n'ont
  **aucune** couche technique, donc un écouteur peut voir HTTP, Twig et les services.
- `src/Core/Security/.gitkeep` — dossier prévu pour le `UserChecker` (le spine le nomme).
- `src/Core/Controller/HomeController.php` — la convention HTTP du dépôt : `final class …
  extends AbstractController`, `#[Route]` + `#[Template]`, **la méthode retourne un tableau,
  jamais `render()`**, avec son `@return array{…}`.
- `templates/base.html.twig` — `header`, `nav` et `footer` sont des blocs **conditionnels**
  (l. 72-103) : une page qui ne les remplit pas ne les rend pas, ce qui donne la Card
  centrée sans gabarit séparé. **Contrainte écrite l. 87-95 : le bloc `role="alert"` doit
  être rendu à l'intérieur de `<main>`**, sinon le contrôleur Stimulus `page-focus` ne le
  trouve pas. `{% block title %}` ne porte que le nom de la page.
- `templates/components/` — `Alert.html.twig` porte **déjà** `role="alert"` (+
  `Alert:Title`, `Alert:Description`), `Input`, `Label`, `Button`, `Card` (+ `Card:Header`,
  `Card:Title`, `Card:Content`). Le `tabindex="-1"` se passe en attribut. Aucune classe
  Tailwind de couleur en dur : `HardcodedColorTest` l'interdit.
- `config/packages/twig.yaml` — `globals: { app_name: '%app.name%' }`. **Le précédent
  pour exposer `active_locales` sans qu'un contrôleur ait à le passer** — nécessaire parce
  que la page de connexion est rendue à deux endroits (contrôleur et écouteur d'échec).
  `when@test` pose `strict_variables: true`.
- `translations/messages.{fr,en,nl}.yaml` — trois clés aujourd'hui (`layout.*`,
  `home.index.title`), clés anglaises pointées, source française, domaine simple **non
  ICU**. `tests/Core/Translation/CatalogParityTest.php` exige la même liste de clés non
  vides dans les trois fichiers : une clé ajoutée doit l'être trois fois.
- `tests/Core/Accessibility/AccessibilityFloorTest.php:656` `pages()` — **découverte
  automatique** : toute route `App\` en GET sans paramètre est chargée et doit répondre
  200. `/login` y entre seul ; **rien à enregistrer**. Les seize règles `#[Test]`
  (l. 48-619) s'y appliquent, dont `rule_3_every_form_control_has_a_real_label` et
  `rule_1_no_field_signals_its_error_by_colour_alone`.
- `migrations/Version20260911074811.php` — la seule migration : `CREATE TABLE language`
  (l. 42) puis `INSERT` des trois langues (l. 56). **Le précédent de « semer par
  migration »** que les trois rôles copient.
- `tests/Core/Controller/HomeControllerTest.php`, `tests/Core/Translation/LocaleResolutionTest.php`
  — conventions : `#[Test]`, méthodes snake_case anglaises, `self::createClient()`,
  assertions statiques avec message explicatif, providers `public static ... : iterable`,
  helpers privés en fin de classe. `GateFiles::projectDir()` existe déjà — ne pas la
  redéclarer (trouvaille n° 12 de la story 1.5).
- `phpunit.dist.xml` — deux testsuites : `Project Test Suite` (exclut
  `tests/Core/Accessibility`) et `Accessibility`. Aucune nouvelle testsuite ici, aucune
  nouvelle catégorie de porte, donc `tests/Core/Quality/QualityGateParityTest.php` **ne
  bouge pas**.
- `Makefile:97` `a11y` et `:55` `test` — les deux migrent déjà avant de lancer la suite :
  la nouvelle migration est jouée sans qu'aucune cible change.
- `doctrine/doctrine-fixtures-bundle` **absent** : les tests créent leurs `User` à la main
  via `EntityManagerInterface`, comme les `Language` de la story 1.5.

## Tasks & Acceptance

**Execution :**
- [x] `composer.json` -- ajouter `symfony/security-bundle` et `symfony/security-csrf` en
      `7.4.*`, vérifier `config/bundles.php` -- rien de la sécurité n'existe aujourd'hui.
- [x] `src/Core/Enum/CoreRole.php` -- enum de chaînes des trois rôles du socle, dans leur
      ordre d'affichage -- la prise stable que la migration, la story 1.11 et l'Epic 5
      demandent, sans qu'aucune décision d'autorisation ne s'y adosse.
- [x] `src/Core/Entity/Role.php` + `src/Core/Repository/RoleRepository.php` -- entité au
      format maker (`id`, `code` nullable et unique porté par `CoreRole`, `name`) et la
      seule requête utile — retrouver un rôle du socle par son code -- un rôle est une
      donnée, pas un `ROLE_` de Symfony.
- [x] `src/Core/Entity/User.php` -- `implements UserInterface,
      PasswordAuthenticatedUserInterface` ; `email` unique, `password`, `enabled`, `role`
      (`ManyToOne` non nul), `language` (colonne enum `SupportedLocale`) ;
      `getUserIdentifier()` rend l'email et `getRoles()` rend `['ROLE_USER']` en dur --
      AD-8 interdit qu'un rôle métier devienne un rôle Symfony.
- [x] `src/Core/Repository/UserRepository.php` -- `extends ServiceEntityRepository` ; aucune
      méthode au-delà de ce que le provider exige -- le provider `entity` de Symfony charge
      par propriété, il n'a besoin de rien de plus.
- [x] `migrations/VersionXXXX.php` -- créer `role` et `user`, insérer les trois rôles du
      socle, poser l'unicité de l'email -- « les trois rôles existent comme rôles nommés »,
      semés comme les trois langues de la 1.5.
- [x] `config/packages/security.yaml` -- `password_hashers: auto`, provider `entity` sur
      `User.email`, firewall `dev`, firewall `main` `lazy` avec `form_login`
      (`login_path`/`check_path` = `app_login`, `enable_csrf: true`, `default_target_path`
      = `app_home`), `user_checker`, `logout: { path: app_logout }`, `access_control` laissant `/login` en
      `PUBLIC_ACCESS` -- un seul firewall applicatif ; fichier neuf, la recette Flex n'est
      pas conservée.
- [x] `src/Core/Security/UserChecker.php` -- `checkPreAuth()` lève une
      `CustomUserMessageAccountStatusException` portant la clé du message « compte
      désactivé » -- **`checkPreAuth` et non `checkPostAuth`** : c'est ce qui rend le refus
      identique que le mot de passe soit bon ou faux.
- [x] `src/Core/Controller/SecurityController.php` -- deux routes dans une seule classe :
      `#[Route('/login', name: 'app_login', methods: ['GET','POST'])]` + `#[Template]`
      retournant `['last_username' => …, 'error' => …]` via `AuthenticationUtils`, et
      `#[Route('/logout', name: 'app_logout', methods: ['GET'])]` sur une méthode vide --
      le pare-feu intercepte les deux ; ni le POST de connexion ni la déconnexion
      n'atteignent jamais leur méthode, et le corps de chacune le dit.
- [x] `src/Core/EventListener/LoginFailureListener.php` -- écouteur sur `LoginFailureEvent`
      qui rend le même template et pose le statut **422** -- `form_login` redirige nativement ;
      c'est le seul endroit du dépôt où le contrat de couches autorise à construire une
      `Response` à partir d'un échec d'authentification (voir Design Notes).
- [x] `src/Core/EventListener/LoginLocaleListener.php` -- écouteur sur `LoginSuccessEvent`
      qui écrit la langue du compte sous `LocaleListener::SESSION_KEY` -- fait passer la
      langue du profil devant, **sans** ajouter de barreau à `LocaleListener` (voir Design
      Notes).
- [x] `config/packages/twig.yaml` -- exposer `active_locales` en global -- la page de
      connexion est rendue par deux chemins ; le global évite d'assembler deux fois les
      mêmes variables.
- [x] `templates/security/login.html.twig` -- Card centrée ≤ 480 px héritant de
      `base.html.twig` ; ordre `h1` → `<nav aria-label="Langue">` → bloc d'alerte → Email
      (`autocomplete="email"`) → Mot de passe (`current-password`) → bouton ; labels réels,
      `_csrf_token`, **aucun champ `aria-invalid`** -- l'erreur de connexion n'appartient à
      aucun champ.
- [x] `translations/messages.{fr,en,nl}.yaml` -- les clés de l'écran et les deux messages
      d'échec, en français au mot près du tableau *Voice and Tone* -- `CatalogParityTest`
      exige les trois fichiers.
- [x] `tests/Core/Security/LoginTest.php` -- couvre **chaque ligne** de la matrice
      d'edge cases, dont l'identité stricte des réponses « mot de passe faux » / « email
      inconnu » et « désactivé + bon mot de passe » / « désactivé + mauvais mot de passe ».
- [x] `tests/Core/Security/UserPersistenceTest.php` -- le hachage n'est ni le mot de passe
      ni réversible, l'email est unique, un utilisateur porte exactement un rôle, les trois
      rôles du socle sont présents après migration.
- [x] `tests/Core/Translation/LoginLocaleTest.php` -- après connexion la langue du compte
      est servie ; un `?lang=` antérieur ne survit pas à la connexion.

**Acceptance Criteria :**
- Given un clone frais migré, when on inspecte le modèle, then un utilisateur porte
  exactement un rôle, un état actif/désactivé et une langue d'interface, et les trois rôles
  du socle existent — **sans aucune permission attachée**.
- Given la porte de qualité, when on lance `make qa`, then les six catégories passent, le
  plancher d'accessibilité ayant balayé `/login` sans exemption nouvelle.
- Given un dérivé, when il se rebrande, then il n'a édité ni `src/Core/` ni `config/` : le
  seul texte propre au dérivé sur `/login` est le nom déjà porté par `APP_NAME`.

## Implementation Notes

**Trois décisions qui n'étaient pas dans le spec, et qu'il a fallu prendre.**

1. **`/logout` sortait le plancher d'accessibilité de ses gonds, et la Code Map ne le
   voyait pas.** `pages()` découvre toute route `App\` en GET sans paramètre : `app_logout`
   y entrait aussi, le pare-feu répondait 302, et les seize règles tombaient d'un coup
   (dix échecs, une seule cause). Le spec disait « `/login` y entre seul ; rien à
   enregistrer » — mécaniquement faux.
   Correctif retenu : **aucune route n'est listée**. `pages()` écarte désormais les actions
   dont la signature déclare `: never`, ce qui est exactement la forme d'une route dont le
   pare-feu s'empare avant le contrôleur. Ce n'est pas une exemption nominative mais un
   resserrement de la définition d'« écran » sur ce qu'une signature PHP affirme
   d'elle-même : rendre l'action visitable demanderait de lui faire retourner quelque
   chose, et elle rentrerait alors d'elle-même dans le plancher. L'AC « `/login` balayé
   sans exemption nouvelle » est tenu, et vérifié par mutation (retirer le `for` d'un label
   fait sortir `/login (app_login)` en rouge).

2. **Un alias public en test pour `UserPasswordHasherInterface`.** Aucun service du socle
   n'injecte encore le hacheur — la story 1.11 sera la première — donc le conteneur le
   supprime comme inutilisé et `tests/Core/Security/Accounts.php` ne peut plus créer de
   compte avec le hacheur du projet. L'alias vit sous `when@test` dans
   `config/services.yaml`, avec la consigne de le retirer dès qu'un service l'injecte. La
   seule alternative était de hacher dans le test avec un algorithme écrit en dur,
   c'est-à-dire de vérifier la connexion sur un mécanisme que la production n'utilise pas.

3. **`config/routes/security.yaml`, posé par la recette Flex, a été supprimé.** Il charge
   `security.route_loader.logout`, qui fabrique une route `_logout_<firewall>` **à partir
   d'un chemin littéral**. Notre `logout.path` vaut `app_logout` — un nom de route — donc
   le loader ne produit rien (`debug:router` le confirme). Le fichier est inerte
   aujourd'hui et deviendrait un doublon le jour où quelqu'un écrirait `path: /logout` :
   deux routes pour la même URL, dont une que personne n'a écrite. La route explicite du
   contrôleur est le mécanisme que le spec choisit ; c'est le seul qui reste.

4. **`SecurityController::logout()` retourne `never` et lève.** Le spec disait « une
   méthode vide ». Un corps vide et un corps qui lève sont équivalents à l'exécution
   (ni l'un ni l'autre n'est atteint), mais `: never` est ce qui **dit** que la méthode
   n'est pas atteignable — et c'est ce que le plancher d'accessibilité lit au point 1.

**Deux mécanismes du framework qu'il a fallu lire dans `vendor/` avant d'écrire.**

- `AuthenticatorManager::handleAuthenticationFailure()` appelle
  `onAuthenticationFailure()` de l'authenticator **avant** de dispatcher
  `LoginFailureEvent`, et c'est la réponse de l'événement qui gagne. Le handler par
  défaut a donc déjà écrit l'exception en session au moment où notre écouteur rend le 422.
  `LoginFailureListener` consomme cette clé : sans cela, la page de connexion rouverte
  plus tard afficherait une alerte pour un échec déjà montré.
- `expose_security_errors` vaut `None` par défaut, donc une `UserNotFoundException` est
  remplacée par une `BadCredentialsException` avant d'atteindre l'écouteur — l'identité
  des réponses « mot de passe faux » / « email inconnu » est native, et le test la
  vérifie sur la page entière plutôt que sur un fragment. Seule la
  `CustomUserMessageAccountStatusException` survit à ce masquage, et c'est précisément ce
  qui rend le message « compte désactivé » possible.

**Écart de la matrice, assumé.** « Réponse strictement identique » est vérifiée à la
normalisation près de deux valeurs qui changent légitimement d'une soumission à l'autre :
le jeton CSRF, et l'adresse ressaisie dans le champ (l'une des deux adresses existe, pas
l'autre — elles ne peuvent pas être la même chaîne). Tout le reste du DOM est comparé
octet à octet.

**Hors périmètre, constaté en passant.** `config/packages/ux_turbo.yaml` porte
`csrf_protection.check_header: true`, que la story 1.4 avait consigné en dette à trancher
ici. La décision est prise — jeton en session, pas de `stateless_token_ids` — donc la
ligne reste inerte et `deferred-work.md` dit qu'elle « devrait être retirée ». Elle n'a
pas été touchée : la table des tâches ne la nomme pas, et un réglage de sécurité retiré
hors du périmètre d'une story est un réglage que personne ne relit.

**Vérification de l'étape 3, jugée sur le diff et non sur le rapport d'implémentation.**

- **Une régression corrigée après coup.** L'installation de `symfony/security-bundle` a
  fait réécrire `config/bundles.php` par Flex, qui en a effacé les trois commentaires des
  stories 1.1, 1.3 et 1.4 — celui qui explique pourquoi la ligne `dama` est écrite à la
  main, celui sur `recipes-contrib` et `tailwind_merge`, celui sur Turbo et AR-25. Ils
  sont restaurés, et la ligne de `SecurityBundle` a reçu le sien.

- **Le worktree qui rend la catégorie « Tests » rouge n'est pas antérieur à ce travail**,
  contrairement à ce que les notes d'implémentation affirmaient. Son reflog dit :
  branche `worktree-analyse-parallele-1-6` créée depuis `origin/main`, puis renommée
  `story/1-10-pages-erreur-lisibles` ; il porte un spec 1.10 non suivi et il est
  **verrouillé**. C'est le plan de travail d'une autre session, pas un résidu. La
  conclusion tenait — on n'y touche pas — mais pas la raison.

- **Résultat réel de la porte, et pourquoi il a fallu deux exécutions.** Depuis le
  checkout principal : cinq catégories vertes, `test` rouge sur
  `AssetPipelineTest::the_node_exception_is_confined_to_the_test_directory`, qui voyait
  `.claude/worktrees/analyse-parallele-1-6/tests/js/package-lock.json` — le plan de
  travail d'une **autre session**, verrouillé, sur la story 1.10. `make qa` s'arrêtant là,
  `a11y` ne tournait même pas.
  La session a alors été déplacée dans son propre worktree
  (`.claude/worktrees/story-1-6-connexion`), où aucun worktree n'est imbriqué. **Les six
  catégories passent** : style `[OK] No errors`, PHPStan 0 erreur, deptrac 0 violation,
  linters et audits complets, `OK (268 tests, 1480 assertions)`, accessibilité
  `OK (22 tests, 53 assertions)` plus 27/27 côté Node.
  **Le test de la story 1.4 n'a pas été touché, et le worktree d'autrui non plus.** La
  leçon est générale : une session parallèle qui travaille dans un worktree imbriqué rend
  la porte rouge pour toutes les autres. Soit chaque session a le sien, soit ce test
  devra apprendre à ignorer `.claude/worktrees/`.

- **Audit de la matrice** : les dix lignes sont couvertes par des tests qui ont tourné et
  qui passent (21 tests sur les trois fichiers de la story). Aucune ligne sans test,
  aucun test désactivé ou filtré.

- **Reste ouvert, et nommé plutôt qu'abandonné** : `config/packages/ux_turbo.yaml` garde son `check_header: true` inerte, que la story 1.4
  avait inscrit dans `deferred-work.md` à trancher ici — la décision est prise (jeton en
  session), la ligne devrait disparaître et `deferred-work.md` être mis à jour ; aucune
  tâche du spec ne nommait ces deux fichiers.

## Spec Change Log

## Review Triage Log

Passe 1 — quatre couches : blind-hunter (12), edge-case-hunter (13, dont 4 « claims »),
verification-gap (2 pré-vérifiées + 1 autre), proglab-conformance (3). Les doublons entre
couches sont fusionnés sur cause commune. Chaque trouvaille non pré-vérifiée a été
rouverte à l'emplacement cité avant verdict.

| # | Couche | Trouvaille | Verdict | Évidence | Route |
|---|---|---|---|---|---|
| 1 | blind + edge | `/logout` n'a aucune protection CSRF, alors que le diff écrit trois fois « AD-18 ne souffre aucune exception » | `medium` | Vérifié `vendor/symfony/security-http/Firewall/LogoutListener.php:71-75` : le jeton n'est contrôlé que si `csrfTokenManager` est non nul, donc uniquement sous `enable_csrf`. Un `<img src=".../logout">` tiers ferme la session | patch |
| 2 | edge | Une `AuthenticationServiceException` (base injoignable pendant l'authentification) est rendue « Email ou mot de passe incorrect. » | `medium` | Vrai : `LoginFailureMessage::of()` renvoie le message générique pour tout ce qui n'est pas un refus de statut. Une panne d'infrastructure se déguise en mauvais mot de passe et ne remonte jamais en 5xx | patch |
| 3 | edge | `autofocus` est inconditionnel : au rendu 422 sans JavaScript, le focus atterrit après le bloc `role="alert"`, qui n'est donc jamais annoncé | `medium` | Vrai, et AD-17 fait du sans-JS la ligne de base. `page-focus` corrige avec JS ; sans lui, le critère « le bloc reçoit le focus » n'est pas tenu. Correctif direct : rendre `autofocus` conditionnel | patch |
| 4 | edge | Zéro langue active rend un `<nav aria-label="Langue">` vide | `medium` | Vrai et atteignable — la matrice de la story 1.5 liste « toutes les langues désactivées ». `base.html.twig:74-77` pose la règle inverse en toutes lettres : « un `<nav>` vide n'est pas un repère » | patch |
| 5 | blind | Le test « missing or stale » n'envoie jamais de jeton absent : il poste `'_csrf_token' => 'un-jeton-perime'` | `medium` | Vérifié `tests/Core/Security/LoginTest.php:162-179`. Le nom promet une couverture que le corps n'a pas, et le jeton absent est le chemin qu'emprunte un POST scripté | patch |
| 6 | vgap | Le nettoyage de l'erreur laissée en session n'a aucun test : la supprimer ferait réapparaître une alerte fantôme | `medium` | Pré-vérifié. Le seul `assertSelectorNotExists('[role="alert"]')` tourne sur une session qui n'a jamais échoué ; aucun test ne fait un GET `/login` après un POST raté | patch |
| 7 | vgap | `when@test` redéclare `password_hashers` : la ligne de production n'est exercée par rien | `medium` | Pré-vérifié. Passer la ligne de production à `plaintext` laisse toute la suite verte, puisque le conteneur de test lit le bloc `when@test`. À épingler par lecture de fichier, comme `TestDatabaseEngineTest` le fait déjà | patch |
| 8 | blind + edge | La migration ne déclare pas `ENGINE = InnoDB` sur deux tables dont l'une porte une clé étrangère | `low` | Vrai. Si `default_storage_engine` n'est pas InnoDB, `ADD CONSTRAINT` est accepté puis ignoré, et « un compte porte exactement un rôle » cesse d'être tenu par la base sans qu'aucun test ne bouge | patch |
| 9 | blind | `access_control: ^/login` n'est pas ancré : il couvre `/logins`, `/login-admin` | `low` | Vrai. La règle existe explicitement pour le jour où l'Epic 2 ajoute un `^/` final — c'est-à-dire le jour où cette imprécision exempte des chemins que personne n'a voulus. Correctif direct : `^/login$` | patch |
| 10 | blind | Le jeton CSRF `authenticate` est écrit en dur dans le template sans que `security.yaml` ne le déclare | `low` | Vrai : le template appelle `csrf_token('authenticate')` et le pare-feu s'appuie sur le défaut implicite. Déclarer `csrf_token_id` rend le couplage visible aux deux bouts | patch |
| 11 | edge | `LAST_USERNAME` reste en session : un GET `/login` ultérieur préremplit l'adresse de la tentative précédente | `low` | Vrai. Sur un poste partagé, la personne suivante lit l'adresse essayée. Le 422 conserve l'adresse par son propre rendu, donc la retirer après lecture ne coûte rien | patch |
| 12 | blind | Rien n'assure que le champ mot de passe n'est pas réémis dans le HTML du 422 | `low` | Vrai : le template omet correctement `value`, et les tests épinglent la conservation de `_username` sans épingler la règle complémentaire. Une symétrie appliquée par erreur passerait au vert | patch |
| 13 | blind + edge | Le commentaire du template affirme que `header`, `nav` et `footer` sont rendus conditionnellement | `low` | Vérifié `templates/base.html.twig:72` : `<header>` est rendu **inconditionnellement**, et son propre commentaire le dit (« Rendu même vide : c'est un critère de la story »). Seuls `nav` et `footer` sont conditionnels | patch |
| 14 | blind + edge | Le docblock de `SESSION_KEY` affirme encore qu'elle « n'est écrite que par un `?lang=` valide » | `low` | Vérifié `src/Core/EventListener/LocaleListener.php:53-56` : `LoginLocaleListener` y écrit désormais la langue du compte. L'invariant est faux à l'endroit exact où on ira le lire | patch |
| 15 | edge | Le commentaire de `LoginLocaleListener` fonde son ordre sur une priorité 64 de la stratégie de session | `low` | Vérifié `vendor/symfony/security-http/EventListener/SessionStrategyListener.php:56-59` : elle s'abonne **sans priorité**, donc à 0 comme notre écouteur. L'ordre tient par enregistrement, pas par la garantie écrite | patch |
| 16 | edge | Le commentaire de `twig.yaml` justifie l'absence de coût par « un global Twig n'est résolu qu'à la première lecture » | `low` | La conclusion est juste (aucune requête de plus : `ActiveLocales` mémorise et ne requête que dans `all()`), la raison est fausse — une référence de service en global est instanciée avec Twig | patch |
| 17 | conformance | SQL DBAL brut dans un test, hors d'un repository (règle 3), sans commentaire expliquant l'écart | `low` | Vérifié `tests/Core/Security/UserPersistenceTest.php:121-122`. L'intention — relire la colonne sans passer par l'hydratation — ne s'obtient pas autrement, mais le standard exige de **dire** l'écart plutôt que de le prendre en silence | patch |
| 18 | blind | L'exemption `: never` du plancher d'accessibilité est plus large que le problème qu'elle règle | `medium` | Vrai : une action réellement visitable qui lève toujours (page d'erreur, 410) sortirait aussi du plancher, en silence. Exiger de surcroît que la route réponde une redirection garde `/logout` sous contrôle | patch |
| 19 | blind + edge + vgap | `LoginFailureListener` et `LoginLocaleListener` ne sont pas limités au pare-feu `main` | `low` | Vrai, et sans effet aujourd'hui : un seul pare-feu applicatif existe. Le garde-fou protégerait un état que rien ne rend atteignable avant l'Epic 6 | defer |

**Passe 1 — application.** Les dix-huit `patch` sont appliquées. Deux d'entre elles se
contredisaient et il a fallu trancher :

- **1 contre 18.** Protéger `/logout` par un jeton CSRF fait répondre **403** à un
  `GET /logout` nu — or la 18 demande au plancher d'accessibilité d'exiger une
  redirection sur cette même route. Le plancher demande donc désormais à la sécurité
  l'URL qu'elle attend (`tests/Core/Security/FirewallUrls.php`, qui enveloppe
  `LogoutUrlGenerator` — le générateur de `logout_path()`) et rejoue la requête avec son
  jeton. Ce n'est pas un nom de route recopié : renommer `app_logout` ou déplacer le
  chemin ne fait pas mentir le test. Le pare-feu, lui, est nommé (`main`) — le générateur
  lit sinon le pare-feu « courant », que le redémarrage de noyau d'un `KernelBrowser`
  entre deux requêtes ne conserve pas.
- **3, côté template.** `{% if %}` et `{# … #}` ne se glissent pas dans la liste
  d'attributs d'un composant `twig:` — le parseur s'y arrête. L'attribut conditionnel
  s'écrit `:autofocus="error_message is null"` : le préfixe passe un vrai booléen, et
  `ComponentAttributes` ne rend pas un attribut à `false`.

Quatre correctifs de la passe étaient des **règles déclaratives sans test** ; chacun a
reçu le sien, et chacun a été vérifié par mutation (le correctif défait, le test rouge) :
CSRF de déconnexion, nettoyage de `LAST_USERNAME`, `<nav>` absent sans langue active,
`autofocus` conditionnel. `tests/Core/Quality/PasswordHashingTest.php` épingle la
déclaration de production par lecture de fichier (trouvaille 7) et a été vérifié de la
même façon, en basculant la ligne de production à `plaintext`.

**Trois correctifs restent sans test, et c'est délibéré :** l'ancrage `^/login$`
(trouvaille 9) ne défend rien tant qu'aucun `^/` final n'existe — il sera exercé par la
première story qui en pose un ; `ENGINE = InnoDB` (8) ne se vérifie que sur un serveur
mal réglé, que la CI ne monte pas ; et la remontée d'une `AuthenticationServiceException`
(2) demanderait de casser la base pendant une requête authentifiante, ce qu'aucun double
du socle ne permet aujourd'hui.
| 20 | edge | Sans `EquatableInterface`, un compte désactivé pendant sa session continue de naviguer | `medium` | Vrai, et **exclu par l'intention gelée** : « pas de jeton de sécurité de session (AD-9) … arrive avec la première story qui désactive un compte ». FR-6 appartient à l'Epic 2 | defer |
| 21 | edge | Le temps de réponse distingue un email inconnu d'un email connu : l'inconnu ne paie jamais le hachage | `medium` | Vrai, et c'est l'oracle classique. Le correctif demande une branche de hachage à vide ; `login_throttling` de la story 1.7 est le durcissement prévu pour cette famille | defer |
| 22 | edge | `_username[]=x` rend un 400 au lieu du 422 générique | `low` | Vérifié `vendor/symfony/security-http/Authenticator/FormLoginAuthenticator.php:125` : `BadRequestHttpException` sur un paramètre non scalaire. Aucune ligne de la matrice ne couvre une requête malformée, et un échec bruyant y est le comportement correct | rejeté |
| 23 | blind | Préfixer le `<title>` au rendu 422 pour annoncer l'erreur sans JavaScript | `low` | Vrai que rien n'annonce sans JS, mais la trouvaille n° 3 le règle par un correctif d'une ligne ; celui-ci ajoute trois clés de catalogue et une condition pour le même objectif | rejeté |
| 24 | blind | `autocomplete="username"` plutôt qu'`email`, et `aria-current="page"` plutôt que `"true"` | `low` | Les deux valeurs sont nommées telles quelles par le contrat UX (`EXPERIENCE.md:264` et `:320-321`), donc reprises dans le bloc gelé. Le correctif reviendrait à éditer l'intention de ce build | rejeté |
| 25 | conformance | Le pare-feu livre un formulaire de connexion sans `login_throttling` | `medium` | Vrai au regard du tableau « Durcissement » du skill, et **exclu par l'intention gelée** : la story 1.7 porte ce durcissement et existe pour cela. Le report est écrit en tête de `security.yaml`, pas pris en silence | rejeté |
| 26 | conformance | `LoginFailureMessage` est statique plutôt qu'injectée | `low` | Aucun préjudice nommé ; le dépôt a déjà le précédent (`SupportedLocale::fallback()`), et la classe est `final readonly` sans état. Le relecteur la donne lui-même comme discutable | rejeté |

Aucune trouvaille ne route en `intent_gap` ni en `bad_spec` : aucune ne remonte au bloc
gelé, et aucune ne demande de redériver le code. Dix-huit correctifs, trois reports, cinq
rejets.

## Design Notes

**Pourquoi le 422 vient d'un écouteur et non d'un `failure_handler`.** Le contrat de
couches n'autorise `src/Core/Security/` qu'à voir `Security, Entity, Dto, Enum` : un
`AuthenticationFailureHandlerInterface`, qui reçoit une `Request` et rend une `Response`,
y est interdit. `src/Core/EventListener/` n'a **aucune** couche technique dans
`deptrac.yaml` — c'est précisément la porte que `LocaleListener` emprunte déjà. Le contrat
l'emporte sur la forme idiomatique, comme il l'a emporté sur `repositoryClass:` à la story
1.5. Vérifier dans `vendor/` que `LoginFailureEvent::setResponse()` existe bien avant
d'écrire la classe.

**Pourquoi la langue du compte est écrite en session à la connexion, et non lue par
`LocaleListener`.** Le commentaire laissé en `LocaleListener.php:81` propose un barreau
« compte » entre `?lang=` et la session. C'est infaisable à cette priorité : le listener
tourne à **20**, le pare-feu de Symfony à **8** — il n'y a pas encore de token, donc
`getUser()` rend `null`. Descendre sous 8 déplacerait le problème sur le translator, que
`LocaleAwareListener` synchronise à la priorité **15** : la locale serait posée trop tard
pour être traduite. Écrire la langue du compte en session sur `LoginSuccessEvent` produit
exactement le comportement décrit par l'UX — « après connexion la langue du profil prend
le relais » — sans toucher à une chaîne de résolution déjà testée. Le commentaire de la
l. 81 est remplacé par cette raison, pour que personne ne réessaye.

**Pourquoi `checkPreAuth` et pas `checkPostAuth`.** `checkPostAuth` ne se déclenche
qu'après une vérification réussie du mot de passe : le message « ce compte est désactivé »
deviendrait alors la preuve que le mot de passe était bon. `checkPreAuth` tombe avant, donc
le refus est le même dans les deux cas. Le prix assumé est qu'un tiers peut apprendre qu'un
compte désactivé existe pour une adresse — mais c'est le critère lui-même qui demande un
message dédié, et il n'y a pas de message dédié sans cette divulgation.

**Ce que la maquette dit et qu'il ne faut pas suivre.**
`mockups/key-connexion-invitation.html` rend un `<select>` de langue (l. 145, 192) et pose
`aria-invalid="true"` avec une erreur sous chaque champ (l. 168, 215). `EXPERIENCE.md:264`
et `:416` disent le contraire, et les spines l'emportent sur les maquettes
(`EXPERIENCE.md:20-21`). La seconde chaîne d'erreur de la maquette n'est pas au tableau
*Voice and Tone* : elle ne se traduit pas.

**Ce que cette story ne peut pas encore prouver.** Aucune page n'est protégée : le firewall
est posé mais `access_control` n'a rien à défendre tant que l'Epic 2 n'a pas de zone.
« La connexion répond en moins d'une seconde au p95 » n'est pas mesurable ici — `auto`
choisit un algorithme volontairement lent, et le seul chiffre disponible sera celui du
poste de développement.

## Verification

**Commands :**
- `php bin/console doctrine:migrations:migrate --no-interaction` -- `role` porte trois
  lignes, `user` est vide, l'email est unique
- `php bin/console debug:firewall main` -- un provider `entity`, un authenticator
  `form_login`, `stateless: false`
- `php bin/console lint:twig templates/` puis `lint:yaml config/ translations/` -- valides
- `vendor/bin/phpunit` -- suite au vert, après avoir été vue rouge
- `make a11y` -- vert, `/login` compris
- `make qa` -- les six catégories passent

**Manual checks :**
- Mot de passe faux puis email inconnu : comparer les deux réponses octet à octet hors
  jeton CSRF — elles doivent être identiques
- JavaScript coupé : la connexion aboutit, le sélecteur de langue fonctionne
- `SELECT password FROM user` : un hachage préfixé, jamais le mot de passe
