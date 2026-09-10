# Réconciliation : brief.md → PRD

Sources : `brief.md` (brief-proglab-skills-2026-09-09), `prd.md` et `addendum.md`
(prd-proglab-skills-2026-09-09). Comparaison ligne à ligne du brief contre le PRD et
son addendum.

## Couvert (bref, liste)

- Objectif « moins d'une journée » du clonage à la première fonctionnalité spécifique
  (brief l.27-29 → PRD SM-1, FR-1, FR-2).
- « Visibilité client » à deux jours (brief l.99-100 → PRD SM-2, formulation quasi
  identique).
- « Autonomie de l'équipe » : story conforme au standard proglab sans relecture
  bloquante dans la première semaine (brief l.101-102 → PRD SM-3).
- Connexion et comptes : comptes locaux, invitation, 2FA (brief l.114-115 → FR-3 à
  FR-5).
- Utilisateurs et droits : rôles + permissions par défaut + surcharges en ajout/retrait
  (brief l.116-118 → FR-7, FR-8, FR-9).
- Journal d'audit à deux niveaux, consultable par le client (brief l.119-121 → FR-11 à
  FR-13).
- Roadmap lue depuis les fichiers BMAD, en lecture seule en production, « lancer une
  tâche » réservé au poste développeur (brief l.122-130 → FR-14 à FR-16).
- Amorce d'API : authentification + health check (brief l.131-132 → FR-17, FR-18).
- Documentation pensée pour les agents, développeur autonome « sans Fabrice » (brief
  l.133-134 → FR-2, et repris quasi mot pour mot dans le JTBD « comprendre où sont les
  choses sans demander », PRD §2.1).
- Hors périmètre : interface commune, multi-tenant, facturation/comptabilité,
  application mobile (brief l.138-141 → PRD §7).
- Contrainte « pas de noyau partagé mis à jour dans les dérivés » (brief l.149 → PRD §7
  « La mise à jour du socle dans les dérivés déjà clonés »).
- Report explicite du dialogue client depuis la roadmap (brief l.170-171 → PRD §8.2,
  qui cite le brief).
- Candidat de module commun (gestion documentaire) et règle d'extraction (brief
  l.108-110 → PRD §8.2 avec `[NOTE FOR PM]`).

## Écarts

1. **Trois rôles par défaut nommés et leurs permissions exactes.**
   - Brief (l.116-118) : décrit seulement le mécanisme — rôles portant des permissions
     par défaut, surcharges par utilisateur. Aucun nom de rôle, aucune liste de
     permissions par défaut n'est fixé.
   - PRD : FR-7 fixe trois rôles nommés (Super admin, Admin, User) avec un périmètre de
     permissions précis, présenté comme acquis — sans balise `[ASSUMPTION]`, alors que
     §0 du PRD annonce que « les hypothèses non confirmées sont balisées
     `[ASSUMPTION]` ».
   - Gravité : mineur.
   - Proposition : marquer les permissions par défaut des rôles Admin/User comme
     `[ASSUMPTION]` (cohérent avec OQ-5, qui pose déjà la question de leur suffisance
     sans que FR-7 le signale).

2. **2FA obligatoire pour tous, y compris le rôle User, dès le premier accès.**
   - Brief (l.114-115) : liste « double authentification » comme fonctionnalité, sans
     préciser si elle est optionnelle ou obligatoire, ni pour qui.
   - PRD : FR-5 la rend obligatoire pour *chaque* utilisateur, bloquant tout autre écran
     tant qu'elle n'est pas activée — alors que le PRD lui-même décrit le JTBD de
     « L'utilisateur de la PME » comme vouloir « se connecter sans friction » (§2.1).
     Cette exigence, plus dure que ce que dit le brief, n'est pas balisée
     `[ASSUMPTION]` (seules les méthodes le sont).
   - Gravité : mineur.
   - Proposition : soit tagger l'obligation elle-même en `[ASSUMPTION]`, soit
     documenter explicitement pourquoi elle est jugée non négociable (sécurité) malgré
     la tension avec le JTBD « sans friction ».

3. **Nuance « tant que le contrat reste réduit » sur l'absence d'API Platform.**
   - Brief (l.148) : « pas d'API Platform tant que le contrat reste réduit » — une
     contrainte conditionnelle, révisable si l'API grossit.
   - Addendum : « endpoints à la main, sans API Platform (standard proglab) » — énoncé
     comme un choix définitif du standard, sans reprendre la clause de révision.
   - Gravité : mineur.
   - Proposition : ajouter la condition de révision dans l'addendum, pour ne pas figer
     un choix que le brief présente comme temporaire.

