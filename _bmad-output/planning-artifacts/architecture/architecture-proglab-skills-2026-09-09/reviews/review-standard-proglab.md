# Revue indépendante — conformité au standard proglab

- **Objet :** `_bmad-output/planning-artifacts/architecture/architecture-proglab-skills-2026-09-09/ARCHITECTURE-SPINE.md` (status: final, updated 2026-09-10)
- **Lentille unique :** confronter AD-1 → AD-15, chaque ligne de *Consistency Conventions* et chaque élément de *Structural Seed* au contrat de couches de `symfony-proglab-architecture` et à la liste des rejets délibérés de `references/rejections.md`, plus les cinq règles de `symfony-proglab-standards`.
- **Date :** 2026-09-10
- **Relecteur :** revue indépendante, spécialiste du standard interne

## Verdict

Le spine est **globalement conforme** : il ne réintroduit aucune alternative rejetée en douce, il restitue le contrat de couches intégralement et il tranche explicitement plusieurs rejets (pagination, Pagerfanta, bundler, Docker, transport Messenger, bundle d'audit). Trois écarts de sévérité **haute** doivent être corrigés avant que ce document serve de substrat, parce qu'ils portent chacun sur une des cinq règles du socle : l'audit écrit par un listener Doctrine sans frontière de transaction visible ni service porteur (AD-3), des dossiers de `Core/` qui mélangent les couches et rendent la promesse de deptrac invérifiable (AD-7 + Structural Seed), et un DTO Read qui franchit la frontière du service (AD-10).

Sommaire des constats :

| # | Constat | Sévérité |
|---|---|---|
| 1 | AD-3 — le listener `onFlush` porte la règle d'audit ; frontière de transaction invisible ; écart non énoncé | haute |
| 2 | AD-7 + Seed — `Core/Audit/`, `Core/Roadmap/`, `Core/Security/` mélangent les couches ; deptrac ne peut pas tenir la promesse d'AD-7 | haute |
| 3 | AD-10 — le lecteur de roadmap rend des DTO Read à travers la frontière du service | haute |
| 4 | AD-12 / FR-13 — libellés résolus à la lecture sans domicile de requête ni garde-fou N+1 | moyenne |
| 5 | AD-8 + Permissions — « test de droit uniquement par le voter » évince `access_control` | moyenne |
| 6 | Configuration — coffre de secrets choisi sans dire lequel des deux cas du standard s'applique | moyenne |
| 7 | AD-9 — `EquatableInterface` met une règle sur l'entité `User` | moyenne |
| 8 | DTO — « `object-mapper` reste expérimental sur 7.4 » contredit le standard | moyenne |
| 9 | Frontend — « aucun Live Component dans le socle » bannit l'outil que le standard désigne | moyenne |
| 10 | AD-8 — la mémoïsation par requête contredit `final readonly` | moyenne |
| 11 | AD-13 — « publie ses routes par des services taggés » renomme un mécanisme déjà nommé | basse |
| 12 | Seed — muet sur `Form/`, `Message*/`, `EventListener/`, `Health/` que le spine exige pourtant | basse |
| 13 | AD-3 — le mécanisme de câblage du listener n'est pas nommé, et le réflexe par défaut est rejeté | basse |

---

## 1 — AD-3 : le listener Doctrine porte la règle d'audit, et la frontière de transaction disparaît — **haute**

**Ce que dit le spine.** AD-3 : « un listener Doctrine `onFlush` capte les modifications d'objets et écrit dans la même table que les actions métier déclarées depuis la couche service. » Structural Seed : « `Audit/  # listener onFlush + service d'actions métier (AD-3)` » — deux artefacts côte à côte, le listener n'étant pas présenté comme un appelant du service.

**Le mécanisme lui-même est admissible.** Contrairement à ce qu'on pourrait craindre, un listener Doctrine n'est pas visé par la clause du dernier recours, qui nomme les listeners de *kernel* :

> « **Les listeners de kernel sont un dernier recours.** […] Un listener de kernel s'exécute sur tout, loin du code qu'il affecte, et se débogue mal. »
> — `symfony-proglab-architecture/SKILL.md`

et les événements Doctrine sont dans la liste blanche explicite :

> « **`#[AsEventListener]` sert uniquement aux événements du framework** — `kernel.exception`, `kernel.response`, **Doctrine**, Messenger, Security. »
> — `symfony-proglab-architecture/SKILL.md`

Un journal d'audit transversal est précisément le cas où un appel direct par service écrirait la même ligne à N endroits. **Le choix est défendable ; ce qui manque, c'est tout ce qui l'encadre.**

**Ce qui n'est pas conforme.**

a) **Des règles métier vivent dans le listener.** AD-12 confie au dispositif d'audit des décisions qui sont des règles : « Les champs sensibles sont exclus **à l'écriture** par une liste tenue dans `Core` et extensible par module », « Une modification faite hors requête HTTP est attribuée à un acteur système nommé », et la convention Audit ajoute « Tout objet métier est audité par défaut ; l'exclusion est explicite ». Rien dans le spine ne dit que le listener délègue ces décisions à un service.

