<?php

declare(strict_types=1);

namespace App\Tests\Core\Quality;

use DAMA\DoctrineTestBundle\DAMADoctrineTestBundle;
use DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'isolation transactionnelle des tests tient à deux lignes écrites dans deux fichiers
 * différents, et l'une sans l'autre est pire que ni l'une ni l'autre.
 *
 * L'extension PHPUnit sans le bundle démarre, ne trouve aucune connexion statique à
 * envelopper, et laisse chaque test valider (commit) : la suite reste verte pendant
 * qu'elle remplit la base de données. Rien dans la sortie de PHPUnit ne le signale —
 * c'est exactement pourquoi cela se lit ici, et se vérifie en CI avec `debug:config`.
 */
final class DatabaseIsolationTest extends TestCase
{
    #[Test]
    public function the_bundle_and_its_phpunit_extension_are_declared_together(): void
    {
        $bundles = require \dirname(__DIR__, 3).'/config/bundles.php';
        self::assertIsArray($bundles);

        $declaration = $bundles[DAMADoctrineTestBundle::class] ?? null;
        $bundleEnabled = \is_array($declaration) && true === ($declaration['test'] ?? false);

        $extensionRegistered = 1 === preg_match(
            '#<extensions>\s*<bootstrap\s+class="'.preg_quote(PHPUnitExtension::class, '#').'"\s*/>\s*</extensions>#',
            GateFiles::read('phpunit.dist.xml'),
        );

        self::assertSame(
            $bundleEnabled,
            $extensionRegistered,
            $bundleEnabled
                ? 'Le bundle DAMA est activé en `test` mais son extension PHPUnit n\'est pas enregistrée dans `phpunit.dist.xml` : aucun test n\'est isolé.'
                : 'L\'extension PHPUnit DAMA est enregistrée mais le bundle n\'est pas activé en `test` dans `config/bundles.php` : chaque test valide en base, et la suite reste verte pendant qu\'elle fuit.',
        );

        self::assertTrue($bundleEnabled, 'L\'isolation transactionnelle des tests n\'est pas installée.');
    }

    /**
     * Le filet mécanique : la commande sort en 1 quand le bundle n'est pas activé en
     * test. Elle appartient au job qui exécute la suite, et avant elle — une suite verte
     * qui écrit en base ne se remarque pas.
     */
    #[Test]
    public function the_test_job_verifies_the_isolation_before_running_the_suite(): void
    {
        $guard = null;
        $suite = null;

        foreach (GateFiles::runSteps('tests') as $index => $run) {
            if (null === $guard && str_contains($run, 'debug:config dama_doctrine_test')) {
                $guard = $index;
            }

            if (null === $suite && str_contains($run, 'phpunit')) {
                $suite = $index;
            }
        }

        self::assertNotNull($guard, 'Le job de tests n\'exécute pas `php bin/console --env=test debug:config dama_doctrine_test`.');
        self::assertNotNull($suite, 'Le job de tests n\'exécute pas la suite.');
        self::assertLessThan($suite, $guard, 'La vérification de l\'isolation doit précéder la suite.');
    }

    /**
     * `--no-dev` retirerait `dama/doctrine-test-bundle` et tous les outils de la porte,
     * qui sont des require-dev : elle s'effondrerait sans un mot.
     */
    #[Test]
    public function the_workflow_never_installs_without_dev_dependencies(): void
    {
        foreach (GateFiles::activeLines('.github/workflows/ci.yml') as $number => $line) {
            self::assertStringNotContainsString(
                '--no-dev',
                $line,
                \sprintf('Ligne %d : les outils de la porte et l\'isolation des tests sont des require-dev.', $number),
            );
        }
    }
}
