# Scheduler

Travail récurrent, versionné avec le code qu'il exécute.

## Sommaire

- [Avant de l'installer](#avant-de-linstaller)
- [Les attributs](#les-attributs)
- [Un schedule sous forme de classe](#un-schedule-sous-forme-de-classe)
- [Exécutions manquées et redémarrages](#exécutions-manquées-et-redémarrages)
- [Verrouillage](#verrouillage)
- [Le faire tourner](#le-faire-tourner)
- [Tester une tâche planifiée](#tester-une-tâche-planifiée)
- [Scheduler ou crontab](#scheduler-ou-crontab)

## Avant de l'installer

```bash
composer require symfony/scheduler
composer require dragonmantank/cron-expression   # seulement si tu utilises des expressions cron
```

**Un schedule ne tourne que tant qu'un worker est en cours d'exécution.** C'est la
différence qui détermine si Scheduler est une amélioration ou une régression sur un
projet donné :

| | crontab | Scheduler |
|---|---|---|
| Tourne parce que | cron fait partie de l'OS et est toujours actif | `messenger:consume scheduler_default` est actif |
| Vit | dans `/etc/cron.d`, édité sur le serveur | dans `src/`, dans la pull request |
| Visible en revue | non | oui |
| Survit à un déploiement | oui, intact | nécessite que le superviseur redémarre le worker |
| Survit au crash du worker | n/a | non — rien ne tourne tant qu'il n'est pas revenu |

Sur un projet avec un superviseur de processus (voir `references/messenger.md`),
Scheduler est meilleur sur chaque ligne qui compte. Sur un projet sans superviseur ni
monitoring, une crontab qui appelle `bin/console` est plus honnête, parce qu'un cron mort
est visible au niveau de l'OS, contrairement à un worker mort. Dis dans quelle situation
tu te trouves avant d'écrire l'attribut.

## Les attributs

Les deux vivent dans `Symfony\Component\Scheduler\Attribute` et ciblent tous les deux une
classe ou une méthode.

```php
final readonly class ShelfMaintenance
{
    public function __construct(private ShelfArchiver $archiver) {}

    #[AsCronTask(expression: '0 3 * * *', jitter: 60)]
    public function archiveAbandonedShelves(): void
    {
        $this->archiver->archiveAbandoned();
    }

    #[AsPeriodicTask(frequency: '10 minutes', from: '07:00:00', until: '23:00:00')]
    public function refreshTrendingBooks(): void
    {
        $this->archiver->refreshTrending();
    }
}
```

Les paramètres, vérifiés par rapport aux constructeurs des attributs :

- `AsCronTask(string $expression, ?string $timezone, ?int $jitter, array|string|null $arguments, string $schedule = 'default', ?string $method, array|string|null $transports)`
- `AsPeriodicTask(string|int $frequency, ?string $from, ?string $until, ?int $jitter, array|string|null $arguments, string $schedule = 'default', ?string $method, array|string|null $transports)`

`frequency` accepte une chaîne lisible (`'10 minutes'`), un entier en secondes, ou un
`\DateInterval`. `jitter` est un nombre de **secondes** de délai aléatoire — mets-le sur
tout ce qui appelle une API externe, pour que vingt instances de l'application ne
l'appellent pas à la même seconde.

`#[AsCronTask]` a besoin de `dragonmantank/cron-expression` ; sans ça, il lève une
`LogicException` nommant le paquet à installer. `#[AsPeriodicTask]` n'a pas cette
dépendance.

**Les expressions hashées sont le meilleur défaut pour tout ce qui n'est pas lié à une
heure d'horloge précise.** `#[AsCronTask('#daily')]` choisit une minute et une heure
stables dérivées d'un SHA-256 de l'identité propre de la tâche (`@service::method`), donc
c'est la même à chaque exécution mais différente de chaque autre tâche et de la même
tâche sur une autre application. La liste complète des alias, tirée de
`CronExpressionTrigger::HASH_ALIAS_MAP`, compte onze entrées :

```
#hourly  #daily  #weekly  #monthly  #annually  #yearly  #midnight
#weekly@midnight  #monthly@midnight  #annually@midnight  #yearly@midnight
```

Le suffixe `@midnight` fait **partie de l'alias** — s'écrit `#weekly@midnight`, en
gardant le `#` initial — et il contraint l'heure hashée à 0–2. Il n'existe ni
`#hourly@midnight` ni `#daily@midnight` : `#midnight` *est* déjà celle de tous les jours
la nuit. Un `#` isolé dans n'importe laquelle des cinq positions est randomisé sur la
plage de ce champ. Fais attention au symbole : ce sont des `#`, pas la forme `@daily`
qu'utilisent d'autres implémentations cron — `@daily` n'est pas reconnu. N'utilise
`'0 3 * * *'` que quand 03h00 compte réellement.

Une contrainte si tu combines ça avec le schedule à base de classe ci-dessous : une
expression hashée exige que le message soit `\Stringable`, car le hash est calculé sur
`(string) $message`. `RecurringMessage::cron('#daily', $message)` sur une classe de
message ordinaire lève *« A message must be stringable to use \"hashed\" cron
expressions. »* Les tâches à base d'attributs ne sont pas concernées —
`ServiceCallMessage` est déjà `\Stringable`, ce qui est l'origine de l'identité
`@service::method`.

**La méthode est une couche de traduction**, exactement comme une commande ou un
handler : elle appelle un service et ne porte aucune règle métier. C'est ce qui rend le
schedule remplaçable — le même `ShelfArchiver::archiveAbandoned()` peut être déclenché
par une commande console, un bouton admin, ou un test, et aucun d'eux ne passe par le
scheduler.

## Un schedule sous forme de classe

Les attributs suffisent pour la plupart des projets. Utilise `#[AsSchedule]` quand le
schedule lui-même a besoin de configuration — verrouillage, état, plusieurs tâches
partageant une politique :

```php
#[AsSchedule('maintenance')]
final readonly class MaintenanceSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        private LockFactory $lockFactory,
    ) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                RecurringMessage::cron('0 3 * * *', new ArchiveAbandonedShelves()),
                RecurringMessage::every('10 minutes', new RefreshTrendingBooks()),
            )
            ->stateful($this->cache)
            ->lock($this->lockFactory->createLock('maintenance-schedule'))
            ->processOnlyLastMissedRun(true);
    }
}
```

`#[AsSchedule(name: 'maintenance')]` enregistre le provider sous ce nom, et le transport
devient `scheduler_maintenance`. Les messages produits sont des messages Messenger
ordinaires avec des handlers ordinaires, donc les trois règles de `SKILL.md`
s'appliquent sans changement.

Un schedule portant un nom autre que `default` a besoin de son propre worker, ou de voir
son nom ajouté à une invocation `messenger:consume` existante. Oublier ça est la raison
la plus fréquente pour laquelle une tâche ajoutée à un second schedule ne se déclenche
jamais.

## Exécutions manquées et redémarrages

Par défaut, le scheduler calcule la prochaine exécution à partir du moment où il
démarre. Un worker resté arrêté deux heures saute donc tout ce qui aurait dû se passer
pendant ces deux heures — généralement sans conséquence, parfois non.

- `->stateful($cache)` persiste le point de contrôle dans un cache pool, donc un worker
  redémarré sait où il en était et rejoue ce qu'il a manqué.
- `->processOnlyLastMissedRun(true)` (Symfony 7.2+) plafonne ce replay à une seule
  exécution. Sans ça, un worker qui redémarre après une longue panne déclenche toutes les
  occurrences manquées d'un coup — pour une tâche toutes les dix minutes et une journée
  d'indisponibilité, ça fait 144 messages d'un seul coup.

Combine les deux, sauf besoin spécifique de rejouer chaque exécution manquée. Et note que
c'est exactement la situation pour laquelle la règle 2 existe : la tâche rejouée doit
pouvoir être exécutée à nouveau sans risque.

## Verrouillage

`->lock()` empêche deux workers d'exécuter le même schedule en même temps — nécessaire
dès que `numprocs` dépasse 1 ou que deux serveurs font chacun tourner un worker. Ça
nécessite `symfony/lock` avec un store **partagé** : un `FlockStore` sur des machines
séparées ne verrouille rien.

Verrouiller la *tâche* est une préoccupation différente qui relève de la commande qu'elle
appelle ; voir `symfony-proglab-console` pour `LockableTrait` et pourquoi un job de six
minutes planifié toutes les cinq minutes finit par mettre le serveur à genoux.

## Le faire tourner

```bash
php bin/console debug:scheduler                    # chaque schedule, tâche et prochaine date d'exécution
php bin/console messenger:consume scheduler_default --time-limit=3600 --memory-limit=128M
```

Le nom du transport est toujours `scheduler_` plus le nom du schedule. Donne-lui sa
propre entrée de superviseur plutôt que de le fusionner dans le worker `async` : le
transport du scheduler est un générateur qui émet sur un timer, et le mélanger avec une
file qui a parfois mille messages en attente retarde le timer derrière eux.

`debug:scheduler` prend un nom de schedule en argument, `--all` pour inclure les
messages récurrents terminés, `--date` pour calculer les prochaines exécutions à partir
d'un instant donné, et `--sort` (8.1) pour trier par prochaine date d'exécution.

## Tester une tâche planifiée

La méthode de la tâche est une couche de traduction, donc le test qui compte porte sur le
service qu'elle appelle, exactement comme pour une commande console. Deux choses
méritent quand même d'être vérifiées :

```php
// La tâche délègue, et ne porte rien.
$archiver = $this->createMock(ShelfArchiver::class);
$archiver->expects(self::once())->method('archiveAbandoned');

(new ShelfMaintenance($archiver))->archiveAbandonedShelves();
```

```php
// Le schedule déclare ce que tu penses qu'il déclare.
$schedule = self::getContainer()->get(MaintenanceSchedule::class)->getSchedule();

self::assertCount(2, $schedule->getRecurringMessages());
```

Le second est un cas de vérification par mutation issu du standard de tests : l'attribut
ou l'appel à `add()` est déclaratif, donc il n'y a pas d'étape « rouge d'abord ». Change
l'expression, observe le test passer au rouge, remets-la — c'est la preuve que le test
mesure bien quelque chose.

Ne teste pas que le trigger se déclenche à 03h00. Ça, c'est le `CronExpressionTrigger` de
Symfony, il est déjà testé, et réimplémenter l'horloge dans ta suite n'apporte rien.

## Scheduler ou crontab

Utilise Scheduler quand le projet fait déjà tourner des workers Messenger sous un
superviseur — ce qui, sur ce standard, est le cas dès que quelque chose est asynchrone.
Le schedule voyage alors avec le code, apparaît en revue, et est identique dans chaque
environnement.

Garde une crontab quand le job récurrent doit tourner que les workers de l'application
soient en bonne santé ou non : rotation des logs, sauvegardes, un watchdog qui vérifie
les workers eux-mêmes. Un scheduler ne peut pas superviser le processus dont il dépend.
