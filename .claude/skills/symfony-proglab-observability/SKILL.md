---
name: symfony-proglab-observability
description: >-
  Faire en sorte qu'une application Symfony dise ce qu'elle fait en production : des
  canaux Monolog séparant les logs applicatifs de ceux de Doctrine et de Messenger, des
  processors attachant un identifiant de corrélation et l'utilisateur courant,
  fingers_crossed expliqué, le masquage des mots de passe et des données personnelles,
  une alerte quand un message atteint le transport d'échec de Messenger, un heartbeat de
  worker, et un health check qui teste vraiment la base de données.
  Utilise ce skill dès qu'on demande comment savoir si quelque chose a cassé en
  production, comment ajouter du logging, ajouter un logger à un service, mettre en
  place des canaux de logs, ajouter du error tracking ou Sentry, être alerté quand
  quelque chose échoue, ajouter un health check ou une readiness probe, comprendre
  pourquoi rien n'apparaît dans les logs, ou dit « on ne s'est pas rendu compte que la
  queue était bloquée », « personne n'a vu l'erreur », « les logs sont illisibles »,
  « est-ce que cet email est vraiment parti », « le worker est-il toujours vivant »,
  « comment tracer la requête d'un utilisateur », ou « il y a des mots de passe dans nos
  logs ».
---

# Observabilité

> **Niveau : à la demande** — les canaux et le masquage des données comptent dès le
> premier déploiement en production ; les identifiants de corrélation, le heartbeat du
> worker et les sondes de santé ne se justifient qu'avec un vrai worker, un vrai
> orchestrateur, un vrai incident.

Une application qui arrive en production puis devient silencieuse n'est pas terminée. Ce
skill couvre **ce que l'application émet et comment c'est structuré** : canaux, niveaux,
processors, masquage, alertes, santé. **La destination des logs relève de
l'infrastructure et sort du périmètre** — un fichier, stdout, un agrégateur managé, une
stack Grafana. La configuration du handler de prod (`php://stderr`, JSON) appartient déjà
à `symfony-proglab-deployment` ; le profiling et Stopwatch appartiennent à
`symfony-proglab-performance` ; le flux de logs du serveur de dev appartient à
`symfony-proglab-local-dev`.

## Le pacte déjà passé par cette suite

Le standard impose `#[WithLogLevel]` sur les exceptions métier attendues pour qu'elles
cessent de noyer les vraies pannes. Cela ne paie que si quelque chose surveille ce qui
reste. L'échelle appliquée par Symfony, tirée de `ErrorListener::resolveLogLevel()` :

| Exception | Niveau |
|---|---|
| Pas une `HttpExceptionInterface`, ou statut ≥ 500 | `critical` |
| `HttpExceptionInterface` avec un statut 4xx (un simple 404 inclus) | `error` |
| Porte `#[WithLogLevel]` | ce que dit l'attribut |
| Listée dans `framework.exceptions` avec un `log_level` | celui-là — **la config l'emporte sur l'attribut** |

Donc **`critical` signifie « une requête ou un message est mort sur quelque chose que
personne n'avait prévu »**, et c'est le niveau auquel s'abonne un handler d'alerte ;
`error` n'est pas un seuil utile, car tout 404 que `excluded_http_codes` ne filtre pas
y atterrit. `framework.exceptions` accepte aussi un `log_channel`, qui déplace toute une
famille d'exceptions attendues hors du canal `request` — `log_channel: business` avec
`log_level: info` produit `business.INFO`, vérifié en 8.1 (vérifier
`config:dump-reference framework exceptions` sur une version plus ancienne).

## Canaux

Un flux de logs unique cesse d'être lisible à la taille où on en aurait justement besoin :
sur n'importe quelle page réelle, Doctrine seul émet un enregistrement par requête SQL,
Messenger un par dispatch, le cache un par miss, et les dix lignes écrites par ton
propre code deviennent une erreur d'arrondi. Les canaux permettent de filtrer *avant* que
la donnée ne quitte le processus. Symfony en fournit déjà — `doctrine`, `messenger`,
`request`, `security`, `cache`, `http_client`, `mailer`, `console`, `router`, `event`,
`php`, `deprecation` — donc ajoute les tiens avec
`monolog.channels: ['deprecation', 'business', 'alert']` pour tout ce qui mérite d'être
surveillé séparément, puis injecte le logger de ce canal. Quatre approches fonctionnent,
vérifiées sur monolog-bundle 4.0 :

