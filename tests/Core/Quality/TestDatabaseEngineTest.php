<?php

declare(strict_types=1);

namespace App\Tests\Core\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La base de test suit le moteur de production, et rien d'autre.
 *
 * SQLite est le raccourci qui coûte le plus cher : la suite passe, la production casse,
 * et `dbname_suffix` — le garde-fou qui empêche les tests d'écrire dans la base de
 * développement — n'a même aucun effet sur cette plateforme, ce que DoctrineBundle
 * documente lui-même. Le socle contractualise MySQL 8.4 LTS (décision 1) : ce test
 * refuse toute dérive entre `.env`, `.env.test` et le workflow de CI.
 */
final class TestDatabaseEngineTest extends TestCase
{
    private const string SERVER_VERSION = '8.4';

    #[Test]
    public function the_committed_development_dsn_pins_the_contractual_engine(): void
    {
        self::assertSame(self::SERVER_VERSION, self::serverVersionOf(self::databaseUrlOf('.env')));
    }

    /**
     * Un `DATABASE_URL` explicite en test, plutôt que le seul suffixe appliqué au DSN de
     * développement : le nom de la base de test cesse alors de dépendre d'une surcharge
     * locale que la CI n'a pas (décision 2).
     */
    #[Test]
    public function the_test_environment_carries_its_own_explicit_dsn(): void
    {
        self::assertSame(self::SERVER_VERSION, self::serverVersionOf(self::databaseUrlOf('.env.test')));
    }

    /**
     * **Tout** job qui monte une base, pas seulement celui des tests. « Accessibility » en
     * a gagné une, et un job cadré sur son seul identifiant laisserait le prochain passer
     * en `mysql:8.0` sans que rien ne le dise.
     */
    #[Test]
    public function the_ci_runs_the_suite_against_the_same_engine(): void
    {
        $jobIds = array_values(GateFiles::jobIdsByName());

        $checked = 0;

        foreach ($jobIds as $jobId) {
            $job = GateFiles::job($jobId);

            $services = $job['services'] ?? null;

            if (!\is_array($services) || [] === $services) {
                continue;
            }

            ++$checked;

            $env = $job['env'] ?? null;
            self::assertIsArray($env, \sprintf('Le job « %s » monte une base et ne définit aucune variable d\'environnement.', $jobId));

            $dsn = $env['DATABASE_URL'] ?? null;
            self::assertIsString($dsn, \sprintf('Le job « %s » monte une base et ne définit pas `DATABASE_URL`.', $jobId));
            self::assertSame(self::SERVER_VERSION, self::serverVersionOf($dsn));

            $images = [];

            foreach ($services as $service) {
                $image = \is_array($service) ? $service['image'] ?? null : null;
                self::assertIsString($image);
                $images[] = $image;
            }

            self::assertSame(
                ['mysql:'.self::SERVER_VERSION],
                $images,
                'L\'image de la base de CI doit être celle du moteur contractuel — pas une version majeure en fin de vie, et surtout pas un autre moteur.',
            );
        }

        self::assertGreaterThan(0, $checked, 'Aucun job de CI ne monte de base de données : la suite ne s\'exécute plus contre le moteur de production.');
    }

    /**
     * Le schéma de test est monté par les migrations. Une suite qui construit son schéma
     * à partir du mapping n'exerce jamais les migrations, et les migrations sont la
     * première chose que la production exécute.
     */
    #[Test]
    public function the_ci_builds_the_test_schema_with_the_migrations(): void
    {
        $script = implode("\n", GateFiles::runSteps('tests'));

        self::assertStringContainsString('doctrine:migrations:migrate', $script);
        self::assertStringNotContainsString('doctrine:schema:create', $script);
    }

    /**
     * Le filet, au-delà des trois assertions ci-dessus : aucune ligne *active* de la
     * porte ne nomme SQLite.
     */
    #[Test]
    public function no_active_line_of_the_gate_falls_back_to_sqlite(): void
    {
        // `config/packages/doctrine.yaml` est dans la liste parce que c'est l'endroit le
        // plus naturel pour basculer les tests sur SQLite : son bloc `when@test` porte
        // deja `dbname_suffix`, et un `dbal.url:` y suffirait.
        foreach (['.env', '.env.test', '.github/workflows/ci.yml', 'config/packages/doctrine.yaml'] as $path) {
            foreach (GateFiles::activeLines($path) as $number => $line) {
                self::assertStringNotContainsStringIgnoringCase(
                    'sqlite',
                    $line,
                    \sprintf('« %s » ligne %d nomme SQLite. Le socle teste sur le moteur de production.', $path, $number),
                );
            }
        }
    }

    private static function databaseUrlOf(string $path): string
    {
        self::assertSame(
            1,
            preg_match('/^DATABASE_URL=["\']?(?P<dsn>[^"\'\n]+)/m', GateFiles::read($path), $matches),
            \sprintf('« %s » ne définit pas de `DATABASE_URL`.', $path),
        );

        return $matches['dsn'];
    }

    private static function serverVersionOf(string $dsn): string
    {
        self::assertStringStartsWith('mysql://', $dsn, \sprintf('DSN inattendu : %s', $dsn));
        self::assertSame(
            1,
            preg_match('/[?&]serverVersion=(?P<version>[^&]+)/', $dsn, $matches),
            \sprintf('DSN sans `serverVersion` : %s', $dsn),
        );

        return $matches['version'];
    }
}
