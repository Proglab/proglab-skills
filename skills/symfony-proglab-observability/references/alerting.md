# Alertes, heartbeats et health checks

Le mécanisme derrière la règle de `symfony-proglab-async` — « un message dans la queue
d'échec doit déclencher une alerte » — plus les deux choses qu'un listener d'échec seul
ne peut pas détecter : un worker qui ne tourne pas, et une dépendance qui est en panne.
Exécuté sur Symfony 8.1.5 avec le transport Doctrine.

## 1. L'alerte d'échec

### Ce que le framework fait déjà

`SendFailedMessageForRetryListener` s'abonne à `WorkerMessageFailedEvent` à la priorité
**100** et, une fois que la stratégie de retry indique qu'il n'y a plus de tentative,
écrit `messenger.CRITICAL: Error thrown while handling message … Removing from transport
after 3 retries`. `SendFailedMessageToFailureTransportListener` déplace ensuite
l'enveloppe à la priorité **-100**. Un listener personnel à la priorité par défaut `0` se
place donc entre les deux : `willRetry()` est déjà exact, l'enveloppe n'a pas encore
bougé.

Si un handler Monolog route déjà `critical` vers un humain, la règle est satisfaite.

### Le listener

```php
#[AsEventListener]
#[WithMonologChannel('alert')]
final readonly class AlertOnMessageSentToFailureTransport
{
    public function __construct(private LoggerInterface $logger) {}

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $envelope = $event->getEnvelope();

        $this->logger->critical('Message moved to the failure transport', [
            'message_class' => $envelope->getMessage()::class,
            'transport' => $event->getReceiverName(),
            'retries' => RedeliveryStamp::getRetryCountFromEnvelope($envelope),
            'exception' => $event->getThrowable(),
        ]);
    }
}
```

Vérifié : exactement un enregistrement pour quatre tentatives. `#[AsEventListener]` sans
argument déduit l'événement du type du paramètre de `__invoke`. `getThrowable()` renvoie
le wrapper `HandlerFailedException`, dont le `getPrevious()` est ton exception ; la
placer sous la clé `exception` est la convention lue par les formatters Monolog et les
error trackers.

### La destination — l'endroit où ça part vraiment en vrille

**Ne l'envoie pas par email.** Mesuré, avec `SendEmailMessage` routé vers `async` comme
l'exige ce standard : un handler `symfony_mailer` s'est déclenché, et la ligne de log
suivante était `messenger.INFO: Sending message …\SendEmailMessage with async sender
using …\DoctrineTransport`. L'alerte sur la queue en échec était mise en queue sur la
queue en échec. Comme la raison habituelle pour laquelle un message pourrit est qu'aucun
worker ne tourne, l'email est l'unique alerte garantie de ne pas arriver au moment où tu
en as besoin — et router `SendEmailMessage` de façon synchrone n'est pas une solution,
car cela fait bloquer chaque requête par l'envoi d'un email métier.

Destinations qui ne repassent pas par Messenger :

| Destination | Config | Coût |
|---|---|---|
| `slackwebhook` | `webhook_url`, `channel`, `level: critical`, en option `exclude_fields: ['context.exception']` | Un appel HTTP bloquant dans le processus en échec. Acceptable uniquement en `critical` |
| `error_log` / `stream` sur `php://stderr` | Rien de plus que la recette | Gratuit. Nécessite une règle d'alerte côté plateforme — cette moitié relève de l'infrastructure |
| Error tracker comme handler `service` | `sentry/sentry-symfony`. monolog-bundle 4.0 a **supprimé** les types intégrés `sentry` et `raven` | Dépendance tierce, meilleur regroupement et meilleures notifications |

### Un handler d'alerte injoignable casse la requête

Mesuré, avec l'URL du webhook pointant vers un hôte qui ne se résout pas : un seul
`$logger->critical(...)` a produit un `Curl error (code 6): Could not resolve host` non
rattrapé, et la réponse est devenue un **500**. Monolog n'avale pas les exceptions de
handler sauf si un exception handler est défini, donc une destination d'alerte tierce
devient une dépendance d'exécution de tout chemin de code qui logue. Enveloppe-la dans
un `fallbackgroup`, qui essaie ses membres dans l'ordre et s'arrête au premier qui ne lève
pas d'exception :

```yaml
when@prod:
    monolog:
        handlers:
            alert:
                type: fallbackgroup
                members: ['alert_slack', 'alert_stderr']
                level: critical
            alert_slack:
                type: slackwebhook
                webhook_url: '%env(SLACK_ALERT_WEBHOOK)%'
                channel: '#alerts'
                level: critical
                exclude_fields: ['context.exception']
                nested: true
            alert_stderr:
                type: stream
                path: php://stderr
                level: critical
                nested: true
```

