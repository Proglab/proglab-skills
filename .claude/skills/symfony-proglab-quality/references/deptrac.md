# deptrac : imposer le contrat de couches

`deptrac.yaml` dans `assets/` est écrit pour un projet organisé par rôle technique
(`src/Controller`, `src/Service`, `src/Repository`). La plupart des projets ne sont pas
exactement dans ce cas. Ce fichier explique comment l'adapter sans l'affaiblir.

## Sommaire

- [Ce qui est réellement vérifié](#ce-qui-est-réellement-vérifié)
- [Projets regroupés par domaine](#projets-regroupés-par-domaine)
- [Ce que signifie « uncovered », et pourquoi cela ne fait pas échouer le build](#ce-que-signifie--uncovered--et-pourquoi-cela-ne-fait-pas-échouer-le-build)
- [Lire une violation](#lire-une-violation)
- [Introduire deptrac sur une base de code existante](#introduire-deptrac-sur-une-base-de-code-existante)
- [Les règles que tu seras tenté d'assouplir](#les-règles-que-tu-seras-tenté-dassouplir)

## Ce qui est réellement vérifié

Trois affirmations, et rien d'autre :

- un contrôleur traduit HTTP en appel de service, donc il n'a aucune raison de
  toucher Doctrine ;
- un service porte la règle, donc il ne doit pas construire de requêtes et ne doit
  pas savoir que HTTP existe ;
- un repository possède les requêtes, donc c'est la seule couche autorisée à voir
  Doctrine.

Chaque autre ligne de la configuration existe pour exprimer ces trois affirmations.
Quand tu adaptes le fichier, adapte les collecteurs — pas le ruleset.

Deux ouvertures découlent directement de la première affirmation et sont faciles à
perdre en éditant : un contrôleur **peut** voir `HttpFoundation` (il retourne une
`Response`), et il peut voir les entités et les constantes de voter, parce que
l'EntityValueResolver et `#[IsGranted(subject:)]` lui donnent les deux. La couche
`Http` existe pour qu'un *service*, lui, ne le puisse pas — retire-la du ruleset de
`Controller` et chaque action retournant une `Response` devient une violation.

**Deux dérogations délibérées**, car le standard se contredirait sinon. Le service
possède la frontière transactionnelle et appelle `flush()` une fois par cas
d'utilisation (`symfony-proglab-architecture`), donc il doit pouvoir typer
`EntityManagerInterface` : le `deptrac.yaml` fourni sort ces deux interfaces
(`Doctrine\ORM\EntityManagerInterface`, `Doctrine\Persistence\ObjectManager`) de la
couche `Doctrine` avec un lookahead négatif et leur donne leur propre couche,
`EntityManager`. Et une entité est faite d'attributs `#[ORM\…]`, de classes
`Collection` et de constantes `Types`, ce qui leur vaut une couche `DoctrineMapping`
que `Entity` peut voir — tandis que le reste de Doctrine lui reste fermé. Garde les
deux aussi étroites : ajouter `Doctrine` au ruleset de `Service` ou `Entity`
laisserait revenir `QueryBuilder`, ce qui est exactement ce que ce fichier existe
pour empêcher.

Les collecteurs sont des `classNameRegex`, et la valeur est une regex complète
**avec délimiteurs** (`'/^Doctrine\\…/'`). L'ancien type `className` n'existe plus
dans les versions actuelles de `deptrac/deptrac`, et un motif sans délimiteurs est
rejeté comme invalide. Vérifié sur deptrac 4.7.1 contre un projet d'échantillon : le
fichier fourni passe proprement sur un contrôleur, un service, un repository, une
entité mappée avec une collection, un formulaire, un handler, un voter et un enum, et
signale `must not depend on Doctrine\ORM\QueryBuilder (Service on Doctrine)` avec le
code de sortie 1 dès qu'un service en acquiert un.

## Projets regroupés par domaine

Un projet organisé en `src/Billing/…`, `src/Catalog/…` a besoin de collecteurs qui
correspondent au *rôle* à l'intérieur de chaque domaine plutôt qu'à un seul
répertoire de premier niveau :

```yaml
deptrac:
    paths:
        - ./src

    layers:
        - name: Controller
          collectors:
              - type: directory
                value: src/.*/Controller/.*

        - name: Service
          collectors:
              - type: directory
                value: src/.*/Service/.*

        - name: Repository
          collectors:
              - type: directory
                value: src/.*/Repository/.*
```

Si le projet nomme les choses différemment — `UseCase` au lieu de `Service`, `Query`
au lieu de `Repository` — renomme les couches en conséquence. Le vocabulaire du
skill n'est pas le sujet ; les frontières le sont.

### Faire respecter aussi les limites entre domaines

Une fois que les couches techniques tiennent, le même outil peut empêcher les
domaines d'aller fouiller dans les entrailles les uns des autres, ce qui est
généralement la vérification la plus utile sur une grande base de code :

```yaml
        - name: Billing
          collectors:
              - type: directory
                value: src/Billing/.*

        - name: Catalog
          collectors:
              - type: directory
                value: src/Catalog/.*

    ruleset:
        Billing: []      # Billing ne peut pas du tout atteindre Catalog
        Catalog: []
```

Puis ouvre exactement les portes voulues, généralement une interface publique ou un
message :

```yaml
        - name: CatalogApi
          collectors:
              - type: directory
                value: src/Catalog/Api/.*

    ruleset:
        Billing:
            - CatalogApi   # et rien d'autre de Catalog
```

## Ce que signifie « uncovered », et pourquoi cela ne fait pas échouer le build

```bash
deptrac analyse --config-file=deptrac.yaml --report-uncovered
```

deptrac ne juge que les dépendances entre deux couches. Une dépendance d'une couche
vers une classe qui n'appartient à **aucune** couche est *uncovered* (non couverte) :
elle n'est ni autorisée ni interdite, ce n'est simplement pas une question à laquelle
le ruleset répond. `AbstractController`, `LoggerInterface`, `ValidatorInterface`,
chaque attribut PHPUnit — l'essentiel de `vendor/` est non couvert, par conception.

C'est pourquoi les tâches fournies ne passent **pas** `--fail-on-uncovered`. Cette
option échoue sur *toute* dépendance non couverte, vendor inclus, donc le premier
contrôleur qui étend `AbstractController` ferait passer la porte au rouge. Un outil
qui échoue sur un projet vide est un outil qu'on finit par supprimer.

`--report-uncovered` est la moitié utile. Elle imprime la liste, et ce qu'il faut y
chercher est une classe sous `src/` : c'est un répertoire que personne n'a collecté,
donc rien à l'intérieur n'est vérifié. Traite-le comme une question — quelle est
vraiment cette couche ? — et ajoute-la soit à un collecteur existant, soit nomme la
couche qu'elle est. Le fichier fourni laisse volontairement non collectés
`src/Command`, `src/Doctrine`, `src/EventListener`, `src/Serializer`, `src/Health` et
`src/Scheduler` ; ils sont listés dans son commentaire d'en-tête pour que les entrées
`src/` du rapport restent reconnaissables.

## Lire une violation

```
App\Service\ReviewPublisher must not depend on Doctrine\ORM\QueryBuilder (Service on Doctrine)
```

C'est la règle centrale du standard qui attrape exactement ce pour quoi elle a été
écrite. La correction n'est jamais d'ajouter `Doctrine` au ruleset de `Service`.
C'est de déplacer la requête vers une méthode de repository nommée d'après
l'intention :

```php
// avant, dans le service
$reviews = $this->em->createQueryBuilder()->select('r')/* … */->getResult();

// après
$reviews = $this->reviews->findLatestPublishedForBook($bookId);
```

Le service devient plus court, la requête devient réutilisable, et le service
devient testable sans base de données. L'outil n'a pas créé de travail — il a trouvé
du travail déjà dû.

## Introduire deptrac sur une base de code existante

Une base de code écrite sans ces frontières signalera beaucoup de choses. Ne la
rends pas bloquante dès le premier jour ; c'est ainsi que les outils finissent
supprimés.

1. Exécute-le. Lis les violations comme une carte de l'architecture réelle, qui
   n'est souvent pas celle que tout le monde décrit, et les entrées `src/` non
   couvertes comme les répertoires que la carte a oubliés.
2. Corrige une couche à la fois, en commençant par les contrôleurs — généralement
   les victoires les plus rapides et les plus précieuses, puisqu'un contrôleur qui
   touche Doctrine est aussi le code le plus difficile à tester.
3. Ajoute la tâche à la CI une fois les violations disparues, dans le même commit
   que la dernière correction. Une vérification qui n'est pas en CI est une
   vérification qui cesse de s'exécuter.

deptrac a aussi une baseline, mais préfère corriger plutôt que geler ici.
Contrairement à une erreur PHPStan, une violation de couche est rarement une
correction d'une ligne — c'est une classe au mauvais endroit, et la laisser là
signifie que la prochaine classe la copiera.

## Les règles que tu seras tenté d'assouplir

| Tentation | Ce que cela signifie réellement |
|---|---|
| Laisser `Service` dépendre de `Doctrine` « juste pour cet appel de repository » | La requête appartient à une méthode de repository. La couche `EntityManager` couvre déjà le seul cas légitime, `flush()` |
| Laisser `Controller` dépendre de `Repository` « ce n'est qu'un find() » | Passe par le service, qui prend l'id et possède le 404. L'EntityValueResolver est pour le seul cas où un voter a besoin du sujet en premier (`symfony-proglab-architecture`) |
| Laisser `Entity` dépendre de `Service` | Une entité qui appelle un service n'est plus un modèle, c'est un orchestrateur |
| Ajouter une couche `Shared` dont tout peut dépendre | Légitime pour des objets-valeur véritablement partagés ; un fourre-tout dès qu'elle contient quoi que ce soit avec du comportement |

Le dernier est le plus dangereux, car il paraît raisonnable. Garde `Shared` pour des
choses sans dépendances propres — et vérifie cette affirmation avec deptrac
lui-même, en lui donnant un ruleset vide.
