---
stepsCompleted: [1]
inputDocuments:
  - _bmad-output/planning-artifacts/prds/prd-proglab-skills-2026-09-09/prd.md
  - _bmad-output/planning-artifacts/prds/prd-proglab-skills-2026-09-09/addendum.md
  - _bmad-output/planning-artifacts/architecture/architecture-proglab-skills-2026-09-09/ARCHITECTURE-SPINE.md
  - _bmad-output/planning-artifacts/ux-designs/ux-proglab-skills-2026-09-09/DESIGN.md
  - _bmad-output/planning-artifacts/ux-designs/ux-proglab-skills-2026-09-09/EXPERIENCE.md
  - _bmad-output/specs/spec-proglab-skills/SPEC.md
  - _bmad-output/specs/spec-proglab-skills/criteres-acceptation.md
  - _bmad-output/specs/spec-proglab-skills/glossaire.md
---

# proglab-skills — Découpage en epics

## Vue d'ensemble

Ce document porte le découpage complet en epics et stories du **socle ERP custom (proglab)**.
Il décompose les exigences du PRD, du contrat UX (`DESIGN.md` + `EXPERIENCE.md`) et de
l'ARCHITECTURE-SPINE en stories implémentables. Le vocabulaire est celui du glossaire du
PRD (§3) et de `specs/spec-proglab-skills/glossaire.md`.

**Correspondance des identifiants.** `CAP-N` du SPEC = `FR-N` du PRD, volontairement.
Ce document utilise `FR-N`. Les décisions d'architecture sont citées `AD-N`, les exigences
tirées du contrat UX `UX-DR-N`, celles tirées de l'architecture `AR-N`.

## Inventaire des exigences

### Exigences fonctionnelles (FR)

**FR-1** : Initialiser un dérivé en une commande — prépare la base, applique les migrations, charge les rôles par défaut, crée le premier Super admin ; refuse d'agir sur une base peuplée sans confirmation explicite.

**FR-2** : Guide de dérivation — documentation du dépôt expliquant l'organisation du socle, comment le dériver, où ajouter un module métier, les conventions, les skills proglab et workflows BMAD à invoquer, la convention d'en-tête YAML des stories, et la responsabilité de sauvegarde du serveur client.

**FR-3** : Se connecter avec email et mot de passe — refus d'un compte désactivé sans révéler la validité du mot de passe, ralentissement des échecs répétés avec les secondes restantes, réinitialisation par lien email à usage unique.

**FR-4** : Inviter un utilisateur — lien à usage unique et expirable, renvoi invalidant le précédent, acceptation créant un compte actif au rôle prévu avec entrée d'audit, états visibles par l'administrateur (en attente, expirée, acceptée), fonction réservée aux administrateurs.

**FR-5** : Double authentification — obligatoire pour les rôles qui l'exigent (attribut de rôle, aucun exempté), optionnelle sinon ; délai de grâce de sept jours porté par un paramètre, remis à zéro au changement de rôle ; deux méthodes au moins plus des codes de secours ; réinitialisation par un administrateur, auditée.

**FR-6** : Désactiver un utilisateur — plus de connexion, sessions closes, entrées d'audit et objets intacts sous son nom marqué « compte désactivé », réactivation possible, aucune suppression.

**FR-7** : Rôles par défaut — Super admin, Admin, User livrés ; permissions en grille ressources × opérations (`create`, `read`, `update`, `delete`) plus les actions à code nommé de chaque ressource ; grille dérivée des ressources déclarées, jamais tenue à la main ; modification d'un rôle appliquée immédiatement sauf surcharge ; création de rôles depuis l'interface ; un rôle porte l'attribut « exige la double authentification ».

**FR-8** : Surcharger les permissions d'un utilisateur — ajout ou retrait primant sur le rôle, chaque surcharge auditée.

**FR-9** : Voir l'origine de chaque permission — même grille que l'écran de rôle, état effectif et origine (héritée, ajoutée, retirée) par case, annulation d'une surcharge, permission obsolète signalée et jamais accordée.

**FR-10** : Appliquer les permissions partout — 403 traduit sur une zone non permise, vérification portant sur l'opération demandée, 404 indistinguable d'un objet inexistant sans droit de consultation, 403 sur une opération refusée d'un objet consultable, navigation filtrée.