Vérifié sur monolog-bundle 4.0.2 : avec le même webhook injoignable, l'enregistrement a
atterri sur stderr et le processus s'est terminé avec le code `0`. `nested: true` garde
les membres hors de la pile de handlers de premier niveau, donc ils ne reçoivent que ce
que le groupe leur transmet. À noter : il n'y a **aucun filtre `channels:`** : `critical`
est déjà le seuil qui signifie « échec non planifié » (voir l'échelle de niveaux dans
SKILL.md), donc ce handler unique couvre les 500, les échecs de worker et tout ce qui
l'atteint. `exclude_fields` prend des chemins pointés (`context.*`, `extra.*`) et
nécessite monolog-bundle 3.11+.

`Symfony\Bridge\Monolog\Handler\NotifierHandler` transforme les enregistrements en
notifications Notifier, mais monolog-bundle n'a pas de type de handler `notifier` — il
doit être câblé comme handler `service`, et ses transports sont des fournisseurs de
chat/SMS sur lesquels ce standard n'a pas tranché. Préfère le tableau ci-dessus.

## 2. Le heartbeat du worker

Un listener sur `WorkerMessageFailedEvent` nécessite un worker. Quand le worker est mort,
rien n'échoue — les messages s'accumulent simplement, et toute alerte basée sur l'échec
reste silencieuse. C'est l'incident que les gens décrivent par « on ne s'est pas rendu
compte que la queue était bloquée ». `WorkerRunningEvent` est dispatché à chaque itération
de boucle, **y compris quand le worker est inactif** (une fois par `sleep`, une seconde
par défaut). Limite sa fréquence et écris un horodatage quelque part que le processus
web peut lire :

```php
#[AsEventListener]
final class WorkerHeartbeat
{
    public const string CACHE_KEY = 'worker.heartbeat';

    private ?int $lastWrittenAt = null;

    public function __construct(
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.worker_heartbeat_period')] private readonly int $period = 30,
    ) {}

    public function __invoke(WorkerRunningEvent $event): void
    {
        $now = $this->clock->now()->getTimestamp();

        if (null !== $this->lastWrittenAt && $now - $this->lastWrittenAt < $this->period) {
            return;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set($now);
        $item->expiresAfter(3600);
        $this->cache->save($item);

        $this->lastWrittenAt = $now;
    }
}
```

Trois choses sont délibérées, et la première est une exception à une règle que cette
suite énonce clairement. **Elle est `final` mais pas `final readonly`.** La raison pour
laquelle le standard impose `readonly` est qu'un service qui se mute lui-même se comporte
différemment à la deuxième requête ; ici, la mutation *est* la fonctionnalité —
`$lastWrittenAt` est ce qui limite la fréquence d'un listener qui se déclenche à chaque
boucle du worker — et l'état est propre au processus, jamais lu par personne d'autre, et
sans conséquence s'il est perdu. C'est le seul type d'exception qui vaille la peine
d'être fait : le dire, et le limiter à cette seule propriété. **Elle n'est pas non plus
`ResetInterface`** — le `ResetServicesListener` de Messenger réinitialise les services
tagués après chaque message traité, et un `$lastWrittenAt` réinitialisé annulerait la
limitation de fréquence. Et **`cache.app`, pas un fichier** : la même contrainte que
`symfony-proglab-deployment` documente pour `messenger:stop-workers` s'applique, donc
avec un `cache.app` sur système de fichiers, le worker et le conteneur web écrivent dans
des pools différents et le heartbeat est invisible pour le endpoint de santé. Pointe
`cache.app` vers Redis, Valkey ou la base de données dès qu'ils sont dans des conteneurs
séparés. La période est un comportement identique partout, donc c'est un paramètre
`app.`, pas une variable d'environnement.

## 3. Le watchdog de profondeur de queue

La profondeur détecte ce que le heartbeat et le listener d'échec manquent tous les deux :
un worker vivant mais qui ne suit pas le rythme, et un transport `failed` que personne
n'a vidé. Une règle sur l'endroit où il tourne : **pas à l'intérieur du worker qu'il
surveille.** Une tâche du Scheduler (`#[AsCronTask]`) est consommée par
`messenger:consume scheduler_default`, donc si c'est la couche worker elle-même qui est
morte, le watchdog meurt avec elle. Faites-le tourner depuis le planificateur propre à la
plateforme (cron, un `CronJob` Kubernetes) ou expose-le comme un health check qu'un
moniteur externe interroge.

Pour une sonde au niveau shell, la console suffit :

```bash
php bin/console messenger:stats async failed --format=json
# {"transports":{"async":{"count":0},"failed":{"count":1}}}
php bin/console messenger:failed:show --stats
# There is 1 message pending in the failure transport.  App\Message\ImportShelf  1
```

`messenger:stats` date de la 6.2, `--format` de la 7.2 (la valeur `text` a été supprimée
en 8.0, utilise `txt`) ; `messenger:failed:show --stats` date de la 5.4. Les deux
sortent avec le code `0` quel que soit le compteur, donc un script de monitoring doit lire
la sortie, pas le code de sortie. En PHP, injecte directement le transport — le receiver
Doctrine implémente `MessageCountAwareInterface` :

```php
final readonly class FailureQueueHealthCheck implements HealthCheck
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private MessageCountAwareInterface $failed,
    ) {}

    public function name(): string { return 'failure_queue'; }

    public function run(): bool { return 0 === $this->failed->getMessageCount(); }
}
```

