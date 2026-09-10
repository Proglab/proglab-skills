---
title: Revue du spine d'architecture — socle ERP custom (proglab)
target: ../ARCHITECTURE-SPINE.md
reviewed: 2026-09-10
grille: couverture · exécutabilité · excès · deferred · couverture du PRD · dimensions silencieuses · diagrammes
sources:
  - ../ARCHITECTURE-SPINE.md
  - ../.memlog.md
  - ../../../prds/prd-proglab-skills-2026-09-09/prd.md
  - ../../../prds/prd-proglab-skills-2026-09-09/addendum.md
  - ../../../ux-designs/ux-proglab-skills-2026-09-09/EXPERIENCE.md
---

# Revue du spine d'architecture — socle ERP custom (proglab)

## Verdict

Le spine est exploitable et tient sa promesse principale sur **un** des deux niveaux
inférieurs : la frontière socle / module est posée avec des règles réellement
contraignantes (AD-2, AD-7, AD-11, AD-13), et AD-11 est un modèle du genre — une règle
mécaniquement vérifiable, pas une intention. Il échoue sur le second niveau, les
**epics du socle entre elles** : quatre à six coutures inter-epics ne sont pas tranchées
(locale des emails asynchrones, export du journal d'audit, forme de la charge utile
d'une action métier, câblage Doctrine/routes des deux racines, point d'application de la
2FA obligatoire), l'enveloppe opérationnelle est amputée de la sauvegarde et de la CI, et
deux contradictions internes dures subsistent (FR-5 × AD-8, « DB de CI identique à la
production » × « MySQL : version du serveur client »).

Recomptage : **2 critiques, 9 hautes, 14 moyennes, 9 basses**.

---

## 1. Couverture — les vrais points de divergence sont-ils fixés ?

### Ce qui est correctement couvert

Le spine identifie bien le niveau « modules métier d'un dérivé » et le verrouille :
deux racines (AD-2), frontière imposée par un outil et non par la revue (AD-7), un seul
mécanisme d'extension (AD-13), un seul magasin d'audit (AD-3), un seul lecteur de
fichiers BMAD (AD-10), un seul voter (AD-8), un seul mécanisme à état pour les liens
(AD-15). Ce sont les bons axes : ce sont exactement ceux que deux modules trancheraient
incompatiblement. AD-6 résout une question ouverte héritée de l'UX (OQ-UX-4) plutôt que
de la laisser flotter, ce qui est le bon réflexe.

### Manques — niveau « epics du socle »

- **[critique] La 2FA obligatoire par rôle (FR-5) est irréalisable sous AD-8.** FR-5
  impose la 2FA « pour les rôles Super admin et Admin » ; AD-8 impose « aucun code ne
  teste un nom de rôle ». Aucun AD ne dit par quoi l'obligation est portée — un drapeau
  booléen sur l'entité rôle, une permission du catalogue, une constante de classe ? Ni
  où l'obligation s'applique — un subscriber global avec liste blanche de routes
  (déconnexion, enrôlement, changement de langue) ou un contrôle par contrôleur. Deux
  epics divergeront forcément : l'epic « comptes et 2FA » choisira un mécanisme, l'epic
  « rôles et permissions » un autre, et le délai de grâce d'OQ-UX-1 est reporté comme
  « question produit » alors que c'est sa *mécanique* qui manque, pas sa valeur.
- **[critique] Le câblage framework des deux racines n'est pas tranché, ce qui falsifie
  l'invariant central d'AD-13.** Avec `src/Core/Entity/` et
  `src/Module/<Nom>/Entity/`, la configuration Doctrine par défaut de Symfony (mapping
  `src/Entity`, préfixe `App\Entity`) ne couvre rien : il faut des entrées
  `doctrine.orm.mappings`. Idem pour le chargement des routes (`config/routes.yaml` ne
  scanne pas `src/Module/` de lui-même) et pour les chemins du translator
  (`framework.translator.paths`). Le spine ne dit pas si le seed globalise `src/` une
  fois pour toutes ou si chaque module ajoute sa ligne. Tant que ce n'est pas écrit,
  « ajouter un module ne modifie aucun fichier de `src/Core/` » est vrai à la lettre et
  faux dans l'esprit : il modifie `config/`. C'est la promesse de report de correctif
  d'AD-2 qui est en jeu.
- **[haute] La locale d'un email asynchrone n'appartient à personne.** AD-4 sort tous
  les emails de la requête ; AD-14 dit que la langue de l'utilisateur « s'applique aussi
  à ses emails ». Un handler Messenger n'a ni requête ni utilisateur connecté : la
  locale doit voyager dans le message (ou être relue depuis le destinataire). Rien ne le
  dit. Trois epics écrivent des emails (invitation, réinitialisation, code 2FA) : les
  trois devineront, et deux au moins enverront en français par défaut.
- **[haute] L'export du journal d'audit (FR-13) n'a aucune décision.** Exporter une
  sélection filtrée sur une table dimensionnée à 1 million d'entrées est un arbitrage
  d'architecture, pas un détail de code : `StreamedResponse` + itération par lots,
  ou tâche Messenger + email, ou refus au-delà d'un plafond. Le choix détermine la
  signature du repository (curseur vs page), ce qu'AD-8 et la ligne « Listes » ont
  justement figé dans l'autre sens.