| Comment | Remarques |
|---|---|
| `#[WithMonologChannel('business')]` sur la classe | La plus claire. Monolog 3.5 + monolog-bundle 3.9 |
| `#[Target('business')]` sur l'argument `LoggerInterface` | Au niveau de l'argument, quand une classe a besoin de deux canaux |
| Nommer l'argument `$businessLogger` | Fonctionne, mais c'est invisible. Renomme la variable et le canal change silencieusement |
| `#[Autowire(service: 'monolog.logger.business')]` | L'échappatoire quand l'alias n'existe pas |

**Un piège, mesuré.** `#[Target]` et `#[Autowire(service:)]` échouent à la compilation sur
une faute de frappe, en listant tous les canaux qui existent réellement.
`#[WithMonologChannel]` ne le fait pas : un canal mal orthographié est silencieusement
*créé*, échappe à tout filtre de handler, et rien ne te prévient.
`debug:container --tag=monolog.channel_logger` les liste tous ; un nom que tu ne
reconnais pas est une faute de frappe.

## fingers_crossed, et pourquoi une requête qui fonctionne ne logue rien

La recette de prod enveloppe le stream handler dans `fingers_crossed`. Celui-ci
**bufférise tout et n'écrit rien** tant qu'aucun enregistrement n'atteint `action_level`
(`error` par défaut), puis vide le buffer entier — la trace de debug qui a mené à
l'échec — et laisse ensuite passer les enregistrements pour le reste du processus. Deux
conséquences signalées comme des bugs : une requête réussie n'écrit **zéro ligne** (donc
les appels `info` qui tracent un chemin nominal n'apparaissent jamais en production), et
une requête en échec déverse jusqu'à `buffer_size` (50 dans la recette) enregistrements
d'un coup, y compris chaque requête SQL Doctrine **avec les valeurs de ses paramètres
liés** — le but même du mécanisme, et la plus grosse source unique de secrets dans les
logs.

Dans un worker Messenger, le handler est réinitialisé entre les messages
(`kernel.reset`, via le `ResetServicesListener` de Messenger), donc un message en échec
vide sa propre trace et un message réussi reste silencieux, exactement comme pour une
requête. `passthru_level` et `excluded_http_codes` : `references/monolog.md`.

## Une action utilisateur, plusieurs processus

Un identifiant de corrélation est ce qui permet de lire une requête et les workers
qu'elle a déclenchés comme une seule histoire. Trois éléments : un service holder qui
résout l'id depuis un en-tête `X-Request-Id` ou en génère un (implémentant
`ResetInterface`, pour qu'un worker de longue durée ne le réutilise pas) ; un processor
Monolog qui le pose sur chaque enregistrement ; un middleware Messenger qui l'estampille
sur les enveloppes sortantes et le restaure dans le worker.

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

Mesuré de bout en bout : un id généré dans le processus qui dispatche apparaît sur les 29
enregistrements écrits par le worker en traitant ce message, y compris le
`messenger.CRITICAL` propre au framework. Cela ne nécessite **aucun kernel listener** —
le holder lit `RequestStack`, ce qui reste conforme à la règle « les kernel listeners sont
un dernier recours ». Code complet, plus le processor utilisateur/token :
`references/monolog.md`. `#[AsMonologProcessor]` est disponible depuis monolog-bundle
3.8+ (`priority:` depuis Monolog 3.4 et monolog-bundle 3.11) ; en dessous, tague
`monolog.processor` en YAML.

## Ce qui ne doit jamais être logué

Un log finit dans une sauvegarde, chez un agrégateur tiers, et sur une capture d'écran de
support. Les mots de passe, tokens, clés d'API, identifiants de session, numéros de carte
et de compte bancaire, et les données personnelles au-delà de ce qui est nécessaire pour
identifier l'acteur n'ont rien à y faire.

