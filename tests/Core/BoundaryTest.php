<?php

declare(strict_types=1);

namespace App\Tests\Core;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * La frontière des deux racines est vérifiable, pas promise (AD-7).
 *
 * Chaque cas de violation est monté dans un bac à sable jetable qui reçoit une copie
 * conforme de `deptrac.yaml` : le contrat testé est bien celui qui est livré, et le
 * dépôt réel ne contient jamais de classe fautive.
 */
final class BoundaryTest extends TestCase
{
    private const string CLEAN = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace %s;

        final readonly class %s
        {
        }
        PHP;

    private const string REACHING = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace %s;

        use %s;

        final readonly class %s
        {
            public function __construct(private %s $reached)
            {
            }
        }
        PHP;

    private ?string $sandbox = null;

    protected function tearDown(): void
    {
        if (null !== $this->sandbox) {
            new Filesystem()->remove($this->sandbox);
            $this->sandbox = null;
        }
    }

    // Le depot lui-meme n'est pas analyse ici : c'est le job « Layer contract » de la CI
    // qui le fait, et la cible `deptrac` du Makefile en local. Le dupliquer dans la suite
    // ferait rougir « Tests » en meme temps que « Layer contract » sur une seule et meme
    // violation, alors que la porte promet que le job de la categorie fautive echoue, et
    // lui seul. Le test « uncovered » ci-dessous reste, lui : le job deptrac se contente
    // de rapporter les classes non couvertes (--report-uncovered sans
    // --fail-on-uncovered), donc c'est le seul endroit qui echoue dessus.

    /**
     * Le rapport « uncovered » se lit **dans les deux sens**, et chacun attrape un défaut
     * que l'autre ne voit pas.
     *
     * *Côté dépendance* — `has uncovered dependency on App\…` : une classe à nous que
     * personne n'a collectée est **atteinte** par une autre. C'est un répertoire de `src/`
     * dont rien du contenu n'est vérifié. Les dépendances vendor y sont normales et
     * attendues, ce sont les nôtres qui ne le sont pas.
     *
     * *Côté source* — le `(Couche)` que le rapport accole au **dépendeur** : une classe
     * couverte par une couche technique porte deux messages pour la même dépendance,
     * `(Controller)` **et** `(Core)` ; une classe d'un dossier sans couche technique n'en
     * porte qu'un, celui de sa couche de racine. C'est ce déséquilibre qui est lu ici, et
     * la liste canonique des couches techniques est `ruleset.AnyLayer` de `deptrac.yaml`,
     * lue et non recopiée : une couche ajoutée sans y être nommée n'autorise plus rien
     * depuis une racine, donc elle ne peut pas compter comme une couverture.
     *
     * **Ce que le côté source ne voit pas, et que rien ne couvre encore.** Une classe qui
     * ne porte aucune dépendance *uncovered* — aucune classe vendor, ou seulement des
     * classes vendor déjà collectées — n'apparaît pas du tout dans le rapport, donc ce test
     * ne peut rien en dire. Un dossier neuf de `src/Core/` peuplé de telles classes
     * échappe donc au détecteur.
     *
     * Le seul relais existant est
     * `DerivationGuideTest::every_layer_directory_of_the_core_is_named_by_the_guide()`, et
     * il faut savoir exactement ce qu'il tient : il balaie les dossiers réellement présents
     * sous `src/Core/` et cherche la sous-chaîne `` `<Dossier>/` `` dans le Markdown du
     * guide. **Il ne lit ni les couches ni les rulesets.** Une ligne de tableau disant
     * « Aucune couche technique » suffit donc à le repasser au vert — c'est-à-dire à
     * reconstituer en une ligne de Markdown l'état que la story 1.16 vient de fermer.
     *
     * Le test qui manque confronterait les dossiers de `src/Core/` aux collecteurs de
     * `deptrac.yaml`. Il n'est pas écrit ici : il déborde le périmètre de la story, et il
     * est reporté.
     */
    #[Test]
    public function no_class_of_ours_escapes_every_layer(): void
    {
        [, $output] = self::deptrac(self::projectDir(), reportUncovered: true);

        self::assertSame([], self::uncoveredDependenciesOfOurs($output), $output);
        self::assertSame([], self::classesOutsideEveryTechnicalLayer(self::projectDir(), $output), $output);
    }

