# Critères d'acceptation — Socle ERP custom (proglab)

Companion de `SPEC.md`. Pour chaque capacité, l'ensemble des conséquences testables ; `SPEC.md` n'en retient qu'une par capacité. Ordre de livraison et métriques en fin de document. Le vocabulaire est celui de `glossaire.md`.

## CAP-1 — Initialiser un dérivé en une commande

- Sur une base vide, la commande termine sans autre intervention que la saisie des identifiants du premier Super admin.
- Sur une base déjà peuplée, la commande refuse d'agir sans une option de confirmation explicite.
- À la fin, le Super admin peut se connecter et voit la roadmap (vide).

## CAP-2 — Guide de dérivation

- Un agent d'implémentation chargé d'ajouter une entité métier trouve dans cette documentation, sans autre source, l'emplacement et les conventions à respecter.
- La documentation nomme les skills proglab et les workflows BMAD à invoquer.
- Elle documente la convention d'en-tête YAML attendue dans les stories BMAD (voir CAP-14).
- Elle nomme la responsabilité de sauvegarde du serveur client, sa procédure de restauration, et l'exigence qu'elle ait été exécutée une fois avant la mise en service.

## CAP-3 — Se connecter avec email et mot de passe

- Un utilisateur désactivé ne peut pas se connecter et voit un message qui le dit sans révéler si le mot de passe était correct.
- Les échecs répétés sont ralentis ; l'affichage rend les secondes restantes ; aucun blocage définitif.
- Un utilisateur peut demander la réinitialisation de son mot de passe par un lien email à usage unique.

## CAP-4 — Inviter un utilisateur

- Le lien est à usage unique et expire.
- Un administrateur peut renvoyer une invitation ; le lien précédent devient invalide.
- Une invitation acceptée crée un utilisateur actif avec le rôle prévu et une entrée d'audit « invitation acceptée ».
- Seuls les administrateurs voient et utilisent la fonction d'invitation.
- L'administrateur voit l'état d'une invitation : en attente, expirée, acceptée.

## CAP-5 — Double authentification

- Le délai de grâce est de sept jours à compter de la première connexion sous un rôle qui exige la 2FA ; sa durée est un paramètre `app.`, pas une constante.
- Aucun rôle n'en est exempt, Super admin compris : un compte qui porte toutes les permissions n'échappe pas à la règle.
- Un utilisateur sous un rôle qui exige la 2FA est conduit à l'activation avant tout autre écran une fois son délai de grâce écoulé ; le profil et la déconnexion restent accessibles pendant.
- Un utilisateur qui reçoit un tel rôle après coup y est conduit à sa connexion suivante, avec un délai de grâce remis à zéro.
- Un utilisateur dont la 2FA est active fournit son second facteur à chaque connexion, quel que soit son rôle.
- Deux méthodes au moins sont proposées, plus des codes de secours.
- Un administrateur peut réinitialiser la 2FA d'un utilisateur qui a perdu son second facteur ; l'opération crée une entrée d'audit.

## CAP-6 — Désactiver un utilisateur

- Un utilisateur désactivé ne peut plus se connecter ; ses sessions en cours sont closes.
- Ses entrées d'audit et les objets qu'il a créés restent intacts et le désignent par son nom, avec la mention « compte désactivé ».
- Il peut être réactivé.
- Aucune fonction ne supprime un utilisateur.

## CAP-7 — Rôles par défaut

- Le socle livre trois rôles : Super admin (toutes les permissions, dont celles réservées au développement), Admin (administration des comptes et des permissions, consultation de la roadmap et du journal d'audit), User (aucune permission d'administration, consultation de la roadmap).
- Le premier compte créé à l'initialisation (CAP-1) est Super admin.
- Une modification des permissions d'un rôle s'applique immédiatement à tous les utilisateurs qui l'ont, sauf là où une surcharge existe.
- Les permissions sont des codes stables que les modules métier des dérivés étendent.
- Le code par défaut d'une permission a la forme `<ressource>.create`, `.read`, `.update`, `.delete`. Une action que le CRUD ne couvre pas garde un code nommé : `user.invite`, `user.anonymize`, `audit.export`, `roadmap.launch`, `roadmap.notes`.
- Le socle et chaque module déclarent leurs ressources et les opérations que chacune supporte ; la grille des écrans de rôle et de surcharge s'en dérive, sans liste tenue à la main. Une ressource qui ne supporte pas une opération n'en propose pas la case.
- Chaque ressource porte, à côté de ses opérations, les actions à code nommé qu'elle déclare : inviter et anonymiser sur les utilisateurs, exporter sur le journal, lancer une tâche et voir les notes internes sur la roadmap. Elles s'accordent au même endroit — sans quoi aucun rôle ne pourrait les recevoir.
- Un rôle porte un attribut « exige la double authentification » ; aucun code ne teste un nom de rôle.

