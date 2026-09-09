# Messenger en détail

Transports, retries, workers, et les commandes que tu vas réellement taper.

## Sommaire

- [La configuration complète](#la-configuration-complète)
- [Créer la table](#créer-la-table)
- [Échecs récupérables ou permanents](#échecs-récupérables-ou-permanents)
- [Différer un message](#différer-un-message)
- [Faire tourner des workers en production](#faire-tourner-des-workers-en-production)
- [Les commandes](#les-commandes)
- [Tester le dispatch et le traitement](#tester-le-dispatch-et-le-traitement)
- [Quand quitter Doctrine](#quand-quitter-doctrine)

## La configuration complète

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        failure_transport: failed

        transports:
            async_high:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options: { queue_name: high }
                retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2, jitter: 0.3 }
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options: { queue_name: default }
                retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2, jitter: 0.3 }
            failed: 'doctrine://default?queue_name=failed'

        routing:
            Symfony\Component\Mailer\Messenger\SendEmailMessage: async_high

when@test:
    framework:
        messenger:
            transports:
                async_high: 'in-memory://'
                async: 'in-memory://'
```

Notes qui comptent :

- **L'email va vers le transport haute priorité.** Une confirmation d'inscription en
  concurrence avec un export nocturne est exactement le problème que les files priorisées
  existent pour résoudre.
- **Les messages applicatifs utilisent `#[AsMessage('async')]`** plutôt que le bloc
  `routing:`. Les messages tiers (`SendEmailMessage`) doivent être routés ici, parce
  qu'on ne peut pas poser un attribut sur la classe de quelqu'un d'autre. Sur Symfony
  6.4–7.1, tout passe par `routing:`.
- **`when@test` surcharge le DSN, pas les noms de transport.** Garder les noms
  identiques signifie que le routage sous test est le routage en production.
- Symfony 8.1 déprécie la clé imbriquée `senders:` sous `routing:`. Écris une liste
  plate.

## Créer la table

Avec `auto_setup=0`, le transport n'émet jamais de DDL. Deux façons d'obtenir la table,
et une seule d'entre elles a sa place dans un repository :

```bash
php bin/console make:migration          # la table est dans le schéma généré — commite ça
php bin/console messenger:setup-transports   # impératif ; correct en local, pas une étape de déploiement
```

Le `MessengerTransportDoctrineSchemaListener` de Doctrine ajoute `messenger_messages` au
schéma que Doctrine génère, donc le diff la récupère comme n'importe quelle table
d'entité. Relis la migration comme n'importe quelle autre (voir `symfony-proglab-doctrine`).

## Échecs récupérables ou permanents

La stratégie de retry ne peut pas distinguer « l'API de paiement a renvoyé 503 » de
« cette étagère a été supprimée ». C'est au handler de le faire, via l'exception qu'il
laisse s'échapper.

| Situation | Exception levée | Résultat |
|---|---|---|
| La ligne référencée n'existe plus | `UnrecoverableMessageHandlingException` | Direct vers `failed`, aucun retry |
| Payload invalide, une forme de message qu'on ne supporte plus | `UnrecoverableMessageHandlingException` | Idem |
| Timeout HTTP, deadlock, connexion réinitialisée | n'importe quelle autre exception | 3 retries avec backoff |
| Une limite de débit avec une heure de reset connue | `RecoverableMessageHandlingException` | Retenté, éventuellement après `$retryDelay` ms |

`RecoverableMessageHandlingException` cache un piège. Son constructeur est
`(string $message, int $code, ?\Throwable $previous, ?int $retryDelay, bool $forceRetry = true)`
et **`forceRetry` vaut `true` par défaut**, ce qui retente indépendamment de
`max_retries` — une boucle infinie contre un endpoint qui renvoie 429 en permanence.
Passe `forceRetry: false` sauf décision contraire explicite. Le paramètre est arrivé avec
Symfony 8.1 (avec `RecoverableExceptionInterface::forceRetry()`) ; avant ça, le retry
forcé était inconditionnel, donc en 6.4–8.0 utilise une exception simple et laisse la
stratégie configurée borner les tentatives.

## Différer un message

```php
$this->bus->dispatch(new SendReadingReminder($readingId), [new DelayStamp(3_600_000)]);
```

En millisecondes. Le transport Doctrine stocke `available_at` et le worker ignore la
ligne jusque-là, donc le délai ne coûte rien pendant qu'il attend.

Ce n'est pas un scheduler. `DelayStamp`, c'est « fais ça une fois, plus tard » ; une
tâche récurrente, c'est `references/scheduler.md`.

## Faire tourner des workers en production

Un worker est un processus PHP longue durée, ce pour quoi PHP n'a pas été conçu. Trois
conséquences en découlent, et sauter l'une d'elles produit une panne bien précise :

```bash
php bin/console messenger:consume async_high async \
    --time-limit=3600 \
    --memory-limit=128M \
    --limit=1000 \
    -v
```

- **`--memory-limit`** — Messenger réinitialise les services entre les messages, ce qui
  gère la identity map de Doctrine, mais pas les allocations au niveau des extensions,
  les caches statiques de bibliothèques, ni la fragmentation du tas. Sans limite, le
  processus est tué par l'OOM killer en plein milieu d'un message, à un moment
  imprévisible ; avec une limite, il se termine proprement *entre* les messages et le
  superviseur le redémarre. Fixe-la en dessous de la limite du conteneur.
- **`--time-limit`** — la même assurance contre les fuites lentes et les connexions
  périmées.
- **Un superviseur.** Les deux limites ne fonctionnent que parce que quelque chose
  redémarre le processus. Un worker avec `--memory-limit` et sans superviseur est un
  worker qui s'arrête au bout d'une heure.

`supervisor` :

```ini
[program:messenger-async]
command=php /srv/app/bin/console messenger:consume async_high async --time-limit=3600 --memory-limit=128M
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
startsecs=0
stopwaitsecs=20
```

`systemd` :

```ini
[Service]
ExecStart=/usr/bin/php /srv/app/bin/console messenger:consume async_high async --time-limit=3600 --memory-limit=128M
Restart=always
RestartSec=1
TimeoutStopSec=20
```

`stopwaitsecs` / `TimeoutStopSec` est le budget de redémarrage propre : Messenger
s'arrête sur `SIGTERM` **après avoir terminé le message en cours**. Trop court, et le
superviseur envoie un `SIGKILL` à un handler en plein milieu — le message est redistribué
après `redeliver_timeout` (3600s par défaut), et c'est l'idempotence qui te sauve.

Un `numprocs` supérieur à 1 est sûr avec Doctrine grâce à `SKIP LOCKED`.

**Au déploiement, `messenger:stop-workers` n'est pas optionnel.** Ça positionne un flag
en cache ; chaque worker termine son message en cours puis s'arrête, et le superviseur en
démarre un sur le nouveau code. Si tu le sautes, les workers continuent de tourner sur la
release précédente — désérialisant de nouveaux messages avec de vieux handlers, ce qui
est l'origine des bugs « ça marche en local ». L'ordonnancement complet est dans
`symfony-proglab-deployment`.

## Les commandes

| Commande | Usage |
|---|---|
| `messenger:consume <t1> <t2>` | Lance un worker ; les receivers sont vidés dans l'ordre donné |
| `messenger:stats` | Nombre de messages en attente par transport — la première chose à vérifier |
| `messenger:failed:show` | Liste le transport d'échec ; `messenger:failed:show <id>` en affiche un avec son exception, `--stats` compte par classe |
| `messenger:failed:retry` | Remet en file après avoir corrigé la cause ; interactif par défaut |
| `messenger:failed:remove` | Supprime un message qui ne réussira jamais (`--class-filter` depuis 7.3) |
| `debug:messenger` | Quel handler traite quel message, par bus |
| `messenger:setup-transports` | Crée l'infrastructure de transport à la main |

## Tester le dispatch et le traitement

**Le handler, sans aucun framework.** C'est une couche de traduction, donc le test est un
appel au constructeur et une assertion que le service a été invoqué.

```php
#[CoversClass(ImportShelfHandler::class)]
final class ImportShelfHandlerTest extends TestCase
{
    #[Test]
    public function itImportsTheShelfItWasGivenTheIdOf(): void
    {
        $shelf = new Shelf();
        $shelves = $this->createStub(ShelfRepository::class);
        $shelves->method('find')->willReturn($shelf);

        $importer = $this->createMock(ShelfImporter::class);
        $importer->expects(self::once())->method('import')->with($shelf, '/tmp/shelf.csv');

        (new ImportShelfHandler($shelves, $importer))(new ImportShelf(12, '/tmp/shelf.csv'));
    }
}
```

`createStub()` pour le repository (pas d'attente), `createMock()` seulement là où il y a
un `expects()` — depuis PHPUnit 11, un mock sans attente émet un avis *PHPUnit*, et ce
standard fait tourner `failOnPhpunitNotice="true"` (le `failOnNotice` de la recipe ne
couvre que les notices PHP et ne l'attrape pas — voir `symfony-proglab-testing`).

**Le service qui dispatche.**

```php
$bus = $this->createMock(MessageBusInterface::class);
$bus->expects(self::once())
    ->method('dispatch')
    ->with(self::callback(static fn (ImportShelf $m): bool => 12 === $m->shelfId))
    ->willReturn(new Envelope(new ImportShelf(12, '/tmp/shelf.csv')));
```

Sans `willReturn()`, le mock renvoie `null` face à un type de retour `: Envelope` et le
test meurt avec un `TypeError` qui pointe vers le code de Symfony.

**Fonctionnellement, à travers le kernel**, en utilisant le transport in-memory déclaré
sous `when@test` :

```php
$client->request('POST', '/shelves/12/import', [], ['file' => $upload]);

$transport = self::getContainer()->get('messenger.transport.async');
\assert($transport instanceof InMemoryTransport);

self::assertCount(1, $transport->getSent());
self::assertInstanceOf(ImportShelf::class, $transport->getSent()[0]->getMessage());
```

`getSent()` renvoie des `Envelope[]`, pas des messages. `getAcknowledged()` et
`getRejected()` existent aussi, et `reset()` vide les trois.

N'écris pas de test qui démarre un vrai worker. Si tu veux prouver que le handler
fonctionne contre une vraie base de données, appelle le handler toi-même à l'intérieur
d'un `KernelTestCase` — même couverture, aucun processus, aucun timing à gérer.

## Quand quitter Doctrine

Les signaux, dans l'ordre où ils apparaissent : `messenger:stats` affichant régulièrement
un backlog alors que les workers sont en bonne santé ; la table de la file d'attente qui
grossit plus vite que l'autovacuum ne la récupère ; des workers qui pollent assez souvent
pour apparaître dans les logs de requêtes lentes ; un besoin de fan-out vers plusieurs
consommateurs.

Redis (`symfony/redis-messenger`,
`MESSENGER_TRANSPORT_DSN=redis://localhost:6379/messages`) retire la charge de la base de
données métier et c'est le plus petit pas. AMQP (`symfony/amqp-messenger`) ajoute des
exchanges, des routing keys et le dead-lettering côté broker, et ajoute un broker à
opérer.

Dans les deux cas, la migration se fait ainsi : déployer le nouveau transport en
parallèle, y router les nouveaux messages, garder un worker sur l'ancien jusqu'à ce que
`messenger:stats` rapporte zéro, puis le retirer. Les messages et les handlers ne
changent pas — c'est le gain des règles 1 et 3.
