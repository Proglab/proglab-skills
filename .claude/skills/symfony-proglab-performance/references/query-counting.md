# Compter les requêtes SQL dans un test

Tout ici a été exécuté avec **Symfony 8.1.5 / doctrine-bundle 3.3.1 / doctrine/dbal 4.4.4
/ doctrine/orm 3.6.8 / PHPUnit 13.3.2**. `vendor/` fait foi par rapport à ce fichier ; la
dernière section indique exactement quoi grepper pour revérifier.

## Ce qui n'existe plus

La plupart de ce qu'un moteur de recherche retourne pour « symfony count queries test »
est du code mort.

| Trouvé dans des articles de blog | Statut |
|---|---|
| `Doctrine\DBAL\Logging\DebugStack` | **Retiré dans DBAL 4.** Il était déjà déprécié dans DBAL 3.2 quand l'API middleware est arrivée. |
| `$connection->getConfiguration()->setSQLLogger(new DebugStack())` | Disparu avec lui. `SQLLogger` n'existe plus. |
| `$collector->getQueryCount()` | **Vivant et correct.** Elle est sur `Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector`, que le `DoctrineDataCollector` de doctrine-bundle étend. Ne pas la remplacer par `count($collector->getQueries())` — ça retourne un tableau *par connexion*. |

Ce qui a remplacé `DebugStack` : un `Driver\Middleware` DBAL
(`Doctrine\Bundle\DoctrineBundle\Middleware\DebugMiddleware`) enveloppant chaque
instruction et poussant un `Symfony\Bridge\Doctrine\Middleware\Debug\Query` dans un objet
collecteur partagé enregistré comme **`doctrine.debug_data_holder`**
(`Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder`, qui étend
`Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder`).

Les deux méthodes de comptage ci-dessous lisent depuis ce même holder. Elles ne diffèrent
que par le fait de passer ou non par le profiler.

## Méthode 1 — le profiler, pour les tests d'endpoint

C'est la méthode par défaut. Elle mesure ce que la *requête* a fait, ce qui est le sujet
du test.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * Compte les requêtes SQL exécutées par une requête HTTP.
 */
trait CountsQueries
{
    /** @var list<string> */
    private array $lastQueries = [];

    protected function countQueriesFor(KernelBrowser $client, string $method, string $url): int
    {
        // Tout ce que le test lui-même a fait — fixtures, seeding — est encore dans le
        // holder avant la première requête du test. Voir « Les quatre pièges » plus bas.
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder, 'Doctrine profiling is off.');
        $holder->reset();

        $client->enableProfiler();
        $client->request($method, $url);

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'No profile: is framework.profiler enabled under when@test?');

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $this->lastQueries = [];
        foreach ($collector->getQueries() as $queriesForConnection) {
            foreach ($queriesForConnection as $query) {
                $this->lastQueries[] = (string) $query['sql'];
            }
        }

        return $collector->getQueryCount();
    }

    /**
     * Le SQL de la dernière requête mesurée, pour les messages d'assertion. Sans ça, un
     * échec dit « expected 2, got 14 » et l'investigation part de zéro.
     */
    protected function queryLog(): string
    {
        return "\n  - ".implode("\n  - ", $this->lastQueries);
    }
}
```

Utilisé dans un `WebTestCase` :

```php
#[Test]
public function the_shelf_costs_two_queries_whatever_its_size(): void
{
    $this->seed(1);
    $small = $this->countQueriesFor($this->client, 'GET', '/livres');

    $this->seed(9);
    $large = $this->countQueriesFor($this->client, 'GET', '/livres');

    self::assertSame(2, $small, 'shelf with one book:'.$this->queryLog());
    self::assertSame($small, $large, 'the query count grew with the row count:'.$this->queryLog());
}
```

**Pourquoi deux assertions.** La première fixe le coût d'aujourd'hui, donc une régression
est visible dans le diff du test plutôt que masquée. La seconde est la définition réelle
du N+1 : le nombre ne doit pas dépendre du nombre de lignes. Une simple borne supérieure
(`assertLessThan(10, $count)`) ne détecte ni un saut de 2 à 9, ni le cas où le profiling
est entièrement désactivé.

## Méthode 2 — le holder directement, pour les tests de repository et de service

Pas de requête HTTP, pas de profiler, pas besoin de web-profiler-bundle. Utilise ceci pour
tester une méthode de repository de manière isolée, ce qui est de toute façon là où vit
une fetch join.

```php
$holder = self::getContainer()->get('doctrine.debug_data_holder');
self::assertInstanceOf(DebugDataHolder::class, $holder);
$holder->reset();

$rows = $this->repository->findShelfRows();

$count = array_sum(array_map('count', $holder->getData()));
self::assertSame(1, $count);
```

`getData()` retourne `array<connectionName, list<array{sql, params, types, executionMS}>>`,
d'où le `array_sum(array_map('count', …))`. C'est exactement ce que fait `getQueryCount()`
sur le collector.

Préfère la méthode 1 pour tout ce qu'un utilisateur peut atteindre : c'est l'endpoint qui
régresse, et la régression vient généralement du template plutôt que du repository.

## Les quatre pièges

### 1. `APP_DEBUG=0` fait passer un mauvais test au vert

L'option `profiling` de Doctrine vaut par défaut `%kernel.debug%`. Avec `APP_DEBUG=0`, le
`DebugMiddleware` n'est pas enregistré du tout, et — vérifié — les deux méthodes
retournent **0**. Un `assertLessThan(10, $count)` passe alors sur une page qui fait 400
requêtes.

Deux défenses, à utiliser toutes les deux :

- vérifier un nombre exact avec `assertSame()`, jamais seulement une borne supérieure ;
- le `assertInstanceOf(DebugDataHolder::class, …)` dans le trait échoue bruyamment quand
  le service a été optimisé et supprimé.

Si un projet exécute délibérément sa suite de tests avec `APP_DEBUG=0`, fixe le profiling
activé pour l'environnement de test plutôt que d'abandonner la vérification :

```yaml
# config/packages/doctrine.yaml
when@test:
    doctrine:
        dbal:
            profiling: true