    /**
     * Le détecteur du côté source, mis à l'épreuve sur **les deux** cas qu'il doit séparer.
     *
     * Sans ce bac à sable, rien ne distingue « le dépôt est propre » de « le test ne
     * regarde pas » : le test précédent est vert dans les deux cas, et il l'a effectivement
     * été pendant quinze stories pendant que `Command/` et `EventListener/` passaient de 0
     * à 21 % de `src/`. `Serializer/` est choisi parce qu'il n'a pas de couche ici et n'en
     * aura pas — le fichier du skill le laisse de côté au même titre —, donc ce cas reste
     * rouge le jour où quelqu'un le rendrait vert en ajoutant une couche par erreur.
     *
     * `Contract/`, lui, ne doit **pas** être nommé, et c'est le piège que ce test ferme :
     * voir rougir une classe de la porte publique donne envie d'ajouter `CoreContract` à
     * `AnyLayer`, c'est-à-dire d'ouvrir la porte publique à toutes les couches techniques
     * et de détruire AD-2. Les deux sondes sont dans le **même** bac à sable pour que
     * l'assertion porte sur la liste exacte : l'une dedans, l'autre dehors.
     */
    #[Test]
    public function a_class_in_a_directory_without_a_technical_layer_is_named(): void
    {
        $sandbox = $this->sandboxWith([
            'src/Core/Serializer/Probe.php' => self::reaching(
                'App\Core\Serializer',
                'Psr\Log\LoggerInterface',
                'Probe',
                'LoggerInterface',
            ),
            'src/Core/Contract/Probe.php' => self::reaching(
                'App\Core\Contract',
                'Psr\Log\LoggerInterface',
                'Probe',
                'LoggerInterface',
            ),
        ]);

        [, $output] = self::deptrac($sandbox, reportUncovered: true);

        self::assertSame(
            ['App\Core\Serializer\Probe'],
            self::classesOutsideEveryTechnicalLayer($sandbox, $output),
            $output,
        );
    }

    /**
     * Les classes à nous qu'un rapport « uncovered » désigne comme **atteintes**.
     *
     * @return list<string>
     */
    private static function uncoveredDependenciesOfOurs(string $report): array
    {
        $escaping = [];

        foreach (self::messagesOf($report) as $text) {
            if (1 === preg_match('/uncovered dependency on (App\\\\\S+)/', $text, $matches)) {
                $escaping[] = $matches[1];
            }
        }

        return array_values(array_unique($escaping));
    }

    /**
     * Les classes à nous que le rapport annote **sans jamais nommer une couche qui juge**.
     *
     * @return list<string>
     */
    private static function classesOutsideEveryTechnicalLayer(string $root, string $report): array
    {
        $technical = [...self::technicalLayers($root), ...self::layersRefusingEverything($root)];

        $outside = [];

        foreach (self::layersByClass($report) as $class => $layers) {
            if ([] === array_intersect($layers, $technical)) {
                $outside[] = $class;
            }
        }

        sort($outside);

        return $outside;
    }

    /**
     * Par classe à nous, l'ensemble des couches dont le rapport l'annote.
     *
     * Le message du rapport est
     * `App\Core\Command\InitCommand has uncovered dependency on X (Core)` : le nom entre
     * parenthèses est la couche du **dépendeur**, pas celle de la dépendance, et le même
     * couple produit un message par couche à laquelle le dépendeur appartient.
     *
     * @return array<string, list<string>>
     */
    private static function layersByClass(string $report): array
    {
        $layers = [];

        foreach (self::messagesOf($report) as $text) {
            if (1 !== preg_match('/^(App\\\\\S+) has uncovered dependency on \S+ \((\w+)\)$/', $text, $matches)) {
                continue;
            }

            $layers[$matches[1]][$matches[2]] = true;
        }

        return array_map(static fn (array $found): array => array_keys($found), $layers);
    }

