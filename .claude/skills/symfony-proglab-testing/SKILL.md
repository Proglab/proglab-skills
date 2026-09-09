---
name: symfony-proglab-testing
description: >-
  Écrire et exécuter des tests PHPUnit sur un projet Symfony à la manière TDD : le test
  d'abord, l'exécution rouge, puis le code — avec la pyramide de tests (TestCase pour les
  règles métier, KernelTestCase pour les repositories, WebTestCase pour le câblage HTTP),
  l'isolation de base de données via dama/doctrine-test-bundle, des fixtures Doctrine
  avec références nommées et groupes, et des
  test doubles natifs (mailer en mémoire, MockHttpClient, Messenger en mémoire, MockClock)
  plutôt que des mocks faits à la main. Utilise ce skill dès que quelqu'un dit « écris un
  test pour », « ajoute des tests », « teste ce service », « mon test échoue », « mon test
  passe mais le code est cassé », « comment tester ceci », « la suite de tests est rouge »,
  « teste un contrôleur ou un endpoint d'API », « teste une commande console », « teste un
  voter », « teste la validation d'un formulaire ou d'un DTO », « vérifie mes contraintes
  de validation », « charge des fixtures dans les tests », « mes tests polluent la base de
  données », « les tests passent seuls mais échouent ensemble », « mocke le mailer ou le
  client HTTP », « fige le temps dans un test », ou demande un test de non-régression
  avant de corriger un bug. Utilise-le aussi avant d'écrire tout code de production sur un
  projet qui suit ce standard, car le test vient en premier.
---

# Tests

> **Niveau : socle** — la boucle, la pyramide et DAMA s'appliquent à tout projet. La
> vérification par mutation s'applique à tout comportement déclaratif que tu prétends
> avoir testé. Les tests de comptage de requêtes sont du ressort de
> `symfony-proglab-performance` et sont à la demande.

Le test est écrit en premier, exécuté en premier, et vu en échec en premier. Tout le
reste dans ce skill existe pour rendre cela possible sur une vraie application Symfony.

## La boucle

```
écrire le test  →  l'exécuter  →  le voir ROUGE  →  écrire le code  →  le voir VERT
```

```bash
vendor/bin/phpunit --filter it_refuses_a_rating_of_zero
```

**Le rouge est le but, pas la cérémonie.** Un test qui n'a jamais échoué est un test
auquel tu ne peux pas faire confiance, car il y a trois façons pour lui d'être vert pour
une mauvaise raison et aucune n'est visible dans le code :

- l'assertion est inversée, ou compare une valeur avec elle-même ;
- le double est permissif — `createStub()` renvoie ce que l'appelant lui demande, donc un
  collaborateur qui n'est jamais appelé « fonctionne » quand même ;
- le test ne s'exécute jamais du tout : mauvais namespace, nom de fichier ne se
  terminant pas par `Test.php`, méthode sans `#[Test]`, un répertoire hors de
  `<testsuites>`.

Voir le rouge une fois élimine les trois causes à la fois. Rien d'autre n'y parvient.

Un `Class "App\Service\ShelfReader" not found` est un premier rouge légitime — il prouve
que le test s'exécute. Une fois la classe créée, le rouge doit venir d'une assertion, pas
d'une erreur fatale. Si tu écris la classe en premier et que le test est vert
immédiatement, tu n'as rien appris ; supprime l'implémentation, observe l'échec, remets-la
en place.

**Exécute le test qui échoue seul, pas toute la suite.** `--filter` sur le nom de la
méthode te donne le message en une seconde et garde le feedback honnête.

## Quand le rouge d'abord est impossible : la vérification par mutation

Certaines choses ne peuvent pas échouer avant d'exister : un attribut de validation, un
`ORDER BY` dans un repository, une route, `cascade: ['remove']`, un groupe de
sérialisation. Le test a besoin que le code soit là avant de pouvoir affirmer quoi que ce
soit.

La réponse n'est pas de sauter la preuve, c'est de la déplacer :

1. écrire le test, le voir passer ;
2. **casser le code exprès** — inverser `ASC` en `DESC`, changer `min: 1` en `min: 0`,
   supprimer la contrainte ;
3. confirmer que le test passe au rouge ;
4. restaurer, confirmer le vert.

