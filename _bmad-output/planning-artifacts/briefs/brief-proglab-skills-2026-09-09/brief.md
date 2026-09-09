---
title: Socle ERP custom (proglab)
status: final
created: 2026-09-09
updated: 2026-09-09
---

# Product Brief : Socle ERP custom (proglab)

## Résumé exécutif

Chaque ERP custom livré à une PME commence par la même semaine de travail — connexion,
utilisateurs, droits, journal d'audit — refaite à la main, et refaite différemment à
chaque fois. Ce projet la fait une bonne fois : un socle Symfony, construit selon la
suite de skills `symfony-proglab-*` et piloté par BMAD, que l'on clone au démarrage de
chaque ERP et que l'on fait diverger librement.

Le socle n'est pas le produit vendu. Ce que la PME achète, c'est son ERP : plus simple
que l'ERP généraliste qu'elle subit, modelé sur ses process, et visible en construction
dès les premiers jours grâce à une roadmap lue directement dans les artefacts BMAD du
projet. L'avantage n'est pas technique : c'est une base de clients qui demande déjà cet
outil, l'adéquation aux process, et une méthode qui rend la qualité répétable quand
l'équipe passe d'une personne à cinq.

La première version se limite au strict nécessaire pour dériver le premier ERP —
comptes, droits, audit, roadmap BMAD, amorce d'API, documentation pour les agents
(détail en Périmètre). Le reste s'ajoute par extraction depuis les dérivés. Objectif
mesurable : du clonage du socle à la première fonctionnalité spécifique en moins d'une
journée, contre une semaine aujourd'hui.

## Le problème

Une semaine de socle avant d'écrire la première ligne qui appartient réellement au
client — et une semaine pendant laquelle le client ne voit rien qui lui ressemble.

Cette semaine ne se répète pas seulement, elle se répète *différemment* : refaite à la
main à chaque projet, elle produit des socles cousins mais jamais identiques, chacun
avec ses écarts de conventions, de sécurité et de qualité. Quand l'équipe grandira,
chaque nouveau développeur héritera d'un socle légèrement différent selon le projet sur
lequel il arrive.

Le second coût est moins visible : le suivi. La roadmap d'un ERP custom vit aujourd'hui
dans les artefacts de planification (epics, stories BMAD) que seul le développeur lit.
Le client n'a pas de vue simple sur « où en est mon ERP ».

## La solution

Le socle est le point de départ de chaque projet : un dépôt Symfony que l'on clone et
que l'on fait diverger librement. Il contient déjà ce que tous les ERP ont en commun —
connexion, utilisateurs, rôles et droits par utilisateur, journal de qui a fait quoi et
quand, roadmap visible par l'équipe et par le client. Les modules communs s'y
ajouteront à mesure que les dérivés les révéleront. Le travail spécifique commence le
premier jour, sur une base dont les conventions, la sécurité et la qualité sont les
mêmes d'un projet à l'autre.

Ce socle est construit et dérivé avec une méthode, pas seulement du code : la suite de
skills `symfony-proglab-*` — le standard proglab — fixe *comment* on écrit, BMAD fixe
*quoi* et *dans quel ordre*. Un développeur qui rejoint un projet dérivé retrouve les
mêmes couches, les mêmes règles et les mêmes artefacts de planification que sur tous
les autres.

## Ce qui rend ce socle différent

L'avantage n'est pas technique et le brief ne prétend pas le contraire. Il tient à trois
choses :

- **Une base de clients existante.** Déjà équipée d'un ERP généraliste type Odoo, elle
  le trouve trop lourd et trop éloigné de sa façon de travailler. La demande précède
  l'offre.
- **L'adéquation aux process.** L'ERP dérivé est modelé sur les habitudes réelles du
  client, pas l'inverse. Un ERP paramétrable oblige le client à se plier au produit ;
  ici c'est le produit qui se plie.
- **La simplicité de prise en main.** Elle est possible parce que l'outil ne couvre que
  ce dont le client a besoin.

La vitesse d'exécution — la semaine commune déjà faite — rend ces trois points
rentables ; la méthode décrite plus haut les rend répétables.

## À qui il sert

**La PME cliente.** Elle n'a pas de secteur type : la base de clients est trop diverse
pour un portrait, et ce qui la définit est dit à la section précédente. Sa réussite : un
outil que ses équipes prennent en main sans formation lourde, qui reflète ses process
au lieu de les dicter, et dont elle voit la construction avancer dès les premiers jours.

