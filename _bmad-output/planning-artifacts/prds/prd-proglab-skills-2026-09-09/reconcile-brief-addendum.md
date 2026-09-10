# Réconciliation : addendum du brief → PRD

Sources lues : `briefs/brief-proglab-skills-2026-09-09/addendum.md` (source),
`prds/prd-proglab-skills-2026-09-09/prd.md` et `prds/prd-proglab-skills-2026-09-09/addendum.md`
(cibles).

## Couvert (bref, liste)

- **Roadmap, fichiers BMAD seule vérité** — décision et conséquences PRD reprises :
  Glossaire « Roadmap », FR-14, FR-15, FR-16, UJ-3, UJ-5, Non-objectifs (pas de
  modification depuis l'interface, pas de commentaires client). Conséquence
  architecture (parseur tolérant) reprise dans l'addendum PRD, section « Roadmap :
  format BMAD et correspondance des statuts ».
- **Déclenchement des agents, local uniquement** — décision reprise via Non-objectifs
  (« L'exécution d'agents côté serveur »), FR-16, Contraintes et garde-fous
  (« jamais en production »), et l'addendum PRD section « Lancer une tâche » qui
  reprend même le « Parqué » (exécuteur d'agents côté serveur / file de travaux)
  quasi mot pour mot.
- **Droits, rôle par défaut + surcharge par utilisateur** — décision et raison
  (surcharge en ajout ou en retrait) reprises : FR-8, FR-9. Conséquence architecture
  (voters Symfony, modèle à trois niveaux) reprise dans l'addendum PRD section
  « Choix techniques déjà arrêtés » ; le PRD va même plus loin avec le glossaire
  « Origine d'une permission » (héritée / ajoutée / retirée).
- **Audit, deux niveaux consultables par le client** — conséquence PRD (le client
  consultant le journal, filtrage par droits, lisibilité non technique) reprise dans
  FR-7 (permission Admin), FR-13. Le mécanisme d'enregistrement des actions métier
  depuis la couche service est repris dans FR-12 et dans l'addendum PRD.
- **API à la main, sans API Platform** — décision reprise dans l'addendum PRD, section
  « Choix techniques déjà arrêtés » ; conforme à la règle du PRD (§0) qui garde les
  choix techniques hors du corps du PRD.
- **Multi-tenant hors périmètre** — cohérent avec le Non-objectif « Le multi-tenant »
  du PRD ; le bundle cité dans le paysage des solutions n'a pas à être répété (destiné
  à l'architecture, hors des deux documents cibles).
- **Paysage des solutions (audit)** — les candidats de bundles (damienharper,
  rcsofttech, Gedmo écarté) sont repris dans l'addendum PRD avec les mêmes
  conclusions, bien que ce paysage soit explicitement destiné à l'architecture selon
  le brief ; la reprise partielle dans l'addendum PRD est utile et non contradictoire.

## Écarts

1. **Ce que dit l'addendum (section « Données de coût »)** : la « semaine » de
   démarrage citée dans le brief est une estimation de Fabrice, non mesurée.
   **Ce que fait le PRD** : la Vision (§1, ligne « Chaque ERP custom livré à une PME
   commence par la même semaine de travail ») énonce ce chiffre comme un fait, sans
   balise `[ASSUMPTION]` ; la métrique SM-1 fixe ensuite une cible dure (« moins d'une
   journée de travail ») sur cette base non mesurée, et l'Index des hypothèses (§11)
   ne la liste pas.
   **Gravité** : important — une métrique de succès primaire (SM-1) est construite sur
   une donnée que le brief qualifie lui-même de non mesurée, sans que le PRD ne le
   signale.
   **Proposition** : ajouter `[ASSUMPTION: durée de référence « une semaine » non
   mesurée, estimation de Fabrice]` au §1 et l'indexer en §11.

2. **Ce que dit l'addendum (section « Données de coût »)** : équipe en freelances ;
   Fabrice considère le socle rentabilisé dès le premier dérivé (non mesuré).
   **Ce que fait le PRD** : aucune trace — ni dans les Métriques de succès (§9), ni
   dans les Contraintes (§6), ni ailleurs (recherche « freelance », « rentabil »,
   « non mesuré » : aucun résultat dans prd.md ni addendum.md).
   **Gravité** : important — cette donnée est explicitement étiquetée « → PRD » dans
   le routage de l'addendum du brief (« données de coût → PRD ») et n'apparaît nulle
   part dans le document cible.
   **Proposition** : soit ajouter une métrique secondaire ou une note de contexte en
   §9 (« rentabilité attendue dès le premier dérivé, non mesurée »), soit documenter
   explicitement que cette donnée est écartée du PRD (mode d'équipe hors périmètre
   produit) et le justifier en une ligne.

3. **Ce que dit l'addendum (section « Roadmap », Conséquences PRD)** : « le
   déploiement embarque `_bmad-output/` ».
   **Ce que fait le PRD** : cette phrase précise n'apparaît que dans l'addendum PRD
   (section « Roadmap : format BMAD... », destiné à l'architecture), pas dans prd.md.
   Le PRD lui-même reste implicite : FR-14 parle de « fichiers BMAD embarqués dans le
   dépôt » et UJ-3/FR-14 couvrent la fraîcheur (date du dernier déploiement), mais
   l'affirmation explicite que le déploiement embarque `_bmad-output/` — étiquetée
   « Conséquence (PRD) » dans le brief — n'est donc pas au bon endroit.
   **Gravité** : mineur — le sens fonctionnel (fraîcheur, pas de temps réel) est bien
   présent dans le PRD ; seule la formulation technique précise est placée dans le
   mauvais document.
   **Proposition** : ajouter une phrase courte dans le PRD (§4.5 ou §6) confirmant
   que le déploiement embarque `_bmad-output/`, ou accepter le placement actuel si le
   PM juge la nuance purement technique.

4. **Ce que dit l'addendum (section « Audit », Conséquence architecture)** : « les
   actions métier explicites sont déclenchées depuis les services, jamais depuis les
   contrôleurs. »
   **Ce que fait le PRD** : FR-12 reprend « L'action est déclarée en un appel depuis
   la couche service » et l'addendum PRD reprend « service dédié appelé depuis la
   couche service » — mais la contrainte négative explicite (« jamais depuis les
   contrôleurs ») n'est formulée nulle part, ni dans prd.md ni dans l'addendum PRD.
   **Gravité** : mineur — le sens positif est présent (déclenché depuis les services),
   seule l'interdiction explicite manque, et elle relève probablement autant de
   l'architecture que du PRD.
   **Proposition** : ajouter la formulation négative dans l'addendum PRD (section
   « Choix techniques déjà arrêtés » ou « Journal d'audit — actions métier ») pour
   éviter toute ambiguïté côté architecture.

## Contradictions

Aucune contradiction de fond identifiée entre l'addendum du brief et les deux
documents cibles. Les seuls écarts relevés sont des omissions (données de coût,
§ Écarts 1-2) ou des pertes de précision/placement (§ Écarts 3-4) ; aucune décision,
raison ou conséquence de l'addendum du brief n'est reprise avec un sens inversé ou
incompatible dans le PRD ou son addendum.
