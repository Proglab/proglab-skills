---
title: PRD — Socle ERP custom (proglab)
status: final
created: 2026-09-09
updated: 2026-09-10
---

# PRD : Socle ERP custom (proglab)

## 0. Objet du document

Ce PRD s'adresse à Fabrice (pilote et premier développeur), aux développeurs qui
dériveront le socle, et aux workflows en aval — UX, architecture, epics et stories. Il
part du brief validé (`_bmad-output/planning-artifacts/briefs/brief-proglab-skills-2026-09-09/`)
et ne le répète pas : le brief dit pourquoi, ce document dit quoi. Le vocabulaire est
fixé au Glossaire (§3) ; les fonctionnalités sont groupées avec leurs exigences
fonctionnelles (FR) numérotées globalement dans l'ordre de leur ajout ; les hypothèses
non confirmées sont balisées `[ASSUMPTION]` en ligne et indexées en §11. Les choix
techniques (bundles, mécanismes) vivent dans `addendum.md`, pas ici.

**Révision du 2026-09-10.** Le run d'architecture a contredit deux points de ce
document, corrigés ici : FR-17 (l'API n'émet pas de jeton) et la règle de dérivation du
§6 (un dérivé livré est maintenu). Les décisions correspondantes sont AD-5 et AD-1 de
`../../architecture/architecture-proglab-skills-2026-09-09/ARCHITECTURE-SPINE.md`.

## 1. Vision

Chaque ERP custom livré à une PME commence par la même semaine de travail. Le socle la
fait une bonne fois : un dépôt Symfony que l'on clone au démarrage de chaque projet et
que l'on fait diverger librement, contenant déjà la connexion, les utilisateurs et
leurs permissions, le journal d'audit, la roadmap du projet et l'amorce d'une API. Le
travail spécifique au client commence le premier jour.

Le socle n'est pas le produit vendu : la PME achète son ERP. Le socle est ce qui rend
cette livraison tenable projet après projet et répétable quand l'équipe passe d'une
personne à cinq. Il reste interne : ni vendu, ni ouvert. Le brief porte le reste.

## 2. Utilisateurs cibles

### 2.1 Tâches à accomplir (JTBD)

- **Le développeur qui dérive** : partir d'une base qui fonctionne, comprendre où sont
  les choses sans demander, livrer du spécifique le premier jour, et savoir quelle tâche
  prendre ensuite.
- **L'administrateur du dérivé** (Fabrice ou un développeur au démarrage, puis un
  responsable côté PME) : faire entrer les bonnes personnes, leur donner exactement les
  droits qu'il faut, et pouvoir répondre à « qui a fait ça ? ».
- **L'utilisateur de la PME** : se connecter sans friction, dans sa langue, prendre
  l'outil en main sans formation lourde, et n'y voir que ce qui le concerne.
- **Le client (direction de la PME)** : voir où en est la construction de son ERP sans
  rien comprendre à BMAD, et vérifier ce qui s'est passé dans son outil.

Non-utilisateurs de la v1 : voir §7.

### 2.2 Parcours utilisateurs clés

- **UJ-1. Fabrice démarre l'ERP d'un nouveau client.**
  Lundi matin, Fabrice clone le socle dans un nouveau dépôt, lance la commande
  d'initialisation qui prépare la base, applique les migrations et crée le premier
  compte Super admin. Il ouvre l'application sur son Laragon, se connecte, arrive sur
  la page d'accueil — la roadmap, encore vide — d'un ERP fonctionnel, et invite le
  responsable de la PME. L'après-midi, il écrit la première entité métier du client.
  **Cas limite** : la commande d'initialisation détecte une base déjà peuplée et refuse
  d'écraser sans confirmation explicite.

- **UJ-2. Sophie, responsable administrative de la PME, fait entrer un collègue.**
  Sophie a reçu une invitation par email, a créé son mot de passe et activé sa double
  authentification au premier passage. Admin de l'ERP, elle invite Karim en lui donnant
  le rôle User, puis lui ajoute une permission que ce rôle n'a pas — consulter le
  journal d'audit — parce qu'il la remplacera pendant ses congés. Sur la fiche de
  Karim, chaque permission indique son origine : héritée du rôle, ajoutée, ou retirée.
  **Cas limite** : Karim ne clique pas sur l'invitation dans le délai ; Sophie la
  renvoie d'un clic ; l'ancien lien est invalidé.

