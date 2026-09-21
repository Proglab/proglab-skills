# Guide de dérivation

Ce guide répond à une question : **où va cette classe, sous quel nom et sous quelle
forme**. Il s'adresse à qui construit sur ce socle, agent ou développeur, pour un
module client ou pour une story du socle. Il suffit pour ajouter une entité, un
contrôleur ou un module entier sans ouvrir aucun autre document. Les renvois aux
décisions d'architecture (`AD-*`) servent à justifier une règle, jamais à la compléter.

## À qui il s'adresse, et ce qu'il ne couvre pas

**À qui.** À quiconque reçoit une tâche métier (« ajouter une ressource », « brancher
un module client ») et doit savoir où écrire le code, comment le nommer et ce qu'il a
le droit d'appeler. À quiconque installe un dérivé chez un client et doit savoir quoi
configurer et qui sauvegarde quoi.

**Ce qu'il ne couvre pas :**

- **Faire tourner le projet en local** : prérequis, Mailpit, le worker, le profiler,
  `dump()`, le frontend sans Node, la porte de qualité et ses six catégories. Tout
  cela est dans [`README.md`](../README.md), et ce guide ne le répète pas.
- **Le *pourquoi* des décisions.** Il est dans le spine d'architecture,
  [`ARCHITECTURE-SPINE.md`](../_bmad-output/planning-artifacts/architecture/architecture-proglab-skills-2026-09-09/ARCHITECTURE-SPINE.md),
  et dans le spine UX
  ([`DESIGN.md`](../_bmad-output/planning-artifacts/ux-designs/ux-proglab-skills-2026-09-09/DESIGN.md),
  [`EXPERIENCE.md`](../_bmad-output/planning-artifacts/ux-designs/ux-proglab-skills-2026-09-09/EXPERIENCE.md)).
  Rien de ce qu'il faut *faire* pour écrire du code conforme ne dépend de ces fichiers.
  Ne supprimez pas `_bmad-output/` pour autant : la roadmap de l'Epic 3 lit
  `_bmad-output/implementation-artifacts/` (AD-10, voir « Convention d'en-tête YAML
  des stories »).
- **Le comportement de chaque écran.** Il est dans les specs de story, sous
  `_bmad-output/implementation-artifacts/`.
- **Le contenu des skills et des workflows.** Ce guide les nomme (voir « Les skills et
  les workflows à invoquer ») et ne recopie pas leur contenu.

## Les deux racines, et le contrat de couches

**Deux racines (AD-2).** `src/Core/` contient le socle et rien d'autre. Tout code
propre à un client va dans `src/Module/<Nom>/`, avec les **mêmes** dossiers de couche
que le socle. Un dérivé ne modifie **aucun fichier** de `src/Core/` ni de `config/`
(NFR-6). Quand le socle ne couvre pas un besoin, on ne le contourne pas dans le
dérivé : on ajoute un contrat à `src/Core/Contract/` **sur le socle**, puis on reporte
ce correctif dans chaque dérivé. Ce report se lit alors comme un `git diff` limité à
`src/Core/`. Un dérivé livré est maintenu, mais son socle n'est pas resynchronisé
automatiquement.

**Une seule porte (AD-13).** Un module ne voit du socle que `src/Core/Contract/` :
des interfaces et des DTO, sans Doctrine ni HTTP. Aujourd'hui, cette porte contient
une seule interface, `App\Core\Contract\ModuleDescriptor` (`name()`,
`translationDomain()`). `config/services.yaml` pose le tag `app.module` sur toute
classe qui l'implémente, et `App\Core\Service\ModuleRegistry` les reçoit par
`#[AutowireIterator('app.module')]`. Pour qu'un module se déclare, il lui suffit donc
d'implémenter cette interface.

Un enum ou un objet valeur qui passe par un contrat doit être rangé dans `Contract/`,
pas dans `src/Core/Enum/`. Le ruleset de `CoreContract` est vide : un contrat qui
référence un enum interne du socle fait échouer deptrac, et c'est voulu.

**Une frontière vérifiée par un outil (AD-7).** `deptrac.yaml` range chaque classe
dans **deux** couches à la fois : sa couche technique (Controller, Service…) et sa
couche de racine (`Core`, `CoreContract`, `Module<Nom>`). Il vérifie en CI le contrat
de couches proglab dans chaque racine, plus trois règles de frontière :

- `Core → Module` est interdit ;
- `Module → Module` est interdit ;
- `Module → Core` est interdit, sauf vers `Core\Contract`.

Une classe placée au mauvais endroit ne passe donc pas inaperçue : `make qa` échoue
et nomme la dépendance interdite — à condition que le module ait été déclaré dans
`deptrac.yaml` comme la recette le dit.

## Où va chaque type de classe

Les dossiers de couche sont les mêmes dans les deux racines. Le tableau liste ceux
qui existent aujourd'hui sous `src/Core/`. La colonne « Peut dépendre de » reprend le
ruleset de `deptrac.yaml`. Une classe de module peut en plus atteindre sa propre
racine et `CoreContract`, et rien d'autre.

