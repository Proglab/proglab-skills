---
name: symfony-proglab-quality
description: >-
  Met en place et exécute la porte de qualité sur un projet Symfony : PHPStan au niveau
  max avec les extensions Symfony et Doctrine, deptrac pour imposer le contrat de couches,
  php-cs-fixer pour le style, et un pipeline CI qui exécute le tout. Fournit des fichiers
  de configuration prêts à committer et un point d'entrée de tâches Make ou Castor, avec
  chaque outil en dépendance require-dev épinglée exécutée via vendor/bin/ — sans daemon
  Docker. Utilise ce skill dès qu'on demande de mettre en place l'analyse statique,
  d'ajouter PHPStan, deptrac ou php-cs-fixer, de configurer la CI ou un workflow GitHub
  Actions, de corriger une erreur PHPStan, d'ajouter une baseline, d'imposer mécaniquement
  des règles d'architecture, d'ajouter un Makefile ou un castor.php, de vérifier les
  dépendances vulnérables, ou de comprendre pourquoi le build échoue sur le style ou les
  types. Utilise-le aussi pour introduire ce standard sur une base de code existante qui
  n'a jamais eu d'analyse statique.
---

# Porte de qualité

> **Niveau : socle pour PHPStan et php-cs-fixer, à la demande pour deptrac** — le contrat de couches tient par lui-même ; deptrac le vérifie mécaniquement et vaut la peine d'être adopté dès que plus d'une personne, ou un agent, écrit dans la base de code. Les tâches fournies l'ignorent quand `deptrac.yaml` est absent.

Analyse statique, style de code, contrôle des couches et pipeline CI.

Tout ce qui suit vise à rendre les règles *vérifiables*. Un standard qui ne vit que dans
un document est un standard que l'on suit jusqu'à ce qu'on soit pressé. PHPStan et
php-cs-fixer en sont le socle : bon marché, silencieux quand tout va bien, et tournent
sur chaque projet. deptrac est la partie à la demande — le contrat de couches de
`symfony-proglab-architecture` tient par lui-même, et l'outil qui le vérifie mécaniquement
mérite sa place dès que plus d'une personne, ou un agent, écrit dans la base de code.
**Adopte-le délibérément :** copie `deptrac.yaml` le jour où tu le fais, et jusque-là
les tâches fournies l'ignorent.

## Les outils vivent en require-dev

```bash
composer require --dev phpstan/phpstan phpstan/phpstan-symfony phpstan/phpstan-doctrine \
  phpstan/phpstan-strict-rules phpstan/phpstan-deprecation-rules phpstan/phpstan-phpunit \
  php-cs-fixer/shim deptrac/deptrac
```

**Une version épinglée de chaque outil, résolue par `composer.lock`, identique sur
chaque machine et en CI.** Rien au-delà de Composer et du PHP du projet lui-même —
pas de daemon, pas de tag d'image à monter dans son propre commit.

**`php-cs-fixer/shim`, pas `friendsofphp/php-cs-fixer` directement.** Le vrai paquet
dépend de `symfony/console`, `symfony/finder` et consorts, et sa contrainte sur ces
paquets peut bloquer l'application le jour d'une montée de version Symfony — un outil
de dev ne devrait jamais être ce qui bloque une montée de version du framework. Le shim
reconditionne php-cs-fixer avec ses dépendances préfixées et isolées, donc il n'ajoute
rien au graphe de dépendances propre de l'application. **PHPStan et deptrac n'ont pas
ce problème** : les deux sont livrés en phars autonomes et préfixés en interne, donc
`phpstan/phpstan` et `deptrac/deptrac` en simple `require-dev` ne créent aucun conflit.

Deux outils pour lesquels ce skill ne fournit pas de configuration, délibérément :
`composer-require-checker` et `composer-unused` sont utiles mais bruyants à maintenir
au vert sur une vraie base de code, et `infection` (mutation testing) est une adoption
délibérée et coûteuse en soi — introduis l'un des trois quand un vrai besoin se
présente, de la même manière que `deptrac.yaml` lui-même est à la demande ci-dessous.

## Mise en place

Copie les fichiers de `assets/` à la racine du projet et commite-les. `assets/README.md`
indique ce que fait chaque fichier et ce qui doit être adapté — lis-le avant de copier,
car trois des fichiers ont besoin de valeurs propres au projet et resteront sans effet
utile si tu les laisses tels quels.