- **UJ-3. Marc, gérant, regarde où en est son ERP.**
  Marc se connecte le vendredi soir. Sa page d'accueil montre la roadmap : quatre
  epics avec leur pourcentage d'avancement, et sous chacune la liste des tâches avec
  leur statut lisible (à faire, en cours, terminé), leur priorité, leur difficulté, la
  personne assignée et leurs dépendances. Il voit que « Gestion des devis » est à 60 %
  et que la tâche suivante attend qu'une autre, encore en cours, soit terminée. Il n'a
  jamais entendu parler de BMAD et n'a pas besoin de l'apprendre. **Cas limite** : le
  dernier déploiement date de mardi ; la page l'indique, pour que Marc ne prenne pas
  la vue pour du temps réel.

- **UJ-4. Sophie cherche qui a modifié un tarif.**
  Un prix a changé sans que personne ne s'en souvienne. Sophie ouvre le journal
  d'audit, filtre sur l'objet « Tarif » et sur la semaine passée, et voit l'entrée :
  Karim, mercredi 14 h 12, champ « prix unitaire », ancienne valeur, nouvelle valeur.
  Juste au-dessus, une action métier : « Karim a validé le devis D-2026-041 ». Elle
  exporte la sélection pour l'envoyer au comptable. **Cas limite** : Karim a été
  désactivé entre-temps ; ses actions restent sous son nom, marqué « compte
  désactivé ».

- **UJ-5. Léa, développeuse, prend la prochaine tâche.**
  Léa fait tourner le dérivé sur son poste. Dans la roadmap, elle ouvre l'epic en cours
  et voit la prochaine tâche prête, dont toutes les dépendances sont terminées. Elle
  clique sur « lancer » : l'application démarre les agents sur son poste avec la story
  correspondante et lui confirme que le lancement a eu lieu. Les agents passent la
  tâche en cours et l'assignent à Léa dans les fichiers BMAD, puis y consignent la fin
  du travail ; la roadmap le reflète au rechargement. **Cas limite** : la tâche a une
  dépendance non terminée ; le bouton est inactif et dit laquelle.

## 3. Glossaire

- **Socle** — le dépôt Symfony de référence que l'on clone pour démarrer un ERP.
- **Dérivé** — un ERP client issu d'un clonage du socle ; diverge librement ensuite.
- **Utilisateur** — une personne disposant d'un compte dans un dérivé. A exactement un
  **rôle** et zéro ou plusieurs **surcharges**. Peut être **actif** ou **désactivé**.
- **Administrateur** — utilisateur dont le rôle porte la permission d'administrer les
  comptes et les permissions ; par défaut les rôles **Super admin** et **Admin**.
- **Super admin** — rôle par défaut de l'équipe qui construit le dérivé : toutes les
  permissions, dont celles réservées au développement.
- **Admin** — rôle par défaut du responsable côté PME : administre les comptes et les
  permissions, consulte la roadmap et le journal d'audit.
- **User** — rôle par défaut de tout autre utilisateur de la PME.
- **Client** — la direction de la PME pour laquelle le dérivé est construit ; ce sont
  des utilisateurs (Admin ou User), distingués par leurs permissions, pas par une
  nature différente.
- **Rôle** — un ensemble nommé de **permissions** par défaut. Un utilisateur a un rôle.
- **Permission** — le droit d'accomplir une action ou d'accéder à une zone, identifié
  par un code stable.
- **Surcharge** — l'ajout ou le retrait d'une permission pour un utilisateur donné, qui
  prime sur ce que son rôle prévoit.
- **Origine d'une permission** — pour un utilisateur, l'une de : héritée du rôle,
  ajoutée par surcharge, retirée par surcharge.
- **Invitation** — un lien à usage unique et à durée limitée envoyé par email, qui
  permet à une personne de créer son compte avec un rôle prédéfini.
- **Double authentification (2FA)** — second facteur exigé à la connexion après le
  mot de passe.