| Dossier | Ce qu'il contient | Peut dépendre de (couches) |
|---|---|---|
| `Controller/` | Traduit HTTP : lit la requête, appelle un service, rend une réponse. Ne contient ni règle métier ni persistance. Une classe par ressource. | Controller, Http, Service, Dto, Entity, Form, Message, Exception, Security, Enum. Ni Repository ni Doctrine. |
| `Service/` | Toutes les règles métier. Chaque service est `final readonly` et appelle `flush()` une fois par cas d'usage. Il prend des identifiants et renvoie des DTO Output, jamais une entité — y compris vers une commande console : `App\Core\Service\DerivativeInitializer::createFirstSuperAdmin()` rend `App\Core\Dto\Output\AccountOutput`, et c'est ici que le passage de l'entité à son DTO Output se fait. | Service, Repository, Dto, Entity, Message, EntityManager (`flush()`), Exception, Enum, Twig (le moteur) — pour composer un email à partir d'un gabarit, et pour cela seulement. Ni Http ni QueryBuilder. |
| `Repository/` | Le seul endroit où écrire du DQL, du QueryBuilder ou du SQL. Il appelle `persist()` et `remove()`, jamais `flush()`. Chaque méthode porte le nom de l'intention de l'appelant. **Il n'est pas `final`.** | Repository, Entity, Dto, Doctrine, DoctrineMapping, EntityManager, Enum. |
| `Entity/` | Format maker : données et mapping, sans règle ni méthode de comportement. Seule exception nommée : `isEqualTo()` de `User` (AD-9). | Entity, DoctrineMapping, Enum. |
| `Dto/` | `Dto/Input/` porte la validation, `Dto/Read/` les projections `SELECT NEW`, `Dto/Output/` le contrat qui sort de la couche service. Un DTO ne connaît pas les entités. | Dto, Enum. **Pas Entity.** |
| `Message/` | Les messages Messenger. Un message ne porte que des scalaires et une locale explicite, jamais une entité, et **son routage vit sur la classe** : `#[AsMessage('async')]`. Sans lui, le handler s'exécute en synchrone dans la requête. `config/packages/messenger.yaml` n'écrit **aucun** `routing:` : un bloc de routage n'y serait justifié que pour un message **tiers**, sur lequel on ne peut pas poser d'attribut. | Message, Service, Repository, Dto, Entity, Exception, Enum. |
| `MessageHandler/` | Traduit un message en appel de service, comme un contrôleur traduit une requête. Même couche deptrac que `Message/`. | Mêmes couches que `Message/`. |
| `Exception/` | Les exceptions métier. Chacune porte son statut HTTP (`#[WithHttpStatus]`) et son niveau de log (`#[WithLogLevel]`), **le statut écrit en littéral** : la couche ne voit pas `HttpFoundation`, donc `Response::HTTP_NOT_FOUND` fait échouer deptrac. Seule exception à la règle des deux attributs, un échec qui ne franchit jamais HTTP — il écrit alors son absence (`App\Core\Exception\InitializationFailed`). Elles n'existent que pour un échec qu'un appelant peut réellement rencontrer. | Exception. |
| `Security/` | Plie l'authentification et l'autorisation au métier : `UserChecker` et `LoginFailureMessage` aujourd'hui, les voters à l'Epic 2. Un voter décide sur une entité ou un DTO. | Security, Entity, Dto, Enum. |
| `Enum/` | Les enums natifs, mappés avec `enumType:`. | Enum. |
| `Command/` | Les commandes console : une couche fine de traduction au-dessus d'un service. Voir `App\Core\Command\DeploymentCheckCommand` pour la forme invocable. | Command, Service, Dto, Enum, Exception. **Ni Entity, ni Http, ni Message** : une commande qui tient une entité l'a reçue d'un service qui aurait dû rendre un DTO Output, une commande n'a pas de requête, et mettre un travail en file passe par le service qui porte la règle. |
| `EventListener/` | Écoute des événements **du framework** (locale, échec de connexion). Le métier s'appelle directement, pas par événement. | EventListener, Service, Dto, Entity, Enum, Exception, Security, Http, Twig (le moteur). Rendre une réponse sur un événement de sécurité est prévu ; interroger la base ne l'est pas, et mettre un travail en file non plus — pas de Message, c'est le service appelé qui le fait. |
| `Twig/` | Les extensions et runtimes Twig. Le dossier est vide dans le socle aujourd'hui : la couche est écrite pour le premier arrivant. Elle s'appelle `TwigExtension` dans `deptrac.yaml`, parce que `Twig` y désigne le moteur. | TwigExtension, Service, Dto, Enum, Exception, Twig (le moteur). **Ni Entity, ni Http, ni Message** : une entité qui atteint une extension atteint le gabarit, la requête courante n'est pas une donnée de gabarit, et une extension ne met rien en file. |
| `Contract/` | La porte publique : interfaces et DTO seulement, sans Doctrine ni HTTP. **Elle existe seulement dans `src/Core/`.** | Rien (`CoreContract: ~`). |

`deptrac.yaml` connaît aussi une couche `Form/`. Aucun dossier `Form/` n'existe
pourtant sous `src/Core/`, car le socle n'installe pas `symfony/form` : ses
formulaires sont écrits en Twig, et le contrôleur construit un DTO Input à partir de
la requête avant de le valider (voir `App\Core\Controller\PasswordController`). Un
module qui a besoin du composant Form doit le **proposer**, et non l'installer sans le
dire.

Trois autres emplacements ne se trouvent pas dans la racine du module :

- **Les templates** vont sous `templates/<module>/` (le nom du module en minuscules).
  Aucun glob ne déclare de dossier de templates *dans* un module, et en ajouter un
  modifierait `config/`. Ce dossier ne doit reprendre aucun dossier du socle :
  `bundles/`, `components/`, `emails/`, `home/`, `password/`, `security/`. Un module
  `Security` ou `Password` choisit donc un autre nom de dossier.
- **Les migrations** vont sous `migrations/`, dans le namespace `DoctrineMigrations`.
- **Les tests** vont sous `tests/Module/<Nom>/`, de la même façon que ceux du socle
  vivent sous `tests/Core/`. Ne les mettez jamais sous `tests/Fixtures/Module/` : ce
  dossier est réservé aux modules de démonstration, que deptrac et les miroirs
  `when@test` analysent.