> « | **Service** | Toutes les règles métier, l'orchestration, `flush()` | … |
> | **Repository** | DQL, QueryBuilder, SQL, `persist()`, `remove()` | Règles métier, `flush()` | »
> — contrat de couches, `symfony-proglab-architecture/SKILL.md`

> « **5. Toute règle métier vit dans un service.** »
> — `symfony-proglab-standards/SKILL.md`

b) **La frontière de transaction n'est plus visible dans la méthode qui porte l'opération.** Le spine nomme `flush()` une seule fois, dans le schéma du Design Paradigm (« Service — toutes les règles, flush() »), et ne dit jamais que le listener n'en appelle pas.

> « **Le service appelle `flush()`, une fois par cas d'usage.** […] Une frontière de transaction par opération métier, **visible dans la méthode qui porte l'opération**. Un `flush()` dans une méthode de repository rend la frontière invisible et transforme deux écritures en deux transactions. »
> — `symfony-proglab-architecture/SKILL.md`

> « | `flush()` à l'intérieur d'une méthode de repository | Le service flush, une fois par cas d'usage | La frontière de transaction doit être visible dans la méthode qui porte l'opération | »
> — `references/rejections.md`, section Doctrine

Écrire l'audit depuis `onFlush` signifie qu'une seconde écriture entre dans la transaction du service sans qu'aucune ligne de ce service ne le montre. C'est exactement le symptôme que ce rejet décrit, déplacé du repository vers le listener.

