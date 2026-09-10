---
title: Revue adverse — ARCHITECTURE-SPINE.md, seconde passe sur l'archivage et les permissions
reviewer: relecteur adverse
lens: attaquer le spine en adversaire — paires conformes mais incompatibles
target: ../ARCHITECTURE-SPINE.md
scope: 'AD-23 (nouvelle), AD-3 amendée, AD-8 amendée, AD-19 et AD-20 amendées, et tout ce que ces cinq changements ouvrent'
sources:
  - ../../../prds/prd-proglab-skills-2026-09-09/prd.md
  - ../../../ux-designs/ux-proglab-skills-2026-09-09/EXPERIENCE.md
  - ./review-adversaire.md
date: 2026-09-10
---

# Revue adverse — seconde passe (archivage du journal, forme CRUD des permissions)

## Méthode et verdict

Même méthode que la première passe : je ne discute aucune décision. Je les prends pour
acquises et je cherche des **paires d'unités du niveau inférieur** — deux epics du socle,
deux modules d'un dérivé, deux développeurs ou deux agents `bmad-build` travaillant en
parallèle — qui obéissent **à la lettre** à AD-1..AD-23 et construisent pourtant des
choses incompatibles : formes de données divergentes, deux propriétaires d'une même
entité, chemins de mutation contradictoires. Chaque paire est un trou à combler.

La numérotation continue celle de la première passe : T-39 et suivants. Les paires T-1 à
T-38 ne sont pas rejouées ici, sauf quand l'archivage change leur sévérité — c'est dit
quand c'est le cas.

**Verdict.** AD-23 est une bonne décision de mécanisme et une décision de propriété
incomplète : elle place correctement la frontière au niveau du dépôt, mais elle crée une
seconde table *de forme identique* sans jamais dire qui possède l'**identité** d'une
entrée, qui possède la **frontière** entre les deux tables, ni ce qui arrive à une entrée
pendant qu'elle traverse — et « forme identique » plus « déplacée telle quelle » suffit à
deux développeurs conformes pour produire deux schémas incompatibles. Symétriquement,
AD-8 nouvelle version remplace une liste de codes par un espace de noms de **ressources**
qu'aucune décision ne rend unique, ni ne rapproche de l'espace de noms d'**alias de type**
qu'AD-12 avait déjà créé pour exactement les mêmes objets. Trente paires suivent, dont
douze critiques.

Le nœud, en une phrase : **AD-23 rend la lecture du journal distribuée sur deux tables,
et le reste du spine continue de décrire cette lecture comme si elle était locale** —
pagination par offset, `total` exact, export streamé, filtre par droits, annonce à
l'écran, tous écrits pour une table unique, tous faux ou ambigus sur une union.

---

## A. Ce que l'archivage ouvre — anonymisation et droit à l'effacement

### T-39 — L'anonymisation ne touche pas l'archive, et AD-12 lui donne raison

- **La paire.** Le développeur de l'epic FR-20 écrit `Core/Service/UserAnonymizer`. Il
  applique AD-12 mot à mot : « l'anonymisation réécrit, **dans les seules entrées dont
  l'objet est ce compte**, les valeurs et étiquettes qui portent son nom ou son email ».
  Il écrit donc un `UPDATE` sur `audit_entry` et sur `audit_field_change`, compte les
  lignes touchées, et rend « 143 entrées réécrites ». Le développeur de l'epic FR-23 écrit
  la commande d'archivage et n'a aucune raison de connaître l'anonymisation. Les deux
  epics passent leurs tests.
- **Le scénario.** FR-20 exige explicitement que l'opération « atteigne **aussi** les
  entrées archivées » et « rende compte du nombre d'entrées réécrites, en ligne et en
  archive ». AD-12 ne le dit nulle part, et AD-23 dit que **seul le dépôt d'audit sait que
  l'archive existe** — un service d'anonymisation qui écrit sur la table en ligne respecte
  AD-12 et viole AD-23 *et* FR-20 sans qu'aucun test du socle ne s'en aperçoive, puisque le
  jeu de test d'une suite fonctionnelle n'a jamais d'entrée de plus d'un mois. Le symptôme
  n'apparaît que chez un client, un an après la mise en service, sous la forme d'un nom et
  d'un email conservés dans une table que le dérivé déclare immuable.
- **AD à resserrer.** AD-12 doit énoncer son exception **sur le journal logique**, pas sur
  la table : l'anonymisation est une opération du dépôt d'audit, qui décide seul quelles
  tables elle atteint, et qui rend **deux compteurs**. Corollaire à écrire dans AD-23 : le
  déplacement est un second chemin d'écriture sur une entrée existante ; il faut le nommer,
  sinon AD-12 ment en affirmant qu'il n'y en a qu'un (voir section F).
- **Sévérité.** critique

### T-40 — Le nom survit dans les étiquettes de relation, et l'archive le fige pour toujours

- **La paire.** `Module/Sales` audite `Quote.owner` : conformément à AD-12 point 3, « une
  relation est enregistrée comme l'alias, la clé et **l'étiquette** de l'objet lié ». Les
  entrées de `quote` portent donc « Karim Benali » en clair, dans une valeur avant/après.
  `Module/Hr` fait la même chose sur `LeaveRequest.approver`. Le développeur de FR-20
  réécrit « les seules entrées dont l'objet est ce compte » — aucune de celles-là.
- **Le scénario.** Après anonymisation, « Utilisateur anonymisé #12 » s'affiche sur la
  fiche et sur les entrées dont l'objet est le compte, et « Karim Benali » reste lisible
  dans tout le reste du journal, en ligne comme en archive, pour tout lecteur ayant
  `quote.read`. L'écran de confirmation d'`EXPERIENCE.md` promet pourtant au client, en
  toutes lettres, que le nom sera remplacé « **partout**, y compris dans le journal
  d'audit ». Les deux modules sont conformes, le service d'anonymisation est conforme, et
  la promesse produit est fausse. L'archive aggrave : ces entrées-là ne sont plus jamais
  réécrites par personne, et FR-23 interdit de les supprimer.
- **AD à resserrer.** AD-12 doit choisir, et le dire. Soit une étiquette figée d'objet
  **lié** n'a pas le droit de porter une donnée personnelle, et une relation vers un compte
  est enregistrée comme alias + clé seuls, résolus à la lecture par le mécanisme du point 1.
  Soit l'anonymisation porte sur **toute entrée où le compte apparaît, à quelque titre que
  ce soit** — objet, valeur avant, valeur après, étiquette de relation — ce qui exige
  d'indexer le couple alias + clé dans les valeurs et pas seulement sur l'objet de l'entrée.
  La première branche est la seule qui reste vraie sans réécriture, et c'est celle que le
  point 1 a déjà choisie pour l'acteur : l'incohérence entre le point 1 et le point 3 est
  le trou.