Le mécanisme est un processor de masquage à faible priorité — il s'exécute avant
l'interpolation PSR-3 propre aux handlers, donc `{password}` dans une chaîne de message
est masqué aussi (vérifié). Le piège qu'il ne peut pas corriger est une valeur que tu
as toi-même concaténée dans le message : **mets les données dans le tableau de
contexte, jamais dans la chaîne du message.** La première fuite à vérifier est
`doctrine.dbal.logging` — elle vaut `%kernel.debug%` par défaut, donc elle est désactivée
en production, mais quiconque l'active pour traquer une requête lente se met à écrire
chaque paramètre lié au niveau `debug`, et `fingers_crossed` les déverse au prochain
incident. Destinations et code du processor : `references/sensitive-data.md`.

## L'alerte d'échec Messenger

`symfony-proglab-async` pose la règle — un message qui atteint le transport d'échec doit
déclencher une alerte — sans le mécanisme. Le voici : ce que le framework fait déjà, ce
qu'ajoute un listener, et la destination qui décide si tout ceci constitue réellement une
alerte.

**Le framework le logue déjà.** `SendFailedMessageForRetryListener` écrit
`messenger.CRITICAL: Error thrown while handling message … Removing from transport
after N retries` dès que les tentatives sont épuisées, avant que
`SendFailedMessageToFailureTransportListener` (priorité -100) ne déplace l'enveloppe. Si
`critical` atteint déjà un humain, c'est réglé.

**Un listener ajoute la structure** — `#[AsEventListener]` sur `WorkerMessageFailedEvent`,
gardé par `willRetry()` pour que les trois tentatives restent silencieuses :

```php
// La copie canonique est references/alerting.md ; celle-ci lui est identique.
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

**La destination est ce qui décide si c'est une alerte.** Et voici la découverte qui
compte : **ne l'envoie pas par email.** Vérifié — avec `SendEmailMessage` routé vers
`async` comme l'exige ce standard, un handler `symfony_mailer` produit
`messenger.INFO: Sending message SendEmailMessage with async sender`. L'alerte sur la
queue cassée est mise en queue *sur la queue cassée*. Utilise une destination qui ne
repasse pas par Messenger — un handler `slackwebhook`, `error_log`, Sentry, ou `critical`
sur stderr avec la règle d'alerte propre à la plateforme — et enveloppe une destination
tierce dans un handler `fallbackgroup` : un webhook injoignable lève une exception, et
Monolog la laisse transformer une erreur loguée en 500 (mesuré).

**Un listener ne peut pas se déclencher si aucun worker ne tourne**, ce qui est la raison
la plus fréquente pour laquelle une queue pourrit. Cela nécessite le second mécanisme :
un heartbeat de worker écrit sur `WorkerRunningEvent`, plus une sonde sur la profondeur
de la queue — les deux dans `references/alerting.md`, avec les commandes console
(`messenger:stats --format=json`, `messenger:failed:show --stats`) pour la version en
script shell.

## Health checks

Une route qui renvoie 200 sans condition dit à l'orchestrateur que le conteneur a
démarré, pas que l'application fonctionne. Teste les dépendances :

```php
public function run(): bool
{
    return 1 === (int) $this->connection
        ->executeQuery($this->connection->getDatabasePlatform()->getDummySelectSQL())
        ->fetchOne();
}
```

**Deux exceptions délibérées au standard vivent dans ce endpoint, et ce sont bien des
exceptions, pas des oublis.** La première est ici : c'est du SQL en dehors d'un
repository. C'est autorisé parce que ce n'est pas une requête sur le domaine — il n'y a
ni intention à nommer ni résultat à mapper, c'est une sonde de connectivité — donc cela
reste dans un service `Health\` dédié où l'exception à la règle demeure visible plutôt
que de se propager. La seconde est plus bas.

`getDummySelectSQL()` est portable là où un littéral `SELECT 1` ne l'est pas (Oracle a
besoin de `FROM DUAL`). Rassemble les checks avec `#[AutoconfigureTag]` sur une interface
`HealthCheck` et **`#[AutowireLocator]`** dans le contrôleur — avec un itérateur, chaque
check est construit par le `foreach`, en dehors de tout `try`, et un check
inconstructible fait planter tout l'endpoint en 500 (mesuré). Renvoie **503 quand un
check échoue** ; une sonde qui répond toujours 200 est décorative.