c) **L'écart n'est pas énoncé comme un écart.** AD-3 ne justifie que la *table unique* (« **Prevents :** deux magasins d'audit »), pas le *mécanisme implicite*. Or le mécanisme contredit frontalement le premier des cinq rejets porteurs :

> « **1. Pas d'événements de domaine pour les conséquences métier.** Les conséquences sont des appels directs, si bien qu'un cas d'usage se lit dans une seule méthode et se teste avec une seule assertion. »
> — `references/rejections.md`

Le listener `onFlush` produit très exactement le défaut que ce rejet veut éviter : « que se passe-t-il quand on modifie un objet ? » redevient une recherche à l'échelle du projet. Le standard autorise l'écart, mais il exige de le dire :

> « Si une règle semble inadaptée au cas présent, le dire et l'expliquer plutôt que de la contourner en silence. »
> — `symfony-proglab-standards/SKILL.md`

**Correction demandée.** Réécrire AD-3 pour (i) nommer l'écart à « les conséquences sont des appels directs » et sa raison (un appel direct écrirait la même ligne depuis chaque service du socle et de chaque module) ; (ii) réduire le listener à une couche de collecte qui lit les change sets de l'UnitOfWork et appelle un service `Core` (`AuditRecorder`), lequel porte la liste d'exclusion, l'acteur et le code d'action ; (iii) écrire noir sur blanc qu'**aucun `flush()` n'est appelé dans le listener** — l'insertion se fait dans le flush en cours via l'UnitOfWork — et que la frontière de transaction reste celle du service appelant ; (iv) ajouter la contrepartie à assumer, puisque le standard exige que le prix soit énoncé : l'audit automatique n'apparaît pas à la lecture du service audité, donc il est couvert par un test fonctionnel par FR plutôt que par une assertion dans le test unitaire du service.

## 2 — AD-7 + Structural Seed : des dossiers qui mélangent les couches, et une promesse deptrac invérifiable — **haute**

**Ce que dit le spine.** Structural Seed : `Core/Security/`, `Core/Audit/`, `Core/Roadmap/`, à côté de `Core/Controller/`, `Core/Service/`, `Core/Repository/`. La *Capability → Architecture Map* envoie FR-11/12/13 dans `Core/Audit/`, FR-14/15/16 dans `Core/Roadmap/`, FR-5 et FR-7→FR-10 dans `Core/Security/`. AD-7 affirme : « `deptrac/deptrac` en `require-dev` vérifie les couches proglab ».

**Le renommage de racines est légitime — pas le mélange de couches.** Sur le point 3 de la commande : AD-2 est **bien fondé et suffisamment énoncé**. Le standard l'autorise nommément :

> « **La structure existante de `src/`** → les conventions à suivre. Si le projet regroupe déjà le code par domaine […] suivre cela. **Les frontières entre couches ne sont pas négociables ; les noms de dossiers le sont.** »
> — `symfony-proglab-standards/SKILL.md`

et le spine tient sa part du marché : le Design Paradigm dit « Le contrat […] s'applique intégralement et sans exception », AD-2 dit que les deux racines « portent les mêmes couches », AD-7 outille la frontière. Rien à redire sur `Core/` et `Module/<Nom>/`.

**Ce qui n'est pas conforme, c'est le troisième niveau.** `Audit/` et `Roadmap/` ne sont pas des racines, ce sont des dossiers de fonctionnalité qui contiennent un contrôleur (FR-13 a une page et un export), un service, une requête (FR-13 filtre et pagine) et un listener. La frontière de couche y devient invisible, en contradiction avec AD-2 lui-même (« porte les mêmes couches ») et avec le Seed qui annonce dans la même page « `Repository/  # seul endroit où vit une requête` ». Conséquence mécanique sur AD-7 : deptrac collecte par espace de noms, et un dossier qui héberge quatre couches ne peut être affecté à aucune — soit il reste non collecté, soit la couche créée pour lui devra s'autoriser Doctrine, ce qui ouvre une brèche dans la règle centrale.

> « […] ce qu'il faut y chercher est une classe sous `src/` : **c'est un répertoire que personne n'a collecté, donc rien à l'intérieur n'est vérifié**. »
> — `symfony-proglab-quality/references/deptrac.md`

> « La correction n'est jamais d'ajouter `Doctrine` au ruleset de `Service`. »
> — idem

**Et c'est un renommage de ce que le standard nomme déjà.** La configuration deptrac fournie énumère les dossiers hors couches du standard :

> « Le fichier fourni laisse volontairement non collectés `src/Command`, `src/Doctrine`, `src/EventListener`, `src/Serializer`, `src/Health` et `src/Scheduler` »
> — `symfony-proglab-quality/references/deptrac.md`

Le listener d'AD-3 a donc déjà un domicile nommé (`EventListener/` ou `Doctrine/`), et le health check de FR-18 aussi (`Health/`, alors que la Capability Map le place dans `Core/Controller/`). Inventer `Audit/` et `Roadmap/` comme conteneurs multi-couches relève du quatrième critère de cette revue : inventer une structure que le standard nomme déjà autrement.

**Correction demandée.** Garder les couches comme troisième niveau sous chaque racine et réserver les dossiers de fonctionnalité aux classes hors couches : `Core/Controller/AuditLogController`, `Core/Service/AuditRecorder`, `Core/Repository/AuditEntryRepository`, `Core/EventListener/` (ou `Core/Doctrine/`) pour le listener `onFlush`, `Core/Health/` pour FR-18. Mettre à jour la Capability Map en conséquence, et énoncer dans AD-7 quels dossiers restent non collectés par deptrac — sinon « vérifie les couches proglab » est une affirmation que la CI ne soutient pas.

## 3 — AD-10 : un DTO Read franchit la frontière du service — **haute**

**Ce que dit le spine.** AD-10 : « un unique lecteur dans `Core` parse ces fichiers de façon tolérante et **rend des DTO Read**. Aucun contrôleur, aucun template, aucun module ne les lit. »

**Deux problèmes distincts.**

a) **Le Read est défini par le standard comme la forme d'une projection SQL**, pas comme la sortie d'un parseur de fichiers :

