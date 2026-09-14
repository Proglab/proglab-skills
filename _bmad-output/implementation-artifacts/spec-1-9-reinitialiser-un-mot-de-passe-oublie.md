---
title: 'Réinitialiser un mot de passe oublié'
type: 'feature'
created: '2026-09-14'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '13a09ca69cbbb83949eab2d62533c928ffdb8165'
context:
  - '{project-root}/.claude/skills/symfony-proglab-security/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-http/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-accessibility/SKILL.md'
  - '{project-root}/.claude/skills/symfony-proglab-testing/SKILL.md'
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-8-sortir-l-envoi-des-emails-de-la-requete.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem :** le socle sait connecter, pas rendre un accès perdu. Trois pièces manquent
ensemble : l'entité à jeton d'AD-15 — que l'invitation de l'Epic 2 reprendra telle
quelle —, le jeton de sécurité de compte d'AD-9 que le docblock de `User` renvoie « à la
première story qui change un mot de passe », et le premier formulaire du socle dont le
POST atteigne réellement un contrôleur : celui de `/login` est intercepté par le
pare-feu, donc rien n'a encore posé la forme que les Epics 2 à 6 copieront.

**Approach :** deux pages publiques — `/password/forgot` qui répond la même chose à toute
adresse, `/password/reset/{token}` qui consomme un lien à usage unique valable une heure.
Une entité à jeton porte destinataire, rôle prévu, empreinte, expiration et date d'usage ;
l'URL seule porte le jeton en clair. L'email part par `SendEmail` sur le transport posé en
1.8. Un limiteur nommé, distinct de celui de la connexion, borne la demande. Le changement
de mot de passe incrémente le jeton de sécurité du compte, et `EquatableInterface` ferme
les autres sessions.

## Boundaries & Constraints

**Always :**
- **Réponse indiscernable** sur `/password/forgot` : même page, même statut, même message,
  que l'adresse soit inconnue, connue, ou connue mais désactivée. Aucune validation qui
  dirait « cet email n'existe pas », aucun compteur visible, aucune redirection différente.
- **Le jeton en clair ne vit que dans l'URL et dans l'email.** La base ne garde que son
  empreinte SHA-256 ; il n'apparaît dans aucun log, aucun flash, aucun message d'erreur.
  Le tirage est `random_bytes(32)`, la comparaison se fait par recherche sur l'empreinte.
- **Une nouvelle demande invalide les jetons non consommés du même compte** (AD-15) : un
  seul lien vivant à la fois.
- **Un seul chemin d'email** : `SendEmail` dispatché **à l'intérieur** de
  `wrapInTransaction()`, comme la décision D-1 de la story 1.8 l'impose — le message ne
  devient consommable qu'au commit qui persiste le jeton.
- **CSRF sur les deux POST**, jeton nommé, posé en Twig par `csrf_token()` et vérifié par
  `#[IsCsrfTokenValid]`.
- **Le changement de mot de passe incrémente `securityToken` sur le compte** et
  `User::isEqualTo()` le compare — unique méthode de comportement admise sur une entité
  (AD-9, écart énoncé). Les autres sessions du compte sont closes.
- **Trois langues, source française**, textes repris **mot pour mot** du tableau de ton
  d'`EXPERIENCE.md`. L'email est rendu dans la langue du compte, portée par le message.
- **Les deux pages entrent dans le plancher d'accessibilité automatisé**, y compris celle
  dont la route porte un paramètre — le balayage actuel l'écarterait en silence.
- La porte de qualité reste à **six catégories** : aucune testsuite, aucun job de CI,
  aucune cible de `make qa` en plus.

**Never :**
- Pas d'URL signée sans état, pas de `symfonycasts/reset-password-bundle` : AD-15 exige une
  entité, et le bundle ne porte ni rôle prévu ni les états que l'administrateur verra.
- Ne pas livrer l'invitation (Epic 2), l'audit (Epic 4, raccordé rétroactivement), la 2FA,
  ni le changement de mot de passe depuis le profil (Epic 5). L'entité **prévoit** le rôle
  et l'objet du jeton ; cette story n'en livre qu'un usage.
- Ne pas purger les jetons expirés par une tâche planifiée : le Scheduler n'est pas livré
  (Epic 6) et la rétention appartient à l'archivage de l'Epic 4.
- Ne toucher ni au pare-feu, ni à `login_throttling`, ni aux deux limiteurs de la story
  1.7 : le limiteur de cette story est nommé à part et consommé explicitement.
- Ne pas connecter l'utilisateur automatiquement après la réinitialisation : le flux se
  termine sur `/login` avec son Flash.
- Ne pas rendre le jeton de sécurité visible ou modifiable depuis une interface.

## I/O & Edge-Case Matrix