```
phpstan.dist.neon          niveau max, extensions Symfony + Doctrine
deptrac.yaml               le contrat de couches
.php-cs-fixer.dist.php     style
phpunit.dist.xml           le fichier de la recette plus les deux éléments qui lui manquent
castor.php                 point d'entrée des tâches — celui par défaut
alternatives/Makefile      le repli, seulement si Castor ne peut pas être installé
github-workflow-ci.yml  →  .github/workflows/ci.yml
```

Puis :

```bash
castor qa        # ou : make qa
```

**`castor.php` est le point d'entrée ; le Makefile est le repli, et un projet en
commite un, jamais les deux.** Ils exposent les mêmes noms de tâches — `qa`, `test`,
`stan`, `stan-baseline`, `cs`, `cs-check`, `deptrac`, `audit`, `lint`,
`container-cache` — pour qu'un runbook écrit pour l'un reste vrai pour l'autre. Castor
est le choix par défaut car les tâches y sont du PHP réel : arguments typés,
conditions qui restent lisibles au-delà de la dixième cible. Make est la réponse
quand Castor ne peut vraiment pas être installé, puisqu'il ne demande rien d'autre
que `make` lui-même. Commiter les deux laisse un projet avec deux points d'entrée qui
finissent par diverger, et alors plus personne ne sait lequel la CI exécute réellement.

## À quoi sert chaque outil

| Outil | Attrape |
|---|---|
| **deptrac** | Un `QueryBuilder` qui s'est glissé dans un service, un contrôleur qui va chercher l'entity manager, un formulaire lié à une entité |
| **PHPStan** | Mauvais types, branches impossibles, code mort, API dépréciées, ids de service qui n'existent pas |
| **php-cs-fixer** | Tout ce dont personne ne devrait jamais discuter en revue |
| **`composer audit` / `importmap:audit`** | Vulnérabilités connues, en PHP **et** en JavaScript |
| **`lint:container`** | Un service qui ne peut pas être construit — avant que la production ne le découvre. Il attrape bien un `#[Target]` mal orthographié, bruyamment. Ce qui lui échappe : un service atteint seulement via un localisateur paresseux (un contrôleur, une collection `#[AutowireLocator]`), qui compile en `container.error` et échoue à la première instanciation ; un `#[Autowire(service: '…')]` typé sur une interface que le service n'implémente pas, qui passe et lève un `TypeError` à l'exécution ; et `#[WithMonologChannel('typo')]`, qui invente silencieusement le canal (`symfony-proglab-architecture`, `symfony-proglab-observability`) |
| **`doctrine:schema:validate`** | Un mapping qui ne correspond plus à la base de données |

`importmap:audit` est celui qu'on oublie. Avec AssetMapper, il n'y a pas de `npm audit`
dans la boucle, donc rien d'autre ne signale une dépendance JavaScript vulnérable.

### Outils délibérément non utilisés

Trois outils semblent avoir leur place ici et ne l'ont pas.

| Non utilisé | Pourquoi |
|---|---|
| **`local-php-security-checker`** | **Archivé.** Son propre dépôt renvoie vers `composer audit` comme remplacement. Il apparaît encore dans des tutoriels, ce qui est exactement pourquoi il vaut la peine d'être cité ici. |
| **`symfony check:security`** | Fonctionne, et lit la base d'avis FriendsOfPHP plutôt que celle de Packagist. Mais il fait doublon avec `composer audit`, intégré à Composer depuis la 2.4 et qui ne demande aucun outillage supplémentaire. Une seule vérification de vulnérabilités, pas deux. |
| **PHP_CodeSniffer** | Délibéré : php-cs-fixer est l'unique outil de style. Deux formateurs en désaccord se corrigent en boucle l'un l'autre, et l'essentiel de ce que phpcs ajouterait est déjà couvert par PHPStan au niveau max — par un outil qui comprend les types plutôt que la seule syntaxe. |

Si un projet utilise déjà phpcs, laisse-le : migrer une configuration qui fonctionne
ne vaut pas la peine. Ne l'ajoute simplement pas à côté de php-cs-fixer.

## PHPStan au niveau max

Niveau max, avec `phpstan-symfony`, `phpstan-doctrine`, `phpstan-strict-rules` et
`phpstan-deprecation-rules`. C'est cohérent avec le typage strict que ce standard
impose déjà partout : se contenter du niveau 5 tout en écrivant du code entièrement
typé revient à payer le coût des annotations sans en récolter le bénéfice.