Voici la seconde exception : le standard dit qu'une action renvoie des données et que
`#[Serialize]` décide de la représentation, mais le `code` de `#[Serialize]` est fixé au
niveau de l'attribut, donc il ne peut pas exprimer un statut qui dépend du résultat. Un
`JsonResponse` brut est donc correct *ici* — parce que l'attribut ne peut réellement pas
le faire, pas par commodité — et nulle part ailleurs.

Vérifié en conditions réelles :
`{"status":"degraded","checks":{"database":"ok","failure_queue":"failed","worker":"ok"}}`
avec un HTTP 503. Implémentation complète, et quels checks relèvent d'une liveness ou
d'une readiness probe : `references/alerting.md`.

## Error tracking

Sentry ou un équivalent apporte ce qu'une ligne de log ne peut pas : le même exception
groupée à travers ses occurrences, un marqueur de release, la requête et l'utilisateur
attachés, et une notification la première fois qu'une empreinte apparaît. Cela ne
remplace rien — les logs restent la trace qui y a mené. Sur monolog-bundle 4.0, les types
de handler intégrés `sentry` et `raven` **ont été supprimés** ; l'intégration se fait via
`sentry/sentry-symfony` et un handler de type `service`.

La seule chose qui compte lors du câblage : **n'envoie pas les exceptions déjà marquées
comme attendues par `#[WithLogLevel]`.** La route par niveau de log fait cela
gratuitement — un SDK intégré comme handler Monolog à `level: critical` ne voit jamais le
`info` produit par l'attribut. La liste d'exclusion propre au SDK est le second levier :
lis sa clé de config actuelle avec `debug:config` plutôt que d'en copier une depuis un
article de blog.

## Quand rien n'apparaît

| Symptôme | Cause |
|---|---|
| Rien dans le log de prod pour une requête qui fonctionne | `fingers_crossed` — c'est un comportement correct, pas un handler cassé |
| Les appels `info` n'apparaissent jamais en production | Idem. Ils sont bufférisés et abandonnés sauf si la requête échoue |
| Toute une famille d'exceptions noie le log | `#[WithLogLevel]` manquant, ou un 4xx logué en `error` — ajoute `excluded_http_codes` |
| Le handler d'alerte ne se déclenche jamais | Son `level` est au-dessus de ce que porte l'enregistrement, ou un filtre `channels:` l'exclut |
| Les emails d'alerte n'arrivent jamais quand la queue casse | Ils sont mis en queue sur la queue cassée. Utilise une destination hors de Messenger |
| Un filtre de canal n'a aucun effet | Le nom du canal est une faute de frappe — `debug:container --tag=monolog.channel_logger` |
| Un champ de processor manque sur certains enregistrements | Il est limité à un canal ou un handler — `debug:container --tag=monolog.processor` |
| Logs pleins de paramètres SQL | `doctrine.dbal.logging` a été activé, et `fingers_crossed` a vidé le buffer |
| Le health check renvoie 200 alors que l'app est cassée | Il ne teste rien. Donne-lui un vrai check de dépendance |
| Le endpoint de santé plante en 500 | Un check n'a même pas pu être construit — utilise `#[AutowireLocator]`, pas `#[AutowireIterator]` |
| L'identifiant de corrélation change en cours de worker | Le holder n'est pas `ResetInterface`, ou le middleware n'est pas enregistré sur le bus |

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/monolog.md` | Configurer canaux et handlers, écrire un processor, options de `fingers_crossed`, configuration par environnement, vérifier dans un test que quelque chose a été logué |
| `references/sensitive-data.md` | Avant de loguer quoi que ce soit dérivé d'une saisie utilisateur, ou quand une revue de logs révèle un secret |
| `references/alerting.md` | Câbler l'alerte d'échec Messenger et sa destination, le heartbeat de worker, le watchdog de profondeur de queue, les endpoints de health check |
