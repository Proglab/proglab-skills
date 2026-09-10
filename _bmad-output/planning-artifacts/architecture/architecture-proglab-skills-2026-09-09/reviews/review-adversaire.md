---
title: Revue adverse — ARCHITECTURE-SPINE.md (socle ERP custom proglab)
reviewer: relecteur adverse
lens: attaquer le spine en adversaire — paires conformes mais incompatibles
target: ../ARCHITECTURE-SPINE.md
sources:
  - ../../../prds/prd-proglab-skills-2026-09-09/prd.md
  - ../../../prds/prd-proglab-skills-2026-09-09/addendum.md
  - ../../../ux-designs/ux-proglab-skills-2026-09-09/EXPERIENCE.md
date: 2026-09-10
---

# Revue adverse — ARCHITECTURE-SPINE.md

## Méthode et verdict

Je ne discute aucune des quinze décisions : je les prends pour acquises et je cherche
des **paires d'unités du niveau inférieur** — deux modules métier d'un dérivé, deux
epics du socle, deux développeurs ou deux agents `bmad-build` travaillant en
parallèle — qui respectent AD-1 à AD-15 **à la lettre** et construisent pourtant des
choses incompatibles. Chaque paire trouvée est un trou : une décision qui manque, ou
une décision existante dont la formulation laisse passer les deux lectures.

Verdict : le spine tranche correctement les quinze questions qu'il s'est posées, mais
il tranche surtout des questions de **mécanisme** et presque aucune question de
**propriété et de forme de donnée**. Les trois promesses centrales — « le report d'un
correctif est un diff sur `src/Core/` » (AD-2), « une seule table d'audit donc un seul
filtre » (AD-3 / FR-13) et « la désactivation ferme tout » (AD-9 / FR-6) — sont toutes
tenues par des conventions non écrites plutôt que par une décision. Trente-huit paires
suivent, dont douze critiques.

Ce que je ne reproche pas : les versions, la pile, le choix de l'audit maison, le refus
d'un endpoint d'authentification, `#[When('dev')]`, l'entité d'invitation. Ces choix
sont défendables et défendus.

---

## A. Frontière, propriété du code et report de correctif

### T-1 — Un module peut dépendre des classes internes de `Core`, et AD-7 ne l'interdit pas

- **La paire.** `src/Module/Hr/Service/EmployeeOffboarder` injecte
  `App\Core\Service\UserDeactivator` (la classe concrète).
  `src/Module/Portal/Service/AccountCloser` injecte
  `App\Core\Contract\UserDeactivatorInterface`. Deux modules, deux développeurs, chacun
  sur son dérivé.
- **Le scénario.** AD-7 énumère exactement trois règles deptrac : les couches proglab,
  l'interdit `Core → Module`, l'interdit `Module → Module` hors contrats publiés. Le sens
  `Module → Core` n'est contraint nulle part, donc `Module → Core\Service`,
  `Module → Core\Entity`, `Module → Core\Repository` passent la CI. Un correctif du socle
  qui renomme, scinde ou change la signature de `UserDeactivator` — exactement ce qu'un
  correctif fait — casse le premier dérivé à la compilation du conteneur et pas le second.
  AD-2 promet que le report est « un diff sur `src/Core/` et sur rien d'autre » : avec
  cette paire, c'est faux pour une partie du parc, et on ne le découvre qu'au déploiement
  chez le client.
- **AD à resserrer.** AD-7 doit poser la règle symétrique : un module ne dépend de `Core`
  **que** par `src/Core/Contract/`, et la couche deptrac `Module` n'a `Core\Contract` pour
  unique dépendance autorisée côté socle. Corollaire à écrire : toute capacité du socle
  dont un module a besoin existe comme interface publiée ; si elle manque, c'est une
  modification du socle, pas un accès direct.
- **Sévérité.** critique

### T-2 — Rien n'interdit à un dérivé d'éditer `src/Core/`

- **La paire.** Le dérivé « Menuiserie Dubois » ajoute un champ `phone` à
  `src/Core/Entity/User.php` et la colonne correspondante, parce que le client veut un
  téléphone sur la fiche. Le dérivé « Transport Lemaire » ajoute à la place un
  `Module/Directory/Entity/UserProfile` avec une clé étrangère vers `User`. Les deux
  respectent AD-2 (le métier du client est ailleurs… ou pas), AD-13 (aucun des deux n'a
  *exigé* une modification du socle pour accueillir un module) et AD-7.
- **Le scénario.** AD-13 interdit à **un module** d'obliger le socle à changer ; aucune
  décision n'interdit à **un dérivé** de changer le socle directement. Or c'est le geste le
  plus naturel pour un développeur pressé, et c'est celui qui détruit AD-2 : le correctif
  de sécurité suivant sur `Core/Entity/User.php` ne s'applique plus proprement sur le
  premier dérivé. Même paire avec `config/packages/security.yaml` (ajout d'un firewall pour
  un module), `templates/base.html.twig`, ou `Core/Security/PermissionVoter.php` (« juste un
  cas particulier pour ce client »).
- **AD manquant.** AD-2 doit porter l'interdit explicite : **un dérivé ne modifie jamais un
  fichier de `src/Core/` ni un fichier de `config/` livré par le socle** ; un besoin qui
  l'exigerait est remonté au socle, tranché là, puis reporté. Et la liste des fichiers
  « propriété du socle » doit être mécanisable (un manifeste plus une vérification dans la
  CI du dérivé), sinon l'interdit n'existe pas — AD-7 a déjà posé le principe que la revue
  ne suffit pas.
- **Sévérité.** critique

### T-3 — Les migrations ne sont pas dans le périmètre du report, et AD-2 affirme le contraire

- **La paire.** Un correctif du socle ajoute un index sur `audit_entry` : il embarque
  `migrations/Version20261102101500.php`. Le dérivé A a déjà dix migrations de module dans
  le même dossier, numérotées plus haut. Le dérivé B a régénéré un
  `doctrine:migrations:diff` après avoir ajouté son module, et sa migration contient aussi
  des tables du socle.
- **Le scénario.** AD-2 énonce « le report d'un correctif du socle est un diff sur
  `src/Core/`, et sur rien d'autre ». C'est faux dès qu'un correctif touche le schéma, ce
  qui est le cas courant. Sans décision, les deux dérivés mélangent migrations du socle et
  migrations métier dans un dossier unique : le diff du correctif entre en conflit, et le
  `diff` régénéré du dérivé B recrée des tables du socle à l'identique, rendant
  indiscernable ce qui appartient à qui.
- **AD manquant.** Un AD de propriété des migrations : deux espaces de noms et deux dossiers
  (`migrations/Core/` et `migrations/<Dérivé>/`) déclarés dans
  `doctrine_migrations.migrations_paths`, le socle ne générant jamais que dans le premier et
  le dérivé jamais que dans le second ; et la reformulation d'AD-2 en « un diff sur
  `src/Core/`, le `config/` du socle, les `templates/` du socle et `migrations/Core/` ».
- **Sévérité.** haute

### T-4 — Noms de routes et chemins : deux modules s'écrasent silencieusement

- **La paire.** `src/Module/Sales/Controller/QuoteController` déclare
  `#[Route('/quotes', name: 'app_quote_')]` avec `app_quote_show`.
  `src/Module/Purchasing/Controller/QuoteController` déclare exactement les mêmes, pour un
  devis fournisseur. Les deux respectent AD-6 (chemins anglais, invariables) et la
  convention « `app_<ressource>_<action>`, préfixe de chemin au niveau de la classe, une
  classe de contrôleur par ressource ».