Si l'étape 3 reste verte, le test ne mesure rien et il vaut mieux le supprimer que le
garder — un test qui ne peut pas échouer est un commentaire qui coûte du temps de CI.

Fais cela pour chaque comportement déclaratif que tu prétends avoir testé. Cela prend
quinze secondes et c'est la seule chose qui distingue « on a un test pour ça » d'en avoir
réellement un.

## Ce que chaque unité doit couvrir

Il n'y a pas de cible en pourcentage ici. Un chiffre de couverture est satisfait en
testant des getters, et les getters n'ont jamais été l'endroit où se trouvent les bugs.
La règle est comportementale : pour chaque unité de comportement, pose ces cinq questions
et écris un test pour chaque réponse qui existe.

| Question | À quoi ça ressemble |
|---|---|
| **Nominal** | Le cas pour lequel la fonctionnalité a été construite. Un test, le chemin heureux. |
| **Limites** | La plus basse et la plus haute valeur acceptée, et la première rejetée de chaque côté. Note 1, 5, 0, 6 — pas 3. |
| **Erreurs** | L'exception précise, avec `expectException(BookNotFound::class)`. Affirmer `\Exception` passe sur un `TypeError` et masque un vrai bug. |
| **Sécurité** | Qui n'a pas le droit de faire ceci. Une règle qui n'est testée que du côté autorisé n'a jamais été testée. |
| **Effets de bord** | Ce qui s'est passé d'autre : le flush, l'email, le message envoyé — et, quand l'opération peut être rejouée, que l'exécuter deux fois n'équivaut pas à l'exécuter deux fois. |

Les limites et les erreurs sont l'endroit où `#[DataProvider]` prouve sa valeur : une
méthode de test, un provider produisant des cas nommés. Le provider doit être `static`
(PHPUnit 10+).

## La pyramide, et la règle qui la garde honnête

| Niveau | Classe de base | Pour | Coût |
|---|---|---|---|
| **Unitaire** | `PHPUnit\Framework\TestCase` | Règles métier, validation de DTO, voters, normalisation de sortie | Pas de kernel, millisecondes |
| **Intégration** | `KernelTestCase` | Repositories, DQL, mapping, tout ce qui nécessite une vraie base de données | Kernel + base de données |
| **Fonctionnel** | `WebTestCase` | Routing, formulaires, codes de statut, redirections, contrats HTML et JSON | Kernel par requête |

**Une règle métier testée via `WebTestCase` est une règle au mauvais endroit.** Si la
seule façon de vérifier qu'une note doit être comprise entre 1 et 5 est de POSTer un
formulaire, la règle vit dans le contrôleur et aurait dû être un service
(`symfony-proglab-architecture`) ou une contrainte de DTO. Le test lent est le symptôme ;
déplacer la règle est la solution. Le test fonctionnel qui reste affirme alors ce pour
quoi il sert réellement : la route existe, le payload est mappé, l'échec est un 422 et
non un 500.

La règle miroir : **si tu te surprends à mocker ton propre service dans un test
fonctionnel, arrête-toi.** Tu es en train de faire un test unitaire à travers six couches
HTTP. Écris le test unitaire.

## Isolation de base de données : dama/doctrine-test-bundle

`KernelTestCase` et `WebTestCase` parlent à une vraie base de données, et un test qui
laisse des lignes derrière lui est un test qui échouera selon ce qui s'est exécuté avant
lui. Chaque test s'exécute dans une transaction qui est annulée (rollback) à sa fin, et
c'est le bundle qui l'ouvre.

```bash
composer require --dev dama/doctrine-test-bundle
```

Sa recipe vit dans `recipes-contrib`, donc Composer affiche `IGNORING` plutôt que de
l'appliquer. Deux lignes sont à écrire toi-même. Dans `config/bundles.php` :

```php
DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true],
```

et dans `phpunit.dist.xml` :

```xml
<extensions>
    <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
</extensions>
```

C'est toute la configuration. Pas de `setUp()`, pas de `tearDown()`, pas de classe de
base à étendre : chaque méthode de test obtient une transaction et la voit annulée
(rollback), y compris tout ce que ses requêtes HTTP ont écrit, sur autant de requêtes
qu'elle en fait.

