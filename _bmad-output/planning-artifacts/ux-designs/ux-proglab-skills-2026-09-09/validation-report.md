# Rapport de validation — proglab-skills

- **DESIGN.md :** `D:\laragon\www\proglab-skills\_bmad-output\planning-artifacts\ux-designs\ux-proglab-skills-2026-09-09\DESIGN.md`
- **EXPERIENCE.md :** `D:\laragon\www\proglab-skills\_bmad-output\planning-artifacts\ux-designs\ux-proglab-skills-2026-09-09\EXPERIENCE.md`
- **Exécuté le :** 2026-09-09T14:16:43Z

## Verdict global

La paire `DESIGN.md` + `EXPERIENCE.md` est un contrat exploitable en aval : les cinq parcours UJ-1..UJ-5 sont repris verbatim avec protagoniste, étapes numérotées, point culminant et cas limite ; les 22 FR sont tous rattachés à une surface ou déclarés sans interface ; chaque jeton de couleur a son hex en clair et en sombre et toutes les références `{…}` des deux fichiers se résolvent ; l'ordre canonique des sections est respecté et toutes les décisions du memlog sont placées. Trois points méritent une correction avant que l'architecture et le dev ne s'y appuient : les bordures des badges « À faire » / « Non lisible » référencent `{colors.border}` (≈ 1,3:1) alors que la forme de bordure est présentée comme porteuse de l'information de statut ; le niveau de titre des Dialog se contredit entre les deux fichiers (h2 / h3) ; et, la direction Aéré venant d'être promue dans `mockups/`, les liens vers les deux directions rejetées ne résolvent plus tandis que les trois écrans clés en cours de rendu ne sont référencés nulle part. Quelques états (vérification 2FA, invitation d'un email déjà connu, erreur serveur) manquent encore.

La revue accessibilité (WCAG 2.1 AA, sept règles de `symfony-proglab-accessibility`) déplace le curseur : elle confirme que les spines tiennent leurs règles là où elles sont explicites (statuts icône + libellé, labels réels, `:focus-visible`, un seul `h1`, rien derrière le survol, thème sombre conforme partout) mais relève un défaut critique que la rubrique n'a pas vu — la bordure des champs de formulaire à 1,26:1 en clair et 1,18:1 en sombre, seul indice du contour du champ, sur toutes les surfaces à formulaire, connexion comprise (WCAG 1.4.11) — et cinq constats élevés sur ce que les spines promettent sans le spécifier : annonces au lecteur d'écran sous Turbo, `autocomplete` (1.3.5, critère AA oublié), saisie du code 2FA, sélecteur de langue qui soumet au changement, et `muted-foreground` / `destructive` sous 4,5:1 hors d'une carte blanche. Rien n'est structurel : chaque constat se corrige par une ligne de spine ou un jeton. Cinq constats communs aux deux revues ont été fusionnés et comptés une seule fois, à la sévérité la plus haute des deux (bordures de badge, `muted-foreground` hors carte, 2FA, expiration de session, niveaux de titre) ; ils sont marqués « fusionné » ci-dessous.

## Verdicts par catégorie

- Couverture des flux — Solide (strong)
- Complétude des jetons — Solide (strong)
- Couverture des composants — Adéquat (adequate)
- Couverture des états — Adéquat (adequate)
- Couverture des références visuelles — Adéquat (adequate)
- Bloat & surspécification — Adéquat (adequate)
- Discipline d'héritage — Solide (strong)
- Conformité de forme — Solide (strong)

## Constats par sévérité

### Critique (1)

**[Accessibilité]** — La bordure des champs de formulaire est à 1,26:1 en clair et 1,18:1 en sombre, seul indice du contour du champ (WCAG 1.4.11) (§ `DESIGN.md` · frontmatter `input`, `components.input.border`, Components « Champ de formulaire »)
La bordure des champs est le seul indice de leur contour, et elle est à 1,26:1 en clair (`input` `#e5e5e5` sur `background` `#ffffff`) comme en sombre (1,18:1, `#262626` sur `card-dark` `#171717`). Le fond du champ est identique à celui de la carte, sans ombre ni fond différencié : un utilisateur malvoyant ne voit pas où cliquer ni où s'arrête le champ (≥ 3:1 exigé pour un composant d'interface). C'est le défaut du kit shadcn neutre, et il touche toutes les surfaces à formulaire, connexion comprise.
Correction : fixer `--input` à `oklch(0.60 0 0)` ≈ `#8a8a8a` (3,4:1 sur blanc) et `--input-dark` à `oklch(0.55 0 0)` ≈ `#7a7a7a` (3,3:1 sur `#171717`), ou donner aux champs un fond `{colors.muted}` + bordure 2 px `{colors.muted-strong}` ; ajouter la paire `input / background` à la table des combinaisons porteuses pour qu'elle soit remesurée au rebranding.

