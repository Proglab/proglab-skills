# Relecture qualité PRD — révision du 2026-09-10 (spec)

Portée : le delta de la révision « spec » (FR-5 délai de grâce, FR-7/8/9 permissions en
grille, FR-13 + FR-23 rétention et archivage, FR-17 liste des jetons, §5 budget p95, §8,
§10, §11), et ce que ce delta a laissé désaligné ailleurs dans le document. Documents lus :
`prd.md`, `addendum.md`, la relecture précédente `review-rubric.md`, et
`../../../specs/spec-proglab-skills/SPEC.md` pour vérifier ce que Fabrice a effectivement
tranché.

## Verdict global

Les quatre décisions sont bonnes et bien écrites là où elles sont écrites : FR-23 est la FR
la plus rigoureuse du document, le délai de grâce lève une tension que la version
précédente laissait pourrir (un premier Super admin bloqué à l'enrôlement dès FR-1), et la
forme « ressource + opération » remplace une liste devinée par une mécanique dérivée. Mais
la révision a été appliquée aux FR touchées et pas à leur voisinage : le nouveau modèle de
permissions n'a pas d'endroit à l'écran pour les cinq codes nommés dont Admin a besoin —
ce qui rend UJ-2 et UJ-4 irréalisables telles qu'écrites — et l'archive de FR-23 échappe à
l'anonymisation que le §6 présente comme la réponse au droit à l'effacement. Ces deux
points sont bloquants pour l'aval ; le reste est du réalignement (FR-10, FR-12, FR-13,
glossaire, §5, §8.3).

## Décisionnabilité — adéquat

Les quatre arbitrages sont posés comme des décisions, avec leur raison et leur trace :
§0 les résume, §10 ferme OQ-2, OQ-5 et OQ-6 en disant *ce qui* a été décidé et pas
seulement *que* c'est décidé, et OQ-5 va jusqu'à expliquer que la question ne se pose plus
sous sa forme d'origine. C'est le bon niveau. Le commentaire révisé de FR-17 (AD-5) reste
un modèle : il nomme la version perdante et pourquoi elle perd.

Deux faiblesses, toutes deux introduites par la révision. D'abord OQ-7 : elle demande si le
délai de grâce tient pour un Super admin, alors que FR-5 écrit déjà comme un fait
« Un Super admin ou un Admin dispose d'un délai de grâce de sept jours ». Un lecteur en
aval qui extrait FR-5 ne saura pas que sa première conséquence est provisoire. Ensuite
FR-17 : la clause « rien d'autre » fige une décision d'écran dans une FR sans nommer ce
qu'elle coûte, alors que le jeton dure une heure par défaut — la liste des jetons d'un
utilisateur est, en régime normal, une liste de jetons morts, et rien ne dit qu'ils en
sortent un jour (FR-6 et FR-20 posent par ailleurs que le socle ne supprime rien).

### Constatations
- **élevé** OQ-7 contredit une conséquence déjà écrite au présent dans FR-5 (§4.2 FR-5, §10) — et la réponse « exiger le second facteur dès la première connexion d'un Super admin » casserait deux autres endroits : la dernière conséquence de FR-1 (« À la fin, le Super admin peut se connecter et voit la roadmap (vide) ») et le lundi matin d'UJ-1. Le coût de la question n'est pas visible depuis la question. *Fix :* un `[NOTE FOR PM]` sous FR-5 renvoyant à OQ-7, et une mention dans OQ-7 de ce qu'elle rouvre (FR-1, UJ-1) ; ou trancher tout de suite en exemptant le compte créé par FR-1.
- **moyen** FR-17 « rien d'autre » ferme la porte à ce que la FR exige par ailleurs (§4.6 FR-17) — la même FR demande que l'utilisateur puisse révoquer un jeton et qu'un administrateur puisse le faire « depuis la fiche de l'utilisateur » ; une liste qui ne porte « rien d'autre » que le nom, la date de création et une mention d'état ne porte ni commande de révocation ni la vue côté administrateur, qui n'est décrite nulle part (FR-9 dit que la fiche présente la grille de permissions, pas les jetons). *Fix :* reformuler en « aucune colonne supplémentaire », et ajouter une conséquence pour la vue administrateur des jetons d'un utilisateur.
- **moyen** L'expiration par défaut d'une heure n'a pas été confrontée à la décision de liste (§4.6 FR-17, §10 OQ-6) — OQ-6 a été fermée sur « une mention visible expiré/révoqué », mais avec une heure par défaut la mention est l'état normal de presque toutes les lignes. Soit la durée par défaut est mauvaise, soit la liste doit masquer ou grouper les jetons éteints. *Fix :* une conséquence disant ce que devient un jeton expiré dans la liste (masqué par défaut, purgé après N jours, ou conservé — et le dire).