- **[haute] La charge utile d'une action métier n'est pas spécifiée — OQ-UX-5 a été
  perdue.** AD-12 interdit les libellés figés et impose la résolution à la lecture.
  Mais « Karim a validé le devis D-2026-041 » exige des paramètres de substitution ;
  s'ils ne sont pas stockés, ils doivent être relus sur un objet qui a pu changer, ce
  qui contredit l'immuabilité de FR-13. La question existait en UX (OQ-UX-5, « contenu
  exact d'une entrée d'action métier ») et le spine ne la reprend ni en AD, ni en
  Deferred, ni en question ouverte. La signature du service d'audit métier est donc
  ouverte, alors qu'elle est appelée depuis tous les modules.
- **[haute] Emplacement des templates, catalogues, migrations et tests par racine.** Le
  Structural Seed met `templates/` et `translations/` à la racine, sans partition, et
  **ne montre pas `migrations/` ni `tests/` du tout**. Un module aura donc ses vues
  quelque part — `templates/module/quote/` ? `src/Module/Quote/templates/` ? — et ses
  migrations dans le même dossier que celles du socle. Conséquence directe : la règle
  d'AD-2 « le report d'un correctif est un diff sur `src/Core/`, et sur rien d'autre »
  est fausse dès le premier correctif qui touche un Twig, une clé de traduction ou une
  migration.
- **[moyenne] Stratégie de jeux de données de test.** « Test écrit d'abord et vu rouge »
  + « chaque FR couverte par au moins un test fonctionnel » + `dama` : l'isolation est
  décidée, la *fabrication* des données ne l'est pas (fixtures Doctrine, factories
  maison, builders par epic). Classique divergence à deux epics, et elle est coûteuse à
  réunifier ensuite.
- **[moyenne] Stockage du ralentissement des échecs de connexion (FR-3).** Le
  `login_throttling` de Symfony s'adosse au RateLimiter et exige un `cache_pool` ; le
  memlog l'a vérifié, le spine l'a perdu en route. Et « cache applicatif » est en
  Deferred, ce qui laisse croire qu'aucun pool n'est à décider.
- **[moyenne] Sort de la session courante lors d'un changement de mot de passe
  (FR-21).** AD-9 incrémente un jeton comparé par `EquatableInterface` : il ferme
  *toutes* les sessions, y compris celle de l'auteur du changement, sauf réauthentification
  explicite du jeton en cours. FR-21 dit « clôt les *autres* sessions ». L'écart entre
  les deux est un comportement à trancher, pas une ligne de code évidente.
- **[basse] Emplacement et forme du guide de dérivation (FR-2).** La carte le place
  « racine du dépôt » ; rien ne dit s'il s'agit d'`AGENTS.md`, d'un `README`, ou d'un
  couple des deux, alors que FR-2 exige qu'un agent y trouve l'emplacement d'une entité
  métier « sans autre source ».

---

## 2. Exécutabilité — AD-1 à AD-15, règle par règle

Lecture : une règle est *contraignante* si un relecteur, un test ou un outil peut dire
oui/non sans discuter ; elle est un *vœu* si elle exprime une intention que rien ne
rattrape.

| AD | Règle applicable ? | Empêche la divergence annoncée ? | Verdict |
| --- | --- | --- | --- |
| AD-1 | Partiellement | Partiellement | Contrainte + vœu |
| AD-2 | Oui (structure) / Non (report) | Non pour le report | Mi-contrainte |
| AD-3 | Oui | Oui | Contrainte |
| AD-4 | Oui (1 phrase) / Non (3 garde-fous) | Partiellement | Contrainte + vœux |
| AD-5 | Oui | Oui | Contrainte |
| AD-6 | Partiellement | Oui sur l'essentiel | Contrainte |
| AD-7 | Oui, bloquante | Oui | **Exemplaire** |
| AD-8 | Oui, sauf un point | Oui, sauf FR-5 | Contrainte fêlée |
| AD-9 | Oui | Oui | Contrainte |
| AD-10 | Oui | Oui | Contrainte |
| AD-11 | Oui, testable | Oui | **Exemplaire** |
| AD-12 | Oui, sauf deux angles | Oui | Contrainte incomplète |
| AD-13 | Non pour routes et traductions | Non en l'état | Contrainte + vœu |
| AD-14 | Oui | Oui | Contrainte |
| AD-15 | Oui, sauf la forme de table | Oui | Contrainte incomplète |

### Constats

- **[moyenne] AD-1, troisième phrase : vœu.** « Un dérivé ne monte pas de majeure
  Symfony de son propre chef » est une règle de gouvernance dans un dépôt que le PRD §6
  dit explicitement libre de diverger. Rien dans le socle cloné ne peut l'empêcher. Les
  deux premières phrases, en revanche, sont contraignantes et vérifiables
  (`composer why-not symfony/symfony 8`, contraintes de `composer.json`).
