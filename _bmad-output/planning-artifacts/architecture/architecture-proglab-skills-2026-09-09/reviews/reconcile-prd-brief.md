---
title: Réconciliation PRD / brief → ARCHITECTURE-SPINE
status: final
created: 2026-09-10
reviewer: revue de traçabilité amont
---

# Réconciliation — ce que le spine d'architecture n'a pas reçu de ses sources amont

## 0. Portée et méthode

Sources confrontées, toutes quatre en `status: final` :

- `prds/prd-proglab-skills-2026-09-09/prd.md` (FR-1 à FR-22, NFR §5, contraintes §6,
  non-objectifs §7, périmètre §8, métriques §9, questions ouvertes §10, index des
  hypothèses §11)
- `prds/prd-proglab-skills-2026-09-09/addendum.md` (décisions *Arrêté*, items *À
  trancher en architecture*, contrat d'entrée BMAD, lancement de tâche)
- `briefs/brief-proglab-skills-2026-09-09/brief.md`
- `briefs/brief-proglab-skills-2026-09-09/addendum.md` (conséquences étiquetées
  *(architecture)*, paysage des solutions, données de coût)

Cible : `architecture/architecture-proglab-skills-2026-09-09/ARCHITECTURE-SPINE.md`
(AD-1 à AD-15, Consistency Conventions, Stack, Structural Seed, Capability Map,
Deferred).

Le spine UX (`ux-designs/ux-proglab-skills-2026-09-09/DESIGN.md` et `EXPERIENCE.md`) est
cité comme source par le spine d'architecture. Quand une exigence atterrit **seulement**
là, la ligne le dit : le comportement est spécifié, mais le spine d'architecture ne
laisse aucun point d'accroche pour le **mécanisme**, et c'est lui qui revendique le
`binds: FR-1..FR-22 / NFR §5`.

Échelle de sévérité :

- **critique** — deux documents finaux se contredisent, ou une exigence de conformité /
  de sécurité n'a aucun point d'accroche : un builder livrera quelque chose de faux, pas
  seulement d'incomplet.
- **haute** — conséquence testable du PRD sans aucun point d'accroche, ou tension
  interne au spine qu'un builder devra trancher seul, différemment d'un autre builder.
- **moyenne** — exigence réalisable mais sous-déterminée : le spine laisse deux
  implémentations défendables et divergentes.
- **basse** — détail tenu implicitement, report explicite acceptable, ou incohérence de
  rédaction sans conséquence de construction.

**Verdict.** Le spine est solide sur ce qu'il a choisi de trancher (deux racines, audit
unique, permission calculée, BMAD en lecture seule, surface de lancement absente en
production) et honore ses cinq décisions *Arrêté*. Il perd, en revanche, une part
notable de la couche « conséquences testables » du PRD : tout le volet requête/écriture
transverse (CSRF, ralentissement des échecs, porte 2FA obligatoire, appliquer les
permissions partout), la totalité de la liste de référence des actions auditées
(FR-12), le contrat d'entrée BMAD nommé de l'addendum, et quatre exigences de
conformité ou de lisibilité (effacement, droits à la lecture de l'audit, objets
supprimés, budget p95 à 1 M d'entrées).

---

## 1. FR-1 à FR-22 et leurs conséquences testables

### 4.1 Initialisation d'un dérivé

| Exigence source | Atterrissage dans le spine | Sévérité |
| --- | --- | --- |
| FR-1 — « une seule commande qui prépare la base de données, applique les migrations, charge les rôles par défaut et crée le premier Super admin » | Capability Map « FR-1 Initialisation → `Core/Command/` » ; Structural Seed `Command/ # initialisation (FR-1)` ; migrations à chaque release (diagramme de déploiement). Enveloppe présente. | basse |
| FR-1 · conséquence « Sur une base vide, la commande termine sans autre intervention que la saisie des identifiants du premier Super admin » | **Nulle part.** Le tableau *Consistency Conventions* n'a aucune ligne « Commandes console » : ni `SymfonyStyle`, ni mode interactif, ni verrouillage, ni `#[AsCommand]`. La saisie interactive d'identifiants est pourtant le seul point d'entrée de secret du socle. | moyenne |
| FR-1 · conséquence « Sur une base déjà peuplée, la commande refuse d'agir sans une option de confirmation explicite » (et UJ-1, cas limite) | **Nulle part.** Aucune décision sur la détection d'une base peuplée ni sur le nom/la sémantique de l'option de confirmation. S'y ajoute une collision non arbitrée avec `symfony-proglab-console`, qui impose un *dry-run par défaut avec `--force`* sur tout ce qui est destructif : deux conventions opposées pour la même commande. | haute |
| FR-1 · « charge les rôles par défaut » + FR-7 « Le socle livre trois rôles » | **Nulle part.** AD-8 décide que le **catalogue des permissions** a le code pour source de vérité et est projeté en base par une commande de synchronisation — mais rien ne dit comment les trois rôles et leurs attributions par défaut arrivent en base (fixtures ? migration ? commande d'init ? service de seed rejouable ?), ni ce qui se passe au déploiement suivant si un administrateur les a modifiés entre-temps (FR-7 l'autorise). | haute |
| FR-1 · conséquence « À la fin, le Super admin peut se connecter et voit la roadmap (vide) » | Partiellement : AD-10 garantit une roadmap partielle plutôt qu'une erreur ; `EXPERIENCE.md` fixe `app_home` `/`. Mais FR-5 rend la 2FA obligatoire pour Super admin, donc le premier écran réel après l'init est l'enrôlement 2FA, qui exige un worker Messenger vivant si la méthode choisie est l'email (AD-4). La chaîne « une commande → connexion → roadmap » n'est nulle part vérifiée de bout en bout. | moyenne |
| FR-1 + SM-1 (moins d'une journée) vs AD-4 (worker systemd), AD-7 (deptrac en CI), AD-8 (commande de synchronisation) | **Nulle part.** Le spine ajoute trois pièces mobiles au démarrage d'un dérivé (worker, sync du catalogue, barrière deptrac) sans dire si elles entrent dans la commande unique de FR-1. UJ-1 invite le responsable PME *le premier jour* : sans worker lancé en dev, l'email d'invitation reste en file. | moyenne |
| FR-2 — guide de dérivation « rédigée pour être lue aussi bien par un humain que par un agent » | Capability Map « FR-2 → racine du dépôt », gouverné par AD-2 et AD-13. AD-4 lui confie un contenu précis (« le guide de dérivation nomme l'enfermement des administrateurs en 2FA par email »). | basse |
| FR-2 · conséquence « Un agent d'implémentation … trouve dans cette documentation, **sans autre source**, l'emplacement et les conventions » | Partiellement. AD-2 donne l'emplacement (`src/Module/<Nom>/`) et AD-13 le mécanisme de déclaration. Mais « racine du dépôt » ne nomme aucun fichier : `AGENTS.md`, `CLAUDE.md`, `README.md`, `docs/` restent interchangeables, alors que le fichier lu automatiquement par un agent n'est pas le même que celui lu par un humain. | moyenne |
| FR-2 · conséquence « La documentation nomme les skills proglab et les workflows BMAD à invoquer » | **Nulle part.** Aucune décision sur qui garantit cette nomination, ni sur le fait que le guide reste synchrone des skills `symfony-proglab-*` (versionnées hors du dépôt du socle). | moyenne |

### 4.2 Comptes et connexion

| Exigence source | Atterrissage dans le spine | Sévérité |
| --- | --- | --- |
| FR-3 — connexion email + mot de passe | Capability Map FR-3/FR-4 → `Core/Service/`, `Core/Entity/`, AD-15, AD-4. | basse |
| FR-3 · conséquence « Un utilisateur désactivé … voit un message qui le dit **sans révéler si le mot de passe était correct** » | **Nulle part.** Exigence subtile : un `UserChecker` en pré-authentification divulgue l'état du compte avant vérification du mot de passe (énumération) ; il faut donc un contrôle *après* vérification des identifiants, ou un message volontairement ambigu. Le spine ne décide ni l'un ni l'autre, et `EXPERIENCE.md` ne parle que d'un « message générique ». | moyenne |
| FR-3 · conséquence « Les échecs répétés sont ralentis » `[ASSUMPTION: délai doublé à partir du cinquième, plafonné à quinze minutes, sans blocage définitif]` | **Nulle part.** Aucun AD, aucune convention, et **aucune dépendance** : `symfony/rate-limiter` est absent de la table *Stack*. Le `login_throttling` natif de Symfony est une fenêtre fixe, pas un doublement plafonné : l'hypothèse du PRD exige un limiteur écrit, avec un magasin (base ? cache ?) que le spine ne choisit pas. `EXPERIENCE.md` spécifie l'affichage des secondes restantes et étend le ralentissement au code 2FA (l. 409) — ce qui double la surface sans point d'accroche. | haute |
| FR-3 · conséquence « réinitialisation … par un lien email à usage unique `[valable une heure]` » | AD-15 : mécanisme à état partagé avec l'invitation, jeton haché, date d'expiration, date d'usage. Mécanisme couvert. | basse |
| FR-3 / FR-4 · les **durées** (1 h pour la réinitialisation, 7 jours pour l'invitation) | **Nulle part.** AD-15 énumère les champs de l'entité mais pas où vivent les durées. La ligne *Configuration* offre trois niveaux (env / `app.` / constante) sans trancher, alors que la NFR dérivabilité exige que ce qui est propre à un dérivé soit configurable. | moyenne |
| FR-4 — invitation par email avec rôle prévu | AD-15 (entité : destinataire, rôle prévu, jeton haché, expiration, usage) ; AD-4 (email en file) ; entité `INVITATION` au Structural Seed. | basse |
| FR-4 · conséquences « lien à usage unique et expire » / « renvoyer ; le lien précédent devient invalide » | AD-15, explicitement : l'URL signée sans état est exclue *parce qu'*elle ne peut ni marquer un lien consommé ni invalider le précédent. Traçabilité exemplaire. | basse |
| FR-4 · conséquence « Une invitation acceptée crée un utilisateur actif avec le rôle prévu **et une entrée d'audit « invitation acceptée »** » | Renvoie à la liste de FR-12 — qui n'atterrit nulle part (voir §1/4.4). | (voir FR-12) |
| FR-4 · conséquence « Seuls les administrateurs voient et utilisent la fonction d'invitation » | AD-8 (voter unique) + AD-13 (entrées de navigation publiées) + convention *Permissions* (`user.invite` donné en exemple). | basse |
| FR-5 — 2FA obligatoire pour Super admin et Admin, optionnelle sinon | Capability Map FR-5 → `Core/Security/`, AD-9, AD-4, `scheb/2fa` 8.6 (totp, email, backup-code) en *Stack*. | basse |
| FR-5 · conséquence « conduit à l'activation **avant tout autre écran** ; un utilisateur qui reçoit l'un de ces rôles après coup y est conduit à sa connexion suivante » | **Nulle part** côté mécanisme. Et tension directe avec AD-8 : « **Aucun code ne teste un nom de rôle** » — or l'obligation de 2FA est définie *par rôle* (Super admin, Admin), pas par permission. Le builder devra soit violer AD-8, soit inventer un code de permission « 2FA obligatoire » que ni le PRD ni le spine ne prévoient. S'ajoute l'absence de décision sur le porteur du barrage (listener de requête ? `access_control` ? liste d'exemptions Profil/Déconnexion). | haute |
| FR-5 vs délai de grâce 2FA adopté par le spine UX (`EXPERIENCE.md` l. 244, 422-427) | Le spine d'architecture range « Durée du délai de grâce 2FA (OQ-UX-1) » dans *Deferred* comme « question produit, pas architecture ». Il **adopte donc implicitement** un délai de grâce, alors que FR-5 dit « avant tout autre écran », c'est-à-dire sans délai. La divergence n'est signalée nulle part — contrairement à FR-17, où le spine dit franchement que le PRD doit être corrigé. | haute |
| FR-5 · conséquence « Deux méthodes au moins `[TOTP et email]`, plus des codes de secours » | *Stack* : `scheb/2fa-bundle (totp, email, backup-code) 8.6`. Couvert. | basse |
| FR-5 · conséquence « Un administrateur peut réinitialiser la 2FA … crée une entrée d'audit » | AD-9 (jeton de sécurité incrémenté à la réinitialisation de la 2FA) ; l'entrée d'audit renvoie à la liste de FR-12. | basse |
| FR-5 / FR-11 · entité `TWO_FACTOR_METHOD` du Structural Seed | Incohérence avec la décision *Arrêté* `scheb/2fa` : le bundle attend que **l'utilisateur** porte les interfaces `TotpTwoFactorInterface`, `EmailTwoFactorInterface`, `BackupCodeInterface` et leurs champs. Une entité séparée par méthode est défendable mais impose des adaptateurs que le spine ne prévoit pas. À trancher avant la première story 2FA. | moyenne |
| FR-6 — désactiver ; « ses sessions en cours sont closes » | AD-9, pleinement : jeton de sécurité + `EquatableInterface`, sessions en fichiers, aucun second magasin. | basse |
| FR-6 · conséquence « Ses entrées d'audit et les objets qu'il a créés … le désignent par son nom, avec la mention « compte désactivé » » (et UJ-4, cas limite) | AD-12 : libellés résolus à la lecture, donc la mention suit l'état courant du compte. Couvert. | basse |
| FR-6 · conséquence « Aucune fonction ne supprime un utilisateur » | AD-12 s'**appuie** sur ce fait (« ce qui tient parce qu'aucune fonction ne supprime un utilisateur ») mais ne l'érige pas en règle vérifiable : aucune interdiction de `remove()` sur `User`, aucun garde-fou en CI, alors qu'AD-7 montre qu'on sait imposer une frontière par outil. | moyenne |
| FR-20 — anonymiser un utilisateur désactivé, « tout le reste est conservé » | AD-12 (référence par identifiant, libellés résolus à la lecture, « Utilisateur anonymisé #12 » sans toucher une entrée). Mécanisme élégant et couvert. | basse |
| FR-20 + §6 « Le droit à l'effacement est satisfait par l'anonymisation … la personne n'y est plus identifiable » | **Contredit par le spine.** AD-12 protège la *référence* (acteur, objet) mais FR-11 impose de stocker, pour chaque champ modifié, **l'ancienne et la nouvelle valeur**. Le nom et l'email de la personne subsistent donc en clair dans les entrées qui ont historisé ces champs (création du compte, changement d'email, toute modification d'un objet portant son nom). Anonymiser la ligne `USER` ne les atteint pas, et l'immuabilité de l'audit interdit de les réécrire. L'unique mécanisme d'effacement du PRD ne tient pas : ni purge ciblée de valeurs, ni chiffrement des diffs, ni liste de champs « porteurs d'identité » n'est décidé. | critique |
| FR-20 · conséquence « Un utilisateur actif ne peut pas être anonymisé » | **Nulle part**, mais règle de service triviale et sans ambiguïté. | basse |
| FR-21 — profil : langue, mot de passe, réenrôlement 2FA | Capability Map FR-6/FR-20/FR-21 → `Core/Service/`, AD-9, AD-12 ; AD-14 pour la langue ; AD-5 ajoute « Profil › Jetons d'API ». | basse |
| FR-21 · conséquence « Le changement de mot de passe … clôt **les autres** sessions » | AD-9 incrémente le jeton de sécurité au changement de mot de passe, ce qui déauthentifie **toutes** les sessions, y compris celle de l'auteur. Le spine ne dit pas comment la session courante est réauthentifiée après l'incrément (`TokenStorage` rafraîchi, nouvelle authentification programmatique). Le builder livrera « je change mon mot de passe et je suis déconnecté » ou devra improviser. | moyenne |
| FR-21 · conséquence « Le réenrôlement 2FA exige le second facteur en cours ou un code de secours » | **Nulle part.** Une réauthentification forte sur une action précise (et non à l'entrée du firewall) est un mécanisme à part entière ; `scheb/2fa` ne le fournit pas tel quel. | moyenne |

### 4.3 Rôles et permissions

| Exigence source | Atterrissage dans le spine | Sévérité |
| --- | --- | --- |
| FR-7 — rôles prédéfinis + écran de gestion, création et modification de rôles | AD-8, AD-13, ER (`ROLE`, `ROLE_PERMISSION`), AD-6 (`/roles`). | basse |
| FR-7 · conséquence « Super admin (**toutes** les permissions) » confrontée à AD-8 (« le catalogue des permissions a le code pour source de vérité : chaque module publie ses codes … une commande de synchronisation projette le catalogue dans la table au déploiement ») | **Trou.** Rien ne dit si Super admin reçoit automatiquement un code nouvellement publié par un module. Deux implémentations défendables et incompatibles : (a) un drapeau « tout accorder » sur le rôle, court-circuité dans le voter ; (b) la commande de synchronisation accorde chaque nouveau code au rôle Super admin. (a) casse « l'origine de chaque permission » de FR-9 pour ce rôle ; (b) fait écrire la commande dans des données qu'un administrateur peut modifier (FR-7). | haute |
| FR-7 · conséquence « Une modification des permissions d'un rôle s'applique **immédiatement** … sauf là où une surcharge existe » | AD-8, c'est exactement son *Prevents*. Couvert. | basse |
| FR-7 · conséquence « Les permissions sont des codes stables que les modules métier des dérivés étendent » | AD-8 + AD-13 + convention *Permissions* (`snake_case` préfixé par domaine, module préfixe par son nom). Couvert. | basse |
| FR-8 — surcharge en ajout et en retrait, qui prime sur le rôle | AD-8 (« le rôle, moins les retraits, plus les ajouts ») + ER `PERMISSION_OVERRIDE`. Couvert. | basse |
| FR-8 · conséquence « Toute surcharge crée une entrée d'audit » | Renvoie à la liste de FR-12 (non atterrie). | (voir FR-12) |
| FR-9 — origine de chaque permission : héritée / ajoutée / retirée | AD-8 (héritage et surcharge stockés séparément) ; le spine ne nomme pas les trois origines, mais le modèle les rend dérivables. La *Table des permissions* de `DESIGN.md` porte l'écran. | basse |
| FR-9 · conséquence « La liste montre **toutes les permissions existantes** » + AD-8 « un code présent en base mais plus déclaré s'affiche comme obsolète » | Couvert, et le spine va au-delà du PRD (état « obsolète » non prévu par FR-9). À reporter vers le PRD/UX. | basse |
| FR-9 · conséquence « annuler une surcharge pour revenir à l'héritage » | **Nulle part** explicitement (suppression de la ligne de surcharge), mais sans ambiguïté vu le modèle. `DESIGN.md` porte l'action « Revenir à l'héritage ». | basse |
| FR-10 — « Chaque action et chaque zone protégée du dérivé **vérifie** la permission effective » | AD-8 (voter unique), convention *Erreurs* (403 zone / 404 objet), AD-13 (navigation). Les trois conséquences sont adressées. **Mais rien ne garantit la couverture** : pas de refus par défaut (`access_control` fermé, `#[IsGranted]` obligatoire sur tout contrôleur, test systématique), alors qu'AD-7 prouve que le spine sait imposer un invariant en CI. FR-10 est une garantie d'exhaustivité, or elle repose ici sur la vigilance du builder. | haute |
| FR-10 · conséquence « Une tentative d'accès à un objet précis non permis reçoit la même réponse que pour un objet inexistant (404) » | Convention *Erreurs*, mot pour mot. Couvert. | basse |
| FR-10 · conséquence « libellé traduit » du 403 | Convention *Traduction* + `EXPERIENCE.md` l. 209. Couvert. | basse |

### 4.4 Journal d'audit

| Exigence source | Atterrissage dans le spine | Sévérité |
| --- | --- | --- |
| FR-11 — création, modification **ou suppression** d'un objet audité, avec champs avant/après | AD-3 (listener `onFlush`, table unique, aucun bundle), AD-12, `Core/Audit/`. | basse |
| FR-11 · le cas **suppression** confronté à AD-12 (« les libellés sont résolus à la lecture ») | **Contradiction non traitée.** Pour un objet supprimé, il n'y a plus rien à résoudre à la lecture : l'entrée ne peut plus afficher qu'une classe et une clé primaire — exactement les « identifiants internes bruts » que FR-13 interdit, dans un journal que le client doit lire. AD-12 ne s'immunise que pour `User` (« aucune fonction ne supprime un utilisateur »), pas pour les objets métier d'un module, où la suppression est normale. Aucun instantané de libellé, aucune stratégie de repli. | critique |
| FR-11 · conséquence « Tous les objets métier sont audités par défaut ; un développeur peut en exclure un explicitement » | Convention *Audit* + AD-13 (exclusions publiées par service taggé). Couvert. | basse |
| FR-11 · conséquence « Les champs sensibles (mot de passe, secrets de 2FA) n'apparaissent **jamais** dans une entrée » | AD-12 : exclusion **à l'écriture**, liste tenue dans `Core`, extensible par module, jamais masquée à l'affichage. Couvert, et mieux spécifié que le PRD. | basse |
| FR-11 · conséquence « Une modification faite par une commande ou un traitement automatique est attribuée à un **acteur système nommé** » | AD-12 l'énonce, mais entre en tension avec sa propre règle « l'entrée référence son acteur … par identifiant (classe et clé primaire) » : un acteur système n'a pas de ligne. Il faut soit une ligne `USER` système (qui apparaîtra dans la liste des utilisateurs, la gestion des rôles, les filtres par auteur), soit un acteur nullable + code, que le spine ne choisit pas. L'ER montre `USER ||--o{ AUDIT_ENTRY : "auteur de"` comme une relation, ce qu'AD-12 récuse. | moyenne |
| FR-12 — action métier nommée déclarée depuis la couche service | AD-3 + convention *Audit* (code stable, libellé traduisible, jamais depuis un contrôleur) + brief addendum honoré. | basse |
| FR-12 · conséquence « **Liste de référence des actions du socle auditées** : connexion, échec de connexion, mot de passe changé ou réinitialisé, invitation envoyée et acceptée, 2FA activée ou réinitialisée, surcharge de permission, permissions d'un rôle modifiées, désactivation et réactivation, anonymisation, jeton d'accès obtenu ou révoqué, langue activée ou désactivée » (treize événements, et **toutes** les puces « crée une entrée d'audit » des autres FR y renvoient) | **Nulle part.** Le spine ne reprend ni ne pointe cette liste, alors qu'elle est le seul endroit où l'audit du socle est énuméré et qu'elle est référencée par FR-4, FR-5, FR-8, FR-17, FR-20, FR-22. Pire, deux de ses entrées sont **incompatibles avec les règles du spine** : « connexion » et « échec de connexion » naissent dans la couche sécurité, pas dans un service métier (convention *Audit* : « déclarée depuis la couche service, jamais depuis un contrôleur »), et un échec de connexion n'a **pas d'acteur identifiable** — AD-12 exige une référence par classe et clé primaire, or l'auteur peut être un email inconnu. Aucun AD ne prévoit l'acteur anonyme ni l'origine « événement de sécurité ». | critique |
| FR-12 · « jeton d'accès obtenu » confronté à AD-5 (plus d'endpoint d'authentification) | L'événement change de sens (création d'un jeton depuis le Profil, non plus obtention par identifiants) et n'est requalifié nulle part. | moyenne |
| FR-13 — consulter, **filtrer** et exporter | Convention *Listes* (enveloppe `items` + `meta`) ; `EXPERIENCE.md` porte l'écran et les filtres. Côté architecture, rien de plus. | — |
| FR-13 · conséquence « Filtres : période, auteur, type d'objet, objet précis, type d'entrée » confrontée à la NFR performance (« moins d'une seconde au p95 … 1 million d'entrées d'audit ») | **Nulle part.** Cinq axes de filtre sur une table unique (AD-3) d'un million de lignes : aucune décision d'indexation, aucune contrainte sur les combinaisons autorisées, aucun budget de requête. S'y ajoute le coût d'AD-12 : résoudre les libellés **à la lecture** pour une page d'entrées visant N classes différentes, sans décision de regroupement, est un N+1 structurel. Et *Deferred* referme la porte (« Cache HTTP et cache applicatif : sur lenteur mesurée seulement »), ce qui laisse le budget p95 sans moyen. | critique |
| FR-13 · conséquence « Chaque entrée se lit **sans connaissance technique** : libellés traduits, valeurs lisibles, **pas d'identifiants internes bruts** » | AD-12 (résolution + traduction à la lecture) couvre le cas nominal ; le cas des objets supprimés le casse (voir FR-11). Les « valeurs lisibles » (enum → libellé, booléen, date, référence vers une autre entité) n'ont pas de point d'accroche : aucune notion de formateur de valeur par type, ni de contrat que les modules implémenteraient via AD-13. | haute |
| Brief addendum · « le client consultant le journal, **le filtrage par droits** et la lisibilité non technique sont des exigences, pas des options » | **Nulle part.** AD-8 autorise l'accès à la *zone* audit (`audit.read`), mais rien ne filtre les *entrées* selon ce que le lecteur a le droit de voir. Un Admin PME avec `audit.read` lit, dans une table unique (AD-3), les modifications de tous les objets de tous les modules, y compris ceux dont les écrans lui sont refusés par 403/404 (FR-10). Le spine crée ainsi un contournement de FR-10 par l'audit, sans le voir. | critique |
| FR-13 · conséquence « L'export porte sur la sélection filtrée `[CSV]` » | **Nulle part.** Aucun choix de mécanisme (réponse en flux, génération différée par Messenger — AD-4 n'existe que pour l'email), aucun plafond de volume, alors que l'export d'un filtre large sur 1 M de lignes est le cas limite évident. `EXPERIENCE.md` décide seulement `data-turbo="false"`. | haute |
| FR-13 · conséquence « Les entrées sont immuables : aucune interface ne permet de les modifier ni de les supprimer » + NFR sécurité « le journal d'audit est en **ajout seul** » | **Nulle part.** Aucune règle sur l'entité d'audit (pas d'`update`/`remove` au repository, pas de droits SQL restreints, pas de test de régression). L'immuabilité est le pilier d'AD-12 et n'est garantie que par convention tacite. | moyenne |

### 4.5 Roadmap

| Exigence source | Atterrissage dans le spine | Sévérité |
| --- | --- | --- |
| FR-14 — lire epics, tâches et statut dans les fichiers BMAD, « sans base de données intermédiaire » | AD-10, pleinement : lecteur unique dans `Core`, parse tolérant, DTO Read, aucun autre lecteur, cache invalidé par version de release / date de modification. | basse |
| FR-14 · conséquence « Les fichiers BMAD font partie de ce qui est déployé » | Structural Seed (`_bmad-output/ # embarqué dans la release, lu seulement`) + diagramme `releases/ · current/ · shared/`. Couvert. | basse |
| FR-14 · conséquence « Un fichier absent ou mal formé produit une roadmap partielle avec un avertissement **visible des administrateurs**, jamais une page en erreur » | AD-10 dit « une roadmap partielle et un avertissement », sans le destinataire. Le PRD restreint l'avertissement aux administrateurs : il faut donc une permission (aucune n'est prévue dans la convention *Permissions*, qui ne cite que `roadmap.notes`) ou un test de rôle, interdit par AD-8. | moyenne |
| FR-14 · conséquence « La roadmap affiche la date du dernier déploiement » | Couvert explicitement : « la date de la release est la "date du dernier déploiement" qu'affiche FR-14 ». | basse |
| FR-15 · conséquence « La roadmap est la page d'accueil de tout utilisateur **qui a la permission de la voir** » | AD-6 énumère `/users`, `/audit-log`, `/roles`, `/languages`, `/profile` — ni `/`, ni le chemin de la roadmap. `EXPERIENCE.md` fixe `app_home` `/`. Reste sans réponse : quelle page d'accueil pour un utilisateur **sans** la permission roadmap, cas que FR-15 ouvre et que le rôle User par défaut n'expose pas (FR-7 lui accorde la roadmap, mais OQ-5 laisse chaque dérivé l'ajuster). | moyenne |
| FR-15 · conséquence « L'avancement d'une epic est le rapport des tâches terminées sur le total » | **Nulle part**, et le contrat d'entrée le rend piégeux : l'addendum du PRD signale la clé `epic-{n}-retrospective` dans `sprint-status.yaml`. Comptée comme une tâche, elle fausse tous les pourcentages ; le spine ne la mentionne pas. | haute |
| FR-15 · conséquence « Les statuts BMAD sont traduits en trois statuts lisibles : `backlog`/`ready-for-dev` → à faire ; `in-progress`/`review` → en cours ; `done` → terminé. Un statut inconnu est montré comme « à faire » **et signalé aux administrateurs** » | **Nulle part.** La table de correspondance (OQ-4, pourtant marquée « décision prise pendant le PRD ») n'est reprise par aucun AD ni aucune convention, alors qu'elle est précisément le genre de chose que deux morceaux pourraient trancher différemment — l'objet déclaré du spine. AD-10 garantit seulement qu'un lecteur unique parse les fichiers. | moyenne |
| FR-15 · conséquence « Une tâche dépendante indique de quelles tâches elle dépend et si elles sont terminées » + FR-16 « tâche prête » + UJ-5 | **Trou fonctionnel.** AD-10 rend priorité, difficulté, assignation et dépendances « optionnelles dans le contrat : absentes, elles se rendent "non renseigné", ce qui rend OQ-3 non bloquante ». Mais les dépendances ne sont pas un ornement : « tâche prête » (Glossaire, FR-15, FR-16, UJ-5) s'en déduit. Si elles sont absentes, « prête » dégénère en « à faire », le bouton de FR-16 ne peut plus être inactif « avec la raison », et le cas limite d'UJ-5 disparaît. Le spine neutralise OQ-3 au lieu de la trancher, et n'adopte ni ne rejette la « convention d'en-tête YAML dans les stories du projet » que l'addendum du PRD propose comme issue. | haute |
| FR-15 · conséquence « Les notes internes ne sont montrées qu'aux utilisateurs ayant la permission dédiée `[Super admin seul par défaut]` » | Convention *Permissions* cite `roadmap.notes`. Le défaut d'attribution dépend du seed des rôles (non décidé, voir FR-1). La **définition** des notes internes (Glossaire, `[ASSUMPTION]` : « contenu d'une story au-delà de son titre et de son résumé ») est portée par le lecteur d'AD-10, qui ne la formalise pas : le lecteur doit savoir quelles sections d'un fichier de story sont « notes ». | moyenne |
| FR-16 — lancer une tâche en dev ; « les agents — et eux seuls — mettent à jour les fichiers BMAD » | AD-11, pleinement : `#[When('dev')]` sur contrôleur, service et route ; « FR-16 exige "impossible", pas "masqué" » ; processus local détaché ; compte rendu du seul démarrage. AD-10 ajoute « seul `var/` est inscriptible ». Le meilleur alignement du document. | basse |
| FR-16 · conséquence « Seule une tâche prête peut être lancée ; sinon le bouton est inactif et la raison est affichée » / « Une tâche déjà en cours ne peut pas être relancée » | Dépend entièrement des dépendances lues (voir FR-15 ci-dessus). Le calcul lui-même revient au lecteur d'AD-10, ce qui est cohérent mais non dit. | (voir FR-15) |
| FR-16 · conséquence « échoué avec la raison (**commande absente**, processus non démarré) » + addendum PRD « Claude Code avec `bmad-build` sur la story » | **Nulle part.** « Commande absente » suppose une commande **configurée** ; le spine ne dit pas où (variable d'environnement, exigée par la NFR dérivabilité, puisque le chemin dépend du poste), ni qu'elle est paramétrable par dérivé. | moyenne |
| FR-16 — déclenchement d'un processus, et NFR sécurité « protection CSRF sur toute écriture » | **Nulle part.** Lancer un processus par une requête est l'écriture la plus sensible du socle ; aucune exigence de méthode (POST) ni de jeton. | moyenne |

### 4.6 API

| Exigence source | Atterrissage dans le spine | Sévérité |
| --- | --- | --- |
| FR-17 — « Un utilisateur actif peut **obtenir par l'API** un jeton d'accès avec ses identifiants et son second facteur » | **Contredit sciemment** par AD-5 : « L'API n'a pas d'endpoint d'authentification … **FR-17 dit le contraire et doit être corrigé.** » La contradiction est honnête mais non résolue : le PRD reste `status: final` sans correction, le brief garde « Début d'API … authentification et health check » dans son périmètre v1, et aucun addendum ne consigne l'arbitrage. Les workflows aval (epics, stories) hériteront de deux documents finaux qui se contredisent. | critique |
| FR-17 · conséquence « Un utilisateur **désactivé** ou sans second facteur valide est refusé » | **Perdue.** AD-5 pose un firewall `/api` **sans état** n'acceptant que `Authorization: Bearer`, et AD-9 fait reposer la fermeture des sessions sur `EquatableInterface`, c'est-à-dire sur le rafraîchissement d'un utilisateur **en session**. Sur un firewall sans état, rien ne revérifie l'état actif du porteur du jeton à chaque requête : le jeton d'un utilisateur désactivé continue de fonctionner jusqu'à révocation manuelle. La désactivation de FR-6 (« ses sessions en cours sont closes ») perd son équivalent côté API. | haute |
| FR-17 · conséquence « Le jeton d'accès **expire** `[durée configurable, par défaut une heure]` » | **Perdue en silence.** AD-5 décrit un jeton personnel créé depuis le Profil, montré une fois, stocké haché, révocable — et ne dit **rien** d'une expiration. Un jeton personnel est par nature long ; la rotation horaire exigée par le PRD disparaît sans que le spine la conteste (contrairement au point d'authentification, lui, discuté). | haute |
| FR-17 · conséquence « peut être révoqué par l'utilisateur lui-même ou par un administrateur » | AD-5, mot pour mot, et l'ER porte `API_TOKEN`. Couvert. | basse |
| FR-17 · conséquence « Chaque obtention de jeton d'accès crée une entrée d'audit » | Renvoie à la liste de FR-12, non atterrie, et change de sens avec AD-5. | moyenne |
| Brief · « Début d'API. Endpoints JSON écrits à la main **pour préparer une future application mobile** : authentification et health check » | AD-5 réduit l'API à un porteur de jeton créé depuis une interface web. La cible annoncée par le brief — une application cliente hors navigateur qui s'authentifie — n'a plus de chemin : l'utilisateur mobile devra passer par le web pour obtenir un jeton à coller. Le spine ne le dit pas et ne reporte pas la question dans *Deferred*. | haute |
| FR-18 — health check sans authentification | Capability Map → `Core/Controller/`, gouverné par AD-4 ; AD-4 exige l'état dégradé quand la file Messenger cesse d'être dépilée. Excellent ajout. | basse |
| FR-18 · conséquences « l'état du service **et de sa base de données**, sans détail interne » / « Un état dégradé se traduit par un code HTTP distinct » | Partiellement : AD-4 ne couvre que la dimension file. L'état base de données, le code HTTP distinct et la discrétion (endpoint non authentifié, donc exposé) n'ont pas de point d'accroche ; la convention *Erreurs* ne prévoit pas ce cas. | moyenne |

### 4.7 Langues

| Exigence source | Atterrissage dans le spine | Sévérité |
| --- | --- | --- |
| FR-19 — chaque utilisateur choisit sa langue parmi les langues actives | AD-14, pleinement (supportées en code, actives en base, champ du compte, appliquées aux emails, français langue source) + convention *Traduction* + `translations/` au Structural Seed + ER `LANGUAGE`. | basse |
| FR-19 · conséquence « **Tout** texte d'interface, message d'erreur, email et libellé d'audit du socle existe en français, anglais et néerlandais » | **Nulle part** comme garantie. La convention *Traduction* dit comment traduire, pas comment prouver l'exhaustivité : ni `translation:extract --dry-run` ni `lint:xliff` en CI, alors que la ligne *Tests* et AD-7 montrent que le spine sait placer des barrières bloquantes. Sur un socle trilingue par conception, c'est la régression la plus probable. | moyenne |
| FR-19 · conséquence « le français est la langue par défaut **tant qu'il est actif** » + FR-22 `[repli : première langue active dans l'ordre fr, en, nl]` | **Nulle part.** AD-14 fait du français la langue *source des catalogues*, ce qui n'est pas la même chose que le défaut d'exécution, et ne dit rien de l'ordre de repli quand le français est désactivé — cas que FR-22 autorise explicitement. | moyenne |
| FR-19 · conséquence « Un module métier ajouté suit le même mécanisme de traduction » | AD-13 + convention *Traduction* (« Un module livre ses catalogues par AD-13 »). Couvert. | basse |
| FR-22 — activer/désactiver une langue depuis l'interface | AD-14, dont le *Prevents* vise précisément la variable d'environnement. Couvert. | basse |
| FR-22 · conséquence « Au moins une langue reste active ; désactiver la dernière est refusé » | **Nulle part**, règle de service triviale. | basse |
| FR-22 · conséquence « un utilisateur qui l'avait choisie bascule sur la langue par défaut » | **Nulle part.** Bascule en masse des comptes concernés au moment de la désactivation, ou repli calculé à la lecture ? Les deux sont défendables et visibles différemment dans l'audit et dans les emails en file (AD-4). | moyenne |
| FR-22 · conséquence « Le changement crée une entrée d'audit » | Liste de FR-12, non atterrie. | (voir FR-12) |

---

## 2. NFR §5

| Exigence source | Atterrissage dans le spine | Sévérité |
| --- | --- | --- |
| Sécurité — « mots de passe hachés selon l'état de l'art » | **Nulle part.** Aucun choix d'algorithme ni de `password_hashers: auto`, aucune politique de migration du hachage. Implicite mais non dit dans un document qui fixe par ailleurs les versions au dixième. | basse |
| Sécurité — « sessions et jetons d'accès révocables » | AD-9 (sessions) + AD-5 (jetons). Couvert. | basse |
| Sécurité — « **protection CSRF sur toute écriture** » | **Nulle part.** Aucun AD, aucune ligne de convention, aucune dépendance (`symfony/security-csrf` absent de la *Stack*) — alors que le spine impose Turbo (ligne *Frontend*), dont les soumissions et les `data-turbo-method` interagissent avec les jetons CSRF, et qu'AD-11 déclenche un processus système par requête. Une exigence de sécurité nommée par le PRD, sans le moindre point d'accroche. | haute |
| Sécurité — « aucune donnée sensible dans les journaux applicatifs » | **Nulle part.** La convention *Erreurs* n'offre que `#[WithLogLevel]`. Aucun processeur Monolog, aucune liste de champs à masquer — alors qu'AD-12 en tient déjà une pour l'audit et qu'elle pourrait être partagée. | moyenne |
| Sécurité — « le journal d'audit est en **ajout seul** » | **Nulle part** (voir FR-13). | moyenne |
| Accessibilité — « WCAG 2.1 de la suite proglab (couleur jamais seule, contraste, labels, focus visible, une seule h1) » | Atterrit **dans le spine UX** (`DESIGN.md` § Colors, Typography, Components, check-list finale ; `review-accessibility.md`). Côté architecture : aucun porteur, alors que le frontmatter annonce `binds: NFR §5 — … accessibilité` et que la ligne *Tests* ne prévoit aucune vérification d'accessibilité bloquante. Le thème de formulaire Twig, qui est le lieu architectural des labels et des `aria-describedby`, n'est pas nommé. | moyenne |
| Performance — « moins d'une seconde au p95 … `[50 utilisateurs et 1 million d'entrées d'audit]` ; le journal d'audit est paginé » | Pagination : convention *Listes* (`items` + `meta`, pas de curseur, pas de Pagerfanta) — or une pagination par `OFFSET` sur un million de lignes est exactement ce qui tient mal le p95 en fin de liste, et le curseur est explicitement exclu. Budget, indexation, dimensionnement de référence, test de charge : **nulle part**. *Deferred* ferme le cache « sur lenteur mesurée seulement », ce qui transforme un budget contractuel en réaction après coup. | haute |
| Observabilité — « des journaux applicatifs **par canal**, sans données personnelles » | **Nulle part.** Aucun canal nommé (audit, sécurité, messenger, roadmap), aucune configuration de handler par environnement. | moyenne |
| Qualité — « passe l'analyse statique et les tests de la suite proglab ; chaque FR couverte par au moins un test fonctionnel » | Ligne *Tests* (test rouge d'abord, `dama/doctrine-test-bundle`, une FR = au moins un test fonctionnel) + `phpstan` et `php-cs-fixer/shim` en `require-dev` + AD-7 (deptrac bloquant en CI). Le **niveau** PHPStan n'est pas fixé, ce qui est le seul réglage qui change le coût d'une story. | basse |
| Qualité — « Base de données de CI **identique à la production** » (ligne *Tests*) confrontée à la *Stack* « MySQL — **version du serveur client** » | **Contradiction interne.** Si la version de MySQL est celle du serveur de chaque client, aucune CI ne peut être identique à toutes les productions. Il manque une version plancher garantie par le socle. | moyenne |
| Dérivabilité — « rien dans le socle ne suppose un client particulier ; toute configuration propre à un dérivé vit dans des variables d'environnement ou des fichiers prévus pour être modifiés » | Ligne *Configuration* donne la règle de répartition (env / `app.` / constante) et AD-13 garantit qu'un module ne touche pas `src/Core/`. Manque l'**inventaire** de ce qu'un dérivé doit configurer — nom et marque du produit, SMTP, langues actives initiales, durées de jetons, commande d'agent (AD-11) — alors que c'est la liste qu'un développeur ouvre le premier jour (SM-1). La marque est par ailleurs tranchée comme « du code dans le dérivé » par le spine UX, ce que le spine d'architecture ne reprend pas. | moyenne |

---

## 3. Contraintes et garde-fous (§6)

| Exigence source | Atterrissage dans le spine | Sévérité |
| --- | --- | --- |
| Données personnelles — « le journal d'audit contient des noms et des actions ; la désactivation ne supprime rien » | AD-12 + AD-9. Couvert. | basse |
| Données personnelles — « Le droit à l'effacement est satisfait par l'anonymisation (FR-20) : l'historique reste, la personne n'y est plus identifiable » | **Non tenu** par le spine (voir FR-20 : les valeurs avant/après échappent à l'anonymisation). | critique |
| Coût — « le socle ne requiert aucun service externe payant `[SMTP du client ou de l'équipe]` » | Honoré et explicite : « Aucun conteneur, aucun service externe payant » (légende du diagramme de déploiement), `SMTP du client` en production, Mailpit en développement, transport Messenger Doctrine plutôt qu'un courtier, sessions en fichiers (AD-9), aucun Redis. Contrainte de coût intégralement respectée. | basse |
| Dérivation — « le socle se clone et diverge ; il n'est pas mis à jour dans les dérivés, les correctifs se reportent à la main » | AD-2 (« le report d'un correctif du socle vers un dérivé est un diff sur `src/Core/`, et sur rien d'autre ») + AD-13 (« Ajouter un module ne modifie aucun fichier de `src/Core/` ») + *Deferred* (noyau partagé à rouvrir à des dizaines de dérivés, reprenant la formulation du §6 et du brief). Le meilleur exemple d'une contrainte amont convertie en invariant opérationnel. | basse |

---

## 4. Non-objectifs (§7) — le spine en contredit-il un, ou ouvre-t-il une porte ?

| Non-objectif | Traitement par le spine | Sévérité |
| --- | --- | --- |
| Interface commune à plusieurs dérivés / compte valable sur plusieurs | *Deferred* : « Multi-tenant et compte valable sur plusieurs dérivés. Hors périmètre (PRD §7) ». Respecté. | basse |
| Mise à jour du socle dans les dérivés déjà clonés | Respecté par AD-2. Nuance : AD-1 écrit « Un dérivé ne monte pas de majeure Symfony de son propre chef : la montée est décidée sur le socle et reportée » — une gouvernance centralisée des versions qui frôle la mise à jour du socle dans les dérivés, sans mécanisme de report. À assumer comme une règle d'équipe, pas comme une capacité du produit. | basse |
| Multi-tenant | *Deferred*. Aucune porte ouverte : pas de notion de locataire dans l'ER, un déploiement par client. Respecté. | basse |
| Facturation, comptabilité, tout module métier | AD-2 / AD-13 définissent l'accueil d'un module sans en livrer aucun. Respecté. Point de vigilance : la mécanique d'extension (contrats publiés, services taggés, commande de synchronisation, deptrac) est un coût porté par le socle pour des modules qui n'existent pas encore — voir SM-C1 au §8. | basse |
| L'application mobile | Respecté au sens strict (aucune application), mais AD-5 **rétrécit l'amorce d'API** que le PRD et le brief conservaient pour elle (voir FR-17). | haute |
| L'exécution d'agents côté serveur | AD-11 (`#[When('dev')]`, absence du conteneur et de la table de routage) va plus loin que le non-objectif : il le rend structurellement impossible. *Deferred* garde « Exécuteur d'agents côté serveur » pour plus tard. Respecté exemplairement. | basse |
| Toute écriture dans les fichiers BMAD par l'application | AD-10 : « L'application n'écrit jamais dans le dépôt ; seul `var/` est inscriptible ». Respecté. Le cache de parse vit dans `var/`, donc sans conflit. | basse |
| Les commentaires ou demandes du client depuis la roadmap | Aucune trace, aucune porte. Respecté. | basse |
| La connexion par fournisseur externe (Google, Microsoft) | Aucune trace, aucun bundle OAuth dans la *Stack*. Respecté. | basse |
| §8.2 Hors périmètre MVP — gestion documentaire, notifications de changement de statut, rétention de l'audit | Aucun de ces trois n'est amorcé. AD-4 installe une infrastructure d'envoi qui *pourrait* servir aux notifications, mais le spine ne les évoque pas. Rétention reportée dans *Deferred* (OQ-2). Respecté. | basse |

---

## 5. Addendum du PRD — décisions marquées « Arrêté »

| Décision *Arrêté* | Honorée ? | Sévérité |
| --- | --- | --- |
| **Décision ouverte en tête** — « la version de Symfony du socle … À trancher en premier en architecture » | **Tranchée** : AD-1 (Symfony 7.4 LTS, PHP 8.4, aucune dépendance exigeant Symfony 8). La conséquence annoncée par l'addendum est tirée : la *Stack* écarte nommément `damienharper/auditor-bundle` (branche 7.x exigeant Symfony 8) et `scheb/2fa` 8.6 est retenu en version compatible. | basse |
| **2FA** — `scheb/2fa` v8.6, modules TOTP, email, codes de secours | Honorée (*Stack*, Capability Map FR-5). Réserve : l'entité `TWO_FACTOR_METHOD` du Structural Seed s'écarte du modèle attendu par le bundle (voir FR-5). | moyenne |
| **Permissions** — voters Symfony, trois entités (permission / rôle+défauts / surcharge par utilisateur qui prime) | Honorée et renforcée : AD-8 (voter **unique**, calcul par requête, mémoïsation de requête, aucun test de nom de rôle, catalogue sourcé par le code) + ER conforme aux trois entités. | basse |
| **API** — sans API Platform ; jeton opaque en base, révocable par l'utilisateur et par un administrateur ; JWT sans état exclu par l'exigence de révocation | Honorée sur la forme du jeton (AD-5 : opaque, stocké **haché**, révocable par les deux) et sur l'absence d'API Platform (aucune dépendance). **Silencieusement dépassée** sur deux points non couverts par l'*Arrêté* mais exigés par FR-17 : le mode d'obtention et l'expiration. | (voir FR-17) |
| **Langues** — composant Translation, catalogues FR/EN/NL, langue de l'utilisateur en champ du compte, langues actives configurables depuis l'interface | Honorée intégralement par AD-14, y compris la distinction code/base que l'addendum n'avait pas formalisée. | basse |
| **Journal d'audit — actions métier** — service dédié appelé depuis la couche service, code d'action stable, libellé traduisible, objet concerné | Honorée par AD-3 et la convention *Audit*. Réserve : la liste de référence des actions (FR-12) n'est pas reprise, et deux de ses événements ne peuvent pas naître d'un service (voir FR-12). | (voir FR-12) |

---

## 6. Addendum du PRD — items « À trancher en architecture »

| Item | Tranché par le spine ? | Sévérité |
| --- | --- | --- |
| **Invitation** — « aucun bundle maintenu pour Symfony 7/8 … `symfonycasts/verify-email-bundle` ou les Login Links sont candidats pour signer l'URL. *À trancher : le mécanisme de signature.* » | **Tranché** par AD-15, et pour la bonne raison : l'URL signée sans état est exclue parce qu'elle ne peut ni marquer un lien consommé, ni invalider le précédent, ni porter les états « en attente » / « expirée ». Les deux candidats disparaissent donc légitimement de la *Stack*. Réponse complète et motivée. | basse |
| **Journal d'audit — modifications** — « candidats et contraintes de version dans l'addendum du brief ; Gedmo Loggable écarté (lié à DBAL 3). *À trancher selon la version de Symfony du socle.* » | **Tranché** par AD-3 (écriture à la main, listener `onFlush`, aucun bundle) et motivé par l'unicité du magasin. Réserve : seul `damienharper/auditor-bundle` est écarté nommément ; `rcsofttech/audit-trail-bundle`, que l'addendum du brief signale comme compatible Symfony 7.4 / PHP ≥ 8.4 — donc avec AD-1 — n'est ni nommé ni rejeté. Le candidat le plus gênant pour la décision est celui qu'on ne voit pas. | moyenne |

---

## 7. Contrat d'entrée BMAD (addendum du PRD)

| Élément du contrat | Atterrissage dans le spine | Sévérité |
| --- | --- | --- |
| « Source : `_bmad-output/implementation-artifacts/sprint-status.yaml`, généré par `bmad-sprint-planning` à partir des epics » | **Nulle part.** AD-10 parle de « ces fichiers » sans en nommer un seul ; le Structural Seed ne montre que `_bmad-output/`. Le fichier qui porte le statut — la donnée la plus structurante de la roadmap — n'est pas identifié. | haute |
| « Clés observées : `epic-{n}`, `{n}-{m}-{titre}` pour une story, `epic-{n}-retrospective` » | **Nulle part.** Conséquence directe sur FR-15 : `epic-{n}-retrospective` comptée comme une tâche fausse l'avancement ; `{n}-{m}-{titre}` est la seule source du rattachement d'une tâche à son epic et de son ordre. | haute |
| « Les fichiers d'epics et de stories vivent dans `_bmad-output/planning-artifacts/` et `_bmad-output/implementation-artifacts/` » | Partiellement : le Structural Seed montre `_bmad-output/` embarqué. La répartition entre les deux sous-dossiers, et donc d'où viennent titres, résumés et notes internes (FR-15), n'est pas dite. | moyenne |
| « Forme exacte à confirmer sur les premiers fichiers produits par ce projet » | AD-10 répond par le parse tolérant — bonne réponse de principe. Mais aucune décision sur la **validation** du contrat : pas de schéma, pas de commande de diagnostic, pas de test sur un jeu de fichiers de référence, alors que le contrat est externe et évoluera avec BMAD. | moyenne |
| « Statuts BMAD observés : les cinq de FR-15 ; **jamais de rétrogradation** » | **Nulle part.** L'invariant de non-rétrogradation est une information exploitable (cache, avancement monotone, détection d'anomalie) que le spine n'utilise ni ne consigne. | basse |
| « Priorité, difficulté, assignation, dépendances : voir OQ-3 ; si absentes des fichiers BMAD, **convention d'en-tête YAML dans les stories du projet** » | AD-10 les rend optionnelles et « non renseigné ». La porte de sortie proposée par l'addendum — la convention d'en-tête — n'est ni adoptée ni rejetée (voir FR-15, sévérité haute). | haute |

---

## 8. Addendum du brief — conséquences « (architecture) » et paysage

| Élément | Atterrissage dans le spine | Sévérité |
| --- | --- | --- |
| « le format des fichiers BMAD (epics, stories, sprint status) est un **contrat d'entrée, à parser de façon tolérante** » | AD-10, mot pour mot dans l'esprit (« parse ces fichiers de façon tolérante », « jamais une erreur »). Honoré — sauf que le contrat n'est pas nommé (§7). | basse |
| « **voters Symfony** ; le modèle de données distingue « hérité du rôle » et « surchargé » » | AD-8 + ER (`ROLE_PERMISSION` / `PERMISSION_OVERRIDE`). Honoré. | basse |
| « les actions métier explicites sont **déclenchées depuis les services, jamais depuis les contrôleurs** » | AD-3 + convention *Audit*, littéralement. Honoré — la liste de FR-12 le met en défaut pour « connexion » et « échec de connexion » (voir FR-12). | basse |
| Paysage — « **Aucun socle ERP Symfony 7/8 maintenu et dérivable** trouvé ; Sylius est le seul modèle sérieux de « core + plugins-bundles » mais orienté e-commerce » | Le paradigme « deux racines » est une variante de *core + modules*, mais le spine ne reconnaît ni le précédent Sylius ni l'absence de base dérivable existante — alors que l'addendum adresse explicitement le paysage à l'architecture (« paysage des solutions existantes → architecture »). Les raisons sont renvoyées à `.memlog.md` ; la lignée de la décision n'est donc pas lisible dans le document qui engage les builders. Choix assumé, à noter. | basse |
| Paysage — « Gedmo Loggable — incompatible DBAL ≥ 4 : à écarter » | Honoré de fait (AD-3, aucun bundle d'audit ; DBAL 4.4 en *Stack*), sans le nommer. | basse |
| Paysage — « Aucun bundle ne couvre le journal d'actions métier explicites : couche à écrire dans les services » | AD-3. Honoré. | basse |
| Paysage — « `hakam/multi-tenancy-bundle` (pour mémoire, hors périmètre) » | Absent de la *Stack*, multi-tenant dans *Deferred*. Honoré. | basse |
| Paysage — « recherche web du 2026-09-09, **à revérifier avant usage** » | Honoré explicitement : « Versions vérifiées sur `symfony.com/releases.json`, Packagist et les dépôts amont le 2026-09-09 ». | basse |
| Déclenchement des agents — *Parqué* : « file de travaux côté serveur **dépilée en local**, ou exécuteur d'agents, quand le volume le justifiera » | *Deferred* reprend « Exécuteur d'agents côté serveur » mais pas la variante « file côté serveur dépilée en local », qui est la moins coûteuse des deux et la plus proche d'AD-4. Report partiel. | basse |
| Données de coût — « la semaine de démarrage est une estimation de Fabrice, non mesurée » ; « socle rentabilisé dès le premier dérivé (non mesuré) » | Hors périmètre du spine, aucun besoin d'atterrissage. | basse |

---

## 9. Questions ouvertes et index des hypothèses

| Élément | Traitement par le spine | Sévérité |
| --- | --- | --- |
| **OQ-2** — rétention du journal d'audit | **Reporté explicitement** : *Deferred* « Rétention et purge du journal d'audit (OQ-2). Quand le volume l'imposera ». Traitement correct. Réserve : le report devient fragile faute de budget de performance à 1 M d'entrées (NFR) — c'est le volume qui devait déclencher la réouverture, et rien ne le mesure. | moyenne |
| **OQ-3** — champs BMAD (priorité, difficulté, assignation, dépendances) | **Neutralisé, pas tranché** : AD-10 les rend optionnelles, donc « OQ-3 non bloquante ». Mais les dépendances portent « tâche prête » (FR-15, FR-16, UJ-5) : sans elles, une fonctionnalité du MVP perd son sens. La question reste ouverte sans propriétaire ni échéance, alors que le PRD lui en donnait deux (« Propriétaire : Fabrice ; à trancher dès que `bmad-create-epics-and-stories` aura produit les premiers fichiers, **avant les stories de la roadmap** »). | haute |
| **OQ-5** — permissions par défaut des rôles Admin et User | **Reporté explicitement** : *Deferred* « À observer sur les premiers dérivés ». Traitement correct. Réserve : le report suppose un seed de rôles modifiable par dérivé, qui n'est pas décidé (voir FR-1). | moyenne |
| §10 — « Décisions prises pendant le PRD : OQ-1 → anonymisation (FR-20) ; **OQ-4 → correspondance des statuts fixée dans FR-15** » | OQ-1 atterrit dans AD-12. **OQ-4 n'atterrit nulle part** : la table de correspondance des cinq statuts BMAD vers les trois statuts lisibles, décision déjà prise en amont, n'est reprise par aucun AD ni aucune convention (voir FR-15). | moyenne |
| §11 — hypothèse « notes internes = contenu d'une story au-delà du titre et du résumé » | Permission `roadmap.notes` existe ; la **définition** à implémenter dans le lecteur d'AD-10 n'est pas formalisée. | moyenne |
| §11 — ralentissement des échecs de connexion (doublement à partir du cinquième, plafond 15 min) | **Nulle part** (voir FR-3). Hypothèse la plus lourde de l'index, et la seule qui exige une dépendance absente de la *Stack*. | haute |
| §11 — lien de réinitialisation valable une heure ; expiration des invitations à sept jours | Mécanisme couvert (AD-15), **durées et leur configurabilité nulle part**. | moyenne |
| §11 — méthodes 2FA : TOTP et code par email | *Stack* (`scheb/2fa-bundle (totp, email, backup-code)`). Couvert. | basse |
| §11 — tous les objets métier audités par défaut, exclusion explicite possible | Convention *Audit* + AD-13. Couvert. | basse |
| §11 — export du journal d'audit en CSV | **Nulle part** (voir FR-13). | haute |
| §11 — priorité, difficulté, assignation, dépendances portées par l'en-tête des stories | Non adopté, non rejeté (voir OQ-3). | haute |
| §11 — permission « notes internes » au seul Super admin par défaut | Dépend du seed des rôles, non décidé (voir FR-1). | moyenne |
| §11 — jeton d'accès expirant après une heure par défaut | **Perdue** (voir FR-17). | haute |
| §11 — langue de repli : première active dans l'ordre fr, en, nl | **Nulle part** (voir FR-19/FR-22). | moyenne |
| §11 — dimensionnement : 50 utilisateurs, 1 million d'entrées d'audit | **Nulle part.** Aucune trace du dimensionnement de référence, qui est pourtant ce qui rend le p95 vérifiable et l'indexation dimensionnable. | haute |
| §11 — envoi d'email par le SMTP du client ou de l'équipe, aucun service payant | Honoré (diagramme de déploiement, AD-4). | basse |
| §11 — pas de connexion par fournisseur externe en v1 | Honoré (aucune dépendance, aucun AD). | basse |
| §11 dans son ensemble — « les hypothèses non confirmées sont balisées `[ASSUMPTION]` … et indexées en §11 » | Le spine ne mentionne **aucune** hypothèse amont et n'en confirme ni n'en infirme aucune, alors que le spine UX tient sa propre liste `[ASSUMPTION]` / `[ASSUMPTION PRD]` et renvoie plusieurs fois au PRD. Le document aval qui consomme le plus ces hypothèses n'en accuse pas réception : rien ne dit à un builder lesquelles sont devenues des décisions. | moyenne |

---

## 10. Exigences discrètes — ce que la structure en décisions numérotées pouvait perdre

| Exigence source | Atterrissage dans le spine | Sévérité |
| --- | --- | --- |
| **SM-C1** (contre-métrique) — « Taille du socle : ne pas y ajouter un module tant que deux dérivés ne l'ont pas réclamé. Contrebalance SM-1 : un socle plus gros démarre moins vite » ; brief : « Aucun module commun n'est engagé dans la première version tant qu'un dérivé ne l'a pas réclamé **deux fois** » | **Nulle part.** Le spine n'énonce aucune règle d'admission dans `src/Core/`, alors qu'AD-13 crée précisément le mécanisme par lequel du code s'y ajoute et que la règle « deux dérivés l'ont réclamé » est la seule barrière du brief et du PRD contre l'embonpoint. C'est une contrainte de gouvernance qu'un spine d'architecture peut porter (critère d'entrée dans `Core/Contract/`). | moyenne |
| **SM-C2** (contre-métrique) — « Volume du journal d'audit : ne pas chercher à tout tracer au point de rendre le journal **illisible pour Sophie** (UJ-4) » | **Nulle part**, et le spine pousse dans l'autre sens : convention *Audit* « Tout objet métier est audité par défaut » + AD-3 table unique + FR-12. Un seul `flush()` touchant cinq objets produit cinq entrées indépendantes ; rien ne les corrèle (pas d'identifiant de transaction, pas de regroupement par requête), alors qu'UJ-4 demande de lire « l'entrée » d'un changement de tarif juste au-dessus d'une action métier. La contre-métrique n'a aucun levier architectural. | moyenne |
| **Lisibilité non technique** — FR-13 « pas d'identifiants internes bruts » ; FR-14/FR-15 « vocabulaire sans jargon BMAD » ; brief addendum « ses utilisateurs en production ne sont pas techniciens » ; UJ-3 « Il n'a jamais entendu parler de BMAD » | Partiellement : AD-12 couvre la résolution et la traduction des libellés d'audit. Côté roadmap, la traduction du vocabulaire BMAD (epic, story, statuts, `sprint-status`) vers la langue du client n'a pas de porteur architectural — le lecteur unique d'AD-10 rend des DTO Read, sans qu'on sache s'il rend du vocabulaire BMAD ou du vocabulaire produit. Le cas des objets supprimés casse la règle (voir FR-11). | haute |
| **Contrainte de coût** §6 — aucun service externe payant | Intégralement honorée (voir §3). À signaler comme un point fort. | basse |
| §8.3 **Ordre de livraison** — « D'abord FR-1, FR-2, FR-3, FR-4, FR-7, FR-14, FR-15 ; puis FR-11 à FR-13 ; puis le reste » | **Nulle part**, ce qui est en soi défendable (c'est le travail des epics). Mais l'ordre a une conséquence architecturale : FR-14/FR-15 arrivent dans la première vague alors qu'AD-10 dépend d'un contrat BMAD non nommé (§7) et qu'AD-8 impose une commande de synchronisation dès FR-7. Un mot sur ce qui doit exister avant la première story aurait sa place. | basse |
| **UJ-5** — « l'application démarre les agents sur son poste avec la story correspondante et **lui confirme que le lancement a eu lieu** » | AD-11, littéralement. Couvert. | basse |
| **UJ-1** — « Il ouvre l'application sur son Laragon » | Diagramme d'environnement (serveur de dev Symfony HTTPS, MySQL natif, Mailpit, worker), conforme à `symfony-proglab-local-dev`. Couvert. | basse |
| **UJ-3** — « le dernier déploiement date de mardi ; la page l'indique, pour que Marc ne prenne pas la vue pour du temps réel » | AD-10 + « la date de la release est la "date du dernier déploiement" ». Couvert. | basse |
| **Glossaire** §3 — « **Administrateur** : utilisateur dont le **rôle** porte la permission d'administrer les comptes » | Tension de vocabulaire avec AD-8 (« Aucun code ne teste un nom de rôle ») : le PRD définit plusieurs notions par le rôle (administrateur, obligation de 2FA, défaut des notes internes). Le spine impose le raisonnement par permission sans fournir la table de traduction des notions du Glossaire vers des codes de permission. | moyenne |
| **Brief** — « Documentation du socle. **Complète**, pensée d'abord pour que les agents (BMAD, skills proglab) s'y retrouvent dans un dérivé sans Fabrice » | Voir FR-2 : « racine du dépôt » ne nomme aucun fichier, et rien ne garantit la complétude. | moyenne |

---

## 11. Contradictions internes relevées au passage

Elles ne sont pas des écarts amont, mais elles bloqueront un builder qui lit le spine
comme une source unique.

| Point | Description | Sévérité |
| --- | --- | --- |
| AD-8 « aucun code ne teste un nom de rôle » vs FR-5 / Glossaire | L'obligation de 2FA et la notion d'« administrateur » sont définies par rôle dans le PRD. Aucune passerelle. | haute |
| AD-12 « référence par identifiant » vs ER `USER ||--o{ AUDIT_ENTRY : "auteur de"` | Le diagramme présente une relation là où AD-12 refuse la clé étrangère, et l'acteur système n'a pas de ligne à référencer. | moyenne |
| *Stack* « MySQL — version du serveur client » vs *Tests* « Base de données de CI identique à la production » | Inconciliables sans version plancher. | moyenne |
| *Deferred* « Cache … sur lenteur mesurée seulement » vs NFR p95 < 1 s à 1 M d'entrées | Un budget contractuel traité comme une réaction après coup. | haute |
| AD-5 « FR-17 … doit être corrigé » | Le spine demande la correction d'un document `status: final` sans que personne ne soit désigné pour la faire, et sans consigner l'arbitrage ailleurs que dans sa propre règle. | critique |

---

## 12. Synthèse par sévérité

**Critique (6)**

1. FR-20 + §6 — l'anonymisation ne couvre pas les valeurs avant/après des entrées
   d'audit : le seul mécanisme d'effacement du PRD ne tient pas.
2. FR-11 + FR-13 — un objet supprimé n'a plus de libellé résoluble à la lecture
   (AD-12) : l'audit retombe sur des identifiants internes bruts, que FR-13 interdit.
3. Brief addendum — le filtrage des **entrées** d'audit par les droits du lecteur
   n'existe pas : l'audit contourne les 403/404 de FR-10.
4. FR-13 + NFR performance — filtres sur cinq axes, 1 M d'entrées, résolution des
   libellés à la lecture, pagination par `OFFSET`, cache reporté : aucun moyen pour le
   budget p95.
5. FR-12 — la liste de référence des treize actions auditées n'atterrit nulle part, et
   deux de ses entrées (connexion, échec de connexion) violent les règles du spine.
6. FR-17 / AD-5 — deux documents finaux se contredisent, sans correction ni arbitrage
   consigné.

**Haute (16)** — FR-1 (refus sur base peuplée, collision dry-run ; seed des rôles) ;
FR-3 (ralentissement des échecs, dépendance absente) ; FR-5 (porte 2FA obligatoire vs
AD-8 ; délai de grâce adopté en silence) ; FR-7 (« Super admin a toutes les
permissions » vs catalogue sourcé par le code) ; FR-10 (aucune garantie d'exhaustivité
de la vérification) ; FR-13 (valeurs lisibles ; export CSV) ; FR-15 (avancement et clé
`epic-{n}-retrospective` ; dépendances et « tâche prête ») ; FR-17 (utilisateur
désactivé côté API ; expiration du jeton) ; brief (amorce d'API pour le mobile) ;
NFR sécurité (CSRF) ; NFR performance (budget et dimensionnement) ; contrat BMAD
(fichier et clés non nommés ; en-tête YAML) ; OQ-3 ; lisibilité non technique de la
roadmap.

**Moyenne (33)** et **basse (44)** — détaillées dans les tableaux ci-dessus.

**Ce que le spine honore sans réserve** — les cinq décisions *Arrêté* de l'addendum du
PRD ; la décision ouverte en tête (version de Symfony) tranchée en AD-1 ; les deux items
*À trancher en architecture* (AD-15, AD-3) ; les trois conséquences *(architecture)* de
l'addendum du brief ; les neuf non-objectifs du §7, dont deux rendus structurellement
impossibles (AD-10, AD-11) ; la contrainte de coût du §6 ; la contrainte de dérivation
du §6 convertie en invariant opérationnel (AD-2) ; OQ-2 et OQ-5 reportées explicitement.