**Écris les deux lignes ou aucune.** L'extension enregistrée sans le bundle est la pire
configuration que ce standard connaisse : PHPUnit la démarre, elle ne trouve aucune
connexion statique à envelopper, chaque test commit — et la suite reste verte pendant
qu'elle remplit la base de données. Mesuré, sur cette configuration exacte.
`symfony-proglab-quality` fournit un `phpunit.dist.xml` avec le bloc déjà présent, et le
workflow de CI protège cette paire.

### Pourquoi pas un `beginTransaction()` manuel dans `setUp()`

C'est ce que ce standard disait autrefois, et c'était faux pour une raison qui ne se
révèle que dans les tests que tu veux le plus isoler. **Une transaction ouverte dans
`setUp()` meurt à la deuxième requête d'un `WebTestCase`.** `KernelBrowser::doRequest()`
saute le redémarrage du kernel uniquement pour la première requête ; à la deuxième, le
kernel est arrêté puis redémarré, la connexion est recréée, et la transaction n'existe
plus. `tearDown()` lève alors `Doctrine\DBAL\Exception\NoActiveTransaction`, et les
lignes écrites à partir de la deuxième requête sont **commitées et laissées en place** —
tandis que la ligne écrite par la première requête disparaît avec sa connexion.
L'isolation disparaît exactement là où tu croyais l'avoir, et l'état dans lequel tu te
retrouves n'est même pas l'état d'avant le test.

DAMA n'a pas ce problème car il ne vit pas dans le kernel. Il s'accroche au niveau du
driver via une extension PHPUnit : la connexion est statique, elle survit à chaque
redémarrage, et la transaction est ouverte et annulée par le système d'événements de
PHPUnit lui-même plutôt que par ta classe de test.

Transactions imbriquées, tests qui ont réellement besoin d'un commit, et
`dama_doctrine_test.enable_static_connection` : `references/database-tests.md`.

## Test doubles : préférer ceux que Symfony fournit

Avant de recourir à `createMock()`, vérifie si le framework a déjà une implémentation
réelle qui enregistre au lieu d'exécuter : le mailer en mémoire avec
`assertEmailCount()` / `assertQueuedEmailCount()`, `MockHttpClient`, le transport
Messenger `in-memory://`, `MockClock`. Ils exercent ton câblage réel — serializer,
transport, template — là où un mock prouve seulement que tu as appelé une méthode.

Pour tes propres collaborateurs :

- **`createStub()`** quand le double n'est que du décor — il fournit une valeur et tu
  affirmes sur autre chose.
- **`createMock()`** seulement quand tu le fais suivre d'un `expects()` — l'appel *est*
  l'assertion.

Un mock créé sans aucune expectation émet un avis (notice) PHPUnit à partir de PHPUnit
11, te disant d'utiliser un stub à la place. C'est un avis *PHPUnit*, pas un avis PHP —
détails et flag de configuration exact dans `references/test-doubles.md`.

**`$container->set()` est réservé aux frontières externes** : une passerelle de
paiement, un fournisseur SMS, une API tierce que tu ne veux pas appeler. Jamais pour tes
propres services. Si un test fonctionnel a besoin que ton propre service soit remplacé,
la règle testée est dans la mauvaise couche et mérite plutôt un test unitaire.

## Pièges

