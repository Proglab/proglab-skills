# Epic 1 Context: Cloner un socle et obtenir un ERP qui répond

<!-- Compilé depuis les artefacts de planification. Éditable librement. Régénérer avec compile-epic-context si les documents de planification changent. -->

## Objectif

Cet epic amorce le dépôt du socle ERP et le rend utilisable de bout en bout : un développeur clone le projet, lance une commande d'initialisation, ouvre l'application et s'y connecte dans sa langue. Il ne s'agit pas d'adopter un starter template tiers — le socle démarre d'un squelette Symfony nu auquel cet epic ajoute lui-même sa structure à deux racines, son outillage de qualité, sa chaîne d'intégration continue et son enveloppe de déploiement. Au-delà des quatre capacités visibles (initialisation en une commande, guide de dérivation, connexion avec réinitialisation du mot de passe, interface trilingue), l'epic porte tout le socle transverse dont les cinq epics suivantes dépendent : thème et kit de composants, gabarit de base et plancher d'accessibilité, envoi d'emails hors requête, entité à jeton partagée, limitation de débit et CSRF, pages d'erreur, amélioration progressive. C'est l'epic qui rend les autres possibles.

## Stories

- Story 1.1 : Amorcer le dépôt et la frontière des deux racines
- Story 1.2 : Bloquer les régressions en intégration continue
- Story 1.3 : Installer le kit de composants et poser le thème du socle
- Story 1.4 : Poser le gabarit de base et le plancher d'accessibilité
- Story 1.5 : Servir l'interface en français, anglais et néerlandais
- Story 1.6 : Se connecter avec son email et son mot de passe
- Story 1.7 : Ralentir les tentatives de connexion répétées
- Story 1.8 : Sortir l'envoi des emails de la requête
- Story 1.9 : Réinitialiser un mot de passe oublié
- Story 1.10 : Rendre les pages 403, 404 et 500 lisibles
- Story 1.11 : Initialiser un dérivé en une commande
- Story 1.12 : Écrire le guide de dérivation
- Story 1.13 : Rendre la déconnexion joignable sans jeton

## Exigences et contraintes

- **Initialisation** : une seule commande prépare la base, applique les migrations, charge les rôles par défaut et crée le premier Super admin ; elle refuse d'agir sur une base peuplée sans confirmation explicite.
- **Connexion** : message générique unique sur identifiants faux, qui ne révèle jamais l'existence d'un compte ; message dédié pour un compte désactivé, sans dire si le mot de passe était bon ; ralentissement progressif des échecs répétés affichant les secondes restantes ; aucun blocage définitif. Réinitialisation par lien email à usage unique et expirable, avec réponse identique que le compte existe ou non.
- **Trois langues** : tout texte d'interface, message d'erreur et email du socle en français, anglais et néerlandais, le français étant la langue source. Les langues supportées sont en code, les langues **actives** en base — jamais en variable d'environnement. La langue du compte est mémorisée et appliquée à ses emails.
- **Dérivabilité** : rien ne suppose un client particulier ; un dérivé n'édite aucun fichier de `src/Core/` ni de `config/`. Le rebranding se limite aux variables de couleur du thème et au logo.
- **Accessibilité** : plancher WCAG 2.1 AA — couleur jamais seule, contraste 4,5:1 sur le texte et 3:1 sur les composants, labels réels, focus visible, alternatives textuelles, une seule `h1`, rien derrière le survol seul — porté par un gabarit unique et **vérifié par un test automatisé en CI, jamais par relecture**.
- **Sécurité** : mots de passe hachés selon l'état de l'art ; CSRF obligatoire sur toute écriture, **avec une seule exception nommée, la déconnexion** ; aucune donnée sensible dans les journaux applicatifs.
- **Performance** : la connexion répond en moins d'une seconde au p95, en développement comme en production.
- **Qualité** : test écrit d'abord et vu rouge ; chaque exigence fonctionnelle couverte par au moins un test fonctionnel ; base de données de test identique à celle de production, jamais SQLite.
- **Couture assumée** : le critère « le premier Super admin voit la roadmap » ne se referme qu'à l'Epic 3. L'Epic 1 livre la connexion et une page d'accueil ; l'Epic 3 en fait la roadmap. La fonction est complète, seul le critère se termine plus tard.
- **Audit** : aucune écriture d'audit n'est câblée ici. L'Epic 4 raccordera rétroactivement les actions livrées par cet epic.

## Décisions techniques

