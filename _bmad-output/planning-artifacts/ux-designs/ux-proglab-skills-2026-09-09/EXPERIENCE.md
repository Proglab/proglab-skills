---
name: proglab-skills
version: 0.1.0
status: final
updated: 2026-09-10
design: ./DESIGN.md
sources:
  - ../../briefs/brief-proglab-skills-2026-09-09/brief.md
  - ../../prds/prd-proglab-skills-2026-09-09/prd.md
---

# Socle ERP custom (proglab) — Experience Spine

> Web desktop responsive, Symfony UX Toolkit kit shadcn. Ce document dit comment le
> socle se comporte ; `DESIGN.md` dit à quoi il ressemble. Les deux ne spécifient que le
> delta par rapport aux défauts du kit shadcn et aux règles de la suite proglab
> (`symfony-proglab-ui`, `symfony-proglab-accessibility`, `symfony-proglab-frontend`),
> que les deux documents citent par leur nom. Le vocabulaire est celui du glossaire du
> PRD (§3) ; les parcours UJ-1..UJ-5 et les exigences FR-1..FR-22 sont hérités du PRD par
> référence, jamais recopiés. **En cas de conflit entre une maquette (`mockups/`) et ce
> document ou `DESIGN.md`, les spines l'emportent.** Les titres de section gardent leur
> nom canonique anglais ; le contenu est en français.

## Foundation

- **Form-factor.** Surface principale : le web sur desktop — la quasi-totalité des
  utilisateurs y est. « Mobile first » est une discipline de construction, pas une
  cible : chaque écran est composé depuis la plus petite largeur avec les composants
  shadcn, pour qu'une PWA puisse envelopper l'application plus tard si le besoin
  apparaît. La PWA elle-même et l'application mobile native sont hors périmètre (PRD
  §7). `[ASSUMPTION]` Le PRD excluant une application native, « mobile first » s'entend
  comme web responsive conçu d'abord pour l'écran de téléphone.
- **Système UI.** Symfony UX Toolkit, kit shadcn, composants copiés dans le projet
  (`symfony-proglab-ui`). `DESIGN.md` est la référence visuelle et nomme le thème de
  départ neutre (white-label) et le contrat de rebranding.
- **Pile.** Twig, Stimulus, Turbo (Drive + Frames), AssetMapper, Tailwind via
  `symfonycasts/tailwind-bundle` — sans bundler, sans étape Node
  (`symfony-proglab-frontend`). Component Patterns dit, composant par composant, où vit
  chaque comportement, selon la seule question du skill : *où vit l'état ?*
- **Modes.** Clair et sombre, `prefers-color-scheme` par défaut, bascule
  clair / sombre / système mémorisée par utilisateur. Trois langues (français, anglais,
  néerlandais), chacune désactivable par un administrateur.
- **Un dérivé, un client, un déploiement.** Pas de multi-tenant, pas de sélecteur
  d'organisation, pas d'écran commun à plusieurs ERP.
- **Sources.** `[ASSUMPTION]` Le brief et le PRD du 2026-09-09 sont les seules sources
  amont ; aucun import visuel n'a été fourni.

## Information Architecture

Principe : le plus simple possible. Peu d'écrans, un chemin évident par tâche, aucun
réglage qui ne soit exigé par un FR. Les chemins ci-dessous sont indicatifs (nom de
route Symfony en `app_*`, paramètres notés `:id` par convention de route) ;
l'architecture fixe les chemins définitifs.

**Mise à jour du 2026-09-10.** Elle les a fixés, et en anglais : AD-6 de
`../../architecture/architecture-proglab-skills-2026-09-09/ARCHITECTURE-SPINE.md`
porte la table complète (`/users`, `/audit-log`, `/roles`, `/languages`,
`/profile/{tab}`…), invariables quelle que soit la langue du lecteur. Les chemins
français de la colonne ci-dessous sont donc périmés ; les noms de route `app_*` et
tout le reste de la table restent valables.

### Surfaces