- **[critique → voir §1] AD-2, clause de report : vœu.** « Un diff sur `src/Core/`, et
  sur rien d'autre » est contredit par le Structural Seed du même document.
- **[haute] AD-4, garde-fou n°1 : vœu.** « Le health check passe en état dégradé quand
  la file cesse d'être dépilée » ne dit pas *comment* on mesure « cesse d'être dépilée » :
  âge du plus vieux message en attente au-delà d'un seuil ? compteur de la file
  d'échec ? battement du worker ? Sans seuil nommé, l'epic FR-18 inventera un critère et
  l'epic FR-4/FR-5 en supposera un autre. Les garde-fous 2 et 3 sont des engagements de
  documentation et de déploiement, pas des invariants d'architecture — leur place est la
  checklist de livraison, pas la « Rule » d'un AD (voir §3).
- **[moyenne] AD-6 : « chemins en anglais » n'est pas mécaniquement vérifiable** (qu'est-ce
  qu'un chemin anglais ?). La moitié portante l'est : « aucun préfixe de langue, le chemin
  ne varie pas avec la locale » se teste en une assertion sur le routeur. L'énumération
  des cinq chemins est du détail qu'un builder lit dans les attributs de route.
- **[haute] AD-8 : « aucun code ne teste un nom de rôle » n'a aucun garde-fou et se
  heurte à FR-5.** deptrac ne voit pas ça. Ni règle PHPStan nommée, ni test, ni motif
  interdit (`ROLE_`, `isGranted('ROLE_`). Et FR-5 exige précisément une décision « par
  rôle ». Tant que l'obligation 2FA n'est pas reformulée en permission ou en attribut du
  rôle, cette phrase sera violée au premier sprint.
- **[moyenne] AD-8 : la commande de synchronisation du catalogue n'est rattachée à rien.**
  « Projette le catalogue dans la table au déploiement » — par quel crochet ? La carte
  FR-1 invoque une « enveloppe de déploiement » qui n'existe pas comme AD. Un oubli de
  ce crochet donne un dérivé en production sans permissions : panne silencieuse et
  totale.
- **[moyenne] AD-12 : deux angles morts.** (a) L'entité d'audit elle-même n'est pas
  exclue de l'audit par défaut alors que « tout objet métier est audité par défaut » et
  qu'un listener `onFlush` écrit une entité — risque de récursion et, à tout le moins,
  décision non posée ; même question pour `ApiToken`, `Invitation`, et les lignes du
  catalogue de permissions réécrites à chaque déploiement. (b) « Les libellés sont
  résolus à la lecture » est incompatible avec FR-11, qui exige de *figer* l'ancienne et
  la nouvelle valeur de chaque champ modifié : l'AD doit distinguer acteur/objet
  (référencés) des valeurs de champ (recopiées, par nécessité).
- **[haute] AD-13 : « ses routes et ses catalogues de traduction par des services
  taggés » est un vœu déguisé en mécanisme.** En Symfony, une route se charge par la
  configuration du routeur ou par scan d'attributs, pas par un service taggé ; un
  catalogue se déclare par `framework.translator.paths`. Les porter par tag suppose un
  chargeur de routes maison et une passe de compilation — deux pièces réelles que le
  spine ne nomme pas. Trois des cinq points d'extension (permissions, navigation,
  exclusions d'audit) sont en revanche naturellement taggés et parfaitement
  contraignants. L'AD mélange les deux familles sous une phrase unique.
- **[basse] AD-14 : tension `enabled_locales` / langues actives en base.** Les catalogues
  sont compilés par locale au warmup (configuration), alors que les langues actives sont
  une donnée d'exécution. La règle reste juste, mais la conséquence (les trois locales
  sont toujours compilées ; « actif » ne filtre que l'offre de choix) mérite une phrase,
  sinon quelqu'un tentera de piloter `enabled_locales` depuis la base.
- **[moyenne] AD-15 : une entité ou deux ?** « Invitation et réinitialisation partagent
  un mécanisme » — la même table avec un discriminant, ou deux entités de même forme ?
  Le diagramme ER ne montre qu'`INVITATION`, ce qui suggère une table unique, mais
  « rôle prévu » n'a aucun sens pour une réinitialisation. Deux epics (FR-3, FR-4)
  trancheront différemment. Les durées (7 jours, 1 heure) ne sont ni décidées ni
  reportées : la ligne « Configuration » les couvre implicitement en paramètre `app.`,
  ce qui suffit, mais il faudrait le dire.
- **[basse] AD-5 : l'expiration du jeton d'API a disparu.** FR-17 la demande
  (`[ASSUMPTION]` une heure, configurable) ; AD-5 ne parle que de révocation. Un jeton
  opaque sans expiration est un choix défendable — mais c'en est un, et il n'est pas écrit.

---

## 3. Excès — ce qui n'avait pas à être décidé ici

Le spine annonce qu'il ne réécrit pas la suite `symfony-proglab-*` puis en recopie une
bonne part. Un builder lirait tout ce qui suit dans du code conforme ou dans le skill
cité.