### Élevé (6)

**[Complétude des jetons · Accessibilité — fusionné]** — Bordures des badges « À faire » / « Non lisible » invisibles (≈ 1,3:1) alors que la forme de bordure est déclarée porteuse du statut (§ `DESIGN.md` · frontmatter l. 289-302 `badge-todo.border`, `badge-unreadable.border`, `track` ; Colors « Statuts lisibles » ; `mockups/direction-aere.html` l. 136, 138, 148, 160)
`components.badge-todo.border` = `1px dashed {colors.border}` et `components.badge-unreadable.border` = `1px dotted {colors.border}`, alors que les commentaires des jetons `status-todo` / `status-unreadable` les décrivent comme « contour tireté / pointillé (= muted-strong) » et que § Colors fait de la forme de bordure l'un des trois porteurs obligatoires du statut (« jamais la couleur seule »). `#e5e5e5` sur blanc ≈ 1,3:1 : le tireté et le pointillé sont invisibles, la distinction repose de fait sur l'icône seule ; la maquette (`.badge.todo`, `.badge.err`, `.ico.todo`, `details.card.broken`) reproduit le même défaut et le code aval le recopiera. La revue accessibilité ajoute que le rail de Progress (`track` `#e5e5e5`, 1,26:1) disparaît à 0 % ; l'icône et le libellé portant l'information, WCAG passe, mais la promesse écrite est fausse. (Rubrique : élevé ; accessibilité : faible — compté une fois, à élevé.)
Correction : faire pointer les deux bordures sur `{colors.status-todo}` / `{colors.status-unreadable}` (`#525252`, ≈ 7:1) ; dessiner le rail de Progress en `oklch(0.80 0 0)` ≈ `#c4c4c4` (1,6:1, suffisant pour un rail décoratif) ; ajouter la ligne « bordure de Badge de statut sur card ≥ 3:1 » au tableau de contraste — ou retirer « forme de bordure » de la règle.

**[Complétude des jetons · Accessibilité — fusionné]** — `muted-foreground` et `destructive` passent sous 4,5:1 hors d'une Card, et rien ne garantit que tableaux et formulaires y restent (§ `DESIGN.md` · Colors (tableau de contraste, « jamais sur `{colors.page}` »), Layout & Spacing, Components ligne Table, frontmatter `input.help-color`, `table.header-color`, `input.error-color` ; `mockups/direction-aere.html` l. 157, 284)
`{colors.muted-foreground}` est autorisé pour les en-têtes de tableau « sur carte uniquement », mais ni Layout & Spacing ni la ligne Table de Components ne disent que les tableaux (Utilisateurs, Journal d'audit) sont rendus dans une Card. Recalcul de la revue accessibilité : `muted-foreground` `#737373` sur `page` `#f5f5f5` = 4,35:1 ; `destructive` `#e7000b` sur `page` = 4,38:1. Le mock le fait déjà : `.foot-note` (`color: var(--muted-foreground)`) est posée directement sur `--page` (`.content` n'a pas de fond carte). Et `components.input.help-color` = `muted-foreground`, `components.table.header-color` = `muted-foreground`, `components.input.error-color` = `destructive`, alors que rien ne dit si un formulaire (Connexion, Inviter, filtres du journal) ou un tableau vit dans une Card ou à nu sur la page. (Rubrique : moyen ; accessibilité : élevé — compté une fois, à élevé.)
Correction : écrire dans Layout & Spacing et dans la ligne Table « tout formulaire et tout tableau est rendu dans une `{components.card}` » ; passer `foot-note` et `help-color` en `muted-strong` (ou `table.header-color` en `{colors.muted-strong}`) ; ajouter un jeton `destructive-text` `oklch(0.50 0.22 27)` ≈ `#c4000a` (≈ 5,4:1 sur `#f5f5f5`) pour le texte d'erreur, `destructive` restant réservé aux fonds de bouton.