Tous les transports ne peuvent pas être comptés : `messenger:stats` signale
`scheduler_default` comme non comptable, car `SchedulerTransport` n'implémente pas
l'interface. Pointer le check vers l'un d'eux n'est **pas** détecté par `lint:container` —
il rapporte que le conteneur est correct, et le `TypeError` survient à la première
instanciation, à l'exécution. Même forme que le piège `#[Target]` dans
`symfony-proglab-architecture` ; le contrôleur ci-dessous y survit.

## 4. Health checks

```php
#[AutoconfigureTag('app.health_check')]
interface HealthCheck
{
    public function name(): string;

    public function run(): bool;
}
```

```php
final class HealthController
{
    /**
     * `getProvidedServices()` lives on Symfony\Contracts\Service\ServiceProviderInterface,
     * not on Psr\Container\ContainerInterface — and it is the templated one, so this
     * type-hint is what makes `$check->run()` resolve at PHPStan level max.
     *
     * @param ServiceProviderInterface<HealthCheck> $checks
     */
    public function __construct(
        #[AutowireLocator('app.health_check')] private readonly ServiceProviderInterface $checks,
    ) {}

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $results = [];
        $healthy = true;

        foreach (array_keys($this->checks->getProvidedServices()) as $id) {
            try {
                $check = $this->checks->get($id);
                $name = $check->name();
                $ok = $check->run();
            } catch (\Throwable) {
                $name = $id;
                $ok = false;
            }

            $results[$name] = $ok ? 'ok' : 'failed';
            $healthy = $healthy && $ok;
        }

        return new JsonResponse(
            ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $results],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
```

Vérifié de bout en bout :
`{"status":"ok","checks":{"database":"ok","failure_queue":"ok","worker":"ok"}}` avec un
200, puis après qu'un message a été laissé dans le transport d'échec,
`{"status":"degraded",…,"failure_queue":"failed",…}` avec un 503. Quatre détails qui
décident si tout ceci vaut quelque chose :

- **Un locator, pas `#[AutowireIterator]`.** Avec un itérateur, chaque check est construit
  par le `foreach` lui-même, *en dehors* de tout `try` autour de `run()` — mesuré : un
  check dont le constructeur levait un `TypeError` produisait un 500 pour tout le
  endpoint. Avec le locator, `get()` se produit à l'intérieur du `try`, et le même check
  cassé est revenu sous la forme
  `{"App\\Health\\FailureQueueHealthCheck":"failed"}` avec un 503.
- **503, pas 200 avec un corps disant « degraded ».** Les orchestrateurs lisent le code de
  statut et rien d'autre.
- **`#[Serialize]` ne peut pas exprimer cela** : son `code` est fixé au niveau de
  l'attribut, donc la réponse ne peut pas dépendre du résultat. Renvoie directement un
  `JsonResponse`.
- **`access_control` doit le laisser passer.** Un endpoint de santé derrière le firewall
  redirige la sonde vers un formulaire de connexion, un 302 que l'orchestrateur lit comme
  « en panne » — ou pire, comme « en bonne santé ».

### Liveness et readiness

Elles répondent à des questions différentes, et les confondre provoque des boucles de
redémarrage.

| Sonde | Question | Inclure | Exclure |
|---|---|---|---|
| **Liveness** | Ce processus est-il bloqué ? | Rien d'autre que « PHP a répondu » | Toute dépendance. Une panne de base de données qui redémarre tous tes conteneurs aggrave la panne |
| **Readiness** | Le trafic doit-il aller ici ? | Base de données, cache, tout ce dont une requête a besoin | Les choses sur lesquelles la *plateforme* devrait alerter plutôt que d'y couper le trafic |
| **Monitoring** | Le système est-il en bonne santé ? | Profondeur de la queue d'échec, heartbeat du worker, API tierces | — |

La queue d'échec et le heartbeat du worker appartiennent à la troisième ligne : une queue
d'échec pleine mérite un signal pour un humain, pas une raison d'arrêter de router le
trafic HTTP vers un conteneur web. Place-les sur une route séparée pour que la readiness
probe ne fasse pas tomber le site parce qu'un worker est en retard.

### Le check de base de données

```php
public function run(): bool
{
    return 1 === (int) $this->connection
        ->executeQuery($this->connection->getDatabasePlatform()->getDummySelectSQL())
        ->fetchOne();
}
```

`getDummySelectSQL()` (DBAL 4, `AbstractPlatform`) renvoie le `SELECT 1` portable propre à
la plateforme — Oracle a besoin de `FROM DUAL`. Il fait aussi plus que `isConnected()` :
il force un véritable aller-retour, ce qui détecte une connexion à laquelle le driver
croit encore. C'est le seul endroit du standard où du SQL vit en dehors d'un repository,
délibérément — ce n'est pas une requête sur le domaine, c'est une sonde de connectivité.
Garde-le dans un service `Health\` dédié pour que l'exception à la règle reste visible.
