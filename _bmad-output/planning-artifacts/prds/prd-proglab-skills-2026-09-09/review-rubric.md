# PRD Quality Review — Socle ERP custom (proglab)

Enjeu retenu : outil interne qui sert aussi de base à une offre commerciale ; cible cinq
à huit pages. Documents lus : `prd.md`, `addendum.md`, et le brief
(`../../briefs/brief-proglab-skills-2026-09-09/brief.md`) pour vérifier la cohérence de
périmètre.

## Verdict global

Le PRD tient : il a une thèse claire (la semaine commune faite une fois, le spécifique
dès le premier jour, le client qui voit sa roadmap), vingt FR dont l'immense majorité ont
des conséquences réellement testables, un glossaire qui est utilisé, et des non-objectifs
qui font du vrai travail. Il est prêt à alimenter l'architecture et les epics après trois
corrections ciblées : lever la contradiction entre le PRD et l'addendum sur *qui écrit*
les fichiers BMAD au lancement d'une tâche (FR-16), définir ce qu'est le « tableau de
bord » que FR-1 et UJ-1 promettent, et assumer explicitement deux choix qui pèsent sur
chaque utilisateur PME — la 2FA obligatoire pour tous et les trois langues, ce dernier
absent du brief. Aucune constatation critique.

## Décisionnabilité — adéquat

Les décisions sont posées comme des décisions, pas comme des « considérations » :
désactivation sans suppression (FR-6), anonymisation plutôt qu'effacement (FR-20),
roadmap lue depuis les fichiers sans base intermédiaire (FR-14), « lancer une tâche »
rendu impossible en production et « pas seulement caché » (§6). Le compromis central du
brief — « le socle se clone et diverge », dette assumée « qui grandit avec le succès » —
est repris en §6 avec le seuil de réexamen (« dès que les dérivés se compteront en
dizaines »). Les questions OQ-2, OQ-3 et OQ-5 sont réellement ouvertes.

Là où ça faiblit : le seul `[NOTE FOR PM]` du document (§8.2, gestion documentaire)
répète la phrase qui le précède — « dès qu'un dérivé l'aura réclamé deux fois » puis « à
revisiter dès qu'un deuxième dérivé le réclame » — c'est un point de contrôle sûr, pas une
tension. Les vraies tensions n'ont pas de callout : la 2FA obligatoire pour « tous les
rôles, sans exception » (FR-5) face au JTBD de l'utilisateur PME « se connecter sans
friction » (§2.1), d'autant que la méthode « code par email » proposée en second facteur
repose sur le même canal que l'identifiant de connexion ; et la dépendance de toute la
promesse UJ-3 (priorité, difficulté, assignation, dépendances) à des champs que
l'addendum dit « non portées nativement par BMAD ».

