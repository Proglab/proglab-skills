# Écrire une commande

Les trois formes selon la version, comment l'entrée est déduite, quoi faire pour la sortie
et les signaux, et comment tester le résultat.

## Sommaire

- [Les trois formes](#les-trois-formes) · [Inférence des entrées](#inférence-des-entrées-en-détail) ·
  [Injection par type](#ce-qui-est-injecté-par-type) · [Codes de sortie](#codes-de-sortie) ·
  [Sortie et verbosité](#sortie-et-verbosité) ·
  [Signaux](#signaux--sarrêter-proprement) · [Tests](#tests)

## Les trois formes

Même commande, trois façons. Choisis selon ce que supporte le `vendor/` du projet.

### Symfony 8.1+ — une classe par famille, `#[AsCommand]` sur les méthodes

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\{Argument, AsCommand, Option};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ShelfCommands
{
    public function __construct(
        private readonly ShelfPurger $purger,
        private readonly ClockInterface $clock,
    ) {
    }

    #[AsCommand(name: 'app:shelf:purge', description: 'Remove abandoned shelves')]
    public function purge(
        SymfonyStyle $io,
        #[Option(description: 'Actually delete, instead of reporting')] bool $force = false,
    ): int {
        $report = $this->purger->purge($this->clock->now()->modify('-1 year'), dryRun: !$force);
        $io->success(\sprintf(
            '%d shelves %s.',
            $force ? $report->deleted : $report->concerned,   // deleted is 0 on a dry run
            $force ? 'deleted' : 'concerned',
        ));

        return Command::SUCCESS;
    }

    #[AsCommand(name: 'app:shelf:show', description: 'Show one shelf')]
    public function show(SymfonyStyle $io, #[Argument(description: 'Shelf id')] int $id): int
    {
        return Command::SUCCESS;
    }
}
```

Vérifié à la compilation du conteneur : la classe **ne doit pas** hériter de `Command`
(faire les deux lève *"cannot define a method command when it is a subclass of Command"*),
et les méthodes doivent être **publiques et non statiques**. Un objet `Command` est
construit par méthode attribuée, donc les deux sont des commandes indépendantes qui
partagent simplement un constructeur.

### Symfony 7.3 – 8.0 — une classe invocable par commande

```php
#[AsCommand(name: 'app:shelf:purge', description: 'Remove abandoned shelves')]
final class PurgeShelvesCommand
{
    public function __construct(
        private readonly ShelfPurger $purger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Actually delete')] bool $force = false,
    ): int {
        // corps identique
    }
}
```

Mêmes règles pour `#[Argument]` / `#[Option]` ; seul l'emplacement de `#[AsCommand]`
change. Grouper deux commandes dans une seule classe n'est pas possible avant 8.1 —
écris deux classes.

### Symfony 6.4 – 7.2 — `extends Command`

```php
#[AsCommand(name: 'app:shelf:purge', description: 'Remove abandoned shelves')]
final class PurgeShelvesCommand extends Command
{
    public function __construct(
        private readonly ShelfPurger $purger,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();          // mandatory, and easy to forget
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $report = $this->purger->purge($this->clock->now()->modify('-1 year'), dryRun: !$force);
        $io->success(\sprintf(
            '%d shelves %s.',
            $force ? $report->deleted : $report->concerned,   // deleted is 0 on a dry run
            $force ? 'deleted' : 'concerned',
        ));

        return Command::SUCCESS;
    }
}
```

Oublie `parent::__construct()` et tu obtiens une commande sans nom. Cette forme **n'est
pas dépréciée** en 8.1 — les commandes du framework lui-même l'utilisent encore. C'est
simplement plus de cérémonie pour le même résultat, avec la définition d'entrée écrite
deux fois : une fois dans `configure()`, une fois dans chaque appel `getOption()`, sans
rien qui vérifie qu'elles concordent.

## Inférence des entrées, en détail

Vérifié contre `Symfony\Component\Console\Attribute\Argument` et `…\Option`, et confirmé
en exécutant `--help`.

### Arguments

| Signature | Définition |
|---|---|
| `#[Argument] int $bookId` | requis `<book-id>` |
| `#[Argument] string $shelf = 'to-read'` | optionnel `[<shelf>]`, défaut affiché dans l'aide |
| `#[Argument] ?string $shelf` | optionnel (nullable compte comme optionnel) |
| `#[Argument] array $shelves = []` | `[<shelves>...]`, un tableau |
| `#[Argument] string ...$shelves` | pareil, variadique |
| `#[Argument] BookStatus $status` | enum backée : les valeurs deviennent des suggestions de complétion |

Les types non typés, union et intersection lèvent une exception. Les arguments requis
viennent en premier, comme en PHP.

### Options

| Signature | Définition |
|---|---|
| `#[Option] bool $force = false` | `--force`, un drapeau |
| `#[Option] bool $report = true` | `--report\|--no-report`, négatable |
| `#[Option] ?string $olderThan = null` | `--older-than=OLDER-THAN` |
| `#[Option] int $batchSize = 500` | `--batch-size=BATCH-SIZE [default: 500]` |
| `#[Option] array $tag = []` | `--tag=TAG`, répétable |
| `#[Option(shortcut: 'f')] bool $force = false` | ajoute `-f` |

Trois erreurs que le conteneur lève, avec des messages clairs : **pas de défaut**
(*"must declare a default value"* — une option est optionnelle par définition ; si elle
est requise c'est un argument), **nullable avec un défaut non nul** (doit être `= null`,
ou ne pas être nullable), et **`bool` nullable avec un défaut booléen** (ça n'a pas de
sens ; choisis l'un ou l'autre).

Les noms sont **convertis en kebab-case depuis le nom du paramètre**, pas depuis ce que tu
as écrit ailleurs : `$olderThan` devient `--older-than`. Passe `name:` explicitement quand
tu as besoin d'autre chose.

Au-delà de ça : `#[MapInput]` (7.4+) bind un DTO entier d'arguments et d'options avec des
contraintes du validator ; `#[Ask]`, `#[Interact]` (7.4+) et `#[AskChoice]` (8.1+) rendent
un paramètre interactif — utile pour un outil destiné aux développeurs, inutile pour tout
ce qu'un planificateur exécute.

## Ce qui est injecté par type

Dans une commande invocable ou une méthode de famille, ces types sont remplis directement,
sans attribut : `SymfonyStyle`, `InputInterface`, `RawInputInterface`, `OutputInterface`,
`Application`, `Command`, `Cursor`. Tout le reste passe par le résolveur d'arguments.
Prends `SymfonyStyle` plutôt que d'en construire un — le faire toi-même fait perdre le
dispatcher d'événements.

## Codes de sortie

La méthode doit retourner un `int` — tout autre type lève un `TypeError` nommant la
commande.

- **`Command::SUCCESS`** (0) — elle a fait ce qui était demandé, *y compris* un dry-run qui
  n'a rien changé et un run verrouillé qui s'est sauté lui-même. Les deux sont le
  fonctionnement voulu.
- **`Command::FAILURE`** (1) — tentée et non aboutie. C'est aussi ce que le framework
  retourne pour un argument requis manquant et pour une exception non attrapée, donc un
  script englobant ne peut pas distinguer « mauvaise invocation » de « job échoué » avec le
  seul code de sortie.
- **`Command::INVALID`** (2) — *ta* validation a rejeté une valeur qui s'est correctement
  parsée : une date dans le futur, un shelf inconnu, une taille de batch de zéro.

## Sortie et verbosité

```php
$io->title('Purging shelves');
$io->section('Candidates');
$io->table(['Shelf', 'Books', 'Last activity'], $rows);
$io->definitionList(['Batch size' => 500], ['Dry run' => 'yes']);
foreach ($io->progressIterate($shelves) as $shelf) { /* … */ }
$io->success('1240 shelves deleted.');
$io->warning('3 shelves skipped: still referenced.');
$io->getErrorStyle()->error('Could not reach the search index.');
```

Ce qui revient à chaque niveau de verbosité : **normal** les paramètres utilisés, les
comptages, le résultat ; **`-v`** une ligne par élément traité ; **`-vv`** les timings, les
limites de batch, les compteurs de requêtes ; **`-vvv`** les payloads. `--silent` (7.2+)
supprime tout y compris les erreurs, `-q` presque tout — ne mets rien là que tu regretterais
de manquer. Protège avec `$io->isVerbose()`, `isVeryVerbose()`, `isDebug()` plutôt que de
passer `OutputInterface::VERBOSITY_*` à `writeln()` : un bloc protégé ne paie pas le coût
de construire un message qu'il n'affichera pas.

Deux habitudes qui payent une fois que la sortie finit dans un fichier de log. **Affiche
les paramètres effectifs sur la première ligne** — des mois plus tard, ce log est le seul
enregistrement de ce que le planificateur a réellement passé. Et **les erreurs sur
stderr** via `getErrorStyle()`, ce qui permet à `command > report.txt` de garder le
rapport tout en laissant l'échec remonter.

## Signaux : s'arrêter proprement

Une commande qu'un déploiement va tuer avec `SIGTERM` en pleine exécution doit terminer
son unité de travail en cours plutôt que mourir entre un `persist()` et un `flush()`.

```php
#[AsCommand(name: 'app:reviews:archive')]
final class ArchiveReviewsCommand extends Command implements SignalableCommandInterface
{
    private bool $shouldStop = false;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->repository->iterateStale() as $review) {
            $this->archiver->archive($review);      // commits its own unit of work

            if ($this->shouldStop) {
                $io->warning('Stopped on signal, work committed.');

                return Command::SUCCESS;
            }
        }

        return Command::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        return [\SIGTERM, \SIGINT];
    }

    public function handleSignal(int $signal, int|false $previous = 0): int|false
    {
        $this->shouldStop = true;

        return false;      // false = do not exit now, let execute() finish its unit
    }
}
```

**Ça doit être la forme `extends Command`.** Vérifié sur Symfony 8.1.5 : une commande
*invocable* qui implémente `SignalableCommandInterface` ne voit jamais `handleSignal()`
appelée. Le compiler pass enveloppe le service dans une `Closure` avant `setCode()`, et
`InvokableCommand` décide si une commande est signalable par un `instanceof` sur cette
closure — qu'une closure ne satisfait jamais, donc `getSubscribedSignals()` retourne `[]`.

Le mode d'échec est silencieux : sur `SIGTERM`, le processus sort avec le code **0** sans
message, parce que `ConsoleSignalEvent` a par défaut un code de sortie à `0` et que rien
ne l'a modifié. Une commande qui a l'air d'avoir fini et qui en réalité s'est arrêtée à
moitié. Vérifie `vendor/` avant de supposer que c'est toujours vrai ; jusqu'à ce que ça
change, une commande qui gère les signaux est la seule classe du projet qui hérite de
`Command`, et ça vaut un commentaire expliquant pourquoi.

## Tests

Le service porte les règles et reçoit les vrais tests (nominal, limites, erreurs) ; la
commande en reçoit un ou deux qui prouvent que le câblage tient.

### Symfony 8.1+ — `static::runCommand()`

`ConsoleCommandAssertionsTrait` est déjà utilisé par `KernelTestCase`, donc `WebTestCase`
aussi bien que `KernelTestCase` disposent de `runCommand()`, et elle démarre le kernel
elle-même.

```php
final class ShelfCommandsTest extends KernelTestCase       // no bootKernel() needed
{
    #[Test]
    public function it_reports_without_deleting(): void
    {
        $result = self::runCommand('app:shelf:purge', verbosity: OutputInterface::VERBOSITY_VERBOSE);

        $this->assertCommandIsSuccessful($result);
        self::assertStringContainsString('shelves concerned', $result->getDisplay());
    }
}
```

`runCommand(string $name, array $input = [], array $interactiveInputs = [], ?bool
$interactive = null, ?bool $decorated = null, ?int $verbosity = null, array $normalizers
= [])` retourne un `ExecutionResult` : `$statusCode` public en lecture seule, plus
`getDisplay()` (les deux flux combinés), `getOutput()` et `getErrorOutput()` séparément.

Assertions : `assertCommandIsSuccessful()`, `assertCommandFailed()`,
`assertCommandIsInvalid()`, `assertCommandResultEquals()` — des méthodes d'**instance**
qui prennent le résultat, donc `$this->assertCommandIsSuccessful($result)`, pas `self::`.

### Avant 8.1 — `CommandTester`

```php
self::bootKernel();

$application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
$tester = new CommandTester($application->find('app:shelf:purge'));

self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
$tester->assertCommandIsSuccessful();
self::assertStringContainsString('deleted', $tester->getDisplay());
```

Utilise `Symfony\Bundle\FrameworkBundle\Console\Application`, pas celle du composant : le
constructeur de celle du composant prend une chaîne de nom, donc lui passer le kernel
donne un `TypeError` qui ressemble à un bug du framework de test. Pour une commande qui
pose des questions, passe les réponses en second argument de `run()` (8.1+) ou via
`$tester->setInputs([...])` avant `execute()`. Isolation de la base de données et
fixtures : **symfony-proglab-testing**.