**Le développeur qui dérive.** Aujourd'hui Fabrice seul ; demain des freelances
seniors, parfois un junior, de plus en plus nombreux à mesure que les ERP se
multiplient. Sa réussite : ouvrir le dépôt dérivé et, sans poser de question, ajouter
une epic ou une tâche à la roadmap, prendre la prochaine tâche selon sa priorité et ses
dépendances, et livrer une story conforme au standard proglab dès la première semaine.

**Le pilote.** Fabrice suit chaque ERP depuis la roadmap propre à ce dérivé.

## Critères de succès

- **Vitesse de démarrage** : du clonage du socle à la première fonctionnalité
  spécifique livrée au client, moins d'une journée — contre une semaine aujourd'hui,
  semaine facturée ou réinvestie dès le premier dérivé.
- **Visibilité client** : le client se connecte et voit la roadmap de son ERP au plus
  tard deux jours après le démarrage du projet.
- **Autonomie de l'équipe** : un nouveau développeur livre une story conforme au
  standard proglab, sans relecture bloquante, dans sa première semaine sur un dérivé.

## Périmètre

Le socle se construit au plus vite, avec juste ce qu'il faut pour dériver le premier
ERP, et s'améliore ensuite par extraction : ce qu'un dérivé refait deux fois remonte
dans le socle. Aucun module commun n'est engagé dans la première version tant qu'un
dérivé ne l'a pas réclamé deux fois — une gestion documentaire client (pièces jointes,
dossiers) est le premier candidat pressenti. On commence petit.

### Dans la première version

- **Connexion et comptes.** Comptes locaux (email + mot de passe), invitation par email,
  double authentification.
- **Utilisateurs et droits.** Des rôles portant des permissions par défaut ; des
  permissions par utilisateur qui priment sur celles du rôle, en ajout comme en
  retrait.
- **Journal d'audit.** Deux niveaux : automatique sur les modifications d'entités
  (qui, quand, avant/après) et explicite sur les actions métier (« a validé X »).
  Consultable par le client.
- **Roadmap.**
  - Source de vérité : les fichiers BMAD du dépôt (`_bmad-output/`), statut inclus.
    Les epics et les tâches (stories BMAD) y sont créées et suivies, même en cours de
    projet ; l'application ne fait que les lire.
  - Ce qui est montré, au client comme à l'équipe : les epics et leur avancement, les
    tâches, leur priorité, leur difficulté, leurs dépendances et qui travaille dessus.
  - Comportement : sur le poste d'un développeur, « lancer une tâche » déclenche les
    agents qui la réalisent et mettent à jour les fichiers ; en production, chez le
    client, la roadmap est en lecture seule.
- **Début d'API.** Endpoints JSON écrits à la main pour préparer une future application
  mobile : authentification et health check.
- **Documentation du socle.** Complète, pensée d'abord pour que les agents (BMAD,
  skills proglab) s'y retrouvent dans un dérivé sans Fabrice.

### Explicitement hors périmètre

- Une interface commune à plusieurs ERP.
- Le multi-tenant : un ERP, un client, un déploiement.
- La facturation et la comptabilité intégrées.
- L'application mobile elle-même — seule l'API qui la prépare est dans le socle.

### Contraintes

- Symfony, selon le standard proglab (`symfony-proglab-*`) ; planification et
  exécution pilotées par BMAD.
- Un ERP par client, déployé séparément.
- API écrite à la main ; pas d'API Platform tant que le contrat reste réduit.
- Le socle se clone et diverge : pas de noyau partagé mis à jour dans les dérivés.

## Risque assumé

Le socle n'est pas mis à jour dans les dérivés déjà clonés : chaque dérivé diverge et
les correctifs (sécurité, dépendances) se reportent à la main. Cette trajectoire porte
une tension que le brief assume plutôt qu'il ne la cache : des centaines de dépôts qui
divergent librement, c'est une dette de maintenance qui grandit avec le succès. La
règle « on clone et on diverge » est la bonne pour démarrer vite ; elle sera à
réexaminer — noyau partagé, outillage de report des correctifs — dès que les dérivés se
compteront en dizaines.

## Vision

Dans deux à trois ans, si le socle tient sa promesse : des centaines d'ERP dérivés en
service chez des PME, construits et entretenus par une équipe de cinq développeurs
freelances. Chaque ERP a démarré en une journée sur la même base, avec les mêmes
conventions, et son client a suivi sa construction depuis le premier jour dans son
propre outil. Le socle reste interne : il n'est ni vendu ni ouvert, c'est l'avantage de
l'équipe, pas un produit.

Le client, dans un premier temps, suit. Plus tard, il pourra demander une évolution
depuis sa roadmap — mais cette version ne le prépare pas.