> « `src/Dto/Input/`     ce qui entre    — validé, façonné par le contrat HTTP
> `src/Dto/Read/`      ce qu'une requête renvoie — brut, façonné par la projection SQL
> `src/Dto/Output/`    ce qui sort     — façonné par le contrat de l'API ou du template »
> — `symfony-proglab-architecture/references/dtos.md`

Le spine reprend d'ailleurs cette définition dans sa propre convention : « `Dto/Read/` les projections `SELECT NEW` ». Un lecteur de fichiers BMAD n'émet aucune projection ; appeler Read sa sortie détourne un nom déjà attribué.

b) **Surtout, seul un DTO Output franchit la frontière du service** — et le spine l'écrit lui-même (« `Dto/Output/` le contrat qui franchit la couche service ») avant de l'enfreindre en AD-10.

> « **Un service ne renvoie jamais une entité à un contrôleur. Il renvoie un DTO Output.** »
> — `symfony-proglab-architecture/SKILL.md`

> « **4. Des DTOs aux deux extrémités.** Les DTOs d'entrée portent la validation ; les DTOs de sortie portent le contrat. »
> — `symfony-proglab-standards/SKILL.md`

> « La traduction d'un DTO Read vers un DTO Output n'est jamais mécanique, et faire comme si elle l'était place les décisions au mauvais endroit. […] La normalisation vit donc dans le service, dans une méthode privée. »
> — `references/dtos.md`

Or AD-10 nomme précisément ce genre de décisions : « Priorité, difficulté, assignation et dépendances sont optionnelles dans le contrat : absentes, elles se rendent « non renseigné » », et « Un fichier absent ou mal formé produit une roadmap partielle et un avertissement ». Ce sont des décisions produit qui appartiennent au service et se testent unitairement — donc un Output.

**Correction demandée.** Soit AD-10 rend des **DTO Output** (et la couche Read disparaît du cas, ce que le standard encourage explicitement : « Si la projection correspond déjà au contrat […] supprimez la classe intermédiaire »), soit le lecteur garde des structures internes non publiées et un service de lecture les normalise en Output — en énonçant que « non renseigné » et « roadmap partielle » sont des décisions de ce service.

## 4 — AD-12 : libellés résolus à la lecture, sans domicile de requête ni garde-fou N+1 — **moyenne**

AD-12 : « une entrée d'audit référence son acteur et son objet par identifiant (classe et clé primaire) […] Les libellés sont résolus à la lecture et traduits. » Le choix est bon (il protège l'immuabilité et permet de viser une entité de module), mais le spine ne dit pas **où** vit la résolution, alors qu'elle implique une lecture par classe auditée, ni comment FR-13 évite une requête par ligne affichée.

> « **3. Aucun DQL, QueryBuilder ou SQL en dehors d'un repository.** Les requêtes portent le nom de l'intention de l'appelant, pas du mécanisme. »
> — `symfony-proglab-standards/SKILL.md`

> « | Faire confiance à un endpoint de liste pour l'absence de N+1 | Un nombre de requêtes vérifié dans un test d'intégration | Une règle non testée est un vœu pieux | »
> — `references/rejections.md`, section Performance

**Correction demandée.** Nommer le mécanisme : un résolveur de libellés par classe, publié par les modules via un contrat de `Core/Contract/` (cohérent avec AD-13) et agrégé par `#[AutowireLocator]`, chaque résolveur appelant une méthode de repository nommée et **chargeant par lot les ids d'une page**. Ajouter à la convention Tests que la page FR-13 porte un test de comptage de requêtes — le seul artefact « à la demande » que ce choix rend obligatoire.

## 5 — AD-8 et convention Permissions : `access_control` disparaît — **moyenne**

AD-8 : « lue par un unique voter. Aucun code ne teste un nom de rôle. » Convention Permissions : « Test de droit **uniquement** par le voter d'AD-8. » Le spine ne mentionne `access_control` nulle part, alors que sa convention Erreurs parle bien de « Zone non permise : 403 » — il y a donc des zones, mais aucun filet nommé.