## CAP-8 — Surcharger les permissions d'un utilisateur

- Une permission retirée par surcharge reste absente même si le rôle l'accorde.
- Une permission ajoutée par surcharge reste présente même si le rôle ne l'accorde pas.
- Toute surcharge crée une entrée d'audit.

## CAP-9 — Voir l'origine de chaque permission

- La fiche présente la même grille que l'écran de rôle — ressources × opérations, plus les actions à code nommé de chaque ressource — avec pour chaque case l'état effectif et son origine : héritée du rôle, ajoutée, retirée.
- Un administrateur peut annuler une surcharge pour revenir à l'héritage.
- Un code de permission présent en base mais plus déclaré par le code s'affiche comme obsolète et ne s'accorde jamais.

## CAP-10 — Appliquer les permissions partout

- Une tentative d'accès à une zone non permise est refusée (403) avec un libellé traduit.
- La vérification porte sur l'opération demandée, jamais sur la ressource en bloc : un utilisateur qui peut consulter sans pouvoir modifier voit l'objet et se voit refuser la modification.
- Sans la permission de consulter un objet, la réponse est celle d'un objet inexistant (404). Sur un objet consultable, le refus d'une autre opération est un 403 : l'objet lui est déjà connu.
- Les éléments de navigation vers des zones non permises ne sont pas affichés.

## CAP-11 — Enregistrer les modifications d'objets

- Tous les objets métier sont audités par défaut ; un développeur peut en exclure un explicitement.
- Les champs sensibles (mot de passe, secrets de 2FA) n'apparaissent jamais dans une entrée : ils sont exclus à l'écriture, pas masqués à l'affichage.
- Une modification faite par une commande ou un traitement automatique est attribuée à un acteur système nommé.
- L'entrée d'audit est écrite dans la même transaction que la donnée : un rollback l'emporte avec elle.

## CAP-12 — Enregistrer les actions métier

- L'action est déclarée en un appel depuis la couche service — jamais depuis un contrôleur — avec un code stable et un libellé traduisible.
- Exception nommée : les actions d'authentification (connexion, échec de connexion) sont déclarées par la couche sécurité ; un échec de connexion enregistre l'identifiant tenté, sans acteur référencé.
- Liste de référence des actions du socle auditées : connexion, échec de connexion, mot de passe changé ou réinitialisé, invitation envoyée et acceptée, 2FA activée ou réinitialisée, surcharge de permission, permissions d'un rôle modifiées, désactivation et réactivation, anonymisation, jeton d'accès créé ou révoqué, langue activée ou désactivée. Les mentions « crée une entrée d'audit » des autres capacités renvoient à cette liste.

## CAP-13 — Consulter le journal d'audit

- Filtres : période, auteur, type d'objet, objet précis, type d'entrée (modification ou action métier).
- Chaque entrée se lit sans connaissance technique : libellés traduits, valeurs lisibles, pas d'identifiants internes bruts.
- L'objet d'une entrée est rendu en lien vers sa fiche quand le module qui le possède a déclaré cette route **et** que le lecteur a le droit de le consulter ; en texte simple dans tous les autres cas — objet supprimé, droit manquant, aucune route déclarée. Le socle n'en déclare aucune pour ses propres objets.
- L'export est unique pour tout le journal : il porte sur la sélection filtrée, archive comprise, et demande la permission d'export de la ressource « journal d'audit ».
- Le lecteur n'a jamais à choisir entre en ligne et archive. Une recherche qui remonte au-delà de la fenêtre en ligne peut être plus lente et l'écran le dit : c'est une information sur l'attente, pas sur le rangement.
- Les entrées sont immuables : aucune interface ne permet de les modifier ni de les supprimer. Seule exception, l'anonymisation de CAP-20.
- Une entrée n'est rendue à un lecteur que s'il a la permission attachée au type de son objet : le journal ne contourne pas les 403 et 404 de CAP-10.

## CAP-14 — Lire la roadmap depuis les fichiers BMAD

- Les fichiers BMAD font partie de ce qui est déployé : la roadmap en production reflète le dernier déploiement.
- Un fichier absent ou mal formé produit une roadmap partielle avec un avertissement visible des administrateurs, jamais une page en erreur.
- La roadmap affiche la date du dernier déploiement, pour situer sa fraîcheur.
- La source des statuts est `_bmad-output/implementation-artifacts/sprint-status.yaml`. Les clés `epic-{n}` portent une epic, `{n}-{m}-{titre}` une tâche ; les clés `epic-{n}-retrospective` sont exclues du calcul d'avancement.
- Priorité, difficulté, assignation et dépendances sont lues dans l'en-tête YAML du fichier de story, selon une convention que le socle définit et documente. Absentes, elles se rendent « non renseigné », et une tâche sans dépendance déclarée est prête dès que son statut est « à faire ».