- **Socle** : Symfony 7.4 LTS sur PHP 8.5, Doctrine ORM 3.7 / DBAL 4.4. Aucune dépendance exigeant Symfony 8 n'entre dans le socle.
- **Deux racines** : `src/Core/` porte le socle, `src/Module/<Nom>/` le métier du client, avec les mêmes dossiers de couche des deux côtés. `Core/Contract/` — interfaces et DTO seulement, sans Doctrine ni HTTP — est la seule porte publique. Le câblage framework (mapping Doctrine, routes, chemins du translator, découverte des services) est fixé une fois en configuration par glob sur `src/Module/*/`, de sorte qu'ajouter un module ne modifie aucun fichier existant.
- **Frontière mécanisée** : deptrac vérifie quatre règles bloquantes en CI — couches proglab dans chaque racine, `Core → Module` interdit, `Module → Module` interdit, `Module → Core` interdit hors `Core\Contract`.
- **CI livrée avec le socle**, bloquante sur la même liste que la tâche de qualité locale : analyse statique, style, deptrac, tests, accessibilité, audit des dépendances.
- **Emails** : tout email passe par Messenger sur transport Doctrine, avec retry et file d'échec. Un message porte des scalaires et une locale explicite, jamais une entité ni une dépendance au contexte de requête, et n'est consommable qu'après le commit. Worker livré en service systemd piloté par le déploiement ; Mailpit capte les envois en développement.
- **Jetons d'invitation et de réinitialisation** : une entité à état partagée — destinataire, rôle prévu, jeton aléatoire stocké haché, date d'expiration, date d'usage. L'URL transporte le jeton en clair, la base n'en garde que l'empreinte. Aucune URL signée sans état.
- **Sessions** : le compte porte un jeton de sécurité incrémenté à la désactivation, au changement de mot de passe et à la réinitialisation du second facteur ; la comparaison d'utilisateur à chaque requête déauthentifie les sessions concernées. Sessions en fichiers, aucun second magasin.
- **Limitation de débit** : `login_throttling` natif adossé au rate-limiter, jamais un compteur écrit à la main, avec un limiteur nommé distinct pour la demande de réinitialisation. Les secondes restantes sont lues sur le limiteur, jamais déduites d'un message.
- **Déconnexion sans jeton** : `/logout` aboutit sans aucun paramètre — depuis un favori, un lien recopié ou une page servie par un cache — et ne répond jamais 403. L'exception à la règle CSRF est justifiée sur place : session en `SameSite=Lax` (une requête de sous-ressource n'emporte pas le cookie), jeton qui fuirait en query string dans les journaux d'accès et le `Referer`, et navigation piégée qui ne détruit ni ne divulgue rien. L'analogie ne vaut pas argument : toute autre exception exigerait une nouvelle décision d'architecture.
- **URL** : chemins en anglais et invariables, sans préfixe de langue ; le `?lang=` de la page de connexion est la seule exception. Noms de route préfixés `app_` ; les chemins de cet epic sont `/login`, `/logout`, `/password/forgot`, `/password/reset/{token}` et `/` pour l'accueil.
- **Conventions** : namespaces `App\Core\<Couche>\…` et `App\Module\<Nom>\<Couche>\…` ; services `final readonly` nommés d'après leur responsabilité, jamais `<Chose>Service` ; entités au format maker sans méthode de comportement ; `Dto/{Input,Read,Output}` ; exceptions métier portant leur statut HTTP et leur niveau de log ; permissions en `snake_case` préfixé ; catalogues de traduction par domaine, clés en anglais pointées, source française ; configuration répartie entre variable d'environnement, paramètre `app.` et constante de classe ; secrets par fichier local en développement, coffre de secrets Symfony en production.
- **Console** : une commande est une fine couche de traduction au-dessus d'un service qui porte les règles.
- **Frontend** : AssetMapper et Tailwind 4 via le bundle Symfony, sans bundler ni étape Node. Kit shadcn copié dans le dépôt (un seul kit, une seule bibliothèque d'icônes). Aucun Live Component dans le socle.
- **Déploiement** : Deployer en SSH sur `releases/` · `current/` · `shared/`, Apache et PHP-FPM, MySQL et SMTP du serveur client. Les migrations s'exécutent à chaque release, le worker est redémarré par le déploiement, et la date de la release servira de « date du dernier déploiement » à l'Epic 3. La sauvegarde est une responsabilité nommée du serveur client, documentée avec sa procédure de restauration.

## UX et patterns d'interaction

- **Thème** : shadcn « neutral » white-label, en clair et en sombre, avec les jetons ajoutés par le socle (`page`, `muted-strong`, `track`, la famille `sidebar`, les cinq alias de statut), chacun avec sa variante sombre. Douze rôles typographiques sur pile système, sans police web ; graisses 400 / 500 / 600, aucune capitale forcée ; échelle d'espacement base 4 avec jetons nommés de gouttière, de largeur de contenu, de sidebar et de cible tactile. Aucune couleur codée en dur dans un template, aucun réglage de marque dans l'administration. Un défaut du kit sous un seuil WCAG (`--input`, `--ring`) est **conservé et documenté comme choix assumé, jamais consigné en dette**.
- **Gabarit de base unique** portant le lien « Aller au contenu », les repères de page, un `<main>` focalisable, la région d'annonces persistante que les navigations Turbo ne recréent pas, le `<title>` « <page> — <nom du dérivé> » et l'unique `h1`. Deux contrôleurs Stimulus l'accompagnent : l'un place le focus après chaque navigation (bloc d'erreur, puis premier champ invalide, puis flash, puis titre), l'autre recopie flashes et erreurs dans la région live.
- **Amélioration progressive vérifiable** : tout chemin fonctionne en pleine page sans JavaScript ; Turbo et Stimulus n'ajoutent que le confort ; aucune règle métier ne vit en JavaScript. Chaque action destructive a deux routes — un GET rendant une page de confirmation complète, un POST porteur d'un jeton CSRF qui agit.
- **Interaction** : clavier d'abord, aucun raccourci global inventé ; Échap ferme l'élément flottant le plus haut et rend le focus à son déclencheur ; `:focus-visible` sur tout élément interactif, y compris les lignes de tableau et les résumés d'accordéon ; le survol n'ajoute qu'une teinte, jamais une action ; une seule couche flottante à la fois ; ni glisser-déposer, ni clic droit, ni double clic, ni geste de balayage.
- **Responsive et mouvement** : quatre paliers jusqu'à 320 px à zoom 400 % sans défilement horizontal du body — seuls les tableaux et les blocs à chasse fixe défilent dans leur conteneur. Mouvement limité à quatre transitions courtes, toutes ramenées à zéro sous `prefers-reduced-motion`.
- **Sélecteur de langue de la connexion** : une navigation de liens `?lang=`, un par langue active, chacun portant ses attributs de langue et l'actif marqué comme courant — jamais un `<select>` qui soumettrait au changement.
- **Pages d'erreur** : 403, 404 et 500 partagent un gabarit court, un message traduit et un lien de retour à l'accueil, sans aucun détail technique — ni code HTTP, ni trace, ni nom de classe. Rendues par le template d'erreur du framework, donc aussi hors Turbo, qui retombe sur une navigation complète.
- **Textes** : la version française de référence de chaque moment vient du tableau de ton du contrat UX — vouvoiement, phrases courtes, jamais d'identifiant technique, un message d'erreur qui dit toujours quoi faire ensuite. Ces chaînes sont la source dont l'anglais et le néerlandais sont les traductions.
- **États vides** : barre de progression Turbo par défaut, aucun squelette de chargement.

## Dépendances inter-stories

- La 1.1 précède tout : arborescence, frontière et câblage par glob conditionnent chaque story suivante.
- La 1.2 outille la 1.1 et absorbe ensuite le contrôle d'accessibilité automatisé posé par la 1.4. Elle n'est pas vérifiable de bout en bout par un agent seul — elle demande un runner de CI réel.
- La 1.3 (thème et kit) précède la 1.4 (gabarit), qui précède tout écran : 1.6, 1.9 et 1.10 en héritent.
- La 1.5 fournit les catalogues dont dépendent les messages de 1.6, 1.7, 1.9 et 1.10.
- La 1.6 livre les entités Utilisateur et Rôle ; les trois rôles nommés existent ici, leurs permissions n'arrivant qu'à l'Epic 2.
- La 1.7 étend la 1.6 ; la 1.9 dépend de la 1.8 pour l'envoi de l'email et livre l'entité à jeton que l'invitation de l'Epic 2 réutilisera telle quelle.
- La 1.11 suppose migrations, rôles et connexion en place (1.1, 1.6) ; son troisième critère ne se referme qu'à l'Epic 3.
- La 1.12 documente ce que les autres stories ont posé, et fixe la convention d'en-tête YAML des stories que l'Epic 3 consommera.
- La 1.13 revient sur la déconnexion posée par la 1.6 et sur le plancher d'accessibilité de la 1.4 : elle inverse le test qui exigeait un 403 sans jeton et fait disparaître le helper qui fabriquait l'URL signée.