- **Sévérité.** critique

### T-41 — L'anonymisation et la commande d'archivage se croisent, et l'effacement devient partiel sans que personne le sache

- **La paire.** L'administrateur anonymise Karim à 02:00:03. Le Scheduler a déclenché
  l'archivage à 02:00:00, par lots, une transaction par lot. Les deux respectent AD-21 (le
  service porte sa frontière de transaction), AD-23 (verrou `LockableTrait`, qui protège la
  commande **contre elle-même** et contre rien d'autre) et AD-12.
- **Le scénario.** Deux entrelacements, tous deux atteignables.
  1. La commande a lu son lot, l'anonymiseur réécrit ces lignes et les compte, puis la
     commande insère dans l'archive **la copie qu'elle avait en mémoire** : le nom revient
     dans l'archive, et le compteur de l'anonymiseur affirme le contraire.
  2. La commande déplace le lot pendant que l'anonymiseur travaille : son `UPDATE` touche
     moins de lignes qu'il n'en existe, il rend « 87 entrées réécrites » au lieu de 143, et
     les 56 autres sont déjà en archive, non réécrites. FR-20 déclare l'opération
     **irréversible** : il n'existe aucun chemin de rattrapage, et rien n'a signalé l'écart.
- **AD à resserrer.** Trois choses à décider. Le déplacement se fait par `INSERT ... SELECT`
  **dans la transaction du lot**, jamais depuis une copie lue plus tôt. L'anonymisation et
  le déplacement s'excluent mutuellement : le verrou d'AD-23 doit être un verrou nommé sur
  *le journal*, pris par les deux opérations, pas un verrou de commande. Et l'anonymisation
  doit être **rejouable** malgré FR-20 — « irréversible » y qualifie l'effacement, pas
  l'interdiction de repasser ; sans rejouabilité, aucun rattrapage n'existe.
- **Sévérité.** critique

### T-42 — Les messages Messenger portent le nom et l'email hors d'atteinte de l'anonymisation

- **La paire.** AD-4 exige qu'un message porte « destinataire, locale explicite, et des
  **scalaires** — jamais une entité ». Le développeur de FR-3 met donc l'email et le nom
  dans le message d'invitation, et celui de FR-4 dans le message de réinitialisation. Le
  développeur de FR-20 anonymise le compte. Les trois obéissent.
- **Le scénario.** Un message en file — ou, pire, dans la **file d'échec**, qu'AD-4 installe
  et que rien ne purge — contient une copie des données personnelles que l'anonymisation
  vient d'effacer. Le worker le consomme après coup et envoie un email à une adresse qu'un
  client a demandé d'effacer ; la file d'échec, elle, garde la copie indéfiniment. Le
  journal d'audit a été traité avec soin, le transport ne l'est nulle part.
- **AD à resserrer.** AD-4 ou AD-12 doivent nommer le transport comme un troisième
  emplacement de données personnelles, avec sa règle : l'anonymisation échoue s'il reste des
  messages non consommés visant ce compte, ou elle les retire, ou les messages portent une
  **référence de compte** plus le strict nécessaire et le worker re-résout le destinataire à
  la consommation en refusant un compte anonymisé — seul choix cohérent avec le `UserChecker`
  d'AD-5. Ajouter au passage une politique de rétention de la file d'échec, aujourd'hui
  absente.
- **Sévérité.** haute

### T-43 — Un champ déclaré sensible après coup n'a aucun chemin de remédiation, et l'archive fige la fuite

- **La paire.** `Module/Payroll` audite `Employee` sans exclure `iban` — AD-12 exclut « à
  l'écriture par une liste tenue dans `Core` et extensible par module », et le module n'a
  rien déclaré. Trois mois plus tard, un second développeur ajoute l'exclusion. Les deux
  sont conformes : la liste est extensible, personne n'a violé de règle.
- **Le scénario.** Les entrées déjà écrites gardent les IBAN, en ligne et surtout en
  archive. AD-12 interdit de masquer à l'affichage (« jamais masqués à l'affichage ») et
  n'autorise l'écriture sur une entrée existante que pour l'anonymisation d'un compte. Le
  développeur pressé fera donc exactement ce qu'AD-12 interdit — un masquage au rendu — et
  il aura raison, parce que le spine ne lui laisse rien d'autre.
- **AD à resserrer.** AD-12 doit prévoir une **purge de champ** : une opération nommée,
  auditée, exécutée par le dépôt sur les deux tables, qui neutralise la valeur d'un champ
  identifié par alias + nom de champ. C'est la troisième écriture légitime sur une entrée
  existante ; le spine en reconnaît une, il en a trois.
- **Sévérité.** moyenne

---

## B. Ce que l'archivage ouvre — identité, atomicité, intégrité

### T-44 — « Forme exactement identique » ne dit pas si l'identifiant est préservé, et deux développeurs répondent différemment

- **La paire.** Développeur A écrit la migration de `audit_entry_archive` avec un `id`
  entier auto-incrémenté, comme toutes les entités du socle (convention « identifiants
  entiers auto-incrémentés »), et insère sans forcer l'id : l'archive numérote pour son
  compte. Développeur B copie l'id de la ligne d'origine, parce qu'elle est « déplacée telle
  quelle ». Les deux tables ont bien « exactement la même forme — mêmes colonnes ». Les deux
  développeurs sont conformes à AD-23 et au tableau des conventions.
- **Le scénario.** Chez A, l'entrée 4 812 en ligne et l'entrée 4 812 en archive coexistent
  et désignent deux événements différents. La route `app_audit_show` d'AD-6 est
  `/audit-log/{id}` : le dépôt doit choisir une table, et les deux réponses sont légitimes.
  Un lien de panneau — qu'`EXPERIENCE.md` promet « partageable » — désigne une entrée
  jusqu'au jour de son archivage puis une autre, sans erreur visible. Un export produit il y
  a un mois et un export produit aujourd'hui donnent deux identifiants au même événement.
  Chez B tout tient, mais rien dans le spine ne choisit B.
- **AD à resserrer.** AD-23 doit poser que **l'identité d'une entrée est stable et unique à
  travers les deux tables** : l'id est préservé au déplacement, l'archive n'a pas de séquence
  propre, sa clé primaire est fournie et non générée. Corollaire : c'est aussi la seule clé
  de tri totale disponible (T-50), donc le choix n'est pas cosmétique.
- **Sévérité.** critique