### Constatations
- **high** Le PRD et l'addendum se contredisent sur qui écrit les fichiers BMAD (§4.5 FR-16 ; addendum « Lancer une tâche ») — FR-16 : « Le lancement met à jour les fichiers BMAD (statut, personne assignée) — l'application n'écrit nulle part ailleurs » ; addendum : « la mise à jour de `sprint-status.yaml` (statut, assignation) est faite par les outils BMAD, pas par l'application ». L'architecture ne peut pas trancher seule : dans un cas l'application a un accès en écriture au dépôt, dans l'autre non, et le comportement en cas d'échec de lancement des agents diffère. *Fix :* choisir dans le PRD (« l'application ne fait que lancer ; les outils BMAD écrivent » ou l'inverse), aligner la conséquence de FR-16 et le §7 (« hors « lancer une tâche » »), et supprimer la version perdante de l'addendum.
- **medium** 2FA obligatoire pour tous sans tension nommée (§4.2 FR-5, §2.1) — « obligatoire pour tous les rôles, sans exception par utilisateur » est balisé `[ASSUMPTION]` mais jamais mis en face de « se connecter sans friction » ni du fait que « code par email » comme second facteur n'ajoute qu'une preuve de possession de la boîte mail déjà utilisée pour se connecter. Un responsable PME qui refuse la 2FA pour ses employés n'a aucun endroit dans le PRD où son objection est reconnue. *Fix :* un `[NOTE FOR PM]` sous FR-5 qui nomme le compromis (sécurité de l'ERP vs friction PME, valeur réelle du code email) et l'option écartée (2FA obligatoire pour Admin/Super admin seulement, optionnelle pour User).
- **medium** Le `[NOTE FOR PM]` unique est mal placé (§8.2) — il ne signale aucune tension. Les décisions différées qui en mériteraient un : OQ-3 (champs de story BMAD), qui conditionne FR-14, FR-15, UJ-3 et SM-2 ; et le choix jeton opaque vs JWT laissé « à trancher » dans l'addendum alors que FR-17 exige déjà la révocation. *Fix :* retirer le callout de §8.2 (la phrase précédente suffit), en poser un sous FR-14 ou OQ-3, et trancher le jeton dans le PRD (la révocation impose l'opaque ; le dire).
- **low** OQ-1 et OQ-4 figurent en « Questions ouvertes » alors qu'elles sont marquées *Résolue* (§10) — le lecteur qui compte les ouvertures en voit cinq ; il y en a trois. *Fix :* les déplacer dans une courte liste « Décisions prises pendant le PRD » ou les supprimer, la décision vivant déjà dans FR-20 et FR-15.

## Substance plutôt que décor — fort

