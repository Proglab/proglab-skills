# Glossaire — Socle ERP custom (proglab)

Companion de `SPEC.md`. Vocabulaire contraignant : `SPEC.md`, `criteres-acceptation.md`, l'ARCHITECTURE-SPINE, `DESIGN.md` et `EXPERIENCE.md` emploient ces termes dans ce sens exact.

- **Socle** — le dépôt Symfony de référence que l'on clone pour démarrer un ERP.
- **Dérivé** — un ERP client issu d'un clonage du socle ; diverge librement ensuite.
- **Module** — un domaine métier propre à un dérivé, vivant sous `src/Module/<Nom>/`, par opposition au socle qui vit sous `src/Core/`.
- **Utilisateur** — une personne disposant d'un compte dans un dérivé. A exactement un **rôle** et zéro ou plusieurs **surcharges**. Peut être **actif** ou **désactivé**.
- **Administrateur** — utilisateur dont le rôle porte la permission d'administrer les comptes et les permissions ; par défaut les rôles **Super admin** et **Admin**.
- **Super admin** — rôle par défaut de l'équipe qui construit le dérivé : toutes les permissions, dont celles réservées au développement.
- **Admin** — rôle par défaut du responsable côté PME : administre les comptes et les permissions, consulte la roadmap et le journal d'audit.
- **User** — rôle par défaut de tout autre utilisateur de la PME.
- **Client** — la direction de la PME pour laquelle le dérivé est construit ; ce sont des utilisateurs (Admin ou User), distingués par leurs permissions, pas par une nature différente.
- **Rôle** — un ensemble nommé de **permissions** par défaut. Un utilisateur a un rôle.
- **Permission** — le droit d'accomplir une **opération** sur une **ressource**, identifié par un code stable.
- **Ressource** — une chose sur laquelle des permissions portent (les utilisateurs, les rôles, le journal d'audit, la roadmap, et chaque objet métier d'un module). Le socle et chaque module déclarent les leurs, avec les opérations que chacune supporte.
- **Opération** — l'une de : créer, consulter, modifier, supprimer. Une ressource ne supporte pas nécessairement les quatre.
- **Action particulière** — un droit qu'aucune des quatre opérations ne décrit (inviter, anonymiser, exporter, lancer une tâche, voir les notes internes), déclaré par sa ressource et accordé au même endroit qu'elle.
- **Grille des permissions** — l'écran qui présente, pour un rôle ou pour un utilisateur, une ligne par ressource et une colonne par opération, plus ses actions particulières.
- **Surcharge** — l'ajout ou le retrait d'une permission pour un utilisateur donné, qui prime sur ce que son rôle prévoit.
- **Permission effective** — le rôle, moins les retraits, plus les ajouts de surcharge.
- **Origine d'une permission** — pour un utilisateur, l'une de : héritée du rôle, ajoutée par surcharge, retirée par surcharge.
- **Invitation** — un lien à usage unique et à durée limitée envoyé par email, qui permet à une personne de créer son compte avec un rôle prédéfini.
- **Double authentification (2FA)** — second facteur exigé à la connexion après le mot de passe.
- **Délai de grâce** — la période, ouverte à la première connexion sous un rôle qui exige la 2FA, pendant laquelle l'utilisateur est averti avant d'y être contraint.
- **Journal d'audit** — l'ensemble des **entrées d'audit** d'un dérivé, fenêtre en ligne et archive confondues.
- **Fenêtre en ligne** — la durée pendant laquelle une entrée d'audit reste dans le journal en ligne avant d'être archivée. Un mois par défaut, réglable par dérivé.
- **Archive** — le magasin qui reçoit les entrées sorties de la fenêtre en ligne. Même contenu, même lecture, même export ; seul le dépôt d'audit sait qu'elle existe.
- **Entrée d'audit** — un enregistrement immuable de qui a fait quoi et quand. De deux types : **modification** (changement d'un objet, avec les champs avant/après) ou **action métier** (événement nommé déclenché par le code, par exemple « devis validé »).
- **Alias de type d'objet** — la chaîne courte et stable par laquelle une entrée d'audit désigne le type de son objet, déclarée par le propriétaire de ce type ; jamais un nom de classe.
- **Roadmap** — la vue, dans le dérivé, des **epics** et des **tâches** du projet, lue depuis les fichiers BMAD du dépôt.
- **Epic** — un regroupement de tâches portant un objectif ; a un avancement.
- **Tâche** — l'unité de travail de la roadmap ; correspond à une story BMAD. Porte un statut, une priorité, une difficulté, une personne assignée et des dépendances.
- **Tâche prête** — tâche au statut lisible « à faire » dont toutes les dépendances sont terminées.
- **Notes internes** — le contenu d'une story au-delà de son titre et de son résumé.
- **Statut lisible** — la traduction d'un statut BMAD en l'un de trois états montrés aux utilisateurs : à faire, en cours, terminé.
- **Lancer une tâche** — l'action, disponible seulement en environnement de développement, qui démarre les agents chargés de réaliser une tâche prête.
- **Environnement de développement** — un dérivé qui tourne sur le poste d'un développeur, par opposition à la **production** déployée chez le client.
- **API** — l'interface JSON du dérivé destinée à de futures **applications clientes** (hors navigateur).