**Réchauffe d'abord le conteneur.** L'extension Symfony lit
`var/cache/dev/App_KernelDevDebugContainer.xml` pour résoudre les ids de service et
les noms de route. Sans cela, l'extension n'échoue pas — elle se tait, et des règles
qui auraient dû attraper quelque chose passent silencieusement. La tâche `stan`
réchauffe le cache pour toi ; si tu exécutes PHPStan à la main, fais-le toi-même.

Erreurs courantes et comment les corriger proprement : `references/phpstan.md`.

## deptrac : le contrat de couches, imposé (à la demande)

Le contrat est une règle que l'on peut suivre sans outil. deptrac est là pour le jour
où cela cesse d'être vrai — un deuxième développeur, un agent qui travaille sans
supervision, une base de code assez grande pour qu'un `QueryBuilder` dans un service
passe inaperçu en revue. Jusque-là, ne copie pas `deptrac.yaml` : la tâche `qa`, la
cible du Makefile et le job CI ignorent tous deptrac quand le fichier est absent.
Quand tu l'adoptes, le fichier déclare quelle couche peut dépendre de quelle autre :

- un **contrôleur** peut atteindre HttpFoundation, les services, les DTO, les
  formulaires, les entités (pour l'EntityValueResolver et
  `#[IsGranted(subject:)]`) et les constantes de voter — jamais Doctrine ;
- un **service** peut atteindre les repositories, les DTO, les entités et les
  exceptions, plus `EntityManagerInterface` pour le `flush()` qu'il possède —
  jamais DBAL, jamais `QueryBuilder`, jamais `HttpFoundation` ;
- un **repository** est le seul endroit autorisé à toucher Doctrine ;
- une **entité** peut atteindre les attributs de mapping `#[ORM\…]`, les classes
  `Collection` et `Types` — et rien d'autre de Doctrine, et rien des nôtres à part
  les enums ;
- un **handler de message** est un contrôleur pour les messages : services et
  repositories, puisqu'un message transporte des identifiants et que le handler
  recharge.