### T-45 — Le détail d'une entrée peut disparaître au déplacement, dans un journal déclaré immuable

- **La paire.** `AUDIT_ENTRY ||--o{ AUDIT_FIELD_CHANGE` et son jumeau d'archive figurent au
  diagramme. Développeur A génère la relation avec `onDelete: CASCADE` — le réflexe courant,
  et le seul moyen d'éviter des orphelins. Développeur B laisse la contrainte stricte. Aucun
  des deux ne viole une règle : le spine ne dit rien des lignes filles.
- **Le scénario.** AD-23 parle d'« entrées dont l'horodatage dépasse la fenêtre », jamais de
  leur détail. Chez A, une implémentation qui copie l'entrée puis supprime la ligne en ligne
  détruit les `audit_field_change` par cascade : l'entrée archivée existe, son « avant /
  après » est perdu — définitivement, dans une table que FR-13 déclare immuable et que FR-23
  déclare non supprimable. Chez B, le même code échoue sur une contrainte d'intégrité en
  pleine nuit, la commande s'arrête au premier lot, et la fenêtre en ligne grossit en
  silence jusqu'à ce que le budget d'AD-20 tombe.
- **AD à resserrer.** AD-23 doit dire que **le déplacement porte l'entrée et l'intégralité de
  ses lignes filles, dans la même transaction, et échoue en bloc**, identifiants préservés
  (T-44), la suppression en ligne n'ayant lieu qu'après insertion vérifiée des enfants. Un
  test d'intégration doit compter les lignes filles avant et après un cycle d'archivage.
- **Sévérité.** critique

### T-46 — Deux commits ou un seul : une entrée visible deux fois, définitivement

- **La paire.** AD-23 exige « par lots, dans une transaction par lot ». Développeur A ouvre
  une transaction pour tout le lot : insertion en archive puis suppression en ligne, commit
  unique. Développeur B, qui a lu la même phrase et veut borner la durée des transactions,
  fait deux transactions par lot — une pour l'insertion, une pour la suppression. « Une
  transaction par lot » se lit dans les deux sens.
- **Le scénario.** Chez B, entre les deux commits, toute lecture traversant les deux tables
  voit chaque entrée du lot **deux fois** : doublons à l'écran, `total` faux, export
  dupliqué. Si le processus meurt entre les deux commits, les doublons sont permanents — et
  la commande, « rejouable sans effet de bord » d'après AD-23, ne les corrigera jamais,
  puisque son critère est l'âge : elle réinsérera en archive ce qui y est déjà (violation de
  clé, ou triplon, selon la stratégie d'id de T-44).
