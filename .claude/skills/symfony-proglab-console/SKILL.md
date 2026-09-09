---
name: symfony-proglab-console
description: >-
  Écris les commandes console Symfony comme de fines couches de traduction au-dessus d'un
  service : une classe par famille de commandes avec #[AsCommand] au niveau méthode sur
  Symfony 8.1, des commandes invocables avec #[Argument] et #[Option] avant ça, une sortie
  via SymfonyStyle, un dry-run par défaut avec --force sur tout ce qui est destructif, un
  verrouillage obligatoire sur les commandes longues ou planifiées, un arrêt propre sur
  SIGTERM, et un smoke test avec runCommand ou CommandTester. Utilise ce skill dès que
  quelqu'un demande d'ajouter une commande, d'écrire un script, de rendre quelque chose
  exécutable depuis le terminal, de construire un import, un export ou un batch job, de
  nettoyer, purger ou supprimer de vieilles données, de faire un backfill sur une colonne,
  d'envoyer un digest, « run this once », « j'ai besoin d'une CLI pour ça », « make:command »,
  signale qu'une commande time out ou consomme toute la mémoire ou a supprimé plus qu'elle
  n'aurait dû, ou demande comment tester une commande. Pour planifier l'exécution de cette
  commande chaque nuit, et pour les workers Messenger, utilise symfony-proglab-async à la
  place.
---

# Commandes console

> **Niveau : socle dès qu'on écrit une commande** — et dans ce cadre, le dry-run par défaut et le verrou ne sont pas optionnels sur une commande destructive ou planifiée. Le reste est une commande comme une autre.

Une commande est une couche de traduction, exactement comme un contrôleur. Elle analyse
l'entrée, appelle **un seul** service, met en forme la sortie. C'est tout son travail.

Ce n'est pas une question d'ordre. Une règle qui vit dans `__invoke()` ne peut être
exercée qu'en démarrant une console, en construisant une entrée et en relisant du texte
dans un buffer ; la même règle dans un service se teste en appelant une méthode et en
vérifiant la valeur de retour. Toute décision métier laissée dans une commande est une
décision qu'on ne pourra jamais tester autrement qu'à travers une chaîne de caractères.

```php
#[AsCommand(name: 'app:shelf:purge', description: 'Remove abandoned shelves')]
public function purge(
    SymfonyStyle $io,
    #[Option(description: 'Actually delete, instead of reporting')] bool $force = false,
): int {
    $report = $this->shelfPurger->purge(dryRun: !$force);   // le seul appel

    $io->table(['Shelf', 'Books'], $report->rows());
    $io->success(\sprintf('%d shelves %s.', $report->count, $force ? 'deleted' : 'concerned'));

    return Command::SUCCESS;
}
```

## La forme, selon la version

`vendor/` fait foi avant tout ce qui est écrit ici — vérifie quelles formes existent avant
de choisir.

| Symfony | Écris ceci |
|---|---|
| **8.1+** | Une classe par **famille** de commandes, une méthode publique par commande, `#[AsCommand]` sur chaque méthode |
| **7.3 – 8.0** | Une classe **invocable** par commande, `#[AsCommand]` sur la classe, `__invoke()` avec `#[Argument]` / `#[Option]` |
| **6.4 – 7.2** | Une classe par commande héritant de `Command`, `#[AsCommand]` sur la classe, `configure()` + `execute()` |

La forme famille est l'objectif : `app:user:create` et `app:user:delete` partagent un
constructeur, partagent leurs conventions d'entrée, et se lisent ensemble.

```php
final class UserCommands
{
    public function __construct(private readonly UserRegistrar $registrar) {}

    #[AsCommand(name: 'app:user:create', description: 'Create a user')]
    public function create(SymfonyStyle $io, #[Argument] string $email): int { /* … */ }

    #[AsCommand(name: 'app:user:delete', description: 'Delete a user')]
    public function delete(SymfonyStyle $io, #[Argument] string $email): int { /* … */ }
}
```

L'autoconfiguration tague le service une fois par méthode attribuée et le conteneur
construit un `Command` par méthode. La classe **ne doit pas** hériter de `Command` —
mélanger les deux lève une erreur à la compilation — et les méthodes doivent être
publiques et non statiques.

**`extends Command` n'est pas dépréciée.** Elle fonctionne toujours en 8.1, et
`configure()` / `execute()` ne sont pas marquées pour suppression. C'est simplement ce
qu'on n'écrit plus pour du nouveau code — et elle reste la bonne réponse pour la seule
chose que les commandes invocables ne savent pas faire (les signaux, plus bas). Les trois
formes côte à côte, plus la table complète d'inférence des entrées :
`references/writing-commands.md`.

## L'entrée est déduite de la signature

Les définitions d'arguments et d'options viennent des types de paramètres et de leurs
valeurs par défaut. Deux surprises :