> « | `access_control` seul, ou `#[IsGranted]` seul | Les deux | `access_control` est le filet que personne n'oublie ; `#[IsGranted]` est la précision | »
> — `references/rejections.md`, section Sécurité

> « Seul `#[IsGranted]` fuit le jour où quelqu'un ajoute une méthode et oublie l'attribut »
> — `symfony-proglab-security/SKILL.md`

> « | Des rôles pour des décisions au niveau objet | Des rôles pour les zones, des Voters pour les objets | »
> — `references/rejections.md`

Le rejet n'est pas réintroduit à l'envers (le spine ne met pas de rôles là où il faut un voter), mais la moitié « filet » du dispositif est affaiblie par une formulation absolue, dans un produit dont AD-13 prévoit que des modules ajoutent des contrôleurs sans toucher au socle — soit le scénario exact que `access_control` couvre.

**Correction demandée.** Préciser qu'`access_control` reste le filet par zone d'URL (y compris `^/api`, `^/admin`, et la règle `^/` en dernier) et que « uniquement par le voter » porte sur les décisions **au niveau objet et permission**, pas sur le filet de zone. Préciser aussi comment un module déclare la protection de ses zones, puisque AD-13 interdit de modifier un fichier de `Core/`.

## 6 — Configuration : le coffre de secrets choisi sans dire lequel des deux cas s'applique — **moyenne**

Convention Configuration : « Secrets : `.env.local` en développement, coffre de secrets Symfony en production. » Une ligne, aucune raison, aucun AD.

**Sur le fond, le choix est le bon.** Le spine cible un serveur client nu déployé par Deployer en SSH (« Production — serveur client, Deployer par SSH », « Aucun conteneur, aucun service externe payant »), ce qui est exactement le cas où le standard recommande le coffre :

> « Le coffre n'est **pas** rejeté sur le fond […] C'est la réponse dès qu'il n'y a pas de magasin de plateforme (un serveur nu, un déploiement par rsync), ou dès que des secrets doivent transiter par le dépôt. »
> — `symfony-proglab-architecture/SKILL.md`

> « **Hors d'une plateforme conteneurisée, le vault de secrets Symfony est le standard**, pas une option écartée »
> — `symfony-proglab-deployment/SKILL.md`

> « | Le magasin de la plateforme plutôt que le coffre | Il n'y a pas de magasin de plateforme (serveur nu, déploiement rsync) […] — alors le coffre, entièrement | »
> — `references/rejections.md`, « Quand un rejet cesse de s'appliquer »

**Ce qui manque, c'est la discrimination explicite.** Le standard formule le rejet comme « le coffre **par-dessus** le magasin de secrets d'une plateforme de conteneurs », et la phrase de référence de `symfony-proglab-architecture` présuppose le cas conteneurisé (« La suite déploie une image de conteneur »). Un lecteur de la seule ligne de convention — c'est-à-dire l'auteur d'un dérivé — ne peut pas savoir si le cas rejeté a été réintroduit ou si le cas recommandé s'applique. Le spine n'énonce jamais « il n'y a pas de magasin de plateforme ici », ni ce qui devient l'unique secret hors dépôt.

**Correction demandée.** Une proposition dans la cellule, ou mieux un AD à part entière : « Cible nue, aucun magasin de plateforme ; le coffre Symfony est donc le mécanisme du standard dans ce cas. `config/secrets/prod/` est commité, `SYMFONY_DECRYPTION_SECRET` est la seule valeur injectée sur le serveur, `.env.local` est interdit en production. » Et déclarer le déclencheur inverse : si un dérivé part un jour sur une plateforme conteneurisée, le magasin de la plateforme remplace le coffre, jamais les deux.

## 7 — AD-9 : `EquatableInterface` place une règle sur l'entité `User` — **moyenne**

AD-9 : « `EquatableInterface` le compare à chaque requête, ce qui déauthentifie toutes les sessions concernées ». L'interface s'implémente sur l'objet utilisateur, donc sur l'entité — qui reçoit ainsi une méthode comportementale portant une décision de sécurité.