- **Le scénario.** Symfony ne lève **aucune erreur** sur un nom de route dupliqué : le
  dernier chargé gagne, silencieusement. Tous les `path('app_quote_show')` du premier module
  mènent au contrôleur du second — donc à un autre attribut de permission, donc
  potentiellement à un objet que l'utilisateur ne devrait pas voir, avec un 404 là où
  l'utilisateur attend sa page, ou un accès accordé par le mauvais voter. Même problème sur
  les chemins : `/quotes` déclaré deux fois, la seconde déclaration est morte.
- **AD manquant.** Un AD de namespacing des surfaces partagées : les routes d'un module sont
  préfixées par le nom du module en nom (`app_<module>_<ressource>_<action>`) et en chemin
  (un segment racine réservé par module), `Core` réserve les chemins qu'il utilise déjà, et
  la CI échoue sur une collision de nom de route.
- **Sévérité.** critique

### T-5 — Traductions, templates, contrôleurs Stimulus : trois autres espaces plats non attribués

- **La paire.** `Module/Sales` livre `translations/messages.fr.yaml` avec la clé
  `quote.status.draft` = « Brouillon », un template `templates/quote/show.html.twig` et
  `assets/controllers/quote_controller.js`. `Module/Purchasing` livre les mêmes trois noms
  avec d'autres contenus. Les deux respectent AD-13 (« un module livre ses catalogues de
  traduction ») et la convention « catalogues par domaine, clés en anglais pointées ».
- **Le scénario.** Symfony fusionne les catalogues d'un même domaine clé par clé, dernier
  chargé gagnant : une moitié de l'interface du premier module affiche le vocabulaire du
  second, sans erreur. Le template est résolu par chemin : l'un des deux devient invisible.
  L'importmap d'AssetMapper est plate : un seul `quote_controller` survit. Rien dans le spine
  n'attribue ces trois espaces de noms, alors qu'AD-13 y envoie précisément les modules
  écrire.
- **AD à resserrer.** AD-13 doit nommer, pour chaque contribution, l'espace réservé : un
  **domaine de traduction égal au nom du module** (`sales.fr.yaml`), un **namespace Twig**
  (`@Sales/quote/show.html.twig`), un **préfixe de contrôleur Stimulus**
  (`sales--quote`), et l'interdit d'écrire dans les domaines, templates et contrôleurs du
  socle (qui rejoint T-2).
- **Sévérité.** haute

### T-6 — La surface de rebranding n'est pas isolée, et le kit shadcn est du code du socle

- **La paire.** Le dérivé A applique la marque du client en modifiant
  `templates/components/button.html.twig` et `badge.html.twig` (couleurs, rayons,
  capitales). Le dérivé B la pose dans un unique fichier de jetons. Les deux respectent le
  spine — qui dit seulement que le kit est « un design system que le socle maintient
  lui-même » — et `DESIGN.md`, qui dit que la marque est du code dans le dérivé.
- **Le scénario.** Le socle corrige un composant du kit (régression d'accessibilité, anneau
  de focus, rupture de compatibilité d'UX Toolkit, que le spine reconnaît comme
  expérimental). Le report est impossible sur le dérivé A : le fichier a divergé pour des
  raisons de marque. L'écart d'accessibilité connu du thème de départ
  (`DESIGN.md`, anneau de focus clair) ne sera corrigé que sur une partie du parc.
- **AD manquant.** Un AD de frontière de marque : le rebranding d'un dérivé touche
  **uniquement** un fichier de jetons déclaré (couleurs, rayons, typographie, logo) ; les
  templates de composants du kit sont propriété du socle au sens de T-2 ; un composant qui
  doit changer de structure pour un client est copié sous le namespace du dérivé, jamais
  modifié en place.
- **Sévérité.** haute

---

## B. Permissions

### T-7 — « Super admin a toutes les permissions » : deux lectures, l'une interdite, l'autre cassée

- **La paire.** L'epic « Rôles et permissions » implémente Super admin comme un rôle portant
  une ligne `role_permission` pour chaque code existant. L'epic « Dérivation » ajoute
  `Module/Quote`, qui publie `quote.read` et `quote.validate` par service taggé (AD-13) ; la
  commande de synchronisation d'AD-8 les projette dans la table `permission`.
- **Le scénario.** Après déploiement, Fabrice (Super admin) reçoit un 403 sur son propre
  module : la synchronisation a créé les codes mais ne les a attachés à personne. Le
  développeur suivant corrige comme tout le monde corrige — un court-circuit « si le rôle
  est Super admin, accorde tout » dans le voter — ce qu'AD-8 interdit explicitement (« aucun
  code ne teste un nom de rôle »). Deux dérivés finissent avec deux sémantiques de Super
  admin : l'un où une permission oubliée bloque l'équipe de développement, l'autre où un nom
  de rôle contourne le voter. Le glossaire du PRD dit « toutes les permissions » ; le spine
  ne dit pas comment.
