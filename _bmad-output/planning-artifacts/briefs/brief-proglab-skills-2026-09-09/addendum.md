---
title: Addendum au brief — Socle ERP custom (proglab)
status: final
created: 2026-09-09
updated: 2026-09-09
---

# Addendum au brief — Socle ERP custom (proglab)

Détails discutés pendant le brief, utiles aux documents suivants (PRD, architecture)
mais sans place dans le brief lui-même. `brief.md` porte la décision ; ce document
porte sa raison. Qui lit quoi : décisions → PRD et architecture ; paysage des solutions
existantes → architecture ; données de coût → PRD.

## Décisions déjà prises et leur raison

Chaque décision suit le même schéma : **Écarté** (si une alternative a été examinée),
**Raison**, **Conséquences** (étiquetées par destinataire), **Parqué**.

### Roadmap : les fichiers BMAD sont la seule vérité

- **Écarté** : import en base et annotation dans l'appli (statut, assignation,
  commentaires client). Plus riche, mais crée deux vérités (BMAD tient déjà son suivi
  de statut dans `_bmad-output/`) et suppose que l'appli écrive dans le dépôt —
  impossible en production.
- **Conséquences (PRD)** : le déploiement embarque `_bmad-output/` ; la roadmap en
  production reflète le dernier déploiement, pas le temps réel ; ses utilisateurs en
  production ne sont pas techniciens — lecture seule, vocabulaire sans jargon BMAD.
- **Conséquence (architecture)** : le format des fichiers BMAD (epics, stories, sprint
  status) est un contrat d'entrée, à parser de façon tolérante.

### Déclenchement des agents : local uniquement

- **Raison** : les agents ont besoin du dépôt, des skills et des clés API ; un
  exécuteur côté serveur ne se justifie qu'avec assez de dérivés pour l'amortir.
- **Parqué** : file de travaux côté serveur dépilée en local, ou exécuteur d'agents,
  quand le volume le justifiera.

### Droits : rôle par défaut, surcharge par utilisateur

- **Raison** : la surcharge joue en ajout comme en retrait — un utilisateur peut
  recevoir plus ou moins que son rôle.
- **Conséquence (architecture)** : voters Symfony ; le modèle de données distingue
  « hérité du rôle » et « surchargé ».

### Audit : deux niveaux, consultables par le client

- **Conséquence (architecture)** : les actions métier explicites sont déclenchées
  depuis les services, jamais depuis les contrôleurs.
- **Conséquence (PRD)** : le client consultant le journal, le filtrage par droits et
  la lisibilité non technique sont des exigences, pas des options.

### API : à la main, sans API Platform

- **Raison** : standard proglab — pas d'API Platform tant que le contrat d'API reste
  réduit (authentification et sanity check).

## Paysage des solutions existantes (recherche web du 2026-09-09, à revérifier avant usage)

- **Aucun socle ERP Symfony 7/8 maintenu et dérivable** trouvé : iMOControl (Sonata)
  est abandonné depuis 2013 ; Sylius est le seul modèle sérieux de « core +
  plugins-bundles » mais orienté e-commerce (v2.3.0-alpha, Symfony 6.4/7.4/8.0,
  PHP ≥ 8.3).
- **Journal d'audit** :
  - `damienharper/auditor-bundle` — listeners Doctrine, UI `/audit` ; 7.x exige
    Symfony 8.0 / PHP 8.4 / DBAL 4 / ORM 3.2.
  - `rcsofttech/audit-trail-bundle` — onFlush/postFlush, transports DB/queue/HTTP,
    masquage ; Symfony 7.4/8.0, PHP ≥ 8.4 ; jeune, adoption modeste.
  - Gedmo Loggable — incompatible DBAL ≥ 4 : à écarter.
  - Aucun bundle ne couvre le journal d'actions métier explicites : couche à écrire
    dans les services.
- **Multi-tenant** (hors périmètre, pour mémoire) : `hakam/multi-tenancy-bundle`
  (base par client, Symfony 6.4/7.4/8.0).

## Données de coût

- La « semaine » de démarrage citée dans le brief (environ une semaine avant la
  première ligne de code spécifique au client) est une estimation de Fabrice, non
  mesurée.
- Équipe : freelances. Fabrice considère le socle rentabilisé dès le premier dérivé
  (non mesuré).
