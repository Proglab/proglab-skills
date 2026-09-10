---
name: symfony-proglab-async
description: >-
  Sortir du traitement de la requête un travail via Messenger, envoyer des emails de façon
  asynchrone avec Mailer et TemplatedEmail, et exécuter des tâches récurrentes avec
  Scheduler — transport Doctrine, politique de retry et d'échec, files priorisées,
  handlers idempotents, et workers sous supervisor ou systemd. Utilise ce skill dès qu'on
  demande d'envoyer un email, de faire quelque chose en arrière-plan, de mettre une tâche
  en file d'attente, d'exécuter quelque chose chaque nuit ou toutes les cinq minutes, de
  remplacer une crontab, d'accélérer un import lent ou une inscription lente, de traiter
  un gros CSV, de notifier quelqu'un après une action, de mettre en place Messenger, un
  message handler ou un worker, de configurer des retries, de traiter un message bloqué
  dans la file d'échec, ou dit « mes emails n'arrivent jamais », « l'import prend trop de
  temps », « cette page est lente parce qu'elle envoie un email », ou « comment planifier
  ça ».
---

# Travail asynchrone

> **Niveau : à la demande** — rien dans ce skill ne s'applique tant qu'une opération ne quitte pas réellement la requête. La première section détermine si c'est le cas ; sinon, ferme ce fichier.

Messenger, Mailer et Scheduler. Un seul transport, trois règles.

## D'abord : cela doit-il vraiment être asynchrone ?

Une opération devient un message quand **l'utilisateur n'a pas besoin du résultat**. C'est
tout le test. Pas « c'est lent » : un traitement lent que l'utilisateur attend reste lent
quand c'est un worker qui s'en charge, il a juste été déplacé là où personne ne le
surveille, et l'utilisateur reçoit maintenant une page de succès pour quelque chose qui
n'a pas encore eu lieu.

| Situation | Verdict |
|---|---|
| Importer 10 000 livres depuis un CSV, envoyer un email à l'utilisateur une fois terminé | Asynchrone |
| Envoyer l'email de publication d'une critique | Asynchrone — la page suivante n'en dépend pas |
| Générer un export mensuel que l'utilisateur télécharge plus tard | Asynchrone |
| Recalculer une note affichée sur la page qui s'affiche ensuite | **Synchrone** |
| Valider un import d'étagère pour que le formulaire puisse afficher des erreurs | **Synchrone** |
| Tout ce dont le résultat apparaît dans la réponse | **Synchrone** |

Le mode d'échec quand on se trompe est précis : le contrôleur renvoie 200, le handler
lève une exception trois heures plus tard, et personne ne s'en aperçoit parce que
personne ne lit le transport d'échec. Le travail asynchrone a besoin d'un responsable
opérationnel — sur un projet sans alerting ni superviseur de processus, ajouter une file
d'attente rend le système *moins* fiable. Dis-le avant d'installer quoi que ce soit.

## Messenger n'est pas un bus de commandes

Ici, Messenger n'est utilisé **que** pour le travail qui quitte la requête. Un contrôleur
appelle un service directement — `$this->shelfImporter->import($shelf, $file)` — et ne
fait appel à `$this->bus->dispatch(new ImportShelf($id))` que lorsque personne n'attend
le résultat.

Faire passer chaque action par le bus — le « command bus » façon CQRS — est délibérément
rejeté : le site d'appel cesse d'indiquer ce qui se passe, la stack trace traverse cinq
middlewares, et avec un transport synchrone tout ça a été payé pour un simple appel de
méthode. Même raisonnement que `symfony-proglab-architecture` : les conséquences métier sont
des appels explicites, pas des événements dispatchés.

## Transport : Doctrine

`MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0` — la file d'attente vit dans la
base de données de l'application elle-même : pas d'infrastructure supplémentaire,
messages en attente visibles avec un `SELECT`, couverts par la procédure de sauvegarde et
de restauration déjà en place. Sur PostgreSQL et MySQL 8, le transport lit avec
`FOR UPDATE SKIP LOCKED`, donc plusieurs workers peuvent tourner sans risque.

`auto_setup=0` signifie que `messenger_messages` n'est pas créée à l'exécution ; elle
provient d'une migration, car le schema listener de Doctrine ajoute la table au schéma
généré et `make:migration` la récupère comme n'importe quelle autre. Aucun DDL en
production.

**La limite, dit sans détour.** Au-delà d'environ quelques milliers de messages par jour,
la file d'attente représente une charge d'écriture et une pression de vacuum sur la base
de données métier, en plus du polling constant `SELECT … FOR UPDATE` de chaque worker.
Passe à Redis (`symfony/redis-messenger`) puis à AMQP (`symfony/amqp-messenger`, pour un
vrai routage et le dead-lettering) : un changement de DSN plus la purge de l'ancien
transport, handlers inchangés. N'installe pas RabbitMQ pour un projet qui envoie une
centaine d'emails par jour.

