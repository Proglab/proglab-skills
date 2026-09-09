# Commandes destructives, planifiées et longues

Là où une commande cesse d'être une commodité et commence à pouvoir causer un incident.
Une seule idée appliquée trois fois : rendre la chose dangereuse conditionnée à un acte
délibéré, rendre la chose concurrente impossible, rendre la chose longue bornée.

**Sommaire :** [dry-run et `--force`](#dry-run-par-défaut---force-pour-agir) ·
[pourquoi pas une confirmation](#pourquoi-pas-une-confirmation-interactive) ·
[compter](#compter-avant-et-après) · [exemple complet](#lexemple-complet) ·
[le tester](#tester-une-commande-irréversible) · [verrouillage](#verrouillage) ·
[stores de verrou](#choisir-un-store-de-verrou) · [mémoire](#commandes-longues-et-mémoire-doctrine)

## Dry-run par défaut, `--force` pour agir

Toute commande qui supprime, tronque, anonymise, écrase, renvoie ou met à jour en masse
tourne en **mode rapport** sauf si `--force` est passé : elle calcule exactement ce
qu'elle ferait, l'affiche, ne change rien, et sort avec `Command::SUCCESS`.

Le défaut compte plus que le drapeau, parce que l'invocation dangereuse est celle à
laquelle personne n'a pensé — quelqu'un qui essaie la commande pour la première fois, une
ligne copiée-collée dans un runbook, une entrée de planificateur écrite de mémoire. Tous
ces cas produisent la forme courte, donc la forme courte doit être la sûre. `--dry-run` en
opt-in est le même mécanisme pointé dans le mauvais sens : il protège la personne qui s'en
est souvenue, la seule qui n'avait pas besoin de protection.

## Pourquoi pas une confirmation interactive

`$io->confirm('Delete 1240 shelves?')` a l'air plus solide et l'est moins. `--no-interaction`
/ `-n` la court-circuite, et ce drapeau est présent dans chaque job CI, script de
déploiement et tâche Ansible, parce que sans lui une commande qui prompte reste bloquée
indéfiniment. Un run planifié n'a aucun TTY du tout : la question n'est pas posée, la
réponse par défaut est prise, personne ne voit rien. Et ça ne se teste pas de façon
significative : vérifier qu'un prompt est apparu ne dit rien de ce qui se passe quand le
prompt est sauté.

`--force` survit aux trois : présent sur la ligne de commande, il est visible dans
l'historique du shell, dans la définition du planificateur, dans la ligne de log que la
commande imprime sur ses propres paramètres, et dans le test qui vérifie que rien n'a été
supprimé sans lui. Le prompt reste correct comme couche *supplémentaire* sur une commande
destinée à un humain :

```php
if ($force && $io->isInteractive() && !$io->confirm(\sprintf('Delete %d shelves?', $count), false)) {
    $io->warning('Aborted.');

    return Command::SUCCESS;
}
```

Note le défaut `false` et le garde-fou `isInteractive()` : de la friction pour un humain,
invisible pour une machine.

## Compter avant et après

Affiche deux nombres, obtenus par deux requêtes indépendantes autour de l'opération.

```
Shelves concerned : 1240
Shelves deleted   : 1240
```

Ce qui compte, c'est l'*écart*, pas le nombre. Des causes réelles, toutes silencieuses :
une clé étrangère avec un comportement `ON DELETE` qui a retiré plus que ce que le filtre
de la commande sélectionnait ; une boucle par batch qui s'est arrêtée tôt sur une exception
avalée par un `catch` ; un filtre évalué deux fois à des moments différents ; une cascade
`orphanRemoval` que personne n'avait en tête. Aucun de ces cas ne lève d'erreur.

Afficher une variable deux fois ne prouve rien ; afficher `count()` puis le nombre de
lignes affectées réellement retourné par l'opération est ce qui rend la divergence
visible. Compte dans le service, dans la même transaction que la suppression, et retourne
les deux nombres dans un petit objet résultat — alors « concerned égale deleted » devient
une assertion testable unitairement plutôt que quelque chose qu'un humain doit remarquer
dans un log.

## L'exemple complet

**Écrit pour Symfony 8.1.** `#[AsCommand]` au niveau méthode, `#[Option]`,
`self::runCommand()` et les assertions `ExecutionResult` sont toutes des ajouts de 8.1. Le
*patron* est inchangé sur toutes les versions supportées — dry-run par défaut, `--force`
pour agir, deux comptages issus de deux requêtes, les deux branches testées ; seule la
plomberie change. En 7.3–8.0, écris la commande comme une classe invocable ; en 6.4–7.2,
comme `extends Command` avec `configure()`/`execute()` ; et teste avec `CommandTester`
plutôt que `runCommand()`. Les trois formes sont côte à côte dans `writing-commands.md`.

```php
final readonly class PurgeReport
{
    public function __construct(public int $concerned, public int $deleted, public bool $dryRun) {}

    public function isConsistent(): bool
    {
        return $this->dryRun ? 0 === $this->deleted : $this->concerned === $this->deleted;
    }
}

final class ShelfPurger
{
    public function __construct(
        private readonly ShelfRepository $shelves,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function purge(\DateTimeImmutable $before, bool $dryRun = true): PurgeReport
    {
        if ($dryRun) {
            return new PurgeReport($this->shelves->countAbandonedBefore($before), 0, true);
        }

        // Les deux instructions dans une seule transaction, ce qui donne un sens à
        // l'écart entre elles : une ligne insérée entre le COUNT et le DELETE
        // apparaîtrait sinon comme un écart qui n'est pas un bug.
        //
        // Note : pas de flush() ici — un DELETE en masse est exécuté immédiatement
        // par l'exécuteur DQL et retourne son nombre de lignes affectées. Rien n'est
        // mis en file dans l'unit of work pour qu'un flush() l'écrive.
        return $this->em->wrapInTransaction(function () use ($before): PurgeReport {
            $concerned = $this->shelves->countAbandonedBefore($before);
            $deleted = $this->shelves->deleteAbandonedBefore($before);   // affected rows

            return new PurgeReport($concerned, $deleted, false);
        });
    }
}

final class ShelfCommands
{
    use LockableTrait;

    public function __construct(
        private readonly ShelfPurger $purger,
        private readonly ClockInterface $clock,
        LockFactory $lockFactory,
    ) {
        $this->lockFactory = $lockFactory;
    }

    #[AsCommand(name: 'app:shelf:purge', description: 'Delete inactive shelves (reports unless --force)')]
    public function purge(
        SymfonyStyle $io,
        #[Option(description: 'Delete for real. Without it, the command only reports.')] bool $force = false,
        #[Option(description: 'Consider shelves untouched for this many days')] int $days = 365,
    ): int {
        if (!$this->lock('app:shelf:purge')) {
            $io->warning('Another purge is running; skipping this run.');

            return Command::SUCCESS;
        }

        try {
            $before = $this->clock->now()->modify(\sprintf('-%d days', $days));
            $io->writeln(\sprintf('Shelves untouched since %s — mode: %s',
                $before->format('Y-m-d'), $force ? 'DELETE' : 'dry run'));

            $report = $this->purger->purge($before, dryRun: !$force);

            $io->definitionList(
                ['Shelves concerned' => (string) $report->concerned],
                ['Shelves deleted' => (string) $report->deleted],
            );

            if (!$report->isConsistent()) {
                $io->getErrorStyle()->error(\sprintf('%d concerned but %d deleted — investigate.',
                    $report->concerned, $report->deleted));

                return Command::FAILURE;         // the gap is a failure, not a note
            }

            $force
                ? $io->success(\sprintf('%d shelves deleted.', $report->deleted))
                : $io->note(\sprintf('%d would be deleted. Rerun with --force.', $report->concerned));

            return Command::SUCCESS;
        } finally {
            $this->release();
        }
    }
}
```

Tout ce qui se décide est dans `ShelfPurger` et `PurgeReport`, testable sans console. La
commande choisit un message et un code de sortie.

## Tester une commande irréversible

L'exception à « une commande n'a droit qu'à un smoke test » : elle est souvent le seul
point d'entrée vers une opération sans retour en arrière, donc le garde-fou mérite un
test — pas le message, le garde-fou.

```php
#[Test]
public function dry_run_deletes_nothing(): void
{
    $before = $this->countShelves();
    $result = self::runCommand('app:shelf:purge');

    $this->assertCommandIsSuccessful($result);
    self::assertSame($before, $this->countShelves());          // the assertion that matters
    self::assertStringContainsString('Rerun with --force', $result->getDisplay());
}

#[Test]
public function force_deletes_exactly_what_it_announced(): void
{
    $survivor = $this->getReference('shelf_active', Shelf::class)->getId();
    $result = self::runCommand('app:shelf:purge', ['--force' => true]);

    $this->assertCommandIsSuccessful($result);
    self::assertSame(0, $this->countAbandonedShelves());
    self::assertNotNull($this->shelves->find($survivor));      // nothing else touched
}
```

Trois assertions portent le poids : **rien n'a changé** sans `--force`, **seules les
lignes ciblées ont changé** avec, et le code de sortie. Vérifier le libellé du message de
succès est du bruit — il sera reformulé et le test échouera pour rien. L'assertion « rien
d'autre n'a été touché » est celle qu'on saute et celle qui attrape une cascade : sème une
ligne qui doit survivre, et vérifie qu'elle a survécu.

## Verrouillage

Une commande planifiée toutes les cinq minutes qui prend parfois six minutes n'échoue
pas — elle se chevauche. Deux runs suppriment les mêmes lignes, envoient deux fois le même
digest, ou se bloquent mutuellement en deadlock. Chaque chevauchement est petit, donc le
symptôme visible arrive des heures plus tard sous forme d'épuisement mémoire ou de
connexions, loin de la cause.

`LockableTrait` vit dans `symfony/console`, mais le composant dont il a besoin n'est pas
fourni avec — `composer require symfony/lock`. Sans ça, le trait lève une exception à
l'exécution, dès la première exécution, avec *"To enable the locking feature you must
install the symfony/lock component."*

```php
if (!$this->lock('app:digest:send')) {
    $io->warning('Already running, skipping.');

    return Command::SUCCESS;
}

try {
    $this->digestSender->sendPending();

    return Command::SUCCESS;
} finally {
    $this->release();
}
```

**Retourne `SUCCESS`, pas `FAILURE`, quand le verrou est déjà tenu.** La commande a fait ce
qu'il fallait. Un échec ici fait alerter un planificateur surveillé en fonctionnement
normal, et les alertes qui se déclenchent en fonctionnement normal finissent par être
mises en sourdine.

**Libère dans un `finally`**, sinon une exception qui s'échappe laisse le verrou tenu. Avec
le store flock par défaut, l'OS le libère quand le processus meurt, ce qui masque
l'erreur ; avec un store base de données ou Redis, il ne le fait pas, et le run suivant est
bloqué jusqu'à l'expiration du TTL (`createLock()` a par défaut 300 secondes, rafraîchies
seulement tant qu'un processus vivant le fait).

**Nomme le verrou explicitement.** `$this->lock()` sans argument cherche `#[AsCommand]`
*sur la classe*. Une famille de commandes au niveau méthode n'en a pas, donc le trait lève
*"Lock name missing: provide it via LockableTrait::lock(), #[AsCommand] attribute, or by
extending Command class."* Passer le nom de la commande en littéral rend aussi le verrou
grep-able. Et n'utilise `blocking: true` que quand le second run doit finir par avoir lieu
— bloquer un planificateur, c'est justement comment on obtient l'empilement que le verrou
était censé empêcher.

## Choisir un store de verrou

`LockableTrait` **ne lit ni `framework.lock` ni `LOCK_DSN`**. Laissé seul, il construit son
propre `SemaphoreStore` si `sysvsem` est disponible, sinon un `FlockStore` — tous deux
locaux à une seule machine. Correct sur un serveur unique, faux partout ailleurs : deux
conteneurs applicatifs, deux hôtes cron, un déploiement rolling où ancienne et nouvelle
version se chevauchent brièvement, chacun avec son verrou privé n'excluant personne.

Le correctif est la ligne déjà présente dans l'exemple ci-dessus :
`$this->lockFactory = $lockFactory;`. Le trait ne construit sa propre factory que quand
`$this->lockFactory` est encore null, donc assigner le `LockFactory` autowiré
(`lock.default.factory`) prend le dessus. Il devient autowirable dès que `symfony/lock`
est installé ; la recette écrit `framework: lock: '%env(LOCK_DSN)%'` et `LOCK_DSN=flock`.

| Store | `LOCK_DSN` | À utiliser quand |
|---|---|---|
| Flock | `flock` | Une seule machine. Le défaut, et celui qui ne fait rien silencieusement sur un second hôte |
| Semaphore | `semaphore` | Une seule machine, `sysvsem` disponible |
| PostgreSQL advisory | `postgresql+advisory://…` | La base de données est déjà partagée — aucune nouvelle infrastructure, les verrous disparaissent avec la connexion |
| Redis | `redis://…` | Redis tourne déjà |

Le store advisory-lock correspond bien à ce standard : Messenger utilise déjà le transport
Doctrine, donc la base de données est la seule chose que tous les processus partagent.
Réutilise `DATABASE_URL` plutôt que d'introduire Redis pour un seul verrou.

Checklist : `symfony/lock` bien présent dans `composer.json` ; verrou acquis avant tout
travail, nom passé explicitement ; verrou tenu → `SUCCESS` plus un `warning()` ;
`release()` dans un `finally` ; `LockFactory` injecté dès que plus d'une machine exécute la
commande.

## Commandes longues et mémoire Doctrine

L'identity map garde chaque entité que l'unit of work a vue : sur un gros batch, ce n'est
pas une fuite mais une croissance linéaire, qui finit par `Allowed memory size exhausted`.
Deux leviers, utilisés ensemble :

```php
// Repository : la requête, comme toujours. toIterable() hydrate une ligne à la fois.
/** @return iterable<Review> */
public function iterateStaleReviews(\DateTimeImmutable $before): iterable
{
    return $this->createQueryBuilder('r')
        ->andWhere('r.updatedAt < :before')->setParameter('before', $before)
        ->getQuery()->toIterable();
}

// Service : le batching, pour que ce soit testable unitairement.
$processed = 0;

foreach ($this->reviews->iterateStaleReviews($before) as $review) {
    $this->archive($review);

    // ++$processed, pas la clé de boucle : toIterable() produit des clés
    // partant de 0, donc `0 === $i % 500` ferait flush et clear dès la toute
    // première itération — en détachant l'entité qu'on vient d'archiver avant
    // même qu'un batch se soit accumulé.
    if (0 === ++$processed % 500) {
        $this->em->flush();
        $this->em->clear();
    }
}

$this->em->flush();       // le dernier batch, partiel
$this->em->flush();
```

- **`clear()` détache tout**, y compris les entités que l'appelant détient encore. Une
  référence conservée à travers cette frontière devient un objet détaché qui cesse
  silencieusement de participer au `flush()`. Recharge par id ensuite, ou `detach()`
  l'entité isolément.
- **`toIterable()` ne fonctionne pas avec des collections en fetch-join** — elle hydrate
  ligne par ligne, et une relation `to-many` jointe s'étale sur plusieurs lignes. Récupère
  la collection séparément.
- **Le logger SQL garde chaque requête en `dev`.** Une commande longue exécutée là épuise
  la mémoire pour une raison sans rapport avec les entités. Utilise `--env=prod`, et ne te
  réfugie pas dans `memory_limit=-1` : ça transforme un échec borné en échec sans limite.