> « | **Entité** | État mappé, getters, setters, constantes de valeur | Invariants, méthodes de domaine façon `publish()` | »
> — contrat de couches, `symfony-proglab-architecture/SKILL.md`

> « **2. Pas de règles sur les entités.** »
> — `references/rejections.md`

Le spine se contredit lui-même : sa convention Entités dit « Aucune règle, aucun invariant ». Le mécanisme reste le bon — Symfony compare l'objet utilisateur lui-même, il n'existe pas d'alternative en service — donc c'est un écart imposé par le framework, du même genre que le `#[Assert]` que le standard tolère sur une entité pour une surface d'admin. Mais il doit être énoncé, et borné.

**Correction demandée.** Ajouter à AD-9 : écart délibéré au contrat de couches, imposé par `EquatableInterface` ; `isEqualTo()` ne compare que le jeton de sécurité et l'état actif, n'appelle aucun service et ne porte aucune autre condition ; l'incrémentation du jeton reste dans les services de `Core` (désactivation, changement de mot de passe, réinitialisation 2FA), un par cas d'usage.

## 8 — Convention DTO : l'état de `symfony/object-mapper` est faux — **moyenne**

Convention DTO : « Le mapping se fait par `static fromEntity()` ; `symfony/object-mapper` **reste expérimental sur 7.4** (AD-1) et n'entre pas dans le socle. »

> « `ObjectMapperInterface` vient de `symfony/object-mapper` — **expérimental en 7.3, stable en 7.4, absent en 6.4** »
> — `symfony-proglab-architecture/SKILL.md`

La décision survit : le standard admet les deux moyens (« sans lui, un `static fromEntity()` sur le DTO Output. La règle ne change pas, seul le moyen change »). C'est la **raison** qui est fausse, et elle va se propager dans chaque dérivé qui lira ce spine comme une source de faits sur la pile.

**Correction demandée.** Rectifier la cellule : le composant est stable en 7.4 ; `static fromEntity()` est retenu pour une autre raison, à nommer si elle existe (par exemple : garder la normalisation Read → Output visible et testable dans le service plutôt que répartie dans des attributs `#[Map]`) — sinon adopter `ObjectMapperInterface`, que le standard présente comme le moyen par défaut quand il est disponible.

## 9 — Convention Frontend : « aucun Live Component dans le socle » — **moyenne**

Convention Frontend : « Aucun Live Component dans le socle. »

> « | Un Turbo Stream là où l'interaction a un état à retenir — **un filtre, une recherche live**, un assistant en plusieurs étapes | Un Live Component | Le `LiveProp` sérialisé est ce qui porte cette valeur à travers l'aller-retour ; un stream laisse maintenir des ids et des fragments pour le simuler | »
> — `references/rejections.md`, section Frontend

> « Une seule question tranche : y a-t-il quelque chose à retenir entre deux interactions ? »
> — idem

