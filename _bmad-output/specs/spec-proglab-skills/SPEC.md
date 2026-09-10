---
id: SPEC-proglab-skills
companions:
  - criteres-acceptation.md
  - glossaire.md
  - ../../planning-artifacts/architecture/architecture-proglab-skills-2026-09-09/ARCHITECTURE-SPINE.md
  - ../../planning-artifacts/ux-designs/ux-proglab-skills-2026-09-09/DESIGN.md
  - ../../planning-artifacts/ux-designs/ux-proglab-skills-2026-09-09/EXPERIENCE.md
sources:
  - ../../planning-artifacts/briefs/brief-proglab-skills-2026-09-09/brief.md
  - ../../planning-artifacts/briefs/brief-proglab-skills-2026-09-09/addendum.md
  - ../../planning-artifacts/prds/prd-proglab-skills-2026-09-09/prd.md
  - ../../planning-artifacts/prds/prd-proglab-skills-2026-09-09/addendum.md
---

> **Contrat canonique.** Ce SPEC et les fichiers de `companions:` forment le contrat complet, validé en préservation, de ce qu'il faut construire, tester et valider. Les documents de `sources:` servent la traçabilité — ne les consulter que pour la justification narrative que ce contrat omet volontairement.

# Socle ERP custom (proglab)

## Why