- **[moyenne] Le premier bloc mermaid (couches Contrôleur → Service → Repository) est
  une redite de `symfony-proglab-architecture`.** Il n'ajoute rien que la première phrase
  du Design Paradigm ne dise déjà, et il coûte plus qu'il ne rapporte (voir §7 sur la
  sémantique de ses flèches). Seul le *second* diagramme porte l'apport propre du spine :
  les deux racines et la dépendance à sens unique.
- **[moyenne] Lignes de Consistency Conventions qui sont du standard, pas un arbitrage :**
  `Services` (final readonly, injection par constructeur, pas de `<Chose>Service`),
  `Entités` (format maker, identifiants entiers, `DateTimeImmutable`, enums — intégralement
  dans `symfony-proglab-doctrine`), la moitié d'`Erreurs` (`#[WithHttpStatus]`,
  `#[WithLogLevel]`, RFC 7807), la moitié de `Routes` (nommage, préfixe de classe, une
  classe par ressource), la moitié de `Tests` (test d'abord, `dama`), et la ligne
  `Configuration` (env / `app.` / constante : recopie mot pour mot du skill
  architecture). À garder en revanche, parce que ce sont de vrais arbitrages : les deux
  espaces de noms, l'exclusion de `symfony/object-mapper`, `items` + `meta` sans curseur
  ni Pagerfanta, le 404 pour objet non permis, le préfixe des codes de permission,
  « aucun Live Component dans le socle ».
- **[haute] « MySQL | version du serveur client » n'est pas une décision, et elle en
  contredit une autre.** La ligne `Tests` exige « base de données de CI identique à la
  production » : les deux ne peuvent pas être vraies ensemble si la production est
  « ce que le client a ». Au-delà de la contradiction formelle, la version non fixée
  laisse ouvertes des questions que le socle tranchera de fait au premier commit (types
  JSON, CTE, `utf8mb4` et collation, taille d'index sur la table d'audit). Un plancher
  nommé (« MySQL 8.0 minimum, ou MariaDB exclue ») est exactement le genre d'invariant
  qu'un spine doit porter.
- **[basse] Les deux paragraphes de « réserves » sous la Stack.** Le préambule dit « les
  raisons vivent dans `.memlog.md`, pas ici », et ces paragraphes sont des raisons
  (pourquoi `auditor-bundle` est absent, pourquoi ux-toolkit est risqué). La conséquence
  actionnable — « le kit shadcn est du code du socle, versionné et maintenu par nous » —
  mérite une ligne de convention ; la justification, non.
- **[basse] AD-4, garde-fous 2 et 3 : obligations de documentation dans une « Rule ».**
  Ce sont de bonnes exigences, mais ce ne sont pas des invariants que deux unités
  pourraient trancher incompatiblement.
- **[basse] AD-6 : l'énumération des cinq chemins.** Lisible dans les routes.

---

## 4. Deferred — un report peut-il laisser deux unités diverger ?

Chaque report est évalué sur deux critères : condition de réouverture présente, et
risque de divergence pendant l'attente.

| Report | Condition de réouverture | Risque de divergence d'ici là |
| --- | --- | --- |
| Noyau partagé (bundle) | Oui — « dizaines de dérivés » | Aucun |
| Multi-tenant | Hors périmètre (PRD §7) | Aucun |
| Rétention / purge de l'audit (OQ-2) | Vague — « quand le volume l'imposera » | **Oui** |
| Exécuteur d'agents serveur | Oui, économique | Aucun |
| Transport Messenger ≠ Doctrine | Oui — « sous charge mesurée » | Aucun |
| Cache HTTP et applicatif | Oui — « sur lenteur mesurée » | **Oui** |
| Délai de grâce 2FA (OQ-UX-1) | Non — classé « produit » | **Oui** |
| Champs des jetons d'API (OQ-UX-3) | Non — classé « produit » | Aucun |
| Permissions par défaut Admin/User (OQ-5) | Oui — « premiers dérivés » | Aucun |

- **[haute] « Cache HTTP et cache applicatif » reporté alors qu'AD-10 en impose un.**
  Le spine se contredit en deux pages : AD-10 *exige* un cache du parse de la roadmap,
  avec une stratégie d'invalidation précise, et la section Deferred déclare le cache
  applicatif non tranché « sauf cette exception ». L'exception n'est pas outillée : quel
  pool, quel adaptateur (`cache.app`, système de fichiers, APCu), partagé ou dédié ? Et
  FR-3 a besoin d'un pool pour le `login_throttling`. Deux epics créeront deux pools, ou
  l'un réutilisera `cache.app` que l'autre vide au déploiement.
- **[haute] La rétention de l'audit (OQ-2) est reportée sans protéger la décision de
  schéma qu'elle implique.** Reporter *quand* on archive est légitime ; mais la forme de
  la table d'audit (index composite sur classe + identifiant, index sur la date, table
  d'archive séparée ou non, clé primaire monotone) se décide maintenant, une seule fois,
  et conditionne la possibilité d'archiver plus tard sans migration douloureuse sur un
  million de lignes. Aucune phrase ne dit ce qui doit rester vrai pour que le report
  reste peu coûteux.
- **[moyenne] Le délai de grâce 2FA reporté comme « question produit » masque la
  mécanique manquante.** La *durée* est effectivement produit ; le *point
  d'application* (où l'on intercepte, quelles routes restent permises pendant le délai,
  comment on reconnaît un compte « en délai ») est architectural, et il n'est ni décidé
  ni reporté. Voir §1, constat critique.