FR-13 (filtres et export du journal d'audit) et la roadmap (FR-14/FR-15) sont précisément la forme décrite. Interdire l'outil désigné sans dire par quoi on le remplace expose le socle à ce que le standard rejette : un Turbo Stream qui simule un état avec des ids et des fragments.

**Correction demandée.** Soit énoncer l'alternative conforme et la raison — « les filtres sont des formulaires GET rendus côté serveur, l'état vit dans l'URL ; ce n'est pas un Turbo Stream qui simule un état, donc le rejet ne s'applique pas » — soit lever l'interdiction pour les surfaces à état. Ajouter le déclencheur de réouverture : la première recherche live ou le premier assistant multi-étapes du socle.

## 10 — AD-8 : la mémoïsation par requête contredit `final readonly` — **moyenne**

AD-8 : la permission effective est « calculée à chaque requête, mémoïsée pour la durée de cette requête seulement ». La convention Services, dans la même page, impose : « `final readonly`, injection par constructeur ».

> « **`final readonly`**, injection par constructeur uniquement. […] `readonly` parce qu'un service qui se mute lui-même est un service qui se comporte différemment à la deuxième requête. »
> — `symfony-proglab-architecture/SKILL.md`

Un calculateur qui mémoïse détient un état mutable : `readonly` l'interdit. L'écart est réel et non énoncé, et l'avertissement du standard s'applique littéralement à un worker ou à une commande longue, où « la deuxième requête » devient « le deuxième message ».

**Correction demandée.** Nommer le porteur du cache et sa durée de vie : un petit collaborateur dédié non `readonly` (ou un pool `cache.app` à portée de requête) injecté dans le calculateur, avec une garantie explicite d'invalidation en dehors du cycle requête — worker Messenger et commandes console inclus.

## 11 — AD-13 : « publie ses routes par des services taggés » — **basse**

AD-13 : un module publie « ses permissions, ses entrées de navigation, ses exclusions d'audit, ses catalogues de traduction **et ses routes** par des services taggés ». Les quatre premiers sont du ressort d'un service taggé ; les routes, non : le standard route par attributs sur les contrôleurs, avec un préfixe au niveau de la classe.

> « | Routes | Nom `app_<ressource>_<action>`, préfixe de chemin au niveau de la classe […] une classe de contrôleur par ressource. | »
> — convention du spine, reprise de `symfony-proglab-http`

**Correction demandée.** Retirer les routes de la liste des services taggés, et dire comment les contrôleurs d'un module sont découverts sans modifier `Core/` (un chemin de ressource `../src/Module` dans `config/routes.yaml`, qui est de la configuration, pas un fichier de `Core/`). Si un chargeur de routes est réellement voulu, le nommer pour ce qu'il est.

## 12 — Structural Seed : des dossiers que le spine exige mais ne place pas — **basse**

Le Seed n'a ni `Form/` (FR-3, FR-4 et tout écran d'administration), ni `Message/` / `MessageHandler/` (AD-4 met *tout* email sur Messenger), ni `EventListener/` (AD-3), ni `Health/` (FR-18, rangé dans `Core/Controller/`). Le standard nomme ces emplacements :

> « Un FormType vit dans `src/Form/`, et son `data_class` est un **DTO d'entrée, jamais une entité** »
> — `symfony-proglab-http/SKILL.md`

> « `src/Command`, `src/Doctrine`, `src/EventListener`, `src/Serializer`, `src/Health` et `src/Scheduler` »
> — `symfony-proglab-quality/references/deptrac.md`

Un emplacement que le Seed n'indique pas est un emplacement qu'un agent invente, ce qui est exactement ce qu'AD-2 dit vouloir éviter (« laisse un agent sans emplacement évident »).

**Correction demandée.** Compléter le Seed sous chaque racine : `Form/`, `Message/`, `MessageHandler/`, `EventListener/` (ou `Doctrine/`), `Health/`, et dire lesquels deptrac ne collecte pas.

## 13 — AD-3 : le câblage du listener n'est pas nommé — **basse**

AD-3 dit « un listener Doctrine `onFlush` » sans nommer le mécanisme, alors que le standard en désigne un et en rejette un autre :

> « **`#[AsEventListener]` sert uniquement aux événements du framework** — […] Doctrine […]. Une classe par événement, nommée d'après ce qu'elle fait, avec `__invoke()` »
> « `EventSubscriberInterface` est rejeté : plus de cérémonie, et une méthode statique à garder synchronisée avec la classe. »
> — `symfony-proglab-architecture/SKILL.md`

Le réflexe par défaut pour un événement Doctrine est un *event subscriber*, c'est-à-dire la forme rejetée. À une classe près, le spine laisse le choix ouvert au premier agent qui implémentera.

**Correction demandée.** Nommer l'attribut retenu (`#[AsDoctrineListener(event: Events::onFlush)]`, ou `#[AsEventListener]` selon ce que `vendor/` expose sur la version de DoctrineBundle retenue — à vérifier dans `vendor/`, qui prime), une classe, `__invoke()`, et interdire explicitement `EventSubscriberInterface`.

---

## Ce qui est conforme, et mérite d'être consigné

Pour que cette revue ne se lise pas comme un réquisitoire : le spine tranche correctement, et explicitement, un grand nombre de points où un dérivé aurait pu dériver.