- **Les noms sont convertis en kebab-case.** `int $bookId` devient `<book-id>` sur la
  ligne de commande, et `?string $olderThan` devient `--older-than`. Rien ne t'avertit.
- **Un paramètre d'option doit avoir une valeur par défaut**, sinon le conteneur lève une
  exception. C'est ce qui en fait une option plutôt qu'un argument.

```php
#[Argument] array $shelves = [],            // <shelves>...  (variadique)
#[Option] ?string $olderThan = null,        // --older-than=OLDER-THAN
#[Option] int $batchSize = 500,             // --batch-size=BATCH-SIZE [default: 500]
#[Option] bool $force = false,              // --force
#[Option] bool $report = true,              // --report|--no-report   (négatable)
```

`SymfonyStyle`, `InputInterface`, `OutputInterface`, `Application` et `Cursor` sont
injectés par type — ne les déclare pas comme arguments, et ne construis pas de
`SymfonyStyle` à la main. Retourne toujours un `int` : `Command::SUCCESS` (0),
`Command::FAILURE` (1), `Command::INVALID` (2). Tout autre type lève un `TypeError`.

## Commandes destructives : dry-run par défaut

**Une commande qui supprime, tronque, anonymise ou écrase se contente de rapporter par
défaut, et n'agit qu'avec `--force`.** Sans `--force`, elle affiche ce qu'elle ferait et
sort avec `SUCCESS` — un dry-run qui a réussi est un succès.

Ce n'est délibérément *pas* une confirmation interactive. `$io->confirm()` est ignorée dès
que `--no-interaction` est passé, ce qui est exactement la façon dont la CI, les scripts de
déploiement et les planificateurs font tourner tout le reste. Un prompt protège un humain
devant un terminal et personne d'autre ; `--force` protège les deux, parce que le chemin
sûr est celui par défaut.

**Affiche les comptages avant et après, issus de deux requêtes distinctes.** Un écart
entre `Shelves concerned: 1240` et `Shelves deleted: 1240` est la seule chose qui révèle
une suppression partielle silencieuse — une clé étrangère qui a avalé des lignes, un batch
qui s'est arrêté trop tôt, un filtre qui a changé entre le comptage et la suppression.
Afficher deux fois la même variable ne prouve rien.

Le patron complet — signature du service, commande, et les tests couvrant les deux
branches — se trouve dans `references/destructive-and-scheduled.md`.

## Le verrouillage est obligatoire sur les commandes longues ou planifiées

Une tâche qui prend six minutes et qui est planifiée toutes les cinq s'empile. À la fin de
la journée, des centaines de copies se disputent les mêmes lignes, et le symptôme est un
serveur qui meurt plutôt qu'une commande qui échoue.

`LockableTrait` est fourni avec `symfony/console`, mais pas le composant de verrouillage —
`composer require symfony/lock`. Sans lui, le trait lève *"To enable the locking feature
you must install the symfony/lock component."* à l'exécution, dès la première exécution,
en production.

```php
if (!$this->lock('app:digest:send')) {
    $io->warning('Already running, skipping this run.');

    return Command::SUCCESS;      // pas FAILURE — un run sauté, c'est la conception qui fonctionne
}

try {
    // …
    return Command::SUCCESS;
} finally {
    $this->release();
}
```

Trois pièges. **`finally`, pas un `release()` en fin de méthode** — une exception qui
s'échappe laisse le verrou tenu, et si flock le libère quand le processus meurt, un store
en base de données ne le fait pas, donc le run suivant est bloqué jusqu'à l'expiration de
son TTL. **Nomme le verrou explicitement dans une famille de commandes**, parce que
`$this->lock()` sans argument lit l'attribut `#[AsCommand]` *sur la classe*, que la classe
famille n'a pas, et lève `Lock name missing`. Et **`LockableTrait` ignore `LOCK_DSN`** : il
construit son propre store sémaphore ou flock, tous deux locaux à une seule machine, ce
qui sur plus d'un serveur n'est pas un verrou — assigne le `LockFactory` autowiré à
`$this->lockFactory` dans le constructeur.

Planifier l'exécution de la commande elle-même (`#[AsCronTask]`, `#[AsPeriodicTask]`,
workers) relève de **symfony-proglab-async**. Le verrou est traité ici parce que c'est une
propriété de la commande.

## Commandes longues et mémoire

L'unit of work de Doctrine garde une référence vers chaque entité qu'elle hydrate, donc
une commande qui parcourt 500 000 lignes détient 500 000 objets jusqu'à sa fin. Ça ne fuit
pas lentement : ça croît linéairement, puis ça heurte `memory_limit`.

```php
foreach ($this->repository->iterateStaleReviews() as $i => $review) {   // toIterable()
    $this->archiver->archive($review);

    if (0 === $i % 500) {
        $em->flush();
        $em->clear();       // abandonne tout ce qui a été hydraté jusque-là
    }
}
```