## Substance plutôt que décor — fort

Rien dans le delta ne fait tapisserie. FR-23 est de la substance dense : quatre
conséquences, toutes vérifiables sauf une (voir Clarté du fini), et une phrase qui fait le
travail d'une section entière — « Archiver n'est pas purger ». La grille de FR-7 est
justifiée par sa mécanique (« La grille se dérive des ressources déclarées […] aucune liste
de permissions n'est tenue à la main ») et pas par le fait que ça sonne bien, et la
dernière puce dit précisément ce que la décision achète : « Chaque dérivé compose ainsi ses
rôles à partir de la même grille, au lieu d'hériter d'une liste d'actions décidée en
amont ». Le §5 assume que le budget s'assouplit au lieu de le taire.

Aucune constatation.

## Cohérence stratégique — adéquat

Le delta ne dévie pas de la thèse : la grille sert la dérivabilité (chaque dérivé compose),
l'archivage sert SM-4 sans contredire SM-C2, le délai de grâce sert le JTBD « se connecter
sans friction » sans abandonner la sécurité. §8.2 a été mis à jour et fait du vrai travail :
« La purge du journal d'audit : FR-23 archive, il ne supprime rien » est un non-objectif
utile parce que quelqu'un, un jour, proposera la purge.

Là où la révision n'a pas suivi : §8.3. L'ordre de livraison range l'archivage en dernier
(« puis le reste (… lancement de tâche, archivage) ») alors que FR-13, livré dans la
deuxième vague, porte désormais la conséquence « Les entrées archivées (FR-23) se
consultent depuis le même écran, avec les mêmes filtres ». La deuxième vague ne peut donc
pas être déclarée finie sur ce critère. Par ailleurs FR-10 — l'application effective des
permissions — n'apparaît dans aucune des trois vagues, alors que FR-7 est en première
vague : livrer des rôles sans le point qui les fait respecter est la seule combinaison qui
n'ait pas de sens.

### Constatations
- **moyen** §8.3 n'a pas été rejoué après la révision (§8.3, §4.4 FR-13) — l'archivage arrive après un FR-13 qui le référence déjà, et FR-6 comme FR-10 ne sont dans aucune vague. *Fix :* mettre FR-10 avec FR-7 en première vague ; soit remonter FR-23 dans la vague audit, soit noter dans FR-13 que sa conséquence « entrées archivées » ne s'évalue qu'après FR-23.

## Clarté du « fini » — mince

C'est ici que le delta coûte le plus, et pas dans les FR qu'il a réécrites — dans celles
qu'il n'a pas rouvertes.

**FR-10 est devenue incohérente sous le modèle CRUD.** Sa deuxième conséquence dit qu'« une
tentative d'accès à un objet précis non permis reçoit la même réponse que pour un objet
inexistant (404), pour ne pas révéler son existence ». Avec quatre opérations par ressource,
« non permis » n'est plus binaire : un utilisateur qui a `tarif.read` mais pas
`tarif.update` connaît déjà l'existence de l'objet, et lui répondre 404 sur une tentative de
modification est à la fois absurde et intestable. La règle correcte dépend de l'opération, et
la FR ne le dit pas. Même remarque, plus bénigne, sur « Les éléments de navigation vers des
zones non permises ne sont pas affichés » : une « zone » correspond maintenant à une
opération précise (`read` de la ressource), ce que la FR ne nomme pas.

**FR-13 et FR-7 ne s'accordent plus sur ce qu'est la permission d'exporter.** FR-13 dit
« Un utilisateur ayant la permission peut consulter le journal d'audit, le filtrer et
l'exporter » — une permission pour trois choses. FR-7 fait de « exporter le journal » un
code nommé distinct de la consultation. FR-23 tranche une troisième fois, dans un sens
encore différent : « consultable et exportable par qui a la permission de lecture
correspondante ». Trois formulations, trois modèles.

**FR-23 laisse deux conséquences non vérifiables.** « Le déplacement s'exécute
périodiquement » — à quelle période ? — et « rend compte du nombre d'entrées déplacées » —
à qui, où ? Journal applicatif, entrée d'audit, écran d'administration ? Ce sont trois
implémentations différentes et trois tests différents. Le « attribué à l'acteur système »
suggère une entrée d'audit, mais FR-12 ne liste pas l'archivage parmi les actions du socle
auditées, et une entrée d'audit qui rend compte d'un archivage sera elle-même archivée un
mois plus tard.

**§5 a gagné une exception sans borne.** « Une recherche qui plonge dans l'archive peut être
plus lente, et l'écran le dit au lecteur plutôt que de le laisser attendre » : aucun
plafond, aucun seuil, aucune définition de ce que l'écran dit. C'est la seule phrase de §5
qui redevienne un adjectif après avoir été un chiffre. Et le budget d'une seconde au p95
n'est plus adossé à aucun volume pour la fenêtre en ligne : l'hypothèse de §11 dimensionne
désormais l'*archive* (1 million d'entrées) et plus rien ne dit combien d'entrées un dérivé
courant produit en un mois — c'est-à-dire exactement le nombre que le budget doit tenir.