- **Contrat de couches restitué intégralement**, schématisé, et déclaré sans exception — y compris `Repository` comme seul endroit d'une requête et `Dto/Output/` comme seul objet franchissant le service (les deux manquements ci-dessus sont des applications ratées, pas des contestations du contrat).
- **Aucune alternative rejetée réintroduite en douce** : pas de bus de commandes en process (Messenger sert aux emails, pas au CQRS), pas d'UUID ni d'identifiant non entier, pas de bundler ni d'étape Node, pas d'API Platform pour une petite API possédée de bout en bout, pas de Docker, pas de `#[Required]`, pas de transaction de test manuelle (`dama/doctrine-test-bundle`), pas de `\DateTime`, pas de constantes de chaîne pour des ensembles fermés.
- **Deux rejets nommés explicitement et correctement** : « Pas de pagination par curseur, pas de Pagerfanta », avec l'enveloppe `items` + `meta` et ses quatre entiers.
- **Transport Messenger Doctrine** avec retry, file d'échec **surveillée** par le health check, et worker livré en service systemd piloté par Deployer — le rejet « une file d'échecs que personne ne surveille » est traité, pas oublié.
- **Exceptions métier** dans `Exception/` avec `#[WithHttpStatus]` et `#[WithLogLevel]`, Problem Details RFC 7807 côté API : conforme, et conforme au rejet des listeners d'exception maison.
- **AD-11** utilise `#[When('dev')]`, c'est-à-dire le mécanisme dédié plutôt qu'une vérification à l'exécution, et le dit dans ces termes. Exemplaire.
- **AD-15** écarte l'URL signée sans état avec une raison opérationnelle (marquer consommé, invalider le précédent, porter les états vus par l'administrateur) : c'est la forme que le standard demande pour un écart.
- **Le cache du lecteur de roadmap** est le seul cache activé, justifié par un coût par requête plutôt que par une lenteur supposée, et invalidé par version de release plutôt que par TTL — ce qui évite le rejet « un cache applicatif uniquement basé sur un TTL ».
- **`damienharper/auditor-bundle` écarté avec sa raison** (branche 7.x exigeant Symfony 8, exclu par AD-1), et la réserve sur `symfony/ux-toolkit` énoncée avec son prix. C'est la discipline que demande le standard : le prix énoncé plutôt que découvert.
- **AD-2** est un écart légitime et suffisamment énoncé : le standard rend les noms de dossiers négociables, le spine conserve les couches dans les deux racines et outille la frontière par deptrac (le problème est au troisième niveau, constat 2, pas sur les racines).

## Réponses directes aux trois points signalés

1. **Le listener Doctrine d'AD-3 est-il conforme, et la frontière de transaction reste-t-elle visible ?** Le *mécanisme* est conforme — les événements Doctrine sont dans la liste blanche de `#[AsEventListener]`, et la clause du dernier recours vise les listeners de *kernel*. Mais non, la frontière de transaction **n'est plus visible** : l'audit entre dans le flush du service sans qu'aucune ligne de ce service ne le montre, ce qui reproduit le défaut du rejet « `flush()` dans un repository ». Et les règles d'audit (exclusions, acteur, « audité par défaut ») n'ont pas de service nommé. Constat 1, sévérité haute, avec les quatre corrections demandées.
2. **Le coffre de secrets est-il justifié ?** Le choix est le bon — cible nue, Deployer par SSH, aucun magasin de plateforme, donc le cas que le standard recommande explicitement — mais le spine **ne dit jamais lequel des deux cas s'applique**, ne le dit pas à l'endroit de la décision, et n'en fait pas un AD. Constat 6, sévérité moyenne : un lecteur de la seule convention ne peut pas distinguer le cas recommandé du cas rejeté.
3. **L'écart d'AD-2 est-il légitime et suffisamment énoncé ?** Oui, sur les deux racines : le standard rend les noms de dossiers négociables, et le spine maintient les couches et les fait vérifier. L'écart non légitime est ailleurs, au troisième niveau — `Audit/`, `Roadmap/`, `Security/` mélangent les couches, renomment ce que le standard appelle déjà `EventListener/`, `Doctrine/` et `Health/`, et rendent invérifiable la promesse d'AD-7. Constat 2, sévérité haute.