| Symptôme | Cause | Correction |
|---|---|---|
| Test vert dès le premier lancement, avant tout code | Il ne s'est jamais exécuté, ou l'assertion est trivialement vraie | Casse le code et confirme le rouge (vérification par mutation) |
| `rollBack()` lève `NoActiveTransaction` dans un `WebTestCase` | Une transaction faite à la main dans `setUp()`, tuée par le redémarrage du kernel à la requête 2 | Supprime-la ; DAMA possède la transaction. Ne superpose pas les deux |
| Des lignes survivent à un test malgré DAMA installé | L'extension PHPUnit est enregistrée mais le bundle n'est pas dans `config/bundles.php` | Ajoute l'entrée `['test' => true]` ; `php bin/console --env=test debug:config dama_doctrine_test` doit sortir avec le code 0 |
| Les tests passent seuls, échouent ensemble | État partagé : lignes laissées derrière, un `static`, des fixtures chargées une fois et mutées | Vérifie que DAMA est réellement actif ; récupère les données de fixture par référence nommée, jamais `findAll()[0]` |
| `This bundle only works for database platforms that support savepoints` | DAMA sur une plateforme sans savepoints | Change de moteur, ou désactive DAMA sur cette connexion avec `enable_static_connection: {name: false}` |
| Les tests écrivent dans la base **dev** | Sur SQLite, `dbname_suffix: '_test'` n'a aucun effet — DoctrineBundle le documente | Mets un `DATABASE_URL` explicite dans `.env.test`, ou utilise `%kernel.environment%` dans le DSN |
| `The "App\Service\X" service is already initialized, you cannot replace it` | `$container->set()` appelé après qu'une requête a instancié le service | Remplace-le avant la première requête — et reconsidère, puisque c'est l'un de tes propres services |
| Suite verte mais pleine de « PHPUnit Notices » | Des mocks sans expectations | Utilise `createStub()` ; ajoute `failOnPhpunitNotice="true"` pour qu'on ne puisse plus jamais les ignorer |
| `ConstraintDefinitionException` sur `Assert\Range` | `minMessage`/`maxMessage` sont rejetés quand `min` **et** `max` sont tous les deux définis | Utilise `notInRangeMessage` |
| L'assertion JSON échoue sur `4` vs `4.0` | `json_encode(['rating' => 4.0])` produit `{"rating":4}` | Affirme sur la valeur décodée, ou type la propriété du DTO en `int` si c'en est un |
| `#[DataProvider]` introuvable / provider ignoré | La méthode du provider n'est pas `static`, ou le nom est mal orthographié | Rends-la `public static`, retournant un `\Generator` avec des clés nommées |
| Erreur de métadonnées de couverture | `#[CoversClass]` pointe vers une classe qui n'existe pas ou n'est pas couverte | Corrige la référence ; `#[CoversClass]` sur la classe testée, `#[UsesClass]` pour les collaborateurs |
| Les emails n'arrivent jamais dans un test fonctionnel | Ce standard route `SendEmailMessage` vers le transport asynchrone | Affirme avec `assertQueuedEmailCount()`, pas `assertEmailCount()` |

## Notes de version

`vendor/` fait foi devant tout ce qui est écrit ici. Là où une version compte :

- `static::runCommand()` nécessite Symfony 8.1+ ; avant cela, `CommandTester` à la main.
- `enableAttributeMapping()` sur le builder du validator est en 7.0+ ; c'était
  `enableAnnotationMapping()` avant.
- DBAL 4 déclare `beginTransaction(): void` — l'ancien retour `bool` a disparu, et les
  transactions imbriquées utilisent toujours des savepoints. C'est ce qui permet à la
  transaction externe de DAMA de contenir tout ce qu'un `flush()` ouvre à l'intérieur.
- `dama/doctrine-test-bundle` 8.x est la lignée PHPUnit 10+ : l'extension est enregistrée
  comme `<extensions><bootstrap class="…"/></extensions>`, pas avec l'ancien
  `<extension class="…"/>` d'avant la 10. Vérifié sur la 8.6.0 avec PHPUnit 13.3.2.
- `doctrine/data-fixtures` 2.0 a fait en sorte que `getReference()` prenne la classe
  comme second argument : `getReference('book_dune', Book::class)`.

## Hors périmètre

- **Les assertions de comptage de requêtes pour le N+1** — c'est `symfony-proglab-performance`.
  `DebugStack` a disparu de DBAL 4, mais `DoctrineDataCollector::getQueryCount()` est
  vivant et c'est la voie que ce skill utilise ; n'invente pas de remplacement ici.
- **PHPStan, deptrac, php-cs-fixer, le workflow de CI, et le `phpunit.dist.xml` qui
  porte l'extension DAMA et `failOnPhpunitNotice`** — `symfony-proglab-quality`.
- **Faire fonctionner la base de données et le mailer en local** — `symfony-proglab-local-dev`.

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/database-tests.md` | Tout test qui touche la base de données : configuration de DAMA et ses modes d'échec, ce que coûte encore le redémarrage du kernel, fixtures, groupes, références nommées |
| `references/test-doubles.md` | Choisir un double, l'avis PHPUnit, `$container->set()`, et les fakes natifs pour le mailer, HTTP, Messenger et le temps |
| `references/functional-tests.md` | `WebTestCase`, formulaires et CSRF, assertions JSON et RFC 7807, commandes console, voters, validation de DTO |