**[Couverture des états · Accessibilité — fusionné]** — Vérification et enrôlement 2FA : ni états, ni saisie du code spécifiés (§ `EXPERIENCE.md` · Information Architecture (Vérification 2FA, Enrôlement 2FA), State Patterns, Voice and Tone, Key Flows › UJ-1 étape 3, Flux 6 étape 5)
Aucun état : code erroné, code email expiré / « Renvoyer un code », utilisation d'un code de secours, échec de la vérification à l'enrôlement, épuisement des codes de secours. La revue accessibilité ajoute que la saisie du second facteur n'est spécifiée nulle part : ni le type de champ (un seul `<input>` ou six cases), ni `inputmode="numeric"`, ni le label, ni l'association de l'erreur « code incorrect », ni le chemin « utiliser un code de secours », ni, pour le code par email, sa durée de validité et le bouton « Renvoyer ». Le composant « InputOTP » à six cases, tentant dans un kit shadcn, est notoirement hostile aux lecteurs d'écran (six champs sans label, focus qui saute). (Rubrique : moyen ; accessibilité : élevé — compté une fois, à élevé.)
Correction : trois lignes de State Patterns + les phrases correspondantes en Voice and Tone (« Code incorrect. Vérifiez l'heure de votre téléphone ou utilisez un code de secours. ») ; un seul champ texte, label visible « Code à 6 chiffres de votre application », `inputmode="numeric"` `autocomplete="one-time-code"` `pattern="[0-9]{6}"`, espaces tolérés ; lien « Je n'ai pas accès à mon application — utiliser un code de secours » vers un champ distinct ; pour le code par email : « valable 10 minutes » + bouton « Renvoyer un code » ; erreur en `aria-invalid` + `aria-describedby` comme les autres champs.

**[Accessibilité]** — Flash et Alert « annoncés » ne le seront pas : régions live présentes au chargement, navigation Turbo sans annonce ni focus (§ `EXPERIENCE.md` · Accessibility Floor « En plus », Component Patterns « Flash », « Alert », Interaction Primitives « Focus » ; mock l. 214)
« Les Flash sont annoncés (`role="status"` / `role="alert"`) » ne tiendra pas : le Flash est rendu côté serveur après une redirection 303, et une région live présente au chargement n'est pas annoncée — seuls ses changements le sont. Même chose pour l'Alert « Roadmap partielle » (`role="alert"` statique, un contresens ARIA). Sous Turbo Drive, la navigation ne recharge pas la page : ni le nouveau `<title>`, ni le `h1` ne sont annoncés d'eux-mêmes, et « après une redirection, le focus va au `h1` » n'est pas un comportement de Turbo — il faut l'écrire.
Correction : (1) un contrôleur Stimulus sur `turbo:load` qui met le focus sur le Flash s'il existe (`tabindex="-1"`, il porte déjà `role`), sinon sur le `h1` (`tabindex="-1"`) ; (2) un `<title>` par page « {page} — {dérivé} » (2.4.2) ; (3) l'Alert roadmap partielle en `role="region"` + `aria-labelledby` sur son `h3`, pas `role="alert"`.

**[Accessibilité]** — Le sélecteur de langue de la Connexion soumet au changement d'un `<select>` (échec F37, WCAG 3.2.2) (§ `EXPERIENCE.md` · Component Patterns « Sélecteur de langue (Connexion) », Internationalisation)
Le sélecteur « soumet immédiatement (GET `?lang=`) » au changement d'un `<select>` : c'est l'échec canonique F37 de WCAG 3.2.2 (changement de contexte à la saisie). Au clavier sous Windows, chaque flèche change la valeur et recharge la page — impossible d'atteindre la troisième langue sans deux rechargements, et le lecteur d'écran bascule de langue en cours de route.
Correction : un groupe de trois liens `?lang=fr|en|nl` (chacun avec `lang="…"` et `hreflang`, l'actif en `aria-current="true"`), ou le `<select>` avec un bouton « Changer » explicite ; jamais un `onchange` qui navigue.

**[Accessibilité]** — Aucune mention d'`autocomplete` alors que 1.3.5 Identify Input Purpose est un critère AA (§ `EXPERIENCE.md` · Component Patterns « Champ de formulaire » ; `DESIGN.md` · Components « Champ de formulaire »)
Email, prénom, nom, mot de passe actuel, nouveau mot de passe, code à usage unique doivent porter leur jeton (`email`, `given-name`, `family-name`, `current-password`, `new-password`, `one-time-code`). Sans lui, les gestionnaires de mots de passe et les outils d'assistance cognitive ne remplissent pas Connexion, Invitation, Réinitialisation ni Vérification 2FA.
Correction : ajouter à la règle « Champ de formulaire » : « chaque champ qui concerne la personne porte son jeton `autocomplete` HTML (liste ci-dessus), posé dans le FormType ».

### Moyen (15)

**[Couverture des composants]** — États de compte et de langue rendus par Badge sans variante visuelle (§ `EXPERIENCE.md` · State Patterns, Key Flows › UJ-2 ; `DESIGN.md` · Components ligne Badge)
Les états de compte « Invité », « Invitation en attente » (+ date d'expiration), « Invitation expirée », « Actif », et l'état de langue « Inactif » sont rendus par Badge ou colonne État, mais la ligne Badge de `DESIGN.md` ne connaît que `done / doing / todo / ready / unreadable / deactivated` et l'origine de permission.
Correction : ajouter `badge-invited` et `badge-expired` (icône + forme) au frontmatter et à la ligne Badge, ou dire que l'État est un texte `{typography.body}` sans Badge.

**[Couverture des composants]** — Ligne de tâche en environnement de développement sans spécification visuelle (§ `EXPERIENCE.md` · Component Patterns « Bouton « Lancer » », Permissions et visibilité ; `DESIGN.md` · Layout & Spacing)
Le bouton « Lancer » (actif / `disabled` + raison sous le bouton) et l'Accordion imbriqué « Notes internes » n'ont aucune spécification visuelle : la grille `36px 1fr 150px` ne prévoit pas de colonne pour le bouton, et la maquette retenue ne montre que la production.
Correction : une variante « ligne de tâche (dev) » dans Components : position du bouton (`outline`, colonne badge ou sous les méta), style de la raison (`{typography.meta}` `{colors.muted-strong}`), rendu du bouton « Notes internes ».

**[Couverture des états]** — Inviter un utilisateur : email déjà connu non traité (§ `EXPERIENCE.md` · State Patterns, Voice and Tone)
Email déjà associé à un compte actif, désactivé ou déjà invité — le cas le plus fréquent de ce formulaire — n'est pas traité.
Correction : une ligne par cas ; pour un compte désactivé, proposer « Réactiver » plutôt que ré-inviter.

**[Couverture des états]** — Aucune page d'erreur serveur (§ `EXPERIENCE.md` · Information Architecture, State Patterns › Sans permission)
Pas de page 500 / Turbo sans réponse alors que le memlog classe « pages d'erreur » en spine-only et que seules 403 et 404 sont spécifiées.
Correction : une surface « Erreur inattendue » avec le même gabarit court que 403 (« Quelque chose n'a pas fonctionné. Réessayez ; si cela persiste, prévenez votre administrateur. »).

**[Couverture des états · Accessibilité — fusionné]** — Durée de session et expiration par inactivité non spécifiées (§ `EXPERIENCE.md` · State Patterns › Session close)
« Session close » ne couvre que la désactivation / le changement de mot de passe ; l'expiration par inactivité n'est pas nommée. Une session qui expire sans avertissement fait perdre un formulaire en cours (Inviter, Fiche rôle) et relève de WCAG 2.2.1 Timing Adjustable sauf si la limite dépasse 20 h. (Rubrique : faible ; accessibilité : moyen — compté une fois, à moyen.)
Correction : fixer la durée de session (≥ 20 h glissantes, ou « se souvenir de moi ») et le comportement d'un POST après expiration : reconnexion puis retour sur la même URL, avec Flash « Votre session avait expiré ; vos modifications n'ont pas été enregistrées. » ; l'ajouter à la ligne « Session close » avec le même Flash.

**[Couverture des références visuelles]** — Liens vers les deux directions rejetées ne résolvent pas (§ `EXPERIENCE.md` · Inspiration & Anti-patterns ; `DESIGN.md` · Brand & Style)
`mockups/direction-compact.html` et `mockups/direction-editorial.html` ne résolvent pas : ces fichiers restent dans `.working/` (memlog 16:10 : rejetés, non promus). `DESIGN.md · Brand & Style` affirme de même que « les trois directions … sont dans `mockups/` ».
Correction : soit pointer sur `.working/…`, soit retirer les liens et ne garder que la description en une phrase (les directions rejetées n'ont pas besoin d'un fichier navigable).

**[Couverture des références visuelles]** — Les trois écrans clés en cours de rendu ne sont référencés nulle part (§ `EXPERIENCE.md` · Information Architecture ; `DESIGN.md` · Components lignes Tabs / Champ de formulaire / Dialog)
Les trois écrans clés décidés dans le memlog (`key-fiche-utilisateur`, `key-profil`, `key-connexion-invitation`) ne sont référencés dans aucun des deux spines ; ils seront orphelins dès leur arrivée dans `mockups/`.
Correction : un lien inline « → Composition : `mockups/key-….html` » dans `EXPERIENCE.md · Information Architecture` (lignes Fiche utilisateur, Profil, Connexion / Acceptation d'invitation) et dans les lignes Tabs / Champ de formulaire / Dialog de `DESIGN.md · Components`, avec ce que chacun illustre.

**[Bloat & surspécification]** — Règles comportementales dupliquées entre `DESIGN.md` et `EXPERIENCE.md` (§ `DESIGN.md` · Components ; `EXPERIENCE.md` · Component Patterns)
`DESIGN.md · Components` porte des règles comportementales dupliquées d'`EXPERIENCE.md` : Banner « non fermable tant que la condition tient », Tabs « chaque onglet est un lien vers sa propre URL », Pagination « pas de défilement infini », Flash « pas de disparition automatique », Sidebar « les entrées non permises ne sont pas rendues ». Deux sources pour la même règle = deux endroits à maintenir et un conflit possible.
Correction : ne garder dans `DESIGN.md` que l'anatomie et les variantes, renvoyer à `EXPERIENCE.md` pour le comportement.

**[Discipline d'héritage · Accessibilité — fusionné]** — Niveaux de titre contradictoires entre les documents et la maquette (Dialog h2 / h3, Alert h3 / h2) (§ `DESIGN.md` · Typography (tableau, ligne Sous-titre, paragraphe), Do's and Don'ts, Components lignes Dialog / Alert ; `EXPERIENCE.md` · Accessibility Floor règle 6 ; `mockups/direction-aere.html` l. 109, 217)
`DESIGN.md · Typography` et Do's and Don'ts disent « `h3` pour les Alert et Dialog » ; la ligne Dialog de Components dit « titre `h2` » (Dialog et Sheet) ; `EXPERIENCE.md · Accessibility Floor` règle 6 dit « Sheet et Dialog en `h2`, Alert en `h3` » ; le mock rend l'Alert en `h2`, au même niveau que les cartes d'epic. Le développeur choisira l'un des quatre ; la règle 6 de `symfony-proglab-accessibility` en dépend. (Rubrique : moyen ; accessibilité : moyen — compté une fois.)
Correction : une seule règle, partout : `h1` page ; `h2` carte d'epic, section de fiche, Dialog et Sheet (contexte modal, argument déjà donné dans Accessibility Floor) ; `h3` Alert, Flash, groupe de permissions ; corriger le tableau Typography, la ligne Do's et le mock.

**[Accessibilité]** — Repères de page et saut de blocs (2.4.1) : `<aside>` pour la navigation, deux `<nav>` sans nom, pas de `<main>`, pas de lien d'évitement (§ `EXPERIENCE.md` · Interaction Primitives « Clavier d'abord » ; mock l. 180, 183, 188, 206)
Le mock enveloppe la navigation dans un `<aside>` (repère *complementary*, faux pour la navigation principale), contient deux `<nav>` sans nom (annoncés « navigation, navigation »), les intitulés de groupe « Suivi » / « Administration » sont des `<div>` non reliés, il n'y a pas de `<main>` (`.content` est un `div`) et aucun lien d'évitement n'est prévu : l'ordre « Sidebar, en-tête, `h1` » impose cinq liens et un bouton à traverser au clavier sur chaque page.
Correction : `<nav aria-label="Navigation principale">` contenant des `<ul>` groupés avec `aria-labelledby` sur leur intitulé ; `<main id="contenu" tabindex="-1">` ; un lien « Aller au contenu » premier dans le DOM, visible au focus ; l'ajouter à Interaction Primitives.

**[Accessibilité]** — Tabs du Profil : `aria-selected` sur des liens qui naviguent (4.1.2) (§ `EXPERIENCE.md` · Component Patterns « Tabs (Profil) »)
« Chaque onglet un lien vers sa propre URL ; l'onglet courant a `aria-selected` » — `aria-selected` n'est valide que sur `role="tab"`, et des liens qui naviguent ne sont pas des tabs ARIA (axe le signalera).
Correction : `<nav aria-label="Sections du profil">` avec des liens et `aria-current="page"` sur l'onglet courant ; le visuel Tabs du kit reste, la sémantique change.

**[Accessibilité]** — Filtres du journal : période et type d'entrée sans étiquetage de groupe (§ `EXPERIENCE.md` · Component Patterns « Filtres du journal » ; Accessibility Floor 3)
Deux `<input type="date">` sous un seul mot « Période » donnent deux champs sans nom distinct ; des radios sans `<fieldset>`/`<legend>` perdent leur question au lecteur d'écran.
Correction : `<fieldset><legend>Période</legend>` avec labels « Du » / « Au » ; `<fieldset><legend>Type d'entrée</legend>` pour les radios ; le nombre de résultats (« 3 entrées ») rendu dans le `h1` ou juste sous lui, pour être lu après le focus post-soumission.

**[Accessibilité]** — « Lien couvrant la ligne » : vingt liens au nom identique ou vide dans le Journal (2.4.4) (§ `EXPERIENCE.md` · Component Patterns « Sheet (entrée d'audit) », « Table »)
Sans règle de nommage, le Journal expose vingt liens au nom identique ou vide, et un lien étiré en `::after` par-dessus la ligne rend les autres cellules non sélectionnables et hors du nom du lien.
Correction : le lien vit dans la première cellule et porte un nom complet — « Ouvrir l'entrée du 8 septembre 14 h 12, Karim Benali, Tarif Chêne massif » (texte visible = date, reste en `sr-only`) ; la zone cliquable étendue se fait en `::after` sur ce lien seulement ; même règle pour la ligne d'utilisateur.

**[Accessibilité]** — Erreur de connexion sans champ en erreur : « focus sur le premier champ en erreur » n'a pas de cible (§ `EXPERIENCE.md` · State Patterns « Mot de passe erroné avec ralentissement », « Compte désactivé » ; Voice and Tone)
Le message est générique (« Email ou mot de passe incorrect », compte désactivé, ralentissement) : aucun champ à cibler. Le message « Patientez 40 secondes » n'est ni annoncé ni rafraîchi.
Correction : un bloc `role="alert"` `tabindex="-1"` au-dessus du formulaire qui reçoit le focus au rendu 422 ; à chaque nouvelle soumission pendant le délai, le message est re-rendu avec les secondes restantes (pas de compte à rebours JS, pas de bouton désactivé) ; préciser que le délai est exempté de 2.2.1 (sécurité).

**[Accessibilité]** — Titre d'epic à l'intérieur du déclencheur d'Accordion : nom accessible concaténé, navigabilité par titres perdue (§ `DESIGN.md` · Components « Accordion » ; `EXPERIENCE.md` · Accessibility Floor 6 ; mock l. 117, 225)
Le mock place `<h2>` + Badge + description + phrase d'avancement + pourcentage dans `<summary>` : le nom accessible du bouton est toute la concaténation, et un titre à l'intérieur d'un bouton perd sa navigabilité par titres sous certains couples navigateur / lecteur d'écran. L'Accordion du kit shadcn rend son propre en-tête (souvent `h3`) autour du trigger, ce qui contredit « cartes d'epic en `h2` » si la prop de niveau n'est pas fixée.
Correction : spécifier `<h2><button aria-expanded aria-controls>Gestion des devis</button></h2>` — le bouton ne contient que le titre — avec Badge et bloc d'avancement hors du bouton mais dans le résumé cliquable ; fixer le niveau de titre du composant Accordion à 2 dans la règle d'usage.

### Faible (23)

**[Couverture des flux]** — FR-7, FR-17 et FR-20 n'ont aucun flux (§ `EXPERIENCE.md` · Information Architecture, Voice and Tone, Questions ouvertes › OQ-UX-3)
FR-7 (Rôles / Fiche rôle), FR-20 (anonymiser) et FR-17 (jetons d'API) ne vivent que par une ligne d'IA, une copie de Dialog ou un état.
Correction : un court flux 10 « Sophie crée un rôle et ajuste ses permissions » et un flux 11 « Léa crée un jeton d'API » suffiraient, ou noter explicitement que ces écrans sont « spine-only, tables seules » comme le memlog le décide.

**[Couverture des flux]** — UJ-2 renvoie au flux 6 dont le protagoniste ne correspond pas (§ `EXPERIENCE.md` · Key Flows › UJ-2, Flux 6)
UJ-2 étape 2 renvoie à « Flux 6 » pour l'acceptation de Karim, mais le flux 6 a Sophie pour protagoniste.
Correction : écrire « Karim accepte (même mécanisme que le flux 6) » ou rendre le flux 6 générique.

**[Complétude des jetons]** — Objets `badge-*` et `alert-destructive` partiels sans règle d'héritage explicite (§ `DESIGN.md` · frontmatter l. 260-271, 294-306)
`badge-ready` et `badge-deactivated` n'ont pas de clé `border` ; `alert-destructive` et `flash` sont des objets partiels dont l'héritage n'est explicite que pour `flash` (`extends`, clé hors spec design.md).
Correction : soit une clé `border` explicite, soit une phrase en § Components : « les objets `badge-*` et `alert-destructive` héritent de `badge` / `alert` pour toute clé absente ».

**[Couverture des composants]** — Les deux « pastilles » n'ont ni ligne propre ni jeton (§ `DESIGN.md` · Shapes, Components ligne Accordion ; `EXPERIENCE.md` · Component Patterns)
Les pastilles (dépendance « En attente de … », dernier déploiement) sont décrites en passant, sans ligne propre ni jeton, et sont absentes de Component Patterns.
Correction : une ligne **Pill** (ou les intégrer explicitement à Badge comme variante `outline` sans icône).

**[Couverture des composants]** — « Interrupteur de langue » évoque un Switch alors que le comportement est deux boutons + Dialog (§ `EXPERIENCE.md` · Component Patterns, Information Architecture › Langues)
« Interrupteur de langue » et « interrupteur actif / inactif » évoquent un Switch shadcn, alors que le comportement décrit est deux boutons « Désactiver » / « Réactiver » + Dialog.
Correction : renommer « Boutons de langue » ou préciser « pas un Switch ».

**[Couverture des composants]** — Actions en ligne de la Table des permissions sans variante visuelle (§ `EXPERIENCE.md` · Key Flows › UJ-2 étapes 4-5 ; `DESIGN.md` · Components lignes Table / Button)
« Ajouter », « Retirer », « Revenir à l'héritage » n'ont pas de variante visuelle dans la ligne Table (`ghost` ? `outline` ?).
Correction : une phrase dans la ligne Table ou Button.

**[Couverture des états]** — Lien d'invitation déjà accepté non couvert par « expiré » (§ `EXPERIENCE.md` · State Patterns › Invitation expirée)
Lien d'invitation déjà accepté (second clic) et lien de réinitialisation déjà consommé sont regroupés sous « expiré » côté réinitialisation mais pas côté invitation.
Correction : étendre la ligne à « expirée ou déjà utilisée ».

**[Couverture des états]** — Rôles / Fiche rôle et Profil sans état (§ `EXPERIENCE.md` · State Patterns)
Rôles / Fiche rôle : aucun état (rôle par défaut non supprimable, rôle utilisé par n utilisateurs, enregistrement des cases) ; Profil : un Admin qui voudrait désactiver sa propre 2FA alors que son rôle l'exige.
Correction : deux lignes de State Patterns ou une note « spine-only » assumée.

**[Couverture des références visuelles]** — « Le spine l'emporte » répété trois fois (§ `DESIGN.md` · Brand & Style, Components ; `EXPERIENCE.md` · intro)
La rubrique demande une seule mention ; la phrase apparaît deux fois dans `DESIGN.md` en plus de l'intro d'`EXPERIENCE.md`.
Correction : garder celle de l'intro d'`EXPERIENCE.md` et une seule dans `DESIGN.md`.

**[Couverture des références visuelles]** — La maquette retenue diverge du spine sur deux points non signalés (§ `DESIGN.md` · Components ligne Alert ; `EXPERIENCE.md` · Inspiration & Anti-patterns)
Le titre de l'Alert « Roadmap partielle » est un `h2` (spine : `h3`) et le Banner 2FA est montré à Marc qui, dans UJ-3, a déjà sa 2FA active. Le spine l'emporte, mais un lecteur de la maquette ne le saura pas.
Correction : une parenthèse dans la ligne Alert (« la maquette le rend en h2 ; c'est h3 ») et dans Inspiration (« le Banner y est illustratif »).

**[Bloat & surspécification]** — « Besoins → surfaces → parcours » restitue le PRD §2.1 (§ `EXPERIENCE.md` · Information Architecture)
La table « Exigences → surfaces » suffit à un consommateur.
Correction : supprimer ou réduire à une ligne de renvoi.

**[Bloat & surspécification]** — Fioritures narratives dans les flux (§ `EXPERIENCE.md` · Key Flows)
« avant midi », « personne n'a ouvert un fichier YAML », « Charmant, mais… » : la rubrique réserve la voix éditoriale à `DESIGN.md`.
Correction : garder les faits vérifiables dans le point culminant.

**[Bloat & surspécification]** — `typography.*.fontFamily` répète douze fois la même pile système (§ `DESIGN.md` · frontmatter l. 83-144)
Douze rôles portent la même pile ; seul `mono` diffère.
Correction : un commentaire « pile système sur tous les rôles sauf `mono` » et la pile écrite une fois, `mono` à part — ou accepter la répétition comme prix de la résolution plate.

**[Discipline d'héritage]** — Numérotation des questions ouvertes : OQ-UX-2 manque (§ `EXPERIENCE.md` · Questions ouvertes)
OQ-UX-1, 3, 4, 5 — pas d'OQ-UX-2 alors que le memlog en compte cinq.
Correction : renuméroter ou restaurer l'entrée manquante.

**[Conformité de forme]** — Key Flows placé avant les sections qui fixent les règles qu'ils appliquent (§ `EXPERIENCE.md` · ordre des sections)
`Key Flows` précède `Responsive & Platform`, `Permissions et visibilité`, `Environnement…`, `Internationalisation` et `Inspiration`, alors que les exemples de référence terminent sur les flux ; un lecteur linéaire rencontre le bouton « Lancer » avant la table dev / prod qui le conditionne.
Correction : déplacer les quatre sections inventées et `Inspiration` avant `Key Flows`, `Questions ouvertes` en dernier.

**[Accessibilité]** — Anneau de focus — dette connue : 2,05:1 sur blanc, 1,88:1 sur page, 1,96:1 sur sidebar (§ `DESIGN.md` · Colors)
`#b5b5b5` donne 2,05:1 sur blanc, et 1,88:1 sur `page` / 1,96:1 sur `sidebar`, précisément sur les surfaces où le clavier circule le plus (navigation, en-tête). Mentionné pour que la fiche de dette porte les trois valeurs, sans rediscuter la décision.
Correction : compléter la fiche de dette avec les trois valeurs et son déclencheur de revérification (rebranding).

**[Accessibilité]** — Le mock anime la barre d'avancement que le spine interdit, sans bloc `prefers-reduced-motion` (§ mock l. 14-15, 128, 129)
`.bar i { transition: width .6s }` anime la barre que `DESIGN.md` interdit (`progress.animation: none`), et malgré le commentaire d'en-tête il n'existe aucun bloc `@media (prefers-reduced-motion: reduce)` dans le fichier.
Correction : supprimer la transition de `.bar i`, ajouter le bloc `prefers-reduced-motion` mettant `.chev` à `transition: none`, et rappeler dans `DESIGN.md` que la maquette est périmée sur ce point.

**[Accessibilité]** — Progress : ni `role="progressbar"` ni `aria-valuenow`, et risque de double lecture (§ `EXPERIENCE.md` · Component Patterns « Progress » ; mock l. 127-128, 230)
Si le spine ajoute les attributs ARIA et garde la phrase visible « 6 sur 10 tâches terminées », le lecteur d'écran lit la valeur deux fois.
Correction : `role="progressbar" aria-valuemin="0" aria-valuemax="10" aria-valuenow="6" aria-labelledby="{id de la phrase}"`, ou plus simple : la barre en `aria-hidden="true"`, la phrase et le pourcentage portent tout ; « — » sur une epic non lisible = pas de progressbar.

**[Accessibilité]** — Menu compte et langue : bouton sans indication de menu, entrées de langue sans `menuitemradio` ni `lang`, thème sans script inline (§ `EXPERIENCE.md` · Component Patterns « DropdownMenu (compte) » ; Flux 9 ; mock l. 199-203)
Le bouton d'en-tête s'appelle « Marc Dubois » (avatar `aria-hidden`) — rien ne dit que c'est un menu ; les entrées de langue doivent être des `menuitemradio` avec `aria-checked` et porter chacune leur `lang` (3.1.2) ; la bascule de thème doit poser `color-scheme` sur `<html>` et lire la préférence dans un script inline avant le premier rendu pour éviter le flash clair → sombre.
Correction : `<span class="sr-only">Menu du compte —</span> Marc Dubois` ; préciser `menuitemradio` + `lang` par option ; ajouter le script inline de thème à la coque.

**[Accessibilité]** — Cibles tactiles : 44 px promis mais `size="icon"` à 36 px et entrées de Sidebar ≈ 40 px (§ `DESIGN.md` · frontmatter `spacing.touch-target-min`, `sidebar.item-padding` ; `EXPERIENCE.md` · Interaction Primitives « Tactile »)
`touch-target-min` 44 px n'est promis que pour « boutons, champs, lignes, entrées de menu » ; les boutons `size="icon"` font 36 px dans le kit et les entrées de Sidebar ≈ 40 px. AA en 2.1 n'exige pas 44 px (2.5.5 est AAA), mais le spine s'y engage.
Correction : étendre la règle aux `size="icon"` et aux entrées de navigation sur pointeur grossier (`@media (pointer: coarse)`), ou reformuler l'engagement en « ≥ 24 px, 44 px sur les actions principales ».

**[Accessibilité]** — Espacement du texte (1.4.12) : hauteurs fixes sur badge, bouton et en-tête (§ `DESIGN.md` · frontmatter `badge.height`, `button-primary.height`, `header-height` ; mock l. 93, 132)
`badge` et `button` sont en `height` fixe (26 px, 38 px) avec `line-height: 1` et `white-space: nowrap`, l'en-tête en `height: 60px`. Une feuille utilisateur qui impose `line-height: 1.5` et `letter-spacing: 0.12em` fera déborder le texte hors du badge.
Correction : `min-height` partout, `line-height` normal sur les badges ; vérifier avec le bookmarklet « text spacing » une fois par écran clé.

**[Accessibilité]** — Bouton « Lancer » inactif en `disabled` : sort de l'ordre de tabulation (§ `EXPERIENCE.md` · Component Patterns « Bouton "Lancer" »)
Un élément `disabled` sort de l'ordre de tabulation : l'utilisateur clavier ne rencontre jamais le bouton et ne sait pas qu'une action existe sur cette ligne ; la raison est un texte visible, mais n'est pas reliée.
Correction : `aria-disabled="true"` seul (focusable), `aria-describedby` vers la raison, clic ignoré côté serveur.

**[Accessibilité]** — Reflow : la promesse vise 200 % alors que 1.4.10 exige 320 px CSS (400 %) (§ `EXPERIENCE.md` · Accessibility Floor « En plus »)
1.4.10 exige l'absence de défilement bidimensionnel à 320 px CSS (soit 400 % sur 1280 px), pas à 200 %. Les points de rupture prévus le couvrent très probablement, mais la promesse doit viser la bonne cible.
Correction : remplacer « 200 % » par « jusqu'à 320 px de large (zoom 400 %) sans défilement horizontal » et l'ajouter au tableau Responsive.

## Fichiers des revues

- `review-rubric.md`
- `review-accessibility.md`
