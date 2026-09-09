---
title: Addendum au PRD — Socle ERP custom (proglab)
status: final
created: 2026-09-09
updated: 2026-09-09
---

# Addendum au PRD — Socle ERP custom (proglab)

Choix techniques et détails discutés pendant la rédaction du PRD, destinés à
l'architecture. Le PRD dit quoi ; ce document dit avec quoi et pourquoi. L'addendum du
brief (`../../briefs/brief-proglab-skills-2026-09-09/addendum.md`) reste valable et
n'est pas répété.

## Décision ouverte en tête : la version de Symfony du socle

Non fixée. Elle conditionne le bundle de journal d'audit (voir ci-dessous) et la
compatibilité de `scheb/2fa` (Symfony 7.4/8, PHP 8.4). À trancher en premier en
architecture.

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
- **API** : sans API Platform (addendum du brief). Jeton d'accès opaque stocké en base,
  révocable par l'utilisateur et par un administrateur — la révocation exigée par
  FR-17 exclut un JWT sans état. FR-17, FR-18. *Arrêté.*
- **Langues** : composant Translation de Symfony, catalogues FR/EN/NL ; la langue de
  l'utilisateur est un champ du compte ; les langues actives du dérivé sont une
  configuration modifiable depuis l'interface. FR-19, FR-22. *Arrêté.*
- **Journal d'audit — actions métier** : service dédié appelé depuis la couche
  service ; chaque entrée porte un code d'action stable, un libellé traduisible et
  l'objet concerné. Contrat fixé par FR-12. *Arrêté.*

### À trancher en architecture

- **Invitation** : aucun bundle maintenu pour Symfony 7/8. Entité maison (email, rôle,
  jeton, expiration, usage unique) et URL signée ; `symfonycasts/verify-email-bundle`
  ou les Login Links de Symfony sont candidats pour signer l'URL. FR-4. *À trancher :
  le mécanisme de signature.*
- **Journal d'audit — modifications** : candidats et contraintes de version dans
  l'addendum du brief (§Paysage) ; Gedmo Loggable écarté : il reste lié à DBAL 3.
  FR-11. *À trancher selon la version de Symfony du socle.*

## Roadmap : contrat d'entrée BMAD

- Source : `_bmad-output/implementation-artifacts/sprint-status.yaml`, généré par
  `bmad-sprint-planning` à partir des epics. Clés observées : `epic-{n}`,
  `{n}-{m}-{titre}` pour une story, `epic-{n}-retrospective`. Les fichiers d'epics et
  de stories vivent dans `_bmad-output/planning-artifacts/` et
  `_bmad-output/implementation-artifacts/`. Forme exacte à confirmer sur les premiers
  fichiers produits par ce projet.
- Statuts BMAD observés : les cinq de FR-15 ; jamais de rétrogradation.
- Priorité, difficulté, assignation, dépendances : voir OQ-3 ; si absentes des
  fichiers BMAD, convention d'en-tête YAML dans les stories du projet.

## Lancer une tâche (environnement de développement)

- En production, le contrôleur n'est pas enregistré du tout — FR-16 exige
  « impossible », pas « masqué ».
- Mécanisme pressenti : la commande de l'agent (Claude Code avec `bmad-build` sur la
  story) est lancée dans un processus local détaché ; l'application ne fait que
  démarrer le processus et rendre compte du démarrage ou de son échec (FR-16).