`$em->clear()` détache **tout**, y compris les entités que tu détenais encore — donc
recharge après le clear, ne garde jamais de référence à travers cette frontière. La
requête appartient au repository (`toIterable()` sur un résultat de `QueryBuilder`), le
découpage en batches au service. Les pièges des fetch-join et le reste :
`references/destructive-and-scheduled.md`.

## Une sortie qui survit à une redirection vers un fichier

`SymfonyStyle` donne `title()`, `section()`, `success()`, `warning()`, `error()`,
`table()`, `progressIterate()`. Utilise-les ; ne réinvente pas des `writeln()` avec des
tirets faits main.

La règle qui compte : **la sortie d'une commande planifiée est lue dans un fichier de log,
des semaines plus tard, par quelqu'un qui ne sait pas ce qu'elle fait.** Affiche les
paramètres avec lesquels elle a tourné, affiche des comptages plutôt que des adjectifs,
mets les diagnostics derrière la verbosité :

```php
$io->writeln(\sprintf('Purging shelves older than %s (batch %d)', $olderThan, $batchSize));

if ($io->isVerbose()) {
    $io->writeln(\sprintf('  · shelf #%d (%d books)', $shelf->getId(), $count));
}
```

Une barre de progression dans un fichier de log est un mur de caractères de contrôle :
protège-la, ou exécute les invocations planifiées avec `--no-ansi`. Les erreurs partent
sur stderr via `$io->getErrorStyle()`, ce qui permet à l'appelant de séparer un rapport
d'un échec.

## Tests : teste le service unitairement, fais un smoke test de la commande

La commande ne porte aucune règle, donc il n'y a rien en elle qui mérite un test
approfondi. Ce qu'un smoke test prouve, c'est que le câblage tient : l'id du service se
résout, les arguments se bindent, le code de sortie est le bon.

```php
final class ShelfCommandsTest extends KernelTestCase
{
    #[Test]
    public function it_reports_without_deleting_by_default(): void
    {
        $result = self::runCommand('app:shelf:purge');           // Symfony 8.1+

        $this->assertCommandIsSuccessful($result);
        self::assertStringContainsString('1240 shelves concerned', $result->getDisplay());
    }
}
```

`runCommand()` vient de `ConsoleCommandAssertionsTrait`, que `KernelTestCase` utilise
déjà, et elle démarre le kernel pour toi. Avant 8.1, construis une
`Symfony\Bundle\FrameworkBundle\Console\Application` et utilise `CommandTester::execute()`
; les deux formes sont dans `references/writing-commands.md`.

**Les commandes destructives sont l'exception et méritent une vraie couverture** : le
dry-run ne touche pas la base de données, `--force` si, et les codes de sortie sont
vérifiés. Une commande est souvent le seul point d'entrée vers une opération
irréversible, et « on pensait être en dry-run » est une phrase qu'on entend après coup.

## Quand quelque chose tourne mal

| Symptôme | Cause |
|---|---|
| La commande n'apparaît pas dans `bin/console list` | La classe hérite de `Command` *et* utilise `#[AsCommand]` au niveau méthode, ou la méthode est privée ou statique |
| `The option ... must declare a default value` | Un paramètre `#[Option]` sans valeur par défaut. Une option est optionnelle par définition |
| `--my-option` non reconnu, `--my-option` dans le code | Les noms sont convertis en kebab-case depuis le paramètre : `$myOption` → `--my-option` |
| Un argument manquant sort en `1`, pas `2` | Les échecs de binding d'entrée sont `FAILURE`. `INVALID` est réservé à *ta* validation d'une valeur qui s'est correctement parsée |
| `LogicException: To enable the locking feature…` | `symfony/lock` n'est pas installé |
| `Lock name missing` | `$this->lock()` sans nom dans une famille de commandes au niveau méthode. Passe le nom |
| Deux copies tournent malgré le verrou | Le store par défaut de `LockableTrait` est local à la machine. Injecte le `LockFactory` configuré |
| `Allowed memory size exhausted` après N lignes | L'identity map de Doctrine. `toIterable()` plus un `clear()` périodique |
| SIGTERM tue la commande en pleine écriture | `SignalableCommandInterface` ne se déclenche pas sur les commandes invocables (voir la référence) ; utilise `extends Command` pour cette commande-là |
| Plus de lignes supprimées que rapporté | Comptage et suppression faits dans une seule requête, ou le filtre diffère entre les deux. Compte, agis, recompte |

## Fichiers de référence

| Fichier | Quand le lire |
|---|---|
| `references/writing-commands.md` | Pour écrire n'importe quelle commande : les trois formes selon la version, la table complète d'inférence des entrées, sortie et verbosité, signaux, les deux API de test |
| `references/destructive-and-scheduled.md` | Pour tout ce qui supprime ou écrase, tout ce qui est planifié ou long : le patron dry-run/`--force` de bout en bout, stores de verrouillage et pièges, mémoire Doctrine par batches, tester une commande irréversible |