4. **Persona « Le pilote » (Fabrice suivant chaque ERP) disparaît du glossaire et des
   personas.**
   - Brief (l.92) : traite « Le pilote » comme une des trois catégories d'utilisateurs
     à qui le socle sert, au même titre que la PME cliente et le développeur.
   - PRD §2.1/§3 : ce rôle est absorbé sans le nommer dans « L'administrateur du
     dérivé (Fabrice ou un développeur au démarrage...) » ; le mot « pilote » n'apparaît
     nulle part dans le PRD, ni dans le glossaire.
   - Gravité : mineur.
   - Proposition : soit ajouter « Pilote » au glossaire comme synonyme du rôle Super
     admin quand il est joué par Fabrice, soit noter explicitement le regroupement pour
     que le lecteur du PRD retrouve le terme du brief.

5. **Règle d'extraction « réclamé deux fois » vs. `[NOTE FOR PM]` « au deuxième dérivé ».**
   - Brief (l.106-110) : « ce qu'un dérivé refait deux fois remonte dans le socle » /
     « tant qu'un dérivé ne l'a pas réclamé deux fois » — ambiguë dans le brief déjà
     (une même occurrence répétée deux fois, ou deux dérivés distincts qui la
     réclament chacun une fois ?).
   - PRD §8.2 : reprend mot pour mot « dès qu'un dérivé l'aura réclamé deux fois » puis
     ajoute une note contradictoire en apparence : « à revisiter au deuxième dérivé »,
     qui suggère un déclencheur différent (l'arrivée du deuxième projet dérivé, quel
     que soit le nombre de demandes).
   - Gravité : mineur.
   - Proposition : lever l'ambiguïté explicitement dans le PRD (par ex. « réclamé par
     deux dérivés distincts, ou deux fois dans un même dérivé ») plutôt que de la
     reconduire.

## Contradictions

- Aucune contradiction frontale (le PRD n'affirme rien que le brief nie explicitement).
  Le point le plus proche d'une contradiction interne est le nº 5 ci-dessus
  (« deux fois » vs « au deuxième dérivé »), et la tension nº 2 entre le JTBD « sans
  friction » et la 2FA obligatoire dès le premier accès — deux tensions internes au
  PRD qui prolongent une ambiguïté déjà présente dans le brief plutôt que de la
  trancher.

## Idées qualitatives perdues

1. **Le risque assumé du « clone et diverge » disparaît entièrement.**
   Le brief consacre une section entière (« Risque assumé », l.151-159) à admettre
   sans le cacher que l'absence de noyau partagé crée une dette de maintenance
   croissante, et fixe un déclencheur explicite de révision : « dès que les dérivés se
   compteront en dizaines ». Le PRD ne reprend cette idée que sous la forme neutre d'un
   non-objectif (§7 : « La mise à jour du socle dans les dérivés déjà clonés ») — sans
   le risque assumé, sans la mise en garde, et surtout sans le seuil de révision
   (« des dizaines de dérivés »). Un lecteur du seul PRD croira le choix figé et
   n'aura aucun signal de quand le revisiter. **Gravité : important.**
   Proposition : ajouter une section « Risques » (ou l'intégrer à §6 Contraintes et
   garde-fous) qui reprend le risque de dette de maintenance et son seuil de révision.

2. **« La simplicité de prise en main » comme conséquence d'un périmètre volontairement
   restreint.**
   Le brief (l.73-74) énonce une causalité précise : la simplicité est *possible parce
   que* l'outil ne couvre que ce dont le client a besoin — c'est un principe de conception,
   pas juste un adjectif. Le PRD garde « plus simple » dans sa Vision (§1) mais comme
   qualificatif isolé ; le lien de cause à effet avec la discipline de périmètre
   (SM-C1, la contre-métrique sur la taille du socle) n'est jamais explicité comme
   traduisant ce principe. **Gravité : mineur à important selon l'usage qu'en fera
   l'architecture.**

3. **« Sans formation lourde » comme critère de réussite pour la PME cliente.**
   Brief (l.83-84) : critère de succès explicite pour la PME cliente : « un outil que
   ses équipes prennent en main sans formation lourde ». Ce critère n'est ni repris
   littéralement, ni traduit en exigence testable ou en métrique dans le PRD (les NFR
   couvrent l'accessibilité WCAG et la performance, pas la facilité de prise en main
   sans formation). **Gravité : mineur.**

4. **La logique économique derrière la vitesse de démarrage.**
   Brief (l.97-98) : « une semaine facturée ou réinvestie dès le premier dérivé » — la
   vitesse de démarrage a une valeur économique directe et immédiate (dès le premier
   dérivé, pas seulement à terme). Le PRD reprend le seuil chiffré (SM-1) mais perd
   cette justification économique, ce qui affaiblit la priorité perçue de cette
   métrique face aux autres. **Gravité : mineur.**

5. **« Le socle reste interne : il n'est ni vendu ni ouvert. »**
   Brief (l.167-168, section Vision) : précise explicitement que le socle n'est pas un
   produit commercialisé, seulement un avantage interne à l'équipe. Cette clarification
   stratégique — pertinente pour cadrer toute décision future d'ouverture, de
   licence ou de packaging — n'apparaît nulle part dans le PRD (ni en Vision §1, ni en
   non-objectifs §7). **Gravité : mineur.**