**La fiche utilisateur, que §5 nomme, a changé de poids sans que §5 le note.** FR-9 la fait
porter « la même grille ressources × opérations que l'écran de rôle, avec pour chaque case
l'état effectif et son origine ». Pour un dérivé mûr, c'est plusieurs centaines de cases
dont chacune demande une résolution rôle + surcharge. Rien ne borne le nombre de ressources
déclarables, et la promesse « moins d'une seconde au p95 » sur cette fiche a été faite
contre un écran qui n'existait pas encore.

### Constatations
- **élevé** FR-10 n'est plus testable sous le modèle « ressource + opération » (§4.3 FR-10) — la règle 404 traite « non permis » comme binaire alors qu'un utilisateur peut avoir `read` sans `update` sur le même objet. *Fix :* « une opération de lecture non permise sur un objet précis répond 404 ; une autre opération non permise sur un objet dont l'utilisateur a la lecture répond 403 avec un libellé traduit ; la navigation se dérive de l'opération `read` de chaque ressource ».
- **élevé** Trois définitions concurrentes de la permission d'exporter le journal (§4.3 FR-7, §4.4 FR-13, §4.4 FR-23) — code nommé distinct / permission unique de consultation / « permission de lecture correspondante ». L'aval devra choisir à l'aveugle, et le choix change qui peut exporter. *Fix :* fixer `audit.export` comme code nommé distinct dans FR-7, et réécrire FR-13 et FR-23 pour s'y référer.
- **moyen** Deux conséquences non vérifiables dans FR-23 (§4.4 FR-23) — « périodiquement » sans période, « rend compte » sans destinataire. *Fix :* « s'exécute au moins une fois par jour » et « chaque exécution produit une entrée d'audit de l'acteur système portant le nombre d'entrées déplacées », en ajoutant l'action à la liste de référence de FR-12.
- **moyen** L'exception d'archive du §5 n'a aucune borne (§5) — « peut être plus lente » et « l'écran le dit au lecteur » ne se testent pas. *Fix :* un plafond (« au plus N secondes au p95 sur l'archive dimensionnée ») et une conséquence dans FR-13 décrivant l'avertissement montré.
- **moyen** Le budget p95 de la fenêtre en ligne n'a plus de volume associé (§5, §11) — l'hypothèse de dimensionnement porte sur l'archive ; rien ne dit combien d'entrées la fenêtre d'un mois contient. *Fix :* une hypothèse « un dérivé courant produit jusqu'à N entrées d'audit par mois », indexée en §11.
- **moyen** La fiche utilisateur devient un écran de grille sans borne, sous un budget inchangé (§4.3 FR-9, §5) — rien ne limite le nombre de ressources déclarées. *Fix :* une hypothèse de dimensionnement (« jusqu'à N ressources déclarées dans un dérivé courant ») et, dans FR-9, une conséquence sur la pagination ou le regroupement de la grille au-delà.

## Honnêteté du périmètre — adéquat

La révision est honnête sur ce qu'elle ajoute : FR-23 entre dans le périmètre (§8.1 « FR-1 à
FR-23 »), §8.2 dit explicitement que la purge n'y entre pas, et §10 marque OQ-6 comme
« ouverte et close le 2026-09-10 » plutôt que de faire disparaître le détour. Le SPEC
justifie même le choix de ne pas reporter l'archivage hors MVP — « une politique de rétention
sans mécanisme n'est appliquée par personne » — ce que le PRD aurait pu reprendre en une
ligne mais dont l'absence ne trompe personne.

Ce qui reste implicite, en revanche, engage l'architecture. FR-7 dit que la grille « se
dérive des ressources déclarées par le socle et par chaque module » : quand un développeur
ajoute une ressource métier dans un dérivé, quatre cases apparaissent, et le PRD ne dit pas
ce qu'elles valent par défaut pour les rôles existants. En particulier, FR-7 définit Super
admin comme « toutes les permissions » sans dire si c'est un octroi dynamique ou une liste
figée au moment de FR-1. Si c'est figé, l'après-midi d'UJ-1 — Fabrice écrit la première
entité métier du client — se termine sur un Super admin qui n'a pas accès à ce qu'il vient
d'écrire.

### Constatations
- **moyen** Le comportement par défaut d'une ressource nouvellement déclarée n'est pas spécifié (§4.3 FR-7, §2.2 UJ-1) — ni pour Super admin (« toutes les permissions » : dynamique ou figé ?), ni pour Admin et User. *Fix :* une conséquence dans FR-7 : « Super admin détient toute case de la grille, y compris celles apparues après l'initialisation ; une ressource nouvellement déclarée n'accorde rien aux autres rôles tant qu'un administrateur ne coche pas ».
- **bas** La justification de faire entrer l'archivage dans le MVP n'a pas été reprise du SPEC (§8.1) — le PRD l'annonce sans la motiver. *Fix :* une phrase en §8.2 à côté du non-objectif « purge ».

## Exploitabilité en aval — mince

C'est la dimension la plus abîmée, et le seul point réellement bloquant du document s'y
trouve.

**Les permissions à code nommé n'ont aucun endroit à l'écran.** FR-7 pose que la forme par
défaut est « ressource + opération », et que « une action que ces quatre opérations ne
décrivent pas garde un code nommé — inviter un utilisateur, anonymiser, exporter le journal,
lancer une tâche, voir les notes internes ». Puis FR-7 décrit l'écran de rôle comme « une
grille : une ligne par ressource, une colonne par opération », et FR-9 décrit la fiche
utilisateur comme « la même grille ressources × opérations ». Ces cinq codes n'ont ni ligne
ni colonne. Conséquences directes, toutes vérifiables dans le document :

- FR-7 définit Admin comme « administration des comptes et des permissions, consultation de
  la roadmap et du journal d'audit ». `user.invite` n'y est pas — or FR-4 dit « Seuls les
  administrateurs voient et utilisent la fonction d'invitation », et UJ-2 fait inviter Karim
  par Sophie, Admin.
- `audit.export` n'est pas dans les permissions par défaut d'Admin non plus, et UJ-4 se
  termine sur « Elle exporte la sélection pour l'envoyer au comptable ».
- FR-8 (« ajouter ou retirer une permission à un utilisateur donné ») n'a pas de surface pour
  surcharger un code nommé, alors que c'est précisément ce que fait UJ-2 si l'on considère
  que consulter le journal reste `audit.read` — et impossible si Sophie doit lui donner
  l'export.

**Le glossaire n'a pas été touché.** « Permission — le droit d'accomplir une action ou
d'accéder à une zone, identifié par un code stable » décrit le modèle d'avant. « Ressource »,
« opération », « grille », « archive », « fenêtre en ligne », « délai de grâce » et « jeton
d'accès » sont des noms porteurs, employés dans les FR, le §5 et le §8, et absents du §3 —
alors que §0 annonce « Le vocabulaire est fixé au Glossaire (§3) ». Un workflow d'epics qui
source-extrait le §3 travaillera sur l'ancien modèle.

**UJ-2 raconte encore l'écran d'avant.** « Sur la fiche de Karim, chaque permission indique
son origine » décrit une liste ; FR-9 décrit maintenant une grille dont chaque case porte
l'état effectif et son origine. Et le premier paragraphe d'UJ-2 — « a créé son mot de passe
et activé sa double authentification au premier passage » — présente comme la norme le
comportement que FR-5 rend désormais facultatif pendant sept jours.

**FR-12 n'a pas suivi.** Sa liste de référence des actions auditées est explicitement
normative : « Les puces "crée une entrée d'audit" des autres FR renvoient à cette liste ».
Elle ne contient ni la création ou la suppression d'un rôle — que FR-7 autorise pourtant
(« en créer d'autres ») — ni le déplacement vers l'archive de FR-23, ni la déclaration ou la
péremption d'une ressource (FR-9 : « Une permission présente en base mais qu'aucune ressource
ne déclare plus s'affiche comme obsolète »). Elle ne dit pas non plus si l'enregistrement
d'une grille de rôle produit une entrée ou une par case — question directe pour SM-C2, qui
demande de ne pas rendre le journal illisible.

### Constatations
- **critique** La grille de FR-7 et FR-9 n'offre aucune surface aux cinq permissions à code nommé (§4.3 FR-7 et FR-9) — `user.invite`, `user.anonymize`, `audit.export`, `roadmap.launch`, `roadmap.notes` ne sont ni octroyables sur l'écran de rôle, ni surchargeables sur la fiche utilisateur, ni visibles avec leur origine. En l'état, ni FR-4 (« seuls les administrateurs invitent »), ni FR-8, ni UJ-2, ni UJ-4 ne sont réalisables. *Fix :* une conséquence dans FR-7 et une dans FR-9 : « sous la grille, une section "actions particulières" liste les permissions à code nommé de chaque ressource, avec le même traitement d'état effectif et d'origine » ; et compléter la définition d'Admin en FR-7 avec `user.invite` et `audit.export`.
- **élevé** Le glossaire §3 décrit l'ancien modèle de permissions et ignore le vocabulaire du delta (§3) — « Permission » n'a pas la forme ressource + opération ; « Ressource », « Opération », « Grille », « Archive », « Fenêtre en ligne », « Délai de grâce » et « Jeton d'accès » manquent, alors que §0 fait du §3 la source du vocabulaire. *Fix :* réécrire l'entrée « Permission », ajouter les six entrées manquantes, et préciser dans « Journal d'audit » qu'il couvre la fenêtre en ligne et l'archive.
- **moyen** FR-12 n'intègre pas les événements créés par le delta (§4.4 FR-12) — création et suppression d'un rôle, déplacement vers l'archive, obsolescence d'une permission ; et la granularité d'audit d'un enregistrement de grille n'est pas fixée. *Fix :* compléter la liste de référence et ajouter « l'enregistrement d'une grille de rôle produit une seule entrée portant les cases changées ».
- **moyen** UJ-2 décrit l'écran et le parcours 2FA d'avant la révision (§2.2 UJ-2) — liste de permissions au lieu de grille, activation 2FA « au premier passage » présentée comme la norme. *Fix :* réécrire les deux phrases sur la grille et sur le délai de grâce ; c'est l'UJ que l'UX et les stories liront pour FR-5, FR-8 et FR-9.
- **bas** L'hypothèse de FR-14 renvoie encore à une question close (§4.5 FR-14, §11) — « `[ASSUMPTION: ces champs sont portés par l'en-tête des stories BMAD — voir OQ-3]` » alors que §10 marque OQ-3 close par AD-10, qui dit l'inverse (le socle définit la convention, il ne la constate pas). *Fix :* remplacer par « convention d'en-tête définie et documentée par le socle (AD-10) » et corriger l'entrée de §11.

## Adéquation de la forme — fort

Le delta n'a pas déformé le document. Les quatre décisions sont entrées là où elles
appartenaient — trois dans des FR existantes, une dans une FR nouvelle — sans créer de
section de circonstance, et le §0 porte la trace de la révision comme il portait déjà celle
du run d'architecture. La séparation PRD / addendum tient : l'addendum a reçu la forme
exacte des codes (`<ressource>.create` …) et le paramètre `app.` de la fenêtre en ligne, que
le PRD ne mentionne pas. Le renvoi de §0 vers `../../../specs/spec-proglab-skills/SPEC.md`
résout correctement.

Aucune constatation.

## Contradictions transverses ouvertes par FR-23

Trois promesses antérieures deviennent fausses, ou au moins invérifiables, du fait de
l'archivage. Elles ne relèvent d'aucune dimension en particulier ; elles sont le cœur du
delta.

**Le §6 promet un droit à l'effacement que l'archive ne peut pas honorer.** §6 dit : « Le
droit à l'effacement est satisfait par l'anonymisation (FR-20) : l'historique reste, la
personne n'y est plus identifiable », et FR-20 exige que « Les entrées d'audit et les objets
créés par l'utilisateur subsistent et le désignent par l'identifiant neutre ». Anonymiser
suppose donc de réécrire des entrées d'audit — c'est d'ailleurs l'exception nommée par le
SPEC et par AD-12. Or FR-23 pose que le déplacement « ne modifie ni le contenu ni l'auteur ni
l'horodatage d'une entrée », FR-13 pose que « les entrées sont immuables », et §5 pose que
« le journal d'audit est en ajout seul ». Le PRD ne dit nulle part que l'anonymisation
traverse l'archive, ni qu'elle constitue l'exception à l'immuabilité. Une demande d'effacement
reçue au treizième mois laisse le nom de la personne dans l'archive.

**FR-13 et §5 se contredisent sur ce que le lecteur doit savoir.** FR-13 : les entrées
archivées se consultent depuis le même écran « sans que le lecteur ait à savoir où elles sont
rangées ». §5 : une recherche qui plonge dans l'archive « peut être plus lente, et l'écran le
dit au lecteur ». Les deux phrases ont été écrites dans la même révision et demandent
l'inverse l'une de l'autre.

**« En ajout seul » ne décrit plus le journal.** §5 le pose comme propriété de sécurité, mais
le déplacement de FR-23 retire des lignes de la table en ligne. La propriété visée est
« aucune entrée n'est modifiée ni supprimée », pas « aucune ligne ne quitte sa table » ; en
l'état, un lecteur qui applique §5 littéralement refusera FR-23.

### Constatations
- **critique** L'anonymisation de FR-20 n'atteint pas l'archive de FR-23, et le §6 promet le contraire (§6, §4.2 FR-20, §4.4 FR-23) — le PRD ne nomme jamais l'anonymisation comme exception à l'immuabilité du journal, et FR-23 n'a aucune conséquence sur les entrées archivées désignant un compte anonymisé. La promesse RGPD du §6 est fausse au-delà d'un mois. *Fix :* une conséquence dans FR-20 (« l'anonymisation s'applique aux entrées en ligne et archivées ») et une dans FR-23 (« l'anonymisation est la seule opération autorisée à réécrire une entrée archivée ») ; ajouter l'exception à la puce Sécurité du §5.
- **élevé** FR-13 et §5 demandent l'inverse l'un de l'autre sur la visibilité de l'archive (§4.4 FR-13, §5) — « sans que le lecteur ait à savoir où elles sont rangées » contre « l'écran le dit au lecteur ». *Fix :* trancher pour la transparence avec avertissement : « la recherche est transparente ; quand elle porte au-delà de la fenêtre en ligne, l'écran signale que la réponse peut prendre plus de temps », et aligner les deux endroits.
- **moyen** « Le journal d'audit est en ajout seul » (§5) n'est plus vrai littéralement (§5, §4.4 FR-23) — le déplacement retire des lignes de la table en ligne. *Fix :* reformuler en « aucune entrée n'est modifiée ni supprimée ; le déplacement vers l'archive de FR-23 est la seule opération qui retire une entrée de la table en ligne, et il la conserve intacte ».

## Notes mécaniques

- **Continuité des identifiants** — FR-1 à FR-23 : 23 identifiants, aucun trou, aucun doublon.
  L'ordre de lecture ne suit toujours pas les identifiants (FR-20 et FR-21 entre FR-6 et FR-7,
  FR-23 entre FR-13 et FR-14), ce que §0 assume explicitement. UJ-1 à UJ-5, SM-1 à SM-4,
  SM-C1 et SM-C2 : complets.
- **Renvois croisés** — tous résolvent : FR-13 → FR-23, §5 → FR-23 et FR-18, §8.2 → FR-23,
  §10 → FR-23/FR-7/FR-17/FR-5, §6 → FR-6/FR-20/AD-1, FR-17 → AD-5, §0 → §3 et §11. Le chemin
  relatif vers le SPEC en §0 est correct.
- **Index des hypothèses** — aller-retour complet : quatorze en ligne, quatorze indexées,
  aucune orpheline dans un sens ni dans l'autre. Deux réglages introduits par le delta (durée
  du délai de grâce, fenêtre en ligne) sont posés comme décisions et non comme hypothèses,
  ce qui est correct — mais ni l'un ni l'autre n'apparaît dans la puce « Dérivabilité » du §5
  qui énumère où vit la configuration d'un dérivé.
- **Dérive avec le SPEC** — `SPEC.md` liste encore OQ-6 comme ouverte alors que §10 du PRD la
  ferme le même jour, et porte une OQ-UX-5 (lien d'une entrée d'action métier vers la fiche de
  l'objet) que le PRD ne mentionne pas, alors que §10 affirme que « les questions ouvertes du
  run UX sont tranchées au même moment ». À réconcilier dans un sens ou dans l'autre.
- **Décompte du §0** — « Fabrice a tranché quatre questions » puis cinq éléments sont listés
  (rétention, permissions, délai de grâce, jetons, plus OQ-6 en §10). Cosmétique.
- **Terme employé une seule fois** — « acteur système » (FR-11, FR-23) n'est pas au glossaire
  alors qu'il désigne une identité que le journal d'audit affiche à Sophie.