    /**
     * @return list<string>
     */
    private static function messagesOf(string $report): array
    {
        $decoded = json_decode($report, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $files = $decoded['files'] ?? [];
        self::assertIsArray($files);

        $messages = [];

        foreach ($files as $file) {
            $found = \is_array($file) ? $file['messages'] ?? [] : [];
            self::assertIsArray($found);

            foreach ($found as $message) {
                $text = \is_array($message) ? $message['message'] ?? null : null;

                if (\is_string($text)) {
                    $messages[] = $text;
                }
            }
        }

        return $messages;
    }

    /**
     * Les couches techniques, lues dans `ruleset.AnyLayer` et jamais recopiées ici.
     *
     * C'est la liste canonique : une couche absente d'`AnyLayer` n'est autorisée par
     * aucune couche de racine, donc elle ne couvre rien — la recopier ici ferait passer
     * pour couverte une classe que la première dépendance interne ferait échouer.
     *
     * @return list<string>
     */
    private static function technicalLayers(string $root): array
    {
        $node = Yaml::parseFile($root.'/deptrac.yaml');

        foreach (['deptrac', 'ruleset', 'AnyLayer'] as $key) {
            self::assertIsArray($node, '`deptrac.yaml` ne déclare plus `deptrac.ruleset.AnyLayer`.');

            $node = $node[$key] ?? null;
        }

        self::assertIsArray($node, '`deptrac.yaml` ne déclare plus `deptrac.ruleset.AnyLayer`.');

        return array_values(array_filter($node, \is_string(...)));
    }

    /**
     * Les couches dont le ruleset est `~`, c'est-à-dire qui n'autorisent **rien**.
     *
     * **L'exemption, et pourquoi elle n'est pas un trou.** `AnyLayer` est la liste des
     * couches *techniques*, et `CoreContract` n'en est pas une : c'est une couche de
     * *racine* dont le ruleset est vide, parce que la porte publique du socle ne dépend de
     * rien — ni Doctrine, ni HTTP, ni le reste du socle (AD-2). Une classe de
     * `src/Core/Contract/` n'a donc jamais de couche technique, et sans cette exemption le
     * détecteur la nommerait alors qu'elle est **plus** contrainte que n'importe quelle
     * autre, pas moins.
     *
     * **Le correctif à ne jamais faire**, et c'est pour l'éviter que l'exemption est ici :
     * ajouter `CoreContract` à `AnyLayer`. Ce serait ouvrir la porte publique à toutes les
     * couches techniques — Doctrine et HTTP compris — c'est-à-dire supprimer la frontière
     * que ce fichier existe pour tenir.
     *
     * `UndeclaredModule: ~` est exempté par la même lecture, et ce n'est pas gênant : une
     * classe qui y tombe est déjà nommée bruyamment par une violation, pas par ce test.
     *
     * @return list<string>
     */
    private static function layersRefusingEverything(string $root): array
    {
        $node = Yaml::parseFile($root.'/deptrac.yaml');

        foreach (['deptrac', 'ruleset'] as $key) {
            self::assertIsArray($node, '`deptrac.yaml` ne déclare plus `deptrac.ruleset`.');

            $node = $node[$key] ?? null;
        }

        self::assertIsArray($node, '`deptrac.yaml` ne déclare plus `deptrac.ruleset`.');

        $refusing = [];

        foreach ($node as $layer => $allowed) {
            if (null === $allowed && \is_string($layer)) {
                $refusing[] = $layer;
            }
        }

        return $refusing;
    }

    /**
     * Le filet. Un module posé sous une racine globée sans être déclaré dans
     * `deptrac.yaml` n'a que sa couche technique, dont le ruleset porte `+AnyRoot` — sans
     * la couche `UndeclaredModule`, il atteindrait le socle et les autres modules sans
     * qu'aucune règle ne s'y oppose, et deptrac sortirait en 0.
     */
    #[Test]
    public function a_module_nobody_declared_is_refused_everything(): void
    {
        [$exitCode, $output] = self::deptrac($this->sandboxWith([
            'src/Core/Service/Probe.php' => self::clean('App\Core\Service', 'Probe'),
            'src/Module/Stock/Service/Reaching.php' => self::reaching(
                'App\Module\Stock\Service',
                'App\Core\Service\Probe',
                'Reaching',
                'Probe',
            ),
        ]));

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('(UndeclaredModule on Core)', $output);
    }

    /**
     * @param array<string, string> $files
     */
    #[Test]
    #[DataProvider('forbiddenDependencies')]
    public function deptrac_refuses(array $files, string $rule): void
    {
        [$exitCode, $output] = self::deptrac($this->sandboxWith($files));

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString($rule, $output);
    }

    /**
     * @return Generator<string, array{array<string, string>, string}>
     */
    public static function forbiddenDependencies(): Generator
    {
        yield 'un module atteint une classe interne du socle' => [
            [
                'src/Core/Service/Probe.php' => self::clean('App\Core\Service', 'Probe'),
                'src/Module/Demo/Service/Reaching.php' => self::reaching(
                    'App\Module\Demo\Service',
                    'App\Core\Service\Probe',
                    'Reaching',
                    'Probe',
                ),
            ],
            '(ModuleDemo on Core)',
        ];

        yield 'le socle atteint un module' => [
            [
                'src/Module/Demo/Service/Probe.php' => self::clean('App\Module\Demo\Service', 'Probe'),
                'src/Core/Service/Reaching.php' => self::reaching(
                    'App\Core\Service',
                    'App\Module\Demo\Service\Probe',
                    'Reaching',
                    'Probe',
                ),
            ],
            '(Core on ModuleDemo)',
        ];

        yield 'un module atteint un autre module' => [
            [
                'src/Module/Billing/Service/Probe.php' => self::clean('App\Module\Billing\Service', 'Probe'),
                'src/Module/Demo/Service/Reaching.php' => self::reaching(
                    'App\Module\Demo\Service',
                    'App\Module\Billing\Service\Probe',
                    'Reaching',
                    'Probe',
                ),
            ],
            '(ModuleDemo on ModuleBilling)',
        ];

        yield 'un service construit une requête' => [
            [
                'src/Core/Service/Querying.php' => self::reaching(
                    'App\Core\Service',
                    'Doctrine\ORM\QueryBuilder',
                    'Querying',
                    'QueryBuilder',
                ),
            ],
            '(Service on Doctrine)',
        ];

        // Les quatre cas que les couches `Command`, `EventListener` et `TwigExtension`
        // viennent fermer. Avant elles, `deptrac` en rendait **zéro** sur chacun : ces
        // dossiers n'avaient aucune couche technique, donc aucune règle à violer.

        yield 'un écouteur construit une requête' => [
            [
                'src/Core/EventListener/Querying.php' => self::reaching(
                    'App\Core\EventListener',
                    'Doctrine\ORM\QueryBuilder',
                    'Querying',
                    'QueryBuilder',
                ),
            ],
            '(EventListener on Doctrine)',
        ];

        yield 'un écouteur écrit en base' => [
            [
                'src/Core/EventListener/Flushing.php' => self::reaching(
                    'App\Core\EventListener',
                    'Doctrine\ORM\EntityManagerInterface',
                    'Flushing',
                    'EntityManagerInterface',
                ),
            ],
            '(EventListener on EntityManager)',
        ];

        // Le dossier `src/Core/Twig/` est vide, donc **seul ce cas** tient sa couche : sans
        // lui, supprimer le bloc `TwigExtension` — ou n'y mettre qu'un collecteur qui ne
        // désigne aucun chemin réel — laisse la suite entièrement verte. Le rapport de
        // deptrac est en effet identique dans les trois configurations tant qu'aucune
        // classe ne vit sous ce chemin ; il faut donc en poser une.
        yield 'une extension Twig construit une requête' => [
            [
                'src/Core/Twig/Querying.php' => self::reaching(
                    'App\Core\Twig',
                    'Doctrine\ORM\QueryBuilder',
                    'Querying',
                    'QueryBuilder',
                ),
            ],
            '(TwigExtension on Doctrine)',
        ];

        // La requête courante n'est pas une donnée de gabarit : un runtime qui la lit fait
        // du contrôleur dans une extension.
        yield 'une extension Twig lit la requête' => [
            [
                'src/Core/Twig/Reading.php' => self::reaching(
                    'App\Core\Twig',
                    'Symfony\Component\HttpFoundation\Request',
                    'Reading',
                    'Request',
                ),
            ],
            '(TwigExtension on Http)',
        ];

        // La 1.11 a fait rendre une entité par un service à sa commande. Le type de retour
        // et le DTO `Dto/Output/` le corrigent ; cette ligne-ci empêche la prochaine.
        yield 'une commande tient une entité' => [
            [
                'src/Core/Entity/Probe.php' => self::clean('App\Core\Entity', 'Probe'),
                'src/Core/Command/Holding.php' => self::reaching(
                    'App\Core\Command',
                    'App\Core\Entity\Probe',
                    'Holding',
                    'Probe',
                ),
            ],
            '(Command on Entity)',
        ];

        // « Qui rend un gabarit à la main » : un contrôleur rend par `#[Template]`, et la
        // couche `Twig` n'est accordée qu'à `EventListener`, `TwigExtension` et `Service`.
        yield 'un contrôleur rend un gabarit à la main' => [
            [
                'src/Core/Controller/Rendering.php' => self::reaching(
                    'App\Core\Controller',
                    'Twig\Environment',
                    'Rendering',
                    'Environment',
                ),
            ],
            '(Controller on Twig)',
        ];
    }

    private static function clean(string $namespace, string $class): string
    {
        return \sprintf(self::CLEAN, $namespace, $class);
    }

    private static function reaching(string $namespace, string $reached, string $class, string $shortName): string
    {
        return \sprintf(self::REACHING, $namespace, $reached, $class, $shortName);
    }

    /**
     * @param array<string, string> $files
     */
    private function sandboxWith(array $files): string
    {
        $filesystem = new Filesystem();
        $this->sandbox = \sprintf('%s/var/boundary/%s', self::projectDir(), bin2hex(random_bytes(8)));

        $filesystem->mkdir([$this->sandbox.'/src/Module', $this->sandbox.'/tests/Fixtures/Module']);
        $filesystem->copy(self::projectDir().'/deptrac.yaml', $this->sandbox.'/deptrac.yaml');

        foreach ($files as $path => $contents) {
            $filesystem->dumpFile($this->sandbox.'/'.$path, $contents);
        }

        return $this->sandbox;
    }

    /**
     * `Process` avec son `cwd`, jamais un `cd` passé à un shell : sous Windows `cd` sans
     * `/d` ne change pas de volume, donc un bac à sable sur un autre disque ferait
     * analyser le vrai dépôt — et les quatre cas de violation passeraient à vide.
     *
     * @return array{int, string}
     */
    private static function deptrac(string $root, bool $reportUncovered = false): array
    {
        $command = [
            \PHP_BINARY,
            self::projectDir().'/vendor/deptrac/deptrac/deptrac',
            'analyse',
            '--config-file='.$root.'/deptrac.yaml',
            '--no-progress',
            '--no-ansi',
            // JSON plutôt que la table : la table tronque et enveloppe les messages
            // longs, et les assertions portent justement sur la fin du message —
            // le « (X on Y) » qui nomme la règle violée.
            '--formatter=json',
        ];

        if ($reportUncovered) {
            $command[] = '--report-uncovered';
        }

        $process = new Process($command, $root);
        $process->run();

        return [$process->getExitCode() ?? 1, $process->getOutput().$process->getErrorOutput()];
    }

    private static function projectDir(): string
    {
        return str_replace('\\', '/', \dirname(__DIR__, 2));
    }
}