| Scénario | Entrée / État | Sortie / Comportement attendu | Gestion d'erreur |
|---|---|---|---|
| Demande, compte actif | POST `/password/forgot`, email connu | 200 sur la même page, message de confirmation ; un jeton persisté, un `SendEmail` en file après le commit | N/A |
| Demande, adresse inconnue | POST, email absent de la base | **Réponse identique** au cas précédent, hors jeton CSRF ; aucun jeton, aucun email | N/A |
| Demande, compte désactivé | POST, email d'un compte `enabled = false` | **Réponse identique** ; aucun jeton, aucun email — un compte désactivé ne reprend pas son accès seul | N/A |
| Demande, champ vide ou malformé | POST sans email, ou `n'importe quoi` | 422, page rerendue avec l'erreur de **format** sur le champ ; jamais un message sur l'existence | erreur de forme seulement |
| Demandes répétées | plusieurs POST à la suite | le limiteur nommé borne la fréquence ; au-delà, 429 avec `Retry-After`, secondes lues sur le limiteur et jamais déduites | comme la story 1.7 |
| Lien ouvert dans l'heure | GET `/password/reset/{token}` valide | 200, formulaire à deux champs `new-password` | N/A |
| Réinitialisation nominale | POST, deux saisies identiques et conformes | mot de passe rehaché, jeton marqué consommé, `securityToken` incrémenté, 303 vers `/login` + Flash « Mot de passe changé. Connectez-vous avec le nouveau. » | N/A |
| Saisies différentes | POST, confirmation ≠ mot de passe | 422, page rerendue, erreur sur le champ de confirmation, le lien reste utilisable | le jeton n'est **pas** consommé |
| Lien expiré, déjà utilisé, inconnu ou malformé | GET ou POST | **une seule et même page** : « Ce lien a expiré. Demandez-en un nouveau. » + bouton vers `/password/forgot`, **sans aucun formulaire** | statut 410 ; jamais un 404 qui distinguerait « inconnu » d'« expiré » |
| Compte désactivé après émission | lien valide, compte passé `enabled = false` | même page « lien expiré » | N/A |
| Autres sessions ouvertes | le compte est connecté ailleurs | la requête suivante de ces sessions est déauthentifiée par la comparaison d'utilisateur | N/A |
| Rejeu du POST de réinitialisation | même jeton soumis deux fois | la seconde fois : page « lien expiré », le mot de passe n'est pas rechangé | N/A |

## Décisions de Fabrice (2026-09-14)

**D-1 — Le socle valide avec `symfony/validator` seul ; le balisage reste écrit à la main.**
`symfony/form` n'entre pas. Le contrôleur hydrate un DTO d'entrée `final readonly` depuis
la requête, appelle `ValidatorInterface`, et rerend la même page en 422 avec `aria-invalid`
et `aria-describedby` posés dans le template. C'est le précédent que les formulaires des
Epics 2 à 6 copieront. **Conséquences à tenir :** chaque formulaire du socle réécrit sa
boucle « hydrater, valider, rerendre » — elle doit donc être courte et lisible ici, puisque
c'est elle qu'on copiera ; le jeton CSRF est posé à la main comme sur `/login` ; et
`EXPERIENCE.md:323` (« jeton `autocomplete` posé dans le FormType ») devient faux sur le
mécanisme, ce qui part dans `deferred-work.md` — l'amendement du contrat UX n'appartient pas
à cette story. L'exigence, elle, ne bouge pas : chaque champ porte son `autocomplete`.

**D-2 — La robustesse se vérifie hors ligne, par `#[Assert\PasswordStrength]`.** Seuil
`STRENGTH_MEDIUM` : `STRENGTH_STRONG` refuse des phrases de passe que la plupart des gens
jugent raisonnables, et un refus incompréhensible sur une page de récupération d'accès
renvoie l'utilisateur vers son administrateur — exactement ce que la story existe pour
éviter. `#[Assert\NotCompromisedPassword]` est **écarté** : il ferait dépendre la page d'un
service tiers et ajouterait `symfony/http-client` au socle. L'écart au durcissement du skill
sécurité est nommé dans `deferred-work.md` avec son déclencheur — le jour où le socle aura
déjà un client HTTP pour autre chose, la contrainte coûte une ligne.

</frozen-after-approval>

## Code Map

**Ce que la story ajoute — rien de tout cela n'existe :**

- `src/Core/Entity/` — ne contient que `Language.php`, `Role.php`, `User.php`. Aucune
  entité à jeton, aucune entité n'utilise encore `\DateTimeImmutable` (ce sera la
  première). Forme à suivre : `User.php:37-71` — `#[ORM\Entity]` **sans**
  `repositoryClass` (raison : `Language.php:26-37`), propriétés privées, setters
  retournant `static`, enum natif par `#[ORM\Column(enumType: …)]` (`User.php:70`).
- `src/Core/Entity/User.php:32-35` — le docblock renvoie explicitement le jeton de sécurité
  (AD-9) « à la première story qui désactive un compte ou change un mot de passe ». C'est
  celle-ci. `:110-113` `getRoles()` en dur ; `:172-175` `eraseCredentials()` vide et
  déprécié. **Aucune** implémentation d'`EquatableInterface` dans `src/`.
- `src/Core/Repository/UserRepository.php:24-30` — **vide**, aucune méthode : le provider
  charge par propriété. Le repository est le seul endroit où une requête peut vivre
  (`deptrac.yaml:265-273`).
- `src/Core/Dto/Input/` — un `.gitkeep`. Aucun DTO, aucune contrainte nulle part.
- `src/Core/Controller/` — deux contrôleurs, **aucun préfixe de classe** :
  `SecurityController.php:40-66` (`#[Route]` par méthode, `#[Template]`, noms `app_*`) et
  `HomeController.php:25-30`. Routes chargées par attribut sur le répertoire
  (`config/routes.yaml:10-12`). Noms imposés par la spine : `app_password_request` pour
  `/password/forgot`, `app_password_reset` pour `/password/reset/{token}`.
- `src/Core/Controller/SecurityController.php:28-33` — le docblock qui explique pourquoi il
  n'y a **pas** de DTO : le POST est intercepté par le pare-feu. L'argument tombe ici.

**Ce sur quoi elle s'appuie sans le modifier :**