**FR-11** : Enregistrer les modifications d'objets — auteur, horodatage, objet, ancienne et nouvelle valeur par champ ; tous les objets métier audités par défaut avec exclusion explicite possible ; champs sensibles exclus à l'écriture ; acteur système nommé hors requête ; entrée écrite dans la même transaction que la donnée.

**FR-12** : Enregistrer les actions métier — un appel depuis la couche service, code stable et libellé traduisible ; exception nommée pour les actions d'authentification, déclarées par la couche sécurité, un échec de connexion n'ayant pas d'acteur référencé ; liste de référence des actions du socle auditées.

**FR-13** : Consulter le journal d'audit — filtres période, auteur, type d'objet, objet précis, type d'entrée ; lecture sans connaissance technique ; objet rendu en lien vers sa fiche seulement si une route est déclarée et que le lecteur peut le consulter ; export unique portant sur la sélection filtrée, archive comprise ; entrées immuables ; lecture filtrée par les droits ; aucun choix à faire entre en ligne et archive.

**FR-14** : Lire la roadmap depuis les fichiers BMAD — source `_bmad-output/implementation-artifacts/sprint-status.yaml`, clés `epic-{n}` et `{n}-{m}-{titre}`, `epic-{n}-retrospective` exclue de l'avancement ; priorité, difficulté, assignation et dépendances lues dans l'en-tête YAML de la story, « non renseigné » sinon ; fichier absent ou mal formé produisant une roadmap partielle avec avertissement, jamais une erreur ; date du dernier déploiement affichée.

**FR-15** : Afficher la roadmap — page d'accueil de qui a la permission ; epics avec avancement (tâches terminées sur total) ; tâches avec statut lisible, priorité, difficulté, assignation, dépendances ; correspondance des statuts BMAD fixée, statut inconnu rendu « à faire » et signalé ; notes internes réservées à la permission dédiée.

**FR-16** : Lancer une tâche en environnement de développement — action inexistante en production (ni bouton, ni route enregistrée) ; seule une tâche prête est lançable, sinon bouton inactif avec la raison ; tâche en cours non relançable ; compte rendu du démarrage ou de son échec ; l'application n'écrit jamais dans le dépôt.

**FR-17** : Obtenir un jeton d'accès pour l'API — créé depuis le profil, nommé, montré une seule fois, stocké haché, expirable, révocable par l'utilisateur et par un administrateur ; liste portant nom, date de création et mention visible « expiré » ou « révoqué » ; refus d'un jeton expiré, révoqué ou d'un compte désactivé ou anonymisé ; création et révocation auditées ; aucune URL de l'API n'accepte un mot de passe.

**FR-18** : Health check — état du service et de sa base sans détail interne, code HTTP distinct en état dégradé ; dégradé quand la file d'envoi cesse d'être dépilée ou que son plus vieux message dépasse un seuil, et quand la plus vieille entrée d'audit en ligne dépasse la fenêtre de rétention.

**FR-19** : Interface en trois langues — tout texte d'interface, message d'erreur, email et libellé d'audit du socle en français, anglais et néerlandais ; français langue source et par défaut ; langue du compte mémorisée et appliquée à ses emails ; chemins d'URL invariables ; un module suit le même mécanisme.

**FR-20** : Anonymiser un utilisateur désactivé — nom et email remplacés par un identifiant neutre partout où la personne apparaît, y compris dans les entrées décrivant un autre objet, en ligne **et** en archive ; compte rendu du nombre d'entrées réécrites ; irréversible et auditée ; refusée sur un compte actif ; limitation nommée sur les envois déjà en file.

**FR-21** : Gérer son profil — langue d'interface, mot de passe (exigeant l'ancien et clôturant les autres sessions), réenrôlement 2FA (exigeant le second facteur en cours ou un code de secours).

**FR-22** : Activer ou désactiver une langue — désactiver la dernière est refusé ; une langue désactivée n'est plus proposée et ses utilisateurs basculent sur la langue de repli ; changement audité ; langues actives en donnée d'exécution, jamais en variable d'environnement.