## Les trois règles

Elles évitent les trois bugs qui arrivent réellement.

### 1. Un message transporte des identifiants, jamais des entités

```php
#[AsMessage('async')]
final readonly class ImportShelf
{
    public function __construct(public int $shelfId, public string $uploadedFilePath) {}
}
```

Une entité sérialisée est un instantané : au moment où le worker la désérialise, la ligne
a déjà évolué, et la réécrire annule silencieusement tout ce qui s'est passé entre-temps.
Les proxies Doctrine aggravent le problème — ce qui est sérialisé, c'est le proxy, pas les
données. Le handler recharge depuis le repository et travaille sur l'état courant.

`#[AsMessage]` (`Symfony\Component\Messenger\Attribute\AsMessage`, 7.2+) place le routage
à côté du message ; en 6.4–7.1, déclare-le plutôt sous `routing:` dans
`config/packages/messenger.yaml` — même règle, endroit différent.

### 2. Un handler est idempotent

Messenger retente. Un handler qui débite une carte, envoie un email ou incrémente un
compteur doit survivre à une exécution deux fois sur le même message, parce que ça
arrivera. Protège-toi avec un état vérifiable — `if ($shelf->getImportedAt() !== null) { return; }`
— pas avec de l'espoir.

Symfony 7.3 a ajouté `DeduplicateMiddleware` / `DeduplicateStamp` (nécessite
`symfony/lock`). Son propre docblock le décrit comme « une primitive d'idempotence
best-effort… pas une primitive de correction contre un producteur de file hostile » : un
confort ajouté par-dessus un handler idempotent, jamais un substitut à celui-ci.

### 3. Un handler est une couche de traduction

Exactement comme un contrôleur : déballer le message, charger ce dont le service a
besoin, appeler le service, s'arrêter. Une règle qui vivrait ici serait inaccessible
depuis le chemin synchrone et intestable sans transport.

```php
#[AsMessageHandler]
final readonly class ImportShelfHandler
{
    public function __construct(
        private ShelfRepository $shelves,
        private ShelfImporter $importer,
    ) {}

    public function __invoke(ImportShelf $message): void
    {
        $shelf = $this->shelves->find($message->shelfId)
            ?? throw new UnrecoverableMessageHandlingException(
                \sprintf('Shelf "%d" no longer exists.', $message->shelfId),
            );

        $this->importer->import($shelf, $message->uploadedFilePath);
    }
}
```

Ce throw à lui seul fait toute la distinction sur le retry : retenter ne fera jamais
réapparaître une étagère supprimée, donc le message part directement vers le transport
d'échec au lieu de brûler trois tentatives. `UnrecoverableMessageHandlingException` pour
« ça ne marchera jamais » ; toute autre exception pour « le réseau était en panne ».

## Politique d'échec

```yaml
framework:
    messenger:
        failure_transport: failed
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2, jitter: 0.3 }
            failed: 'doctrine://default?queue_name=failed'
```