## CAP-15 — Afficher la roadmap

- La roadmap est la page d'accueil de tout utilisateur qui a la permission de la voir.
- L'avancement d'une epic est le rapport des tâches terminées sur le total.
- Les statuts BMAD sont traduits en trois statuts lisibles : `backlog` et `ready-for-dev` → à faire ; `in-progress` et `review` → en cours ; `done` → terminé. Un statut inconnu est montré comme « à faire » et signalé aux administrateurs.
- Une tâche dépendante indique de quelles tâches elle dépend et si elles sont terminées.
- Les notes internes ne sont montrées qu'aux utilisateurs ayant la permission dédiée.

## CAP-16 — Lancer une tâche en environnement de développement

- L'action n'existe pas en production : ni bouton, ni point d'entrée, ni route enregistrée. Un test le vérifie.
- Seule une tâche prête (à faire, dépendances terminées) peut être lancée ; sinon le bouton est inactif et la raison est affichée.
- Une tâche déjà en cours ne peut pas être relancée.
- L'application affiche que le lancement a eu lieu, ou qu'il a échoué avec la raison (commande absente, processus non démarré).
- L'application n'écrit jamais dans les fichiers BMAD ni ailleurs dans le dépôt.

## CAP-17 — Jetons d'accès pour l'API

- La création demande un nom ; la liste des jetons du profil porte ce nom et la date de création, et rien d'autre.
- Le jeton n'est montré qu'une seule fois, à sa création ; l'application n'en conserve qu'une empreinte et ne peut pas le réafficher.
- Le jeton porte une date d'expiration et peut être révoqué par l'utilisateur lui-même ou par un administrateur depuis la fiche de l'utilisateur.
- Une requête portant un jeton expiré, révoqué, ou appartenant à un utilisateur désactivé ou anonymisé est refusée, même si le jeton a été créé avant.
- La création et la révocation d'un jeton créent chacune une entrée d'audit.
- Aucune URL de l'API n'accepte un mot de passe ; l'API n'a aucun point d'entrée d'authentification.

## CAP-18 — Health check

- La réponse indique l'état du service et de sa base de données, sans détail interne.
- Un état dégradé se traduit par un code HTTP distinct de celui de l'état nominal.
- La file d'emails cessant d'être dépilée, ou son plus vieux message dépassant un seuil, place le service en état dégradé.
- La plus vieille entrée d'audit en ligne dépassant la fenêtre de rétention place aussi le service en état dégradé : sans ce signal, un archivage arrêté (CAP-23) ne se verrait nulle part.

## CAP-19 — Interface en trois langues

- Tout texte d'interface, message d'erreur, email et libellé d'audit du socle existe en français, anglais et néerlandais ; le français est la langue par défaut tant qu'il est actif.
- La langue d'un utilisateur est mémorisée et appliquée à ses emails.
- Un module métier ajouté à un dérivé suit le même mécanisme de traduction.
- Les chemins d'URL ne varient pas avec la langue.

## CAP-20 — Anonymiser un utilisateur désactivé

- Les entrées d'audit et les objets créés par l'utilisateur subsistent et le désignent par l'identifiant neutre (« Utilisateur anonymisé #12 »).
- L'opération réécrit les valeurs et étiquettes portant son nom ou son email **partout où l'alias de type et la clé de ce compte apparaissent** (AD-12) ; elle nomme le nombre d'entrées réécrites, en ligne et en archive.
- Elle atteint la table en ligne **et l'archive**. Sans cela, nom et email survivraient au-delà d'un mois et le droit à l'effacement serait faux.
- Elle atteint **toute entrée où la personne apparaît**, pas seulement celles qui la prennent pour objet : son nom recopié dans l'étiquette d'une entrée décrivant un autre objet (« assigné à … ») est réécrit lui aussi.
- Limitation nommée et acceptée : un email déjà mis en file part à l'ancienne adresse ; l'opération ne réécrit pas les envois en attente. Un message en échec attendant d'être rejoué est en revanche abandonné.
- L'opération est irréversible et crée elle-même une entrée d'audit.
- Un utilisateur actif ne peut pas être anonymisé : il faut d'abord le désactiver.

## CAP-21 — Gérer son profil

- Le changement de mot de passe exige l'ancien mot de passe et clôt les autres sessions.
- Le réenrôlement 2FA exige le second facteur en cours ou un code de secours.
- L'utilisateur change sa langue d'interface depuis son profil.

## CAP-22 — Activer ou désactiver une langue