```

### 2. La première requête d'un test ne redémarre pas le noyau

`KernelBrowser::doRequest()` saute le cycle shutdown/boot pour la première requête, parce
que `createClient()` a déjà démarré le noyau. Conséquence, vérifiée : les fixtures que ton
`setUp()` vient d'insérer sont **encore dans le data holder** et sont comptées.

Symptôme : la première mesure d'un fichier de test est gonflée (5 au lieu de 1) et toutes
celles qui suivent sont correctes. Le `$holder->reset()` en tête du trait est le
correctif, et il est aussi correct pour les requêtes suivantes, où le redémarrage a déjà
vidé le holder.

### 3. `enableProfiler()` ne couvre qu'exactement une requête

D'après la docblock de la méthode elle-même : *« Enables the profiler for the very next
request. »* Le flag est réinitialisé à l'intérieur de `doRequest()`. Mesurer deux requêtes
implique de l'appeler deux fois — ce que fait le trait, puisqu'il enveloppe une requête.

### 4. `getProfile()` retourne `false`, pas une exception

Elle retourne `false` quand il n'y a pas encore de réponse ou quand le conteneur n'a pas
de service `profiler`. Ça arrive sur un projet qui a retiré
`symfony/web-profiler-bundle`, ou dont `config/packages/web_profiler.yaml` n'active pas le
profiler sous `when@test`. La recette standard fait :

```yaml
when@test:
    framework:
        profiler:
            collect: false      # activé, mais ne collecte que quand enableProfiler() le demande
```

`collect: false` ne veut pas dire « profiler désactivé » — c'est exactement le mode dont
cette technique dépend. Si le bundle est réellement absent, utilise la méthode 2, qui n'en
a pas besoin.

## Choisir le nombre à vérifier

Commence par exécuter le test avec une attente délibérément fausse et lis le SQL depuis
`queryLog()`. Vérifie ensuite ce que tu vois, moins ce que tu peux supprimer.

Un endpoint de liste HTML typique sur ce standard :

| Requête | Légitime ? |
|---|---|
| `SELECT … FROM book …` | Oui — la liste elle-même |
| `SELECT COUNT(*) FROM book` | Oui, si la réponse est paginée avec un `meta.total` |
| `SELECT … FROM review WHERE book_id = ?` × N | **Non** — c'est le N+1 |
| `SELECT … FROM messenger_messages …` | Oui, si l'action envoie un message asynchrone |
| `"START TRANSACTION"` / `"COMMIT"` | Comptées. Chaque `flush()` est une transaction, et le collector journalise les deux extrémités |

Cette dernière ligne compte, et elle a changé avec le mécanisme d'isolation. Sous
`dama/doctrine-test-bundle`, le collector affiche une simple paire `"START TRANSACTION"` /
`"COMMIT"` autour de chaque `flush()` — la propre transaction de DAMA vit au niveau du
driver, sous le middleware de debug, donc ses savepoints n'atteignent jamais le journal.
Mesuré sur doctrine-bundle 3.3.1 / DBAL 4.4.4 : un `flush()` insérant une ligne journalise
exactement trois entrées, `"START TRANSACTION"`, l'`INSERT`, `"COMMIT"`.

Si un test ouvre encore sa propre transaction externe — un test `#[SkipDatabaseRollback]`
faisant son propre nettoyage, par exemple — le même `flush()` journalise
`SAVEPOINT DOCTRINE_2` / `RELEASE SAVEPOINT DOCTRINE_2` à la place, parce que DBAL
l'imbrique. Dans tous les cas : vérifie le nombre que tu observes réellement plutôt que
celui que tu penses élégant.

## Revérifier par rapport à vendor

Quatre commandes, et elles prennent moins de temps qu'une erreur.

```bash
# la méthode du collector est bien présente
grep -n 'function getQueryCount' vendor/symfony/doctrine-bridge/DataCollector/DoctrineDataCollector.php

# le service holder est déclaré par cette version de doctrine-bundle
grep -n 'debug_data_holder' vendor/doctrine/doctrine-bundle/config/middlewares.php

# le profiling est actif dans l'environnement de test
php bin/console debug:container doctrine.debug_data_holder --env=test

# DebugStack a disparu ("No such file or directory" sur DBAL 4 = attendu).
# Nomme le fichier, ne liste pas le répertoire : sur DBAL 4 le répertoire
# existe toujours et affiche Connection/Driver/Middleware/Statement, ce qui
# donne l'impression que la classe a survécu.
ls vendor/doctrine/dbal/src/Logging/DebugStack.php
```

## Versions plus anciennes

La règle ne change jamais ; seule la plomberie change.

- **DBAL 3.2+ / doctrine-bundle 2.6+** — identique à ce qui précède. Le middleware et
  `doctrine.debug_data_holder` sont déjà là.
- **DBAL 3.0–3.1, ou doctrine-bundle < 2.6** — `DebugStack` fonctionne encore :
  `$stack = new DebugStack(); $connection->getConfiguration()->setSQLLogger($stack);`
  puis `count($stack->queries)`. Déprécié, mais c'est ce que ces versions offrent.
- **Toute version** — la voie du profiler (`getCollector('db')->getQueryCount()`) a
  fonctionné sans changement sur toutes, ce qui est une raison supplémentaire de la
  préférer.