Trois tentatives à 1 s / 2 s / 4 s, avec du jitter pour qu'une API tierce en panne ne
reçoive pas tous les retries de tous les workers à la même milliseconde (`jitter` vaut
`0.1` par défaut ; l'augmenter est une assurance gratuite). Le message atterrit ensuite
dans `failed` — une file Doctrine, donc persistante, qui survit à un redémarrage, encore
là le lendemain matin.

**La règle qui compte : un message qui atteint le transport d'échec doit déclencher une
alerte.** Une file d'échec que personne ne lit est un mécanisme de perte de données avec
des étapes en plus.

Ce skill énonce la règle ; **`symfony-proglab-observability` possède le mécanisme** et le
code n'est écrit qu'une fois, dans son `references/alerting.md` : un listener sur
`WorkerMessageFailedEvent` protégé par `willRetry()`, qui journalise en `critical` sur un
canal `alert`, et — la partie qui décide si c'est une alerte du tout — un sink qui ne
repasse pas par Messenger. Le seul fait à retenir d'ici : **pas `symfony_mailer`.** Ce
standard route `SendEmailMessage` vers `async`, donc une alerte email sur la file cassée
serait mise en file sur la file cassée (constaté). Ne recopie pas le listener de mémoire ;
lis ce fichier.

## Files priorisées

Un transport par priorité, pour qu'un import de 10 000 lignes ne passe pas devant un
email d'inscription :

```yaml
transports:
    async_high: 'doctrine://default?queue_name=high'
    async:      'doctrine://default?queue_name=default'
    async_low:  'doctrine://default?queue_name=low'
```

```bash
php bin/console messenger:consume async_high async async_low
```

Le worker vide les receivers **dans l'ordre donné** et ne regarde `async` que lorsque
`async_high` est vide. `messenger:consume --queues=` ne fonctionne *pas* ici — le
receiver Doctrine n'implémente pas `QueueReceiverInterface`, cette option appartient à
AMQP et Redis. Avec Doctrine, des transports séparés sont le mécanisme.

## Tests

Rien ici n'a besoin d'un worker en cours d'exécution. **Le handler** est instancié et
appelé directement — `(new ImportShelfHandler($shelves, $importer))(new ImportShelf(12, $path))`.
**Le service qui dispatche** reçoit un `createMock(MessageBusInterface::class)` avec un
`expects()` sur `dispatch` et un `self::callback()` qui vérifie les valeurs du message ;
le `->willReturn(new Envelope($message))` n'est pas optionnel, car `dispatch()` est
déclarée `: Envelope` et un mock qui renvoie `null` échoue avec un `TypeError` qui
ressemble à un bug dans ton propre code. **Fonctionnellement**, route `async` vers
`'in-memory://'` sous `when@test:` et lis les enveloppes enregistrées avec `getSent()`.
Patterns complets : `references/messenger.md`.

## Email et Scheduler, en deux paragraphes

`SendEmailMessage` est routé vers `async` par la recipe, et l'asynchrone est le bon
choix. Une fois que le projet dispose des transports priorisés ci-dessus, **déplace-le
vers `async_high`** — une confirmation d'inscription qui attend derrière un import
nocturne est exactement le problème que ces transports existent pour résoudre, et c'est
un changement d'une ligne dans `routing:` (`references/messenger.md`). Il reste dans
`routing:` plutôt que de devenir `#[AsMessage]`, parce qu'on ne peut pas poser un
attribut sur la classe de quelqu'un d'autre. Le piège : un `TemplatedEmail` est rendu
**par le worker**, donc son contexte est sérialisé — mets-y une entité Doctrine et tu
livres un instantané périmé ou un proxy cassé. Passe des scalaires. Dans les tests,
l'assertion est `assertQueuedEmailCount()`, pas `assertEmailCount()`. Le reste :
`references/mailer.md`.

`#[AsCronTask('0 3 * * *')]` sur une méthode de service bat une crontab : versionné avec
le code qu'il exécute, et visible en revue. **Sa condition : il faut un worker en cours
d'exécution.** Une crontab tourne parce que cron est toujours là ; un schedule ne tourne
que tant que `messenger:consume scheduler_default` est actif — dis-le avant de
l'installer. Détails : `references/scheduler.md`.

## Quand quelque chose ne se produit pas

| Symptôme | Cause |
|---|---|
| Rien ne se passe, aucune erreur | Aucun worker ne consomme — `messenger:stats` affiche le nombre de messages en attente |
| Les emails n'arrivent jamais en local | Ils sont mis en file d'attente : `symfony console messenger:consume async -vv` |
| `Table messenger_messages does not exist` | `auto_setup=0` et la migration n'a pas été exécutée |
| Le handler exécute du vieux code après un déploiement | `messenger:stop-workers` a été oublié — voir `symfony-proglab-deployment` |
| Un message est traité deux fois | Normal. C'est exactement à ça que sert la règle 2 |
| `Cannot instantiate proxy` à la désérialisation | Une entité ou un proxy s'est retrouvé dans le message ou dans le contexte de l'email |
| Le worker meurt aux alentours de 128 Mo | `--memory-limit` manquant ; c'est un déclencheur de redémarrage, pas un bug |
| Les retries ne s'arrêtent jamais | `RecoverableMessageHandlingException` a `forceRetry: true` par défaut, ce qui contourne `max_retries`. Passe `forceRetry: false` — **8.1+ uniquement** ; en 6.4–8.0 le paramètre n'existe pas, le retry forcé est inconditionnel, et la seule issue est de lever une autre exception (`references/messenger.md`) |
| Une tâche planifiée ne se déclenche jamais | Aucun worker sur `scheduler_default`, ou `dragonmantank/cron-expression` est manquant |

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/messenger.md` | Configurer les transports et les retries, exécuter des workers en production, les commandes console, tester le dispatch |
| `references/mailer.md` | Tout ce qui touche à l'email : templates, inlining CSS, le piège de la sérialisation, configuration dev et test |
| `references/scheduler.md` | Tâches récurrentes, `#[AsSchedule]`, verrouillage, exécutions manquées, remplacement d'une crontab |
