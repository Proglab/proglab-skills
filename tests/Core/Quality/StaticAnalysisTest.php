<?php

declare(strict_types=1);

namespace App\Tests\Core\Quality;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHPStan attrape réellement, avec la configuration livrée.
 *
 * Sans ce test, la porte ne prouve que sa propre existence : `phpstan.dist.neon` peut
 * pointer un `containerXmlPath` faux, oublier une extension, ou retomber à un niveau
 * bas, et dans les trois cas l'outil ne proteste pas — il se tait et sort en 0, ce qui
 * est pire qu'échouer. Deux défauts injectés dans un bac à sable, l'un que le niveau 0
 * voit et l'autre que seul le niveau max voit, mesurent le niveau réellement appliqué.
 */
final class StaticAnalysisTest extends TestCase
{
    use SandboxTrait;

    private const string CLEAN = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Tests\Fixtures\Quality;

        final readonly class Probe
        {
            public function length(string $value): int
            {
                return \strlen($value);
            }
        }
        PHP;

    #[Test]
    public function the_delivered_configuration_accepts_a_sound_file(): void
    {
        [$exitCode, $output] = self::analyse($this->sandboxWith(['src/Probe.php' => self::CLEAN]).'/src/Probe.php');

        self::assertSame(0, $exitCode, $output);
    }

    #[Test]
    #[DataProvider('defects')]
    public function phpstan_refuses(string $code, string $expected): void
    {
        [$exitCode, $output] = self::analyse($this->sandboxWith(['src/Defect.php' => $code]).'/src/Defect.php');

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString($expected, $output);
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function defects(): Generator
    {
        yield 'un type de retour qui ment' => [
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Tests\Fixtures\Quality;

                final readonly class Defect
                {
                    public function count(): int
                    {
                        return 'pas un entier';
                    }
                }
                PHP,
            'should return int but returns string',
        ];

        // Le témoin du niveau. `mixed` passé à un paramètre typé n'est signalé qu'à
        // partir du niveau 9 : si ce cas passe, la configuration a glissé sous `max`
        // sans que rien d'autre ne le montre.
        yield 'un mixed que seul le niveau max refuse' => [
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Tests\Fixtures\Quality;

                final readonly class Defect
                {
                    public function length(mixed $value): int
                    {
                        return \strlen($value);
                    }
                }
                PHP,
            'expects string, mixed given',
        ];

        // Les deux défauts ci-dessus sont des règles du **cœur** de PHPStan : les quatre
        // `includes:` de jeux de règles peuvent disparaître de `phpstan.dist.neon` sans
        // qu'aucun d'eux ne bronche — mesuré, pas supposé. Les deux suivants n'existent
        // que par une extension, donc ils épinglent les includes eux-mêmes.
        yield 'une condition non booléenne, que seul phpstan-strict-rules refuse' => [
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Tests\Fixtures\Quality;

                final readonly class Defect
                {
                    public function label(string $value): string
                    {
                        if ($value) {
                            return 'oui';
                        }

                        return 'non';
                    }
                }
                PHP,
            'Only booleans are allowed in an if condition',
        ];

        yield 'un appel déprécié, que seul phpstan-deprecation-rules refuse' => [
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Tests\Fixtures\Quality;

                final class Legacy
                {
                    /**
                     * @deprecated remplacé par value()
                     */
                    public function old(): int
                    {
                        return 1;
                    }
                }

                final readonly class Defect
                {
                    public function run(Legacy $legacy): int
                    {
                        return $legacy->old();
                    }
                }
                PHP,
            'Call to deprecated method old()',
        ];
    }

    /**
     * Le chemin est passé en argument : PHPStan lui donne la priorité sur `paths`, si
     * bien que le fichier analysé est celui du bac à sable et la configuration celle qui
     * est livrée — includes, extensions, niveau et `containerXmlPath` compris.
     *
     * @return array{int, string}
     */
    private static function analyse(string $file): array
    {
        self::warmContainer();

        return self::execute([
            \PHP_BINARY,
            self::binary(['vendor/phpstan/phpstan/phpstan', 'vendor/bin/phpstan']),
            'analyse',
            '--configuration=phpstan.dist.neon',
            '--memory-limit=1G',
            '--no-progress',
            '--no-ansi',
            '--error-format=raw',
            $file,
        ], self::projectDir());
    }

    /**
     * L'extension Symfony lit le conteneur compilé pour résoudre les ids de service et
     * les noms de route. Sans lui, la moitié des règles se taisent — et
     * `phpstan.dist.neon` référence le fichier, donc l'analyse échouerait ici pour la
     * mauvaise raison. C'est ce que fait la cible `stan` du `Makefile`.
     */
    private static function warmContainer(): void
    {
        $compiled = glob(self::projectDir().'/var/cache/dev/*KernelDevDebugContainer.xml');

        if (\is_array($compiled) && [] !== $compiled) {
            return;
        }

        [$exitCode, $output] = self::execute([\PHP_BINARY, 'bin/console', 'cache:warmup', '--env=dev'], self::projectDir());

        self::assertSame(0, $exitCode, $output);
    }
}
