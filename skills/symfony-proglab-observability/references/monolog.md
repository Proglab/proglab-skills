# Monolog : canaux, handlers, processors

Tout ce qui suit a été exécuté sur Symfony 8.1.5 / PHP 8.4 avec monolog-bundle 4.0.2 et
monolog 3.10. `vendor/` fait foi devant ce fichier.

## Inspecter ce qui est réellement câblé

Quatre commandes, qui répondent à la plupart des questions plus vite qu'une lecture du
YAML :

```bash
php bin/console debug:config monolog                              # config fusionnée, par environnement
php bin/console debug:container --tag=monolog.channel_logger      # tous les canaux existants
php bin/console debug:container --tag=monolog.processor           # processors, avec canal/handler/priorité
php bin/console debug:container --env=prod monolog.logger.alert   # ce canal existe-t-il en prod ?
```

`debug:config` est propre à chaque environnement : exécute-la avec `--env=prod` avant de
conclure qu'un handler est manquant.

## Canaux

### Déclaration

```yaml
monolog:
    channels: ['deprecation', 'business', 'alert']
```

`monolog.channels` crée `monolog.logger.<name>` comme service **public** (le
`LoggerChannelPass` appelle `setPublic(true)` sur les canaux additionnels), ce qui est ce
qui permet à `self::getContainer()->get('monolog.logger.alert')` de fonctionner dans un
test.

Canaux propres à Symfony, qu'on filtre plutôt qu'on ne déclare : `doctrine`, `messenger`,
`request`, `security`, `cache`, `http_client`, `mailer`, `console`, `router`, `event`,
`php`, `translation`, `asset_mapper`, `profiler`, `lock`, `workflow`, `debug`.

### Injection

```php
#[WithMonologChannel('business')]           // Monolog 3.5 + monolog-bundle 3.9
final readonly class PublishReview
{
    public function __construct(private LoggerInterface $logger) {}
}
```

```php
final readonly class ImportShelf
{
    public function __construct(
        #[Target('business')] private LoggerInterface $logger,       // au niveau de l'argument
        #[Target('alert')] private LoggerInterface $alertLogger,
    ) {}
}
```

Les quatre formes du tableau du SKILL ont été vérifiées comme produisant un logger dont
le `getName()` correspond au canal. Sur Symfony < 6.4, ou un monolog-bundle plus ancien
que 3.9, retombe sur le tag :

```yaml
services:
    App\Service\PublishReview:
        tags: [{ name: 'monolog.logger', channel: 'business' }]
```

### Filtrer les handlers par canal

```yaml
handlers:
    main:
        type: fingers_crossed
        channels: ['!deprecation', '!doctrine']    # exclusif : tout sauf ceux-là
    business_audit:
        type: stream
        path: php://stdout
        channels: ['business']                     # inclusif : seulement ceux-là
```

Un handler sans clé `channels:` reçoit tous les canaux. Mélanger `foo` et `!bar` dans une
même liste est une erreur de configuration — une liste est soit inclusive, soit
exclusive.

## Processors

Un processor est n'importe quel invokable recevant et renvoyant un `Monolog\LogRecord`.
Enregistre-le avec `#[AsMonologProcessor]` (monolog-bundle 3.8+, autoconfiguré), dont les
quatre arguments sont `channel`, `handler`, `method` et `priority`.

```php
#[AsMonologProcessor]
final readonly class CorrelationIdProcessor
{
    public function __construct(private CorrelationId $correlationId) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['correlation_id'] = $this->correlationId->get();

        return $record;
    }
}
```

`$record->extra` et `$record->context` sont mutables sur place ; `LogRecord::with(...)`
renvoie une copie modifiée et c'est ce qu'il faut utiliser pour remplacer un tableau
entier.

**Convention : `context` est ce que l'appelant a passé, `extra` est ce qu'un processor a
ajouté.** Les garder séparés est ce qui permet à un processor de masquage de réécrire
`context` sans toucher aux champs générés automatiquement.