- **Journal d'audit** — l'ensemble des **entrées d'audit** d'un dérivé.
- **Entrée d'audit** — un enregistrement immuable de qui a fait quoi et quand. De deux
  types : **modification** (changement d'un objet, avec les champs avant/après) ou
  **action métier** (événement nommé déclenché par le code, par exemple « devis
  validé »).
- **Roadmap** — la vue, dans le dérivé, des **epics** et des **tâches** du projet,
  lue depuis les fichiers BMAD du dépôt.
- **Epic** — un regroupement de tâches portant un objectif ; a un avancement.
- **Tâche** — l'unité de travail de la roadmap ; correspond à une story BMAD. Porte un
  statut, une priorité, une difficulté, une personne assignée et des dépendances.
- **Tâche prête** — tâche au statut lisible « à faire » dont toutes les dépendances
  sont terminées.
- **Notes internes** — le contenu d'une story au-delà de son titre et de son résumé
  `[ASSUMPTION: définition à confirmer sur les premiers fichiers produits]`.
- **Statut lisible** — la traduction d'un statut BMAD en l'un de trois états montrés
  aux utilisateurs : à faire, en cours, terminé.
- **Lancer une tâche** — l'action, disponible seulement en environnement de
  développement, qui démarre les agents chargés de réaliser une tâche prête.
- **Environnement de développement** — un dérivé qui tourne sur le poste d'un
  développeur, par opposition à la **production** déployée chez le client.
- **API** — l'interface JSON du dérivé destinée à de futures **applications clientes**
  (hors navigateur).

## 4. Fonctionnalités

### 4.1 Initialisation d'un dérivé

**Description :** un dérivé fraîchement cloné doit devenir un ERP fonctionnel en une
commande, pour que le développeur écrive du spécifique le premier jour.

#### FR-1 : Initialiser un dérivé en une commande

Un développeur peut, depuis un clone du socle, exécuter une seule commande qui prépare
la base de données, applique les migrations, charge les rôles par défaut et crée le
premier Super admin. Réalise UJ-1.

**Conséquences (testables) :**
- Sur une base vide, la commande termine sans autre intervention que la saisie des
  identifiants du premier Super admin.
- Sur une base déjà peuplée, la commande refuse d'agir sans une option de confirmation
  explicite.
- À la fin, le Super admin peut se connecter et voit la roadmap (vide).

#### FR-2 : Guide de dérivation

Un développeur trouve dans le dépôt une documentation qui explique comment le socle est
organisé, comment le dériver, où ajouter un module métier et quelles conventions
s'appliquent — rédigée pour être lue aussi bien par un humain que par un agent.

**Conséquences (testables) :**
- Un agent d'implémentation chargé d'ajouter une entité métier trouve dans cette
  documentation, sans autre source, l'emplacement et les conventions à respecter.
- La documentation nomme les skills proglab et les workflows BMAD à invoquer.

### 4.2 Comptes et connexion

**Description :** l'entrée dans le dérivé. Comptes locaux, invitation par un
administrateur, double authentification, désactivation sans suppression, anonymisation
sur demande.

#### FR-3 : Se connecter avec email et mot de passe

Un utilisateur actif peut se connecter avec son email et son mot de passe.

**Conséquences (testables) :**
- Un utilisateur désactivé ne peut pas se connecter et voit un message qui le dit sans
  révéler si le mot de passe était correct.
- Les échecs répétés sont ralentis `[ASSUMPTION: délai doublé à chaque échec à partir
  du cinquième, plafonné à quinze minutes, sans blocage définitif]`.
- Un utilisateur peut demander la réinitialisation de son mot de passe par un lien
  email à usage unique `[ASSUMPTION: valable une heure]`.

#### FR-4 : Inviter un utilisateur

Un administrateur peut inviter une personne par email en lui attribuant un rôle ; la
personne crée son compte via le lien d'invitation. Réalise UJ-2.

**Conséquences (testables) :**
- Le lien est à usage unique et expire `[ASSUMPTION: après sept jours]`.
- Un administrateur peut renvoyer une invitation ; le lien précédent devient invalide.
- Une invitation acceptée crée un utilisateur actif avec le rôle prévu et une entrée
  d'audit « invitation acceptée ».
- Seuls les administrateurs voient et utilisent la fonction d'invitation.

#### FR-5 : Double authentification

La double authentification est obligatoire pour les rôles Super admin et Admin, et
optionnelle pour les autres : un User peut l'activer depuis son profil.

**Conséquences (testables) :**
- Un Super admin ou un Admin sans 2FA activée est conduit à l'activation avant tout
  autre écran ; un utilisateur qui reçoit l'un de ces rôles après coup y est conduit à
  sa connexion suivante.
- Un utilisateur dont la 2FA est active fournit son second facteur à chaque
  connexion, quel que soit son rôle.
- Deux méthodes au moins sont proposées `[ASSUMPTION: application TOTP et code par
  email]`, plus des codes de secours.
- Un administrateur peut réinitialiser la 2FA d'un utilisateur qui a perdu son second
  facteur ; l'opération crée une entrée d'audit.

#### FR-6 : Désactiver un utilisateur

Un administrateur peut désactiver un utilisateur ; rien n'est supprimé.

**Conséquences (testables) :**
- Un utilisateur désactivé ne peut plus se connecter ; ses sessions en cours sont
  closes.
- Ses entrées d'audit et les objets qu'il a créés restent intacts et le désignent par
  son nom, avec la mention « compte désactivé ».
- Il peut être réactivé.
- Aucune fonction ne supprime un utilisateur.

#### FR-20 : Anonymiser un utilisateur désactivé

Un administrateur peut anonymiser un utilisateur désactivé pour répondre à une demande
d'effacement : son nom et son email sont remplacés par un identifiant neutre, tout le
reste est conservé.

**Conséquences (testables) :**
- Les entrées d'audit et les objets créés par l'utilisateur subsistent et le désignent
  par l'identifiant neutre (« Utilisateur anonymisé #12 »).
- L'opération est irréversible et crée elle-même une entrée d'audit.
- Un utilisateur actif ne peut pas être anonymisé : il faut d'abord le désactiver.

#### FR-21 : Gérer son profil

Un utilisateur peut, depuis son profil, changer sa langue d'interface, son mot de passe
et réenrôler sa double authentification.

**Conséquences (testables) :**
- Le changement de mot de passe exige l'ancien mot de passe et clôt les autres sessions.
- Le réenrôlement 2FA exige le second facteur en cours ou un code de secours.

### 4.3 Rôles et permissions

**Description :** les rôles portent des permissions par défaut ; un administrateur
ajuste par utilisateur, et l'origine de chaque permission reste visible.

#### FR-7 : Rôles par défaut

Le socle fournit des rôles prédéfinis avec leurs permissions ; un administrateur peut,
depuis un écran de gestion des rôles, en créer d'autres et modifier leurs permissions.

**Conséquences (testables) :**
- Le socle livre trois rôles : Super admin (l'équipe de développement, toutes les
  permissions), Admin (le responsable PME : administration des comptes et des
  permissions, consultation de la roadmap et du journal d'audit), User (aucune
  permission d'administration, consultation de la roadmap).
- Le premier compte créé à l'initialisation (FR-1) est Super admin.
- Une modification des permissions d'un rôle s'applique immédiatement à tous les
  utilisateurs qui l'ont, sauf là où une surcharge existe.
- Les permissions sont des codes stables que les modules métier des dérivés étendent.

#### FR-8 : Surcharger les permissions d'un utilisateur

Un administrateur peut ajouter ou retirer une permission à un utilisateur donné, par
rapport à ce que son rôle prévoit. Réalise UJ-2.

**Conséquences (testables) :**
- Une permission retirée par surcharge reste absente même si le rôle l'accorde.
- Une permission ajoutée par surcharge reste présente même si le rôle ne l'accorde pas.
- Toute surcharge crée une entrée d'audit.

#### FR-9 : Voir l'origine de chaque permission

Sur la fiche d'un utilisateur, chaque permission affiche son origine : héritée du
rôle, ajoutée, retirée. Réalise UJ-2.

**Conséquences (testables) :**
- La liste montre toutes les permissions existantes, avec pour chacune l'état effectif
  et son origine.
- Un administrateur peut annuler une surcharge pour revenir à l'héritage.

#### FR-10 : Appliquer les permissions partout

Chaque action et chaque zone protégée du dérivé vérifie la permission effective de
l'utilisateur.

**Conséquences (testables) :**
- Une tentative d'accès à une zone non permise est refusée (403) avec un libellé
  traduit.
- Une tentative d'accès à un objet précis non permis reçoit la même réponse que pour
  un objet inexistant (404), pour ne pas révéler son existence.
- Les éléments de navigation vers des zones non permises ne sont pas affichés.

### 4.4 Journal d'audit

**Description :** qui a fait quoi et quand, à deux niveaux — les modifications d'objets
et les actions métier — consultable par les utilisateurs qui en ont la permission,
client compris.

#### FR-11 : Enregistrer les modifications d'objets

Toute création, modification ou suppression d'un objet audité produit une entrée
d'audit avec l'auteur, l'horodatage, l'objet, et pour chaque champ modifié l'ancienne
et la nouvelle valeur.

**Conséquences (testables) :**
- Tous les objets métier sont audités par défaut ; un développeur peut en exclure un
  explicitement `[ASSUMPTION]`.
- Les champs sensibles (mot de passe, secrets de 2FA) n'apparaissent jamais dans une
  entrée.
- Une modification faite par une commande ou un traitement automatique est attribuée
  à un acteur système nommé.

#### FR-12 : Enregistrer les actions métier

Le code d'un dérivé peut enregistrer une action métier nommée (« devis validé »), avec
son auteur, son horodatage et l'objet concerné.

**Conséquences (testables) :**
- L'action est déclarée en un appel depuis la couche service — jamais depuis un
  contrôleur — avec un code stable et un libellé traduisible.
- Liste de référence des actions du socle auditées : connexion, échec de connexion,
  mot de passe changé ou réinitialisé, invitation envoyée et acceptée, 2FA activée ou
  réinitialisée, surcharge de permission, permissions d'un rôle modifiées,
  désactivation et réactivation, anonymisation, jeton d'accès créé ou révoqué,
  langue activée ou désactivée. Les puces « crée une entrée d'audit » des autres FR
  renvoient à cette liste.

#### FR-13 : Consulter le journal d'audit

Un utilisateur ayant la permission peut consulter le journal d'audit, le filtrer et
l'exporter. Réalise UJ-4.

**Conséquences (testables) :**
- Filtres : période, auteur, type d'objet, objet précis, type d'entrée (modification
  ou action métier).
- Chaque entrée se lit sans connaissance technique : libellés traduits, valeurs
  lisibles, pas d'identifiants internes bruts.
- L'export porte sur la sélection filtrée `[ASSUMPTION: format CSV]`.
- Les entrées sont immuables : aucune interface ne permet de les modifier ni de les
  supprimer.

### 4.5 Roadmap

**Description :** la vue du projet, lue depuis les fichiers BMAD du dépôt, montrée au
client et à l'équipe dans un vocabulaire sans jargon.

#### FR-14 : Lire la roadmap depuis les fichiers BMAD

Le dérivé lit les epics, les tâches et leur statut dans les fichiers BMAD embarqués
dans le dépôt, sans base de données intermédiaire à alimenter.

**Conséquences (testables) :**
- Les fichiers BMAD font partie de ce qui est déployé : la roadmap en production
  reflète le dernier déploiement.
- Un fichier absent ou mal formé produit une roadmap partielle avec un avertissement
  visible des administrateurs, jamais une page en erreur.
- La roadmap affiche la date du dernier déploiement, pour situer sa fraîcheur.
- Priorité, difficulté, personne assignée et dépendances sont lues depuis les
  fichiers quand elles y figurent ; sinon elles sont affichées comme non renseignées
  `[ASSUMPTION: ces champs sont portés par l'en-tête des stories BMAD — voir OQ-3]`.

#### FR-15 : Afficher la roadmap

Un utilisateur ayant la permission voit les epics avec leur avancement, et sous
chacune ses tâches avec statut lisible, priorité, difficulté, personne assignée et
dépendances. Réalise UJ-3.

**Conséquences (testables) :**
- La roadmap est la page d'accueil de tout utilisateur qui a la permission de la voir.
- L'avancement d'une epic est le rapport des tâches terminées sur le total.
- Les statuts BMAD sont traduits en trois statuts lisibles : `backlog` et
  `ready-for-dev` → à faire ; `in-progress` et `review` → en cours ; `done` → terminé.
  Un statut inconnu est montré comme « à faire » et signalé aux administrateurs.
- Une tâche dépendante indique de quelles tâches elle dépend et si elles sont
  terminées.
- Les notes internes ne sont montrées qu'aux utilisateurs ayant la permission dédiée
  `[ASSUMPTION: accordée par défaut au seul rôle Super admin]`.

#### FR-16 : Lancer une tâche en environnement de développement

En environnement de développement, un développeur peut lancer une tâche prête :
l'application démarre les agents sur son poste ; les agents — et eux seuls — mettent à
jour les fichiers BMAD (statut, personne assignée). Réalise UJ-5.

**Conséquences (testables) :**
- L'action n'existe pas en production : ni bouton, ni point d'entrée.
- Seule une tâche prête (à faire, dépendances terminées) peut être lancée ; sinon le
  bouton est inactif et la raison est affichée.
- Une tâche déjà en cours ne peut pas être relancée.
- L'application affiche que le lancement a eu lieu, ou qu'il a échoué avec la raison
  (commande absente, processus non démarré).
- L'application n'écrit jamais dans les fichiers BMAD ni ailleurs dans le dépôt.

### 4.6 API

**Description :** l'amorce d'une interface JSON pour une future application cliente —
assez pour qu'elle s'identifie et vérifie que le service répond. Pas plus. Les jetons
qu'elle présente sont créés depuis l'application, pas par l'API.

#### FR-17 : Obtenir un jeton d'accès pour l'API

Un utilisateur actif crée ses jetons d'accès depuis son profil, dans l'application, et
peut les révoquer. L'API n'a aucun point d'entrée d'authentification.

**Conséquences (testables) :**
- Le jeton n'est montré qu'une seule fois, à sa création ; l'application n'en conserve
  qu'une empreinte et ne peut pas le réafficher.
- Le jeton porte une date d'expiration `[ASSUMPTION: durée configurable, par défaut une
  heure]` et peut être révoqué par l'utilisateur lui-même ou par un administrateur
  depuis la fiche de l'utilisateur.
- Une requête portant un jeton expiré, révoqué, ou appartenant à un utilisateur
  désactivé ou anonymisé est refusée, même si le jeton a été créé avant.
- La création et la révocation d'un jeton créent chacune une entrée d'audit.
- Aucune URL de l'API n'accepte un mot de passe.

*Révisé le 2026-09-10 (AD-5). La version précédente faisait obtenir le jeton par l'API
avec les identifiants et le second facteur. Le second facteur est porté par un flux de
session qui ne se compose pas avec une API sans état ; créer le jeton derrière la
connexion du navigateur, où la double authentification a déjà eu lieu, atteint le même
but sans faire circuler le mot de passe vers l'API.*

#### FR-18 : Health check

Une application cliente peut vérifier sans authentification que le dérivé est en
service.

**Conséquences (testables) :**
- La réponse indique l'état du service et de sa base de données, sans détail interne.
- Un état dégradé se traduit par un code HTTP distinct de celui de l'état nominal.

### 4.7 Langues

**Description :** l'interface existe en français, anglais et néerlandais — décision
prise pendant le PRD et absente du brief : la clientèle actuelle est belge et
trilingue. Un dérivé n'a pas toujours besoin des trois : un administrateur peut en
désactiver. Les données saisies par les utilisateurs ne sont pas traduites.

#### FR-19 : Interface en trois langues

Chaque utilisateur choisit sa langue d'interface parmi les langues actives du dérivé.

**Conséquences (testables) :**
- Tout texte d'interface, message d'erreur, email et libellé d'audit du socle existe
  en français, anglais et néerlandais ; le français est la langue par défaut tant
  qu'il est actif.
- La langue d'un utilisateur est mémorisée et appliquée à ses emails.
- Un module métier ajouté à un dérivé suit le même mécanisme de traduction.

#### FR-22 : Activer ou désactiver une langue

Un administrateur peut désactiver ou réactiver une langue d'interface pour le dérivé.

**Conséquences (testables) :**
- Au moins une langue reste active ; désactiver la dernière est refusé.
- Une langue désactivée n'est plus proposée au choix ; un utilisateur qui l'avait
  choisie bascule sur la langue par défaut du dérivé `[ASSUMPTION: la première langue
  active dans l'ordre français, anglais, néerlandais]`.
- Le changement crée une entrée d'audit.

## 5. Exigences transverses (NFR)

- **Sécurité** : mots de passe hachés selon l'état de l'art ; sessions et jetons
  d'accès révocables ; protection CSRF sur toute écriture ; aucune donnée sensible dans
  les journaux applicatifs ; le journal d'audit est en ajout seul.
- **Accessibilité** : l'interface du socle respecte les règles WCAG 2.1 de la suite
  proglab (couleur jamais seule, contraste, labels, focus visible, une seule h1).
- **Performance** : les écrans du socle (connexion, fiche utilisateur, journal
  d'audit, roadmap) répondent en moins d'une seconde au p95, en environnement de
  développement comme en production chez le client, pour un dérivé de taille courante
  `[ASSUMPTION: jusqu'à 50 utilisateurs et 1 million d'entrées d'audit]` ; le journal
  d'audit est paginé.
- **Observabilité** : le dérivé expose un health check (FR-18) et des journaux
  applicatifs par canal, sans données personnelles.
- **Qualité** : le socle passe l'analyse statique et les tests de la suite proglab ;
  chaque FR est couverte par au moins un test fonctionnel.
- **Dérivabilité** : rien dans le socle ne suppose un client particulier ; toute
  configuration propre à un dérivé vit dans des variables d'environnement ou des
  fichiers prévus pour être modifiés.

## 6. Contraintes et garde-fous

- **Données personnelles** : le journal d'audit contient des noms et des actions ; la
  désactivation ne supprime rien (FR-6). Le droit à l'effacement est satisfait par
  l'anonymisation (FR-20) : l'historique reste, la personne n'y est plus identifiable.
- **Coût** : le socle ne requiert aucun service externe payant `[ASSUMPTION: l'envoi
  d'email passe par le serveur SMTP du client ou de l'équipe]`.
- **Dérivation et maintenance** : le socle se clone et diverge ; son code n'est pas
  resynchronisé dans les dérivés existants, et un correctif du socle se reporte à la
  main. Un dérivé livré est en revanche **maintenu** : les montées de version du
  framework et des dépendances sont décidées une fois sur le socle, puis appliquées à
  chaque dérivé. C'est ce qui justifie de bâtir le socle sur une version à support long
  plutôt que sur la dernière version publiée. Risque assumé : à des centaines de
  dérivés, c'est une dette de maintenance qui grandit avec le succès. La règle sera
  réexaminée — noyau partagé, outillage de report — dès que les dérivés se compteront en
  dizaines. *Révisé le 2026-09-10 (AD-1) : la version précédente laissait entendre qu'un
  dérivé restait figé sur la version de son clonage.*

## 7. Non-objectifs (explicites)

Ce que le socle n'est pas, et qui n'en est pas utilisateur, en v1 :

- Une interface commune à plusieurs dérivés, ou un compte valable sur plusieurs : un
  tiers qui consulterait plusieurs ERP à la fois n'est pas un utilisateur.
- La resynchronisation du code du socle dans les dérivés déjà clonés. Leur maintenance
  en version, elle, est assurée (§6).
- Le multi-tenant : un ERP, un client, un déploiement ; pas de « locataire » d'une
  instance partagée.
- La facturation, la comptabilité, ou tout module métier : le socle s'arrête là où le
  métier commence.
- L'application mobile : seule l'API du dérivé qu'elle consommera est amorcée ; un
  utilisateur mobile n'est pas un utilisateur de la v1.
- L'exécution d'agents côté serveur.
- Toute écriture dans les fichiers BMAD par l'application : la roadmap se change dans
  les fichiers, par les outils BMAD.
- Les commentaires ou demandes du client depuis la roadmap, conformément au brief.
- La connexion par fournisseur externe (Google, Microsoft) `[ASSUMPTION: non demandée
  en v1]`.

## 8. Périmètre MVP

### 8.1 Dans le périmètre

- FR-1 à FR-22 telles que décrites, et les écrans qui les portent : connexion,
  invitation, profil, liste et fiche des utilisateurs, gestion des rôles, langues,
  journal d'audit, roadmap.

### 8.2 Hors périmètre MVP

- Gestion documentaire client (pièces jointes, dossiers) — premier candidat pour un
  module commun, dès que deux dérivés l'auront réclamée.
- Notifications (email ou autre) au client lors d'un changement de statut dans la
  roadmap — reportées, le déploiement rythme déjà la fraîcheur.
- Rétention et purge du journal d'audit — reportées tant que le volume n'impose rien
  (voir OQ-2).

### 8.3 Ordre de livraison

D'abord ce qui sert SM-1 et SM-2 — FR-1, FR-2, FR-3, FR-4, FR-7, FR-14, FR-15 ; puis
le journal d'audit (SM-4) — FR-11 à FR-13 ; puis le reste (2FA, surcharges,
anonymisation, profil, API, langues, lancement de tâche).

## 9. Métriques de succès

**Primaires**
- **SM-1** : Temps du clonage à la première fonctionnalité spécifique livrée — moins
  d'une journée de travail entre le premier commit du dérivé et le premier commit
  d'une entité métier du client, mesuré sur chaque nouveau dérivé, contre une semaine
  aujourd'hui (estimation de Fabrice, non mesurée). Valide FR-1, FR-2.
- **SM-2** : Délai avant que le client voie sa roadmap — au plus tard deux jours après
  le démarrage du projet. Valide FR-4, FR-14, FR-15.

**Secondaires**
- **SM-3** : Un développeur nouveau sur un dérivé livre une story conforme au standard
  proglab sans relecture bloquante dans sa première semaine. Valide FR-2.
- **SM-4** : Zéro question « qui a changé ça ? » restée sans réponse après consultation
  du journal d'audit, sur les trois premiers dérivés — questions consignées par
  Fabrice lors des points projet. Valide FR-11 à FR-13.

**Contre-métriques (à ne pas optimiser)**
- **SM-C1** : Taille du socle — ne pas y ajouter un module tant que deux dérivés ne
  l'ont pas réclamé. Contrebalance SM-1 : un socle plus gros démarre moins vite.
- **SM-C2** : Volume du journal d'audit — ne pas chercher à tout tracer au point de
  rendre le journal d'audit illisible pour Sophie (UJ-4). Contrebalance SM-4.

## 10. Questions ouvertes

**Décisions prises pendant le PRD** : OQ-1 (droit à l'effacement) → anonymisation,
FR-20 ; OQ-4 (correspondance des statuts) → fixée dans FR-15.
**Décisions prises pendant l'architecture, le 2026-09-10** : OQ-3 (champs BMAD) → close,
le socle définit la convention d'en-tête qu'il attend (AD-10).

- **OQ-2** — Rétention du journal d'audit : illimitée en v1, mais à partir de quel
  volume ou de quelle durée archiver ?
- ~~**OQ-3**~~ — *Close le 2026-09-10 (AD-10).* La question était de savoir si les
  stories BMAD portent priorité, difficulté, assignation et dépendances, ou s'il faut
  une convention propre au projet. Réponse : le socle définit et documente la convention
  d'en-tête YAML qu'il attend, traite les champs absents comme « non renseigné », et
  considère prête toute tâche « à faire » sans dépendance déclarée. La roadmap n'a donc
  plus de dépendance bloquante vers `bmad-create-epics-and-stories`.
- **OQ-5** — Les permissions par défaut des rôles Admin et User (§4.3, FR-7)
  suffisent-elles à tous les dérivés, ou chaque dérivé les ajustera-t-il ?

## 11. Index des hypothèses

- §3 Glossaire — « notes internes » = contenu d'une story au-delà du titre et du résumé.
- §4.2 FR-3 — ralentissement des échecs de connexion : délai doublé à partir du
  cinquième, plafonné à quinze minutes.
- §4.2 FR-3 — lien de réinitialisation valable une heure.
- §4.2 FR-4 — expiration des invitations après sept jours.
- §4.2 FR-5 — méthodes 2FA : application TOTP et code par email.
- §4.4 FR-11 — tous les objets métier audités par défaut, exclusion explicite possible.
- §4.4 FR-13 — export du journal d'audit en CSV.
- §4.5 FR-14 — priorité, difficulté, assignation et dépendances portées par l'en-tête
  des stories.
- §4.5 FR-15 — permission « notes internes » accordée par défaut au seul Super admin.
- §4.6 FR-17 — durée d'expiration d'un jeton d'accès configurable, une heure par défaut.
- §4.7 FR-22 — langue de repli après désactivation : la première active dans l'ordre
  français, anglais, néerlandais.
- §5 — dimensionnement : 50 utilisateurs, 1 million d'entrées d'audit.
- §6 — envoi d'email par le SMTP du client ou de l'équipe, aucun service payant.
- §7 — pas de connexion par fournisseur externe en v1.