- **AD à resserrer.** AD-23 doit poser l'atomicité au niveau de **l'entrée**, pas du lot :
  insertion en archive et suppression en ligne commitent ensemble. Et la rejouabilité doit
  être définie comme une idempotence vérifiée (insertion filtrée sur l'absence en archive),
  pas seulement comme « son critère est l'âge ».
- **Sévérité.** haute

### T-47 — L'archivage écrit par l'ORM déclenche le listener d'audit, qui audite l'audit

- **La paire.** Le diagramme d'entités cartographie `AUDIT_ENTRY_ARCHIVE` et
  `AUDIT_FIELD_CHANGE_ARCHIVE` comme des entités. Développeur A écrit la commande avec
  l'ORM, ce que ce diagramme suggère. Développeur B l'écrit en SQL pour tenir la performance
  sur un million de lignes, dans le dépôt, seul endroit où le standard proglab l'autorise.
  AD-23 ne choisit pas.
- **Le scénario.** Chez A, AD-3 est sans ambiguïté : « **tout** objet métier est audité par
  défaut », et le listener `onFlush` lit tous les changesets. Le déplacement produit donc,
  pour chaque entrée déplacée, une insertion auditée et une **suppression auditée** — un
  journal qui grossit plus vite qu'il ne s'archive, et des entrées décrivant la suppression
  d'entrées de journal, ce que FR-13 déclare impossible. AD-21 aggrave : l'audit s'écrit
  « dans la même transaction, par l'`UnitOfWork` en cours », donc chaque lot double de
  taille et la transaction s'allonge d'autant.
- **AD à resserrer.** AD-3 ou AD-12 doivent nommer les quatre entités d'audit dans la liste
  d'exclusion du socle, explicitement, comme une règle et non comme une évidence ; et AD-23
  doit dire par quelle couche le déplacement écrit. C'est la première fois que « tout est
  audité par défaut » rencontre une écriture massive sur le journal lui-même.
- **Sévérité.** haute

### T-48 — Les règles sont dans la commande, donc personne d'autre ne peut les appeler

- **La paire.** AD-23 : « le déplacement est une **commande console portant toutes les
  règles** ». Développeur A obéit et met la logique dans `Core/Command/`. Développeur B
  applique `symfony-proglab-console`, que le spine déclare s'appliquer « intégralement et
  sans exception » — une commande est une fine couche de traduction au-dessus d'un service —
  et met la logique dans le dépôt et un service `AuditArchiver`. Les deux citent le spine.
- **Le scénario.** Chez A, aucun autre appelant ne peut déclencher ou coordonner un
  archivage : ni un test d'intégration sans `CommandTester`, ni l'anonymisation qui doit
  s'exclure du déplacement (T-41), ni un dérivé qui voudrait archiver avant une opération
  lourde. Surtout, la règle « plus vieux que la fenêtre » se retrouve dupliquée dans le dépôt
  pour la lecture et dans la commande pour l'écriture : deux propriétaires de la même
  frontière, que T-49 exploite. AD-23 contredit ici, en une phrase, un standard que le spine
  dit appliquer sans exception.
- **AD à resserrer.** Reformuler : la commande porte le verrou, les lots, la sortie, le
  `--dry-run` et l'arrêt propre ; **les règles vivent dans le dépôt d'audit**, seul endroit
  qui sache que l'archive existe. C'est la seule formulation cohérente avec AD-23 lui-même.
- **Sévérité.** haute

### T-49 — Deux propriétaires de la frontière : le paramètre `app.` et l'état réel des tables

- **La paire.** Développeur A, sur l'écran de journal, décide si la requête déborde en
  comparant le filtre de période à `%app.audit_online_window%` — la seule source déclarée.
  Développeur B interroge la plus vieille entrée encore en ligne, parce que c'est la
  frontière réelle. Les deux sont conformes : AD-23 dit que la fenêtre est un paramètre
  `app.`, et ne dit pas qui répond à « où est la frontière ».
- **Le scénario.** Les deux valeurs divergent en permanence. Entre deux exécutions de la
  commande, la table en ligne contient jusqu'à un jour de trop ; après un raccourcissement du
  paramètre par un dérivé, jusqu'à un mois de trop ; après un incident de Scheduler (T-56),
  six mois de trop. A annonce un débordement sur des requêtes entièrement en ligne, et
  surtout **A décide de ne pas interroger l'archive** pour une période qu'il croit couverte
  alors qu'elle ne l'est pas : des entrées existantes n'apparaissent pas dans le résultat, et
  FR-23 promet l'inverse — « le journal en ligne et l'archive donnent la même réponse à la
  même question ».
- **AD à resserrer.** AD-23 doit poser que **le paramètre pilote la commande et rien
  d'autre** : la lecture ne s'en sert jamais pour décider quelle table interroger. Une
  requête atteint les deux tables sauf preuve du contraire, et cette preuve est la frontière
  observée, pas la configuration. Corollaire pour T-55 : l'annonce à l'écran ne peut pas se
  déduire d'un paramètre.
- **Sévérité.** haute

---

## C. Lecture à travers deux tables — pagination, total, export

### T-50 — Le tri n'est pas total, et l'union rend la pagination instable

- **La paire.** Développeur A implémente la lecture par une union des deux tables avec
  `ORDER BY occurred_at DESC LIMIT :n OFFSET :o`. Développeur B, plus prudent sur le budget,
  pagine d'abord la table en ligne et ne bascule sur l'archive que lorsque l'offset dépasse
  le nombre d'entrées en ligne. Les deux respectent AD-23 à la lettre : « le dépôt décide
  seul quelle table — ou les deux — une requête doit atteindre ».
- **Le scénario.** Les cinq axes d'index d'AD-20 nomment « horodatage » : le tri déclaré est
  l'horodatage seul, qui n'est **pas un ordre total**. Plusieurs entrées partagent la même
  seconde — c'est le cas normal, une seule requête HTTP en produit plusieurs et le listener
  `onFlush` les écrit dans la même transaction. Réparties entre les deux tables, leur ordre
  relatif dans une union n'est pas déterministe d'une exécution à l'autre : une entrée en bas
  de la page 3 réapparaît en haut de la page 4, une autre n'apparaît nulle part. Chez B, la
  bascule produit le même effet sur toute page chevauchant la frontière, et le nombre
  d'entrées en ligne change entre deux clics. Aucun des deux écrans n'échoue à un test
  unitaire, et les deux mentent.
- **AD à resserrer.** AD-20 doit déclarer une **clé de tri totale et stable** —
  `(horodatage DESC, id DESC)` — et l'index correspondant sur les deux tables. Ce qui
  présuppose T-44 : sans identifiant unique à travers les deux tables, il n'existe aucune clé
  de tri totale, et la pagination n'est pas réparable.
- **Sévérité.** critique

### T-51 — La convention interdit le curseur, et l'union rend l'offset intenable

- **La paire.** Le tableau des conventions dit : enveloppe `items` + `meta` avec page,
  perPage, total, pages ; « **pas de pagination par curseur** ». AD-23 crée une lecture par
  union. Un développeur qui implémente FR-13 obéit à la convention ; un développeur qui tient
  le budget d'AD-20 sur un million d'entrées implémente un curseur sur `(occurred_at, id)`.
  Les deux invoquent le spine.
- **Le scénario.** Sur une union, `LIMIT/OFFSET` oblige le moteur à matérialiser la
  concaténation puis à la trier : les index d'AD-20 servent à filtrer, pas à éviter le tri ni
  le saut. Le coût croît avec la profondeur d'offset et la taille de l'archive — exactement
  ce qu'AD-20 existe pour prévenir — et la promesse d'AD-23 (« les index existent sur les
  deux tables pour que *plus lent* reste borné ») n'est pas soutenue par le mécanisme choisi.
  Le curseur est le seul mécanisme qui reste borné, et il est interdit par une ligne écrite
  avant AD-23.
- **AD à resserrer.** Trancher explicitement pour le journal. Soit la convention gagne, et
  AD-20 doit dire jusqu'à quelle profondeur d'offset le budget vaut, l'écran bornant la
  navigation. Soit le journal est l'exception nommée, avec « Précédent / Suivant » par
  curseur — ce que l'UX supporte telle quelle, puisqu'elle n'offre que Précédent / Suivant,
  mais le « Page n sur m » qu'elle affiche tombe alors, et c'est une décision produit à
  prendre plutôt qu'à découvrir en développement.
- **Sévérité.** critique

### T-52 — `meta.total` : soit le chiffre est faux, soit le budget saute

- **La paire.** Développeur A calcule `total` sur les deux tables à chaque page, parce que la
  convention exige `total` et `pages` et qu'`EXPERIENCE.md` affiche « 3 entrées » sous le
  `h1`. Développeur B ne compte que ce qu'il a interrogé, parce qu'AD-20 borne le budget à la
  fenêtre en ligne.
- **Le scénario.** A paie un dénombrement sur l'archive à chaque page, y compris pour une
  requête entièrement en ligne : le budget p95 est perdu sur *toutes* les pages, y compris
  celles que la portée d'AD-20 déclare couvertes. B affiche « 412 entrées » là où il y en a
  90 000, et la pagination s'arrête avant l'archive — FR-23 promet la même réponse à la même
  question. Les deux respectent le texte, et n'affichent pas le même nombre.
- **AD à resserrer.** AD-20 doit décider ce que `total` signifie quand la requête traverse la
  frontière : compté exactement, estimé et affiché comme tel, ou remplacé par « au moins n ».
  Si le total exact est exigé, il faut dire qu'il sort du budget et le dire à l'écran, comme
  AD-23 l'exige déjà pour la latence.
- **Sévérité.** haute

### T-53 — L'export streamé traverse la frontière pendant que la commande d'archivage tourne

- **La paire.** Développeur A implémente l'export d'AD-20 — streamé par lots, hors Turbo,
  sans charger la sélection en mémoire — en itérant l'archive puis la table en ligne.
  Développeur B itère la table en ligne puis l'archive. AD-23 laisse le dépôt décider.
- **Le scénario.** L'export d'un an de journal dure plusieurs minutes, et rien n'exclut le
  Scheduler pendant ce temps. Chez A, une entrée déplacée après le passage sur l'archive et
  avant le passage en ligne **n'apparaît dans aucun des deux** : le CSV est silencieusement
  incomplet. Chez B, la même entrée apparaît **deux fois**. Par ailleurs les deux produisent
  un CSV en deux tronçons décroissants concaténés, donc non trié globalement : le lecteur
  voit la couture entre les deux tables, ce que FR-13 lui promet de ne jamais voir. Enfin une
  `StreamedResponse` a déjà émis son `200 OK` — une erreur ou un verrou en cours de flux
  produit un fichier tronqué qui ressemble à un fichier complet.
- **AD à resserrer.** AD-20 doit poser que l'export lit un **instantané** — transaction en
  lecture répétable pour la durée du flux, ou curseur sur la clé de tri totale de T-50
  traversant l'union dans un ordre unique — et qu'il se termine par un marqueur de fin
  vérifiable, sans quoi une troncature est indétectable. Archivage et export ne doivent pas
  pouvoir se croiser sur les mêmes lignes.
- **Sévérité.** critique

### T-54 — L'export contourne le filtre par droits d'AD-12

- **La paire.** AD-12 point 4 place le filtre par permission d'alias sur **la lecture** :
  « une entrée n'est rendue à un lecteur que s'il a la permission attachée à l'alias de son
  objet ». AD-20 place l'export dans le **dépôt**, streamé par lots, hors du chemin de rendu.
  Développeur A implémente l'export dans le dépôt et applique les filtres du formulaire.
  Développeur B applique le filtre par droits dans le service de lecture, là où AD-12 le
  décrit. Les deux obéissent.
- **Le scénario.** Un utilisateur à qui `audit.export` a été accordé — code nommé d'AD-8,
  distinct de tout code de ressource — exporte « la sélection filtrée » et reçoit un CSV
  contenant des entrées d'objets que l'écran lui refuse et dont FR-10 exige de cacher jusqu'à
  l'existence. Le contournement ne demande aucune manipulation : c'est le bouton « Exporter
  la sélection » d'`EXPERIENCE.md`. L'archive multiplie la portée par le nombre de mois
  conservés.
- **AD à resserrer.** AD-12 doit dire que le filtre par droits est une **clause de la
  requête** portée par le dépôt et appliquée identiquement à la page, au panneau, au `total`
  et à l'export — jamais une étape de rendu. Et `audit.export` doit être une opération qui
  s'ajoute à la lecture, jamais un droit qui s'y substitue.
- **Sévérité.** critique

### T-55 — « L'écran l'annonce » exige du contrôleur exactement ce qu'AD-3 lui interdit de savoir

- **La paire.** AD-3 : « aucun service appelant, aucun contrôleur, aucun template, aucun
  module ne distingue une entrée en ligne d'une entrée archivée ». AD-23 : « une requête qui
  déborde sur l'archive peut être plus lente, et **l'écran l'annonce au lecteur** ».
  Développeur A met un booléen dans le `meta` de l'enveloppe de liste et le rend dans le
  gabarit. Développeur B refuse de faire remonter la distinction et déduit l'annonce dans le
  contrôleur, en comparant le filtre de période au paramètre `app.`. Chacun respecte l'une
  des deux phrases et casse l'autre.
- **Le scénario.** Chez A, le contrat d'AD-3 est mort : le `meta` partagé de la convention de
  listes gagne un champ propre au journal, et le prochain module qui l'étendra autrement aura
  le même droit. Chez B, la connaissance de la fenêtre remonte au contrôleur — c'est le
  second propriétaire de la frontière de T-49 — et l'annonce est fausse dès que la commande
  a du retard. Par-dessus : l'annonce n'a de sens qu'**avant** l'attente, alors que le dépôt
  ne sait qu'il a débordé qu'en exécutant ; et `EXPERIENCE.md` exige qu'un message de ce type
  passe par la région d'annonces d'AD-16, ce qu'aucun des deux ne fait.
- **AD à resserrer.** AD-3 doit être amendé une seconde fois : ce qui ne remonte jamais,
  c'est **la source** d'une entrée ; ce qui peut remonter, c'est une **propriété de la
  requête** — « cette requête sort du budget » — exprimée sans nommer l'archive, décidée par
  le dépôt avant exécution à partir des bornes du filtre, et portée par un champ déclaré de
  l'enveloppe. Et il faut dire où ce message s'affiche, sinon il ne s'affichera nulle part.
- **Sévérité.** critique

### T-56 — Personne ne surveille que l'archivage tourne, et sa panne est silencieuse pendant des mois

- **La paire.** AD-23 : Scheduler, « son propre schedule et son propre worker de
  **superviseur** ». AD-4 : le worker est livré « comme service **systemd** piloté par
  Deployer ». Le diagramme de déploiement ne montre qu'un worker. Développeur A, sur l'epic
  déploiement, installe l'unité systemd du worker `async` et rien d'autre, parce que c'est ce
  que le diagramme montre. Développeur B, sur FR-23, écrit le schedule et suppose un
  superviseur que le serveur client n'a pas — AD-23 le concède lui-même en offrant la crontab
  comme repli.
- **Le scénario.** Le dérivé part chez le client avec un schedule que rien n'exécute. Le
  health check de FR-18 surveille la file d'emails (AD-4) et pas celle-ci ; aucune entrée
  d'audit n'est produite, puisque rien ne tourne. Le premier symptôme est le budget d'AD-20
  qui tombe six mois plus tard, c'est-à-dire précisément la panne qu'AD-23 existe pour
  empêcher. Après un déploiement, le second symptôme est un worker de Scheduler jamais
  redémarré exécutant l'ancien code : Deployer ne redémarre que ce qu'on lui a nommé.
- **AD à resserrer.** Choisir un gestionnaire de processus, un seul, et employer le même mot
  dans AD-4 et AD-23. Nommer le redémarrage du worker de Scheduler dans l'enveloppe de
  déploiement, au même titre que celui du worker `async`. Étendre le health check de FR-18 :
  « date de la dernière exécution d'archivage » et « âge de la plus vieille entrée en ligne »
  rendent la panne visible en une requête, et sont exactement la frontière observée dont
  T-49 a besoin.
- **Sévérité.** haute

---

## D. Permissions — ressources déclarées et grille dérivée

### T-57 — Deux modules déclarent la même ressource, et une permission en accorde deux

- **La paire.** `Module/Sales` déclare la ressource `quote` (devis client) avec create /
  read / update / delete. `Module/Purchasing` déclare la ressource `quote` (devis
  fournisseur) avec les quatre mêmes. AD-8 dit qu'un module publie « ses ressources et les
  opérations que chacune supporte » ; rien n'exige que le nom d'une ressource soit unique, ni
  préfixé, ni arbitré. AD-7 interdit `Module → Module`, donc aucun des deux ne peut voir la
  déclaration de l'autre : l'isolement rend la collision **structurellement invisible** au
  développement.
- **Le scénario.** Le catalogue dérivé contient un seul `quote.read`. La grille de l'écran de
  rôle affiche une seule ligne « quote », avec le libellé du dernier catalogue chargé.
  Accorder au commercial la lecture des devis clients lui accorde la lecture des devis
  fournisseurs, prix d'achat compris. La commande de synchronisation voit une clé, pas deux,
  et ne signale rien — ou elle échoue sur une clé dupliquée en plein déploiement, selon
  l'implémentation : les deux issues sont conformes au texte.
- **AD à resserrer.** AD-8 doit rendre l'identité d'une ressource **unique et mécanisable** :
  nom préfixé par son module (`sales_quote`, `purchasing_quote`), ou refus de deux
  déclarations du même nom **à la construction du conteneur**, pas au déploiement. Le libellé
  affiché est une clé de traduction séparée du code, sinon deux modules se disputent aussi le
  libellé. Ceci ferme côté permissions ce que T-4 de la première passe avait ouvert côté
  routes.
- **Sévérité.** critique

### T-58 — L'alias de type d'AD-12 et la ressource d'AD-8 sont le même identifiant, et rien ne le dit

- **La paire.** AD-12 crée un espace de noms d'**alias de type d'objet**, déclaré par le
  propriétaire du type, avec « la permission attachée à l'alias ». AD-8 crée un espace de
  noms de **ressources**, déclaré par le module, dont les codes se dérivent. Développeur A
  suppose qu'ils sont la même chose : l'alias `quote` donne `quote.read`, une seule
  déclaration. Développeur B les tient pour distincts : alias `devis` côté audit (parce que
  le filtre « type d'objet » doit être lisible), ressource `quote` côté permission, et une
  permission de lecture nommée à la main.
- **Le scénario.** Chez B, une permission existe qui ne se dérive d'aucune ressource, alors
  qu'AD-8 affirme qu'« aucune liste de permissions n'est tenue à la main » et que la grille se
  dérive. Cette permission n'apparaît donc sur aucun écran de rôle, ne peut être accordée à
  personne, et les entrées d'audit correspondantes sont invisibles pour tout le monde : le
  journal a silencieusement perdu une partie de son contenu. Chez A, l'alias hérite de la
  collision de T-57 : deux modules, un alias, et le filtre « type d'objet » de FR-13 mélange
  deux types d'objets — l'angle que T-11 de la première passe avait attaqué autrement.
- **AD à resserrer.** Déclarer que **l'alias de type d'AD-12 et la ressource d'AD-8 sont le
  même identifiant**, déclaré une fois, dans le même contrat de `Core/Contract/`, avec son
  libellé traduisible et ses opérations ; la permission de lecture d'une entrée d'audit est
  alors `<ressource>.read`, dérivée, jamais nommée. C'est la décision qui manque le plus :
  deux ADs ont créé deux fois le même registre.
- **Sévérité.** critique

### T-59 — Un code nommé hors CRUD et une opération de ressource peuvent produire le même code, avec deux propriétaires

- **La paire.** AD-8 fixe les codes nommés hors CRUD du socle : `user.invite`,
  `user.anonymize`, `audit.export`, `roadmap.launch`, `roadmap.notes`. Développeur A, sur un
  module RH, déclare la ressource `user` — son module ajoute une vue employé — avec les
  opérations `read` et `invite`, `invite` étant à ses yeux une opération parfaitement
  légitime de cette ressource. Développeur B, sur le socle, déclare `user.invite` comme code
  nommé. AD-8 autorise les deux formes et n'oblige personne à choisir la même pour un code
  donné.
- **Le scénario.** Le catalogue reçoit deux déclarations pour `user.invite`, avec deux
  libellés, deux catalogues de traduction, potentiellement deux propriétaires du droit. Pire
  dans l'autre sens : une ressource qui déclare une opération de trop **crée** un code que le
  socle n'a jamais voulu rendre accordable, et la grille l'affiche comme une case à cocher.
  Symétriquement, une ressource `roadmap` déclarée par un module ferait apparaître
  `roadmap.read` et `roadmap.update` à côté de `roadmap.notes` et `roadmap.launch`, sans que
  quiconque sache s'il s'agit de la même ressource.
- **AD à resserrer.** AD-8 doit poser un espace de codes **unique** : les codes nommés hors
  CRUD sont eux aussi des opérations d'une ressource — simplement hors des quatre par
  défaut — et une ressource n'a qu'un propriétaire (T-57). Il faut aussi dire **où** un code
  nommé apparaît dans la grille ressources × opérations, sinon FR-9 (« la même grille, chaque
  case avec son origine ») n'a pas de réponse pour eux et deux écrans de rôle divergeront.
- **Sévérité.** haute

### T-60 — `#[IsGranted]` peut référencer un code que rien ne déclare, et deux votants répondent différemment

- **La paire.** AD-8 traite le sens « en base mais plus déclaré » (obsolète, jamais accordé)
  et laisse le sens inverse muet. Développeur A écrit `#[IsGranted('quote.archive')]` sur une
  action en supposant l'opération déclarée ; développeur B ne déclare pas `archive` parmi les
  opérations de `quote`, puisqu'« une ressource qui ne supporte pas une opération n'en produit
  pas le code ». Le votant unique reçoit un code inconnu.
- **Le scénario.** Deux implémentations conformes du votant : refuser — l'action devient
  inaccessible à tous, Super admin compris, pour toujours, et personne ne le découvre
  puisque la navigation cache aussi le lien — ou s'abstenir, ce qui rend l'action **ouverte**
  si aucun autre votant ne s'exprime. AD-8 ne dit pas lequel. Et rien ne vérifie
  mécaniquement que chaque chaîne passée à `#[IsGranted]` existe au catalogue, alors qu'AD-7
  a posé le principe que ce genre de garantie se mécanise.
- **AD à resserrer.** AD-8 doit poser la règle miroir : un code référencé mais non déclaré
  est une **erreur de construction**, détectée par une passe de compilation ou par la tâche
  de qualité ; et le votant refuse tout code inconnu.
- **Sévérité.** haute

### T-61 — Un alias orphelin dans l'archive : entrée invisible à tous, ou visible à tous

- **La paire.** Un dérivé retire `Module/Payroll` après dix-huit mois, ou renomme une
  ressource. L'archive contient des milliers d'entrées portant l'alias `payroll_slip`, que
  plus aucun service taggé ne déclare. AD-8 traite ce cas pour les **codes** (obsolète,
  affiché comme tel, jamais accordé) ; AD-12 ne le traite pas pour les **alias**. Développeur
  A applique la logique d'AD-8 par analogie : aucune permission déclarée, aucun accord,
  l'entrée disparaît de toutes les lectures. Développeur B considère qu'un alias inconnu
  relève du droit générique de lecture du journal.
- **Le scénario.** Chez A, une partie du journal devient invisible à tout le monde, y compris
  à l'audit légal pour lequel FR-23 refuse la suppression : conserver sans pouvoir lire
  équivaut à supprimer, et personne n'a pris cette décision. Chez B, des entrées que FR-10
  protégeait deviennent lisibles par tout titulaire de `audit.read`. L'archive rend le cas
  certain plutôt qu'hypothétique : sa raison d'être est de survivre aux modules.
- **AD à resserrer.** AD-12 doit décider le sort d'un alias non déclaré — proposition :
  lisible sous une permission de socle explicite, affiché avec son alias brut et une mention
  « type inconnu », jamais accordé par défaut. Et le catalogue doit conserver les alias
  retirés comme il conserve les codes obsolètes, avec leur libellé, sinon le filtre « type
  d'objet » perd ses valeurs historiques.
- **Sévérité.** haute

### T-62 — La grille se lit depuis la table ou depuis les services taggés, et les deux ne coïncident pas

- **La paire.** AD-8 : la source de vérité est le code, « une commande de synchronisation
  projette le catalogue dans la table au déploiement ». Développeur A rend l'écran de rôle et
  la grille depuis la table `permission`. Développeur B les rend depuis les services taggés,
  en direct, parce que la source de vérité est le code.
- **Le scénario.** Entre le déploiement d'une release et l'exécution de la synchronisation —
  ou dans un dérivé dont l'enveloppe de déploiement n'a jamais reçu cet appel, ce qu'aucune
  décision n'impose aujourd'hui — les deux écrans montrent deux grilles. Un rôle enregistré
  depuis l'écran de B référence des codes absents de la table, donc des lignes
  `role_permission` impossibles ou orphelines. Et rien ne dit si la synchronisation
  **supprime** les lignes obsolètes : AD-8 dit qu'un code obsolète s'affiche comme tel, ce
  qui implique qu'il reste, mais un développeur écrira le `DELETE` et les `role_permission`
  partiront en cascade — des rôles perdent des droits silencieusement, à un déploiement.
- **AD à resserrer.** Dire que la grille se dérive **des déclarations à l'exécution** et que
  la table sert la persistance des accords, pas l'affichage du catalogue ; que la
  synchronisation est **idempotente et ne supprime jamais** (elle marque obsolète) ; et que
  son exécution fait partie de l'enveloppe de déploiement au même titre que les migrations
  (AD-22).
- **Sévérité.** haute

### T-63 — `audit.read` et la permission par alias : un portillon ou deux, et les zones sans source

- **La paire.** `EXPERIENCE.md` fait du Journal d'audit une entrée de navigation soumise à
  permission, donc à un code de zone, et AD-8 exige `access_control` par zone « jamais l'un
  seul ». AD-12 point 4 filtre chaque entrée par la permission de son alias. Développeur A
  exige les deux — le code de zone ouvre l'écran, l'alias filtre les lignes. Développeur B
  considère que posséder un droit de lecture sur au moins un alias suffit à ouvrir l'écran,
  sans quoi un utilisateur n'ayant que `quote.read` ne verrait jamais son propre journal.
- **Le scénario.** Le persona qui reçoit le journal « par surcharge seulement » se voit
  accorder ou refuser l'écran selon le développeur. Et la grille dérivée d'AD-8 ne produit
  pas de code de zone : elle produit des couples ressource × opération. Une zone n'est pas
  une ressource, et AD-8 amendée ne dit plus d'où sortent les codes que `access_control`
  consomme.
- **AD à resserrer.** AD-8 doit dire ce qu'est un code de zone dans la forme
  « ressource + opération » — le plus simple étant que la zone soit une ressource comme une
  autre, avec `read` — et AD-12 doit dire si le filtre par alias s'ajoute au code de zone ou
  le remplace.
- **Sévérité.** moyenne

---

## E. Restes

### T-64 — La taille de lot et la durée de transaction ne sont fixées nulle part

Deux développeurs choisissent 500 et 100 000. Le second tient la table en ligne verrouillée
plusieurs minutes en pleine nuit pendant qu'un worker `async` écrit des entrées d'audit —
AD-21 les écrit dans la transaction métier : les écritures métier attendent, et le `retry` de
Messenger amplifie. À fixer comme paramètre `app.` avec une valeur par défaut prudente, et à
nommer la contention comme la raison. **Sévérité : moyenne.**

### T-65 — Le test de comptage de requêtes d'AD-20 ne couvre pas le chemin d'union

AD-20 exige « un test d'intégration qui compte les requêtes d'une page de journal et échoue si
le nombre croît avec le nombre de lignes ». Un développeur l'écrit sur un jeu entièrement en
ligne — le seul que sa fixture produise naturellement. La résolution par lot des étiquettes
sur un chemin d'union (deux tables, donc deux lots, ou pire un lot par table par page) n'est
jamais testée. AD-20 doit exiger ce test **dans les deux régimes**, plus un troisième cas où
la page chevauche la frontière. **Sévérité : moyenne.**

### T-66 — L'acteur système, la clé étrangère de l'archive, et l'écran Utilisateurs

Le diagramme relie `USER` à `AUDIT_ENTRY_ARCHIVE` par une clé étrangère : l'acteur système
d'AD-12, qui signe désormais chaque archivage, doit donc être une **ligne de la table des
comptes**. Elle apparaît alors dans `/users`, dans le Select « auteur » du filtre, et un
administrateur peut la désactiver, la réinviter ou l'anonymiser — FR-6 interdit de la
supprimer, rien n'interdit le reste. T-18 de la première passe le signalait comme un choix
ouvert ; l'archive le rend structurel. À décider : ligne de compte marquée non humaine et
exclue des écrans de gestion, ou colonne d'acteur nullable avec discriminateur.
**Sévérité : moyenne.**

### T-67 — La fenêtre par défaut et le filtre par défaut de l'écran se touchent

Un mois de fenêtre et un filtre de période par défaut sur trente jours placent la lecture
courante exactement sur la frontière : l'annonce de débordement apparaît et disparaît au fil
des exécutions de la commande, sans que le lecteur ait rien changé. Choisir un filtre par
défaut nettement à l'intérieur de la fenêtre est une décision d'une ligne qui évite un défaut
perçu comme aléatoire. **Sévérité : moyenne.**

### T-68 — AD-19 fixe sept jours, et le point de départ du délai reste ambigu

AD-19 pose le délai de grâce à sept jours par paramètre `app.` — c'est net. Reste
qu'« posée à la première connexion sous un rôle qui l'exige et **remise à zéro si le rôle
change** » se lit dans deux sens : le compteur repart de zéro à chaque changement de rôle —
donc un administrateur qui bascule un utilisateur entre deux rôles exigeants tous les six
jours annule indéfiniment l'obligation — ou la date est effacée et ne repart que lors d'un
passage depuis un rôle non exigeant. Deux développeurs, deux comportements, et l'un des deux
rend l'obligation contournable sans mauvaise intention. **Sévérité : moyenne.**

---

## F. Promesses du spine qui ne tiennent pas, hors paire

Trois affirmations sont fausses ou insoutenables telles qu'écrites, indépendamment de toute
divergence entre deux implémentations.

1. **« Les index existent sur les deux tables pour que *plus lent* reste borné » (AD-23).**
   Les index bornent le filtrage ; ils ne bornent ni le tri d'une union matérialisée ni un
   offset profond. La borne annoncée n'existe pas tant que T-50 et T-51 ne sont pas tranchés.
2. **« Aucun contrôleur, aucun template ne distingue une entrée en ligne d'une entrée
   archivée » (AD-3) et « l'écran l'annonce » (AD-23).** Les deux phrases sont dans le même
   document et ne peuvent pas être vraies ensemble sans la reformulation de T-55.
3. **« L'anonymisation est le seul chemin d'écriture autorisé sur une entrée existante »
   (AD-12).** AD-23 en ajoute un deuxième — le déplacement — et T-43 en réclame un troisième.
   La phrase doit être réécrite en énumérant les chemins, sinon elle sera citée pour refuser
   le bon correctif.

---

## Synthèse

| # | Paire | Sévérité |
| --- | --- | --- |
| T-39 | L'anonymisation n'atteint pas l'archive ; AD-12 lui donne raison contre FR-20 | critique |
| T-40 | Le nom survit dans les étiquettes de relation, figé en archive pour toujours | critique |
| T-41 | Course anonymisation × archivage : effacement partiel, irréversible, non signalé | critique |
| T-44 | Identifiant régénéré ou préservé : `/audit-log/{id}` désigne deux entrées | critique |
| T-45 | Lignes de détail perdues au déplacement, dans un journal déclaré immuable | critique |
| T-50 | Tri non total sur l'union : une entrée sautée ou répétée entre deux pages | critique |
| T-51 | La convention interdit le curseur, l'union rend l'offset intenable | critique |
| T-53 | Export streamé pendant l'archivage : lignes manquantes, dupliquées, flux tronqué en 200 | critique |
| T-54 | L'export contourne le filtre par droits d'AD-12 | critique |
| T-55 | AD-3 interdit au contrôleur de savoir ce qu'AD-23 lui demande d'annoncer | critique |
| T-57 | Deux modules déclarent la ressource `quote` : une permission, deux domaines | critique |
| T-58 | Alias de type (AD-12) et ressource (AD-8) : deux registres pour un même identifiant | critique |
| T-42 | Messenger porte le nom et l'email hors d'atteinte de l'anonymisation | haute |
| T-46 | Deux commits par lot : entrée visible deux fois, doublons permanents | haute |
| T-47 | L'archivage par l'ORM déclenche le listener : audit de l'audit, suppression auditée | haute |
| T-48 | « Toutes les règles dans la commande » : personne d'autre ne peut les appeler | haute |
| T-49 | Deux propriétaires de la frontière : paramètre `app.` contre état réel des tables | haute |
| T-52 | `meta.total` : chiffre faux, ou budget perdu sur toutes les pages | haute |
| T-56 | Rien ne surveille que l'archivage tourne ; systemd contre superviseur | haute |
| T-59 | Code nommé hors CRUD et opération de ressource produisent le même code | haute |
| T-60 | `#[IsGranted]` sur un code non déclaré : refus total ou ouverture totale | haute |
| T-61 | Alias orphelin en archive : invisible à tous, ou visible à tous | haute |
| T-62 | Grille depuis la table ou depuis les services taggés ; synchronisation qui supprime | haute |
| T-43 | Champ déclaré sensible après coup : aucune remédiation, l'archive fige la fuite | moyenne |
| T-63 | `audit.read` et permission par alias : un portillon ou deux ; codes de zone sans source | moyenne |
| T-64 | Taille de lot et durée de transaction non fixées : contention nocturne | moyenne |
| T-65 | Le test de requêtes d'AD-20 ne couvre pas le chemin d'union | moyenne |
| T-66 | Acteur système : ligne de compte imposée par la clé étrangère de l'archive | moyenne |
| T-67 | Fenêtre par défaut et filtre par défaut se touchent : annonce erratique | moyenne |
| T-68 | AD-19 : « remise à zéro si le rôle change » se lit dans deux sens | moyenne |

Douze critiques, onze hautes, sept moyennes, plus les trois promesses de la section F.

**Trois décisions ferment à elles seules la moitié de la liste.** L'identité partagée d'une
entrée à travers les deux tables (T-44) débloque le tri total (T-50), la pagination (T-51),
l'idempotence du déplacement (T-46) et l'export (T-53). L'unification de l'alias de type et
de la ressource de permission en un seul registre arbitré (T-58 avec T-57) ferme T-59, T-60,
T-61 et une partie de T-63. Et déplacer l'anonymisation, l'export et le filtre par droits
**dans le dépôt d'audit** — déjà le seul endroit autorisé à connaître les deux tables — ferme
T-39, T-48 et T-54 d'un même geste : c'est la ligne qu'AD-23 a tracée, et que le reste du
spine n'a pas encore suivie.

## Ce que je ne reproche pas

Le choix de deux tables plutôt qu'un partitionnement RANGE, et sa justification. Le refus de
purger. La fenêtre en paramètre `app.`. Le verrou de la commande. La séparation du schedule
d'archivage de la file `async`. La forme CRUD des codes de permission, qui est un meilleur
contrat qu'une liste — c'est son espace de noms qui manque, pas sa forme. Les sept jours
d'AD-19 et le fait de les porter par un paramètre. Et le bornage explicite du budget d'AD-20
à la fenêtre en ligne, qui est une honnêteté rare et la raison pour laquelle la moitié de
cette revue a pu être écrite.

---

*Note hors périmètre.* `EXPERIENCE.md` désigne encore le journal par `/journal-audit` là où
AD-6 fixe `/audit-log` et déclare résoudre OQ-UX-4. Un développeur qui lit le spine UX en
premier construira la route en français. À réconcilier dans le spine UX, pas ici.