**FR-23** : Archiver le journal d'audit au-delà d'un mois — déplacement vers une archive, fenêtre en paramètre ; entrée archivée consultable et exportable sous les mêmes filtres, clé conservée ; aucune suppression ; commande planifiée, verrouillée, attribuée à l'acteur système, rendant compte du nombre d'entrées déplacées ; archivage interrompu sans doublon ni perte ; archivage arrêté visible par le health check ; l'anonymisation atteint l'archive.

### Exigences non fonctionnelles (NFR)

**NFR-1 — Sécurité** : mots de passe hachés selon l'état de l'art ; sessions et jetons d'accès révocables ; protection CSRF sur toute écriture, sans exception ; aucune donnée sensible dans les journaux applicatifs ; journal d'audit en ajout seul (seule exception : l'anonymisation de FR-20).

**NFR-2 — Accessibilité** : plancher WCAG 2.1 AA de `symfony-proglab-accessibility` — couleur jamais seule, contraste 4,5:1 sur le texte et 3:1 sur les composants, labels réels, focus visible, alternatives textuelles, une seule `h1`, rien derrière le survol seul — porté par un gabarit de base unique et **vérifié par un test automatisé en CI, jamais par relecture**.

**NFR-3 — Performance** : connexion, fiche utilisateur, journal d'audit et roadmap répondent en moins d'une seconde au p95, en développement comme en production, pour un dérivé de taille courante (jusqu'à 50 utilisateurs, 50 000 entrées par mois en ligne, 1 million en archive, jusqu'à 40 ressources dans la grille). Le journal est paginé, ses libellés résolus par lot, son export streamé. Une requête débordant sur l'archive sort du budget et l'annonce à l'écran.

**NFR-4 — Observabilité** : health check (FR-18) et journaux applicatifs par canal, sans données personnelles.

**NFR-5 — Qualité** : analyse statique et tests de la suite proglab ; chaque FR couverte par au moins un test fonctionnel ; test écrit d'abord et vu rouge ; CI livrée avec le socle, bloquante sur analyse statique, style, deptrac, tests, accessibilité et `composer audit`, sur une base identique à celle de production.

**NFR-6 — Dérivabilité** : rien dans le socle ne suppose un client particulier ; toute configuration propre à un dérivé vit dans des variables d'environnement ou des fichiers prévus pour être modifiés ; un dérivé n'édite aucun fichier de `src/Core/` ni de `config/`.

**NFR-7 — Données personnelles** : le journal contient des noms et des actions ; la désactivation ne supprime rien ; le droit à l'effacement est satisfait par l'anonymisation — l'historique reste, la personne n'y est plus identifiable.

### Exigences additionnelles (Architecture)

> 🚨 **Point de départ du projet — impacte Epic 1, Story 1.** L'ARCHITECTURE-SPINE ne
> prescrit **aucun starter template tiers**. Le socle démarre d'un **squelette Symfony 7.4
> LTS sur PHP 8.5** (AD-1), auquel il ajoute lui-même sa structure à deux racines, son
> outillage de qualité et sa configuration de CI. Epic 1, Story 1 est donc l'amorce du
> dépôt, pas l'adoption d'un template existant.

- **AR-1 (AD-1)** — Symfony 7.4 LTS sur PHP 8.5. Aucune dépendance exigeant Symfony 8 n'entre dans le socle (ce qui exclut `damienharper/auditor-bundle` 7.x). Un dérivé ne monte pas de majeure de son propre chef.
- **AR-2 (AD-2)** — Deux racines : `src/Core/` porte le socle, `src/Module/<Nom>/` le métier du client, avec les mêmes couches et les mêmes noms de dossiers. `Core/Contract/` (interfaces + DTO, sans Doctrine ni HTTP) est la seule porte publique.
- **AR-3 (AD-7)** — `deptrac/deptrac` en `require-dev` vérifiant quatre règles bloquantes en CI : couches proglab dans chaque racine, `Core → Module` interdit, `Module → Module` interdit, `Module → Core` interdit sauf vers `Core\Contract`.
- **AR-4 (AD-13)** — Câblage framework fixé une fois pour les deux racines, en configuration, par glob sur `src/Module/*/` : mapping Doctrine, routes par attributs, chemins du translator, découverte des services. Ajouter un module ne modifie aucun fichier de `src/Core/` ni de `config/`.
- **AR-5 (AD-8, AD-12, AD-13)** — Registre de déclaration : un module publie par service taggé **une déclaration par type d'objet** portant son alias de type, son nom de ressource (le même identifiant), les opérations supportées, ses actions particulières, sa permission de lecture et, facultativement, la route de sa fiche. Une commande de synchronisation projette le catalogue en base au déploiement et **échoue** sur une collision de nom ou sur un code exigé par `#[IsGranted]` sans déclaration.
- **AR-6 (AD-8)** — Permission effective = rôle − retraits + ajouts, calculée à chaque requête, lue par un **voter unique**, mémoïsée dans un service dédié à durée de requête (le voter reste `final readonly`). Aucun code ne teste un nom de rôle. `access_control` par zone **et** `#[IsGranted]` par action, jamais l'un seul.
- **AR-7 (AD-3, AD-21)** — Un service d'audit unique dans `Core/Service/` porte toutes les règles ; un listener Doctrine `onFlush` ne fait que lire les changesets et l'appeler. Écart énoncé au standard : c'est la seule exception « événement plutôt qu'appel direct ». L'entrée est écrite dans la **même transaction**, sans second `flush()`.
- **AR-8 (AD-23)** — Deux tables d'audit de forme identique (en ligne, archive), clé primaire conservée au déplacement, connues du **seul dépôt d'audit**. Chaque lecture résout un point de coupure une fois par requête, interroge les deux avec le même prédicat et les réunit **en SQL** — union, tri sur `(horodatage, id)`, `LIMIT`/`OFFSET`, comptage sur l'union, déduplication par clé primaire. Jamais une fusion en PHP.
- **AR-9 (AD-20)** — Index déclarés sur les cinq axes de filtre (horodatage, acteur, alias de type, couple alias + clé, type d'entrée), **sur les deux tables** ; étiquettes et libellés résolus **par lot pour la page entière** ; export streamé par lots, hors Turbo, passant par la même lecture filtrée par les droits. Un test d'intégration compte les requêtes d'une page traversant les deux tables et échoue si le nombre croît avec les lignes.
- **AR-10 (AD-4, AD-21)** — Tout email passe par Messenger sur le transport Doctrine, avec retry et file d'échec. Un message porte des **scalaires** et une **locale explicite**, jamais une entité ni une dépendance au contexte de requête. Les messages sont dispatchés pour n'être consommables **qu'après le commit**. Worker livré en service systemd piloté par Deployer.
- **AR-11 (AD-23)** — L'archivage est déclenché par le Scheduler, **dans son propre schedule et sur son propre worker**, jamais mêlé à la file `async` ; verrouillé par `symfony/lock` ; travaille par lots de taille paramétrée, **chaque lot en une seule transaction** portant insertion et suppression ; écrit en SQL depuis le dépôt et non par l'ORM ; les deux tables d'audit sont exclues de l'audit par construction. Une crontab appelant `bin/console` reste un repli légitime.
- **AR-12 (AD-9)** — Le compte porte un jeton de sécurité incrémenté à la désactivation, au changement de mot de passe et à la réinitialisation de la 2FA ; `EquatableInterface` le compare à chaque requête et déauthentifie les sessions concernées. Sessions en fichiers, aucun second magasin. Écart énoncé : `isEqualTo()` est la seule méthode de comportement admise sur une entité.
- **AR-13 (AD-5)** — Firewall `/api` **sans état**, n'acceptant que `Authorization: Bearer`. À chaque requête, l'`AccessTokenHandler` vérifie expiration et non-révocation, et un `UserChecker` refuse un compte désactivé ou anonymisé — `EquatableInterface` n'opérant pas ici. Aucun endpoint d'authentification.
- **AR-14 (AD-15)** — Invitation et réinitialisation partagent **une entité à état** : destinataire, rôle prévu, jeton aléatoire stocké haché, date d'expiration, date d'usage. L'URL transporte le jeton en clair, la base n'en garde que l'empreinte. Aucune URL signée sans état.
- **AR-15 (AD-18)** — CSRF obligatoire sur toute écriture. `login_throttling` natif adossé à `symfony/rate-limiter` ; limiteurs nommés distincts pour la vérification du second facteur, la demande de réinitialisation et l'acceptation d'invitation. Les **secondes restantes** sont lues sur le limiteur, pas déduites du message. Aucun blocage définitif.
- **AR-16 (AD-19)** — Le rôle porte un booléen « exige la double authentification » ; le compte porte la date de début de son délai de grâce, posée à la première connexion sous un tel rôle et remise à zéro si le rôle change. Un **unique point d'application** — un listener de requête dans `Core`, `scheb/2fa` n'en fournissant aucun. Délai porté par un paramètre `app.`. Aucun rôle exempté.
- **AR-17 (AD-10)** — Un **lecteur BMAD unique** dans `Core/Service/` parse les fichiers de façon tolérante et rend des **DTO Output** ; aucun contrôleur, template ou module ne les lit. L'application n'écrit jamais dans le dépôt, seul `var/` est inscriptible. Le résultat du parse est **toujours** mis en cache, invalidé par la version de release en production et par la date de modification en développement — ce cache est le moyen du budget de performance, pas une optimisation reportable.
- **AR-18 (AD-11)** — Le lancement d'une tâche porte `#[When('dev')]` sur le service **et** le contrôleur, **et** `#[Route(..., env: 'dev')]` sur la route — le premier attribut ne retire pas la route de la table de routage. Un test vérifie qu'en production la route est absente. Le lancement démarre un processus local détaché et ne rend compte que du démarrage.
- **AR-19 (AD-14)** — Langues supportées (`fr`, `en`, `nl`) en code ; langues **actives** en base, modifiables depuis l'interface. Langue de l'utilisateur = champ du compte, résolue côté serveur, portée explicitement dans chaque message d'email, appliquée à l'attribut `lang` de la page. Français langue source des catalogues. Dates et nombres dans la locale du lecteur.
- **AR-20 (AD-6)** — Chemins d'URL **en anglais et invariables**, sans préfixe de langue ; le `?lang=` de la page de connexion est la seule exception. La table complète des chemins et noms de route `app_*` est fixée par AD-6 et fait foi (les chemins français d'`EXPERIENCE.md · Surfaces` sont périmés).
- **AR-21 (Consistency Conventions)** — Namespaces `App\Core\<Couche>\…` et `App\Module\<Nom>\<Couche>\…` ; mêmes dossiers de couche dans les deux racines ; services `final readonly` nommés d'après leur responsabilité, jamais `<Chose>Service` ; entités format maker sans méthode de comportement ; `Dto/{Input,Read,Output}` avec `ObjectMapperInterface` ; enveloppe `items` + `meta` sans curseur ni Pagerfanta ; exceptions métier avec `#[WithHttpStatus]` et `#[WithLogLevel]`, Problem Details RFC 7807 côté API ; permissions en `snake_case` préfixé ; catalogues par domaine à source française ; configuration répartie entre variable d'environnement, paramètre `app.` et constante de classe ; secrets par `.env.local` en développement et **coffre de secrets Symfony en production**.
- **AR-22 (AD-22)** — Le socle **livre sa configuration de CI**, bloquante sur la même liste que la tâche de qualité locale : analyse statique, style, deptrac, tests, accessibilité, `composer audit`. La base de données de CI est celle de la production, **jamais SQLite**.
- **AR-23 (AD-22, enveloppe de déploiement)** — Déploiement par Deployer en SSH : `releases/` · `current/` · `shared/`, Apache + PHP-FPM 8.5, MySQL du serveur client, SMTP du client. Les migrations s'exécutent à chaque release ; le worker est redémarré par Deployer ; **la date de la release est la « date du dernier déploiement » de FR-14**. La sauvegarde est une responsabilité du serveur client, nommée et documentée avec sa procédure de restauration et l'exigence qu'elle ait été exécutée une fois avant la mise en service.
- **AR-24 (AD-17)** — Amélioration progressive : tout chemin fonctionne en pleine page sans JavaScript ; Turbo et Stimulus n'ajoutent que le confort. **Chaque action destructive a deux routes** : un GET rendant une page de confirmation complète, et un POST porteur d'un jeton CSRF qui agit. Les panneaux ont tous une URL propre. Aucun Live Component dans le socle.
- **AR-25 (Stack)** — Versions arrêtées : PHP 8.5, Symfony 7.4 LTS, Doctrine ORM 3.7 / DBAL 4.4, `scheb/2fa-bundle` 8.6 (totp, email, backup-code), `symfony/messenger` transport Doctrine, `symfony/scheduler` + `dragonmantank/cron-expression`, `symfony/lock`, `symfony/rate-limiter`, `symfony/object-mapper`, `symfony/asset-mapper`, UX Turbo / Stimulus / Twig Component 3.4, `symfony/ux-toolkit` 3.4 (expérimental — kit copié dans le dépôt et maintenu par le socle), `symfonycasts/tailwind-bundle` 1.0 (Tailwind 4), `deptrac/deptrac` 4.7, et en `require-dev` `dama/doctrine-test-bundle`, PHPStan, `php-cs-fixer/shim`.
- **AR-26 (Tests)** — Test écrit d'abord et vu rouge ; `dama/doctrine-test-bundle` ; chaque FR couverte par au moins un test fonctionnel ; base de CI identique à la production.

### Exigences de conception UX (UX-DR)

Extraites du contrat UX — `DESIGN.md` (identité visuelle, jetons) et `EXPERIENCE.md`
(architecture de l'information, comportement, états, interactions, accessibilité, parcours).
Ces deux documents restent la spécification de détail : les UX-DR ci-dessous en fixent le
périmètre, ils ne le remplacent pas.

- **UX-DR-1 — Jetons de thème.** Thème de départ shadcn « neutral » (white-label) en clair **et** en sombre dans `assets/styles/app.css` : les rôles shadcn standard plus les jetons **ajoutés par le socle** — `page`, `muted-strong`, `track`, `sidebar` / `sidebar-foreground` / `sidebar-accent`, et les cinq alias de statut `status-todo` / `status-doing` / `status-done` / `status-ready` / `status-unreadable`, chacun avec sa variante `-dark`.
- **UX-DR-2 — Contrat de rebranding.** Un dérivé ne change que deux choses : les variables `oklch` de `app.css` (au minimum `--primary` / `--primary-foreground`) et le logo de la sidebar. **Aucun réglage de marque dans l'administration** ; aucune couleur codée en dur dans un template. Le principe « défauts shadcn » est documenté : un défaut du kit sous un seuil WCAG (bordure de champ `--input`, anneau `--ring`) est **conservé et jamais consigné en dette**.
- **UX-DR-3 — Échelle typographique.** Douze rôles sur pile système, sans police web : `display`, `heading`, `subheading`, `lead`, `body` (15 px), `body-sm`, `label`, `meta`, `caption`, `badge`, `stat` (chiffres tabulaires), `mono`. Graisses 400 / 500 / 600 seulement, aucune capitale forcée.
- **UX-DR-4 — Jetons d'espacement et de forme.** Échelle base 4, plus `gutter-desktop` 32, `gutter-mobile` 16, `content-max` 1000, `content-max-table` 1280, `sidebar-width` 248, `header-height` 60, `card-padding` 24, `card-gap` 14, `row-padding` 14, `touch-target-min` 44 ; rayons `sm` 4 / `md` 8 / `DEFAULT` 10 / `xl` 12 / `full`.
- **UX-DR-5 — Créer les composants réutilisables.** Installer le kit shadcn par `ux:install --kit shadcn` (un seul kit dans le projet, icônes Tabler par `ux:icons`), puis construire la bibliothèque de composants Twig du socle — coque et navigation, composants de données, composants de formulaire, surfaces flottantes et retours utilisateur. Les composants prennent des DTO et ne portent aucune règle métier. **Spécification de détail** : `DESIGN.md · Components` pour l'anatomie, les variantes et les règles visuelles ; `EXPERIENCE.md · Component Patterns` pour le comportement et le balisage accessible de chacun, y compris les écarts délibérés aux défauts du kit.
- **UX-DR-6 — Plancher d'accessibilité et comportement sous Turbo Drive.** Un gabarit de base unique dans `Core` portant le lien « Aller au contenu », les repères `<header>` / `<nav aria-label>` / `<main id tabindex="-1">` / `<footer>`, la région d'annonces persistante `<div id="annonces" aria-live="polite" class="sr-only" data-turbo-permanent>`, le `<title>` « <page> — <nom du dérivé> » et l'unique `h1` ; un contrôleur Stimulus `page-focus` plaçant le focus après chaque navigation (bloc d'erreur `role="alert"`, puis premier champ `aria-invalid`, puis Flash, puis `h1`) et un contrôleur `announce` recopiant le texte d'un Flash ou d'une erreur dans la région live. Les sept règles de `symfony-proglab-accessibility` s'appliquent partout et sont **vérifiées par un test automatisé en CI**.
- **UX-DR-7 — Textes d'interface de référence.** Le tableau Voice and Tone d'`EXPERIENCE.md` fixe la version française de référence de chaque moment (états vides, erreurs de connexion, invitations, 2FA et codes de secours, sessions, 403/404/500, dialogs de confirmation, flashs, roadmap partielle, lancement de tâche). Vouvoiement, phrases courtes, jamais d'identifiant technique ni de code HTTP, un message d'erreur dit toujours quoi faire ensuite, une confirmation destructive nomme la personne et la conséquence. Ces chaînes sont la **source** des catalogues fr / en / nl.
- **UX-DR-8 — Amélioration progressive vérifiable.** Sans JavaScript : Accordion en `<details>`, Sheet et Dialog rendus comme pages complètes à URL propre, formulaires en POST + redirection. Turbo et Stimulus n'ajoutent que le confort.
- **UX-DR-9 — Responsive et impression.** Quatre paliers : ≥ `lg` (sidebar fixe, Sheet 480 px), `md`–`lg` (tiroir, colonnes complètes), < `md` (gouttières réduites, colonnes prioritaires, résumé d'epic empilé, Sheet et Dialog plein écran, cibles ≥ 44 px), **320 px à zoom 400 % sans défilement horizontal du body** — seules les Tables et les blocs `mono` défilent dans leur conteneur. Impression du journal filtré et de la roadmap sans sidebar ni en-tête.
- **UX-DR-10 — Mouvement.** Dépliage 200 ms, chevron 150 ms, Sheet 200 ms, Dialog et DropdownMenu 150 ms — **rien d'autre ne bouge**. Sous `prefers-reduced-motion: reduce`, toutes les durées tombent à 0, y compris pour les transitions écrites à la main.
- **UX-DR-11 — Primitives d'interaction.** Clavier d'abord, **aucun raccourci global inventé** ; Échap ferme toujours l'élément flottant le plus haut et rend le focus à son déclencheur ; Entrée soumet depuis n'importe quel champ ; `:focus-visible` sur tout élément interactif, y compris les lignes de tableau et le résumé d'Accordion ; le survol n'ajoute que `accent`, jamais une action ; **une seule couche flottante à la fois** ; pas de glisser-déposer, pas de clic droit, pas de double clic, pas de geste de balayage.
- **UX-DR-12 — Pages d'erreur.** 403, 404 et 500 partagent le même gabarit court : message traduit du tableau Voice and Tone, lien « Retour à la roadmap », **aucun détail technique**. Rendues par le template d'erreur Symfony, donc aussi sans Turbo ; si Turbo n'obtient aucune réponse, il retombe sur une navigation complète.
- **UX-DR-13 — États vides et de chargement.** Roadmap vide (avec, pour un Super admin en dev, la ligne indiquant où les fichiers BMAD sont attendus) ; journal sans résultat, filtres conservés ; « Vous êtes seul pour l'instant » avec bouton « Inviter un utilisateur » ; barre Turbo par défaut, **aucun squelette**.
- **UX-DR-14 — Garde-fous de visibilité.** Un utilisateur **ne peut jamais se désactiver, s'anonymiser ni changer son propre rôle** depuis sa fiche : les boutons et le sélecteur ne sont pas rendus sur sa propre fiche. L'avertissement de roadmap partielle est réservé aux administrateurs. Les notes internes ne sont rendues qu'à la permission dédiée. **Un bouton absent n'est pas une protection** : chaque route vérifie l'opération demandée.

### Carte de couverture des exigences

{{requirements_coverage_map}}

## Liste des epics

{{epics_list}}