Le fichier fourni est vérifié avec `deptrac/deptrac` (4.7.1 au moment de l'écriture) :
propre sur un échantillon de chaque couche ci-dessus, code de sortie 1 dès qu'un
`QueryBuilder` entre dans un service. Ses collecteurs sont des `classNameRegex` avec
des motifs délimités ; le type `className` utilisé par d'anciens exemples n'existe
plus.

Exécute-le avec `--report-uncovered`, et lis le rapport. Une dépendance *uncovered*
(non couverte) est une dépendance vers une classe extérieure à toute couche —
`AbstractController`, `LoggerInterface`, l'essentiel de `vendor/` — donc la liste est
longue par nature. Ce qui compte dedans, c'est une classe sous `src/` : c'est un
répertoire que personne n'a collecté, et il n'est pas vérifié. **`--fail-on-uncovered`
n'est pas utilisé**, délibérément : cela échouerait dès la première classe vendor
qu'un contrôleur étend. Les répertoires que le fichier fourni laisse volontairement
de côté — `src/Command`, `src/EventListener`, `src/Serializer` — sont listés dans son
commentaire d'en-tête.

Adapter les couches à un projet qui regroupe le code par domaine plutôt que par rôle
technique : `references/deptrac.md`.

## Adopter ceci sur une base de code existante

Le niveau max sur une base de code qui n'a jamais vu d'analyse statique produit des
milliers d'erreurs, et l'issue habituelle est que quelqu'un retire l'outil une
semaine plus tard. Gèle plutôt la dette :

```bash
castor stan-baseline    # ou : make stan-baseline
```

Commite `phpstan-baseline.neon`, décommente la ligne `includes`, et tiens une seule
règle : **la baseline peut rétrécir, jamais grandir.** Le nouveau code est écrit au
niveau max. Une pull request qui ajoute des entrées à la baseline ajoute de la dette
délibérément, ce qui mérite une conversation plutôt qu'un commit silencieux.

Même approche pour deptrac : exécute-le, lis le rapport comme une carte de
l'architecture réelle, corrige les violations domaine par domaine, puis ajoute la
tâche à la CI. Il bloque sur les violations dès le premier jour ; il n'y a pas de
baseline à geler, et il ne devrait pas en avoir besoin.

## Quand une vérification échoue

Corrige la cause. Les trois raccourcis tentants, et pourquoi ils sont pires que le
problème :

- **Ajouter à la baseline PHPStan** pour faire disparaître une nouvelle erreur — la
  baseline est pour la dette existante, pas pour le code d'aujourd'hui.
- **`@phpstan-ignore-next-line` sans raison** — si l'ignore est réellement justifié,
  la raison vaut bien une ligne de commentaire ; si elle ne vaut pas la peine d'être
  expliquée, elle n'est pas justifiée.
- **Assouplir une règle deptrac** parce qu'une classe ne rentre pas — cette classe
  est en train de te dire qu'elle est dans la mauvaise couche. Déplace-la.

Le seul cas légitime : l'outil se trompe. Cela arrive, surtout avec les generics et
les retours dynamiques de Doctrine. Ignore-le explicitement, nomme la raison, et
passe à autre chose.

## CI

Le workflow dans `assets/` exécute les jobs en parallèle, pour qu'une erreur de style
ne se cache pas derrière une suite de tests de cinq minutes. Tout ce qui peut échouer
avant la production échoue là : tests, PHPStan, style, couches, linters, audits.

### phpunit.dist.xml fait partie de cette porte

La recette Symfony en génère un, et il lui manque deux choses.
`assets/phpunit.dist.xml` est ce fichier avec les deux ajoutées.

**`failOnPhpunitNotice="true"`.** La recette fournit `failOnDeprecation`,
`failOnNotice` et `failOnWarning`, et `failOnNotice` ne couvre que les notices
*PHP*. L'avertissement de mock sans expectation que PHPUnit 11+ émet est une notice
**PHPUnit**, une catégorie différente. Mesuré sur PHPUnit 13.3.2, avec un test créant
un mock sans expectation :

| Option | Code de sortie |
|---|---|
| aucune | `0` |
| `failOnNotice` | `0` |
| `failOnPhpunitNotice` | `1` |
| `failOnAllIssues` | `1` |

Ajoute `failOnPhpunitNotice="true"`, et `displayDetailsOnPhpunitNotices="true"` avec,
pour que le rapport nomme le test au lieu de seulement le compter. Sans la première
option, le filet de sécurité est décoratif. La règle et son raisonnement vivent dans
`symfony-proglab-testing`.

**L'enregistrement de l'extension DAMA.** L'isolation de la base de données vient de
`dama/doctrine-test-bundle`, et PHPUnit 10+ enregistre son extension dans le fichier
de config :

```xml
<extensions>
    <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
</extensions>
```

C'est la moitié de la mise en place qui vit dans *ce* skill ; l'autre moitié est une
ligne `['test' => true]` dans `config/bundles.php`. **L'une des deux seule est pire
que ni l'une ni l'autre.** L'extension sans le bundle ne trouve aucune connexion
statique à envelopper, donc chaque test valide (commit) — et la suite reste verte
pendant qu'elle remplit la base de données. Rien dans la sortie PHPUnit ne le
signale, c'est pourquoi le workflow CI exécute
`php bin/console --env=test debug:config dama_doctrine_test` avant la suite : il
sort en `1` quand le bundle n'est pas activé dans l'environnement de test, et en `0`
quand il l'est.

**Les dépréciations ne sont pas l'affaire de ce skill.** `phpunit.dist.xml` fournit
`failOnDeprecation` et `ignoreIndirectDeprecations`, donc une dépréciation peut faire
échouer le build ici — mais trier entre ton code, un point d'appel et une dépendance
qui n'est pas prête, et décider ce qu'il faut nettoyer avant une montée de version
majeure, c'est `symfony-proglab-upgrade`. Pareil pour les constats de
`phpstan-deprecation-rules`.

Deux points qui décident si la CI vaut vraiment quelque chose :

- **Le service de base de données doit correspondre à la production.** Le workflow
  est fourni avec MySQL 8. Tester sur SQLite et livrer sur MySQL revient à faire
  apparaître les différences en production plutôt qu'en CI.
- **Les migrations ont aussi leur place en CI** si le projet en a : exécute-les
  contre le schéma précédent. C'est ce qui attrape la migration qui fonctionne en
  local et échoue sur de vraies données.

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `assets/README.md` | Avant de copier quoi que ce soit — indique quoi adapter |
| `references/phpstan.md` | Une erreur PHPStan que tu es sur le point d'ignorer |
| `references/deptrac.md` | Adapter les couches à la structure réelle d'un projet |