- **AD à resserrer.** AD-8 doit trancher la sémantique de Super admin **comme donnée** :
  soit la commande de synchronisation attache tout code nouvellement déclaré au rôle Super
  admin (et l'audite), soit ce rôle porte un code joker `*` que le voter lit comme une donnée
  et non comme un nom de rôle. Et dire ce qui arrive à un code nouveau pour les autres rôles
  (rien par défaut — à écrire, sinon deux modules supposeront l'inverse).
- **Sévérité.** critique

### T-8 — Préfixe des codes de permission : la convention se contredit et la synchronisation est muette

- **La paire.** `Module/Sales` (devis et factures) déclare `quote.validate` et
  `invoice.send`, en imitant le socle, dont les exemples préfixent **par domaine**
  (`user.invite`, `audit.read`, `roadmap.notes`). `Module/Purchasing` déclare lui aussi
  `quote.validate`, pour un devis fournisseur. La convention dit « un module préfixe par son
  nom » ; les exemples du socle disent l'inverse pour le socle lui-même, ce qui autorise la
  lecture « préfixe par domaine ».
- **Le scénario.** Les deux services taggés déclarent le même code. La synchronisation le
  projette une fois. Un administrateur qui accorde « Valider un devis » accorde en réalité
  les deux, dans deux domaines métier différents, derrière un seul libellé. Personne ne voit
  rien : aucune décision ne demande à la commande d'échouer sur un doublon — elle est décrite
  seulement comme projetant le catalogue et marquant les codes obsolètes.
- **AD à resserrer.** AD-8 doit poser une règle de préfixe sans double lecture (préfixe égal
  au nom du module en `snake_case`, `core.` réservé au socle, domaines internes après le
  préfixe : `sales.quote.validate`), et exiger que la synchronisation **échoue** sur un code
  déclaré deux fois, sur un code ne respectant pas le préfixe de son déclarant, et sur un
  code réutilisé avec un autre libellé.
- **Sévérité.** critique

### T-9 — Autorisation au niveau objet : « un unique voter » ne couvre pas le 404 par objet

- **La paire.** La convention d'erreurs exige « objet non permis : 404, identique à un objet
  inexistant ». `Module/Quote` a une règle « un commercial ne voit que ses propres devis » :
  le développeur A l'implémente en ajoutant un voter de module (donc deux voters, contre la
  lettre d'AD-8) ; le développeur B l'implémente dans son service, qui lève une exception
  métier `#[WithHttpStatus(404)]` (voter unique respecté, mais autorisation logée hors du
  mécanisme d'autorisation).
- **Le scénario.** Les deux rendent 404, donc rien ne se voit — mais la règle du premier est
  centralisée et testable, celle du second est noyée dans la logique métier et ne s'applique
  que là où le développeur y a pensé : l'export CSV, l'API et la liste paginée l'oublient
  typiquement. FR-10 (« chaque action et chaque zone vérifie la permission effective ») n'est
  plus vérifiable uniformément, et AD-8 ne donne aucun emplacement à l'autorisation par
  objet, qu'elle ne mentionne pas.
- **AD manquant.** AD-8 doit distinguer la permission (code, sans sujet) et la portée par
  objet, et publier un contrat `Core/Contract/ObjectScopeRule` (par type d'objet) que le
  **voter unique** compose : le voter reste unique, les règles par objet sont des
  contributions déclarées (AD-13), et le 404 est rendu par un seul endroit.
- **Sévérité.** haute

### T-10 — La mémoïsation « pour la durée de la requête » n'a pas de sens dans un worker

- **La paire.** Le worker Messenger d'AD-4 traite la file pendant trois jours sans
  redémarrer ; la commande de synchronisation du catalogue tourne au déploiement ; un
  handler de module vérifie une permission avant d'agir au nom de l'utilisateur d'origine.
  Le développeur A mémoïse dans une propriété du service (« durée de la requête » = durée du
  processus). Le développeur B mémoïse par `RequestStack`.
- **Le scénario.** Dans le premier cas, un retrait de permission n'est jamais vu par le
  worker : FR-7 (« s'applique immédiatement ») est violé hors HTTP, et deux modules se
  comportent différemment pour la même action selon qu'elle passe par le navigateur ou par la
  file.
- **AD à resserrer.** AD-8 doit nommer l'unité de mémoïsation autrement que « la requête » :
  une **unité de travail** (requête HTTP, message consommé, exécution de commande), avec un
  point de réinitialisation explicite entre deux messages.
- **Sévérité.** moyenne

---

## C. Journal d'audit

### T-11 — Deux conventions de « type d'objet », et le filtre de FR-13 devient faux

- **La paire.** Le listener `onFlush` d'AD-3 écrit ce que lui donne Doctrine :
  `ClassMetadata::getName()`, soit `App\Module\Sales\Entity\Quote`. Le service d'actions
  métier reçoit l'objet de l'appelant : `Module/Sales` lui passe l'entité (et le service fait
  `get_class()`, qui rend `Proxies\__CG__\App\Module\Sales\Entity\Quote` sur un proxy non
  initialisé) ; `Module/Purchasing` lui passe la chaîne `'Quote'`, parce que la signature
  accepte une chaîne. Les trois respectent AD-12 à la lettre : « classe et clé primaire ».
- **Le scénario.** Le Select « type d'objet » de FR-13 liste
  `App\Module\Sales\Entity\Quote`, `Proxies\__CG__\…\Quote` et `Quote` comme trois types
  distincts. Sophie filtre sur « Devis » et manque les deux tiers des entrées — la panne
  exacte que SM-4 mesure. Ajoutez l'héritage (`Document` / `Invoice` / `CreditNote` : racine
  ou classe concrète ?) et le filtre devient inutilisable. Enfin, l'interdit « pas
  d'identifiants internes bruts » (FR-13) impose de traduire ce type en libellé : `Core` ne
  peut pas nommer une classe de module (AD-13), et AD-13 ne liste pas « libellés des types
  d'objet » parmi les contributions d'un module.
- **AD manquant.** Une clé canonique de type d'objet, calculée dans un unique service de
  `Core` à partir des métadonnées Doctrine (entité racine, proxy résolu, code stable court),
  jamais passée en chaîne par un appelant ; la source du Select de FR-13 (catalogue déclaré
  et non `SELECT DISTINCT`) ; et l'ajout à AD-13 d'un contrat de **libellés traduits par type
  d'objet** publié par le module.
- **Sévérité.** critique

### T-12 — Racine d'agrégat ou feuille : deux modules auditent le même événement sur deux objets

- **La paire.** Dans `Module/Sales`, modifier le prix d'une ligne de devis écrit une entrée
  sur `QuoteLine` (c'est l'entité que Doctrine voit changer). Dans `Module/Stock`, le
  développeur déclare l'action métier sur l'entrepôt parent, « parce que c'est ce que
  l'utilisateur filtre ». Les deux respectent AD-3 et AD-12.
- **Le scénario.** UJ-4 est la promesse du produit : Sophie filtre sur l'objet « Tarif » et
  trouve l'entrée. Avec la première convention, elle doit connaître le nom technique de
  l'entité enfant ; avec la seconde, elle ne trouve jamais l'objet réellement modifié. Le
  même flou couvre les collections : ajouter une ligne à un devis produit-il une entrée
  « création de QuoteLine », une entrée « modification de Quote », ou les deux ? Le `.memlog`
  reconnaît le coût (« collections et suppressions comprises ») sans trancher la question.
- **AD manquant.** Une règle d'attribution : toute entrée porte l'objet modifié **et** sa
  racine d'agrégat déclarée (un contrat `AuditAggregateRoot` par module), le filtre de FR-13
  interrogeant la racine ; plus la règle explicite pour les changements de collection (une
  entrée sur la racine, pas deux).
- **Sévérité.** haute

### T-13 — Le même événement produit deux entrées, de deux types

- **La paire.** L'epic « Cycle de vie du compte » déclare l'action métier
  « désactivation » (FR-12 l'exige, sa liste de référence la nomme) et écrit
  `User.isActive = false`, que le listener d'AD-3 enregistre aussi comme modification.
  `Module/Order` décide à l'inverse que la transition de statut **est** l'audit et ne déclare
  aucune action métier.
- **Le scénario.** Dans le journal, la désactivation de Karim apparaît deux fois, une fois
  par type d'entrée ; la validation d'une commande n'apparaît qu'une fois, en
  « modification ». Le filtre « type d'entrée » de FR-13 ne veut plus rien dire : filtrer
  « action métier » cache la moitié des événements métier, filtrer « modification » double
  l'autre moitié. La contre-métrique SM-C2 (journal illisible) est atteinte par construction.
  Même paire sur l'invitation (AD-15) : « invitation envoyée » et « acceptée » en actions
  métier **plus** les modifications de l'entité `Invitation`, dont son champ d'empreinte de
  jeton.
- **AD manquant.** Une règle d'exclusivité : quand un événement est déclaré comme action
  métier, les champs qui le portent sont exclus de l'audit des modifications pour cette
  entité (la liste d'exclusions d'AD-12 doit descendre au champ, pas seulement à l'objet) ;
  plus la liste des entités purement techniques exclues d'office (`AuditEntry` elle-même —
  rien ne le dit —, `Invitation`, `ApiToken`, la table de file Messenger).
- **Sévérité.** haute

### T-14 — L'anonymisation de FR-20 écrit dans l'audit l'email qu'elle devait effacer

- **La paire.** L'epic « Anonymisation » (FR-20) remplace nom et email sur `User` et déclare
  l'action métier « anonymisation ». L'epic « Journal d'audit » (AD-3) audite toutes les
  entités métier par défaut, `User` comprise, avec l'avant et l'après par champ.
- **Le scénario.** Anonymiser Karim écrit dans une table déclarée immuable et en ajout seul
  (NFR, FR-13) une entrée « email : karim.benali@client.be → anonyme-12@local ». La donnée
  personnelle que la demande d'effacement visait est désormais conservée pour toujours, dans
  la table que le socle refuse par principe de modifier. AD-12 est conçu pour que
  l'anonymisation n'ait **pas** à réécrire l'audit — et c'est précisément par là que l'audit
  se remplit de la donnée effacée. Deux développeurs corrigeront différemment : l'un exclut
  définitivement `User.email` et `User.name` de l'audit (on perd la trace des changements
  d'email légitimes), l'autre laisse fuiter.
- **AD manquant.** Un AD d'effacement : les mutations porteuses d'effacement passent par un
  chemin qui n'écrit **qu'**une entrée d'action métier, sans avant/après ; tout champ qu'une
  opération d'effacement réécrit figure dans la liste d'exclusion à l'écriture d'AD-12 ; et
  la règle est couverte par un test (chercher l'ancien email dans `audit_entry` après
  anonymisation).
- **Sévérité.** critique

### T-15 — La forme des valeurs avant/après n'est décidée nulle part

- **La paire.** `Module/Sales` stocke les valeurs telles que Doctrine les donne :
  `customer: 3 → 7`, `status: 1 → 2` (enum adossé à des entiers — la convention dit « enums
  natifs avec `enumType:` » sans dire `string` ou `int`), une date en chaîne ISO.
  `Module/Stock` stocke le `__toString()` de l'entité liée et le libellé traduit de l'enum,
  pour que ce soit lisible.
- **Le scénario.** Le premier viole FR-13 (« valeurs lisibles, pas d'identifiants internes
  bruts ») ; le second viole AD-12 (« jamais par un libellé figé à l'écriture ») et
  réintroduit exactement le problème que l'anonymisation devait éviter. Le panneau d'audit,
  l'export CSV et le filtre « objet précis » doivent traiter les deux. Le spine ne dit rien de
  la **colonne la plus lue de sa table principale** : ni sa forme (JSON typé ? scalaire plus
  référence ?), ni le traitement des relations, des collections, des dates (fuseau : T-34),
  des enums, des colonnes JSON, des fichiers.
- **AD manquant.** Un AD de sérialisation des valeurs d'audit : un type discriminé
  (`scalar` ; `enum` avec classe et valeur technique ; `reference` avec type d'objet et clé ;
  `datetime` en UTC ; `collection` avec deltas de références), produit par un unique service
  de `Core`, résolu en libellé **à la lecture** par le résolveur publié par le module
  (T-11) ; plus la fixation du backing des enums (`string`, valeurs stables) dans les
  conventions.
- **Sévérité.** critique

### T-16 — Pour une suppression, « référencer sans recopier » ne peut pas fonctionner

- **La paire.** `Module/Sales` supprime réellement ses lignes de devis annulées (`remove()`
  est autorisé dans un repository). `Module/Stock` n'efface jamais rien. FR-11 exige une
  entrée pour « toute création, modification ou **suppression** ».
- **Le scénario.** L'entrée de suppression référence `QuoteLine#412`, qui n'existe plus :
  AD-12 résout les libellés à la lecture, donc la page de FR-13 affiche un objet qu'elle ne
  peut plus nommer — « — » pour l'un des modules, une exception pour l'autre si le résolveur
  ne prévoit pas l'absence. AD-12 tient explicitement « parce qu'aucune fonction ne supprime
  un utilisateur » : ce raisonnement ne se transpose pas aux entités de module, et rien
  n'interdit la suppression ailleurs.
- **AD à resserrer.** AD-12 doit trancher l'un des deux : (a) une entité auditée n'est jamais
  supprimée physiquement (suppression logique, règle inscrite dans le guide de dérivation),
  ou (b) l'entrée de suppression porte, par exception écrite, un libellé figé — auquel cas ce
  champ entre dans le périmètre de l'effacement (T-14). Dans les deux cas, le rendu d'une
  référence non résoluble est décidé une fois.
- **Sévérité.** haute

### T-17 — Les codes d'action métier n'ont ni catalogue, ni convention de clé, ni détection de collision

- **La paire.** `Module/Sales` déclare le code `quote.validated` et la clé de traduction
  `audit.action.quote.validated` dans le domaine `audit`. `Module/Stock` déclare
  `stock_movement_approved` et range son libellé sous `stock.audit.approved`. Les deux
  respectent la convention : « code d'action stable et libellé traduisible ».
- **Le scénario.** Le lecteur d'audit de `Core` doit rendre un libellé pour un code
  arbitraire : pour le second module, il ne trouve pas la clé et affiche le code brut —
  interdit par FR-13 et par Voice and Tone. Les permissions ont un catalogue déclaré, une
  commande de synchronisation et une notion d'obsolescence (AD-8) ; les codes d'action, qui
  sont pourtant la moitié de la table d'audit, n'ont rien. Deux modules peuvent aussi déclarer
  le même code pour deux événements différents, sans aucun signal.
- **AD manquant.** Un catalogue des codes d'action métier de même forme qu'AD-8 : déclaré par
  service taggé (AD-13), préfixé par module, clé de traduction dérivée mécaniquement du code
  dans un domaine fixe, échec de la CI sur un doublon ou sur une clé manquante dans l'une des
  trois langues.
- **Sévérité.** haute

### T-18 — Acteur absent ou non humain : trois réponses possibles, toutes conformes

- **La paire.** L'epic « Connexion » doit auditer « échec de connexion » (FR-12, liste de
  référence) : il n'y a pas d'acteur identifiable, l'email saisi peut n'exister pas. Le
  développeur A écrit une entrée avec acteur nul et l'email tenté dans une colonne de
  contexte ; le développeur B attribue l'entrée à l'acteur système ; le développeur C n'écrit
  rien, faute de savoir quoi mettre. En parallèle, un handler Messenger de `Module/Sales`
  relance automatiquement un devis et attribue l'action à l'utilisateur d'origine, tandis que
  le handler d'email de `Core` attribue la sienne à l'acteur système.
- **Le scénario.** AD-12 impose « acteur par identifiant » et « acteur système nommé hors
  requête HTTP » : les deux règles se contredisent dès qu'un travail en file agit **au nom**
  de quelqu'un, et la première est inapplicable quand l'acteur n'existe pas. Le filtre
  « auteur » de FR-13 répond « Système » pour un module et « Karim » pour l'autre sur des flux
  structurellement identiques ; et l'email tenté écrit en clair dans une table immuable est
  une donnée personnelle de quelqu'un qui n'est peut-être pas même utilisateur.
- **AD à resserrer.** AD-12 doit définir : un acteur nullable avec un motif déclaré
  (`anonymous`, `system:<nom>`), une énumération fermée des acteurs système, la règle de
  **propagation de l'acteur d'origine** dans l'enveloppe Messenger et dans les commandes
  (l'acteur système ne sert que lorsqu'il n'y a véritablement personne), et ce qui est permis
  comme contexte sur un échec d'authentification (email tronqué ou haché, jamais en clair).
- **Sévérité.** haute

### T-19 — Les compteurs et jetons techniques polluent un journal que FR-13 veut lisible

- **La paire.** AD-9 incrémente un jeton de sécurité sur `User` à chaque changement de mot
  de passe ; l'epic 2FA ajoute un compteur d'échecs sur `User` (T-26) ; l'epic Profil
  enregistre le thème choisi (Flux 9) sur le compte. `User` est une entité métier auditée par
  défaut (AD-3).
- **Le scénario.** Le journal de Sophie se remplit de « securityToken : 4 → 5 », « thème :
  système → sombre », « failedTwoFactorAttempts : 2 → 3 » — des identifiants internes bruts
  interdits par FR-13, à un volume qui noie les entrées utiles (SM-C2). Deux epics décideront
  différemment d'exclure ou non leurs champs, et l'exclusion d'AD-12 est décrite au niveau de
  l'objet et des « champs sensibles », pas des champs techniques non sensibles.
- **AD à resserrer.** AD-12 doit distinguer deux listes : champs **sensibles** (jamais
  écrits) et champs **techniques** (non audités, sans intérêt pour un lecteur), toutes deux
  tenues dans `Core` et extensibles par module, avec la règle que tout champ ajouté à une
  entité du socle déclare sa catégorie.
- **Sévérité.** haute

---

## D. Transactions

### T-20 — Le contrat transactionnel de l'enregistreur d'actions métier n'existe pas

- **La paire.** AD-3 dit que le service d'audit est appelé depuis la couche service, et les
  conventions disent que le service appelle `flush()`. L'epic « Connexion » appelle
  `AuditRecorder::record('core.login')` depuis un listener d'authentification, où **aucun
  `flush()` métier** n'a lieu. L'epic « Devis » appelle `record('sales.quote.validated')`
  puis poursuit et lève une exception métier avant le `flush()`.
- **Le scénario.** Si l'enregistreur se contente de `persist()`, l'entrée de connexion est
  **silencieusement perdue** (personne ne flush), alors que FR-12 l'exige nommément — et la
  perte est invisible. S'il flush lui-même, l'entrée « devis validé » est committée pour un
  devis qui ne l'a jamais été, et le même appel depuis un contexte déjà en transaction (ou
  depuis `onFlush`) lève une erreur Doctrine. Les deux implémentations respectent AD-3 mot
  pour mot. Un troisième développeur ajoutera un `flush()` défensif après chaque `record()`,
  ce qui commite à moitié une transaction métier.
- **AD manquant.** Un AD transactionnel de l'audit, qui dise : (1) les entrées d'action
  métier participent à la transaction de l'action, donc disparaissent avec un rollback, ce
  qui est voulu ; (2) l'enregistreur ne flush jamais ; (3) les chemins sans écriture métier
  (connexion, échec de connexion, consultation) passent par un écrivain dédié qui ouvre sa
  propre transaction, nommé et seul autorisé à le faire ; (4) deux tests, l'un vérifiant
  qu'un rollback ne laisse aucune entrée, l'autre qu'un chemin sans flush en laisse bien une.
- **Sévérité.** critique

### T-21 — L'échec de l'écriture d'audit n'a pas de politique

- **La paire.** Le listener `onFlush` de `Core/Audit` laisse remonter une exception
  d'écriture. Le développeur d'un module, dont les tests métier cassent parce qu'une valeur
  d'audit dépasse la colonne, enveloppe l'appel du service d'audit dans un `try/catch` et un
  log.
- **Le scénario.** Premier cas : le client ne peut plus enregistrer un devis parce qu'une
  ligne d'audit est invalide — panne totale d'une fonction métier pour une raison de
  traçabilité. Second cas : l'audit se perd silencieusement, la table n'est plus exhaustive,
  et la promesse de SM-4 (« zéro question restée sans réponse ») devient fausse sans que
  personne ne le sache. Deux modules du même dérivé peuvent avoir les deux comportements.
- **AD manquant.** La politique d'échec, écrite une fois : l'audit est **fail-closed** (une
  écriture métier qui ne peut pas être auditée échoue), aucun appelant n'a le droit
  d'intercepter l'exception d'audit, et les causes évitables (longueur, nullité, type d'objet
  inconnu) sont rendues impossibles par la validation du service d'audit plutôt que par la
  contrainte de colonne.
- **Sévérité.** haute

### T-22 — AD-4 met en file pendant que l'entité s'écrit : l'ordre n'est pas décidé

- **La paire.** L'epic « Invitation » fait `persist()` puis `flush()` **puis**
  `dispatch(new SendInvitationEmail($id))`. L'epic « Réinitialisation du mot de passe » fait
  `dispatch(...)` **puis** `flush()` — lecture tout aussi conforme à AD-4 (« les emails sont
  mis en file ») et aux conventions (« le service appelle `flush()` »).
- **Le scénario.** Le transport Doctrine insère la ligne de file sur sa connexion. Selon que
  cette connexion est celle de l'application et qu'une transaction est ouverte, le message
  devient visible **avant** que l'entité soit committée : le worker le consomme, ne trouve pas
  la ligne, échoue, épuise ses essais et tombe dans la file d'échec. L'utilisateur ne reçoit
  jamais son lien, et le garde-fou d'AD-4 (health check dégradé quand la file cesse d'être
  dépilée) ne voit rien, puisqu'elle est dépilée. Inversement, un dérivé qui configure une
  connexion Doctrine séparée pour Messenger (rien ne l'interdit) perd la propriété inverse :
  un message subsiste après un rollback métier et envoie un email pour une invitation qui
  n'existe pas.
- **AD manquant.** AD-4 doit fixer : le transport partage la connexion DBAL de l'application ;
  tout message référant une donnée qu'on vient d'écrire est dispatché **après commit**
  (`dispatch_after_current_bus` / `#[DispatchAfterCurrentBus]`, posé une fois et non laissé au
  jugement de l'appelant) ; un handler qui ne trouve pas sa donnée échoue sans réessayer
  indéfiniment ; les handlers sont idempotents.
- **Sévérité.** critique

### T-23 — Ce qui voyage dans un message n'est pas décidé : secret en base, ou code invalidé au retry

- **La paire.** L'epic « 2FA par email » génère le code dans le service et le passe dans le
  message (`SendTwoFactorCode($userId, '418207')`). Un autre développeur le génère dans le
  handler, pour que le message ne porte qu'un identifiant.
- **Le scénario.** Premier cas : un secret à usage unique est écrit en clair dans la table de
  file, que rien n'exclut de l'audit ni des sauvegardes, pour une NFR qui interdit la donnée
  sensible dans les journaux. Second cas : le retry d'AD-4 régénère un code et invalide le
  précédent (EXPERIENCE.md : le renvoi invalide l'ancien code) — l'utilisateur lit un code
  déjà mort, et la panne est indiscernable d'une 2FA cassée, exactement le mode de panne que
  le garde-fou 3 d'AD-4 voulait rendre visible.
- **AD manquant.** AD-4 doit dire ce qu'un message peut porter : des identifiants et des
  données non sensibles uniquement, jamais un secret ni une donnée personnelle ; les secrets à
  usage unique sont générés et persistés avant la mise en file, le handler ne faisant que les
  transmettre ; la locale de rendu voyage dans le message (T-28).
- **Sévérité.** haute

---

## E. Deux propriétaires, deux chemins de mutation

### T-24 — Le jeton de sécurité d'AD-9 a un seul incrémenteur… par convention

- **La paire.** `Core/Service/UserDeactivator` incrémente le jeton de sécurité et désactive.
  `Module/Hr/Service/EmployeeOffboarder` fait `$user->setActive(false); $em->flush();` — un
  module peut lire et muter une entité de `Core` : AD-13 lui interdit de **modifier un
  fichier** du socle, pas d'écrire dans ses entités, et AD-7 ne couvre pas le sens
  `Module → Core` (T-1).
- **Le scénario.** Le second chemin désactive le compte sans incrémenter le jeton : la session
  de l'ex-salarié reste ouverte jusqu'à son expiration (24 h glissantes selon EXPERIENCE.md).
  FR-6 (« ses sessions en cours sont closes ») est violé dans un seul dérivé, silencieusement,
  et le test fonctionnel du socle continue de passer puisqu'il exerce le chemin du socle.
  Même paire pour un changement de mot de passe depuis un module ou une commande de
  maintenance.
- **AD à resserrer.** AD-9 doit placer l'incrémentation là où aucun chemin ne peut la
  contourner — un listener Doctrine sur les champs concernés de `User` (`isActive`,
  `password`, méthodes 2FA) plutôt qu'un appel dans un service — et AD-13 doit poser que les
  entités de `Core` ne se mutent que par les services publiés en contrat.
- **Sévérité.** critique

### T-25 — Les jetons d'API survivent à la désactivation : AD-9 ne couvre pas un firewall sans état

- **La paire.** L'epic « Cycle de vie du compte » implémente FR-6 par le jeton de sécurité
  d'AD-9 (comparaison d'utilisateur à chaque requête du firewall principal). L'epic « Jetons
  d'API » implémente AD-5 : firewall `/api` **sans état**, `Authorization: Bearer`, jeton
  opaque haché en base.
- **Le scénario.** `EquatableInterface` est exploité par le `ContextListener`, qui n'existe
  pas sur un firewall sans état : rien ne compare le jeton de sécurité côté API. Un
  utilisateur désactivé — ou anonymisé, ou dont le mot de passe a été changé après un vol —
  continue d'appeler l'API avec son jeton jusqu'à révocation manuelle. Les deux epics sont
  littéralement conformes : FR-6 parle de « sessions », AD-5 parle de révocabilité sans dire
  quand la révocation est automatique. Deux développeurs, deux périmètres de fermeture, et la
  promesse « la désactivation ferme tout » est fausse par la porte la moins visible.
- **AD à resserrer.** AD-5 et AD-9 doivent se croiser explicitement : l'authentificateur de
  jeton vérifie à chaque requête l'état du compte **et** le jeton de sécurité ; la
  désactivation, l'anonymisation et le changement de mot de passe révoquent les jetons d'API ;
  l'anonymisation révoque aussi les invitations en attente.
- **Sévérité.** critique

### T-26 — Trois compteurs de ralentissement, trois stockages, trois règles de remise à zéro

- **La paire.** Le spine ne mentionne nulle part le ralentissement des échecs, que FR-3 exige
  et qu'EXPERIENCE.md étend au code 2FA (« même ralentissement que le mot de passe à partir du
  cinquième échec ») et au bouton « Renvoyer un code » (« limité par le serveur »). L'epic
  « Connexion » utilise le `login_throttling` natif (RateLimiter, pool de cache) ; l'epic
  « 2FA » ajoute un compteur sur l'entité `User`, parce que scheb/2fa n'est pas derrière ce
  limiteur ; l'epic « Profil » ajoute un troisième compteur pour le renvoi de code.
- **Le scénario.** Trois mécanismes pour une même exigence : trois durées, trois messages,
  trois façons de remettre à zéro, et un compteur sur une entité auditée qui écrit une entrée
  d'audit à chaque tentative (T-19). Pire : le pool de cache par défaut vit dans `var/cache`,
  effacé à chaque release Deployer, donc le ralentissement est réinitialisé à chaque
  déploiement — un contrôle de sécurité dont l'efficacité dépend d'un détail d'arborescence que
  personne n'a décidé. Deux dérivés auront deux comportements.
- **AD manquant.** Un AD de ralentissement : un seul mécanisme (le limiteur natif, étendu au
  second facteur et au renvoi de code par des limiteurs nommés), un pool de cache localisé dans
  `shared/` et non dans `var/cache`, les seuils en paramètres `app.` avec leurs valeurs par
  défaut, et l'interdit de porter un compteur sur une entité auditée.
- **Sévérité.** haute

### T-27 — Qui possède l'état d'un compte : `User` ou `Invitation` ?

- **La paire.** AD-15 donne à l'invitation une entité avec expiration et date d'usage.
  EXPERIENCE.md montre Karim dans la **liste des utilisateurs** à l'état « Invité », avec une
  fiche et un bouton « Renvoyer l'invitation ». L'epic « Invitation » crée donc un `User` dès
  l'invitation, avec un état « invité ». L'epic « Utilisateurs » (FR-6) n'a qu'un booléen
  actif/désactivé — le seul état que le PRD nomme — et rend un invité comme « désactivé ».
- **Le scénario.** Deux sources de vérité pour un même état : la ligne `User` et la ligne
  `Invitation`. La liste montre « Désactivé » là où l'UX promet « Invité » ; le message
  « Ce compte est désactivé » part à quelqu'un qui n'a jamais eu de compte ; AD-9 compare un
  jeton de sécurité sur un compte sans mot de passe. Et le « renvoi » d'AD-15 a deux
  implémentations conformes : muter la ligne existante (l'historique des envois disparaît, le
  contrôle « déjà invité le 2 septembre » de l'UX perd sa date) ou créer une seconde ligne et
  révoquer la première (l'état d'une personne devient une requête sur plusieurs lignes, et deux
  développeurs désigneront « l'invitation courante » de deux façons).
- **AD manquant.** Un AD de cycle de vie du compte : un énuméré unique porté par `User`
  (`invité`, `actif`, `désactivé`, `anonymisé`), seul propriétaire de l'état affiché ;
  l'invitation ne porte que le lien et sa consommation ; la sémantique du renvoi (nouvelle
  ligne plus révocation de la précédente, la courante étant la dernière non révoquée) ; et la
  règle que les messages d'erreur de connexion se dérivent de cet énuméré.
- **Sévérité.** haute

### T-28 — La langue active a trois propriétaires possibles et deux sémantiques de repli

- **La paire.** AD-6 dit « la langue est portée par le compte **et** par la session » ; AD-14
  dit qu'elle est un champ du compte. L'epic « Sécurité » écrit un listener qui lit
  `$user->getLanguage()` à chaque requête. L'epic « Langues » (FR-22) écrit un listener qui lit
  la locale en session, posée à la connexion — lecture également conforme.
- **Le scénario.** Deux listeners de locale, ordre indéfini, et la bascule du menu compte
  (Flux 9 / FR-19) n'en met à jour qu'un : la page revient dans l'ancienne langue une requête
  sur deux. Surtout, le repli de FR-22 a deux implémentations conformes : (a) la désactivation
  du néerlandais **réécrit** le champ de 2 utilisateurs — écriture en masse sur une entité
  auditée, donc 2 entrées « langue : nl → fr » attribuées à Sophie, et la réactivation ne leur
  rend pas le néerlandais ; (b) le repli est calculé à la lecture — journal propre, mais la
  réactivation leur rend le néerlandais, alors que le Flash de l'UX dit « votre interface est
  passée en français ». Enfin, un email mis en file (AD-4) est rendu par le worker plus tard :
  s'il relit la langue du compte au moment du traitement, il peut partir dans une autre langue
  que celle annoncée, ou dans une langue désactivée entre-temps.
- **AD manquant.** Un AD de résolution de locale : une **chaîne unique et ordonnée**
  (`?lang=` de la page de connexion → session avant authentification → champ du compte →
  première langue active), un seul listener, la règle « la désactivation d'une langue ne
  réécrit aucun compte, le repli est calculé » (ou l'inverse, mais écrit), et la locale de
  rendu portée par le message Messenger.
- **Sévérité.** haute

---

## F. Chaîne de requête

### T-29 — Quatre portiers de requête écrits par quatre développeurs, sans ordre ni exemption

- **La paire.** Quatre mécanismes interceptent la requête, chacun exigé par un document
  différent : la redirection forcée vers l'enrôlement 2FA après le délai de grâce
  (EXPERIENCE.md), la déauthentification par jeton de sécurité (AD-9), la résolution et le
  repli de locale avec son Flash (T-28), et le refus de permission (FR-10). Aucun n'est nommé
  dans le spine, aucun n'a de priorité fixée.
- **Le scénario.** Un Admin dont le délai de grâce vient d'expirer et dont la langue vient
  d'être désactivée : le portier de locale pose un Flash, le portier 2FA redirige — le Flash
  est consommé par la page de redirection ou perdu, alors qu'EXPERIENCE.md promet « un seul
  Flash à la fois ». Si le portier 2FA n'exempte pas sa propre cible, la page d'enrôlement se
  redirige vers elle-même : boucle de redirection chez le client, mode de panne classique de
  ce motif. Deux développeurs qui écrivent deux subscribers sans se parler produisent
  exactement cela, et chacun passe ses propres tests.
- **AD manquant.** Un AD de chaîne de requête : la liste ordonnée des portiers avec leur
  priorité d'événement, la règle que chaque portier exempte ses propres routes (enrôlement,
  déconnexion, profil, assets, health check), l'interdit de poser un Flash dans un portier qui
  redirige, et un test fonctionnel croisé (deux conditions simultanées).
- **Sévérité.** haute

---

## G. Roadmap et environnements

### T-30 — Le contrat d'entrée BMAD n'est pas versionné : chaque dérivé forke le lecteur

- **La paire.** Le dérivé A tourne sur la version de BMAD de son clonage (`sprint-status.yaml`,
  clés `epic-{n}`, `{n}-{m}-{titre}`). Le dérivé B a mis BMAD à jour six mois plus tard : le
  fichier a changé de forme — l'addendum du PRD dit lui-même « forme exacte à confirmer ».
  Chacun adapte `Core/Roadmap/` à ce qu'il lit.
- **Le scénario.** AD-10 garantit qu'il n'y a **qu'un** lecteur — dans `Core`, donc dans la
  zone de report de correctif. Deux dérivés qui l'adaptent chacun à leur version de BMAD
  rendent tout correctif du socle sur ce lecteur non reportable (T-2 : ils ont édité
  `src/Core/`), et la tolérance d'AD-10 (« fichier absent ou mal formé → roadmap partielle »)
  masque la divergence : la roadmap s'affiche partiellement au lieu de dire qu'elle ne
  comprend plus le format.
- **AD manquant.** Le contrat d'entrée doit être versionné et testé : un marqueur de version
  dans le fichier lu (ou une détection explicite), un jeu de fixtures dans les tests du socle
  faisant foi, une tolérance qui **distingue** un fichier mal formé d'un format inconnu
  (avertissement différent), et l'interdit d'adapter le lecteur dans un dérivé — la montée de
  format est un correctif du socle.
- **Sévérité.** haute

### T-31 — `#[When('dev')]` retire la route mais pas le `path()` du template : 500 sur l'accueil du client

- **La paire.** L'epic « Roadmap » rend la ligne de tâche dans un template partagé entre dev
  et production — c'est la promesse d'EXPERIENCE.md (« même thème, mêmes écrans, mêmes
  textes »). Le développeur A conditionne le bouton « Lancer » par `app.environment == 'dev'` ;
  le développeur B le conditionne par la permission de développement (« rendu uniquement si
  `APP_ENV=dev` **et** permission de développement », dit EXPERIENCE.md — il ne garde que la
  seconde moitié), ce qui est vrai pour Fabrice **en production**.
- **Le scénario.** Dans le second cas, `path('app_task_launch')` sur une route absente de la
  table de routage lève `RouteNotFoundException` : la page d'accueil explose pour le Super
  admin sur le serveur du client, et pour lui seul — donc jamais repérée par un test
  fonctionnel en `test` ou en `dev`. AD-11 garantit l'absence de la route sans dire comment les
  vues s'en protègent.
- **AD à resserrer.** AD-11 doit porter la règle de rendu : le fragment dev-only est un
  composant ou un template distinct, lui aussi `#[When('dev')]` (ou inclus sous une condition
  d'environnement, jamais sous une seule condition de permission), et le socle a un test de
  fumée qui rend ses écrans avec `APP_ENV=prod` en tant que Super admin.
- **Sévérité.** haute

### T-32 — « Date du dernier déploiement » et clé de cache : deux sources pour la même notion

- **La paire.** AD-10 invalide le cache du lecteur « par la version de release en
  production » ; le spine dit « la date de la release est la date du dernier déploiement
  qu'affiche FR-14 ». Le développeur A dérive les deux de la cible du lien `current` ; le
  développeur B utilise `kernel.build_dir` pour le cache et un paramètre `app.release_date`
  injecté par Deployer pour l'affichage.
- **Le scénario.** Après un rollback Deployer, l'un affiche la date de la release restaurée et
  l'autre celle du dernier déploiement tenté ; le cache de l'un n'est pas invalidé par le
  rollback et la roadmap montre les fichiers d'une release qui n'est plus en ligne — exactement
  ce que la pastille de fraîcheur (UJ-3, cas limite) devait empêcher.
- **AD manquant.** Un unique service `Core` « identité de release » (identifiant plus date),
  alimenté par un mécanisme décidé (fichier généré au déploiement), consommé par la clé de
  cache d'AD-10, par l'affichage de FR-14 et par le health check.
- **Sévérité.** moyenne

---

## H. API et observabilité

### T-33 — FR-18 est un contrat public dont la forme n'est pas décidée

- **La paire.** L'epic « Health check » rend `{"status":"ok"}` en 200 et 503 sinon. Le
  développeur qui implémente le garde-fou 1 d'AD-4 ajoute l'état de la file ; le dérivé suivant
  rend `{"database":"up","queue":"degraded"}` en 200 avec un champ `status`. Les deux
  respectent FR-18 (« l'état du service et de sa base, sans détail interne ; un état dégradé se
  traduit par un code HTTP distinct ») et AD-4.
- **Le scénario.** FR-18 existe pour une future application cliente et pour la supervision du
  client : deux dérivés avec deux formes de réponse et deux définitions de « dégradé » rendent
  impossibles un client et une sonde communs. Et le critère « la file cesse d'être dépilée »
  n'a aucune définition opérationnelle (âge du plus vieux message ? file d'échec non vide ?
  battement du worker ? quel seuil ?) : un dérivé supervisé ne détectera pas l'arrêt du worker
  que l'autre détecte, alors que c'est le garde-fou explicite d'AD-4.
- **AD manquant.** Le contrat de FR-18 : forme de la réponse (clés, énuméré d'états), codes
  HTTP (200 nominal, 503 dégradé), liste des vérifications (base, file mail par âge du plus
  vieux message non consommé, file d'échec), seuils en paramètres `app.` avec leurs valeurs par
  défaut, et l'interdit d'y exposer un détail interne.
- **Sévérité.** haute

---

## I. Dimensions que le spine laisse silencieuses

### T-34 — Fuseau horaire et instants stockés

- **La paire.** `Module/Sales` stocke ses `\DateTimeImmutable` dans le fuseau du serveur ;
  `Core/Audit` horodate en UTC. La convention ne dit que « `\DateTimeImmutable` ».
- **Le scénario.** Le journal mélange deux échelles : « mercredi 14 h 12 » (UJ-4) est tantôt
  l'heure de Sophie, tantôt celle du serveur, et le filtre « période » de FR-13 coupe au
  mauvais endroit ; l'export CSV envoyé au comptable n'est pas comparable d'un module à
  l'autre. Les expirations (invitation 7 jours, lien 1 heure, code 10 minutes, délai de grâce
  2FA) héritent du même flou.
- **AD manquant.** Tous les instants sont stockés en UTC (type `date_immutable` et fuseau de
  connexion fixé), l'affichage se fait dans un fuseau de dérivé paramétré (`app.timezone`), et
  les bornes de filtre sont converties en un seul endroit.
- **Sévérité.** moyenne

### T-35 — Navigation : identité des groupes, ordre et permission du groupe

- **La paire.** `Module/Sales` publie une entrée dans un groupe « Ventes » ; `Module/Stock`
  publie la sienne dans « Administration », le groupe du socle. Les deux respectent AD-13
  (« un module publie ses entrées de navigation »).
- **Le scénario.** L'ordre des groupes et des entrées est celui de l'itérateur autowiré, donc
  l'ordre de déclaration des services — arbitraire, et instable dès qu'on réorganise un fichier
  de configuration : deux dérivés, deux sidebars, et la spécification UX (deux groupes,
  « Suivi » puis « Administration ») n'est plus tenue. Le groupe « Administration » du socle
  n'est rendu que s'il est non vide et ses entrées supposent la permission d'administration :
  l'entrée de `Module/Stock`, gouvernée par `stock.read`, fait apparaître un groupe
  « Administration » à un simple User. Et deux modules déclarant un groupe de même libellé
  produisent deux groupes identiques à l'écran.
- **AD manquant.** Le contrat de navigation doit porter : un identifiant de groupe (énuméré
  des groupes du socle plus règle de création d'un groupe de module), une clé d'ordre explicite
  sur le groupe et sur l'entrée, la permission qui gouverne le groupe, et la résolution d'une
  collision d'identifiant.
- **Sévérité.** haute

### T-36 — CSRF sur les écritures d'un module

- **La paire.** `Module/Sales` expose une écriture par FormType (jeton CSRF par défaut) ;
  `Module/Stock` expose une écriture en JSON par `#[MapRequestPayload]` sur le firewall
  principal (aucun jeton). La NFR sécurité exige « protection CSRF sur toute écriture » ; les
  conventions d'erreurs et de routes ne rappellent pas la règle.
- **Le scénario.** Une écriture de module est exposée au CSRF dans un dérivé et pas dans
  l'autre, pour une exigence transverse que le socle tenait pour acquise.
- **AD manquant.** La règle dans les conventions : toute écriture sur le firewall de session
  porte un jeton CSRF (FormType ou `#[IsCsrfTokenValid]`) ; le JSON non authentifié par session
  vit sur `/api` et nulle part ailleurs.
- **Sévérité.** moyenne

### T-37 — Les tests du socle dans un dérivé

- **La paire.** Les tests fonctionnels du socle (« chaque FR couverte ») vérifient la sidebar,
  le catalogue de permissions et le journal d'audit. `Module/Quote` ajoute une entrée de
  navigation, deux permissions et des entrées d'audit : les assertions exactes du socle
  cassent. Le développeur A assouplit les tests du socle (édition de la zone de report, T-2) ;
  le développeur B ajoute des fixtures.
- **Le scénario.** Le premier dérivé ne peut plus recevoir les tests d'un correctif du socle, et
  la couverture « chaque FR » devient décorative.
- **AD manquant.** Les tests du socle font partie de la zone de report : ils sont écrits pour
  être insensibles aux contributions de modules (assertions sur la présence, jamais sur
  l'exhaustivité), et un dérivé n'édite pas un test du socle.
- **Sévérité.** moyenne

### T-38 — Où vit le contrôleur de FR-16

- **La paire.** Le Structural Seed place les contrôleurs dans `Core/Controller/` ; la
  Capability Map place FR-16 dans `Core/Roadmap/`. Deux développeurs, deux emplacements, et la
  configuration deptrac d'AD-7 doit classer les deux.
- **Le scénario.** Divergence mineure mais réelle : la couche deptrac « Controller » est
  définie par chemin, et un contrôleur rangé sous `Core/Roadmap/` échappe à la règle de couche
  ou la fait échouer, selon la configuration.
- **AD manquant.** Trancher : les couches sont les dossiers de premier niveau sous chaque
  racine (`Core/Controller/`, `Core/Service/`…), les dossiers thématiques (`Audit/`,
  `Roadmap/`, `Security/`) étant des sous-dossiers **de couche** et non des couches, et la
  Capability Map doit le refléter.
- **Sévérité.** basse

---

## Synthèse

| # | Trou | Sévérité |
|---|---|---|
| T-1 | `Module → Core` interne non interdit par AD-7 | critique |
| T-2 | Un dérivé peut éditer `src/Core/` et `config/` | critique |
| T-3 | Migrations hors du périmètre de report affirmé par AD-2 | haute |
| T-4 | Collision silencieuse de noms de routes et de chemins entre modules | critique |
| T-5 | Domaines de traduction, templates et contrôleurs Stimulus non attribués | haute |
| T-6 | Surface de rebranding non isolée du kit maintenu par le socle | haute |
| T-7 | Sémantique de Super admin face à un code de permission nouveau | critique |
| T-8 | Préfixe des codes de permission contradictoire, collision non détectée | critique |
| T-9 | Autorisation par objet sans emplacement (le 404 de FR-10) | haute |
| T-10 | Portée de la mémoïsation des permissions hors HTTP | moyenne |
| T-11 | Deux conventions de « type d'objet » : filtre FR-13 faux | critique |
| T-12 | Racine d'agrégat contre feuille, et changements de collection | haute |
| T-13 | Double entrée modification plus action métier pour un même événement | haute |
| T-14 | L'anonymisation écrit la donnée effacée dans la table immuable | critique |
| T-15 | Forme des valeurs avant/après non décidée | critique |
| T-16 | Suppression : « référencer sans recopier » impossible | haute |
| T-17 | Codes d'action métier sans catalogue ni convention de libellé | haute |
| T-18 | Acteur absent ou non humain, propagation à travers la file | haute |
| T-19 | Compteurs et jetons techniques audités | haute |
| T-20 | Contrat transactionnel de l'enregistreur d'audit | critique |
| T-21 | Politique d'échec de l'écriture d'audit | haute |
| T-22 | Dispatch Messenger avant commit | critique |
| T-23 | Contenu d'un message : secret en base ou code invalidé au retry | haute |
| T-24 | Jeton de sécurité d'AD-9 contournable par un autre chemin de mutation | critique |
| T-25 | Jetons d'API survivant à la désactivation (firewall sans état) | critique |
| T-26 | Trois mécanismes de ralentissement, pool de cache effacé au déploiement | haute |
| T-27 | Propriétaire de l'état du compte : `User` ou `Invitation` | haute |
| T-28 | Chaîne de résolution de la locale et sémantique du repli | haute |
| T-29 | Ordre et exemptions des portiers de requête | haute |
| T-30 | Contrat BMAD non versionné, lecteur forké par dérivé | haute |
| T-31 | `path()` vers une route `#[When('dev')]` absente en production | haute |
| T-32 | Deux sources pour l'identité de release | moyenne |
| T-33 | Forme du health check et critère de dégradation | haute |
| T-34 | Fuseau horaire des instants stockés | moyenne |
| T-35 | Identité, ordre et permission des groupes de navigation | haute |
| T-36 | CSRF sur les écritures d'un module | moyenne |
| T-37 | Tests du socle face aux contributions de modules | moyenne |
| T-38 | Emplacement du contrôleur de FR-16 (couche contre dossier thématique) | basse |

Douze critiques, dix-huit hautes, sept moyennes, une basse.

### Les quatre familles de décisions qui manquent

1. **Propriété du code dans un dérivé** (T-1, T-2, T-3, T-5, T-6, T-37). AD-2 promet un
   report de correctif ; rien ne rend cette promesse mécanisable. C'est la famille qui coûtera
   le plus cher, le plus tard.
2. **Forme de la donnée d'audit** (T-11 à T-19). AD-3 et AD-12 décident du mécanisme et du
   principe de référence, et laissent indéterminées toutes les colonnes que FR-13 lit.
3. **Contrat transactionnel** (T-20 à T-23). Le spine nomme `onFlush`, `flush()` et la mise en
   file sans jamais dire qui ouvre, qui commite, et qui voit quoi, quand.
4. **Espaces de noms partagés** (T-4, T-5, T-8, T-17, T-35). AD-13 envoie les modules
   contribuer dans six espaces plats sans attribuer aucun d'eux.
