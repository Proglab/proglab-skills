<?php

declare(strict_types=1);

namespace App\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

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
            (new Filesystem())->remove($this->sandbox);
            $this->sandbox = null;
        }
    }

    #[Test]
    public function the_delivered_code_base_has_no_layer_violation(): void
    {
        [$exitCode, $output] = self::deptrac(self::projectDir(), reportUncovered: true);

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * Le rapport « uncovered » liste les dépendances qu'aucune couche ne juge. Y voir une
     * classe à nous, c'est un répertoire de `src/` que personne n'a collecté : rien de ce
     * qu'il contient n'est vérifié. Les dépendances vendor y sont normales et attendues.
     */
    #[Test]
    public function no_class_of_ours_escapes_every_layer(): void
    {
        [, $output] = self::deptrac(self::projectDir(), reportUncovered: true);

        $report = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);

        $escaping = [];

        foreach ($report['files'] ?? [] as $file) {
            foreach ($file['messages'] ?? [] as $message) {
                if (preg_match('/uncovered dependency on (App\\\\\S+)/', $message['message'], $matches)) {
                    $escaping[] = $matches[1];
                }
            }
        }

        self::assertSame([], array_values(array_unique($escaping)), $output);
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
     * @return \Generator<string, array{array<string, string>, string}>
     */
    public static function forbiddenDependencies(): \Generator
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