| Surface | Route (indicatif) | Atteinte depuis | Rôle |
|---|---|---|---|
| Connexion | `app_login` `/connexion` | toute URL protégée sans session | email + mot de passe ; liens de langue (langues actives, règle Liens de langue) ; lien « Mot de passe oublié ? » |
| Mot de passe oublié | `app_password_request` `/mot-de-passe/oublie` | Connexion | demande d'un lien à usage unique `[ASSUMPTION PRD]` valable une heure |
| Réinitialisation du mot de passe | `app_password_reset` `/mot-de-passe/reinitialiser/:token` | email | nouveau mot de passe, deux saisies |
| Acceptation d'invitation | `app_invitation_accept` `/invitation/:token` | email | création du mot de passe ; le compte devient actif avec le rôle prévu (FR-4) |
| Vérification 2FA | `app_2fa_check` `/connexion/verification` | Connexion, quand la 2FA est active | second facteur (TOTP ou code par email `[ASSUMPTION PRD]`) dans un seul champ de code ; lien vers la page « Code de secours » (`/connexion/verification/secours`) |
| Enrôlement 2FA | `app_2fa_enroll` `/profil/double-authentification/activer` | Banner, Profil, redirection forcée (FR-5) | choix de la méthode, vérification, codes de secours |
| Roadmap (accueil) | `app_home` `/` | connexion réussie, Sidebar | epics en Accordion, tâches, dépendances, date du dernier déploiement (FR-14, FR-15, FR-16) |
| Journal d'audit | `app_audit_index` `/journal-audit` | Sidebar | Table filtrée et paginée, export (FR-13) |
| Entrée d'audit (panneau) | `app_audit_show` `/journal-audit/:id` | ligne du journal | Sheet à droite : avant / après ou action métier ; URL partageable |
| Utilisateurs | `app_user_index` `/utilisateurs` | Sidebar | Table des comptes, bouton « Inviter » (FR-4, FR-6) |
| Inviter un utilisateur | `app_user_invite` `/utilisateurs/inviter` | Utilisateurs | email, prénom, nom, rôle |
| Fiche utilisateur | `app_user_show` `/utilisateurs/:id` | ligne d'Utilisateurs | identité, rôle, permissions avec origine (FR-8, FR-9), actions : désactiver / réactiver, anonymiser (FR-20), réinitialiser la 2FA (FR-5), renvoyer l'invitation (FR-4) |
| Rôles | `app_role_index` `/roles` | Sidebar | liste des rôles ; création (FR-7) |
| Fiche rôle | `app_role_show` `/roles/:id` | ligne de Rôles | permissions du rôle, cases à cocher par groupe |
| Langues | `app_language_index` `/langues` | Sidebar | trois lignes, boutons « Désactiver » / « Réactiver » (FR-22) |
| Profil | `app_profile` `/profil/:onglet` | menu compte | Tabs : Langue · Mot de passe · Double authentification · Jetons d'API (FR-21, FR-17) |
| Accès refusé | 403 | toute zone non permise | page courte, libellé traduit (FR-10) |
| Page introuvable | 404 | objet inexistant ou non permis | même page pour les deux cas (FR-10) |
| Erreur inattendue | 500 (et toute réponse que Turbo n'obtient pas) | n'importe où | même gabarit court que 403 : message Voice and Tone, lien « Retour à la roadmap », aucun détail technique ; rendue par le template d'erreur Symfony, donc aussi sans Turbo |

Sans interface : **FR-1** (commande d'initialisation, terminal — `symfony-proglab-console`),
**FR-2** (guide de dérivation, fichiers du dépôt), **FR-11** et **FR-12** (enregistrement
côté serveur ; leur résultat se lit dans le Journal d'audit), **FR-18** (health check
JSON). **FR-17** a une interface pour gérer les jetons (Profil), pas pour les obtenir par
l'API.

**Écrans maquettés.** Trois écrans clés et la direction retenue sont rendus en HTML 1:1
dans `mockups/` ; chaque fichier illustre :

- `mockups/key-connexion-invitation.html` — Connexion (en clair et en sombre, Card
  centrée, liens de langue, erreur générique au-dessus du formulaire) et Acceptation
  d'invitation (« Bienvenue Karim ! », prénom et rôle rappelés, deux champs de mot de
  passe ; page « Invitation expirée » sans formulaire).
- `mockups/key-fiche-utilisateur.html` — Fiche de Karim vue par Sophie : Flash après
  redirection, Identité / Permissions / Sécurité en `h2`, Table des permissions avec
  état effectif et Badge d'origine, Dialog « Anonymiser Karim Benali ? ».
- `mockups/key-profil.html` — Profil de Sophie : Banner 2FA, Tabs en liens, onglet
  Double authentification avec Alert « Codes de secours », onglet Jetons d'API.
- `mockups/direction-aere.html` — la direction retenue (Aéré) sur l'accueil Roadmap de
  Marc en production, en clair et en sombre. Le Banner 2FA y est illustratif : dans
  UJ-3, Marc a déjà sa 2FA active.

Spine-only (décision du run UX, consignée dans `.memlog.md`) : Utilisateurs (liste),
Rôles, Fiche rôle, Langues, Journal d'audit et les pages d'erreur n'ont ni flux dédié ni
maquette — les tableaux de ce document et de `DESIGN.md` sont leur seule spécification.

### Navigation

Sidebar shadcn : fixe à `{spacing.sidebar-width}` à partir de `lg`, tiroir (Sheet à
gauche) sous `lg`, ouvert par un bouton hamburger dans l'en-tête. Deux groupes. Une
entrée n'est rendue que si l'utilisateur a la permission effective correspondante
(FR-10), calculée côté serveur à chaque requête — la navigation se dérive des
permissions, jamais du nom du rôle.

| Entrée | Groupe | Permission requise | Super admin | Admin | User |
|---|---|---|---|---|---|
| Roadmap | Suivi | voir la roadmap | oui | oui | oui |
| Journal d'audit | Suivi | consulter le journal d'audit | oui | oui | par surcharge seulement (Karim, UJ-2) |
| Utilisateurs | Administration | administrer les comptes | oui | oui | non |
| Rôles | Administration | administrer les permissions | oui | oui | non |
| Langues | Administration | administrer les comptes | oui | oui | non |

Le groupe « Administration » n'est pas rendu s'il est vide. Le pied de la Sidebar montre
Avatar, nom et rôle de l'utilisateur. L'en-tête porte, à droite, le bouton compte
(`{components.button-account}`) qui ouvre le DropdownMenu : Profil · Langue · Thème ·
Se déconnecter.

### Exigences → surfaces

| FR | Surface(s) |
|---|---|
| FR-3 | Connexion, Mot de passe oublié, Réinitialisation |
| FR-4 | Inviter un utilisateur, Fiche utilisateur (renvoyer), Acceptation d'invitation |
| FR-5 | Vérification 2FA, Enrôlement 2FA, Profil › Double authentification, Fiche utilisateur (réinitialiser), Banner |
| FR-6 | Fiche utilisateur (désactiver / réactiver), Utilisateurs (Badge « Compte désactivé ») |
| FR-7 | Rôles, Fiche rôle |
| FR-8, FR-9 | Fiche utilisateur › Permissions |
| FR-10 | Sidebar (entrées masquées), 403, 404 |
| FR-13 | Journal d'audit, Entrée d'audit |
| FR-14 | Roadmap (Alert « roadmap partielle », date du dernier déploiement, Badge « Non lisible ») |
| FR-15 | Roadmap |
| FR-16 | Roadmap (bouton « Lancer », environnement de développement seulement) |
| FR-17 | Profil › Jetons d'API |
| FR-19 | Connexion (sélecteur), Profil › Langue, menu compte › Langue |
| FR-20 | Fiche utilisateur (anonymiser) |
| FR-21 | Profil |
| FR-22 | Langues |

### Besoins → surfaces → parcours

| Besoin (PRD §2.1) | Surface | Parcours |
|---|---|---|
| Partir d'une base qui fonctionne, savoir quelle tâche prendre | Roadmap (dev) | UJ-1, UJ-5 |
| Faire entrer les bonnes personnes, donner exactement les droits | Inviter, Fiche utilisateur, Rôles | UJ-2 |
| Répondre à « qui a fait ça ? » | Journal d'audit, Entrée d'audit | UJ-4 |
| Se connecter sans friction, dans sa langue | Connexion, Acceptation d'invitation, Profil › Langue | UJ-2, Flux 6, Flux 7 |
| Ne voir que ce qui me concerne | Sidebar filtrée, 403 / 404 | UJ-2 (Karim), UJ-3 |
| Voir où en est la construction sans rien comprendre à BMAD | Roadmap (prod) | UJ-3 |

## Voice and Tone

Vouvoiement chaleureux, prénoms, phrases courtes, encouragement sur les états vides.
Le vocabulaire est celui du glossaire du PRD — « tâche », « epic », « à faire / en cours /
terminé », « Tâche prête » — jamais « story », « sprint », « backlog », « ready-for-dev ».
Tout texte d'interface existe en français, anglais et néerlandais ; **le français est
la langue source**, les deux autres sont des traductions des clés (FR-19).

Règles : jamais de point d'exclamation hors bienvenue ; jamais d'identifiant technique,
de code HTTP ni de nom de classe dans un texte ; un message d'erreur dit toujours quoi
faire ensuite ; un message de confirmation destructive nomme la personne et la
conséquence.

Le tableau ci-dessous est la version française de référence de chaque chaîne, une ligne
par moment ; State Patterns et Key Flows désignent une chaîne par son moment ou la citent
en abrégé.

| Moment | Écrire | Ne pas écrire |
|---|---|---|
| Accueil roadmap | « Bonjour Marc. Voici où en est votre ERP. Ouvrez une epic pour voir ses tâches. » | « Tableau de bord — Roadmap du projet » |
| Pastille de dernier déploiement | « Dernier déploiement : mardi 8 septembre 2026 à 18 h 42 » | Un numéro de build |
| Dépendance d'une epic | « En attente de : Gestion des devis » | Un statut sans la dépendance nommée |
| Premier accès après invitation | « Bienvenue Sophie ! Votre compte est prêt. » | « Compte créé avec succès. » |
| Roadmap vide (UJ-1) | « Votre roadmap est vide pour l'instant. Elle se remplira dès que la première epic sera planifiée. » | « Aucune donnée. » |
| Journal d'audit vide | « Rien à montrer pour ces filtres. Élargissez la période ou retirez un filtre. » | « 0 résultat(s). » |
| Chargement du panneau d'audit | « Chargement de l'entrée… » | Un panneau vide pendant le chargement |
| Utilisateurs : vous seul | « Vous êtes seul pour l'instant. Invitez un premier collègue. » | « Liste vide. » |
| Mot de passe erroné | « Email ou mot de passe incorrect. » — puis, à partir du cinquième échec : « Email ou mot de passe incorrect. Patientez 40 secondes avant de réessayer. » | « Mot de passe invalide pour cet utilisateur. » (révèle l'existence du compte) |
| Compte désactivé à la connexion | « Ce compte est désactivé. Adressez-vous à votre administrateur. » | Un message différent selon que le mot de passe était bon |
| Mot de passe oublié : confirmation | « Si un compte existe pour cette adresse, un lien vient d'être envoyé. Il est valable une heure. » — quel que soit l'email saisi | Un message qui révèle si le compte existe |
| Flash après réinitialisation du mot de passe | « Mot de passe changé. Connectez-vous avec le nouveau. » | Un message qui ne dit pas quoi faire ensuite |
| Invitation expirée ou déjà utilisée | « Ce lien d'invitation n'est plus valable. Demandez à votre administrateur de vous en envoyer un nouveau. » — si le compte est déjà actif : « Cette invitation a déjà été acceptée. Connectez-vous. » + lien | « Token expired » |
| Inviter : email d'un compte actif | « Un compte existe déjà pour cette adresse : Karim Benali (Actif). » + lien « Voir sa fiche » | « Email déjà utilisé » |
| Inviter : email d'un compte désactivé | « Cette adresse appartient à un compte désactivé : Karim Benali. Réactivez-le plutôt que de l'inviter à nouveau. » + lien « Ouvrir sa fiche pour le réactiver » | Une nouvelle invitation envoyée sans rien dire |
| Inviter : email déjà invité | « Karim Benali a déjà été invité le 2 septembre ; son invitation est valable jusqu'au 9 septembre. » + bouton « Renvoyer l'invitation » | « Doublon » |
| Dialog renvoyer l'invitation | « Renvoyer l'invitation à Karim ? L'ancien lien sera invalidé. » | Une confirmation qui ne nomme pas la personne |
| Flash après renvoi d'invitation | « Invitation renvoyée. » | « OK » |
| Flash après ajout d'une permission | « Permission ajoutée pour Karim Benali. » | Un message qui ne nomme pas la personne |
| Code 2FA incorrect | « Code incorrect. Vérifiez l'heure de votre téléphone, ou utilisez un code de secours. » | « Invalid TOTP » |
| Code par email expiré | « Ce code n'est plus valable. Nous pouvons vous en envoyer un nouveau. » + bouton « Renvoyer un code » — aide sous le champ : « Le code reçu par email est valable 10 minutes. » | « Code expiré (600 s) » |
| Code par email renvoyé | « Un nouveau code vient d'être envoyé. » | (rien) |
| Code de secours accepté | « Code de secours accepté. Il ne fonctionnera plus : il vous en reste 4. » | « OK » |
| Dernier code de secours utilisé | « Vous venez d'utiliser votre dernier code de secours. Générez-en de nouveaux maintenant dans votre profil. » + lien « Générer de nouveaux codes » | (rien) |
| Plus aucun code de secours | « Vous n'avez plus de code de secours. Adressez-vous à votre administrateur pour réinitialiser votre double authentification. » | « Backup codes exhausted » |
| Échec de vérification à l'enrôlement | « Code incorrect. Scannez à nouveau le QR code ou vérifiez l'heure de votre téléphone, puis réessayez. » | « Erreur de configuration » |
| Flash après enrôlement 2FA | « Double authentification activée. » | (rien) |
| Profil : 2FA exigée par le rôle | « Votre rôle exige la double authentification. » | Un bouton « Désactiver » grisé sans explication |
| Erreur inattendue (500) | « Quelque chose n'a pas fonctionné. Réessayez ; si cela persiste, prévenez votre administrateur. » + lien « Retour à la roadmap » | « Internal Server Error », une trace |
| Session close (désactivation, changement de mot de passe) | « Vous avez été déconnecté. » | (rien) |
| Session expirée par inactivité | « Votre session avait expiré ; vos modifications n'ont pas été enregistrées. Reconnectez-vous pour reprendre. » | « Session timeout » |
| Lien de réinitialisation expiré | « Ce lien a expiré. Demandez-en un nouveau. » + bouton | « Erreur 400 » |
| 403 | « Vous n'avez pas accès à cette page. Si vous pensez que c'est une erreur, adressez-vous à votre administrateur. » + lien « Retour à la roadmap » | « Access Denied. » |
| 404 | « Cette page n'existe pas. » + lien « Retour à la roadmap » | « Objet 4213 introuvable » |
| Banner 2FA | « Sophie, votre rôle demande la double authentification. Il vous reste 5 jours pour l'activer. Activer maintenant » | « 2FA requise ! » |
| Bloqué après le délai | « Pour continuer, activez votre double authentification. Cela prend deux minutes. » | « Accès bloqué » |
| Dialog désactiver | Titre « Désactiver Karim Benali ? » — corps « Karim ne pourra plus se connecter et ses sessions en cours seront closes. Rien ne sera supprimé : vous pourrez le réactiver. » — boutons « Annuler » / « Désactiver » | « Êtes-vous sûr ? » / « OK » |
| Dialog anonymiser | Titre « Anonymiser Karim Benali ? » — corps « Son nom et son email seront remplacés par “Utilisateur anonymisé #12” partout, y compris dans le journal d'audit. **Cette action est irréversible.** » — champ « Saisissez ANONYMISER pour confirmer » — boutons « Annuler » / « Anonymiser définitivement » | Une simple confirmation en un clic |
| Dialog désactiver une langue | « Désactiver le néerlandais ? Il ne sera plus proposé. 2 utilisateurs l'utilisent : leur interface passera en français. » — boutons « Annuler » / « Désactiver » | Une confirmation qui ne nomme pas les utilisateurs concernés |
| Flash après désactivation d'une langue | « Néerlandais désactivé. » | (rien) |
| Langue désactivée pendant la session | « Le néerlandais n'est plus disponible ; votre interface est passée en français. » | Un changement de langue silencieux |
| Flash après invitation | « Invitation envoyée à karim@… Elle est valable 7 jours. » | « Succès » |
| Flash après lancement (dev) | « Tâche lancée. Les agents travaillent sur votre poste ; rechargez la page pour voir l'avancement. » | « Process started (pid 4821) » |
| Lancement échoué (dev) | « Le lancement a échoué : commande introuvable » / « le processus n'a pas démarré » | Une trace ou un code de sortie |
| Bouton Lancer inactif | « En attente de : Validation du devis par le client (en cours) » | « Dépendances non satisfaites » |
| Roadmap partielle (admin) | « Roadmap partielle : une epic n'a pas pu être lue. Le fichier de l'epic “Suivi des livraisons” est mal formé (ligne 14 : statut “shipped” inconnu). Les autres epics sont affichées normalement. » | Une page d'erreur |
| Pied de roadmap | « Cette page reflète le dernier déploiement, pas le temps réel. » | (rien) |

## Component Patterns

Comportemental. Le visuel est dans `DESIGN.md · Components`. La colonne « Où vit le
comportement » applique la grille de `symfony-proglab-frontend` (état dans le navigateur
→ Stimulus ; état serveur retenu entre interactions → Live Component ; soumission sans
état → Turbo ; rendu pur → composant Twig). Le socle ne prévoit **aucun Live
Component** : rien n'exige un état serveur retenu entre deux interactions.

Les composants courts tiennent dans le tableau ci-dessous, par groupe. Six composants
au comportement plus long ont chacun leur sous-section après le tableau : Sheet, Table,
Champ de formulaire, Champ de code 2FA, DropdownMenu et Bouton « Lancer ».

### Composants courts

| Composant | Où | Règles comportementales | Où vit le comportement |
|---|---|---|---|
| **Coque** | | | |
| Sidebar | coque | Balisage : `<nav aria-label="Navigation principale">` contenant une `<ul>` par groupe, chacune `aria-labelledby` sur son intitulé « Suivi » / « Administration » — jamais un `<aside>`, jamais deux `<nav>` sans nom. Entrée courante `aria-current="page"`. Sous `lg` : Sheet à gauche ouvert par le hamburger (`aria-expanded`), fermé par Échap, clic hors panneau ou navigation ; le focus revient au hamburger. Entrées non permises non rendues (FR-10), filtrées côté serveur. | Twig (rendu, filtrage) ; Stimulus du kit pour l'ouverture |
| Avatar | Sidebar, en-tête, journal | Initiales calculées côté serveur ; `aria-hidden` quand le nom est adjacent. | Twig |
| Banner (2FA) | sous l'en-tête, toutes les pages | Affiché à un Super admin / Admin sans 2FA active tant que le délai de grâce court ; non fermable ; le lien mène à Enrôlement 2FA. À l'expiration, plus de Banner : redirection forcée vers Enrôlement 2FA à chaque requête hors Profil et Déconnexion. | Twig (condition serveur) |
| **Roadmap** | | | |
| Card | Roadmap, Fiches | Conteneur sans comportement : une carte par epic (résumé = Accordion) ou par section de Fiche. Hors Accordion, une Card n'est jamais cliquable en entier — les actions sont des boutons dans la carte. | Twig |
| Accordion (carte d'epic) | Roadmap | Résumé cliquable en entier, Entrée et Espace déplient. Balisage : `<h2><button aria-expanded aria-controls>Gestion des devis</button></h2>` — le bouton ne contient que le titre ; Badge, description et bloc d'avancement sont hors du bouton mais dans la zone cliquable. Le niveau de titre du composant du kit est fixé à 2. Ouverte par défaut : les epics « En cours » ; fermées : « Terminé » et « À faire ». Le dépliage ne charge rien : toutes les tâches sont dans la page. | Stimulus du kit (état ouvert/fermé, navigateur seulement) |
| Progress | Roadmap | Rendue à sa valeur finale. La barre est `aria-hidden="true"` : la phrase « 6 sur 10 tâches terminées » et le pourcentage portent toute l'information (pas de `role="progressbar"` qui la ferait lire deux fois). Une epic non lisible affiche « — » et aucune barre. | Twig |
| Badge | partout | Toujours icône + libellé. Statut de tâche : `{components.badge-todo}` / `{components.badge-doing}` / `{components.badge-done}` ; `{components.badge-ready}` remplace « À faire » quand la tâche est prête ; `{components.badge-unreadable}` sur une epic mal formée ; `{components.badge-deactivated}` sur un compte désactivé. | Twig (composant anonyme, prop `status`) |
| **Données** | | | |
| Colonnes prioritaires | Table < `md` | Utilisateurs : Nom · Rôle · État. Journal : Date · Auteur · Objet. Permissions : Permission · Origine. Avant/après : Champ · Nouvelle valeur (ancienne valeur dans le détail). | Twig (classes responsive) |
| Filtres du journal | Journal d'audit | Formulaire GET dans la Card du tableau : période en `<fieldset><legend>Période</legend>` avec deux champs date labellisés « Du » / « Au », auteur (Select), type d'objet (Select), objet précis (texte, label « Objet »), type d'entrée en `<fieldset><legend>Type d'entrée</legend>` de radios. Bouton « Filtrer » explicite — pas de filtrage à la frappe. Après soumission, le nombre de résultats (« 3 entrées ») est rendu juste sous le `h1`, lu après le focus post-navigation. Bouton « Exporter la sélection » (`{components.button-outline}`, `data-turbo="false"`) exporte exactement les filtres courants `[ASSUMPTION PRD]` en CSV. | Twig + Turbo Drive (GET) ; export hors Turbo |
| Pagination | Journal d'audit, Utilisateurs | Précédent / Suivant + « Page n sur m », dans un `<nav aria-label="Pagination">`. Paramètre `page` dans l'URL. Pas de défilement infini. | Twig + Turbo Drive |
| **Formulaires** | | | |
| Button | partout | Un seul bouton primaire par écran. Un bouton inactif pour une raison métier n'est jamais `disabled` (il sortirait de l'ordre de tabulation) : `aria-disabled="true"`, focusable, `aria-describedby` vers sa raison visible, et le serveur ignore le clic. `destructive` n'apparaît que dans le pied d'un Dialog ; un bouton `size="icon"` a un `aria-label`. Désactivation pendant l'envoi : Champ de formulaire. | Twig (kit) ; Turbo Drive |
| Tabs (Profil) | Profil | Balisage : `<nav aria-label="Sections du profil">` de liens vers `/profil/:onglet`, l'onglet courant en `aria-current="page"` — pas de `role="tab"` ni d'`aria-selected` (des liens qui naviguent ne sont pas des tabs ARIA) ; le visuel Tabs du kit reste. Pas d'état client : l'URL est l'état. | Twig + Turbo Drive |
| Dialog (confirmation destructive) | Fiche utilisateur (désactiver, anonymiser, réinitialiser 2FA), Profil (révoquer un jeton), Langues (désactiver) | Ouvert par le bouton d'action (`{{ ...dialog_trigger_attrs }}`), `aria-modal`, focus piégé, Échap = Annuler, focus rendu au déclencheur. Le bouton de confirmation soumet un `<form method="post">` porteur d'un jeton CSRF. Anonymiser exige en plus la saisie du mot « ANONYMISER ». Un seul Dialog à la fois. | Stimulus du kit ; POST Turbo Drive → redirection + Flash |
| Liens de langue (Connexion) | Connexion | `<nav aria-label="Langue">` de liens `?lang=fr` / `?lang=en` / `?lang=nl`, un par langue active, chacun avec `lang` et `hreflang`, l'actif en `aria-current="true"`. **Jamais un `<select>` qui soumet au changement** (WCAG 3.2.2 : au clavier, chaque flèche rechargerait la page). Le choix est mémorisé en session ; après connexion, la langue du profil prend le relais. | Twig + Turbo Drive (GET) — aucun Stimulus |
| Boutons de langue (Langues) — pas un Switch | Langues | Bouton « Désactiver » / « Réactiver » par ligne ; Désactiver ouvre un Dialog nommant le nombre d'utilisateurs concernés et la langue de repli. La dernière langue active n'a pas de bouton « Désactiver » et une note l'explique. | Twig ; POST Turbo Drive |
| **Retour utilisateur** | | | |
| Flash | haut du contenu | Rendu serveur après redirection (303), `tabindex="-1"`. Un Flash présent dès le rendu n'est pas annoncé de lui-même par un lecteur d'écran : l'annonce et le focus passent par le mécanisme d'Accessibility Floor › Sous Turbo Drive. Fermable au clic ; ne disparaît pas seul. Un seul Flash à la fois. | Twig ; Stimulus `announce` et `page-focus` (Accessibility Floor) ; Stimulus `dismiss` (retire l'élément) |
| Alert | Roadmap (partielle), Enrôlement 2FA (codes de secours), Fiche utilisateur (invitation en attente), Erreur inattendue | Statique, non fermable, jamais `role="alert"` (un `role="alert"` présent au chargement est un contresens ARIA) : `role="region"` + `aria-labelledby` sur son titre `h3` pour l'avertissement roadmap partielle et les codes de secours ; `role="note"` sinon. | Twig |

### Sheet (entrée d'audit)

Où : Journal d'audit.

- **Balisage.** Un clic sur la ligne ouvre le panneau à droite. Le lien vit dans la
  première cellule et porte un nom complet : la date en texte visible, le reste en
  `sr-only` (« Ouvrir l'entrée du 8 septembre 14 h 12, Karim Benali, Tarif Chêne
  massif »). Un `::after` sur ce seul lien étend la zone cliquable à la ligne ; les
  autres cellules restent sélectionnables. Contenu du panneau : auteur (avec « compte
  désactivé » ou « Utilisateur anonymisé #n » le cas échéant), horodatage complet,
  objet, puis Table avant / après champ par champ, ou le libellé de l'action métier.
- **Ouverture / fermeture.** Les filtres et la pagination restent derrière et gardent
  leur état. Échap, bouton Fermer et clic sur le voile ferment. L'URL
  `/journal-audit/:id` est partageable et rend la page complète avec le panneau ouvert.
- **Focus.** `aria-modal`, focus piégé dans le panneau ; à la fermeture, rendu à la
  ligne.
- **Où vit le comportement.** Turbo Frame `audit_entry` (chargement du contenu) ;
  Stimulus du kit pour l'ouverture, la fermeture et le piège de focus.

### Table

Où : Utilisateurs, Journal d'audit, Fiche utilisateur (permissions), avant / après.

- **Balisage.** Toujours dans une Card (`DESIGN.md · Layout & Spacing`). Ligne
  cliquable = lien dans la première cellule, nommé complètement (« Ouvrir la fiche de
  Karim Benali, User, Actif » ; même règle que le Sheet), zone étendue en `::after` sur
  ce lien.
- **Tri et filtres.** Tri par en-tête (lien, `aria-sort`) uniquement sur Utilisateurs
  (nom, rôle, état). Journal : tri fixe par date décroissante.
- **Sous `md`.** Colonnes prioritaires seulement (ligne Colonnes prioritaires) ; un
  appui sur la ligne ouvre le détail (Sheet pour le journal, Fiche pour un utilisateur)
  où tout est visible.
- **Actions.** Pas d'action de ligne au survol : les actions sont sur la Fiche ; les
  actions en ligne de la Table des permissions sont des boutons `outline` toujours
  visibles.
- **Focus.** Anneau `:focus-visible` sur le lien de ligne, comme sur tout élément
  interactif (règle 4).
- **Où vit le comportement.** Twig ; Turbo Drive pour tri et filtres (GET, URL
  partageable).

### Champ de formulaire

Où : tous les formulaires.

- **Balisage.** Un label réel, toujours (règle 3). Chaque champ qui concerne la
  personne connectée porte son jeton `autocomplete` HTML (WCAG 1.3.5), posé dans le
  FormType : email de connexion `email`, prénom `given-name`, nom `family-name`, mot de
  passe de Connexion `current-password`, mot de passe actuel du Profil
  `current-password`, nouveau mot de passe et sa confirmation (Invitation,
  Réinitialisation, Profil) `new-password`, code 2FA `one-time-code`. Les champs qui
  décrivent quelqu'un d'autre (Inviter : email, prénom, nom du collègue) ne portent
  aucun jeton.
- **Soumission.** Entrée soumet. Le bouton de soumission est désactivé pendant l'envoi
  (Turbo le fait).
- **Validation.** La validation est serveur seule. Une réponse 422 réaffiche le
  formulaire avec les erreurs sous chaque champ (`aria-invalid`, `aria-describedby`),
  le focus sur le premier champ en erreur. Si l'erreur n'a pas de champ (message
  générique de Connexion), un bloc `role="alert"` `tabindex="-1"` au-dessus du
  formulaire la porte et reçoit le focus.
- **Focus.** Au rendu 422, le contrôleur `page-focus` applique l'ordre d'Accessibility
  Floor › Sous Turbo Drive : bloc d'erreur générique s'il existe, sinon premier champ
  `aria-invalid`.
- **Où vit le comportement.** FormType (`symfony-proglab-http`) ; Turbo Drive ;
  Stimulus `page-focus` (focus au rendu 422).

### Champ de code 2FA

Où : Vérification 2FA, Enrôlement 2FA, Code de secours.

- **Balisage.** Un seul `<input type="text">`, jamais six cases : label visible « Code
  à 6 chiffres de votre application » / « Code reçu par email » / « Code de secours »,
  `inputmode="numeric"`, `autocomplete="one-time-code"`, `pattern="[0-9]{6}"` pour le
  TOTP (le serveur tolère les espaces et les retire). Visuel : `{components.input-otp}`.
- **Saisie et soumission.** Collage autorisé, **aucune soumission automatique** —
  l'utilisateur appuie sur Entrée ou sur « Vérifier ». Code par email : aide « valable
  10 minutes » `[ASSUMPTION]` et bouton `outline` « Renvoyer un code » (POST, limité par
  le serveur).
- **Erreur et focus.** Erreur sous le champ comme tout autre champ (`aria-invalid` +
  `aria-describedby`), focus sur le champ.
- **Code de secours.** Sous le champ : « Je n'ai pas accès à mon application — utiliser
  un code de secours », lien vers une page distincte qui porte le même champ.
- **Où vit le comportement.** FormType ; Turbo Drive (POST + 422) — aucun Stimulus.

### DropdownMenu (compte)

Où : en-tête.

- **Balisage.** Bouton nommé « Menu du compte — Marc Dubois » (préfixe `sr-only`).
  Langue et Thème sont des groupes `menuitemradio` avec `aria-checked` ; chaque option
  de langue porte son `lang` (« English » `lang="en"`, « Nederlands » `lang="nl"`).
- **Ouverture / fermeture.** Ouvert au clic ou Entrée, navigation aux flèches, Échap
  ferme et rend le focus au bouton.
- **Choix.** Un choix soumet un POST ; la page revient dans la nouvelle langue ou le
  nouveau thème. Pour le thème, Stimulus applique le choix côté client sans attendre la
  réponse ; la classe `dark` et `color-scheme` sur `<html>` sont rendues côté serveur
  depuis la préférence mémorisée (aucun script inline, donc aucun clignotement) ;
  « Système » ne pose rien et laisse `prefers-color-scheme` agir.
- **Où vit le comportement.** Stimulus du kit ; Stimulus `theme` (classe `dark` sur
  `<html>`) ; POST Turbo pour persister.

### Bouton « Lancer »

Où : Roadmap, ligne de tâche, **dev seulement**.

- **Rendu.** Uniquement si `APP_ENV=dev` et permission de développement (Super admin).
  Une tâche « En cours » n'a pas de bouton. Visuel : `DESIGN.md · Components › Ligne de
  tâche (dev)`.
- **Actif / inactif.** Actif seulement sur une tâche prête ; sinon inactif selon la
  règle Button (`aria-disabled`, focusable, jamais `disabled`), avec `aria-describedby`
  vers la raison visible sous le bouton — la dépendance nommée avec son statut (Voice
  and Tone › Bouton Lancer inactif). Le serveur refuse le POST d'une tâche non prête.
- **Clic.** POST, puis Flash de succès ou `{components.alert-destructive}` avec la
  raison (Voice and Tone › Flash après lancement, Lancement échoué).
- **Où vit le comportement.** Twig (condition serveur) ; POST Turbo Drive, redirection
  et Flash.

## State Patterns

### Chargement & vide

| État | Surface | Traitement |
|---|---|---|
| Chargement de page | toutes | Barre de progression Turbo Drive par défaut (retour fonctionnel, visible seulement au-delà de 500 ms). Aucun squelette. |
| Chargement du panneau | Entrée d'audit | Le Sheet s'ouvre immédiatement avec la phrase de chargement (Voice and Tone) dans le Frame, remplacée par le contenu. |
| Roadmap vide | Roadmap | `{typography.lead}` « Votre roadmap est vide pour l'instant… » ; pour un Super admin en dev, une ligne `{typography.meta}` ajoute où les fichiers BMAD sont attendus. Pas de bouton. |
| Journal vide / filtres sans résultat | Journal d'audit | Phrase d'état vide sous les filtres, filtres conservés. |
| Aucun utilisateur autre que soi | Utilisateurs | Phrase + `{components.button-primary}` « Inviter un utilisateur ». |

### Invitations

| État | Surface | Traitement |
|---|---|---|
| Invitation en attente | Fiche utilisateur, Utilisateurs | `{components.badge-invited}` « Invitation en attente » + date d'expiration ; bouton « Renvoyer l'invitation » ; sur la ligne, État = `{components.badge-invited}` « Invité ». |
| Invitation expirée ou déjà utilisée | Acceptation d'invitation | Page courte, message Voice and Tone, aucun formulaire ; si le lien a déjà été accepté, message « déjà acceptée » + lien vers Connexion. Côté admin : État = `{components.badge-expired}` « Invitation expirée », bouton « Renvoyer ». |
| Inviter : email d'un compte actif | Inviter un utilisateur | 422, erreur sous le champ email nommant la personne + lien « Voir sa fiche » ; rien n'est envoyé. |
| Inviter : email d'un compte désactivé | Inviter un utilisateur | 422, erreur sous le champ email proposant la réactivation + lien vers la Fiche (où vit le bouton « Réactiver ») ; pas de seconde invitation. |
| Inviter : email déjà invité | Inviter un utilisateur | 422, erreur sous le champ email avec la date d'envoi et d'expiration + bouton « Renvoyer l'invitation » (même Dialog qu'UJ-2 : l'ancien lien est invalidé). |

### Connexion & 2FA

| État | Surface | Traitement |
|---|---|---|
| Mot de passe erroné avec ralentissement | Connexion | Message générique dans le bloc `role="alert"` `tabindex="-1"` au-dessus du formulaire, qui reçoit le focus au rendu 422 (aucun champ n'est en erreur). À partir du cinquième échec `[ASSUMPTION PRD]`, le message indique le délai à attendre ; le bouton reste actif et le serveur refuse pendant le délai ; chaque nouvelle soumission réaffiche le message avec les secondes restantes — pas de compte à rebours JS. Délai exempté de WCAG 2.2.1 (sécurité). Jamais de blocage définitif affiché. |
| Compte désactivé | Connexion | Message dédié dans le même bloc `role="alert"`, sans révéler si le mot de passe était bon. |
| Lien de réinitialisation expiré ou déjà utilisé | Réinitialisation | Message (Voice and Tone) + bouton « Demander un nouveau lien ». |
| Code 2FA incorrect | Vérification 2FA, Enrôlement 2FA | 422, erreur sous le champ de code (`aria-invalid` + `aria-describedby`), focus sur le champ, valeur effacée. Même ralentissement que le mot de passe à partir du cinquième échec. |
| Code par email expiré | Vérification 2FA | 422, message + bouton « Renvoyer un code » ; le renvoi invalide l'ancien code et réaffiche la page avec le Flash « Un nouveau code vient d'être envoyé. » Validité 10 minutes `[ASSUMPTION]`. |
| Code de secours utilisé | Vérification 2FA → page suivante | Connexion réussie ; Flash `role="status"` avec le nombre de codes restants. Dernier code : `{components.alert}` persistante sur toutes les pages jusqu'à génération de nouveaux codes (Profil › Double authentification). |
| Plus aucun code de secours et pas d'application | Code de secours | Message Voice and Tone, aucun formulaire ; la réinitialisation passe par un administrateur (Fiche utilisateur › « Réinitialiser la 2FA »). |
| Échec de vérification à l'enrôlement | Enrôlement 2FA | 422 sur l'étape de vérification, QR code et secret toujours affichés (même secret), erreur sous le champ ; les codes de secours ne sont générés qu'après une vérification réussie. |

### Comptes

| État | Surface | Traitement |
|---|---|---|
| Compte désactivé (vu par un admin) | Utilisateurs, Fiche, Journal | `{components.badge-deactivated}` « Compte désactivé » à côté du nom ; la Fiche propose « Réactiver » et « Anonymiser » ; le journal garde le nom. |
| Compte anonymisé | Fiche, Journal | Nom remplacé par « Utilisateur anonymisé #n », email vide, aucune action sauf consultation. |

### Délai de grâce 2FA

| État | Surface | Traitement |
|---|---|---|
| Délai de grâce 2FA | toutes (Super admin / Admin) | `{components.banner}` avec jours restants. `[ASSUMPTION]` Délai de 7 jours après la première connexion, blocage ensuite — valeur à confirmer avec le pilote. |
| Délai de grâce expiré | toutes | Redirection vers Enrôlement 2FA avec `{components.alert}` « Pour continuer, activez… » ; seuls Profil et Déconnexion restent accessibles. |
| Nouveau rôle exigeant la 2FA | connexion suivante | Même mécanisme : Banner et délai courent à partir de cette connexion. |

### Roadmap dev

| État | Surface | Traitement |
|---|---|---|
| Bouton « Lancer » inactif | Roadmap (dev) | Inactif selon la règle Button (`aria-disabled`, focusable) ; raison « En attente de : <nom de la tâche> (<statut>) » reliée par `aria-describedby` (Voice and Tone › Bouton Lancer inactif). Tâche en cours : pas de bouton. |
| Lancement échoué | Roadmap (dev) | `{components.alert-destructive}` en Flash, avec la raison (Voice and Tone › Lancement échoué : commande introuvable, processus non démarré). |

### Session & erreurs

| État | Surface | Traitement |
|---|---|---|
| Erreur de champ | tous les formulaires | Bordure `{colors.destructive}` + icône + texte sous le champ (règle 1), focus sur le premier champ en erreur. |
| Roadmap partielle (FR-14) | Roadmap | `{components.alert}` `role="region"` (règle Alert) visible des administrateurs seulement, nommant le fichier et la ligne ; l'epic reste à sa place en `{components.badge-unreadable}`, avancement « — », sans chiffre inventé ; les autres utilisateurs voient la roadmap sans cette epic et sans message. Un statut inconnu sur une tâche → « À faire » + même Alert. |
| Sans permission | zone protégée | 403 traduit avec retour à la roadmap ; l'entrée n'était de toute façon pas dans la Sidebar. Objet précis non permis → 404, identique à un objet inexistant. |
| Session close (désactivation, changement de mot de passe) | toutes | Prochaine requête → Connexion avec le Flash « Vous avez été déconnecté. » |
| Session expirée par inactivité | toutes | `[ASSUMPTION]` Session glissante de 24 heures (au-delà des 20 h qui exemptent de WCAG 2.2.1, donc aucun avertissement de délai à afficher), à confirmer. GET après expiration → Connexion, puis retour sur l'URL demandée. POST après expiration → Connexion avec le Flash « Votre session avait expiré… » (Voice and Tone), puis retour sur le formulaire vide. |
| Erreur inattendue (500, réponse absente) | toutes | Page « Erreur inattendue » (template d'erreur Symfony, même gabarit que 403) ; l'entrée est journalisée côté serveur, rien de technique n'est montré. Si Turbo n'obtient aucune réponse (réseau), il retombe sur une navigation complète : même page. |

### Réglages

| État | Surface | Traitement |
|---|---|---|
| Profil : désactiver sa 2FA quand le rôle l'exige | Profil › Double authentification | Bouton « Désactiver » non rendu ; note `{typography.meta}` « Votre rôle exige la double authentification. » Reste possible : régénérer les codes de secours, changer de méthode. |
| Rôles : rôle par défaut, rôle utilisé | Rôles, Fiche rôle | Les rôles du socle (Super admin, Admin, User) n'ont pas de bouton « Supprimer » et une note l'explique ; supprimer un rôle créé et utilisé par n utilisateurs ouvre un Dialog nommant n et le rôle de repli (User). Enregistrement des cases : bouton « Enregistrer » explicite, POST, Flash. |
| Langue désactivée pendant la session | toutes | Prochaine requête rendue dans la langue de repli ; Flash « Le néerlandais n'est plus disponible… » (Voice and Tone). |
| Thème système sans préférence | toutes | `prefers-color-scheme` ; le DropdownMenu montre « Système » coché. |

## Interaction Primitives

- **Clavier d'abord, sans raccourcis inventés.** Premier élément du DOM : un lien
  « Aller au contenu » (`href="#contenu"`, `sr-only` sauf au focus) vers
  `<main id="contenu" tabindex="-1">` ; repères : `<nav aria-label="Navigation
  principale">`, `<header>`, `<main>`, `<footer>`. Tab parcourt ensuite tout dans l'ordre
  de lecture : Sidebar, en-tête, `h1`, contenu. Entrée active liens et boutons, Espace
  bascule Accordion et cases ; flèches dans DropdownMenu et groupes radio (les Tabs du
  Profil sont des liens). Aucun raccourci global (pas de palette de commandes) : le socle
  est un outil simple, pas un outil de puissance.
- **Échap** ferme toujours l'élément flottant le plus haut — Sheet, Dialog, DropdownMenu,
  tiroir de navigation — et rend le focus à son déclencheur.
- **Entrée soumet** un formulaire depuis n'importe quel champ ; la désactivation du
  bouton pendant l'envoi est dite en Component Patterns › Champ de formulaire.
- **Focus** : `{components.focus-ring}` sur tout élément interactif, `:focus-visible`
  (règle 4). Après fermeture d'un Sheet ou Dialog, au déclencheur (le kit le fait).
  Après une navigation Turbo (lien, redirection après POST, rendu 422), le contrôleur
  `page-focus` place le focus sur le bloc d'erreur, le champ en erreur, le Flash ou le
  `h1` — mécanisme et ordre en Accessibility Floor › Sous Turbo Drive.
- **Pointeur.** Clic pour agir ; le survol n'ajoute que `{colors.accent}` sur une ligne
  ou une entrée de menu, jamais une action (règle 7). Pas de glisser-déposer, pas de
  clic droit, pas de double clic.
- **Tactile.** Cibles ≥ `{spacing.touch-target-min}` sur pointeur grossier
  (`@media (pointer: coarse)`) : boutons y compris `size="icon"` (fermer, hamburger,
  fermer un Flash), champs, lignes de tableau, entrées de menu et de Sidebar (leur
  padding s'y agrandit). Une ligne de tableau répond à l'appui sur toute sa surface. Pas
  de geste de balayage.
- **Une seule couche flottante** à la fois : un Dialog ne s'ouvre jamais au-dessus d'un
  Sheet ; les actions destructives vivent sur la Fiche, pas dans un panneau.
- **Interdits partout** : défilement infini, actions révélées au survol, disparition
  automatique d'un message, animation décorative, texte en capitales forcées.

## Accessibility Floor

WCAG 2.1 AA sur toute la surface web, par les sept règles de
`symfony-proglab-accessibility`, appliquées ainsi :

1. **La couleur seule ne porte jamais une information.** Statuts = icône + libellé +
   forme de bordure ; erreurs de champ = bordure + icône + texte ; origine d'une
   permission = Badge libellé. Test : la page en niveaux de gris ne perd rien.
2. **Contraste** ≥ 4,5:1 sur tout texte, ≥ 3:1 sur les composants ; ratios de référence
   dans `DESIGN.md · Colors`, remesurés après tout rebranding. Le seul écart connu du
   thème de départ est l'anneau de focus clair, noté dans `DESIGN.md`.
3. **Un vrai label** sur chaque champ, y compris le champ de code 2FA, les deux dates
   de la période du journal (« Du » / « Au » sous un `<legend>`) et le champ d'objet du
   journal (`sr-only` si la maquette le cache) ; les groupes de liens de langue et de
   radios portent leur nom (`aria-label` / `<legend>`). Les jetons `autocomplete`
   (WCAG 1.3.5) sont listés dans Component Patterns › Champ de formulaire.
4. **Focus visible** partout, `:focus-visible`, jamais `outline: none` sans
   remplacement — y compris sur les lignes de tableau cliquables et le résumé
   d'Accordion.
5. **Alternatives textuelles** : icônes décoratives `aria-hidden`, boutons icône-seule
   (fermer, hamburger, menu) avec `aria-label`, logo du dérivé avec `alt` = nom du
   dérivé.
6. **Un seul `h1`** par page, porté par le template de page ; cartes d'epic et sections
   de fiche en `h2`, Sheet et Dialog en `h2` (ils sont modaux, leur titre est le titre du
   contexte), Alert, Flash et groupes de permissions en `h3` — la même règle est dans
   `DESIGN.md · Typography`.
7. **Rien derrière le survol seul** : toute action a un bouton ou un lien atteignable au
   clavier et au doigt.

En plus des sept règles :

- **Mouvement réduit.** `prefers-reduced-motion` ramène toutes les transitions à 0.
- **En-têtes de colonne.** Le tableau avant / après a des en-têtes de colonne.
- **Limite de temps.** Aucune limite de temps n'est imposée hors la session de 24 h
  (State Patterns › Session expirée par inactivité).
- **Langue de la page.** L'attribut `lang` de la page est déclaré et suit la langue
  d'interface.
- **Modaux, Progress, 320 px.** Sheet et Dialog sont `aria-modal` avec focus piégé
  (Component Patterns) ; la Progress est décorative, sa phrase porte la valeur
  (Component Patterns › Progress) ; la réorganisation jusqu'à 320 px CSS sans
  défilement horizontal (WCAG 1.4.10) est dite en Responsive & Platform.

### Sous Turbo Drive

Sous Turbo Drive, une navigation ne recharge pas la page : ni le titre, ni le `h1`, ni
un message présent au rendu ne sont annoncés d'eux-mêmes au lecteur d'écran. Le socle y
répond ainsi :

- **Annonces.** Le layout porte une région live persistante, vide au rendu :
  `<div id="annonces" aria-live="polite" class="sr-only" data-turbo-permanent>` (Turbo
  la conserve d'une navigation à l'autre au lieu de la recréer). Un Flash ou un bloc
  d'erreur générique n'est jamais lui-même la région live : un contrôleur Stimulus
  `announce` posé sur lui copie son texte dans `#annonces` à `connect()` (un instant
  après l'insertion, pour que le changement soit perçu) ; pour une erreur, la région
  passe en `aria-live="assertive"`. Le Flash garde `role="status"` / `role="alert"` pour
  sa sémantique, mais c'est la région qui annonce.
- **Titre.** Chaque page rend son `<title>` « <page> — <nom du dérivé> » (par exemple
  « Utilisateurs — Menuiserie Dubois » ; WCAG 2.4.2) ; Turbo fusionne le `<head>` et le
  met à jour.
- **Focus.** Le socle le prend en charge lui-même. Un contrôleur Stimulus `page-focus`
  posé sur `<main>` — son `connect()` s'exécute à chaque remplacement de body, pas
  seulement au premier chargement (`symfony-proglab-frontend`) — place le focus sur le
  premier élément présent parmi :
  1. le bloc d'erreur générique `role="alert"` ;
  2. le premier champ `aria-invalid` ;
  3. le Flash (`tabindex="-1"`) ;
  4. le `h1` (`tabindex="-1"`).

  Il ignore le tout premier chargement de la session (le navigateur y place déjà le
  focus en haut du document). C'est ce déplacement qui fait lire le nouveau contexte au
  lecteur d'écran.

## Key Flows

Noms des UJ verbatim du PRD §2.2. Chaque flux a un point culminant et se termine par
son cas limite (celui du PRD pour UJ-1 à UJ-5) ; les flux 6 à 9 sont ajoutés par ce
spine.

### UJ-1. Fabrice démarre l'ERP d'un nouveau client

1. Fabrice clone le socle, lance la commande d'initialisation dans son terminal (FR-1,
   hors interface) et saisit ses identifiants de premier Super admin.
2. Il ouvre l'application sur Laragon : Connexion, lien de langue « Français » actif,
   email + mot de passe.
3. Son rôle exige la 2FA : Enrôlement 2FA s'affiche avant tout autre écran — il choisit
   l'application TOTP, scanne, saisit le code, télécharge ses codes de secours.
4. Il arrive sur la Roadmap : « Bonjour Fabrice », sous-ligne de la Sidebar « ERP ·
   développement », état vide « Votre roadmap est vide pour l'instant… » avec la ligne
   dev indiquant où les fichiers BMAD sont attendus.
5. Sidebar › Utilisateurs : « Vous êtes seul pour l'instant. Invitez un premier
   collègue. » Il clique sur « Inviter un utilisateur », saisit l'email de Sophie, rôle
   Admin, envoie.
6. **Point culminant :** redirection vers Utilisateurs, Flash « Invitation envoyée à
   sophie@… Elle est valable 7 jours. », ligne de Sophie en État « Invité ». Un ERP
   fonctionnel, rebrandable, avec son premier administrateur en route — avant midi.
   L'après-midi, il écrit la première entité métier.

Cas limite : si la base est déjà peuplée, la commande refuse sans option de
confirmation ; c'est un message de terminal, l'interface n'est pas concernée.

### UJ-2. Sophie, responsable administrative de la PME, fait entrer un collègue

1. Sophie ouvre Utilisateurs, clique sur « Inviter un utilisateur », saisit Karim, rôle
   User, envoie. Flash de confirmation ; Karim apparaît « Invité ».
2. Karim accepte (même mécanisme que le flux 6, où c'est Sophie qui accepte) ; sa
   ligne passe en État « Actif ».
3. Sophie ouvre la Fiche de Karim. Section Permissions : une Table de toutes les
   permissions existantes, chacune avec son état effectif et un Badge d'origine —
   toutes « Héritée du rôle » pour l'instant.
4. Sur la ligne « Consulter le journal d'audit » (non accordée à User), elle clique sur
   « Ajouter ». POST, redirection, Flash « Permission ajoutée pour Karim Benali. »
5. **Point culminant :** la ligne affiche maintenant l'état « Accordée » et le Badge
   « Ajoutée » ; à côté, un bouton « Revenir à l'héritage ». Sophie sait exactement ce
   que Karim peut faire et pourquoi, sans lire un rôle. Karim, à sa prochaine page,
   voit « Journal d'audit » apparaître dans sa Sidebar.

Cas limite : Karim n'a pas cliqué dans les 7 jours. Sur sa Fiche, l'État passe à
« Invitation expirée » et le bouton « Renvoyer l'invitation » ouvre un Dialog court
(« Renvoyer l'invitation à Karim ? … », Voice and Tone). À la confirmation, Flash
« Invitation renvoyée. » ; l'ancien lien affiche l'état « Invitation expirée ».

### UJ-3. Marc, gérant, regarde où en est son ERP

1. Vendredi soir, Marc se connecte (email, mot de passe, code TOTP — il est Admin).
2. Roadmap : « Bonjour Marc. Voici où en est votre ERP. » et la pastille « Dernier
   déploiement : mardi 8 septembre 2026 à 18 h 42 ».
3. Quatre cartes d'epic : « Catalogue produits » Terminé 100 %, « Gestion des devis »
   En cours 60 % — dépliée par défaut —, « Suivi des livraisons » En cours 22 %,
   « Facturation et relances » À faire 0 % « En attente de : Gestion des devis ».
4. Dans « Gestion des devis », il lit la liste : six tâches Terminé, « Validation du
   devis par le client (signature) » En cours (Karim Benali · priorité haute ·
   complexe), puis « Transformer un devis validé en commande » À faire avec la pastille
   « En attente de : Validation du devis par le client (en cours) ».
5. **Point culminant :** Marc comprend en cinq secondes que le devis est à 60 %, que la
   prochaine étape attend la signature client, et qui s'en occupe — sans avoir jamais
   entendu le mot BMAD. Il n'a ni bouton « Lancer » ni notes internes : en production,
   ils n'existent pas.

Cas limite : la pastille de dernier déploiement et la note de pied « Cette page reflète
le dernier déploiement, pas le temps réel. » évitent qu'il prenne la vue pour du direct.
Variante FR-14 : si un fichier est mal formé, Marc (Admin) voit l'Alert « Roadmap
partielle » et l'epic en « Non lisible » ; un User ne voit ni l'un ni l'autre.

### UJ-4. Sophie cherche qui a modifié un tarif

1. Sidebar › Journal d'audit. Filtres : type d'objet « Tarif », période « 7 derniers
   jours », bouton « Filtrer ». L'URL porte les filtres.
2. La Table montre trois entrées ; celle de mercredi 14 h 12 : Karim Benali ·
   Modification · Tarif « Chêne massif 40 mm ».
3. Elle clique sur la ligne : le Sheet s'ouvre à droite, la Table reste derrière.
   Avant / après : « prix unitaire » 42,00 € → 45,50 €.
4. Juste au-dessus dans la liste, une action métier : « Karim a validé le devis
   D-2026-041 » — elle l'ouvre aussi, referme avec Échap, le focus revient à la ligne.
5. **Point culminant :** elle clique sur « Exporter la sélection » : un CSV des trois
   entrées filtrées se télécharge, prêt pour le comptable. La question « qui a changé
   ça ? » a pris moins d'une minute et n'a pas eu besoin de Fabrice.

Cas limite : Karim a été désactivé entre-temps. Son nom reste sur les entrées, suivi de
`{components.badge-deactivated}` « Compte désactivé », dans la Table comme dans le Sheet
et dans l'export.

### UJ-5. Léa, développeuse, prend la prochaine tâche

1. Léa ouvre le dérivé sur son poste ; Sidebar « ERP · développement ». Roadmap, epic
   « Gestion des devis » dépliée.
2. « Relancer automatiquement un devis sans réponse » porte `{components.badge-ready}`
   « Tâche prête » et un bouton « Lancer » actif ; « Transformer un devis validé en
   commande » a son bouton inactif avec « En attente de : Validation du devis par le
   client (en cours) ».
3. Elle clique sur « Lancer ». POST, redirection, Flash « Tâche lancée. Les agents
   travaillent sur votre poste ; rechargez la page pour voir l'avancement. »
4. Les agents passent la tâche « En cours » et l'assignent à Léa dans les fichiers BMAD ;
   l'application n'écrit rien.
5. **Point culminant :** au rechargement, la tâche est « En cours · Léa Martin », son
   bouton a disparu (une tâche en cours ne se relance pas), et l'avancement de l'epic
   n'a pas bougé — il ne bougera qu'à « Terminé ». Léa sait ce qu'elle fait, la
   roadmap le sait aussi, et personne n'a ouvert un fichier YAML.

Cas limite : si la dépendance n'est pas terminée, le bouton est inactif selon la règle
Button (Component Patterns), avec la raison nommée (étape 2). Si le lancement échoue,
`{components.alert-destructive}` « Le lancement a échoué : commande introuvable ».

### Flux 6. Sophie accepte son invitation et enrôle sa 2FA dans le délai de grâce

1. Sophie clique sur le lien de l'email (reçu en français, langue par défaut du dérivé).
   Acceptation d'invitation : son prénom et son rôle sont rappelés, deux champs de mot
   de passe, bouton « Créer mon compte ».
2. Compte actif, session ouverte, entrée d'audit « invitation acceptée ». Redirection
   vers la Roadmap : Flash « Bienvenue Sophie ! Votre compte est prêt. »
3. Sous l'en-tête, `{components.banner}` : « Sophie, votre rôle demande la double
   authentification. Il vous reste 7 jours pour l'activer. Activer maintenant ».
   `[ASSUMPTION]` 7 jours, à confirmer.
4. Elle explore d'abord — Utilisateurs, Rôles — le Banner reste sur chaque page.
5. Le lendemain elle clique sur « Activer maintenant » : Enrôlement 2FA, choix
   « Application d'authentification » ou « Code par email », vérification dans un seul
   champ de code (Component Patterns › Champ de code 2FA), codes de secours dans une
   Alert avec bouton « Télécharger ».
6. **Point culminant :** redirection Profil › Double authentification, Flash « Double
   authentification activée. », le Banner a disparu. Elle n'a jamais été bloquée, et
   elle n'a jamais pu oublier.

Cas limite : le huitième jour sans 2FA, toute requête hors Profil et Déconnexion
redirige vers Enrôlement 2FA avec l'Alert « Pour continuer, activez votre double
authentification. »

### Flux 7. Karim réinitialise son mot de passe

1. Connexion › « Mot de passe oublié ? ». Champ email, bouton « Envoyer le lien ».
2. Quel que soit l'email, même écran : « Si un compte existe pour cette adresse, un
   lien vient d'être envoyé… » (Voice and Tone).
3. Karim ouvre le lien : Réinitialisation, deux champs, bouton « Changer mon mot de
   passe ».
4. **Point culminant :** Flash sur Connexion « Mot de passe changé. Connectez-vous avec
   le nouveau. » ; ses autres sessions sont closes ; entrée d'audit « mot de passe
   réinitialisé ».

Cas limite : si le lien a expiré ou a déjà servi, la page affiche « Ce lien a expiré.
Demandez-en un nouveau. » avec le bouton.

### Flux 8. Sophie désactive le néerlandais

1. Sidebar › Langues : trois lignes — Français (par défaut, actif), Anglais (actif),
   Néerlandais (actif, 2 utilisateurs).
2. Le bouton « Désactiver » sur Néerlandais ouvre le Dialog « Désactiver le
   néerlandais ? … » (Voice and Tone), qui nomme les 2 utilisateurs concernés et le
   passage en français. Annuler / Désactiver.
3. **Point culminant :** Flash « Néerlandais désactivé. » ; la ligne montre « Inactif »
   et « Réactiver » ; entrée d'audit « langue désactivée ». Les deux utilisateurs
   concernés voient leur prochaine page en français avec le Flash « Le néerlandais n'est
   plus disponible… » (Voice and Tone). Repli `[ASSUMPTION PRD]` : première langue
   active dans l'ordre français, anglais, néerlandais.

Cas limite : la dernière langue active n'a pas de bouton « Désactiver » ; une note
`{typography.meta}` l'explique.

### Flux 9. Léa passe en mode sombre

1. Bouton compte de l'en-tête › DropdownMenu › groupe Thème : « Clair », « Sombre »,
   « Système » (coché).
2. Elle choisit « Sombre ».
3. **Point culminant :** la page bascule immédiatement (classe `dark` posée par
   Stimulus), sans clignotement, et le choix est persisté sur son profil par un POST ;
   sur son autre poste, l'application s'ouvre déjà en sombre. Les statuts restent
   lisibles : icône + libellé, `{colors.status-done-dark}` sur les cartes.

Cas limite : sans préférence mémorisée, « Système » est coché et `prefers-color-scheme`
décide seul (State Patterns › Thème système sans préférence).

## Responsive & Platform

Desktop d'abord dans l'usage, plus petite largeur d'abord dans la construction.

| Largeur | Comportement |
|---|---|
| ≥ `lg` (1024 px) | Sidebar fixe ; en-tête avec bouton compte ; contenu à `{spacing.content-max}` (tableaux à `{spacing.content-max-table}`) avec gouttières `{spacing.gutter-desktop}`. Sheet d'audit à 480 px. |
| `md` – `lg` (768–1023 px) | Sidebar devient un tiroir (Sheet à gauche), hamburger dans l'en-tête ; colonnes complètes des tableaux ; grille d'epic intacte. |
| < `md` | Gouttières `{spacing.gutter-mobile}` ; tableaux réduits aux colonnes prioritaires, détail d'un appui sur la ligne ; résumé d'epic empilé (titre, puis avancement, chevron à droite) ; badge de tâche (et bouton « Lancer » en dev) sous les méta ; Sheet et Dialog en plein écran ; Tabs du Profil défilables horizontalement ; cibles ≥ `{spacing.touch-target-min}`. |
| 320 px (zoom 400 %) | Même mise en page que < `md`, sans défilement horizontal du body (WCAG 1.4.10 — largeurs fluides, pas de hauteur fixe sur le contenu) : seules les Tables et les blocs `{typography.mono}` défilent dans leur propre conteneur. |

Navigateurs : versions courantes de Chrome, Edge, Firefox, Safari (desktop et iOS).
Sans JavaScript, tout fonctionne en pleine page : Accordion en `<details>`, Sheet et
Dialog rendus comme pages complètes (`/journal-audit/:id`), formulaires en POST +
redirection — Turbo et Stimulus n'ajoutent que le confort. Impression : le journal
filtré et la roadmap s'impriment sans la Sidebar ni l'en-tête.

## Permissions et visibilité

- **Navigation dérivée des permissions effectives** (rôle + surcharges), calculées côté
  serveur à chaque requête : Information Architecture › Navigation et Component
  Patterns › Sidebar. Aucune règle métier en JavaScript (`symfony-proglab-frontend`).
- Un **bouton absent n'est pas une protection** : chaque route vérifie la permission
  (FR-10) ; 403 pour une zone, 404 pour un objet précis.
- **Notes internes** (contenu d'une tâche — story BMAD — au-delà du titre et du résumé,
  `[ASSUMPTION PRD]`) : rendues dans la ligne de tâche, sous les méta, seulement aux
  utilisateurs ayant la permission dédiée (`[ASSUMPTION PRD]` Super admin seul).
  Repliées par défaut derrière un bouton « Notes internes » (Accordion imbriqué) pour
  ne pas alourdir la lecture.
- **Avertissement roadmap partielle** réservé aux administrateurs : State Patterns ›
  Roadmap partielle (FR-14).
- Un utilisateur **ne peut jamais se désactiver, s'anonymiser ni changer son propre
  rôle** depuis sa Fiche : les boutons « Désactiver », « Anonymiser » et le sélecteur de
  rôle ne sont pas rendus sur sa propre fiche ; seul un autre administrateur peut agir.
  Décision de ce spine (le PRD ne le dit pas), confirmée par le pilote.

## Environnement de développement vs production

| Élément | Développement | Production |
|---|---|---|
| Sous-ligne de la Sidebar | « ERP · développement » | « ERP · production » |
| Bouton « Lancer » | rendu sur les tâches prêtes (Super admin) | inexistant — ni bouton, ni route |
| Ligne d'aide sous l'état vide de la roadmap | chemin attendu des fichiers BMAD | absente |
| Alert roadmap partielle | administrateurs | administrateurs (mêmes règles) |
| Date du dernier déploiement | affichée (peut lire « aujourd'hui ») | affichée |

Rien d'autre ne diffère : même thème, mêmes écrans, mêmes textes. Un développeur qui
voit un écran en dev voit ce que le client verra.

## Internationalisation

- **Trois endroits pour choisir sa langue** (Connexion, Profil › Langue, menu compte —
  FR-19) : règles en Component Patterns › Liens de langue et DropdownMenu ; le réglage
  du Profil s'applique aussi aux emails (FR-21) ; seules les langues actives
  apparaissent (FR-22).
- **Le français est la source** (Voice and Tone) ; un dérivé qui ajoute un module suit
  le même mécanisme.
- **Dates et nombres** dans la locale de l'utilisateur (« mardi 8 septembre 2026 à
  18 h 42 », « 45,50 € ») ; les valeurs brutes du journal d'audit sont formatées
  lisiblement, jamais montrées comme identifiants internes (FR-13).
- **Longueur des textes** : le néerlandais et l'anglais peuvent être plus longs ou plus
  courts ; aucun bouton ni Badge n'a de largeur fixe.
- **Les données saisies** (noms d'epic, valeurs métier) ne sont pas traduites.
- **Attribut `lang`** de la page : Accessibility Floor › Langue de la page.

## Inspiration & Anti-patterns

- **Directions visuelles** : Aéré retenue, Compact et Éditorial rejetées — la décision
  de registre et ses motifs sont dans `DESIGN.md · Brand & Style` ; la maquette de
  référence est listée en Information Architecture › Écrans maquettés.
- **Rejeté — palette de commandes, raccourcis vim, kanban** : le socle est un outil
  simple pour des utilisateurs occasionnels ; la puissance viendra des modules métier
  des dérivés, pas du socle.
- **Rejeté — toasts flottants qui disparaissent** : un message qu'on n'a pas eu le temps
  de lire n'existe pas ; les Flash restent jusqu'à fermeture.
- **Rejeté — réglages de marque en administration** : la marque est du code dans le
  dérivé (`DESIGN.md · Brand & Style`).

## Questions ouvertes

- **OQ-UX-1** — Durée du délai de grâce 2FA (7 jours en `[ASSUMPTION]`) : à fixer.
- **OQ-UX-3** — Jetons d'API (Profil) : le socle affiche-t-il le jeton une seule fois à
  la création (pratique usuelle) et quels champs porte la liste (nom, création,
  expiration, dernière utilisation) ? Le PRD ne détaille pas l'écran ; ce spine ne
  l'invente pas au-delà de « créer » et « révoquer » (Dialog).
- ~~**OQ-UX-4**~~ — *Close le 2026-09-10.* Chemins d'URL fixés en anglais et
  invariables par AD-6, qui en porte la table complète ; le `?lang=` de la page de
  connexion est conservé.
- **OQ-UX-5** — Contenu exact d'une entrée d'audit d'action métier dans le Sheet
  (l'objet concerné est-il un lien vers sa fiche métier ?) : dépend des modules des
  dérivés ; le socle affiche libellé, auteur, horodatage, objet.

Hypothèses à confirmer — chaque `[ASSUMPTION]` (posée par ce spine) et
`[ASSUMPTION PRD]` (lue entre les lignes du PRD) du document, avec sa section :

- `[ASSUMPTION]` « Mobile first » = web responsive conçu d'abord pour l'écran de
  téléphone, le PRD excluant une application native — Foundation › Form-factor.
- `[ASSUMPTION]` Le brief et le PRD du 2026-09-09 sont les seules sources amont —
  Foundation › Sources.
- `[ASSUMPTION PRD]` Lien de réinitialisation du mot de passe à usage unique, valable
  une heure — Information Architecture › Surfaces (Mot de passe oublié).
- `[ASSUMPTION PRD]` Second facteur : TOTP ou code par email — Information
  Architecture › Surfaces (Vérification 2FA).
- `[ASSUMPTION PRD]` L'export CSV du journal reprend exactement les filtres courants —
  Component Patterns › Filtres du journal.
- `[ASSUMPTION]` Code reçu par email valable 10 minutes — Component Patterns › Champ de
  code 2FA et State Patterns › Code par email expiré.
- `[ASSUMPTION PRD]` Ralentissement à partir du cinquième échec (mot de passe et code
  2FA) — State Patterns › Mot de passe erroné avec ralentissement.
- `[ASSUMPTION]` Délai de grâce 2FA de 7 jours après la première connexion, blocage
  ensuite (OQ-UX-1) — State Patterns › Délai de grâce 2FA et Key Flows › Flux 6.
- `[ASSUMPTION]` Session glissante de 24 heures — State Patterns › Session expirée par
  inactivité.
- `[ASSUMPTION PRD]` Langue de repli = première langue active dans l'ordre français,
  anglais, néerlandais — Key Flows › Flux 8.
- `[ASSUMPTION PRD]` Notes internes = contenu d'une tâche (story BMAD) au-delà du titre
  et du résumé, visibles du Super admin seul — Permissions et visibilité.
