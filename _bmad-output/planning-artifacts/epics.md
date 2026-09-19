---
stepsCompleted: [1, 2, 3, 4]
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

**NFR-1 — Sécurité** : mots de passe hachés selon l'état de l'art ; sessions et jetons d'accès révocables ; protection CSRF sur toute écriture (seule exception : la déconnexion, AD-18) ; aucune donnée sensible dans les journaux applicatifs ; journal d'audit en ajout seul (seule exception : l'anonymisation de FR-20).

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
- **AR-15 (AD-18)** — CSRF obligatoire sur toute écriture, à la seule exception nommée de la déconnexion. `login_throttling` natif adossé à `symfony/rate-limiter` ; limiteurs nommés distincts pour la vérification du second facteur, la demande de réinitialisation et l'acceptation d'invitation. Les **secondes restantes** sont lues sur le limiteur, pas déduites du message. Aucun blocage définitif.
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

| FR | Epic | Portée |
|---|---|---|
| FR-1 | Epic 1 | Commande d'initialisation |
| FR-2 | Epic 1 | Guide de dérivation |
| FR-3 | Epic 1 | Connexion, ralentissement, réinitialisation du mot de passe |
| FR-19 | Epic 1 | Trois langues — mécanisme et catalogues |
| FR-4 | Epic 2 | Invitation, renvoi, acceptation |
| FR-6 | Epic 2 | Désactivation et réactivation |
| FR-7 | Epic 2 | Rôles et grille dérivée des ressources déclarées |
| FR-8 | Epic 2 | Surcharges par utilisateur |
| FR-9 | Epic 2 | Origine de chaque permission |
| FR-10 | Epic 2 | Application partout — 403 / 404, navigation filtrée |
| FR-22 | Epic 2 | Activer ou désactiver une langue |
| FR-14 | Epic 3 | Lecture des fichiers BMAD |
| FR-15 | Epic 3 | Affichage de la roadmap |
| FR-16 | Epic 3 | Lancer une tâche en environnement de développement |
| FR-11 | Epic 4 | Audit des modifications d'objets |
| FR-12 | Epic 4 | Audit des actions métier |
| FR-13 | Epic 4 | Consultation, filtres, export |
| FR-23 | Epic 4 | Archivage au-delà d'un mois |
| FR-20 | Epic 4 | Anonymisation, en ligne et en archive |
| FR-5 | Epic 5 | Double authentification |
| FR-21 | Epic 5 | Profil |
| FR-17 | Epic 6 | Jetons d'accès pour l'API |
| FR-18 | Epic 6 | Health check |

23 FR, aucune orpheline, aucune en double.

**Deux coutures assumées, énoncées plutôt que masquées.**

1. **FR-1 n'est pleinement vérifiable qu'à l'Epic 3.** Son troisième critère est « le Super
   admin peut se connecter et voit la roadmap (vide) ». L'Epic 1 livre la connexion et une
   page d'accueil ; l'Epic 3 fait de cette page la roadmap. L'Epic 1 **fonctionne** sans
   l'Epic 3 — c'est le critère, pas la fonction, qui se complète plus tard.
2. **L'Epic 2 réunit deux vagues de l'ordre de livraison** de `criteres-acceptation.md`
   (CAP-4 et CAP-7 en vague 1 ; CAP-6, CAP-8, CAP-9, CAP-22 en vague 3), parce qu'elles
   vivent sur les mêmes écrans et le même composant de grille. Les séparer ferait repasser
   deux epics sur la fiche utilisateur. Arbitré par Fabrice le 2026-09-10.

**Où sont câblées les clauses « crée une entrée d'audit ».** Elles ne sont portées par
aucune des epics 1 à 3 : FR-12 possède la liste de référence des actions du socle auditées,
et c'est l'Epic 4 qui la câble sur tout ce qui a été livré avant elle. L'Epic 5 et l'Epic 6,
qui viennent après, appellent le service d'audit déjà en place.

## Liste des epics

### Epic 1 : Cloner un socle et obtenir un ERP qui répond

Fabrice clone le socle, lance une commande, ouvre l'application et s'y connecte dans sa
langue. Un développeur qui arrive sur le dépôt sait où écrire le premier module du client
sans demander à personne.

**FR couvertes :** FR-1, FR-2, FR-3, FR-19

**Porte aussi le socle transverse** dont toutes les epics suivantes dépendent : deux
racines, deptrac et CI (AR-2, AR-3, AR-4, AR-22, AR-26) ; enveloppe de déploiement
(AR-23) ; gabarit de base et plancher d'accessibilité (UX-DR-6, NFR-2) ; kit shadcn,
jetons de thème, typographie et espacement (UX-DR-1 à UX-DR-5) ; pages d'erreur (UX-DR-12)
et états vides (UX-DR-13) ; Messenger et emails (AR-10) ; entité à jeton partagée entre
invitation et réinitialisation (AR-14) ; limitation de débit et CSRF (AR-15) ;
amélioration progressive, responsive, mouvement (UX-DR-8, UX-DR-9, UX-DR-10, UX-DR-11).

### Epic 2 : Administrer les comptes et les droits

Sophie fait entrer ses collègues et leur donne exactement les droits qu'il faut — ni plus,
ni moins — et voit d'où vient chaque permission. Ce que l'application refuse, elle le
refuse partout.

**FR couvertes :** FR-4, FR-6, FR-7, FR-8, FR-9, FR-10, FR-22

**Notes d'implémentation :** registre de déclaration des ressources et commande de
synchronisation qui échoue sur collision (AR-5) ; voter unique et mémoïsation à durée de
requête (AR-6) ; fermeture des sessions par comparaison d'utilisateur (AR-12) ; garde-fous
de visibilité — nul ne se désactive ni ne change son propre rôle (UX-DR-14).

### Epic 3 : Montrer au client où en est son ERP

Marc ouvre son ERP le vendredi soir et comprend en cinq secondes où en est sa construction,
sans avoir jamais entendu le mot BMAD. Sur son poste, Léa lance la tâche suivante depuis
la même page.

**FR couvertes :** FR-14, FR-15, FR-16

**Notes d'implémentation :** lecteur BMAD unique rendant des DTO Output, avec son cache
obligatoire — moyen du budget de performance, pas une optimisation reportable (AR-17) ;
surface de lancement absente de la production par double attribut, `#[When('dev')]` et
`#[Route(env: 'dev')]`, vérifiée par un test (AR-18).

### Epic 4 : Répondre à « qui a fait ça ? »

Sophie répond seule à « qui a changé ce tarif ? » en moins d'une minute et exporte la
réponse pour son comptable. Le journal reste lisible et rapide après un an, sans que
personne n'ait rien supprimé — et une demande d'effacement est satisfaite sans perdre
l'historique.

**FR couvertes :** FR-11, FR-12, FR-13, FR-23, FR-20

**Notes d'implémentation :** listener `onFlush` ne traduisant qu'un changeset vers un
service unique, écrit dans la même transaction (AR-7) ; deux tables de forme identique
réunies en SQL par le seul dépôt d'audit (AR-8) ; index sur les cinq axes, résolution des
libellés par lot, export streamé (AR-9, NFR-3) ; archivage planifié et verrouillé sur son
propre worker, un lot par transaction (AR-11). **Cette epic câble la liste de référence de
FR-12 sur tout ce que les epics 1 à 3 ont livré.**

### Epic 5 : Sécuriser l'accès de chaque utilisateur

Aucun compte à pouvoir n'est protégé par un seul mot de passe, et chaque utilisateur gère
lui-même sa langue, son mot de passe et son second facteur.

**FR couvertes :** FR-5, FR-21

**Notes d'implémentation :** obligation portée par un attribut du rôle et jamais par son
nom, avec un point d'application unique et un délai en paramètre `app.` (AR-16) ; champ de
code à un seul input, jamais l'InputOTP à six cases (UX-DR-5) ; bandeau pendant le délai
de grâce puis redirection forcée, profil et déconnexion toujours accessibles.

### Epic 6 : Ouvrir le dérivé à une application cliente

Une future application cliente s'identifie auprès du dérivé avec un jeton que son
utilisateur a créé et peut révoquer, et sait sans s'authentifier si le service répond.

**FR couvertes :** FR-17, FR-18

**Notes d'implémentation :** firewall `/api` sans état, vérification de l'expiration et de
la révocation à chaque requête par `AccessTokenHandler`, refus d'un compte désactivé ou
anonymisé par `UserChecker` (AR-13). Le health check n'est complet qu'ici : son second
signal dégradé est la plus vieille entrée d'audit en ligne, livrée à l'Epic 4.

---

## Epic 1 : Cloner un socle et obtenir un ERP qui répond

Fabrice clone le socle, lance une commande, ouvre l'application et s'y connecte dans sa
langue. Un développeur qui arrive sur le dépôt sait où écrire le premier module du client
sans demander à personne.

**FR couvertes :** FR-1, FR-2, FR-3, FR-19

**Sur les estimations.** Chaque story porte une estimation du temps de travail d'un agent
d'implémentation — écriture du code et des tests jusqu'à ce que les critères passent. Elle
**n'inclut pas** les allers-retours de relecture avec Fabrice, ni le débogage
d'environnement. Une story n'est pas vérifiable de bout en bout par l'agent seul : la
**1.2** demande un runner de CI réel. Son estimation couvre l'écriture de la
configuration, pas la campagne de vérification sur l'infrastructure.

### Story 1.1 : Amorcer le dépôt et la frontière des deux racines

As a développeur qui dérive,
I want un dépôt Symfony 7.4 LTS sur PHP 8.5 où le socle et le code du client ont chacun leur racine, avec une frontière vérifiée par un outil,
So that je sache immédiatement où écrire mon code, et qu'un report de correctif reste un `git diff` sur une seule arborescence.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un clone du dépôt et PHP 8.5
**When** j'installe les dépendances et j'ouvre l'application
**Then** elle répond sur une page d'accueil minimale
**And** `composer.json` fixe Symfony 7.4 LTS et n'admet aucune dépendance exigeant Symfony 8

**Given** l'arborescence livrée
**When** je cherche où placer un service du socle ou un service métier
**Then** `src/Core/` et `src/Module/<Nom>/` portent les mêmes dossiers de couche (`Controller/`, `Service/`, `Repository/`, `Dto/{Input,Read,Output}/`, `Entity/`, `Exception/`, `EventListener/`, `Security/`, `Command/`, `Twig/`)
**And** `src/Core/Contract/` ne contient que des interfaces et des DTO, sans dépendance Doctrine ni HTTP

**Given** un module de démonstration placé dans `src/Module/`
**When** je lance l'application sans toucher à `config/` ni à `src/Core/`
**Then** ses entités sont mappées, ses routes chargées, ses services découverts et ses catalogues lus, par le glob de `config/`

**Given** une classe de `src/Module/` qui référence `App\Core\Service\…`
**When** je lance deptrac
**Then** il échoue en nommant la règle violée
**And** les quatre règles sont vérifiées : couches proglab dans chaque racine, `Core → Module` interdit, `Module → Module` interdit, `Module → Core` interdit hors `Core\Contract`

### Story 1.2 : Bloquer les régressions en intégration continue

As a développeur qui dérive,
I want que le socle arrive avec sa propre chaîne de vérification, bloquante,
So that un dérivé ne parte pas chez un client avec une régression que personne n'a vue.

**Estimation :** ~45 min de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** une tâche de qualité locale et une configuration de CI livrées avec le socle
**When** je lance l'une ou l'autre
**Then** elles vérifient la même liste : analyse statique, style, deptrac, tests, `composer audit`

**Given** un défaut introduit dans l'une de ces catégories
**When** la CI s'exécute
**Then** elle échoue et nomme la catégorie fautive

**Given** la CI qui s'exécute
**When** les tests ont besoin d'une base de données
**Then** c'est le même moteur qu'en production, jamais SQLite

**Given** un test fonctionnel
**When** il s'exécute
**Then** `dama/doctrine-test-bundle` isole sa transaction
**And** la convention du socle est d'écrire le test d'abord et de le voir rouge

### Story 1.3 : Installer le kit de composants et poser le thème du socle

As a développeur qui dérive,
I want un thème neutre complet en clair et en sombre, et le kit shadcn copié dans le dépôt,
So that je rebrande le dérivé en changeant deux choses, sans toucher à un seul écran.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** AssetMapper et Tailwind via `symfonycasts/tailwind-bundle`
**When** je construis les assets
**Then** aucun bundler ni étape Node n'est requis

**Given** `assets/styles/app.css`
**When** je le lis
**Then** il déclare les rôles shadcn neutral en clair et en sombre, plus les jetons ajoutés par le socle — `page`, `muted-strong`, `track`, `sidebar*` et les cinq alias de statut — chacun avec sa variante sombre
**And** l'échelle typographique, l'espacement, les rayons et les largeurs de contenu y sont des jetons, jamais des valeurs répétées

**Given** un dérivé qui veut sa marque
**When** il change `--primary` / `--primary-foreground` et le logo de la sidebar
**Then** tout le reste hérite
**And** aucun écran d'administration n'expose de réglage de marque
**And** aucune couleur n'est codée en dur dans un template

**Given** un défaut du kit sous un seuil WCAG (`--input`, `--ring`)
**When** je relis le thème
**Then** il est conservé tel quel et documenté comme choix assumé, jamais consigné en dette

**Given** le kit installé
**When** je liste les composants et les icônes du projet
**Then** ils viennent d'un seul kit (`--kit shadcn`) et d'une seule bibliothèque d'icônes

### Story 1.4 : Poser le gabarit de base et le plancher d'accessibilité

As a utilisateur au clavier ou au lecteur d'écran,
I want que chaque page du socle porte les mêmes repères, le même lien d'évitement et la même gestion du focus,
So that je me déplace dans l'application sans que chaque écran réinvente ses règles.

**Estimation :** ~2 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** n'importe quelle page du socle
**When** je tabule depuis le début du document
**Then** le premier élément est « Aller au contenu » vers `<main id tabindex="-1">`
**And** la page porte `<header>`, `<nav aria-label>`, `<main>`, `<footer>`, un seul `h1`, et un `<title>` « <page> — <nom du dérivé> »

**Given** une navigation Turbo
**When** la page est remplacée
**Then** le contrôleur `page-focus` place le focus sur le premier élément présent parmi : le bloc d'erreur `role="alert"`, le premier champ `aria-invalid`, le Flash, le `h1`
**And** il ignore le tout premier chargement de la session

**Given** un Flash ou un bloc d'erreur rendu
**When** il est inséré dans la page
**Then** le contrôleur `announce` en recopie le texte dans la région live persistante `#annonces`, en `assertive` s'il s'agit d'une erreur
**And** la région survit aux navigations Turbo au lieu d'être recréée

**Given** `prefers-reduced-motion: reduce`
**When** une transition du socle s'exécute
**Then** sa durée est nulle, y compris pour les transitions écrites à la main

**Given** JavaScript désactivé dans le navigateur
**When** je parcours n'importe quel chemin du socle
**Then** il fonctionne en pleine page — Turbo et Stimulus n'ajoutent que le confort
**And** aucune règle métier ne vit en JavaScript

**Given** un zoom à 400 %, soit une largeur CSS de 320 px
**When** je consulte une page
**Then** la mise en page se réorganise sans défilement horizontal du body
**And** seules les Tables et les blocs à chasse fixe défilent dans leur propre conteneur

**Given** un élément interactif, y compris une ligne de tableau cliquable ou un résumé d'accordéon
**When** il reçoit le focus au clavier
**Then** il porte l'anneau `:focus-visible`, jamais supprimé sans remplacement
**And** Échap ferme toujours l'élément flottant le plus haut et rend le focus à son déclencheur
**And** aucun raccourci clavier global n'est inventé

**Given** la CI
**When** elle s'exécute
**Then** elle vérifie automatiquement le plancher WCAG 2.1 des sept règles et échoue s'il est rompu

### Story 1.5 : Servir l'interface en français, anglais et néerlandais

As a utilisateur de la PME,
I want lire l'application dans ma langue,
So that je l'utilise sans traduire mentalement chaque libellé.

**Estimation :** ~1 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** les catalogues du socle
**When** je les lis
**Then** chaque clé existe en français, anglais et néerlandais, le français étant la langue source
**And** les catalogues sont organisés par domaine, avec des clés en anglais pointées

**Given** une langue résolue côté serveur
**When** une page est rendue
**Then** l'attribut `lang` du document la porte
**And** les dates et les nombres sont formatés dans cette locale

**Given** les langues supportées `fr`, `en`, `nl` déclarées en code
**When** je cherche lesquelles sont actives
**Then** l'information vient de la base de données, jamais d'une variable d'environnement
**And** les trois sont actives à l'installation

**Given** un chemin d'URL du socle
**When** je change de langue
**Then** le chemin ne change pas — aucun préfixe de langue, le `?lang=` de la connexion étant la seule exception

**Given** le tableau Voice and Tone du contrat UX
**When** j'écris une chaîne d'interface du socle
**Then** sa version française de référence en vient — vouvoiement, phrases courtes, jamais d'identifiant technique ni de code HTTP, un message d'erreur qui dit toujours quoi faire ensuite
**And** ces chaînes sont la source dont l'anglais et le néerlandais sont les traductions

**Given** un module qui livre ses propres catalogues
**When** il est déposé dans `src/Module/`
**Then** ses traductions sont lues sans modifier `config/` ni `src/Core/`

### Story 1.6 : Se connecter avec son email et son mot de passe

As a utilisateur actif,
I want me connecter avec mon email et mon mot de passe,
So that j'accède à l'application qui m'a été ouverte.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** les entités Utilisateur et Rôle livrées
**When** j'examine le modèle
**Then** un utilisateur porte exactement un rôle, un état actif ou désactivé, et une langue d'interface
**And** les trois rôles du socle — Super admin, Admin, User — existent comme rôles nommés, leurs permissions arrivant avec FR-7 à l'Epic 2

**Given** un utilisateur actif
**When** je saisis mon email et mon mot de passe justes sur `/login`
**Then** je suis connecté et redirigé vers la page d'accueil

**Given** un mot de passe faux ou un email inconnu
**When** je soumets le formulaire
**Then** la réponse est 422 avec un unique message générique dans un bloc `role="alert"` `tabindex="-1"` qui reçoit le focus
**And** rien ne révèle si le compte existe

**Given** un utilisateur désactivé qui saisit son mot de passe juste
**When** il soumet
**Then** il voit un message dédié disant que le compte est désactivé
**And** rien ne révèle que le mot de passe était bon

**Given** la page de connexion
**When** je la charge
**Then** elle porte un `<nav aria-label="Langue">` de liens `?lang=`, un par langue active, chacun avec `lang` et `hreflang` et l'actif en `aria-current`
**And** jamais un `<select>` qui soumettrait au changement

**Given** un mot de passe enregistré
**When** je l'inspecte en base
**Then** il est haché selon l'état de l'art et n'est jamais lisible ni réversible

### Story 1.7 : Ralentir les tentatives de connexion répétées

As a responsable de la sécurité du dérivé,
I want que les échecs de connexion répétés soient ralentis progressivement,
So that un mot de passe ne tombe pas par force brute, sans jamais enfermer un utilisateur dehors définitivement.

**Estimation :** ~45 min de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** cinq échecs de connexion consécutifs
**When** j'essaie à nouveau
**Then** le serveur refuse pendant un délai croissant
**And** le message affiche les secondes restantes lues sur le limiteur, jamais déduites d'un texte

**Given** un ralentissement en cours
**When** j'attends le délai puis réessaie avec les bons identifiants
**Then** je me connecte — aucun blocage n'est définitif, et aucun ne s'affiche comme tel

**Given** le formulaire de connexion, comme toute écriture du socle
**When** il est soumis
**Then** il porte un jeton CSRF, sans exception

**Given** le mécanisme de ralentissement
**When** j'en lis le code
**Then** il s'appuie sur le `login_throttling` natif adossé à `symfony/rate-limiter`, jamais sur un compteur écrit à la main

### Story 1.8 : Sortir l'envoi des emails de la requête

As a utilisateur qui déclenche une action envoyant un email,
I want que ma page réponde sans attendre le serveur SMTP,
So that un SMTP lent ou injoignable ne fasse pas échouer ce que je viens d'enregistrer.

**Estimation :** ~1 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un email à envoyer
**When** le cas d'usage se termine
**Then** le message est mis en file sur le transport Doctrine, avec retry et file d'échec
**And** il n'est consommable qu'après le commit de la transaction qui l'a produit

**Given** un message en file
**When** le worker le rend hors requête
**Then** le message portait tout le nécessaire en scalaires, dont la locale explicite du destinataire
**And** jamais une entité ni une dépendance au contexte de requête

**Given** l'environnement de production
**When** le dérivé est déployé
**Then** le worker tourne comme service systemd piloté par le déploiement, jamais comme étape manuelle

**Given** l'environnement de développement
**When** une action envoie un email
**Then** il arrive dans Mailpit sans configuration supplémentaire

### Story 1.9 : Réinitialiser un mot de passe oublié

As a utilisateur qui a perdu son mot de passe,
I want demander un lien de réinitialisation par email,
So that je récupère mon accès sans passer par un administrateur.

**Estimation :** ~1 h 15 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** la page `/password/forgot`
**When** je saisis une adresse, quelle qu'elle soit
**Then** le même message s'affiche — « Si un compte existe pour cette adresse, un lien vient d'être envoyé. Il est valable une heure. »
**And** rien ne révèle si le compte existe

**Given** l'entité à jeton livrée
**When** j'examine le modèle
**Then** elle porte destinataire, rôle prévu, jeton aléatoire stocké haché, date d'expiration et date d'usage
**And** l'URL transporte le jeton en clair tandis que la base n'en garde que l'empreinte
**And** aucune URL signée sans état n'est utilisée

**Given** un lien de réinitialisation reçu
**When** je l'ouvre dans l'heure et saisis deux fois mon nouveau mot de passe
**Then** il est changé et je suis renvoyé vers la connexion avec le Flash « Mot de passe changé. Connectez-vous avec le nouveau. »

**Given** un lien expiré ou déjà utilisé
**When** je l'ouvre
**Then** la page dit qu'il a expiré et propose d'en demander un nouveau, sans formulaire de saisie

**Given** des demandes de réinitialisation répétées
**When** je les enchaîne
**Then** un limiteur de débit nommé, distinct de celui de la connexion, en borne la fréquence

### Story 1.10 : Rendre les pages 403, 404 et 500 lisibles

As a utilisateur qui tombe sur une erreur,
I want une page courte qui me dise quoi faire ensuite,
So that je ne reste pas devant un message technique sans issue.

**Estimation :** ~30 min de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un accès refusé, une page inexistante ou une erreur inattendue
**When** la page s'affiche
**Then** elle porte le même gabarit court, un message traduit et un lien de retour à l'accueil
**And** aucun détail technique n'apparaît — ni code HTTP, ni trace, ni nom de classe, ni identifiant interne

**Given** une erreur inattendue
**When** elle se produit
**Then** elle est journalisée côté serveur par canal, sans donnée personnelle

**Given** Turbo qui n'obtient aucune réponse
**When** la navigation échoue
**Then** il retombe sur une navigation complète qui rend la même page

**Given** ces trois pages
**When** elles sont rendues
**Then** elles le sont par le template d'erreur Symfony, donc aussi hors Turbo

### Story 1.11 : Initialiser un dérivé en une commande

As a développeur qui démarre l'ERP d'un nouveau client,
I want une seule commande qui rende le dérivé fonctionnel,
So that j'écrive du spécifique le premier jour au lieu de rejouer une installation.

**Estimation :** ~1 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** une base de données vide
**When** je lance la commande d'initialisation
**Then** elle prépare la base, applique les migrations, charge les rôles par défaut et me demande les identifiants du premier Super admin
**And** elle termine sans autre intervention que cette saisie

**Given** la commande terminée
**When** je me connecte avec ce premier compte
**Then** j'arrive sur la page d'accueil de l'application, que FR-15 remplacera par la roadmap à l'Epic 3

**Given** une base de données déjà peuplée
**When** je lance la commande sans option
**Then** elle refuse d'agir et dit pourquoi
**And** elle n'agit qu'avec une option de confirmation explicite

**Given** le code de la commande
**When** je le relis
**Then** elle est une couche de traduction au-dessus d'un service qui porte les règles, comme l'exige le standard

### Story 1.12 : Écrire le guide de dérivation

As a développeur ou agent d'implémentation qui arrive sur le dépôt,
I want une documentation qui dise comment le socle est organisé et où ajouter un module,
So that je livre du code conforme dès le premier jour, sans demander à personne.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un agent chargé d'ajouter une entité métier
**When** il lit cette seule documentation
**Then** il y trouve l'emplacement et les conventions à respecter, sans autre source

**Given** le guide
**When** je le parcours
**Then** il nomme les skills proglab et les workflows BMAD à invoquer

**Given** le guide
**When** je cherche ce que le socle attend des fichiers BMAD
**Then** il documente la convention d'en-tête YAML des stories, que FR-14 consommera à l'Epic 3

**Given** le guide
**When** je cherche qui sauvegarde la base en production
**Then** il nomme la responsabilité du serveur client, la procédure de restauration, et l'exigence qu'elle ait été exécutée une fois avant la mise en service

**Given** le guide
**When** je cherche les limites assumées du socle
**Then** il nomme l'envoi déjà en file qui part à l'ancienne adresse après une anonymisation

### Story 1.13 : Rendre la déconnexion joignable sans jeton

As a utilisateur connecté,
I want que la déconnexion aboutisse depuis n'importe quel point de l'application,
So that je ne reste pas connecté parce qu'un jeton manque à l'URL.

**Estimation :** ~30 min de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** une session ouverte
**When** j'ouvre `/logout` sans aucun paramètre — depuis un favori, un lien recopié, une page servie par un cache
**Then** ma session est fermée et je suis redirigé, jamais refusé en 403

**Given** un lien de déconnexion rendu par `logout_path()`
**When** j'inspecte l'URL
**Then** elle ne porte plus de jeton en query string, donc plus rien à fuir dans les journaux d'accès ni dans un `Referer`

**Given** la configuration de la déconnexion
**When** je la lis
**Then** l'exception à AD-18 y est nommée et justifiée sur place — le commentaire qui invoquait l'attaque par `<img>` est remplacé par ce que `SameSite=Lax` couvre déjà et ce qu'il laisse passer

**Given** la suite de tests
**When** je cherche ce qui prouve la déconnexion
**Then** le test qui exigeait un 403 sans jeton est inversé et exige une déconnexion aboutie, et le helper qui fabriquait l'URL signée disparaît avec son dernier appelant

**Given** le plancher d'accessibilité
**When** il découvre `app_logout`
**Then** il obtient une redirection au premier appel, sans rejeu signé

**Given** toute autre écriture du socle, à commencer par le formulaire de connexion
**When** elle est soumise
**Then** elle porte toujours son jeton CSRF — l'exception ne s'étend à rien d'autre

### Story 1.14 : Refermer la surface de rebranding

As a développeur qui dérive le socle pour un client,
I want que le nom et l'icône du dérivé viennent de sa configuration et de ses fichiers de marque,
So that un rebranding complet ne laisse traîner nulle part le nom du socle.

**Estimation :** ~45 min de travail agent (implémentation et tests).

**Note.** Ce reste a été reporté trois fois — story 1.4 pour le favicon, story 1.5 pour le
`h1`, story 1.11 pour l'attribution — puis sorti du découpage de la 1.12, trop longue pour
un seul objectif. `deferred-work.md` en porte l'historique et le comment déjà tranché par
Fabrice. Le **logo de la sidebar**, troisième pièce de UX-DR-2, n'est pas ici : il arrive
avec la coque de l'application, story 2.3.

**Acceptance Criteria:**

**Given** un dérivé qui a renseigné `APP_NAME`
**When** il ouvre la page d'accueil
**Then** « Socle ERP » n'apparaît plus en dur : le `h1` nomme la page et le `<title>` nomme
la page puis le dérivé
**And** la page n'affiche plus deux noms différents

> **Amendé le 2026-09-18, pendant l'implémentation de la 1.14.** Cette AC demandait
> « le `h1` porte le nom du dérivé ». La revue a montré que cela contredit le spine UX
> (DESIGN.md l. 515 réserve le `h1` au nom de la page, EXPERIENCE.md l. 183 donne
> « Bonjour Marc… » à l'accueil) et l'ordre de focus Turbo, qui ferait annoncer le nom du
> produit à la place de la page atteinte. Fabrice a tranché pour le nom de la page ; le
> problème d'origine — deux noms sur la page, « Socle ERP » codé en dur — est résolu de
> la même façon. Trace complète dans le bloc gelé du spec de la story.

**Given** le gabarit de base
**When** un navigateur charge n'importe quelle page
**Then** il y trouve une icône déclarée, servie par AssetMapper depuis `assets/brand/`

**Given** un dérivé qui remplace le fichier d'icône de `assets/brand/`
**When** il recharge l'application
**Then** l'icône change sans qu'aucun fichier de `src/Core/` ni de `config/` ait été édité

**Given** `composer.json`
**When** je cherche ce qui rend la fonction Twig `asset()` disponible
**Then** `symfony/asset` est en `require` et non en `require-dev` — le gabarit l'appelle en production

**Given** `BaseTemplateTest`
**When** je cherche ce qui garde la surface de marque
**Then** il tient l'icône déclarée et le `h1` en `app_name` au même titre que le `<title>`

**Given** le contrat de rebranding (UX-DR-2)
**When** je le lis dans `assets/styles/brand.css`, dans `README.md` et dans `docs/DERIVATION.md`
**Then** les trois disent la même chose, et la limite « la surface de marque n'est pas encore complète » a disparu du guide

**Given** la porte de qualité
**When** `make qa` tourne
**Then** les six catégories passent sans qu'aucune cible ni aucun job n'ait été ajouté

### Story 1.15 : Corriger la découverte des services d'un module et le filet de la frontière

As a développeur qui livre le premier module d'un dérivé,
I want qu'un module se câble comme le socle et que le filet de la frontière tienne ce que ses commentaires promettent,
So that ma première erreur soit signalée par un outil, et non découverte en production.

**Estimation :** ~1 h de travail agent (implémentation et tests).

**Note.** Les deux défauts ont été trouvés en écrivant le guide de dérivation (story 1.12,
constats R12 et R6) et remontés dans `deferred-work.md` sans être corrigés : cette story
s'interdisait de toucher `config/` et `deptrac.yaml`. Le guide les nomme aujourd'hui en
« Limites assumées » ; cette story les y efface. Ils sont réunis parce qu'ils sont les deux
mêmes oublis du même mécanisme — ce qu'un module apporte au conteneur, et ce que la
frontière refuse — et parce que chacun se corrige par une ligne de configuration et un
test. Ils restent séparables si l'implémentation préfère deux lots.

**Acceptance Criteria:**

**Given** un module qui livre `Enum/`, `Exception/` ou `Message/`
**When** le conteneur se construit, dans les deux environnements de `lint:container`
**Then** ces classes ne sont pas proposées comme services, exactement comme celles du socle

**Given** le glob des modules et son miroir `when@test`
**When** je les compare à celui du socle
**Then** ils excluent la même liste de dossiers — au moins `Enum`, `Exception` et `Message` en plus de `Dto` et `Entity` — et tout écart qui subsiste est justifié sur place, là où le socle écrit déjà la raison de chacune de ses exclusions

**Given** un module de fixture qui livre l'un de ces dossiers
**When** la suite tourne
**Then** un test le prouve, et la régression ne peut plus revenir en silence

**Given** un module retiré du `must_not` d'`UndeclaredModule` sans son bloc de couche, sa ligne de ruleset ou son nom dans `AnyRoot`
**When** la suite tourne
**Then** un test rougit en nommant l'édition manquante — y compris pour les modules d'un dérivé, que rien ne vérifie aujourd'hui

**Given** les commentaires de `deptrac.yaml` qui décrivent le coût d'un module et le filet `UndeclaredModule`
**When** je les lis
**Then** ils disent ce que le filet tient réellement : oublier l'exclusion du `must_not` ne rouvre rien, mais faire l'exclusion sans déclarer le bloc de couche laisse le module sans couche de racine, donc libre d'atteindre `Core`

**Given** `docs/DERIVATION.md`
**When** je cherche ces deux limites dans « Limites assumées »
**Then** elles n'y sont plus, et la recette « ajouter un module » dit ce que le socle vérifie désormais à la place du lecteur

**Given** la porte de qualité
**When** `make qa` tourne
**Then** les six catégories passent sans qu'aucune cible ni aucun job n'ait été ajouté

**Note sur les stories 1.16 à 1.21.** Elles viennent de la rétrospective d'Epic 1
(`_bmad-output/implementation-artifacts/epic-1-retro-2026-09-19.md`, verdict
`accepted-with-open-items`) et des dix-neuf actions qu'elle a ouvertes. Aucune n'ajoute de
fonctionnalité : elles referment des coutures entre stories déjà livrées, des garde-fous
qui ne gardent rien, et des contrats écrits qui ont divergé du livré. Les arbitrages
qu'elles appliquent sont datés du 2026-09-19 et consignés dans la section « Arbitrages »
du rapport. **L'ordre compte** : la 1.16 décide où le code a le droit de vivre et peut
déplacer des classes que la 1.17 modifie ; la 1.19 est bloquante pour l'Epic 2, qui
s'appuie sur le plancher d'accessibilité à la story 2.3 et recopiera les patrons de test.
Les 1.17, 1.18 et 1.20 sont indépendantes entre elles.

### Story 1.16 : Élargir le contrat de couches aux trois dossiers sans couche

As a développeur qui relit le socle ou qui le dérive,
I want que tout `src/` soit jugé par le contrat de couches, et que le test qui le garde soit capable d'échouer,
So that changer de dossier cesse d'être un moyen de sortir du contrat.

**Estimation :** ~3 h de travail agent (implémentation et tests).

**Note.** Constats D1, D2 et D4 de la rétrospective. La zone sans couche technique
(`Command/`, `EventListener/`, `Twig/`) est passée de 0 à 21 % de `src/` en un epic, et
`LoginFailureListener` y a été écrit **pour** échapper au contrat — son propre docblock le
dit. Fabrice a tranché le 2026-09-19 : élargir plutôt qu'acter. La story vient en premier
parce qu'elle peut déplacer des classes que les suivantes modifient.

**Acceptance Criteria:**

**Given** `deptrac.yaml`
**When** je lis les couches
**Then** `EventListener/`, `Command/` et `Twig/` en ont chacun une, avec les dépendances qui leur sont légitimes — `Service`, `Response`, `Twig` — et sans accès direct à Doctrine

**Given** une classe d'`EventListener/` qui porte `EntityManagerInterface` ou `QueryBuilder`
**When** deptrac tourne
**Then** il rend au moins une violation, là où il en rendait zéro

**Given** `BoundaryTest::no_class_of_ours_escapes_every_layer()`
**When** une classe du socle n'est couverte par aucune couche technique
**Then** le test rougit en la nommant : il lit le côté source du rapport, et non seulement le côté dépendance

**Given** `LoginFailureListener`, qui importe l'environnement Twig, `DefaultLoginRateLimiter` et `Response`
**When** le contrat élargi s'applique
**Then** soit ces dépendances sont légitimes pour sa couche, soit la classe change de place — et son docblock ne peut plus invoquer « aucune couche technique »

**Given** `DerivativeInitializer::createFirstSuperAdmin()`
**When** `InitCommand` l'appelle
**Then** il reçoit un DTO de `Dto/Output/` et non l'entité `User`, et le dossier cesse d'être vide après quinze stories

**Given** les méthodes d'état du dérivé de `UserRepository`, qui vivent là parce que seule la couche `Repository` touche la couche DBAL de Doctrine
**When** le contrat élargi s'applique
**Then** soit elles y restent avec une raison écrite qui n'est plus « le contrat m'y oblige », soit elles rejoignent la couche qui leur revient

**Given** la porte de qualité
**When** `make qa` tourne
**Then** les six catégories passent, deptrac à 0 violation, sans qu'aucune cible ni aucun job n'ait été ajouté

### Story 1.17 : Recoudre le refus de connexion et son message

As a utilisateur qui vient de redéfinir son mot de passe, ou dont le compte est désactivé,
I want que l'application ne m'affirme jamais quelque chose de faux sur mon propre accès,
So that je sache quoi faire au lieu de réessayer contre un message qui ment.

**Estimation :** ~2 h 30 de travail agent (implémentation et tests).

**Note.** Constats A1, A2, A8 et A7. Ils partagent une surface — le refus de connexion et
le message qui l'accompagne — et un jeu de sondes HTTP. Arbitrage du 2026-09-19 sur A1 :
la réinitialisation purge le limiteur du compte, pas celui de l'adresse. Attention :
`LoginThrottlingTest` et `PasswordResetThrottlingTest` sont les deux tests intermittents
connus du dépôt, leurs pistes sont dans `deferred-work.md`.

**Acceptance Criteria:**

**Given** six échecs de connexion puis une réinitialisation de mot de passe réussie
**When** je me connecte avec le nouveau mot de passe
**Then** je suis connecté : `login_attempt` a été remis à zéro par la réinitialisation

**Given** la même séquence
**When** je regarde `login_address`
**Then** il n'a pas été remis à zéro, et la raison est écrite sur place — le palier d'adresse protège l'infrastructure, pas le compte

**Given** un compte désactivé qui a déjà échoué cinq fois
**When** il tente une fois de plus
**Then** il lit toujours le message dédié au statut de son compte, et non « Email ou mot de passe incorrect. Réessayez dans N secondes »

**Given** un refus fondé sur le statut du compte
**When** il se produit
**Then** il ne dépense pas une tentative du limiteur d'adresse, et un seul compte désactivé qui s'acharne ne dégrade plus la connexion de ses collègues

**Given** le Flash « Mot de passe changé » posé par `PasswordController`
**When** le visiteur est redirigé vers une page qui n'est pas `/login`
**Then** il le lit quand même, parce que le gabarit de base rend les Flashes — et il ne ressurgit pas hors contexte trois pages plus loin

**Given** le report `trusted_proxies` de `deferred-work.md`
**When** je le lis
**Then** il nomme les trois limiteurs, `password_request` compris, et dit lequel ferme un chemin de secours au lieu de le ralentir

**Given** la porte de qualité
**When** `make qa` tourne
**Then** les six catégories passent sans qu'aucune cible ni aucun job n'ait été ajouté

### Story 1.18 : Rendre la langue fiable de bout en bout

As a utilisateur dont la langue n'est pas la langue de repli,
I want que l'application me réponde dans ma langue partout, y compris quand elle échoue,
So that je n'aie pas à deviner ce qu'elle me dit au moment où j'ai un problème.

**Estimation :** ~3 h de travail agent (implémentation et tests).

**Note.** Constats A3, A4, A5 et A6. Les quatre sont le même défaut vu de quatre côtés :
la langue n'est fiable que là où `LocaleListener` a tourné. Les corriger séparément
reviendrait à écrire quatre fois la même sonde. Arbitrage du 2026-09-19 sur A6 : l'email
se replie comme l'interface.

**Acceptance Criteria:**

**Given** le sélecteur de langue et ses liens `?lang=`
**When** Turbo prefetch un de ces liens au survol
**Then** la langue de la session ne change pas — et la règle vaut pour les liens de langue futurs, pas seulement pour ceux de `/login`

**Given** une session en néerlandais
**When** je demande une URL qui n'existe pas
**Then** la page 404 est en néerlandais, et un test l'exerce sur une route **inexistante**, et non sur `/demo/errors/*`

**Given** un compte en néerlandais qui demande un lien de réinitialisation
**When** il ouvre le lien reçu, sans session
**Then** la page de réinitialisation est en néerlandais

**Given** une langue désactivée dans `ActiveLocales`
**When** un email part vers un compte qui la porte
**Then** il se replie comme l'interface, et un test porte la langue désactivée jusqu'au message mis en file

**Given** le docblock de `src/Core/Entity/User.php` qui promet le repli
**When** je le lis
**Then** il décrit ce que les deux canaux font réellement

**Given** la porte de qualité
**When** `make qa` tourne
**Then** les six catégories passent sans qu'aucune cible ni aucun job n'ait été ajouté

### Story 1.19 : Rendre les garde-fous capables d'échouer

As a développeur qui construit l'Epic 2 sur le socle,
I want que les tests qui promettent de me rattraper soient capables de rougir,
So that je ne bâtisse pas sur un filet qui laisse tout passer.

**Estimation :** ~3 h de travail agent (implémentation et tests).

**Note.** Constats C1, C2, E2 et F1. **Bloquante pour l'Epic 2** : la story 2.3 s'appuie
sur le plancher d'accessibilité, et l'Epic 2 recopiera les patrons de test avant qu'on les
unifie. Arbitrage du 2026-09-19 sur F1 : l'absence de `<footer>` se trace comme celle de
`<nav>`, plutôt que d'inventer un contenu que l'UX n'a pas spécifié.

**Acceptance Criteria:**

**Given** les règles 1 et 5 du plancher d'accessibilité
**When** aucune page rendue ne leur donne de sujet
**Then** elles rougissent au lieu de passer en silence

**Given** le squelette de dossiers de couche
**When** l'un d'eux disparaît
**Then** une liste explicite le dit, à côté du balayage qui ne peut pas le voir

**Given** les helpers de test dupliqués et les quatre patrons de bac à sable
**When** j'écris un nouveau test d'infrastructure
**Then** il y a un seul helper et un seul patron à recopier

**Given** l'absence de `<footer>` sur toutes les pages
**When** je cherche pourquoi, en grepant
**Then** une constante `FOOTER_EXEMPTION` le dit et nomme son échéance — la story 2.3 — exactement comme `NAV_EXEMPTION` le fait pour l'autre moitié de la même décision

**Given** la porte de qualité
**When** `make qa` tourne
**Then** les six catégories passent sans qu'aucune cible ni aucun job n'ait été ajouté

### Story 1.20 : Dire la vérité sur la prise en main d'un dérivé

As a développeur qui clone le socle pour la première fois,
I want que le guide décrive l'ordre qui marche et que la commande d'initialisation refuse clairement,
So that ma première heure ne se passe pas à comprendre une trace DBAL.

**Estimation :** ~2 h 30 de travail agent (implémentation et tests).

**Note.** Constats B1, B2, F2 et F6. Même surface : la commande d'initialisation, le guide
de dérivation et le contrôle de déploiement. Arbitrage du 2026-09-19 sur F2 :
`app:deployment:check` reste et reçoit une place, parce que `DERIVATION.md` s'appuie déjà
dessus à deux endroits.

**Acceptance Criteria:**

**Given** un clone frais
**When** je suis le guide de prise en main
**Then** l'ordre écrit est celui qui fonctionne — cloner, `app:init`, ouvrir — et l'AC de la story 1.1 dit la même chose

**Given** une base dont les migrations ne sont pas jouées
**When** `app:init` tourne
**Then** il lit l'état des migrations plutôt que l'existence d'une table, et refuse en une phrase plutôt qu'en trace DBAL

**Given** `app:deployment:check`, livré hors story
**When** je cherche d'où il vient
**Then** `epics.md` lui donne une place et un FR ou un AD de rattachement, et son docblock ne promet plus que la story 3.1 lui en donnera une ; même traitement, plus léger, pour `symfony/apache-pack` et le profiler

**Given** `APP_SECRET`, dont la portée a grossi avec le jeton CSRF obligatoire et le sel du limiteur
**When** un dérivé se déploie sans l'avoir renseigné
**Then** `DeploymentReadiness` le refuse, et la table « Ce qu'un dérivé configure » le nomme

**Given** la porte de qualité
**When** `make qa` tourne
**Then** les six catégories passent sans qu'aucune cible ni aucun job n'ait été ajouté

### Story 1.21 : Aligner les contrats écrits sur le livré

As a lecteur des documents de planification,
I want qu'ils décrivent ce qui est livré, et non ce qui a été envisagé puis abandonné,
So that la prochaine story ne reparte pas d'une phrase fausse.

**Estimation :** ~30 min de travail agent (documents seuls).

**Note.** Constats F4 et F5. Zéro ligne de code : séparée pour qu'un amendement de contrat
ne passe pas pour l'effet de bord d'une story de code. Arbitrage du 2026-09-19 sur F4 :
« Retour à l'accueil » reste, l'UX est amendée.

**Acceptance Criteria:**

**Given** `EXPERIENCE.md`, où le libellé apparaît quatre fois, et UX-DR-12
**When** je lis le libellé de retour des pages d'erreur
**Then** ils écrivent « Retour à l'accueil » comme les quatre templates livrés, avec la raison : le libellé nomme le rôle de la destination et non son contenu, et un dérivé dont l'accueil n'est pas la roadmap garde une phrase vraie

**Given** l'AC 5 de la story 1.14, dans ce fichier
**When** je la lis
**Then** elle ne demande plus « le `h1` en `app_name` », qui contredit l'amendement de la même story

**Given** le dépôt
**When** cette story est close
**Then** aucun fichier de `src/`, `templates/`, `assets/` ni `config/` n'a changé

---

## Epic 2 : Administrer les comptes et les droits

Sophie fait entrer ses collègues et leur donne exactement les droits qu'il faut — ni plus,
ni moins — et voit d'où vient chaque permission. Ce que l'application refuse, elle le
refuse partout.

**FR couvertes :** FR-4, FR-6, FR-7, FR-8, FR-9, FR-10, FR-22

**Note.** La story 2.3 pose la coque de l'application — sidebar, en-tête, menu du compte.
Elle complète au passage le troisième point de choix de langue de FR-19, dont le mécanisme
et les deux autres points sont livrés à l'Epic 1.

### Story 2.1 : Déclarer les ressources et projeter le catalogue des permissions

As a développeur du socle ou d'un module,
I want déclarer mes types d'objets et les opérations qu'ils supportent, en un seul endroit,
So that la grille des permissions se dérive du code au lieu d'être tenue à la main dans chaque dérivé.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un contrat unique dans `Core/Contract/`
**When** un module publie sa déclaration par service taggé
**Then** elle porte son alias de type, son nom de ressource — le même identifiant —, les opérations supportées, ses actions à code nommé, sa permission de lecture et, facultativement, la route de sa fiche

**Given** les ressources du socle déclarées
**When** je lance la commande de synchronisation
**Then** elle projette le catalogue en base sous la forme `<ressource>.create|read|update|delete`
**And** les actions hors CRUD gardent leur code nommé : `user.invite`, `user.anonymize`, `audit.export`, `roadmap.launch`, `roadmap.notes`
**And** une ressource qui ne supporte pas une opération n'en produit pas le code

**Given** deux modules qui déclarent le même nom de ressource
**When** je lance la synchronisation
**Then** elle échoue en nommant la collision, au lieu de laisser les deux se partager le code

**Given** un code exigé par un `#[IsGranted]` sans déclaration correspondante
**When** je lance la synchronisation
**Then** elle échoue en nommant le code manquant

**Given** un code présent en base mais que plus aucune ressource ne déclare
**When** je consulte le catalogue
**Then** il apparaît comme obsolète et n'est jamais accordé

### Story 2.2 : Calculer et appliquer la permission effective

As a responsable de la sécurité du dérivé,
I want que chaque action et chaque zone vérifie la permission effective de l'utilisateur,
So that ce que l'application refuse, elle le refuse partout et de la même façon.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un utilisateur avec son rôle et ses surcharges
**When** une permission est évaluée
**Then** l'effective est le rôle moins les retraits plus les ajouts, calculée à chaque requête
**And** elle est lue par un voter unique, mémoïsée dans un service dédié à durée de requête
**And** aucun code ne teste un nom de rôle

**Given** une zone protégée dont je n'ai pas la permission
**When** j'y accède
**Then** la réponse est 403 avec un libellé traduit

**Given** un objet que je n'ai pas le droit de consulter
**When** j'en demande l'adresse
**Then** la réponse est 404, indistinguable d'un objet inexistant

**Given** un objet que je peux consulter mais pas modifier
**When** je tente de le modifier
**Then** la réponse est 403 — l'objet m'est déjà connu, le masquer ne m'apprendrait rien

**Given** une route du socle
**When** j'en lis la protection
**Then** `access_control` porte le filet par zone et `#[IsGranted]` la précision par action — les deux, jamais l'un seul

### Story 2.3 : Poser la coque de l'application

As a utilisateur connecté,
I want une navigation qui ne me montre que les zones auxquelles j'ai droit, et un menu pour mon compte,
So that je ne bute pas sur des refus et je change de langue ou de thème sans chercher.

**Estimation :** ~2 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** la sidebar
**When** elle est rendue
**Then** c'est un `<nav aria-label="Navigation principale">` avec une `<ul>` par groupe, chacune `aria-labelledby` sur son intitulé « Suivi » ou « Administration »
**And** l'entrée courante porte `aria-current="page"`
**And** un groupe vide n'est pas rendu

**Given** un utilisateur sans la permission d'une entrée
**When** la sidebar est rendue
**Then** l'entrée n'est pas rendue du tout, le filtrage étant fait côté serveur
**And** l'absence d'entrée ne protège rien par elle-même : la route vérifie quand même

**Given** une largeur sous `lg`
**When** j'ouvre la navigation
**Then** elle devient un tiroir ouvert par un bouton hamburger portant `aria-expanded`, fermé par Échap, par un clic hors panneau ou par une navigation
**And** le focus revient au hamburger

**Given** le menu du compte dans l'en-tête
**When** je l'ouvre
**Then** il porte Profil, Langue, Thème et Se déconnecter, Langue et Thème étant des groupes `menuitemradio` avec `aria-checked`
**And** chaque option de langue porte son propre attribut `lang`

**Given** un choix de thème clair, sombre ou système
**When** je le sélectionne
**Then** la page bascule immédiatement sans clignotement, le choix est persisté sur mon compte par un POST, et la classe est rendue côté serveur à la requête suivante

### Story 2.4 : Composer les rôles depuis la grille des permissions

As a administrateur,
I want voir et modifier les permissions d'un rôle dans une grille lisible, et créer mes propres rôles,
So that j'adapte les droits du dérivé à l'organisation du client sans toucher au code.

**Estimation :** ~2 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** le socle installé
**When** j'ouvre la liste des rôles
**Then** Super admin, Admin et User y sont, avec les permissions que le socle leur donne
**And** ces trois rôles n'ont pas de bouton « Supprimer », et une note l'explique

**Given** la fiche d'un rôle
**When** je la consulte
**Then** elle présente une ligne par ressource et une colonne par opération, plus les actions à code nommé que la ressource déclare
**And** la grille est dérivée du catalogue, aucune liste n'étant tenue à la main
**And** une ressource qui ne supporte pas une opération n'en propose pas la case

**Given** une grille modifiée
**When** j'enregistre par le bouton explicite
**Then** le changement s'applique immédiatement à tous les utilisateurs du rôle, sauf là où une surcharge existe

**Given** un rôle
**When** j'en lis les attributs
**Then** il porte un booléen « exige la double authentification », que FR-5 appliquera à l'Epic 5

**Given** un rôle que j'ai créé et que des utilisateurs portent
**When** je le supprime
**Then** un Dialog nomme le nombre d'utilisateurs concernés et le rôle de repli avant que je confirme

### Story 2.5 : Lister les utilisateurs et ouvrir leur fiche

As a administrateur,
I want une liste des comptes du dérivé et une fiche par personne,
So that je sache qui a accès à l'outil et dans quel état.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** la liste des utilisateurs
**When** elle est rendue
**Then** la Table vit dans une Card, sans zébrure, à la même densité que le reste
**And** le lien de chaque ligne vit dans la première cellule, porte un nom complet — le complément en `sr-only` — et un `::after` étend la zone cliquable à la ligne

**Given** la liste
**When** je trie par nom, rôle ou état
**Then** l'en-tête est un lien portant `aria-sort`, et l'URL porte le tri
**And** la pagination est un `<nav aria-label="Pagination">` avec Précédent, Suivant et « Page n sur m », jamais un défilement infini

**Given** une largeur sous `md`
**When** je consulte la liste
**Then** seules les colonnes Nom, Rôle et État restent, et un appui sur la ligne ouvre la fiche où tout est visible

**Given** un dérivé où je suis seul
**When** j'ouvre la liste
**Then** elle affiche « Vous êtes seul pour l'instant. Invitez un premier collègue. » avec un bouton d'invitation

**Given** ma propre fiche
**When** je la consulte
**Then** les actions de désactivation et d'anonymisation et le sélecteur de rôle ne sont pas rendus — seul un autre administrateur peut agir sur moi

### Story 2.6 : Inviter un collègue par email

As a administrateur,
I want inviter une personne par email en lui donnant un rôle,
So that elle crée son compte elle-même sans que j'aie à lui fabriquer un mot de passe.

**Estimation :** ~2 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** le formulaire d'invitation
**When** je saisis email, prénom, nom et rôle et j'envoie
**Then** un lien à usage unique et expirable part par email, dans la langue par défaut du dérivé
**And** la ligne de la personne apparaît à l'état « Invité »
**And** seuls les administrateurs voient et utilisent cette fonction

**Given** un lien d'invitation valide
**When** la personne l'ouvre et crée son mot de passe
**Then** son compte devient actif avec le rôle prévu, sa session s'ouvre, et elle arrive sur l'accueil avec un message de bienvenue

**Given** un lien expiré, déjà utilisé, ou un compte déjà actif
**When** la personne l'ouvre
**Then** la page l'explique sans formulaire, et propose de demander un nouveau lien ou de se connecter selon le cas

**Given** une invitation en attente ou expirée
**When** je renvoie l'invitation depuis la fiche
**Then** un Dialog nomme la personne et prévient que l'ancien lien sera invalidé
**And** après confirmation le précédent lien ne fonctionne plus

**Given** une adresse qui appartient déjà à un compte actif, désactivé, ou déjà invité
**When** je soumets le formulaire
**Then** la réponse est 422 avec une erreur sous le champ email qui nomme la personne et propose l'action juste — voir sa fiche, la réactiver, ou renvoyer l'invitation
**And** rien n'est envoyé

### Story 2.7 : Désactiver et réactiver un compte

As a administrateur,
I want retirer l'accès d'une personne sans rien supprimer de ce qu'elle a fait,
So that l'historique du dérivé reste intact quand quelqu'un s'en va.

**Estimation :** ~1 h 15 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** la fiche d'un utilisateur actif
**When** je clique sur « Désactiver »
**Then** un Dialog nomme la personne et la conséquence — elle ne pourra plus se connecter, ses sessions en cours seront closes, rien ne sera supprimé
**And** le bouton de confirmation soumet un POST porteur d'un jeton CSRF

**Given** un utilisateur qui vient d'être désactivé
**When** sa session en cours fait sa requête suivante
**Then** il est déconnecté et renvoyé vers la connexion avec le message « Vous avez été déconnecté. »
**And** le mécanisme est la comparaison d'un jeton de sécurité porté par le compte, sans second magasin de sessions

**Given** un compte désactivé
**When** je le consulte dans la liste ou sur sa fiche
**Then** il porte le badge « Compte désactivé », avec son icône et son libellé
**And** les objets qu'il a créés restent intacts et le désignent toujours par son nom

**Given** un compte désactivé
**When** je clique sur « Réactiver »
**Then** il redevient actif et peut se connecter

**Given** le socle entier
**When** je cherche une fonction qui supprime un utilisateur
**Then** il n'en existe aucune, ni en interface ni en commande

### Story 2.8 : Surcharger les permissions d'un utilisateur et en voir l'origine

As a administrateur,
I want ajouter ou retirer une permission à une personne précise et voir d'où vient chacune,
So that je donne exactement les droits qu'il faut sans créer un rôle pour une exception.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** la section Permissions d'une fiche utilisateur
**When** je la consulte
**Then** elle présente la même grille que l'écran de rôle — ressources × opérations, plus les actions à code nommé
**And** chaque case porte son état effectif et un badge d'origine : « Héritée du rôle », « Ajoutée » ou « Retirée »

**Given** une permission que le rôle n'accorde pas
**When** je clique sur « Ajouter » sur sa ligne
**Then** un POST l'accorde, je suis redirigé, et un Flash nomme la personne
**And** elle reste accordée même si le rôle ne l'accorde toujours pas

**Given** une permission que le rôle accorde
**When** je la retire par surcharge
**Then** elle reste absente même si le rôle continue de l'accorder

**Given** une case portant une surcharge
**When** je clique sur « Revenir à l'héritage »
**Then** la surcharge disparaît et la case reprend ce que le rôle prévoit

**Given** un utilisateur dont les droits viennent de changer
**When** il charge sa page suivante
**Then** sa navigation reflète immédiatement ses nouveaux droits, sans reconnexion

### Story 2.9 : Activer ou désactiver une langue du dérivé

As a administrateur,
I want retirer une langue dont le client n'a pas besoin,
So that ses utilisateurs ne se voient pas proposer un choix qui n'a pas de sens chez eux.

**Estimation :** ~1 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** l'écran des langues
**When** je le consulte
**Then** il montre une ligne par langue supportée avec son état et un bouton « Désactiver » ou « Réactiver »

**Given** une langue active utilisée par des personnes
**When** je clique sur « Désactiver »
**Then** un Dialog nomme la langue, le nombre d'utilisateurs concernés et la langue de repli avant que je confirme

**Given** une langue désactivée
**When** un utilisateur qui l'avait choisie charge sa page suivante
**Then** elle est rendue dans la langue de repli, avec un message qui le lui dit
**And** la langue n'est plus proposée nulle part, ni à la connexion, ni au profil, ni dans le menu du compte

**Given** la dernière langue active
**When** je consulte sa ligne
**Then** elle n'a pas de bouton « Désactiver », et une note l'explique

---

## Epic 3 : Montrer au client où en est son ERP

Marc ouvre son ERP le vendredi soir et comprend en cinq secondes où en est sa construction,
sans avoir jamais entendu le mot BMAD. Sur son poste, Léa lance la tâche suivante depuis la
même page.

**FR couvertes :** FR-14, FR-15, FR-16

**Note.** La story 3.1 vient de l'Epic 1, déplacée ici sur décision de Fabrice : c'est le
déploiement qui produit la date de release que la roadmap affiche, et ce sont les fichiers
BMAD embarqués dans la release que la roadmap lit. Elle n'est pas vérifiable de bout en
bout par un agent seul — il lui faut un serveur cible accessible en SSH.

### Story 3.1 : Déployer le dérivé chez le client

As a développeur qui livre un dérivé,
I want un déploiement reproductible par SSH,
So that la mise en production ne soit pas une suite de gestes manuels différents à chaque fois.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** la configuration Deployer livrée avec le socle
**When** je déploie
**Then** l'arborescence `releases/` · `current/` · `shared/` est respectée, les migrations s'exécutent, et le worker Messenger est redémarré

**Given** une release déployée
**When** je demande la date du dernier déploiement
**Then** c'est celle de la release, que la story 3.3 affichera sur la roadmap

**Given** une release déployée
**When** je cherche les fichiers BMAD sur le serveur
**Then** ils font partie de ce qui a été déployé, en lecture seule, `var/` étant le seul répertoire inscriptible

**Given** la production
**When** je regarde la configuration serveur
**Then** Apache et PHP-FPM 8.5 servent l'application avec un `php.ini` de production et le préchargement OPcache

**Given** un secret en production
**When** je cherche où il vit
**Then** c'est dans le coffre de secrets Symfony, `SYMFONY_DECRYPTION_SECRET` étant le seul secret vivant hors du dépôt

**Given** un déploiement qui a mal tourné
**When** je veux revenir en arrière
**Then** la procédure de rollback est documentée et exécutable

### Story 3.2 : Lire les fichiers BMAD et en rendre des DTO

As a développeur du socle,
I want un lecteur unique qui transforme les fichiers BMAD en objets utilisables, sans base intermédiaire,
So that la roadmap ne devienne pas une seconde vérité à tenir à jour.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** `_bmad-output/implementation-artifacts/sprint-status.yaml`
**When** le lecteur le parse
**Then** les clés `epic-{n}` donnent une epic et `{n}-{m}-{titre}` une tâche
**And** les clés `epic-{n}-retrospective` sont exclues du calcul d'avancement

**Given** l'en-tête YAML d'un fichier de story
**When** le lecteur le lit
**Then** il en tire priorité, difficulté, assignation et dépendances selon la convention que le socle documente
**And** un champ absent se rend « non renseigné »
**And** une tâche sans dépendance déclarée est prête dès que son statut est « à faire »

**Given** le lecteur
**When** j'en cherche les appelants
**Then** il vit dans `Core/Service/`, rend des DTO Output, et aucun contrôleur, template ou module ne lit les fichiers directement

**Given** un parse réussi
**When** je recharge la page
**Then** le résultat vient du cache, invalidé par la version de release en production et par la date de modification en développement

**Given** l'application en fonctionnement
**When** je vérifie les écritures sur le disque
**Then** elle n'écrit jamais dans les fichiers BMAD ni ailleurs dans le dépôt

### Story 3.3 : Afficher la roadmap comme page d'accueil

As a gérant de la PME,
I want voir où en est la construction de mon ERP dès que je me connecte,
So that je sache ce qui avance sans rien comprendre à la méthode de l'équipe.

**Estimation :** ~2 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un utilisateur qui a la permission de voir la roadmap
**When** il se connecte
**Then** la roadmap est sa page d'accueil
**And** elle porte la pastille « Dernier déploiement : … » et la note de pied disant que la page reflète le dernier déploiement, pas le temps réel

**Given** une epic
**When** elle est rendue
**Then** son avancement est le rapport des tâches terminées sur le total, montré par un chiffre, la phrase « n sur m tâches terminées » et une barre `aria-hidden` rendue à sa valeur finale, sans animation
**And** son titre est un `h2` dont le bouton ne contient que le titre, badge et avancement restant hors du bouton

**Given** les statuts BMAD
**When** ils sont rendus
**Then** `backlog` et `ready-for-dev` deviennent « à faire », `in-progress` et `review` deviennent « en cours », `done` devient « terminé »
**And** chaque statut est une icône, un libellé et une forme de bordure — jamais une couleur seule

**Given** une tâche qui dépend d'une autre
**When** elle est rendue
**Then** elle nomme la tâche attendue et son statut
**And** chaque tâche montre sa priorité, sa difficulté et la personne assignée, ou « non renseigné »

**Given** un dérivé dont la roadmap est vide
**When** je l'ouvre
**Then** elle affiche « Votre roadmap est vide pour l'instant… », et en développement une ligne supplémentaire indique où les fichiers BMAD sont attendus

**Given** JavaScript désactivé
**When** je déplie une epic
**Then** le dépliage passe par un `<details>` natif

**Given** la roadmap
**When** je l'imprime
**Then** elle s'imprime sans la sidebar ni l'en-tête

### Story 3.4 : Signaler une roadmap partielle aux administrateurs

As a administrateur du dérivé,
I want savoir qu'un fichier n'a pas pu être lu, et lequel,
So that je le corrige au lieu de croire que le projet a rétréci.

**Estimation :** ~45 min de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un fichier BMAD absent ou mal formé
**When** j'ouvre la roadmap
**Then** elle s'affiche partiellement, jamais en page d'erreur

**Given** cette roadmap partielle et un rôle d'administrateur
**When** je la consulte
**Then** une Alert nomme le fichier et la ligne fautive, en `role="region"` avec son titre `h3` — jamais un `role="alert"` présent au chargement
**And** l'epic concernée garde sa place, avec le badge « Non lisible » et un avancement « — », sans chiffre inventé

**Given** cette même roadmap partielle et un rôle sans droit d'administration
**When** je la consulte
**Then** je vois la roadmap sans l'epic illisible et sans aucun message

**Given** une tâche dont le statut BMAD est inconnu
**When** elle est rendue
**Then** elle apparaît comme « à faire » et le même avertissement la signale aux administrateurs

### Story 3.5 : Montrer les notes internes à qui en a la permission

As a membre de l'équipe de développement,
I want lire le détail d'une tâche depuis la roadmap,
So that je sache ce qu'elle recouvre sans ouvrir un fichier du dépôt.

**Estimation :** ~45 min de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un utilisateur portant la permission `roadmap.notes`
**When** il ouvre une tâche
**Then** les notes internes se déplient sous les méta, derrière un bouton, repliées par défaut

**Given** un utilisateur sans cette permission
**When** il consulte la même tâche
**Then** ni le bouton ni le contenu ne sont rendus

**Given** le socle installé
**When** je regarde qui reçoit cette permission par défaut
**Then** seul le rôle Super admin la porte

### Story 3.6 : Lancer une tâche prête en environnement de développement

As a développeuse qui travaille sur le dérivé,
I want lancer la tâche suivante depuis la roadmap,
So that je démarre le travail sans ouvrir un fichier YAML ni retaper une commande.

**Estimation :** ~1 h 15 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** l'environnement de production
**When** je cherche la fonction de lancement
**Then** il n'y a ni bouton, ni point d'entrée, ni route enregistrée
**And** un test vérifie l'absence de la route en production, le service et le contrôleur portant `#[When('dev')]` et la route `env: 'dev'`

**Given** l'environnement de développement et une tâche prête
**When** je clique sur « Lancer »
**Then** un POST démarre les agents dans un processus local détaché
**And** un Flash confirme le lancement et m'invite à recharger la page

**Given** une tâche dont une dépendance n'est pas terminée
**When** je regarde son bouton
**Then** il est inactif au sens `aria-disabled`, reste focusable, et pointe par `aria-describedby` vers la raison visible qui nomme la dépendance et son statut
**And** le serveur refuse le POST même s'il est forcé

**Given** une tâche déjà en cours
**When** je consulte sa ligne
**Then** elle n'a pas de bouton de lancement

**Given** un lancement qui échoue
**When** la page revient
**Then** une alerte destructive en donne la raison en clair — commande introuvable, ou processus non démarré — sans trace ni code de sortie

---

## Epic 4 : Répondre à « qui a fait ça ? »

Sophie répond seule à « qui a changé ce tarif ? » en moins d'une minute et exporte la
réponse pour son comptable. Le journal reste lisible et rapide après un an, sans que
personne n'ait rien supprimé — et une demande d'effacement est satisfaite sans perdre
l'historique.

**FR couvertes :** FR-11, FR-12, FR-13, FR-23, FR-20

**Note sur l'ordre.** L'archive (4.4) précède la lecture (4.5) délibérément. AD-23 veut
que le dépôt d'audit soit le seul endroit du système qui sache qu'il y a deux tables ;
écrire la lecture sur une seule table puis la rouvrir pour y greffer l'union reviendrait à
construire deux fois le même dépôt. La story 4.3 câble la liste de référence de FR-12 sur
tout ce que les epics 1 à 3 ont livré : c'est ici que les clauses « crée une entrée
d'audit » des FR précédentes sont honorées.

### Story 4.1 : Écrire une entrée d'audit à chaque modification d'objet

As a administrateur du dérivé,
I want que toute création, modification ou suppression laisse une trace de qui, quand et quoi,
So that aucune question sur l'historique ne reste sans réponse.

**Estimation :** ~2 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un objet métier modifié
**When** la transaction est validée
**Then** une entrée porte l'auteur, l'horodatage, l'objet, et pour chaque champ modifié l'ancienne et la nouvelle valeur
**And** l'entrée est écrite dans la même transaction que la donnée, par l'`UnitOfWork` en cours, sans second `flush()`

**Given** une transaction annulée
**When** je consulte le journal
**Then** l'entrée a disparu avec la donnée — aucune entrée ne décrit un état qui n'a pas existé

**Given** l'objet d'une entrée
**When** je regarde comment il est référencé
**Then** c'est par un alias de type stable, sa clé primaire et une étiquette figée à l'écriture — jamais un nom de classe ni une classe de proxy
**And** l'acteur est référencé par clé étrangère vers le compte, jamais recopié

**Given** un mot de passe ou un secret de 2FA modifié
**When** je consulte l'entrée correspondante
**Then** le champ n'y figure pas — il est exclu à l'écriture par une liste tenue dans `Core` et extensible par module, jamais masqué à l'affichage

**Given** une modification faite par une commande ou un traitement automatique
**When** je consulte son entrée
**Then** elle est attribuée à un acteur système nommé

**Given** l'architecture de l'écriture
**When** j'en lis le code
**Then** un service unique porte toutes les règles et un listener Doctrine `onFlush` ne fait que lui passer les changesets, sans rien décider

### Story 4.2 : Consigner une action métier nommée depuis la couche service

As a développeur d'un module métier,
I want consigner un événement nommé qu'aucun changement de champ ne décrit,
So that le journal raconte ce qui s'est passé, pas seulement ce qui a bougé en base.

**Estimation :** ~45 min de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un cas d'usage métier
**When** je veux le consigner
**Then** un seul appel depuis la couche service suffit, avec un code stable, un libellé traduisible et l'objet concerné
**And** l'appel n'est jamais fait depuis un contrôleur

**Given** une action métier consignée
**When** je consulte le journal
**Then** elle apparaît aux côtés des modifications, dans la même liste et sous les mêmes filtres

**Given** une action consignée avant un rollback
**When** la transaction est annulée
**Then** l'action disparaît avec elle

### Story 4.3 : Câbler les actions déjà livrées par le socle

As a administrateur du dérivé,
I want que ce que le socle fait déjà — connexions, invitations, surcharges, désactivations — apparaisse dans le journal,
So that l'historique ne commence pas au jour où l'audit a été branché.

**Estimation :** ~1 h 15 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** la liste de référence des actions du socle
**When** je la parcours dans le journal
**Then** j'y trouve : connexion, échec de connexion, mot de passe changé ou réinitialisé, invitation envoyée et acceptée, surcharge de permission, permissions d'un rôle modifiées, désactivation et réactivation, langue activée ou désactivée
**And** les actions de 2FA et de jeton d'API rejoindront la liste avec les epics 5 et 6

**Given** les actions d'authentification
**When** je regarde d'où elles sont déclarées
**Then** c'est depuis la couche sécurité — l'unique exception nommée à la règle « depuis la couche service »

**Given** un échec de connexion
**When** je consulte son entrée
**Then** elle enregistre l'identifiant tenté et n'a pas d'acteur référencé, cet identifiant ne correspondant pas nécessairement à un compte

### Story 4.4 : Archiver les entrées au-delà de la fenêtre en ligne

As a administrateur du dérivé,
I want que le journal en ligne reste borné sans que rien ne soit perdu,
So that l'écran reste rapide après un an d'usage et que l'historique complet reste disponible.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** la table d'archive
**When** j'en compare la forme à celle de la table en ligne
**Then** elles ont les mêmes colonnes, la même normalisation des valeurs et les mêmes index
**And** une entrée déplacée garde sa clé primaire, qui n'est jamais réattribuée

**Given** des entrées plus vieilles que la fenêtre
**When** la commande d'archivage s'exécute
**Then** elles sont déplacées par lots de taille paramétrée, chaque lot étant une seule transaction portant l'insertion et la suppression
**And** la fenêtre est un paramètre `app.`, jamais une constante

**Given** un archivage interrompu en plein travail
**When** je compte les entrées
**Then** aucune n'est en double ni perdue — chacune est soit en ligne, soit archivée

**Given** la commande d'archivage
**When** j'en lis le code
**Then** les règles vivent dans un service et la commande n'est qu'une couche de traduction
**And** elle écrit en SQL depuis le dépôt et non par l'ORM, les deux tables d'audit étant exclues de l'audit par construction

**Given** l'ordonnancement
**When** je regarde ce qui déclenche la commande
**Then** c'est le Scheduler, dans son propre schedule et sur son propre worker, jamais mêlé à la file des emails
**And** elle est verrouillée contre tout recouvrement avec elle-même
**And** elle rend compte du nombre d'entrées déplacées, attribuée à l'acteur système

### Story 4.5 : Consulter et filtrer le journal d'audit

As a responsable administrative,
I want retrouver qui a fait quoi, en filtrant sans connaissance technique,
So that je réponde moi-même aux questions sur l'historique.

**Estimation :** ~2 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** l'écran du journal
**When** je filtre
**Then** je dispose de la période, de l'auteur, du type d'objet, de l'objet précis et du type d'entrée
**And** la période est un `fieldset` avec sa légende et deux champs labellisés « Du » et « Au », le type d'entrée un `fieldset` de radios
**And** le filtrage se déclenche par un bouton explicite, jamais à la frappe, et l'URL porte les filtres

**Given** une sélection qui traverse la fenêtre en ligne et l'archive
**When** la page est rendue
**Then** je n'ai jamais eu à choisir où chercher — le dépôt interroge les deux tables avec le même prédicat et les réunit en SQL, trie sur `(horodatage, id)`, pagine et compte sur l'union
**And** une entrée déplacée pendant la requête est dédupliquée sur sa clé primaire

**Given** une recherche qui remonte au-delà de la fenêtre en ligne
**When** je la lance
**Then** l'écran annonce la période demandée et prévient qu'elle peut être plus lente — une information sur l'attente, pas sur le rangement

**Given** une page de journal
**When** je mesure ses requêtes
**Then** les étiquettes et libellés sont résolus par lot pour la page entière, et un test d'intégration échoue si le nombre de requêtes croît avec le nombre de lignes
**And** les cinq axes de filtre sont indexés sur les deux tables

**Given** une entrée dont je n'ai pas la permission de lire le type d'objet
**When** je consulte le journal
**Then** elle ne m'est pas rendue — le journal ne contourne pas les refus appliqués ailleurs

**Given** des filtres qui ne ramènent rien
**When** la page est rendue
**Then** un message d'état vide m'invite à élargir la période ou à retirer un filtre
**And** les filtres saisis sont conservés

**Given** une sélection filtrée
**When** je l'imprime
**Then** elle s'imprime sans la sidebar ni l'en-tête

**Given** le journal
**When** je cherche à modifier ou supprimer une entrée
**Then** aucune interface ne le permet

### Story 4.6 : Ouvrir le détail d'une entrée

As a responsable administrative,
I want voir la valeur avant et après sans quitter ma liste filtrée,
So that je vérifie un changement précis et revienne à ma recherche.

**Estimation :** ~1 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** une ligne du journal
**When** je clique dessus
**Then** un panneau s'ouvre à droite avec l'auteur, l'horodatage complet, l'objet, et la table avant / après champ par champ — ou le libellé de l'action métier
**And** les filtres et la pagination restent derrière, intacts

**Given** ce panneau
**When** je le ferme par Échap, par le bouton ou par le voile
**Then** le focus revient à la ligne d'où je suis partie

**Given** l'adresse du panneau
**When** je la partage ou l'ouvre directement
**Then** elle rend la page complète avec le panneau ouvert
**And** elle reste valable après l'archivage de l'entrée, dont la clé n'a pas changé

**Given** l'objet d'une entrée
**When** il est rendu
**Then** c'est un lien vers sa fiche seulement si son module a déclaré une route **et** que j'ai le droit de le consulter
**And** c'est du texte simple dans tous les autres cas — objet supprimé, droit manquant, aucune route déclarée
**And** le socle n'en déclare aucune pour ses propres objets

**Given** un auteur désactivé ou anonymisé
**When** je lis l'entrée
**Then** son nom reste, suivi de la mention correspondante

### Story 4.7 : Exporter la sélection filtrée

As a responsable administrative,
I want emporter ce que je viens de trouver dans un fichier,
So that je le transmette au comptable sans faire de copier-coller.

**Estimation :** ~1 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** une sélection filtrée
**When** je clique sur « Exporter la sélection »
**Then** un CSV reprenant exactement les filtres courants se télécharge, archive comprise
**And** il n'existe pas un second export pour l'archive

**Given** un export volumineux
**When** il s'exécute
**Then** il est streamé par lots, hors Turbo, sans charger la sélection en mémoire

**Given** l'export
**When** je regarde ce qu'il contient
**Then** il passe par la même lecture filtrée par les droits que l'écran — la permission d'export autorise à emporter ce que je vois déjà, jamais à voir plus

**Given** un utilisateur sans la permission d'export
**When** il consulte le journal
**Then** le bouton n'est pas rendu, et la route refuse la requête

### Story 4.8 : Anonymiser un utilisateur désactivé

As a administrateur,
I want répondre à une demande d'effacement sans perdre l'historique,
So that le droit de la personne soit satisfait et que le journal reste vrai.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un compte actif
**When** je tente de l'anonymiser
**Then** c'est refusé — il faut d'abord le désactiver

**Given** un compte désactivé
**When** je clique sur « Anonymiser »
**Then** un Dialog nomme la personne, décrit la conséquence, prévient que l'action est irréversible et exige la saisie du mot « ANONYMISER »

**Given** l'anonymisation confirmée
**When** elle s'exécute
**Then** nom et email sont remplacés par un identifiant neutre partout où l'alias et la clé de ce compte apparaissent — y compris dans l'étiquette d'une entrée qui décrit un autre objet
**And** elle atteint la table en ligne **et** l'archive, dans une seule transaction, et rend compte des deux nombres d'entrées réécrites

**Given** l'anonymisation terminée
**When** je consulte le journal
**Then** les entrées et les objets créés subsistent et désignent la personne par l'identifiant neutre
**And** l'opération a elle-même créé une entrée d'audit
**And** c'est le seul chemin d'écriture autorisé sur une entrée existante, et il vit dans le dépôt d'audit

**Given** un email déjà mis en file avant l'anonymisation
**When** le worker le traite
**Then** il part à l'ancienne adresse — limite nommée et acceptée, documentée par le guide de dérivation
**And** un message en échec attendant d'être rejoué est en revanche abandonné

---

## Epic 5 : Sécuriser l'accès de chaque utilisateur

Aucun compte à pouvoir n'est protégé par un seul mot de passe, et chaque utilisateur gère
lui-même sa langue, son mot de passe et son second facteur.

**FR couvertes :** FR-5, FR-21

**Note.** L'audit existant depuis l'Epic 4, chaque story de cette epic consigne elle-même
ses actions — 2FA activée, 2FA réinitialisée, mot de passe changé.

### Story 5.1 : Gérer sa langue et son mot de passe depuis son profil

As a utilisateur de la PME,
I want changer ma langue et mon mot de passe moi-même,
So that je n'aie pas à déranger un administrateur pour un réglage qui me concerne seul.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** le profil
**When** je le consulte
**Then** ses sections sont un `<nav aria-label="Sections du profil">` de liens vers `/profile/{tab}`, l'onglet courant portant `aria-current="page"`
**And** on garde le visuel Tabs du kit sans sa sémantique `role="tab"` — l'URL est l'état, il n'y a pas d'état client

**Given** l'onglet Langue
**When** je choisis une langue parmi les actives
**Then** l'interface passe dans cette langue et le choix est mémorisé sur mon compte
**And** mes emails me parviennent désormais dans cette langue

**Given** l'onglet Mot de passe
**When** je change mon mot de passe
**Then** l'ancien m'est demandé
**And** mes autres sessions sont closes, la mienne restant ouverte
**And** l'action est consignée au journal d'audit

**Given** une largeur sous `md`
**When** je consulte le profil
**Then** ses onglets défilent horizontalement sans que la page défile

### Story 5.2 : Enrôler un second facteur

As a utilisateur,
I want ajouter un second facteur à mon compte,
So that mon mot de passe seul ne suffise pas à entrer chez moi.

**Estimation :** ~2 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** l'enrôlement
**When** je le commence
**Then** deux méthodes au moins me sont proposées — application d'authentification et code par email

**Given** la méthode par application
**When** je scanne le QR code et saisis le code affiché
**Then** le second facteur est activé et je suis renvoyé au profil avec la confirmation
**And** l'activation est consignée au journal d'audit

**Given** une vérification qui échoue à l'enrôlement
**When** je resoumets
**Then** la réponse est 422, le QR code et le **même** secret sont toujours affichés, et l'erreur est sous le champ
**And** les codes de secours ne sont générés qu'après une vérification réussie

**Given** un enrôlement réussi
**When** les codes de secours s'affichent
**Then** ils sont dans une Alert `role="region"` avec un bouton de téléchargement

**Given** l'onglet Double authentification du profil
**When** ma 2FA est active
**Then** je peux régénérer mes codes de secours et changer de méthode, en fournissant mon second facteur en cours ou un code de secours
**And** si mon rôle l'exige, le bouton « Désactiver » n'est pas rendu et une note l'explique

### Story 5.3 : Fournir son second facteur à la connexion

As a utilisateur dont la 2FA est active,
I want donner mon second facteur après mon mot de passe,
So that ma connexion reste protégée même si mon mot de passe a fuité.

**Estimation :** ~1 h 30 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un compte dont la 2FA est active, quel que soit son rôle
**When** je me connecte avec mon mot de passe
**Then** le second facteur m'est demandé avant tout accès

**Given** la page de vérification
**When** je la consulte
**Then** elle porte un seul champ texte avec son label visible, `inputmode="numeric"` et `autocomplete="one-time-code"` — jamais six cases
**And** rien ne se soumet automatiquement : j'appuie sur Entrée ou sur « Vérifier »

**Given** un code incorrect
**When** je soumets
**Then** la réponse est 422 avec l'erreur sous le champ, le focus sur le champ et la valeur effacée
**And** les échecs répétés sont ralentis par un limiteur nommé qui lui est propre

**Given** un code reçu par email
**When** il a dépassé sa validité, comptée depuis son émission
**Then** un bouton me propose d'en recevoir un nouveau, ce qui invalide l'ancien

**Given** un second facteur inaccessible
**When** je suis le lien « utiliser un code de secours »
**Then** une page distincte porte le même champ
**And** un code accepté me connecte en m'indiquant combien il m'en reste
**And** le dernier code utilisé déclenche une alerte persistante jusqu'à ce que j'en génère de nouveaux
**And** sans code restant, la page m'oriente vers un administrateur, sans formulaire

### Story 5.4 : Conduire à l'enrôlement les rôles qui l'exigent

As a responsable de la sécurité du dérivé,
I want que les rôles à pouvoir ne restent pas longtemps sans second facteur,
So that le compte qui peut tout faire ne soit pas le moins protégé.

**Estimation :** ~1 h 15 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un utilisateur dont le rôle porte l'attribut « exige la double authentification »
**When** il se connecte pour la première fois sous ce rôle
**Then** son délai de grâce s'ouvre, et un bandeau non fermable annonce sur chaque page les jours qui lui restent
**And** le délai vaut sept jours, porté par un paramètre `app.` et non par une constante

**Given** le socle installé
**When** je cherche un rôle exempté
**Then** il n'y en a aucun, Super admin compris — l'obligation est un attribut du rôle, jamais un test de son nom

**Given** un délai de grâce expiré
**When** je demande n'importe quelle page
**Then** je suis redirigé vers l'enrôlement avec le message qui explique pourquoi
**And** le profil et la déconnexion restent accessibles pendant tout le délai et après

**Given** un utilisateur qui reçoit un tel rôle après coup
**When** il se connecte ensuite
**Then** son délai de grâce démarre à cette connexion, remis à zéro

**Given** l'application de cette règle
**When** j'en lis le code
**Then** elle passe par un point d'application unique, un listener de requête dans `Core`

### Story 5.5 : Réinitialiser la double authentification d'un utilisateur

As a administrateur,
I want rendre l'accès à quelqu'un qui a perdu son second facteur,
So that la sécurité n'enferme pas dehors les gens qu'elle protège.

**Estimation :** ~30 min de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** la fiche d'un utilisateur dont la 2FA est active
**When** je clique sur « Réinitialiser la 2FA »
**Then** un Dialog nomme la personne et la conséquence avant que je confirme

**Given** la réinitialisation confirmée
**When** elle s'exécute
**Then** le second facteur et les codes de secours de la personne sont effacés, ses sessions closes
**And** à sa prochaine connexion, son délai de grâce redémarre si son rôle l'exige
**And** l'opération est consignée au journal d'audit

---

## Epic 6 : Ouvrir le dérivé à une application cliente

Une future application cliente s'identifie auprès du dérivé avec un jeton que son
utilisateur a créé et peut révoquer, et sait sans s'authentifier si le service répond.

**FR couvertes :** FR-17, FR-18

### Story 6.1 : Créer un jeton d'accès depuis son profil

As a utilisateur,
I want créer un jeton pour une application qui agira en mon nom,
So that je lui donne accès sans jamais lui confier mon mot de passe.

**Estimation :** ~1 h 15 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** l'onglet Jetons d'API du profil
**When** je crée un jeton
**Then** un nom m'est demandé, qui servira à le reconnaître ensuite

**Given** un jeton qui vient d'être créé
**When** il s'affiche
**Then** c'est la seule et unique fois — l'application n'en conserve qu'une empreinte et ne peut pas le réafficher
**And** il porte une date d'expiration, configurable et d'une heure par défaut
**And** la création est consignée au journal d'audit

**Given** la liste de mes jetons
**When** je la consulte
**Then** elle porte le nom et la date de création, et rien d'autre
**And** une mention visible signale un jeton expiré ou révoqué

### Story 6.2 : Authentifier une requête d'API par jeton

As a application cliente,
I want m'identifier avec un jeton porteur,
So that j'accède aux données de mon utilisateur sans manipuler ses identifiants.

**Estimation :** ~1 h 15 de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** le firewall de l'API
**When** j'en lis la configuration
**Then** il est sans état et n'accepte que l'en-tête `Authorization: Bearer`
**And** aucune URL de l'API n'accepte un mot de passe, et l'API n'a aucun point d'entrée d'authentification

**Given** une requête portant un jeton
**When** elle est traitée
**Then** l'expiration et la non-révocation sont vérifiées à chaque requête
**And** un compte désactivé ou anonymisé est refusé, même si le jeton a été créé avant

**Given** une requête refusée
**When** je lis la réponse
**Then** elle suit le format Problem Details, sans détail interne

### Story 6.3 : Révoquer un jeton d'accès

As a utilisateur ou administrateur,
I want retirer immédiatement l'accès d'une application,
So that un jeton qui a fuité cesse de fonctionner sans attendre son expiration.

**Estimation :** ~45 min de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** un de mes jetons
**When** je le révoque depuis mon profil
**Then** un Dialog me le fait confirmer, puis toute requête le portant est refusée
**And** la révocation est consignée au journal d'audit

**Given** la fiche d'un utilisateur
**When** je révoque un de ses jetons en tant qu'administrateur
**Then** l'effet est le même, et l'entrée d'audit me désigne comme auteur

**Given** un jeton révoqué
**When** je consulte la liste
**Then** sa ligne le mentionne visiblement, sans colonne de date supplémentaire

### Story 6.4 : Vérifier que le service répond

As a application cliente ou outil de supervision,
I want savoir sans m'authentifier si le dérivé est en service,
So that je détecte une panne avant que les utilisateurs l'appellent.

**Estimation :** ~1 h de travail agent (implémentation et tests).

**Acceptance Criteria:**

**Given** le point de contrôle
**When** je l'appelle sans authentification
**Then** la réponse indique l'état du service et de sa base de données, sans détail interne

**Given** un état dégradé
**When** je lis la réponse
**Then** son code HTTP est distinct de celui de l'état nominal

**Given** la file d'envoi des emails
**When** elle cesse d'être dépilée, ou que son plus vieux message dépasse un seuil
**Then** le service passe en état dégradé

**Given** l'archivage du journal
**When** la plus vieille entrée en ligne dépasse la fenêtre de rétention d'une marge
**Then** le service passe en état dégradé — sans ce signal, un archivage arrêté ne se verrait nulle part