**Un exemple exécuté plutôt qu'un exemple recopié ici.**
[`tests/Fixtures/Module/Demo/`](../tests/Fixtures/Module/Demo/) est le module de
démonstration. La suite l'exécute à chaque build (`tests/Core/ModuleWiringTest.php`).
Il contient :

- `Controller/DemoWidgetController.php` (et `DemoErrorController.php`, qui ne sert qu'à
  prouver les pages d'erreur) ;
- `Dto/Input/`, `Dto/Output/`, `Dto/Read/` (vides) ;
- `Entity/DemoWidget.php` ;
- `Enum/DemoWidgetStatus.php` ;
- `Exception/DemoWidgetNotFound.php` ;
- `Message/RefreshDemoWidget.php` et
  `MessageHandler/RefreshDemoWidgetHandler.php` ;
- `Service/DemoDescriptor.php` ;
- `Command/`, `EventListener/`, `Repository/`, `Security/`, `Twig/` (vides) ;
- `translations/` : `translations/demo.{fr,en,nl}.yaml`.

Les quatre classes des dossiers `Enum/`, `Exception/`, `Message/` et
`MessageHandler/` **sont la forme à recopier pour un module**, et elles ne sont pas
là pour décorer : les trois premières prouvent que la découverte des services les
exclut (étape 4), la quatrième qu'un handler, lui, reste un service.
`tests/Core/ModuleWiringTest.php` les lit. Deux détails s'y lisent et ne se
devinent pas :

- `DemoWidgetNotFound` porte ses deux attributs avec **un statut en littéral**
  (`#[WithHttpStatus(404)]`) : la couche `Exception/` ne voit pas `HttpFoundation`,
  donc `Response::HTTP_NOT_FOUND` fait échouer deptrac ;
- `RefreshDemoWidget` **n'a pas** de `#[AsMessage('async')]`, et c'est la seule
  chose à ne pas recopier : il n'est jamais dispatché. Un message réel le porte,
  sans quoi son handler s'exécute en synchrone dans la requête.

Ses fichiers
`translations/emails.{fr,en,nl}.yaml`
ajoutent volontairement une clé au domaine `emails` du socle, pour prouver un
mécanisme de test. Un vrai module ne fait jamais cela (voir l'étape 5 de la recette).
[`tests/Fixtures/Module/Billing/`](../tests/Fixtures/Module/Billing/) est un second
module, minimal, qui sert à prouver l'interdiction `Module → Module`.

`src/Module/` est livré **vide**, et `ModuleWiringTest::the_module_root_ships_empty()`
le vérifie. Ce test ne garde que la livraison du socle : un dérivé le supprime au
premier module (étape 2 de la recette). Copiez la *forme* de `Demo`, pas ses fichiers.

### Exemple : ajouter une entité métier

Pour une entité `Article` dans un module `Catalog` :

- **Emplacement et namespace** : `src/Module/Catalog/Entity/Article.php`, namespace
  `App\Module\Catalog\Entity` (`composer.json` fait correspondre `App\` à `src/`).
- **Forme** : celle de `tests/Fixtures/Module/Demo/Entity/DemoWidget.php`, à une
  différence près : `DemoWidget` n'a pas de repository, alors que votre entité en a un
  et porte donc `#[ORM\Entity(repositoryClass: ArticleRepository::class)]`. Elle a un `id`
  entier auto-incrémenté (`?int $id = null`), des propriétés privées, des getters et
  des setters qui renvoient `static`. Les dates sont en `\DateTimeImmutable` et les
  enums sont natifs, avec `enumType:`. Elle ne contient ni règle, ni invariant, ni
  constructeur obligatoire. La table prend un nom en `snake_case`, car la stratégie de
  nommage est `underscore`. `symfony/maker-bundle` n'est pas installé : le « format
  maker » décrit une forme, pas une commande à lancer.
- **Repository** : `src/Module/Catalog/Repository/ArticleRepository.php`. Il étend
  `ServiceEntityRepository` et **n'est pas `final`**, pour que les tests unitaires de
  service puissent le doubler. Voir `App\Core\Repository\LanguageRepository`.
- **Mapping** : rien à faire. Le mapping `Module` de `config/packages/doctrine.yaml`
  parcourt tout `src/Module` et retient les classes qui portent `#[ORM\Entity]`.
- **Migration** : générez-la avec `php bin/console doctrine:migrations:diff`, puis
  **relisez-la** comme les migrations du socle :
  - `ENGINE = InnoDB` explicite ;
  - noms réservés entre accents graves ;
  - `down()` dans l'ordre inverse exact de `up()` ;
  - une colonne ou une table renommée est générée en `DROP` puis `ADD`, ce qui perd
    ses données : réécrivez-la en `RENAME` ;
  - une colonne `NOT NULL` ajoutée à une table peuplée échoue : ajoutez-la nullable,
    remplissez-la, puis rendez-la `NOT NULL` ;
  - un changement de type qui rétrécit une colonne tronque ou échoue : vérifiez les
    données existantes d'abord ;
  - un `down()` qui ne peut pas rendre ce que `up()` a détruit doit le dire (lever une
    exception) plutôt que de faire semblant.

  Le détail est dans le skill `symfony-proglab-doctrine`.

  La porte lance `doctrine:schema:validate --skip-sync` : **rien ne compare la
  migration au mapping**, la relecture est la seule garantie.
- **Ce qui a le droit d'y toucher** : un service de `Catalog` la charge par son
  repository, la modifie et appelle `flush()`. Un contrôleur peut la recevoir par
  l'`EntityValueResolver` quand un `#[IsGranted]` en a besoin, mais il passe ensuite
  son identifiant au service. L'entité n'atteint **jamais** un template ni une réponse
  JSON : le service renvoie un DTO de `Dto/Output/`. Aucune classe d'un autre module ni
  du socle ne peut la référencer.
- **Passer de l'entité au DTO Output** : dans la couche service, jamais sur le DTO. Le
  ruleset `Dto` de `deptrac.yaml` n'autorise que `Dto` et `Enum` : un
  `static fromEntity(Article $article)` écrit sur le DTO fait échouer `make qa`. Écrivez
  le passage dans le service qui renvoie le DTO, ou dans un mapper dédié de
  `src/Module/Catalog/Service/` (`ArticleMapper`, `final readonly`) quand plusieurs
  services en ont besoin. Le spine prévoit `ObjectMapperInterface`, mais
  `symfony/object-mapper` n'est pas installé dans le socle : proposez de l'ajouter
  plutôt que de l'installer sans le dire.

## Ajouter un module : la recette complète

**AD-13** : le câblage framework (mapping Doctrine, routes, chemins du translator,
découverte des services) est fixé une fois pour toutes, par glob sur `src/Module/*/`.
Ajouter un module ne modifie donc **aucun fichier** de `src/Core/` ni de `config/`.
Les seuls fichiers existants que la recette modifie sont `deptrac.yaml`, qui
n'appartient à aucun des deux, `tests/Core/ModuleWiringTest.php`, une seule fois, et
`tests/Core/Accessibility/RouteSamples.php`, pour chaque route GET à paramètre.

1. **Écrivez les tests du module d'abord**, sous `tests/Module/<Nom>/`, et voyez-les
   échouer. Le premier peut simplement affirmer que `ModuleRegistry::names()` contient
   `<Nom>` : il est rouge tant que le module ne s'est pas déclaré. Pour la boucle
   complète, invoquez `symfony-proglab-testing`.
2. **Créez `src/Module/<Nom>/`** avec les dossiers de couche dont le module a besoin
   (jamais `Contract/`). `<Nom>` est un singulier anglais en PascalCase (`Catalog`,
   `Invoice`) et donne le namespace `App\Module\<Nom>\`. **N'utilisez ni `Demo`, ni
   `Billing`, ni `Stock`** :
   - les couches deptrac des modules de démonstration collectent aussi
     `src/Module/Demo/` et `src/Module/Billing/`, et `ModuleRegistry` verrait deux fois
     le même nom en test ;
   - `tests/Core/BoundaryTest.php` (`a_module_nobody_declared_is_refused_everything()`)
     pose `src/Module/Stock/` comme module **non déclaré** dans son bac à sable. Un
     dérivé qui déclarerait `ModuleStock` ferait rougir ce test.

   **Au premier module seulement, supprimez la méthode
   `the_module_root_ships_empty()`** (et son docblock) de
   `tests/Core/ModuleWiringTest.php`. Elle garde la livraison du socle, qui doit partir
   avec un `src/Module/` vide ; chez un dérivé qui a des modules, elle est fausse par
   construction. Le reste de ce fichier ne change pas.
3. **Implémentez `App\Core\Contract\ModuleDescriptor`** dans
   `src/Module/<Nom>/Service/<Nom>Descriptor.php`, une classe `final readonly`.
   `name()` renvoie `<Nom>` et `translationDomain()` renvoie le domaine du module (voir
   l'étape 5). Voir `tests/Fixtures/Module/Demo/Service/DemoDescriptor.php`.
4. **Ne modifiez pas les quatre fichiers de configuration du glob.** Ils visent déjà
   `src/Module/*/`, et chacun a un miroir `when@test` sur `tests/Fixtures/Module/` :

   | Fichier | Glob de production | Ce qu'il câble déjà pour tout module |
   |---|---|---|
   | `config/services.yaml` | `../src/Module/*/` | Découverte des services (clé `resource`), sauf `Contract/`, `Dto/`, `Entity/`, `Enum/`, `Exception/` et `Message/` |
   | `config/routes.yaml` | `../src/Module/*/Controller/**/*.php` | Routes par attribut (entrée `module_controllers`) |
   | `config/packages/doctrine.yaml` | `%kernel.project_dir%/src/Module` | Mapping des entités (mapping `Module`, préfixe `App\Module`) |
   | `config/packages/translation.yaml` | `%kernel.project_dir%/src/Module` | Chemins du translator, parcourus récursivement |

   La clé `exclude` du premier vaut exactement
   `../src/Module/*/{Contract,Dto,Entity,Enum,Exception,Message}` — **la liste du socle,
   mot pour mot** : un module ne propose jamais comme service ce que le socle exclut.
   Les six dossiers portent des données, pas des services ; une classe que vous y
   déposez ne sera donc pas autowirable, et c'est voulu. `MessageHandler/` n'en fait pas
   partie : un handler *est* un service. `tests/Core/ModuleWiringTest.php` refuse que
   cette liste diverge de celle du socle, sur le glob de production comme sur son miroir
   `when@test`, et `tests/Core/Documentation/DerivationGuideTest.php` refuse que la
   ligne ci-dessus cesse d'être celle du fichier.

5. **Livrez les catalogues du module** sous
   `src/Module/<Nom>/translations/<domaine>.{fr,en,nl}.yaml`, avec **les trois langues
   supportées**, jamais une partie. Les clés sont en anglais pointé et le texte source
   est en français. `tests/Core/Translation/CatalogParityTest.php` refuse toute clé
   manquante ou vide dans l'une des trois langues.

   Le domaine est le nom du module en minuscules (`catalog`), et il ne doit **jamais**
   reprendre un domaine existant : `messages`, `security`, `validators` et `emails`
   sont ceux du socle et de Symfony. Le translator fusionne tous les fichiers d'un
   même domaine, donc un module `Security` qui livrerait `security.fr.yaml` mélangerait
   ses clés à celles du framework. Dans ce cas, choisissez un autre domaine
   (`security_audit`, par exemple) et renvoyez-le depuis `translationDomain()`. Le
   suffixe `+intl-icu` ne change pas le domaine : `security+intl-icu.fr.yaml` est le
   domaine `security`.
6. **Modifiez `deptrac.yaml`. Il demande quatre éditions**, et son en-tête le dit
   aussi. Toutes copient ce qui existe pour `ModuleDemo` et `ModuleBilling`. Faites-les
   **dans cet ordre** :
   1. **le bloc de couche** `Module<Nom>`, sous `layers:`, dans la section « Un module,
      une couche ». Son unique collecteur est le dossier
      `(src|tests/Fixtures)/Module/<Nom>/.*` :

      ```yaml
      - name: Module<Nom>
        collectors:
            - type: directory
              value: (src|tests/Fixtures)/Module/<Nom>/.*
      ```

   2. **la ligne de ruleset**, sous `ruleset:`, qui n'ouvre au module que toutes les
      couches techniques, sa propre racine et la porte publique. En une ligne :
      `Module<Nom>: ['+AnyLayer', 'Module<Nom>', 'CoreContract']`. Sous la forme des
      blocs existants :

      ```yaml
      Module<Nom>:
          - '+AnyLayer'
          - Module<Nom>
          - CoreContract
      ```

   3. **le nom ajouté à `AnyRoot`** (`- Module<Nom>`). Sans lui, aucune couche
      technique ne voit la racine du nouveau module, et un contrôleur qui appelle son
      propre service devient une violation ;
   4. **l'exclusion ajoutée au `must_not` de `UndeclaredModule`**, avec le même
      dossier que le bloc de couche :

      ```yaml
      - type: directory
        value: (src|tests/Fixtures)/Module/<Nom>/.*
      ```

   `UndeclaredModule` est le filet de sécurité : tout module qu'il attrape n'a le droit
   de dépendre de rien. **Règle d'état : jamais une exclusion dans le `must_not` sans
   le bloc de couche du même module.** L'ordre ci-dessus n'est qu'un moyen de la tenir
   pendant l'édition ; c'est l'état final du fichier qui compte. Oublier
   la ligne de ruleset, le nom dans `AnyRoot` ou l'exclusion ne rouvre rien : deptrac
   échoue dès la première dépendance concernée (celle du descripteur vers
   `CoreContract` suffit pour la ligne de ruleset et pour l'exclusion ; un contrôleur
   qui appelle son propre service suffit pour `AnyRoot`). En revanche, **ajouter
   l'exclusion sans le bloc de couche ouvre la
   frontière en silence** : le module n'a plus aucune couche de racine, sa couche
   technique hérite de `+AnyRoot`, et il atteint `Core` sans violation. deptrac ne
   signale pas qu'un ruleset nomme une couche jamais déclarée, et sort en 0.

   **Vous n'avez rien à vérifier à la main.** La suite le fait, sur les modules de
   `src/Module/` comme sur ceux de `tests/Fixtures/Module/` :
   `every_exclusion_of_the_undeclared_module_net_carries_its_three_other_editions()`
   (`tests/Core/Documentation/DerivationGuideTest.php`) part de chaque exclusion du
   `must_not` et exige ses trois autres éditions, en nommant celle qui manque. Il suffit
   donc de lancer `make test` — ou `make qa` à l'étape 8.
7. **Donnez son échantillon à chaque route GET à paramètre.** Le plancher
   d'accessibilité (`tests/Core/Accessibility/AccessibilityFloorTest.php`) rend
   **toutes** les routes GET de l'application, celles des modules comprises. Une route
   dont le chemin porte un paramètre (`/articles/{id}`) doit recevoir, dans le `match`
   de `App\Tests\Core\Accessibility\RouteSamples::for()`, les paramètres qui lui font
   rendre un écran réel — en créant la ligne en base si nécessaire. Sans échantillon,
   `AccessibilityFloorTest` échoue en nommant la route. Voir le bras
   `app_password_reset`.
8. **Lancez `make qa`.** Les six catégories doivent passer sans qu'aucune cible
   `make` ni aucun job de CI ne soit ajouté : `QualityGateParityTest` refuse une
   catégorie qui n'existerait que d'un côté. Les routes du module doivent alors
   apparaître dans `php bin/console debug:router`, et le test de l'étape 1 doit être
   vert.

## Conventions de nommage

Ces conventions résument la section « Consistency Conventions » du spine
d'architecture. Elles suffisent pour écrire du code conforme.

- **Namespaces** : `App\Core\<Couche>\…` et `App\Module\<Nom>\<Couche>\…`. Seul
  `App\Core\Contract\…` est visible d'un module.
- **Services** : `final readonly`, injection par constructeur. Chacun porte le nom de
  sa responsabilité unique (`PasswordResetRequest`, `DerivativeInitializer`), jamais
  `<Chose>Service`. Un service de lecture peut porter toutes les lectures d'une
  ressource.
- **Repositories** : `<Entité>Repository`, pas `final`.
- **Entités** : format maker, `id` entier auto-incrémenté, `\DateTimeImmutable`,
  enums natifs. Aucune méthode de comportement, sauf `isEqualTo()` (AD-9).
- **DTO** : `Dto/Input/`, `Dto/Read/`, `Dto/Output/`. Un DTO Output par *forme* de
  données, pas par endpoint, **nommé d'après cette forme et suffixé `Output`** :
  `App\Core\Dto\Output\AccountOutput` porte un compte réduit à son identifiant et à son
  adresse, et non `FirstSuperAdminOutput` qui aurait nommé l'appelant. Un compte avec son
  rôle et son état serait une autre forme, donc une autre classe.
- **Routes** : nom `app_<ressource>_<action>`, préfixe de chemin et de nom posé sur la
  classe (`#[Route('/demo/widgets', name: 'app_demo_widget_')]`). Les chemins sont en
  anglais, invariables, sans préfixe de langue.
- **Actions destructives** : deux routes. Un GET rend une page de confirmation
  complète, et un POST protégé par un jeton CSRF agit. La déconnexion est la seule
  exception CSRF, et toute autre exception demande une nouvelle décision
  d'architecture.
- **Erreurs** : exceptions métier dans `Exception/` avec `#[WithHttpStatus]` et
  `#[WithLogLevel]`. Une entrée invalide produit un 422 à partir des contraintes du
  DTO, pas une classe d'exception. Une zone non permise répond 403. Un objet non
  permis répond 404, comme un objet inexistant. L'API répond en Problem Details
  (RFC 7807).
- **Listes** : enveloppe `items` + `meta` (`page`, `perPage`, `total`, `pages`), sans
  pagination par curseur.
- **Permissions** : code stable en `snake_case`, préfixé par sa ressource
  (`user.create`, `audit.export`). Elles arrivent à l'Epic 2.
- **Traduction** : catalogues par domaine, texte source en français, clés en anglais
  pointé (`error.not_found.title`). Un domaine de module ne reprend jamais un domaine
  existant (étape 5 de la recette).
- **Configuration** : une variable d'environnement pour ce qui dépend de la machine, un
  paramètre `app.` pour un comportement identique partout, une constante de classe
  pour ce qui ne change presque jamais. Les secrets vont dans `.env.local` en
  développement et dans le coffre de secrets Symfony en production.

## Rebranding (UX-DR-2)

Un dérivé change **deux choses, et seulement deux** :

1. **les variables `oklch` de [`assets/styles/brand.css`](../assets/styles/brand.css)**,
   au minimum `--primary` et `--primary-foreground`. Le fichier est livré vide (ses
   variables sont commentées) et `assets/styles/app.css` l'importe après `theme.css`.
   Chaque variable se redéfinit **trois fois** : dans `:root`, puis dans les deux blocs
   sombres. **Remesurez le contraste** après chaque changement (4,5:1 sur le texte,
   3:1 sur les composants) avec le skill `symfony-proglab-accessibility`, car aucun
   test ne mesure des pixels rendus ;
2. **la marque graphique**, qui vit dans [`assets/brand/`](../assets/brand) : l'icône du
   navigateur — `favicon.svg`, et son repli matriciel `favicon.png` que Safari prend
   parce qu'il ne rend pas les favicons SVG — et le logo de la sidebar (un carré aux
   couleurs de `--primary`, avec les initiales du dérivé), qui arrive avec la coque de
   l'application, story 2.3. Le défaut du socle est un carré plein sans glyphe, qui
   porte sa propre media query sombre pour rester visible dans un onglet sombre.

**Ces fichiers se remplacent, ils ne s'éditent pas.** Le gabarit les désigne par leur
chemin logique et les sert par AssetMapper : **aucun fichier de code n'est à modifier**,
ni dans `src/`, ni dans `config/`. Deux points de vigilance, tous deux silencieux :

- **Remplacez `favicon.svg` et `favicon.png` ensemble.** Les deux `<link rel="icon">`
  sont indépendants : n'en remplacer qu'un donne votre marque sur certains navigateurs
  et le carré du socle sur les autres, sans aucun message d'erreur.
- **Rejouez `php bin/console asset-map:compile` après le remplacement.** Le chemin
  public porte un condensat du contenu du fichier ; tant que les assets ne sont pas
  recompilés, l'application continue de servir l'ancienne icône. Le déploiement
  exécute cette commande (voir [`README.md`](../README.md)) ; en développement,
  AssetMapper lit les fichiers sources et l'icône change au rechargement.

Le nom du produit n'est pas une troisième chose à rebrander : c'est de la
configuration (`APP_NAME`, voir ci-dessous). Il alimente le `<title>` de chaque page et
le pied des emails ; il ne va **pas** dans le `h1`, qui nomme la **page**.
`assets/styles/theme.css` contient le thème du socle et n'est **jamais** modifié par un
dérivé. Aucun réglage de marque
n'existe dans l'administration, et aucun template ne code une couleur en dur. Deux
valeurs du kit shadcn sont sous un seuil WCAG : la bordure de champ `--input` et
l'anneau de focus `--ring`. Elles sont **conservées** comme choix assumé et ne sont
jamais consignées comme dette. On peut les renforcer, jamais les supprimer.

## Ce qu'un dérivé configure

Tout ce qui suit se règle **sans modifier `src/` ni `config/`**. Les variables
d'environnement ont leur valeur par défaut dans `.env`, expliquée en commentaire. On
les surcharge dans `.env.local`, qui n'est pas versionné, ou dans l'environnement du
serveur.

| Quoi | Où | À savoir |
|---|---|---|
| Nom du produit | `APP_NAME`, dans `.env.local` ou l'environnement du serveur | Il va dans le paramètre `app.name`, puis dans la variable globale Twig `app_name`. Le `<title>` de chaque page devient `<page> — <nom du dérivé>` et les emails le reprennent. Le `h1` nomme la page, jamais le produit : un lecteur d'écran l'annonce après chaque navigation Turbo, il doit dire où l'on est. |
| Marque | `assets/styles/brand.css`, puis les fichiers de `assets/brand/` | Voir « Rebranding ». Les fichiers d'icône se remplacent, ils ne s'éditent pas. |
| SMTP | `MAILER_DSN` et `MAILER_SENDER` | En développement, `MAILER_DSN` vise Mailpit. En production, il vise le SMTP du serveur client. La valeur par défaut `no-reply@localhost` ne peut pas servir en production, et c'est voulu. |
| Langues actives | Table `language`, colonne `enabled` | Les langues **supportées** (`fr`, `en`, `nl`) sont fixées dans le code (`App\Core\Enum\SupportedLocale`). Les langues **actives** sont des lignes en base, et toutes les trois sont actives à l'installation (migration `Version20260911074811`). **Jamais une variable d'environnement** (AD-14) : FR-22 exige un écran d'administration et une entrée d'audit. Cet écran arrive avec la story 2.9. |
| `DEFAULT_URI` | `.env.local` ou l'environnement du serveur | C'est l'hôte des liens que le worker écrit dans les emails. `php bin/console app:deployment:check` échoue tant que `DEFAULT_URI` pointe sur un hôte local. |
| Durées de jetons | Constantes de classe | Un lien de réinitialisation vaut `App\Core\Service\PasswordResetRequest::LIFETIME` (`PT1H`). **Ce réglage n'est pas modifiable sans toucher `src/Core/`** : voir « Limites assumées ». |
| Base de données, file des emails | `DATABASE_URL`, `MESSENGER_TRANSPORT_DSN` | La file vit dans la base de l'application (`doctrine://default`). Elle est donc sauvegardée avec elle. |

Le premier compte se crée avec `php bin/console app:init` (`App\Core\Command\InitCommand`).
Cette commande prépare la base, joue les migrations et crée le premier Super admin.
Elle refuse d'agir sur une base déjà peuplée sans `--force`.

## Les skills et les workflows à invoquer

Les 18 skills `symfony-proglab-*` sont dans `.claude/skills/`, et le tableau du
README les liste. Chacun s'invoque par son nom :

- **Toujours commencer par `symfony-proglab-standards`.** Il lit le projet, énonce les
  cinq règles et indique le skill spécialisé à charger ensuite.
- **`symfony-proglab-architecture`** : où placer une classe, les DTO, les services.
- **`symfony-proglab-testing`** : la boucle « test écrit d'abord, vu rouge ».
- Puis le skill de la surface à construire :
  - `symfony-proglab-http` (contrôleurs, routes, pages Twig) ;
  - `symfony-proglab-doctrine` (entités, repositories, migrations) ;
  - `symfony-proglab-security` (voters, CSRF) ;
  - `symfony-proglab-frontend` (Stimulus, Turbo, AssetMapper) ;
  - `symfony-proglab-ui` (kit shadcn) ;
  - `symfony-proglab-accessibility` ;
  - `symfony-proglab-async` (Messenger, emails) ;
  - `symfony-proglab-console` ;
  - `symfony-proglab-quality` (PHPStan, deptrac, CI) ;
  - `symfony-proglab-local-dev` ;
  - `symfony-proglab-deployment` ;
  - `symfony-proglab-observability` ;
  - `symfony-proglab-performance` ;
  - `symfony-proglab-storage` ;
  - `symfony-proglab-upgrade`.

Les workflows BMAD, dans `.claude/skills/` eux aussi :

- **`bmad-help`** : savoir quoi faire ensuite.
- **`bmad-create-epics-and-stories`** puis **`bmad-sprint-planning`** : découper les
  exigences, puis générer ou réparer `sprint-status.yaml`.
- **`bmad-spec`** : transformer une demande en spec.
- **`bmad-build`** : implémenter une story en commençant par les tests.
- **`bmad-code-review`** : faire relire les changements avant la fusion.
- **`bmad-correct-course`** : gérer un changement de cap en cours de sprint.
- **`bmad-retrospective`** : faire le bilan en fin d'epic.

## Convention d'en-tête YAML des stories

**AD-10** fait des fichiers BMAD un contrat d'entrée que le socle définit et lit sans
jamais y écrire. La roadmap de l'Epic 3 (FR-14, story 3.2) lira la **priorité**, la
**difficulté**, l'**assignation** et les **dépendances** d'une tâche dans un fichier
léger par story. Cette section fixe le contrat que ce lecteur implémentera :

```text
_bmad-output/implementation-artifacts/stories/{n}-{m}-{slug}.md
```

Ce fichier ne contient **que** l'en-tête YAML. `{n}-{m}` est le numéro de la story, et
le lecteur le reconnaîtra par ce préfixe (`1-13-…`). Le `{slug}` reprend le titre de la
clé de `sprint-status.yaml`, sans accents, comme le nom des specs. Il faut un seul
fichier par préfixe.

| Champ | Valeurs | Si le champ est absent, le lecteur rendra |
|---|---|---|
| `priority` | `high`, `medium` ou `low` | « non renseigné » |
| `difficulty` | `S`, `M` ou `L` | « non renseigné » |
| `assignee` | chaîne libre | « non renseigné » |
| `depends_on` | liste de clés `{n}-{m}` (`['1-4', '1-6']`) | aucune dépendance : la tâche sera prête dès que son statut sera « à faire » |

```yaml
---
priority: high
difficulty: S
depends_on: ['1-4', '1-6']
---
```

Le fichier peut exister dès le statut `backlog`, avant qu'aucune spec ne soit écrite.
`bmad-sprint-planning` n'écrit que `sprint-status.yaml` et ne lit pas `stories/` : le
fichier d'en-tête ne change aucun statut et n'est jamais écrasé. Exemple réel :
[`stories/1-13-rendre-la-deconnexion-joignable-sans-jeton.md`](../_bmad-output/implementation-artifacts/stories/1-13-rendre-la-deconnexion-joignable-sans-jeton.md).

**Les statuts viennent d'ailleurs.** Leur source est
`_bmad-output/implementation-artifacts/sprint-status.yaml`, sous
`development_status`. Les clés `epic-{n}` désignent une epic, et les clés
`{n}-{m}-{titre}` désignent une tâche. Ces clés sont en français **avec accents**. Une
story peut avoir l'un de ces cinq statuts :

- `backlog` ;
- `ready-for-dev` ;
- `in-progress` ;
- `review` ;
- `done`.

Ce qui suit est le contrat que le lecteur de la story 3.2 implémentera (AD-10) ; rien
ne lit encore ces fichiers. Les clés `epic-{n}-retrospective` ne compteront pas dans l'avancement. Un
statut inconnu sera rendu « à faire » et signalé aux administrateurs, et un champ
d'en-tête absent sera rendu « non renseigné », comme le dit le tableau ci-dessus.

## Sauvegarde et restauration

**AD-22 : la sauvegarde est la responsabilité du serveur client.** Le socle ne fournit
aucun outil de sauvegarde. Il exige seulement que la question soit traitée avant la
mise en service. Chaque dérivé écrit, pour son serveur réel, le mécanisme retenu et la
procédure ci-dessous. **Il l'exécute ensuite une fois avant la mise en service** et
note la date, la durée et le résultat. Une restauration jamais essayée n'est pas une
procédure.

**Ce qu'il faut sauvegarder :**

- **la base MySQL complète** que vise `DATABASE_URL`, par exemple avec
  `mysqldump --single-transaction`. Elle contient aussi la file des emails
  (`messenger_messages`) et les jetons de compte (`account_token`). **Le dump contient
  donc des empreintes de mots de passe et des jetons encore valides** : chiffrez-le et
  stockez-le hors du serveur ;
- **la configuration qui n'est pas dans le dépôt** : `.env.local` (ou le
  `.env.local.php` produit par `composer dump-env prod`), et la clé de déchiffrement du
  coffre de secrets si le dérivé en a un ;
- **les fichiers déposés par les utilisateurs**, dès qu'un module en stocke. Le socle
  n'en stocke aucun aujourd'hui.

Le cache (`var/`) et les sessions (des fichiers) ne se sauvegardent pas : les perdre
ne fait que déconnecter les utilisateurs.

**Procédure de restauration :**

1. **Coupez le trafic web** (page de maintenance, ou site Apache désactivé) **et
   arrêtez le worker** (`systemctl stop proglab-worker`, unité
   `deploy/systemd/proglab-worker.service`). Aucune requête ni aucun message ne doit
   écrire dans la base pendant la restauration.
2. Restaurez la base dans celle que vise `DATABASE_URL`, puis la configuration hors
   dépôt et les fichiers.
3. Mettez le schéma au niveau du code déployé :
   `php bin/console doctrine:migrations:migrate --no-interaction`. Une sauvegarde plus
   ancienne que la release en place n'a pas les migrations suivantes.
4. Vérifiez la configuration : `php bin/console app:deployment:check`.
5. **Rattrapez ce que la sauvegarde a effacé, trafic toujours coupé.** Tout ce qui
   s'est passé après la sauvegarde est perdu. Avant d'ouvrir l'application à tous,
   corrigez en base ou par un accès que la maintenance ne laisse qu'à vous :
   - **un compte désactivé après la sauvegarde redevient actif** : désactivez-le de
     nouveau ;
   - **un compte anonymisé après la sauvegarde retrouve ses données personnelles** :
     refaites l'anonymisation ;
   - **un compte créé après la sauvegarde a disparu** : recréez-le ou prévenez son
     titulaire ;
   - **un mot de passe changé après la sauvegarde revient à l'ancien** : prévenez les
     utilisateurs concernés ;
   - un lien de réinitialisation émis après la sauvegarde ne fonctionne plus, car son
     jeton n'existe plus en base.
6. Retirez de la file (`messenger_messages`) les messages destinés à un compte
   anonymisé après la sauvegarde, puis redémarrez le worker (`systemctl start proglab-worker`). Les messages qui étaient
   dans la file au moment de la sauvegarde **repartent** : un email déjà reçu peut
   arriver une seconde fois.
7. Connectez-vous avec un compte connu et ouvrez une page, toujours par l'accès de
   maintenance, puis rouvrez le trafic web à tous. La restauration est terminée quand cela fonctionne et que le trafic est
   rouvert.

## Limites assumées

- **Un email déjà en file part à l'ancienne adresse** (AD-4). Un message ne contient
  que des scalaires, dont l'adresse et le nom du destinataire, et l'anonymisation d'un
  compte ne les modifie pas. C'est une limite acceptée, pas un oubli. Corollaire : un
  message qui arrive dans la file d'échec avec un compte anonymisé doit être
  **abandonné**, pas renvoyé. C'est la règle qu'AD-4 fixe pour l'anonymisation, qui
  arrive avec la story 4.8 ; rien ne l'applique encore. Toujours au titre d'AD-4, la
  panne à surveiller quand la double authentification par email arrivera (Epic 5) est
  l'enfermement des administrateurs : si la file ne part plus, plus personne ne reçoit
  son code.
- **Un module ne peut pas encore envoyer d'email.** `App\Core\Message\SendEmail` ne
  fait pas partie de `Core\Contract`, et deptrac refuse donc `Module<Nom> on Core`. Il
  faudra ajouter un contrat d'envoi au socle le jour où un module en aura réellement
  besoin (voir
  [`deferred-work.md`](../_bmad-output/implementation-artifacts/deferred-work.md)).
- **La durée de vie d'un jeton n'est pas configurable par un dérivé.**
  `PasswordResetRequest::LIFETIME` est une constante de classe. Pour la changer, il
  faudrait modifier `src/Core/`, ce que NFR-6 interdit. La limite restera tant que le
  socle n'aura pas une seconde durée à comparer : l'invitation de l'Epic 2, valable
  sept jours.
- **Les langues actives n'ont pas encore d'écran** (story 2.9). D'ici là, on les
  active ou désactive directement en base.
- **Aucun vrai module métier n'a encore suivi la recette « ajouter un module ».** La
  première story d'un module métier (Epic 2) le fera de bout en bout. Si une étape
  manque, corrigez ce guide dans la même story.