- **[basse] Aucun report ne porte de propriétaire ni d'échéance.** Les conditions sont
  presque toutes présentes, ce qui est l'essentiel, mais « quand le volume l'imposera »
  sans seuil chiffré n'est pas une condition observable.
- **[moyenne] Catégorie manquante : ce qui n'est ni décidé ni reporté.** Sauvegarde et
  restauration, plateforme de CI et portes de qualité, niveau PHPStan, canaux de
  journalisation, export de l'audit, locale des emails asynchrones, expiration du jeton
  d'API, version minimale de MySQL, emplacement des migrations et des tests. Une section
  « connu, non tranché, non reporté » serait plus honnête que le silence — c'est la zone
  où les epics divergeront sans même savoir qu'elles tranchent.

---

## 5. Couverture du PRD — FR-1 à FR-22 et NFR §5

### FR : état de couverture

| FR | État | Remarque |
| --- | --- | --- |
| FR-1 | Couvert | `Core/Command/`, AD-8. Le refus sur base peuplée relève du skill console (dry-run / `--force`) — délégation acceptable, mais aucune ligne `Console` dans les conventions. |
| FR-2 | Couvert, mince | Forme et emplacement du guide non décidés (§1). |
| FR-3 | **Partiel** | Ralentissement des échecs : stockage non décidé. Message ne révélant rien : non adressé. Durée du lien : non posée. |
| FR-4 | Couvert | AD-15, AD-4. |
| FR-5 | **Fêlé** | Contradiction avec AD-8 + point d'application absent (constat critique §1). |
| FR-6 | Couvert | AD-9, AD-12. |
| FR-7 | Couvert | AD-8, AD-13. |
| FR-8 | Couvert | AD-8. |
| FR-9 | Couvert | La formule d'AD-8 porte l'origine. |
| FR-10 | Couvert | AD-8 + ligne `Erreurs` (403 / 404). |
| FR-11 | Couvert | AD-3 ; voir la nuance « valeurs figées » d'AD-12 (§2). |
| FR-12 | **Partiel** | Charge utile / paramètres de libellé non spécifiés (OQ-UX-5 perdue). |
| FR-13 | **Partiel** | Export non décidé ; immuabilité non outillée (rien n'interdit un `UPDATE` : ni absence de setters, ni repository en ajout seul, alors que le NFR dit « en ajout seul »). |
| FR-14 | Couvert | AD-10 ; date de release bien rattachée au déploiement. |
| FR-15 | **Partiel** | Voir « ton » ci-dessous : la traduction statut BMAD → statut lisible n'a pas de propriétaire. |
| FR-16 | Couvert | AD-11, excellent. |
| FR-17 | Couvert, en conflit assumé | AD-5 déclare FR-17 à corriger ; voir constat ci-dessous. Expiration perdue. |
| FR-18 | **Partiel** | Critère de dégradation non défini (§2, AD-4). |
| FR-19 | **Partiel** | Locale des emails asynchrones (§1). |
| FR-20 | Couvert | AD-12. |
| FR-21 | **Partiel** | Sort de la session courante (§1). |
| FR-22 | Couvert | AD-14. |

- **[moyenne] FR-17 : le conflit est nommé mais rien ne le referme.** AD-5 écrit
  « FR-17 dit le contraire et doit être corrigé », et le PRD est en `status: final`.
  Deux documents finaux se contredisent, sans propriétaire, sans date, sans trace de
  l'amendement à produire. Le memlog porte bien l'intention (« à remonter au PRD en fin
  de run ») ; le spine, qui est l'artefact lu par les epics, devrait porter la même
  phrase avec son propriétaire.

### NFR §5 : état de couverture

- **Sécurité — [moyenne, partiel].** Hachage à l'état de l'art : absent (délégable au
  défaut `auto` de Symfony, donc acceptable). CSRF sur toute écriture : absent du spine
  alors que c'est une couture inter-epics et que l'UX le mentionne écran par écran. « Aucune
  donnée sensible dans les journaux applicatifs » : aucune règle (AD-12 ne couvre que
  l'audit, pas Monolog). « Journal d'audit en ajout seul » : pas outillé (voir FR-13).
- **Accessibilité — [haute, revendiqué puis non porté].** Le front matter déclare
  `binds: NFR §5 — … accessibilité …`, et aucun AD, aucune ligne de convention, aucune
  phrase ne la mentionne. La ligne `Frontend` énumère la pile et s'arrête. Les spines UX
  *ont* fait ce travail (plancher d'accessibilité, annonces Turbo avec `aria-live`
  permanent et titre, contrôleur Stimulus `page-focus` sur `<main>`, labels réels,
  `focus-visible`, principe « les défauts du kit shadcn priment »), mais deux résidus
  sont strictement architecturaux et ne sont nulle part : **qui possède le gabarit de
  base** qui porte la région `aria-live` et le contrôleur `page-focus` dont *chaque*
  epic dépend, et **où sont vendorisés** les composants copiés d'ux-toolkit pour que le
  socle les maintienne (le Structural Seed n'a ni `assets/` ni découpage de
  `templates/`). Un spine qui revendique un bind doit soit le porter, soit déléguer
  nommément — ici il ne fait ni l'un ni l'autre.
- **Performance — [haute, partiel et contradictoire].** p95 < 1 s pour le journal
  d'audit à 1 million d'entrées, avec filtres sur période, auteur, type d'objet et objet
  précis. Le spine impose `items` + `meta` **avec `total` et `pages`** et exclut la
  pagination par curseur : un `COUNT(*)` filtré sur un million de lignes à chaque page
  est exactement ce que l'objectif p95 interdit. Ajoutez la résolution des libellés « à
  la lecture » (AD-12) sur cinquante lignes visant N classes : N+1 requêtes par page.
  Aucun index n'est nommé, et le cache est reporté. Trois décisions du spine pointent
  ensemble dans la direction opposée au NFR.
- **Observabilité — [moyenne, partiel].** Le health check est rattaché (AD-4), mais
  « journaux applicatifs par canal » n'a aucune décision : ni liste de canaux, ni
  politique de niveau par environnement, ni règle interdisant les données personnelles.
  Deux epics créeront deux canaux aux noms incompatibles — c'est le cas d'école d'un
  invariant à une ligne.
- **Qualité — [haute, partiel].** La ligne `Tests` est bonne. Mais AD-7 affirme « la
  vérification tourne en CI et bloque » alors qu'**aucune CI n'est décidée** : ni
  plateforme, ni déclencheur, ni liste des portes (PHPStan à quel niveau ? php-cs-fixer
  en `--dry-run` bloquant ? deptrac ? tests ? où est le fichier de configuration
  deptrac, absent du seed ?). Une règle dont l'exécutant n'existe pas est un vœu, et
  c'est la règle sur laquelle reposent AD-2, AD-13 et tout le contrat de couches.
- **Dérivabilité — [basse, couvert].** AD-2, AD-13, AD-14 et la ligne `Configuration`
  portent l'essentiel.

### Exigences discrètes — ton, contre-métriques

- **[haute] Le ton « sans jargon » de FR-15 / UJ-3 n'a pas de propriétaire.** Le PRD
  insiste : Marc « n'a jamais entendu parler de BMAD et n'a pas besoin de l'apprendre ».
  Cela implique un invariant architectural précis — le vocabulaire BMAD (`epic`,
  `story`, `backlog`, `ready-for-dev`, `review`) ne franchit jamais la frontière du
  lecteur d'AD-10 ; la traduction vers les trois statuts lisibles se fait **dans le DTO
  Read**, pas dans un template ni dans un filtre Twig. Sans cette phrase, l'epic
  « lecteur » rendra les statuts bruts et l'epic « affichage » écrira sa propre table de
  correspondance — et un statut inconnu sera traité deux fois différemment, alors que
  FR-15 exige « montré comme à faire et signalé aux administrateurs ».
- **[moyenne] SM-C2 (volume du journal d'audit) n'est contrebalancée par rien.** La
  convention dit « tout objet métier est audité par défaut », ce qui pousse
  mécaniquement dans le sens que la contre-métrique interdit, et l'exclusion n'a aucun
  critère (« explicite et déclarée » dit *comment*, pas *quand*). Effet concret et
  immédiat : la commande de synchronisation du catalogue d'AD-8 écrit des permissions à
  chaque déploiement, donc des entrées d'audit système à chaque déploiement, dans le
  journal que Sophie doit pouvoir lire (UJ-4).
- **[basse] SM-C1 (taille du socle) n'a pas d'écho.** Le Deferred la frôle (« noyau
  partagé ») sans poser la règle du PRD — pas de module dans le socle avant que deux
  dérivés l'aient réclamé. Une ligne suffirait, et elle protégerait le spine contre sa
  propre tentation d'accueillir de la gestion documentaire « commune ».
- **[basse] Le p95 « en développement comme en production » du NFR performance** est un
  double objectif que rien dans l'enveloppe n'adresse (OPcache et préchargement sont
  cités par le skill deployment, pas ici).

---

## 6. Dimensions silencieuses — l'enveloppe opérationnelle

| Dimension | État | Sévérité |
| --- | --- | --- |
| Déploiement et environnements | Décidé, mais en prose et en diagramme, sans AD | moyenne |
| Stratégie d'infrastructure | Décidée sauf la version de MySQL | haute |
| Exploitation | Très partielle | moyenne |
| Sauvegarde et restauration | **Absente** | haute |
| Observabilité | Partielle | moyenne |
| Performance | Partielle et contredite | haute |
| Accessibilité | **Absente du spine** (portée par l'UX) | haute |
| Qualité et CI | Partielle ; CI invoquée, jamais décidée | haute |

- **[moyenne] Déploiement : décidé, mais pas au rang d'invariant.** Deployer par SSH,
  `releases/current/shared`, Apache + PHP-FPM 8.4, migrations à chaque release, worker
  systemd redémarré par Deployer, secrets en coffre Symfony, date de release = date du
  dernier déploiement : tout y est, réparti entre le diagramme, la prose qui le suit et
  la ligne `Configuration`. Mais la carte des capacités renvoie à une « enveloppe de
  déploiement » comme à une instance gouvernante qui n'existe pas, et rien n'a de
  « Rule » opposable. Un AD-16 de cinq lignes rassemblerait ce qui est déjà décidé et
  rendrait les crochets (synchronisation du catalogue, redémarrage du worker,
  préchargement) vérifiables.
- **[haute] Migrations et rollback s'ignorent.** « Les migrations s'exécutent à chaque
  release » + « rollback » de Deployer : revenir à la release précédente ne défait pas
  une migration. Rien ne dit si les migrations doivent être rétro-compatibles
  (déploiement en deux temps pour toute suppression de colonne) ou si le rollback est
  réputé interdit après migration. C'est un invariant classique et deux epics le
  trancheront différemment la première fois qu'une colonne est renommée.
- **[haute] Sauvegarde et restauration : absolument rien.** Ni AD, ni Deferred, ni
  question ouverte. Pour un produit qui se livre chez une PME, dont le journal d'audit
  est « en ajout seul » et tient lieu de preuve d'imputabilité, et dont l'anonymisation
  est déclarée irréversible (FR-20), l'absence de toute phrase sur la sauvegarde de la
  base, sa fréquence, sa vérification et la procédure de restauration est le trou le
  plus franc du document. La question n'est pas « qui configure mysqldump » mais :
  une restauration réintroduit-elle un utilisateur anonymisé ? qui en répond ? Au
  minimum, un report avec condition de réouverture.
- **[moyenne] Exploitation au-delà du déploiement : quasi muette.** Rotation des
  journaux, triage de la file d'échec Messenger (quelle surface ? une commande ? un
  écran d'administration ? qui la regarde ?), conduite à tenir quand le worker est mort
  pendant une nuit, supervision du health check par le client. AD-4 crée la file
  d'échec et n'en confie la lecture à personne.
- **[haute] Qualité et CI.** Voir NFR Qualité (§5). À retenir : la seule règle
  vraiment bloquante du spine (AD-7) s'appuie sur un exécutant qui n'est nulle part
  décidé, et le seed ne contient ni `tests/`, ni fichier de configuration deptrac, ni
  emplacement de la configuration de CI.
- **[basse] Pas d'environnement intermédiaire.** Le diagramme ne connaît que
  développement et production. C'est probablement le bon choix pour une PME, mais un
  choix, et il n'est pas écrit — notamment parce que FR-16 n'existe qu'en `dev` (AD-11) :
  une recette chez le client serait en `prod` et perdrait la surface de lancement, ce
  qui est cohérent mais mérite d'être dit.

---

## 7. Diagrammes — validité, et apport par rapport à la prose

**Validation outillée.** Les quatre blocs ont été passés au parseur mermaid 11.17.2
(`mermaid.parse`) : **les quatre sont syntaxiquement valides**. En particulier, les deux
points signalés comme fragiles par la grille sont corrects :

- les flèches en pointillé avec libellé entre guillemets — `Impl -. "interdit — deptrac"
  .-> ModA` — sont acceptées, guillemets et tiret cadratin compris ;
- les sous-graphes à identifiant et titre entre guillemets — `subgraph Core["src/Core —
  …"]` — sont acceptés, y compris les liens entrants depuis l'extérieur vers un nœud
  interne (`ModA --> Contract`) ;
- l'étiquette vide du diagramme ER (`PERMISSION ||--o{ ROLE_PERMISSION : ""`) est la
  forme idiomatique documentée, et elle passe ;
- référencer `Svc` avant la ligne qui lui donne son libellé est légal et le libellé
  s'applique bien.

Constats de fond, diagramme par diagramme.

- **Bloc 1 (lignes 42-51, couches) — [moyenne] n'apporte rien et brouille la
  sémantique.** C'est le schéma de `symfony-proglab-architecture` (voir §3, excès).
  Pire, ses sept arêtes n'ont pas le même sens : `Ctrl --> In` veut dire « construit »,
  `In --> Svc` « est passé à », `Svc --> Repo` « appelle », `Repo --> Ent` « retourne »,
  `Svc --> Out` « produit ». Un lecteur qui cherche la direction des dépendances y lira
  l'inverse de ce qu'AD-7 impose à deptrac. Soit on étiquette les arêtes, soit on
  supprime le bloc.
- **Bloc 2 (lignes 58-69, deux racines) — [basse] le seul diagramme qui porte une
  information propre**, et il la porte bien : la dépendance à sens unique vers
  `Contract/` et les deux interdits. Deux réserves. (a) L'arête `ModA -. "interdit" .->
  ModB` est plus absolue que la règle : AD-7 interdit `Module → Module` **hors des
  contrats publiés par Core**. Le diagramme dit « interdit » sans nuance ; la nuance est
  ce qui compte pour un builder. (b) Représenter un interdit par une arête, dans le même
  vocabulaire visuel que les dépendances réelles, ne se distingue que par le pointillé
  et le libellé — le même défaut que « la couleur seule », appliqué à la forme du trait.
  `-.->|"interdit par deptrac"|` avec un style explicite, ou une légende, lèverait
  l'ambiguïté.
- **Bloc 3 (lignes 313-325, entités) — [moyenne] apporte une information réelle, mais
  incomplète sur des points déjà identifiés comme divergents.** Il montre bien que
  `AUDIT_ENTRY` n'a pas de clé étrangère vers son objet (ce que la prose explique), et
  les cardinalités rôle / surcharge / permission sont l'apport net. Manquent, et ce sont
  précisément les manques de §1 : l'entité de réinitialisation de mot de passe (ou le
  discriminant qui la fond dans `INVITATION`), les codes de secours 2FA (fondus dans
  `TWO_FACTOR_METHOD` ? non dit), et le stockage du ralentissement de FR-3. Par ailleurs
  `INVITATION` n'a pas de relation vers `ROLE` alors qu'AD-15 lui donne un « rôle prévu ».
- **Bloc 4 (lignes 329-351, environnements) — [basse] valide, utile, mais en partie
  redondant et révélateur par ses absences.** Il dit une chose que la prose ne dit pas
  aussi nettement : seul le worker parle au SMTP, le serveur web non — c'est la
  conséquence d'AD-4 rendue visible, et c'est bien. Le reste duplique le paragraphe qui
  le suit. `D5` (agents, processus détaché) flotte sans arête, alors que l'intéressant
  serait de montrer qu'il écrit dans `_bmad-output/` *hors* de l'application (AD-10,
  AD-11). Et le diagramme rend visible, par omission, ce que §6 reproche au document :
  pas de nœud CI, pas de sauvegarde, pas de supervision.

---

## Annexe — récapitulatif par sévérité

**Critiques (2)**
1. FR-5 (2FA obligatoire par rôle) est irréalisable sous AD-8 (« aucun code ne teste un
   nom de rôle »), et son point d'application n'est nulle part. §1, §2, §5.
2. Le câblage framework des deux racines (mappings Doctrine, chargement des routes,
   chemins du translator) n'est pas tranché, ce qui rend fausses dans l'esprit les deux
   promesses phares : AD-13 « ajouter un module ne modifie aucun fichier de `src/Core/` »
   et AD-2 « le report d'un correctif est un diff sur `src/Core/`, et sur rien d'autre ».
   §1, §2.

**Hautes (9)**
3. Locale des emails asynchrones (AD-4 × AD-14) sans propriétaire. §1.
4. Export du journal d'audit (FR-13) non décidé, et incompatible avec la pagination
   imposée. §1, §5.
5. Charge utile / paramètres de libellé d'une action métier : OQ-UX-5 perdue. §1, §5.
6. Emplacement des templates, catalogues, migrations et tests par racine ; `migrations/`
   et `tests/` absents du seed. §1.
7. Accessibilité revendiquée en front matter, portée par aucun AD ni délégation nommée ;
   les deux résidus architecturaux (gabarit de base `aria-live` + `page-focus`,
   vendorisation du kit) sont orphelins. §5, §6.
8. Performance : `total`/`pages` sans curseur + libellés résolus à la lecture + cache
   reporté + aucun index nommé, contre un p95 < 1 s à 10⁶ entrées. §5, §6.
9. Qualité et CI : AD-7 « bloque en CI » sans CI décidée, sans niveau PHPStan, sans
   configuration deptrac dans le seed. §5, §6.
10. Sauvegarde et restauration : dimension entièrement absente, y compris du Deferred. §6.
11. Version de MySQL non fixée, en contradiction directe avec « base de données de CI
    identique à la production ». §3, §6. (Avec, au même rang : AD-4 garde-fou n°1, AD-13
    routes/traductions par services taggés, le cache reporté qu'AD-10 impose, la
    rétention d'audit reportée sans protéger le schéma, le ton sans jargon de FR-15 sans
    propriétaire, et migrations × rollback.)

**Moyennes (14)** — AD-1 clause de gouvernance ; AD-6 « chemins anglais » non
vérifiable ; AD-8 sans garde-fou sur les noms de rôles ; AD-8 crochet de synchronisation
non rattaché ; AD-12 auto-audit et valeurs figées ; AD-15 une table ou deux ; stockage du
`login_throttling` ; session courante au changement de mot de passe ; jeux de données de
test ; conflit FR-17 sans propriétaire ; SM-C2 sans contrepoids ; déploiement décidé mais
sans AD ; exploitation (file d'échec sans lecteur) ; observabilité (canaux de journaux) ;
bloc mermaid 1 redondant et sémantiquement flou ; bloc ER incomplet ; excès des lignes de
conventions recopiées du standard.

**Basses (9)** — AD-5 expiration du jeton ; AD-14 `enabled_locales` ; guide de dérivation
(FR-2) sans forme ; énumération des cinq chemins d'AD-6 ; paragraphes de « réserves »
sous la Stack ; AD-4 garde-fous documentaires ; SM-C1 sans écho ; p95 en développement ;
absence d'environnement intermédiaire ; nuance manquante sur l'arête `Module → Module` du
bloc 2 ; `D5` sans arête au bloc 4.