Rien ne fait tapisserie. Quatre JTBD, cinq UJ, et chaque UJ tire au moins une FR : UJ-1 →
FR-1, UJ-2 → FR-4/5/8/9, UJ-3 → FR-15, UJ-4 → FR-13, UJ-5 → FR-16. Les cas limites des
UJ sont repris en conséquences testables (invitation renvoyée et ancien lien invalidé →
FR-4 ; compte désactivé nommé dans l'audit → FR-6 ; dépendance non terminée → FR-16). Il
n'y a pas de section « différenciation », ce qui est juste : le brief dit que
« l'avantage n'est pas technique » et le PRD n'invente pas le contraire. Les NFR ont des
seuils propres au produit (« moins d'une seconde », « jusqu'à 50 utilisateurs et 1
million d'entrées d'audit », « journal en ajout seul », liste WCAG explicite, « chaque FR
est couverte par au moins un test fonctionnel »). La Vision est spécifique : on ne
pourrait pas la coller dans un autre PRD d'ERP.

### Constatations
- **low** Le second paragraphe de la Vision (§1) recopie presque mot pour mot le résumé exécutif du brief (« Le socle n'est pas le produit vendu… », « il n'est ni vendu ni ouvert, c'est l'avantage de l'équipe, pas un produit ») alors que §0 annonce « ne le répète pas ». Ce n'est pas du décor mais c'est de la duplication qui dérivera. *Fix :* garder une phrase et renvoyer au brief.

## Cohérence stratégique — fort

La thèse est posée dès §1 et les métriques la valident au lieu de mesurer l'activité :
SM-1 (« du clonage à la première fonctionnalité spécifique… moins d'une journée ») et
SM-2 (« au plus tard deux jours ») mesurent exactement les deux promesses du brief ; SM-3
mesure la répétabilité quand l'équipe grandit. Les contre-métriques existent et sont
pertinentes : SM-C1 (taille du socle, « ne pas y ajouter un module tant qu'un dérivé ne
l'a pas réclamé deux fois ») est la bonne garde contre la dérive naturelle d'un socle, et
SM-C2 tient FR-11 « tous les objets audités par défaut » à distance de l'illisibilité.
Le périmètre MVP est de type *plateforme* et sa logique est cohérente : « le socle
s'arrête là où le métier commence » (§7), extraction depuis les dérivés (§8.2).

Ce qui manque est une priorisation *à l'intérieur* du MVP. §8.1 dit « FR-1 à FR-20
telles que décrites » ; or les SM en imposent une : SM-1 dépend de FR-1/FR-2, SM-2 de
FR-4/FR-14/FR-15, et les FR de l'API (FR-17/18) ou des langues (FR-19) ne servent aucune
métrique. Le créateur d'epics devra deviner l'ordre.

### Constatations
- **medium** Pas d'ordre de livraison dans le périmètre MVP (§8.1, §9) — le PRD sait quelles FR portent les métriques primaires mais ne le traduit pas en priorité. *Fix :* trois lignes dans §8.1 : « d'abord ce qui sert SM-1 et SM-2 (FR-1, 2, 3, 4, 7, 14, 15), puis l'audit (SM-4), puis le reste ».
- **low** SM-1 n'a pas de règle de mesure (§9) — « mesuré sur chaque nouveau dérivé » ne dit pas entre quels événements (premier commit du clone → premier commit d'une entité métier ? déploiement ?). SM-4 (« zéro question… restée sans réponse ») n'a pas non plus de protocole. *Fix :* nommer les deux bornes de SM-1 ; pour SM-4, dire qui consigne les questions et où.

## Clarté du « fini » — adéquat

C'est la dimension la plus solide en volume : seize FR sur vingt ont des conséquences
qu'un test fonctionnel peut reproduire — « refuse d'agir sans une option de confirmation
explicite » (FR-1), « conduit à l'activation avant tout autre écran » (FR-5), « ses
sessions en cours sont closes » (FR-6), « L'avancement d'une epic est le rapport des
tâches terminées sur le total » et la table de correspondance des statuts (FR-15), « ni
bouton, ni point d'entrée » (FR-16). La traduction des cas limites d'UJ en conséquences
est systématique.

Les trous sont concentrés et réparables. FR-16 est la FR la plus risquée techniquement
et la moins bornée : « les agents démarrent sur son poste » n'a pas de conséquence
observable (que voit Léa ? que se passe-t-il si le processus ne démarre pas ? deux
développeurs lancent la même tâche ?), et « tâche prête » n'est défini que dans UJ-5
(« sans dépendance ouverte »), pas dans la FR. FR-10 contient une exigence
contradictoire en l'état : « refusé avec un message clair » *et* « n'expose pas
l'existence de données » — un 403 sur un identifiant existant révèle l'existence.
Plusieurs durées sont laissées en adjectif : « verrouillage progressif » (FR-3, sans
palier), « lien email à durée limitée » (FR-3, sans durée), « peut être révoqué » (FR-17,
par qui ?). Enfin UJ-3 promet que Marc voit que « la tâche bloquante attend une réponse
de son équipe » : aucune FR ne porte cet état — les trois statuts lisibles ne le
permettent pas.

### Constatations
- **high** FR-16 n'a pas de « fini » observable pour le lancement lui-même (§4.5 FR-16) — les trois conséquences couvrent l'absence en production, le refus sur dépendance et l'écriture des fichiers, mais rien sur ce que fait ou montre l'application quand elle « lance » : commande exécutée, retour visible, échec de démarrage, double lancement, définition de « prête ». C'est précisément là que la story sera écrite à l'aveugle. *Fix :* ajouter des conséquences : « prête = statut à faire et toutes les dépendances terminées » ; « l'application affiche que le lancement a eu lieu (ou a échoué, avec la raison) » ; « une tâche déjà en cours ne peut pas être relancée ». Et aligner avec la décision de la constatation *high* de Décisionnabilité.
- **medium** FR-10 est intestable telle quelle (§4.3 FR-10) — « message clair » est un adjectif et la seconde clause contredit la première selon l'implémentation. *Fix :* borner : « un accès à un objet non permis reçoit la même réponse qu'un objet inexistant (404) ; un accès à une zone non permise reçoit un 403 avec un libellé traduit ».
- **medium** UJ-3 promet un état que les FR ne fournissent pas (§2.3 UJ-3, §4.5 FR-15) — « la tâche bloquante attend une réponse de son équipe » n'a ni statut, ni champ, ni FR. *Fix :* soit retirer la phrase de UJ-3, soit ajouter à FR-15 un champ « bloquée par » lu depuis les fichiers (et le lister dans l'ASSUMPTION sur l'en-tête des stories).
- **low** Durées et acteurs non bornés (§4.2 FR-3, §4.6 FR-17) — « verrouillage progressif » sans palier ni durée maximale ; « lien… à durée limitée » sans durée ; « peut être révoqué » sans dire par qui (l'utilisateur ? un administrateur ? les deux ?). *Fix :* des valeurs balisées `[ASSUMPTION]` comme pour l'invitation (« 1 h pour le lien de réinitialisation », « délai doublé à chaque échec, plafonné à 15 min », « révocable par l'utilisateur et par un administrateur »).
- **low** Performance sans percentile ni contexte (§5) — « moins d'une seconde » : au p50 ? p95 ? sur quel matériel ? *Fix :* « p95 < 1 s sur un poste Laragon standard et en production chez le client ».

## Honnêteté du périmètre — adéquat

Les non-objectifs (§7) font du vrai travail — multi-tenant, mise à jour du socle dans
les dérivés, agents côté serveur, modification de la roadmap depuis l'interface — et §8.2
explique chaque report (« le déploiement rythme déjà la fraîcheur », « tant que le
volume n'impose rien »). Treize `[ASSUMPTION]` en ligne, toutes indexées ; la plupart sont
des valeurs de paramètre (durées, format CSV), pas des inférences structurantes. Densité
d'ouvertures raisonnable pour l'enjeu : trois OQ réelles, dont une seule (OQ-3) engage le
contenu d'une FR.

Deux choses sont pourtant laissées à l'inférence. D'abord un ajout de périmètre par
rapport au brief, non signalé : le brief ne mentionne aucune langue ; le PRD introduit
« français, anglais et néerlandais » (FR-19) comme une évidence, avec un coût qui touche
« tout texte d'interface, message d'erreur, email et libellé d'audit ». Le JTBD « dans sa
langue » (§2.1) ne suffit pas à justifier trois catalogues. Ensuite des surfaces
implicites que les FR supposent sans les décrire : le « tableau de bord » (UJ-1, FR-1 :
« voir le tableau de bord ») n'est ni une FR, ni un terme du glossaire — pour Marc,
« sa page d'accueil montre la roadmap » ; pour Fabrice, c'est « un ERP vide mais
fonctionnel » ; on ne sait pas si c'est la même page. De même, un écran de profil est
supposé (FR-19 « chaque utilisateur choisit sa langue », changement de mot de passe,
réenrôlement 2FA) mais absent, et FR-7 « un administrateur peut en créer d'autres »
suppose une interface de gestion des rôles qui n'apparaît nulle part.

### Constatations
- **medium** Le « tableau de bord » est promis mais jamais défini (§2.3 UJ-1, §4.1 FR-1) — la dernière conséquence de FR-1 (« voir le tableau de bord ») n'est pas testable tant qu'on ne sait pas ce qu'il contient. *Fix :* soit l'ajouter au glossaire et en faire une FR courte (« la page d'accueil montre la roadmap et, pour les administrateurs, les avertissements de FR-14/FR-15 »), soit remplacer par « voir la roadmap » dans FR-1 et UJ-1.
- **medium** FR-19 (trois langues) est un ajout par rapport au brief, non signalé (§4.7) — le lecteur qui compare au brief y voit une extension silencieuse du MVP, sans `[ASSUMPTION]` ni justification (marché belge ? client déjà identifié ?). *Fix :* une phrase de justification en §4.7 ou §6, et un `[ASSUMPTION: NL requis par la base de clients actuelle]` si c'est une inférence.
- **low** Surfaces implicites sans FR (§4.2, §4.3, §4.7) — profil utilisateur (langue, mot de passe, 2FA), gestion des rôles (FR-7 « en créer d'autres et modifier leurs permissions »). Le créateur de stories les inventera. *Fix :* les nommer dans §8.1 (« et les écrans qui les portent : profil, rôles ») ou en faire deux FR de trois lignes.

## Exploitabilité en aval — fort

Le PRD est chaînon de tête (§0 : « aux workflows en aval — UX, architecture, epics et
stories ») et il est construit pour ça. Le glossaire est réel et utilisé : « surcharge »,
« origine d'une permission », « statut lisible », « entrée d'audit » apparaissent
identiquement dans les UJ, les FR et les SM. Chaque UJ a un protagoniste nommé et porte
son contexte en ligne. Les références croisées résolvent (FR → UJ, SM → FR, §8.2 →
OQ-2, FR-7 → FR-1). L'index des hypothèses fait l'aller-retour complet : treize en
ligne, treize indexées. L'addendum sépare proprement le *quoi* du *avec quoi* et donne à
l'architecture ce dont elle a besoin (sources BMAD, statuts observés, candidats de
bundles avec leur contrainte de version).

Les défauts sont mécaniques (voir la section dédiée) — sauf la contradiction FR-16 /
addendum déjà relevée, qui est le seul point où l'aval ne peut pas extraire une décision.

## Adéquation de la forme — fort

Le produit est un socle interne dont les utilisateurs finaux sont multiples (PME,
direction, développeurs) et qui alimente UX → architecture → stories : la forme choisie
— spécification de capacités *plus* cinq UJ à protagoniste nommé — est la bonne. Les UJ
sont là où l'UX est porteuse (fiche de permissions de Sophie, roadmap de Marc, journal
filtré) et absentes là où elles seraient du poids (FR-2 documentation, FR-18 health check,
FR-19 langues). Les références au terrain existant sont exactes : les skills
`symfony-proglab-*`, `bmad-create-epics-and-stories`, `bmad-sprint-planning`,
`sprint-status.yaml` et ses clés correspondent à ce que le dépôt contient. La longueur
est dans la cible haute (le PRD seul fait environ sept à huit pages) ; l'addendum reste
court et ne fuit pas dans le PRD.

## Notes mécaniques

- **Renvoi cassé** : §0 dit « hypothèses… indexées en §9 » ; l'index est en §11 (§9 est
  « Métriques de succès »).
- **Numérotation** : FR-20 est placée dans §4.2 entre FR-6 et FR-7 — pas de trou ni de
  doublon, mais l'ordre de lecture ne suit plus les identifiants. Acceptable ; le dire en
  §0 (« numérotées dans l'ordre d'ajout ») ou renuméroter avant les epics.
- **Index des hypothèses** : complet dans les deux sens. L'entrée « §4.5 FR-15 —
  permission notes internes » est rangée avant les entrées FR-11/FR-13 ; à remettre dans
  l'ordre des sections.
- **Dérive de glossaire — « Client »** : défini comme « la direction de la PME » (§3),
  mais §3 « API » parle de « clients non navigateur » et FR-18 d'« un client de l'API ».
  Deux sens pour un terme du glossaire. Remplacer par « consommateur de l'API » ou
  « application cliente ».
- **Terme hors glossaire** : « tableau de bord » (UJ-1, FR-1) ; « notes internes »
  (FR-15) n'est défini que par une `[ASSUMPTION]` en ligne — le mettre au glossaire avec
  le renvoi.
- **Addendum désynchronisé sur OQ-4** : l'addendum écrit « Correspondance *proposée*…
  (OQ-4 du PRD) » alors que le PRD marque OQ-4 *Résolue* et fixe la table dans FR-15.
  Remplacer « proposée » par « fixée dans FR-15 ».
- **Ligne 73 de `prd.md`** : une ligne non repliée dans UJ-2 (formatage seulement).
- **Sections requises pour l'enjeu** : toutes présentes (vision, utilisateurs, glossaire,
  FR avec conséquences, NFR, contraintes, non-objectifs, périmètre MVP, métriques avec
  contre-métriques, questions ouvertes, index). Rien à ajouter en structure.
