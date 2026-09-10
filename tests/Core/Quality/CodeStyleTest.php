<?php

declare(strict_types=1);

namespace App\Tests\Core\Quality;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * php-cs-fixer attrape réellement, avec la configuration livrée.
 *
 * Le style est la catégorie qu'on croit acquise parce qu'elle est ennuyeuse. Un finder
 * qui ne vise plus les bons répertoires, un jeu de règles retiré : dans les deux cas le
 * `--dry-run` sort en 0 et la porte paraît verte. Un défaut injecté dans un bac à sable
 * le montre — et le même bac à sable, une fois corrigé par l'outil, prouve que le
 * montage sait aussi sortir en 0.
 */
final class CodeStyleTest extends TestCase
{
    use SandboxTrait;

    #[Test]
    #[DataProvider('defects')]
    public function php_cs_fixer_refuses(string $code): void
    {
        $sandbox = $this->sandboxWithConfig(['src/Defect.php' => $code]);

        [$exitCode, $output] = self::fix($sandbox, dryRun: true);

        self::assertNotSame(0, $exitCode, $output);
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function defects(): Generator
    {
        yield 'un fichier sans typage strict' => [
            "<?php\n\nnamespace App\\Tests\\Fixtures\\Quality;\n\nfinal class Defect\n{\n}\n",
        ];

        yield 'un fichier mal indenté' => [
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests\\Fixtures\\Quality;\n\nfinal class Defect\n{\n        public function value() : int\n    {\n    return 1;\n    }\n}\n",
        ];

        yield 'un import inutilisé' => [
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests\\Fixtures\\Quality;\n\nuse Symfony\\Component\\Filesystem\\Filesystem;\n\nfinal class Defect\n{\n}\n",
        ];
    }

    /**
     * Le finder vise trois répertoires, et chacun doit être exercé.
     *
     * Tous les défauts ci-dessus vivent dans `src/`. Réduire le finder à `src/` seul
     * laisserait donc chaque assertion verte pendant que tout `tests/` — dix fichiers
     * livrés par cette story — et tout `migrations/` cesseraient d'être vérifiés, sur
     * les deux façades. C'est le « finder qui ne vise plus les bons répertoires » que le
     * docblock de cette classe annonce attraper.
     */
    #[Test]
    #[DataProvider('finderRoots')]
    public function php_cs_fixer_covers(string $root): void
    {
        $sandbox = $this->sandboxWithConfig([
            $root.'/Defect.php' => "<?php\n\nnamespace App\\Tests\\Fixtures\\Quality;\n\nfinal class Defect\n{\n}\n",
        ]);

        [$exitCode, $output] = self::fix($sandbox, dryRun: true);

        self::assertNotSame(0, $exitCode, $output);
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function finderRoots(): Generator
    {
        yield 'tests/' => ['tests'];
        yield 'migrations/' => ['migrations'];
    }

    /**
     * Le témoin : sans lui, un montage qui échoue toujours — mauvais chemin de config,
     * exécutable absent — passerait pour une porte qui fonctionne.
     */
    #[Test]
    public function the_delivered_configuration_accepts_what_it_has_itself_formatted(): void
    {
        $sandbox = $this->sandboxWithConfig([
            'src/Defect.php' => "<?php\n\nnamespace App\\Tests\\Fixtures\\Quality;\n\nfinal class Defect\n{\n}\n",
        ]);

        [$fixExit, $fixOutput] = self::fix($sandbox, dryRun: false);
        self::assertSame(0, $fixExit, $fixOutput);

        [$exitCode, $output] = self::fix($sandbox, dryRun: true);
        self::assertSame(0, $exitCode, $output);
    }

    /**
     * Le bac à sable reçoit une copie conforme de `.php-cs-fixer.dist.php` : le style
     * testé est bien celui qui est livré. Le finder du fichier est relatif à `__DIR__`,
     * donc la copie vise les répertoires du bac à sable.
     *
     * @param array<string, string> $files
     */
    private function sandboxWithConfig(array $files): string
    {
        $sandbox = $this->sandboxWith($files + ['tests/.gitkeep' => '']);

        new Filesystem()->copy(self::projectDir().'/.php-cs-fixer.dist.php', $sandbox.'/.php-cs-fixer.dist.php');

        return $sandbox;
    }

    /**
     * @return array{int, string}
     */
    private static function fix(string $sandbox, bool $dryRun): array
    {
        $command = [
            \PHP_BINARY,
            self::binary(['vendor/php-cs-fixer/shim/php-cs-fixer', 'vendor/bin/php-cs-fixer']),
            'fix',
            '--config='.$sandbox.'/.php-cs-fixer.dist.php',
            '--no-ansi',
        ];

        if ($dryRun) {
            $command[] = '--dry-run';
            $command[] = '--diff';
        }

        return self::execute($command, $sandbox);
    }
}