- Au moins une langue reste active ; désactiver la dernière est refusé.
- Une langue désactivée n'est plus proposée au choix ; un utilisateur qui l'avait choisie bascule sur la langue par défaut du dérivé.
- Le changement crée une entrée d'audit.
- Les langues actives sont une donnée d'exécution modifiable depuis l'interface, jamais une variable d'environnement.

## CAP-23 — Archiver le journal d'audit après un mois

- Les entrées de plus d'un mois sont déplacées vers un magasin d'archive ; la fenêtre est un paramètre `app.`, pas une constante.
- Une entrée archivée reste consultable et exportable par qui a la permission de lecture correspondante, sous les mêmes filtres que le journal en ligne.
- Aucune entrée n'est supprimée : archiver n'est pas purger.
- Le déplacement est exécuté par une commande planifiée, verrouillée, attribuée à l'acteur système, et rend compte du nombre d'entrées déplacées.
- Un archivage interrompu ne laisse ni doublon ni entrée perdue : une entrée est soit en ligne, soit archivée, jamais les deux ni aucune des deux.
- Une entrée archivée garde sa clé : le lien direct vers une entrée reste valable après son archivage.
- Un archivage arrêté est visible par le health check (CAP-18).
- Le journal en ligne et l'archive donnent la même réponse à une même question : la lecture ne dépend pas de savoir dans lequel des deux se trouve l'entrée, et il n'existe pas un second export.
- L'anonymisation de CAP-20 atteint l'archive au même titre que la table en ligne.

## Exigences transverses

- **Sécurité** : mots de passe hachés selon l'état de l'art ; sessions et jetons d'accès révocables ; protection CSRF sur toute écriture, la déconnexion exceptée ; aucune donnée sensible dans les journaux applicatifs ; le journal d'audit est en ajout seul.
- **Accessibilité** : plancher WCAG 2.1 de la suite proglab — couleur jamais seule, contraste, labels réels, focus visible, une seule `h1` — porté par un gabarit de base unique et vérifié en CI.
- **Performance** : connexion, fiche utilisateur, journal d'audit et roadmap répondent sous la seconde au p95, en développement comme en production, pour la fenêtre en ligne ; le journal est paginé, ses libellés résolus par lot, son export streamé. Une requête qui déborde sur l'archive sort de ce budget et l'annonce à l'écran.
- **Observabilité** : health check (CAP-18) et journaux applicatifs par canal, sans données personnelles.
- **Qualité** : le socle passe l'analyse statique et les tests de la suite proglab ; chaque capacité est couverte par au moins un test fonctionnel ; la CI livrée avec le socle bloque sur analyse statique, style, deptrac, tests, accessibilité et `composer audit`, sur une base identique à celle de production.
- **Dérivabilité** : rien dans le socle ne suppose un client particulier ; toute configuration propre à un dérivé vit dans des variables d'environnement ou des fichiers prévus pour être modifiés.
- **Données personnelles** : le journal d'audit contient des noms et des actions ; la désactivation ne supprime rien (CAP-6). Le droit à l'effacement est satisfait par l'anonymisation (CAP-20) : l'historique reste, la personne n'y est plus identifiable.

## Ordre de livraison

1. Ce qui sert SM-1 et SM-2 : CAP-1, CAP-2, CAP-3, CAP-4, CAP-7, CAP-14, CAP-15.
2. Le journal d'audit (SM-4) : CAP-11, CAP-12, CAP-13.
3. Le reste : 2FA, surcharges, anonymisation, profil, API, langues, lancement de tâche, archivage (CAP-23).

## Métriques

**Primaires**

- **SM-1** — Temps du clonage à la première fonctionnalité spécifique livrée : moins d'une journée de travail entre le premier commit du dérivé et le premier commit d'une entité métier du client, mesuré sur chaque nouveau dérivé. Valide CAP-1, CAP-2.
- **SM-2** — Délai avant que le client voie sa roadmap : au plus tard deux jours après le démarrage du projet. Valide CAP-4, CAP-14, CAP-15.

**Secondaires**

- **SM-3** — Un développeur nouveau sur un dérivé livre une story conforme au standard proglab, sans relecture bloquante, dans sa première semaine. Valide CAP-2.
- **SM-4** — Zéro question « qui a changé ça ? » restée sans réponse après consultation du journal, sur les trois premiers dérivés. Valide CAP-11 à CAP-13.

**Contre-métriques — à ne pas optimiser**

- **SM-C1** — Taille du socle : ne pas y ajouter un module tant que deux dérivés ne l'ont pas réclamé. Contrebalance SM-1.
- **SM-C2** — Volume du journal d'audit : ne pas chercher à tout tracer au point de le rendre illisible. Contrebalance SM-4.
