---
title: Addendum au PRD — Socle ERP custom (proglab)
status: final
created: 2026-09-09
updated: 2026-09-10
---

# Addendum au PRD — Socle ERP custom (proglab)

Choix techniques et détails discutés pendant la rédaction du PRD, destinés à
l'architecture. Le PRD dit quoi ; ce document dit avec quoi et pourquoi. L'addendum du
brief (`../../briefs/brief-proglab-skills-2026-09-09/addendum.md`) reste valable et
n'est pas répété.

## Décision ouverte en tête : la version de Symfony du socle

**Tranchée le 2026-09-10 — AD-1 : Symfony 7.4 LTS sur PHP 8.5.** Symfony 8.0 est en fin
de vie depuis juillet 2026 et le support de la 8.1 s'arrête en janvier 2027 ; la 7.4 LTS
est soutenue jusqu'en novembre 2028 pour les correctifs et novembre 2029 pour la
sécurité. PHP 8.5 et non 8.4, dont la sécurité s'arrête onze mois avant cette échéance.
`scheb/2fa` 8.6, Symfony UX 3.4 et le kit shadcn restent tous disponibles sur ce couple.
Conséquence pour la suite de ce document : `damienharper/auditor-bundle` 7.x exige
Symfony 8 et sort donc du jeu.

## Choix techniques

Chaque puce suit le même schéma : choix · raison · FR concernée · statut.

### Arrêtés

- **Double authentification** : `scheb/2fa` (v8.6, Symfony 7.4/8, PHP 8.4), modules
  TOTP, email et codes de secours. Décidé par Fabrice. FR-5. *Arrêté.*
- **Permissions** : voters Symfony sur un modèle à trois entités — permission (code
  d'action stable), rôle avec ses permissions par défaut, surcharge par utilisateur
  (permission, ajout ou retrait) qui prime sur le rôle. Raison : afficher l'origine de
  chaque permission (FR-9) exige de stocker l'héritage et la surcharge séparément ;
  Odoo est le seul produit trouvé qui autorise le retrait par utilisateur, et il le
  fait ainsi. FR-7 à FR-10. *Arrêté.*
- **Forme des codes de permission** : **tranché le 2026-09-10 avec OQ-5.** Le code reste
  une chaîne stable, mais sa forme par défaut est `<ressource>.create`, `.read`,
  `.update`, `.delete`. Une action que le CRUD ne décrit pas garde un code nommé :
  `user.invite`, `user.anonymize`, `audit.export`, `roadmap.launch`, `roadmap.notes`.
  Le socle et chaque module déclarent leurs ressources et les opérations qu'elles
  supportent ; la grille des écrans de rôle et de surcharge s'en dérive, et le catalogue
  se projette en base comme prévu par AD-8. Le modèle à trois entités ci-dessus est
  inchangé : la forme CRUD est une convention sur le code, pas une quatrième entité.
  FR-7 à FR-9. *Arrêté.*
- **Archivage du journal d'audit** : **tranché le 2026-09-10 avec OQ-2.** Fenêtre en
  ligne d'un mois, réglée par un paramètre `app.` ; au-delà, les entrées passent dans un
  magasin d'archive que la lecture de FR-13 interroge de façon transparente. Aucune
  suppression, et le déplacement ne réécrit pas les entrées — il ne relève donc pas de
  l'exception d'immuabilité que porte l'anonymisation (AD-12). Le déplacement est une
  commande planifiée et verrouillée (`symfony-proglab-console`,
  `symfony-proglab-async`). Le budget p95 du §5 ne couvre que la fenêtre en ligne :
  la mécanique d'index et de résolution par lot d'AD-20 s'applique à la table en ligne,
  l'archive assume une lecture plus lente et l'annonce à l'écran. FR-23. *Arrêté.*
- **API** : sans API Platform (addendum du brief). Jeton d'accès opaque stocké en base,
  révocable par l'utilisateur et par un administrateur — la révocation exigée par
  FR-17 exclut un JWT sans état. FR-17, FR-18. *Arrêté.*
- **Langues** : composant Translation de Symfony, catalogues FR/EN/NL ; la langue de
  l'utilisateur est un champ du compte ; les langues actives du dérivé sont une
  configuration modifiable depuis l'interface. FR-19, FR-22. *Arrêté.*
- **Journal d'audit — actions métier** : service dédié appelé depuis la couche
  service ; chaque entrée porte un code d'action stable, un libellé traduisible et
  l'objet concerné. Contrat fixé par FR-12. *Arrêté.*

### Tranchés en architecture le 2026-09-10

- **Invitation** : **tranché le 2026-09-10 — AD-15.** Entité maison portant
  destinataire, rôle prévu, jeton aléatoire stocké haché, date d'expiration et date
  d'usage ; l'URL transporte le jeton en clair, la base n'en garde que l'empreinte. Le
  même mécanisme sert la réinitialisation de mot de passe. Aucune signature d'URL sans
  état : ni `symfonycasts/verify-email-bundle` ni les Login Links ne peuvent marquer un
  lien consommé, invalider le précédent au renvoi, ou porter les états « en attente » et
  « expirée » que FR-4 montre à l'administrateur. FR-3, FR-4. *Arrêté.*
- **Journal d'audit — modifications** : **tranché le 2026-09-10 — AD-3.** Couche maison,
  un listener Doctrine `onFlush` qui ne traduit rien d'autre qu'un changeset et appelle
  un service portant toutes les règles, écrivant dans la même table que les actions
  métier de FR-12. Aucun bundle : `damienharper/auditor-bundle` 7.x exige Symfony 8 (et
  sa branche 6.3 serait une branche de maintenance dès le premier jour),
  `rcsofttech/audit-trail-bundle` est trop jeune pour porter la traçabilité clonée chez
  chaque client, et Gedmo Loggable est opt-in par entité. Raison décisive : aucun bundle
  ne couvre FR-12, donc une couche maison existe de toute façon, et FR-13 exige de
  filtrer, paginer et exporter les deux types d'entrées ensemble. FR-11, FR-12, FR-13.
  *Arrêté.*

## Roadmap : contrat d'entrée BMAD

- Source : `_bmad-output/implementation-artifacts/sprint-status.yaml`, généré par
  `bmad-sprint-planning` à partir des epics. Clés observées : `epic-{n}`,
  `{n}-{m}-{titre}` pour une story, `epic-{n}-retrospective`. Les fichiers d'epics et
  de stories vivent dans `_bmad-output/planning-artifacts/` et
  `_bmad-output/implementation-artifacts/`. Forme exacte à confirmer sur les premiers
  fichiers produits par ce projet.
- Statuts BMAD observés : les cinq de FR-15 ; jamais de rétrogradation.
- Priorité, difficulté, assignation, dépendances : **tranché le 2026-09-10 — AD-10.**
  Le socle possède ce contrat plutôt que de le deviner : il définit et documente la
  convention d'en-tête YAML attendue dans les stories, lit ces champs quand ils y sont,
  les rend « non renseigné » sinon, et considère qu'une tâche sans dépendance déclarée
  est prête dès que son statut est « à faire ». Les clés `epic-{n}-retrospective` sont
  exclues du calcul d'avancement des epics. OQ-3 est donc close.

## Lancer une tâche (environnement de développement)

- En production, le contrôleur n'est pas enregistré du tout — FR-16 exige
  « impossible », pas « masqué ».
- Mécanisme pressenti : la commande de l'agent (Claude Code avec `bmad-build` sur la
  story) est lancée dans un processus local détaché ; l'application ne fait que
  démarrer le processus et rendre compte du démarrage ou de son échec (FR-16).