**Une opportunité à saisir, portée par une base de clients qui demande déjà l'outil.** Chaque ERP custom livré à une PME commence aujourd'hui par la même semaine de travail — connexion, utilisateurs, droits, journal d'audit — refaite à la main, et refaite *différemment* à chaque projet : des socles cousins mais jamais identiques, chacun avec ses écarts de conventions, de sécurité et de qualité. Pendant cette semaine, le client ne voit rien qui lui ressemble. Ce projet fait cette semaine une bonne fois : un dépôt Symfony de référence, construit selon la suite `symfony-proglab-*` et piloté par BMAD, que l'on clone au démarrage de chaque ERP et que l'on fait diverger librement. Les personnes concernées sont la PME cliente (déjà équipée d'un ERP généraliste trop lourd et trop éloigné de ses process), le développeur qui dérive (Fabrice seul aujourd'hui, une équipe de freelances demain), et Fabrice comme pilote. L'avantage n'est pas technique : c'est la demande qui précède l'offre, l'adéquation aux process, et une méthode qui rend la qualité répétable quand l'équipe passe d'une personne à cinq.

## Capabilities

Les identifiants `CAP-N` reprennent la numérotation `FR-N` du PRD, que l'ARCHITECTURE-SPINE et `EXPERIENCE.md` référencent déjà. `criteres-acceptation.md` porte, pour chaque capacité, l'ensemble de ses conséquences testables.

- **CAP-1**
  - **intent :** Un développeur peut, depuis un clone du socle, rendre le dérivé fonctionnel en une seule commande.
  - **success :** Sur une base vide, la commande prépare la base, applique les migrations, charge les rôles par défaut et crée le premier Super admin, qui se connecte et voit la roadmap.

- **CAP-2**
  - **intent :** Un développeur ou un agent trouve dans le dépôt comment le socle est organisé, comment le dériver et où ajouter un module métier.
  - **success :** Un agent d'implémentation chargé d'ajouter une entité métier trouve dans cette seule documentation l'emplacement et les conventions à respecter.

- **CAP-3**
  - **intent :** Un utilisateur actif peut se connecter avec son email et son mot de passe, et récupérer un accès perdu.
  - **success :** Un compte désactivé est refusé sans révéler si le mot de passe était correct ; les échecs répétés sont ralentis ; un lien de réinitialisation à usage unique rétablit l'accès.

- **CAP-4**
  - **intent :** Un administrateur peut faire entrer une personne par email en lui attribuant un rôle, sans lui créer son mot de passe.
  - **success :** Le lien d'invitation est à usage unique et expirable ; le renvoi invalide le précédent ; l'acceptation crée un utilisateur actif au rôle prévu et une entrée d'audit.

- **CAP-5**
  - **intent :** Le dérivé exige un second facteur des rôles qui l'imposent, et le propose aux autres.
  - **success :** Un utilisateur sous un rôle qui l'exige est conduit à l'enrôlement avant tout autre écran une fois son délai de grâce de sept jours écoulé, aucun rôle n'en étant exempt, Super admin compris ; deux méthodes et des codes de secours sont proposées ; un administrateur peut réinitialiser un second facteur perdu.

- **CAP-6**
  - **intent :** Un administrateur peut retirer l'accès d'une personne sans rien supprimer de ce qu'elle a fait.
  - **success :** Le compte désactivé ne peut plus se connecter, ses sessions en cours sont closes, ses entrées d'audit et ses objets restent intacts sous son nom marqué « compte désactivé », et il est réactivable ; aucune fonction ne supprime un utilisateur.

- **CAP-7**
  - **intent :** Un administrateur dispose de rôles portant des permissions par défaut, et peut en créer d'autres.
  - **success :** Le socle livre Super admin, Admin et User ; les permissions se présentent en grille ressources × opérations, chaque ressource portant aussi ses actions à code nommé ; modifier les permissions d'un rôle s'applique immédiatement à ses utilisateurs, sauf là où une surcharge existe.

- **CAP-8**
  - **intent :** Un administrateur peut donner à un utilisateur exactement les droits qu'il faut, au-delà ou en deçà de son rôle.
  - **success :** Une permission retirée par surcharge reste absente même si le rôle l'accorde, une permission ajoutée reste présente même s'il ne l'accorde pas, et toute surcharge crée une entrée d'audit.

- **CAP-9**
  - **intent :** Un administrateur voit d'où vient chaque permission d'un utilisateur avant de la modifier.
  - **success :** La fiche présente la même grille que l'écran de rôle, chaque case portant son état effectif et son origine — héritée, ajoutée, retirée — et permet d'annuler une surcharge pour revenir à l'héritage.

- **CAP-10**
  - **intent :** Chaque action et chaque zone du dérivé n'est accessible qu'à qui en a la permission effective.
  - **success :** Une zone non permise répond 403 avec un libellé traduit ; la vérification porte sur l'opération demandée, donc un objet qu'on n'a pas le droit de consulter répond 404 comme s'il n'existait pas, tandis qu'une opération refusée sur un objet consultable répond 403 ; la navigation ne montre pas les zones interdites.

- **CAP-11**
  - **intent :** Toute création, modification ou suppression d'un objet audité laisse une trace de qui, quand et quoi.
  - **success :** L'entrée porte l'auteur, l'horodatage, l'objet et pour chaque champ modifié l'ancienne et la nouvelle valeur ; les champs sensibles n'y figurent jamais ; un traitement automatique est attribué à un acteur système nommé.

- **CAP-12**
  - **intent :** Le code d'un dérivé peut consigner une action métier nommée, au-delà du simple changement de champ.
  - **success :** L'action se déclare en un appel depuis la couche service, avec un code stable et un libellé traduisible, et apparaît dans le journal aux côtés des modifications.

- **CAP-13**
  - **intent :** Un utilisateur ayant la permission peut répondre à « qui a fait ça ? » sans connaissance technique.
  - **success :** Le journal se filtre par période, auteur, type d'objet, objet précis et type d'entrée, se lit en libellés traduits sans identifiants bruts, et aucune interface ne permet de le modifier. Le lecteur n'a jamais à choisir entre en ligne et archive : mêmes filtres, même unique export, portant sur la sélection filtrée et couvrant les deux. L'objet d'une entrée renvoie vers sa fiche quand son module a déclaré une route et que le lecteur peut le consulter, et reste du texte sinon.

- **CAP-14**
  - **intent :** Le dérivé lit sa roadmap dans les fichiers BMAD embarqués dans le dépôt, sans base intermédiaire.
  - **success :** Un fichier absent ou mal formé produit une roadmap partielle avec un avertissement visible des administrateurs, jamais une page en erreur ; la date du dernier déploiement est affichée.

- **CAP-15**
  - **intent :** Le client et l'équipe voient où en est la construction de l'ERP, sans jargon BMAD.
  - **success :** La roadmap est la page d'accueil de qui a la permission, montre les epics avec leur avancement et sous chacune ses tâches avec statut lisible, priorité, difficulté, assignation et dépendances, une tâche dépendante indiquant de quoi elle dépend.

- **CAP-16**
  - **intent :** Sur son poste, un développeur peut lancer depuis la roadmap les agents qui réalisent une tâche prête.
  - **success :** L'action n'existe pas en production, ni bouton ni point d'entrée ; seule une tâche prête est lançable ; l'application rend compte du démarrage ou de son échec et n'écrit jamais dans le dépôt.

- **CAP-17**
  - **intent :** Un utilisateur peut donner à une application cliente un accès à l'API, et le lui retirer.
  - **success :** Le jeton est créé depuis le profil, montré une seule fois, stocké haché, expirable, révocable par l'utilisateur et par un administrateur ; un jeton expiré, révoqué ou appartenant à un compte désactivé ou anonymisé est refusé ; aucune URL de l'API n'accepte un mot de passe.

- **CAP-18**
  - **intent :** Une application cliente peut vérifier sans authentification que le dérivé est en service.
  - **success :** La réponse indique l'état du service et de sa base sans détail interne, et un état dégradé porte un code HTTP distinct de l'état nominal ; l'état est dégradé quand la file d'envoi cesse d'être traitée et quand la plus vieille entrée d'audit en ligne dépasse la fenêtre de rétention.

- **CAP-19**
  - **intent :** Chaque utilisateur travaille dans sa langue parmi celles actives dans le dérivé.
  - **success :** Tout texte d'interface, message d'erreur, email et libellé d'audit du socle existe en français, anglais et néerlandais ; la langue du compte est mémorisée et appliquée à ses emails.

- **CAP-20**
  - **intent :** Un administrateur peut répondre à une demande d'effacement sans perdre l'historique.
  - **success :** L'anonymisation d'un utilisateur désactivé remplace nom et email par un identifiant neutre partout où ils apparaissent, dans les entrées d'audit en ligne **comme archivées**, et partout où la personne apparaît — y compris dans une entrée qui décrit un autre objet ; l'opération est irréversible, auditée, rend compte du nombre d'entrées réécrites, et est refusée sur un compte actif ; un email déjà en file part en revanche à l'ancienne adresse, limite nommée et acceptée.

- **CAP-21**
  - **intent :** Un utilisateur gère lui-même sa langue, son mot de passe et son second facteur.
  - **success :** Le changement de mot de passe exige l'ancien et clôt les autres sessions ; le réenrôlement 2FA exige le second facteur en cours ou un code de secours.

- **CAP-22**
  - **intent :** Un administrateur ajuste les langues du dérivé à ce dont le client a besoin.
  - **success :** Désactiver la dernière langue active est refusé ; une langue désactivée n'est plus proposée et ses utilisateurs basculent sur la langue par défaut ; le changement crée une entrée d'audit.

- **CAP-23**
  - **intent :** Le journal d'audit ne garde en ligne qu'une fenêtre récente, sans perdre ce qui en sort.
  - **success :** Les entrées de plus d'un mois sont déplacées vers un magasin d'archive, où elles restent consultables et exportables par qui a la permission ; aucune entrée n'est supprimée ; une entrée est soit en ligne soit archivée, jamais les deux ni aucune des deux, même si l'archivage est interrompu ; un archivage arrêté est visible par le health check.

## Constraints

- **Symfony 7.4 LTS sur PHP 8.5.** Aucune dépendance exigeant Symfony 8 n'entre dans le socle — ce qui exclut notamment `damienharper/auditor-bundle` 7.x.
- **Le standard proglab s'applique intégralement.** Les couches Contrôleur / Service / Repository / DTO / Entité de `symfony-proglab-*` fixent *comment* on écrit ; BMAD fixe *quoi* et dans quel ordre.
- **Deux racines, frontière mécanisée.** `src/Core/` porte le socle, `src/Module/<Nom>/` le métier du client ; `Core/Contract/` est la seule porte. Un dérivé n'édite aucun fichier de `src/Core/` ni de `config/`.
- **Les fichiers BMAD sont un contrat d'entrée en lecture seule.** L'application ne les écrit jamais ; seul `var/` est inscriptible.
- **Un ERP par client, déployé séparément.** Le socle se clone et diverge ; son code n'est pas resynchronisé dans les dérivés. Un dérivé livré est en revanche maintenu : les montées de version sont décidées sur le socle puis reportées.
- **API écrite à la main, sans API Platform,** tant que le contrat reste réduit. Jeton opaque révocable en base ; jamais de JWT sans état.
- **Journal d'audit en ajout seul, magasin unique.** Seule exception nommée : l'anonymisation réécrit les entrées portant le nom ou l'email du compte concerné. L'archivage de CAP-23 déplace des entrées, il n'en modifie ni n'en supprime aucune.
- **Les permissions ont une forme CRUD.** Le code par défaut est `<ressource>.create|read|update|delete` ; seules les actions que le CRUD ne couvre pas gardent un code nommé (`user.invite`, `user.anonymize`, `audit.export`, `roadmap.launch`, `roadmap.notes`), et elles s'accordent dans la même grille, sous leur ressource — sans quoi aucun rôle ne pourrait les recevoir. Chaque ressource est déclarée par le socle ou par un module avec les opérations qu'elle supporte, et la grille s'en dérive — aucune liste de permissions tenue à la main.
- **Tout chemin fonctionne en pleine page sans JavaScript.** Turbo et Stimulus n'ajoutent que le confort.
- **Plancher WCAG 2.1 de `symfony-proglab-accessibility`,** vérifié par test automatisé en CI, jamais par relecture.
- **Aucun service externe payant.** L'envoi d'email passe par le SMTP du client ou de l'équipe.
- **Budget de performance :** les écrans du socle répondent sous la seconde au p95 pour un dérivé de taille courante ; le journal d'audit est paginé.
- **Le socle reste interne :** ni vendu, ni ouvert. Ce que la PME achète, c'est son ERP.
- **Aucun module commun n'entre dans le socle** tant que deux dérivés ne l'ont pas réclamé.
- **Les décisions AD-1 à AD-22 de l'ARCHITECTURE-SPINE contraignent au même titre** que les points ci-dessus ; le spine en porte le détail et les écarts énoncés au standard.

## Non-goals

- Une interface commune à plusieurs dérivés, ou un compte valable sur plusieurs.
- Le multi-tenant : un ERP, un client, un déploiement — pas de « locataire » d'une instance partagée.
- La facturation, la comptabilité, ou tout module métier : le socle s'arrête là où le métier commence.
- L'application mobile elle-même ; seule l'API qui la prépare est dans le socle.
- L'exécution d'agents côté serveur.
- Toute écriture dans les fichiers BMAD par l'application : la roadmap se change dans les fichiers, par les outils BMAD.
- Les commentaires ou demandes du client depuis la roadmap.
- La connexion par fournisseur externe (Google, Microsoft).
- La resynchronisation du code du socle dans les dérivés déjà clonés — leur maintenance en version, elle, est assurée.
- La suppression d'entrées d'audit : la rétention d'un mois archive (CAP-23), elle ne purge pas.
- Hors MVP, reportés : gestion documentaire client, notifications au client sur changement de statut.

## Success signal

Du premier commit d'un dérivé cloné au premier commit d'une entité métier du client, moins d'une journée de travail — contre une semaine aujourd'hui ; et le client se connecte pour voir la roadmap de son ERP au plus tard deux jours après le démarrage du projet. Les métriques secondaires et les contre-métriques sont dans `criteres-acceptation.md`.

## Assumptions

- La semaine de démarrage actuelle est une estimation de Fabrice, non mesurée.
- Ralentissement des échecs de connexion : délai doublé à partir du cinquième échec, plafonné à quinze minutes, sans blocage définitif.
- Lien de réinitialisation de mot de passe valable une heure ; invitation valable sept jours.
- Méthodes 2FA : application TOTP et code par email, plus des codes de secours ; code par email valable dix minutes depuis son émission.
- Tous les objets métier sont audités par défaut, avec exclusion explicite possible par le propriétaire du type.
- « Archiver » (CAP-23) s'entend comme déplacer vers un magasin consultable et exportable, jamais supprimer — SM-4 demande de répondre à « qui a changé ça » au-delà d'un mois.
- L'archivage entre dans le périmètre plutôt que d'y rester hors MVP : une politique de rétention sans mécanisme n'est appliquée par personne.
- Export du journal d'audit au format CSV, reprenant exactement les filtres courants.
- Permission « notes internes » accordée par défaut au seul Super admin ; notes internes = contenu d'une tâche au-delà du titre et du résumé.
- Durée d'expiration d'un jeton d'API configurable, une heure par défaut.
- Langue de repli après désactivation : la première langue active dans l'ordre français, anglais, néerlandais.
- Dérivé de taille courante : jusqu'à 50 utilisateurs ; 50 000 entrées d'audit par mois dans la fenêtre en ligne ; 1 million d'entrées dans l'archive ; jusqu'à 40 ressources dans la grille des permissions.
- Session glissante de 24 heures.
- « Mobile first » s'entend comme web responsive conçu d'abord pour l'écran de téléphone, le PRD excluant une application native.
- La convention d'en-tête YAML des stories BMAD est définie par le socle ; sa forme exacte reste à confirmer sur les premiers fichiers produits par ce projet.