- `src/Core/Message/SendEmail.php:49-56` — `(string $toEmail, string $toName,
  SupportedLocale $locale, string $subjectKey, string $template, array $context = [])`,
  `$context` typé `array<string, scalar|null>`, routage par `#[AsMessage('async')]`. Un
  `toName` vide est accepté (`User` n'a pas de nom). Clés `locale` et `subject` réservées.
- `tests/Fixtures/Mail/DemoEmailDispatch.php:35-39,67-84` — le modèle de dispatch **dans**
  `wrapInTransaction()`, écrit en citant nommément la story 1.9.
- `src/Core/Service/EmailSender.php:134-136,158-160` — gardes sur le gabarit et sur la clé
  de sujet (domaine figé à `emails`) ; clé absente = `UnrecoverableMessageHandlingException`.
- `templates/emails/base.html.twig:19-32,55` — `{% block content %}` unique ; **tout
  `|trans` prend `locale` en 4e argument** ; `url()` et jamais `path()` (pas d'`app.request`
  dans le worker), `default_uri` = `%env(DEFAULT_URI)%` (`config/packages/routing.yaml:5`).
- `translations/emails.fr.yaml:1-15` — ne porte que `layout.footer`, et son en-tête dit que
  le contenu des premiers emails appartient à cette story.
  `tests/Core/Translation/CatalogParityTest.php:61-141` exige les trois langues, toutes les
  clés de `fr` présentes et non vides, aucune en trop.
- `config/packages/rate_limiter.yaml:50-60` — `login_attempt` et `login_address`, et rien
  d'autre : le limiteur de cette story s'ajoute à côté, avec un nom distinct.
- `src/Core/EventListener/LoginFailureListener.php:127-141,158-161` — le précédent exact du
  429 : `Retry-After` posé, secondes lues par `peek()->getRetryAfter()`, **jamais** déduites
  d'un message. Et le précédent du **422 qui rerend le même template**, pas une redirection.
- `templates/security/login.html.twig:29,46-62,75-84,86-127` — `h1` par
  `<twig:Card:Title as="h1">` ; le `<nav>` de langues en liens `?lang=` ; le bloc d'erreur
  `<twig:Alert variant="destructive" tabindex="-1">` ; le formulaire à la main avec
  `<input type="hidden" name="_csrf_token" value="{{ csrf_token('authenticate') }}">`.
  C'est aussi là que le lien « Mot de passe oublié ? » doit apparaître (`EXPERIENCE.md:64`).
- `templates/base.html.twig:87-96,115` — tout `role="alert"` et **tout Flash** se rendent
  dans `{% block body %}`, jamais dans `header` ; `<main tabindex="-1">` ; région
  `#annonces` persistante.
- `assets/controllers/page_focus_controller.js:20-28` — `[data-flash]` est **le contrat**
  que le composant Flash de cette story doit porter (un `role="status"` seul ne suffit pas).
  `announce_controller.js:1-45` — le contrôleur se pose **sur le message**, jamais sur la
  région. Aucun mécanisme de flash n'existe encore dans `src/`, `templates/` ou `config/`.
- `templates/components/` — kit figé à `Alert, Badge, Button, Card, Input, Label`
  (`tests/Core/Theme/KitIntegrityTest.php:35`). `Input.html.twig:3` porte déjà
  `aria-invalid:border-destructive`. Aucun Textarea, aucun composant de formulaire.
- `tests/Core/Accessibility/AccessibilityFloorTest.php:749-811` — le balayage n'accepte que
  les routes **GET sans paramètre** (`:763-767`) : `/password/forgot` y entre seule, et le
  commentaire `:732-735` annonce que les routes à paramètre « entrent avec les stories qui
  les posent ». `:228-251` — tout `aria-invalid="true"` **doit** porter un
  `aria-describedby` qui pointe un élément présent. `:350-377` — label réel obligatoire.
- `tests/Core/Theme/HardcodedColorTest.php:46-58` — aucune couleur littérale dans
  `templates/**`, `src/**`, `assets/controllers/**` ; seuls les jetons du thème.
- `config/packages/security.yaml:106-107` — `access_control` ne porte qu'une règle,
  `^/login$`. Les deux nouveaux chemins doivent y entrer en `PUBLIC_ACCESS`, ancrés aux deux
  bouts. `:25-26` et `:109-119` — `password_hashers: auto`, coût réduit sous test.
- `config/services.yaml:63-65` — le glob `App\Core\` exclut
  `{Contract,Dto,Entity,Enum,Message}` : une entité, un repository et un service nouveaux ne
  demandent **aucune** édition. `:108-120` — l'alias public `UserPasswordHasherInterface`
  porte la consigne « à retirer le jour où un service du socle l'injecte », et
  `tests/Core/Security/Accounts.php:52` en dépend. `:172-175` — `DemoEmailDispatch`, même
  consigne, et `tests/Core/Mail/EmailQueueTest.php` en dépend.
- `migrations/Version20260914081053.php:10-78` — la forme : docblock français disant ce que
  la relecture a corrigé, `getDescription()` en français, heredoc `<<<'SQL'` indenté,
  `ENGINE = InnoDB` explicite, `down()` en ordre inverse. Tables au singulier, colonnes
  `snake_case`, noms d'index générés **conservés**.
- `deptrac.yaml:236-285` — `Service` voit `Service, Repository, Dto, Entity, Message,
  EntityManager, Exception, Enum` : **ni Http, ni Doctrine, ni Security**. `Controller` voit
  `Http, Service, Dto, Entity, Form, Message, Exception, Security, Enum`. `Command/`,
  `EventListener/`, `Twig/` ne sont dans **aucune** couche.
- `Makefile:14-19,39` — six catégories, `make qa` ;
  `tests/Core/Quality/QualityGateParityTest.php:32-39,74-91` interdit d'en ajouter une sans
  job de CI homonyme.

## Tasks & Acceptance

**Execution :**

- [x] `composer.json` -- ajouter `symfony/validator:7.4.*`, et **rien d'autre** (D-1) --
      version épinglée comme les autres, aucun bundle à déclarer. Relire la recette Flex
      produite comme l'ont été `security.yaml` et `mailer.yaml`.
- [x] `src/Core/Enum/AccountTokenPurpose.php` -- enum `PasswordReset` / `Invitation` --
      l'entité est partagée (AD-15) ; sans objet explicite, l'invitation de l'Epic 2 devra
      deviner à quoi sert une ligne, et les deux durées de vie (une heure, sept jours) ne
      seraient distinguables que par l'écart entre deux dates.
- [x] `src/Core/Entity/AccountToken.php` -- entité : `user`, `purpose`, `tokenHash` unique,
      `expiresAt`, `usedAt` nullable, `createdAt`, `intendedRole` nullable -- le champ de
      rôle est livré vide et sert à l'Epic 2 ; AD-15 l'exige dans le modèle.
- [x] `src/Core/Repository/AccountTokenRepository.php` -- `findLiveByHash()` et
      `invalidateLiveFor()` -- nommés d'après l'intention de l'appelant ; seul endroit où
      une requête est permise.
- [x] `src/Core/Entity/User.php` -- ajouter `securityToken` (entier, défaut 1) et
      `isEqualTo()` -- AD-9 ; unique méthode de comportement admise sur une entité, avec le
      commentaire qui l'énonce.
- [x] `migrations/VersionYYYYMMDDHHMMSS.php` -- générer puis **relire** : table
      `account_token`, colonne `security_token` sur `` `user` `` -- docblock disant ce que
      la relecture a corrigé, `down()` en ordre inverse.
- [x] `src/Core/Dto/Input/…` -- les deux DTO d'entrée `final readonly` avec leurs
      contraintes : `NotBlank` + `Email` pour la demande, `NotBlank` +
      `PasswordStrength(STRENGTH_MEDIUM)` + égalité des deux saisies pour la
      réinitialisation (D-2) -- une seule source de vérité pour la validation, et les
      messages du composant sont déjà traduits dans les trois langues.
- [x] `src/Core/Service/PasswordResetRequest.php` -- tirer le jeton, invalider les
      précédents, persister et dispatcher `SendEmail` **dans** `wrapInTransaction()`, et
      **ne rien faire** si le compte est inconnu ou désactivé -- la règle de
      non-divulgation vit ici, pas dans le contrôleur.
- [x] `src/Core/Service/PasswordReset.php` -- consommer un jeton, rehacher, incrémenter le
      jeton de sécurité, marquer `usedAt` -- en une seule transaction ; un jeton n'est
      consommé que si le mot de passe change vraiment.
- [x] `src/Core/Controller/PasswordController.php` -- `#[Route('/password')]` de classe,
      `app_password_request` et `app_password_reset` (GET+POST), `#[IsCsrfTokenValid]`, 429
      et 422 sur le modèle de `LoginFailureListener` -- il traduit, il ne décide pas. La
      boucle « hydrater le DTO, valider, rerendre en 422 » (D-1) est le précédent que les
      Epics 2 à 6 copieront : la garder courte et commentée est un livrable en soi.
- [x] `config/packages/rate_limiter.yaml` + `config/services.yaml` -- un limiteur nommé pour
      la demande, consommé explicitement -- distinct de `login_attempt` et `login_address`,
      secondes restantes lues sur le limiteur.
- [x] `config/packages/security.yaml` -- `PUBLIC_ACCESS` sur les deux chemins, ancrés --
      sans quoi une redirection vers `/login` rendrait le lien inutilisable.
- [x] `templates/password/{request,reset,expired}.html.twig` + le partiel de Flash --
      balisage du kit, `autocomplete="new-password"`, `data-flash` et le contrôleur
      d'annonce posé sur le message -- le Flash est le premier du socle et son contrat est
      déjà écrit dans `page_focus_controller.js`.
- [x] `templates/security/login.html.twig` -- lien « Mot de passe oublié ? » et rendu du
      Flash -- l'entrée du flux et son point d'arrivée.
- [x] `templates/emails/password_reset.html.twig` + `translations/emails.{fr,en,nl}.yaml` +
      `translations/messages.{fr,en,nl}.yaml` -- textes **mot pour mot** d'`EXPERIENCE.md`
      (`:193-194`, `:214`), `|trans` avec `locale`, `url()` absolu.
- [x] `tests/Core/Security/PasswordResetTest.php` -- la matrice ci-dessus, y compris
      l'égalité des deux réponses et le rejeu -- écrit d'abord, vu rouge.
- [x] `tests/Core/Security/PasswordResetThrottlingTest.php` -- le limiteur nommé, sur le
      modèle de `LoginThrottlingTest` -- et la preuve qu'il est **distinct** de celui du
      login.
- [x] `tests/Core/Security/SessionInvalidationTest.php` -- une session ouverte ailleurs est
      close après le changement -- le seul test qui prouve AD-9.
- [x] `tests/Core/Accessibility/AccessibilityFloorTest.php` -- faire entrer les routes à
      paramètre dans le balayage, avec leurs paramètres d'exemple -- sinon
      `/password/reset/{token}` sort du plancher **en silence**, et toutes celles des epics
      suivantes avec elle.
- [x] `config/services.yaml` -- arbitrer les deux alias `when@test` dont le commentaire
      nomme cette story -- décider et écrire la raison, plutôt que laisser un commentaire
      faux.
- [x] `README.md` et `_bmad-output/implementation-artifacts/deferred-work.md` -- quatre
      entrées nommées : `EXPERIENCE.md:323` devenu faux sur le FormType (D-1),
      `NotCompromisedPassword` écarté et son déclencheur (D-2), la purge des jetons
      expirés, et ce qu'AD-15 laisse à l'Epic 2 (`intendedRole`, `Invitation`).

**Acceptance Criteria :**
- Given un compte actif, when le lien reçu est ouvert dans l'heure et deux saisies valides
  sont soumises, then le mot de passe est changé, le jeton est marqué consommé et la page de
  connexion porte le Flash exact du contrat UX.
- Given deux demandes successives pour le même compte, when la première est ouverte après la
  seconde, then elle affiche la page « lien expiré » — un seul lien vit à la fois.
- Given une session ouverte sur un autre navigateur, when le mot de passe est réinitialisé,
  then la requête suivante de cette session n'est plus authentifiée.
- Given le jeton en clair, when on lit la base, les journaux applicatifs et le corps de la
  réponse HTTP, then il n'y apparaît nulle part — seule l'URL de l'email le porte.
- Given `make qa`, when il est lancé en entier, then les six catégories passent, avec les
  deux nouvelles pages dans le plancher d'accessibilité et les trois catalogues à parité.

## Implementation Notes

Implémenté le 2026-09-14. `make qa` passe en entier (**sortie 0**, six catégories) : 381
tests / 2347 assertions dans « Tests », 22 / 94 en « Accessibility », deptrac 0 violation,
PHPStan sans erreur au niveau max, php-cs-fixer 0 sur 88.

Les trois classes de test ont été **vues rouges avant le code** (34 tests, 30 erreurs et 4
échecs : route absente, `password_request` absent du limiteur, `EquatableInterface` absent).
Vérification par mutation ensuite, sur tout ce qui est déclaratif — chacune rougit puis a
été restaurée :

| Mutation | Ce qui rougit |
|---|---|
| `RouteSamples::for()` ne connaît plus `app_password_reset` | le plancher, en nommant la route |
| `as="h1"` → `as="h2"` sur la page de réinitialisation | le plancher — donc cette page y est réellement entrée |
| `usedAt IS NULL` retiré de `findLiveByHash()` | le rejeu |
| `invalidateLiveFor()` retiré de la demande | « un seul lien vivant » |
| `+ 1` → `+ 0` sur `securityToken` | la session ouverte ailleurs — et **rien d'autre**, ce qui prouve que la comparaison native des hachages ne la ferme plus une fois `EquatableInterface` implémentée |
| `STRENGTH_MEDIUM` → `STRENGTH_STRONG` | la phrase de passe acceptée à la borne basse |
| `STRENGTH_MEDIUM` → `STRENGTH_WEAK` | le mot de passe refusé à la borne haute |
| `Retry-After` décalé de 60 s | les secondes lues sur le limiteur |
| `url()` → `path()` dans l'email | le lien absolu |

**Six écarts ou décisions prises en cours de route, chacun assumé :**

1. **`invalidateLiveFor()` supprime les lignes au lieu de poser `usedAt`.** `usedAt` dit
   « le destinataire l'a ouvert » ; l'écrire sur un lien que personne n'a ouvert mentirait à
   l'écran que l'Epic 2 posera sur cette table. La méthode ne touche que les jetons
   **vivants** — les expirés restent, et leur rétention part dans `deferred-work.md`.
2. **Le partiel de Flash n'utilise pas le composant `Alert` du kit.** `Alert.html.twig`
   porte `role="alert"` en dur, et un second `role` posé par les attributs est ignoré par le
   navigateur, qui garde le premier. Or le contrat UX veut `role="status"` sur un Flash, et
   dit explicitement qu'une `Alert` présente au chargement ne doit **jamais** être
   `role="alert"`. Le partiel écrit donc son balisage, avec les jetons du thème et aucune
   couleur littérale. Le kit reste figé à six composants (`KitIntegrityTest`).
3. **Un septième catalogue est né : `translations/validators.{fr,en,nl}.yaml`, avec une
   seule clé.** Les contraintes utilisent les messages du composant, déjà traduits dans les
   trois langues — sauf l'égalité des deux saisies : le message par défaut d'`EqualTo` est
   « Cette valeur doit être identique à {{ compared_value }} », et `{{ compared_value }}`
   **est le mot de passe saisi**, donc il repartirait dans le HTML du 422, dans l'historique
   du navigateur et dans les caches intermédiaires. Le nom de domaine est imposé par le
   validateur, pas choisi.
4. **Les deux alias `when@test` sont arbitrés, et tous deux restent** — avec une raison
   réécrite, pas avec l'ancienne. `UserPasswordHasherInterface` : la consigne de 1.6
   (« à retirer quand un service du socle l'injecte ») est éteinte, `PasswordReset`
   l'injecte ; mais l'alias est **public**, et c'est ce qui permet à `Accounts` et à
   `PasswordResetTest` de l'atteindre par son identifiant d'autowiring. `DemoEmailDispatch` :
   le cas d'usage réel existe désormais, mais y rebrancher `EmailQueueTest` ferait dépendre
   la preuve de l'infrastructure d'un compte actif, d'un jeton CSRF et d'un limiteur non
   épuisé — le critère central de la story 1.8 rougirait alors en accusant la mauvaise chose.
   Les deux commentaires disent maintenant qu'il n'y a plus de date de retrait.
5. **Un POST sans jeton CSRF valide répond 302 vers `/login`**, et non 403.
   `#[IsCsrfTokenValid]` lève une `InvalidCsrfTokenException`, qui est une
   `AuthenticationException` : l'`ExceptionListener` du pare-feu la renvoie au point
   d'entrée. Aucune écriture n'a lieu, ce qui est le seul point d'AD-18 ; le comportement est
   épinglé par deux tests plutôt que découvert plus tard. L'attribut porte `methods: 'POST'`,
   sans quoi il vérifierait aussi le GET qui rend le formulaire.
6. **`symfony/clock` n'a pas été ajouté.** Les deux services lisent `new \DateTimeImmutable()`
   plutôt qu'une horloge injectée : le paquet est en `require-dev`, et l'injecter dans `src/`
   demanderait de le passer en `require` — ce que le spec interdit (« `symfony/validator`, et
   **rien d'autre** »). L'expiration se teste en reculant la date **en base**
   (`PasswordTokens::expire()`), ce qui est exactement ce que le temps ferait.

**Deux ajouts que le spec n'avait pas listés, et qui ferment des trous :**

- `tests/Core/Accessibility/RouteSamples.php` — les paramètres d'exemple vivent dans leur
  propre classe, et une route à paramètre **inconnue d'elle fait échouer** le plancher en se
  nommant. C'est l'inverse du saut silencieux d'avant, et c'est ce qui fait que les stories
  suivantes ne pourront pas sortir du plancher sans le voir.
- `PasswordResetTest::the_email_is_rendered_in_the_account_language_and_carries_a_working_link()`
  — la vérification manuelle « l'email arrive dans la langue du compte, le lien fonctionne »
  est mécanisée : le message réellement mis en file est rendu par `EmailSender`, donc hors
  requête, et le lien absolu qu'il porte est ensuite ouvert.

**Ce qui n'a pas été fait :** le cycle complet avec Mailpit et un worker (aucun SMTP local
disponible dans cet environnement). Le test ci-dessus rend le même email par le même
service, ce qui couvre la langue, le sujet, le pied de page et l'URL absolue — il ne couvre
pas la remise SMTP elle-même, déjà exercée par la story 1.8. Les deux pages ont en revanche
été ouvertes à 320 px sans défilement horizontal (`scrollWidth === clientWidth`) et
parcourues au clavier seul : lien d'évitement → champ → bouton → lien de retour.

## Spec Change Log

## Review Triage Log

Passe 1 — quatre couches : blind-hunter (13), edge-case-hunter (7 + 2 « claims »),
verification-gap (6 pré-vérifiées + 2 autres), proglab-conformance (2). Chaque trouvaille
non pré-vérifiée a été rouverte à l'emplacement cité — code, `vendor/`, tests — avant
verdict. Les doublons entre couches sont fusionnés sur cause commune et portent le même
numéro de groupe.

| # | Couche(s) | Trouvaille | Verdict | Évidence | Route |
|---|---|---|---|---|---|
| G1 | blind, edge | Un mot de passe de plus de 4096 octets passe la validation et fait lever `InvalidPasswordException` au hacheur : 500 sur la page de récupération | medium | Vérifié dans `vendor/symfony/password-hasher/PasswordHasherInterface.php:25` et `Hasher/CheckPasswordLengthTrait.php:21-23`. `PasswordStrength` ne borne pas la longueur, `NotBlank` non plus : une chaîne de 5 000 caractères aléatoires est acceptée puis explose dans `wrapInTransaction()`. C'est un 500 sur le seul chemin qui reste à quelqu'un qui a perdu son accès | patch |
| G2 | blind, edge | Aucun limiteur sur `/password/reset/{token}` : le sondage de jetons et le travail de hachage ne sont bornés par rien | low | Exact. Le préjudice atteignable est mince — 32 octets tirés au hasard rendent la devinette hors de portée, et un jeton mort coûte un `SELECT` indexé sans hachage — mais l'asymétrie avec la demande n'est nommée nulle part, ce qui la fait lire comme un oubli | defer |
| G3 | blind | La réponse indiscernable est vérifiée sur les octets, jamais sur la durée : un compte connu fait un DELETE, un INSERT, un dispatch et un commit là où une adresse inconnue rend après un SELECT | medium, non vérifié | Le canal est réel et c'est celui que la story existe pour fermer. Mais le spec énumère ce qui doit être identique — « même page, même statut, même message » — et la durée n'y est pas ; et le correctif (délai artificiel, ou file pour tous les cas) ouvre sa propre surface. Ce qui trancherait : une mesure sur la cible réelle, pas sur la suite de tests | defer |
| G4 | blind | « Un seul lien vivant à la fois » (AD-15) n'a aucune garantie au niveau de la base : deux demandes concurrentes ne suppriment rien l'une pour l'autre et insèrent toutes les deux | low | Vrai : `invalidateLiveFor()` puis `persist()` dans une transaction sans verrou ni index unique partiel — que MySQL n'a de toute façon pas. Atteignable seulement par double soumission simultanée, et les deux liens appartiennent au même compte légitime. Le préjudice n'est pas dans le code, il est dans le docblock qui énonce l'invariant sans sa portée | patch |
| G5 | blind, edge | `getClientIp()` peut rendre `null`, et `RateLimiterFactory::create(null)` donne un seau partagé | low — **severité rectifiée en cours de correctif** | `vendor/symfony/rate-limiter/RateLimiterFactory.php:42` accepte bien `?string`, et le triage a d'abord lu « un seau global » comme « tout le monde ». C'est faux et l'implémentation l'a rouvert : `getClientIp()` rend une adresse réelle ou `null`, jamais une chaîne vide, donc le seau `password_request-` n'est partagé qu'entre les requêtes **sans** adresse — il n'en prive aucun client qui en a une. Le correctif a été appliqué quand même, mais il ne change aucun comportement : il nomme ce seau et le met hors d'atteinte d'un futur `create()` qui traiterait `null` à part. Le commentaire du code et le docblock du test le disent, y compris que la mutation ne rougit pas | patch |
| G6 | blind | `templates/_flash.html.twig`, partiel générique, lit une constante de `PasswordController` ; et il ne rend que le type `success` | low | Exact sur les deux points. Mais le couplage est visible aux deux bouts — exactement ce que le dépôt exige ailleurs des identifiants CSRF — et le correctif proposé (un enum `FlashType`) ajoute une classe publique pour une préférence de rangement. Le jour où un second type de Flash existera, il coûtera la même ligne | rejeté |
| G7 | blind | `templates/password/expired.html.twig` n'entre dans aucun contrôle d'accessibilité : rendue en 410, elle ne peut pas passer l'`assertResponseIsSuccessful()` du balayage | medium | Vérifié : `AccessibilityFloorTest.php:828` échoue toute route non réussie, et `RouteSamples` émet délibérément un jeton **valide**. C'est la page que rendent les cinq chemins morts — celle que voit quiconque ouvre un lien périmé — et elle porte un `h1`, un lien à l'apparence de bouton et un anneau de focus que rien ne vérifie. `errorPages()` (`:679`) est le précédent d'une page que le balayage ne peut pas atteindre | patch |
| G8 | blind, edge | `RouteSamples::someone()` crée le compte à chaque appel alors que son docblock promet « une fois par méthode de test » | medium | Vérifié : `pages()` mémorise (`:833`), donc un seul appel aujourd'hui — mais chaque bras du `match` appelle `someone()`. La deuxième route à paramètre jamais ajoutée, c'est-à-dire le cas exact que cette classe existe pour rendre obligatoire, rencontrera la contrainte d'unicité sur `user.email` et fera échouer le plancher sur une clé dupliquée au lieu du message nommé | patch |
| G9 | blind | Le docblock de `PasswordTokens::rows()` cite une méthode de test qui n'existe pas | false | Rouvert : `tests/Core/Security/PasswordTokens.php:126-134` ne cite aucun nom de méthode. La trouvaille décrit un texte qui n'est pas là | rejeté |
| G10 | blind | La raison d'être de `PasswordController::string()` — rendre 422 et non 400 sur `email[]=x` — n'est exercée par rien | low | Vrai : aucun test ne soumet de charge utile non scalaire. Un retour à `getString()` casserait la ligne de matrice avec la suite au vert. Le correctif est un cas de test, pas une branche | patch |
| G11 | blind | `invalidateLiveFor()` rend un compte que personne ne lit | low | Exact et sans préjudice nommé : `execute()` sur un `DELETE` DQL rend ce nombre de lui-même, le docblock le dit, et l'ignorer ne casse rien. Le forcer à `void` retirerait une information gratuite | rejeté |
| G12 | blind, verif-gap | `{% include '_flash.html.twig' %}` sans `only` dans `login.html.twig`, là où `request.html.twig` écrit `with {…} only` | low | Vérifié aux deux emplacements. Le partiel lit `messages|default(…)` : une variable `messages` ajoutée un jour au contexte de la connexion détournerait silencieusement la région de Flash. Le correctif est deux mots | patch |
| G13 | blind | Dérive de suivi : `sprint-status.yaml` en `in-progress` alors que le spec est en `in-review` ; « quatre entrées » annoncées contre cinq écrites ; `too_many` dans `messages` et `retry_after` dans `security+intl-icu` | false | Trois points, trois réfutations : c'est l'étape 5 du workflow qui synchronise le suivi de sprint, pas l'étape 4 (même constat qu'aux stories 1.7 et 1.8) ; écrire une entrée différée de plus que prévu n'est pas un défaut ; et le partage entre `messages` et `security+intl-icu` est exactement le précédent de la connexion — le domaine ICU ne porte que ce qui a un pluriel | rejeté |
| G14 | edge (claim), verif-gap | Le jeton en clair est sérialisé dans `messenger_messages.body` par le transport Doctrine de production, alors que le docblock dit « jamais écrit dans une colonne » et le README « la base ne garde que l'empreinte » | medium | Vrai, et invisible sous test : `when@test` route les deux transports en `in-memory`, donc la ligne qui porterait le jeton n'est jamais créée. En production elle est lisible par quiconque a un SELECT sur la table, et indéfiniment pour un message parti en file d'échec. Le porteur est inévitable — l'email doit contenir le lien — mais l'affirmation, elle, est fausse telle qu'écrite, et un commentaire faux est la dette la plus chère de ce dépôt | patch |
| G15 | edge (claim) | `isEqualTo()` ne compare plus les rôles, alors que la comparaison native le faisait : un changement de rôle ne fermerait plus les sessions | false | Rouvert : `User::getRoles()` rend `['ROLE_USER']` en dur (AD-8, `src/Core/Entity/User.php:110-113`). Aucun rôle ne peut changer sur cette entité aujourd'hui, donc le mauvais résultat décrit n'est pas atteignable. À rouvrir par la story qui rendra les rôles variables — c'est l'Epic 2, et elle touchera `getRoles()`, pas cette méthode | rejeté |
| G16 | edge | Le POST accepté de `/password/forgot` rend un 200 sans redirection : un rafraîchissement resoumet et réémet un second lien | false | Le comportement est exact, mais la matrice du spec impose « 200 sur la même page » pour ce cas, et le limiteur borne la répétition à cinq par quart d'heure. Le correctif consisterait à éditer le spec de ce build | rejeté |
| G17 | edge | `getPayload()` lève sur un corps JSON malformé : 400 au lieu du 422 promis | low | Vrai et hors d'atteinte d'un navigateur : un formulaire HTML n'envoie jamais de JSON. Le correctif ajoute un `try`/`catch` pour un chemin que seul un client fabriqué à la main atteint | rejeté |
| G18 | verif-gap | Le dispatch **dans** `wrapInTransaction()` de `PasswordResetRequest` n'est épinglé par aucun test | medium | Pré-vérifiée et recoupée : aucun test ne nomme `PasswordResetRequest` hors lectures de constantes. Sortir le dispatch de la fermeture laisse toute la suite au vert, puisque le transport est en mémoire sous test — et rouvre en production la fenêtre où un email annonce un jeton qui n'existe pas encore. C'est la règle que la story 1.8 appelle « tout le sujet », tenue aujourd'hui sur une fixture seulement | patch |
| G19 | verif-gap | Le message `password.confirmation_mismatch` — la seule clé que cette story ait dû inventer — n'est jamais relu par un test | medium | Pré-vérifiée. Renommer la clé fait afficher `password.confirmation_mismatch` à l'utilisateur, suite au vert ; retirer le `message:` personnalisé réactive le défaut d'`EqualTo`, qui réémet le mot de passe saisi dans le HTML — et seule une assertion incidente le remarquerait | patch |
| G20 | verif-gap | Le prédicat `purpose` des deux requêtes de jeton n'est exercé par rien | medium | Pré-vérifiée : `AccountTokenPurpose::Invitation` n'apparaît nulle part dans `tests/`. Retirer les deux `andWhere('token.purpose = :purpose')` laisse la suite au vert — et à l'Epic 2, un lien d'invitation deviendrait utilisable comme lien de réinitialisation, et toute demande de mot de passe oublié supprimerait silencieusement l'invitation en attente du compte | patch |
| G21 | verif-gap | Le contrôle « le jeton n'atteint aucun journal » ne lit que `$record->message`, et seulement les journaux de la dernière requête | medium | Pré-vérifiée. Monolog range les données structurées dans `context` et `extra`, jamais dans `message` ; et le POST de réinitialisation n'est inspecté par rien. Un jeton journalisé en contexte passe au vert | patch |
| G22 | verif-gap | Rien ne prouve que les deux identifiants de jeton CSRF sont réellement distincts | medium | Pré-vérifiée. Poser `CSRF_RESET = 'password_request'` laisse la suite au vert, et un jeton obtenu sur la page publique de demande devient valable pour le POST qui change le mot de passe. C'est le fichier que le spec désigne comme le patron des Epics 2 à 6 | patch |
| G23 | verif-gap (autre) | Les deux règles `access_control` ajoutées ne sont lues par aucun test | low | Exact, et sans régression observable à ce commit : elles sont inertes tant qu'aucune règle attrape-tout n'existe. Le dépôt a bien l'idiome pour épingler un texte de configuration, mais l'épingler ici vérifierait que le fichier dit ce qu'il dit, pas qu'un comportement tient | rejeté |
| G24 | conformance | La pyramide de tests est aplatie : trois classes ajoutées, toutes `WebTestCase`, alors que la story pose un repository, deux services et deux DTO qui se testent chacun à leur niveau | medium | Vrai et documenté par le dépôt lui-même : 15 `TestCase` et 7 `KernelTestCase` existent, dont `SendEmailHandlerTest` posé par la story 1.8 sur exactement ce type de service. Les règles vivent bien dans les services — c'est le niveau de test qui dévie. Les deux bornes de `PasswordStrength` sont vérifiées par un POST complet avec jeton réel là où un `TestCase` sur le DTO suffit. Fondu dans G18, G20 et G22, qui appellent précisément les niveaux manquants | patch |
| G25 | conformance | `#[Assert\NotCompromisedPassword]` est absent, alors que le tableau de durcissement du skill sécurité dit qu'aucune de ses lignes n'est optionnelle | false | C'est la décision **D-2**, prise par Fabrice au checkpoint, énoncée dans le bloc gelé du spec et consignée dans `deferred-work.md` avec son déclencheur. Un écart divulgué n'est pas une trouvaille | rejeté |

## Design Notes

**Pourquoi une empreinte SHA-256 et non le hacheur de mots de passe.** Un jeton de 32 octets
tirés au hasard n'a pas besoin d'être ralenti : il n'est pas devinable par dictionnaire, et
le coût d'Argon2 empêcherait la seule chose dont on a besoin — retrouver la ligne **par son
empreinte** en une requête indexée. Un hachage lent obligerait à lire tous les jetons vivants
pour les comparer un par un. La colonne est unique ; la recherche est une égalité, pas une
boucle.

**Pourquoi une seule page pour « inconnu », « expiré » et « déjà utilisé ».** Trois pages
distinctes disent à qui essaie des jetons au hasard lesquels ont existé. Le 410 est le statut
juste — « a existé, n'existe plus » — et il ne se laisse pas distinguer d'un jeton jamais
émis. La page n'a **aucun formulaire** : elle propose de recommencer, elle ne laisse pas
croire qu'un mot de passe saisi ici servirait à quelque chose.

**Pourquoi le service refuse, et pas le contrôleur.** La non-divulgation est une règle : le
contrôleur reçoit un DTO valide et appelle le service, qui décide de ne rien faire pour un
compte inconnu ou désactivé, et rend la même chose dans tous les cas. Un `if` dans le
contrôleur serait la première règle métier posée hors d'un service, et le premier endroit où
un dérivé ajouterait par erreur un message différent.

**Ce que la story laisse ouvert pour l'Epic 2.** `intendedRole` et `AccountTokenPurpose` sont
livrés vides de tout usage d'invitation. C'est AD-15 qui l'exige : l'entité est le mécanisme
partagé, et la découvrir incomplète à la story 2.6 imposerait une seconde migration sur une
table déjà en production chez un dérivé.

## Verification

**Commands :**
- `php bin/console doctrine:migrations:migrate --no-interaction` -- la table `account_token`
  et la colonne `security_token` existent
- `php bin/console doctrine:schema:validate --skip-sync` -- mapping valide
- `php bin/console debug:router` -- deux routes, `app_password_request` et
  `app_password_reset`
- `vendor/bin/phpunit --filter 'PasswordReset|SessionInvalidation'` -- vus **rouges**
  d'abord, puis verts
- `make qa` -- les six catégories passent, sortie 0

**Manual checks :**
- Un cycle complet en local avec Mailpit et un worker : l'email arrive dans la langue du
  compte, le lien fonctionne une fois et une seule.
- La page `/password/reset/{token}` au clavier seul, puis à 320 px et zoom 400 %.