Ordre d'exécution : les processors enregistrés sur le logger s'exécutent avant les
processors enregistrés sur un handler. Le `PsrLogMessageProcessor` propre à Monolog
(l'option de handler `process_psr_3_messages`) est un processor de handler, donc un
processor de masquage au niveau du logger, quelle que soit sa priorité, s'exécute
toujours en premier — mesuré : un placeholder `{password}` interpolé devient
`[redacted]`.

En dessous de monolog-bundle 3.8 :

```yaml
services:
    App\Logging\CorrelationIdProcessor:
        tags: [{ name: 'monolog.processor' }]
```

### L'identifiant de corrélation, en entier

```php
final class CorrelationId implements ResetInterface
{
    private ?string $value = null;

    public function __construct(private readonly RequestStack $requestStack) {}

    public function get(): string
    {
        return $this->value ??= $this->fromRequestOrNew();
    }

    public function set(string $value): void
    {
        $this->value = $value;
    }

    public function reset(): void
    {
        $this->value = null;
    }

    private function fromRequestOrNew(): string
    {
        $incoming = $this->requestStack->getMainRequest()?->headers->get('X-Request-Id');

        return null !== $incoming && 1 === preg_match('/^[A-Za-z0-9._-]{1,64}$/', $incoming)
            ? $incoming
            : bin2hex(random_bytes(8));
    }
}
```

`ResetInterface` est autoconfiguré vers `kernel.reset`, que le `ResetServicesListener` de
Messenger appelle après chaque message traité — sans cela, un worker réutilise un seul id
pour toute sa durée de vie. La regex sur l'en-tête entrant compte : la valeur finit dans
des fichiers de logs et, si tu la renvoies telle quelle, dans un en-tête de réponse.

La moitié Messenger, enregistrée comme middleware de bus :

```php
final readonly class CorrelationIdMiddleware implements MiddlewareInterface
{
    public function __construct(private CorrelationId $correlationId) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle(
                $envelope->with(new CorrelationIdStamp($this->correlationId->get())),
                $stack,
            );
        }

        if (($stamp = $envelope->last(CorrelationIdStamp::class)) instanceof CorrelationIdStamp) {
            $this->correlationId->set($stamp->correlationId);
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
```

```yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware: ['App\Messenger\CorrelationIdMiddleware']
```

`ReceivedStamp` est ce qui distingue les deux directions : absent au dispatch, présent
quand le worker rejoue l'enveloppe. Le stamp est sérialisé avec le message, donc il
survit à la traversée du transport.

### Qui est connecté

`Symfony\Bridge\Monolog\Processor\TokenProcessor` ajoute `extra.token` avec
`authenticated`, `roles` et `user_identifier`. Il n'est pas enregistré par défaut :

```yaml
services:
    Symfony\Bridge\Monolog\Processor\TokenProcessor:
        tags: [{ name: 'monolog.processor' }]
```

`user_identifier` est généralement une adresse email. C'est donc une donnée personnelle
dans chaque ligne de log — voir `sensitive-data.md` avant de l'activer.

`WebProcessor` (également issu du bridge) ajoute l'URL, l'IP, la méthode et le referrer,
et prend un argument de constructeur `$extraFields` restreignant lesquels de ces champs
sont enregistrés.

## fingers_crossed

```yaml
handlers:
    main:
        type: fingers_crossed
        action_level: error          # le déclencheur
        handler: nested              # où va le vidage
        excluded_http_codes: [404, 405]
        buffer_size: 50
        channels: ['!deprecation']
    nested:
        type: stream
        path: php://stderr
        level: debug
        formatter: monolog.formatter.json
```

| Option | Effet |
|---|---|
| `action_level` | Niveau qui libère le buffer. `error` dans la recette |
| `buffer_size` | Enregistrements conservés pendant la bufférisation. `0` est illimité — une fuite mémoire sur une requête longue |
| `stop_buffering` | `true` par défaut : une fois déclenché, tout le reste passe pour le reste du processus |
| `passthru_level` | Les enregistrements à ce niveau ou au-dessus sont écrits même si rien ne se déclenche jamais. Mets-le à `error` si tu veux les erreurs d'un processus CLI qui n'a jamais atteint `critical` |
| `excluded_http_codes` | Statuts de réponse qui ne déclenchent **pas**. S'appelait `excluded_404s`, supprimé dans monolog-bundle 4.0 |

`FingersCrossedHandler::reset()` appelle `flushBuffer()`, qui, sans `passthru_level`,
**abandonne** le buffer et revient en mode bufférisation. Le handler est tagué
`kernel.reset`, donc un worker démarre chaque message propre et un message réussi
n'écrit rien.

Le handler imbriqué est volontairement à `level: debug` : le buffer ne mérite d'être
vidé que s'il contient la trace de debug. Le remonter à `error` rend tout le mécanisme
inutile.

## Environnements

| Environnement | Forme | Pourquoi |
|---|---|---|
| `dev` | simple `stream` à `debug`, plus `console` | Tu veux chaque ligne, immédiatement |
| `test` | `fingers_crossed` vers un stream | Un test vert n'écrit rien ; un test en échec laisse une trace |
| `prod` | `fingers_crossed` → stream sur `php://stderr`, formatter JSON | Détenu par `symfony-proglab-deployment` — vérifie-le, ne le réécris pas |

Le canal `deprecation` a son propre handler de prod dans la recette, non bufférisé.
Garde-le : les dépréciations sont l'alerte précoce pour la prochaine montée de version
Symfony, et elles n'atteignent jamais `error`, donc `fingers_crossed` les avalerait pour
toujours.

## Vérifier que quelque chose a été logué

Une règle qui n'existe que dans une ligne de log reste une règle. Teste-la unitairement
avec un `TestHandler` :

```php
#[Test]
public function it_alerts_once_the_retries_are_exhausted(): void
{
    $handler = new TestHandler();
    $listener = new AlertOnMessageSentToFailureTransport(new Logger('alert', [$handler]));

    $listener(new WorkerMessageFailedEvent(
        new Envelope(new ImportShelf(12, '/tmp/shelf.csv')),
        'async',
        new \RuntimeException('the API was down'),
    ));

    self::assertTrue($handler->hasRecordThatContains(
        'Message moved to the failure transport',
        Level::Critical,
    ));
}
```

Vérifié vert sur PHPUnit 13.3.2. `TestHandler` propose aussi des méthodes magiques par
niveau (`hasCriticalRecords()`, `hasCriticalThatContains()`, `hasCriticalThatMatches()`,
`hasCriticalThatPasses()`) et `getRecords()` — vérifier que `getRecords() === []` est la
façon de prouver le chemin *silencieux*, celui que l'on oublie une fois sur deux.

Dans un test fonctionnel, récupère le logger de canal public et pousses-y un
`TestHandler` :
`self::getContainer()->get('monolog.logger.alert')->pushHandler($handler)`.
