<?php

declare(strict_types=1);

namespace App\Tests\Core\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Un bundle chargé sur **tous** les environnements vient de `require`, jamais de
 * `require-dev`.
 *
 * La panne que ce test ferme n'a aucun symptôme local : en développement et en test,
 * `composer install` installe les dépendances de dev, donc tout fonctionne. C'est sur un
 * dérivé installé en `composer install --no-dev` que le paquet manque — et comme
 * `config/bundles.php` déclare quand même son bundle, le noyau **ne démarre pas du tout**.
 * Ni une page, ni une commande, ni `app:init`. Le premier à le découvrir est celui qui
 * déploie.
 *
 * C'est exactement ce qui était vrai de `doctrine/doctrine-migrations-bundle` jusqu'à la
 * story 1.11 : déclaré `['all' => true]`, installé en `require-dev`. La remettre là
 * laisserait toute la porte verte — PHPStan, deptrac, la suite entière tournent avec les
 * dépendances de dev.
 *
 * **Écrit pour la règle, pas pour ce paquet-là.** Nommer `doctrine-migrations-bundle` ne
 * protégerait que lui ; la question « d'où vient ce bundle ? » se pose pour chaque ligne de
 * `config/bundles.php`, et le prochain oubli portera un autre nom.
 *
 * Le paquet d'un bundle est retrouvé par réflexion — le fichier de la classe, puis le
 * `composer.json` le plus proche au-dessus de lui —, et non par une table écrite à la main
 * qu'il faudrait tenir à jour.
 */
final class BundleDependencyTest extends TestCase
{
    #[Test]
    public function every_bundle_loaded_in_all_environments_comes_from_require(): void
    {
        $composer = json_decode(GateFiles::read('composer.json'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);
        self::assertIsArray($composer['require'] ?? null);
        self::assertIsArray($composer['require-dev'] ?? null);

        $bundles = self::bundlesLoadedEverywhere();

        self::assertNotSame([], $bundles, 'Aucun bundle n\'est chargé sur tous les environnements : config/bundles.php n\'a pas été lu.');

        foreach ($bundles as $bundle) {
            $package = self::packageOf($bundle);

            if (null === $package) {
                // Un bundle écrit dans `src/` n'a pas de paquet : il est livré avec le dépôt.
                continue;
            }

            self::assertArrayNotHasKey(
                $package,
                $composer['require-dev'],
                \sprintf(
                    '« %s » est chargé sur tous les environnements (%s) mais vient de require-dev : sous « composer install --no-dev », le noyau ne démarre pas.',
                    $package,
                    $bundle,
                ),
            );
            self::assertArrayHasKey(
                $package,
                $composer['require'],
                \sprintf(
                    '« %s » est chargé sur tous les environnements (%s) : il doit être une dépendance de production déclarée, pas une transitive dont personne ne garantit la présence.',
                    $package,
                    $bundle,
                ),
            );
        }
    }

    /**
     * Les classes de bundle que `config/bundles.php` déclare sur tous les environnements.
     *
     * @return list<class-string>
     */
    private static function bundlesLoadedEverywhere(): array
    {
        $declared = require GateFiles::projectDir().'/config/bundles.php';

        self::assertIsArray($declared);

        $bundles = [];

        foreach ($declared as $class => $environments) {
            self::assertIsArray($environments);

            if (true === ($environments['all'] ?? false)) {
                self::assertIsString($class);
                self::assertTrue(class_exists($class), \sprintf('Le bundle « %s » n\'est pas chargeable.', $class));

                $bundles[] = $class;
            }
        }

        return $bundles;
    }

    /**
     * Le paquet Composer qui livre cette classe, ou `null` si elle vient du dépôt lui-même.
     *
     * Le `composer.json` le plus proche au-dessus du fichier de la classe est celui de son
     * paquet ; si la remontée atteint celui du projet, c'est que la classe n'est pas dans
     * `vendor/`.
     *
     * @param class-string $bundle
     */
    private static function packageOf(string $bundle): ?string
    {
        $file = new ReflectionClass($bundle)->getFileName();

        self::assertIsString($file, \sprintf('« %s » n\'a pas de fichier : un bundle interne à PHP ?', $bundle));

        $directory = \dirname($file);
        $project = str_replace('\\', '/', GateFiles::projectDir());

        while (!is_file($directory.'/composer.json')) {
            $parent = \dirname($directory);

            self::assertNotSame($parent, $directory, \sprintf('Aucun composer.json au-dessus de « %s ».', $file));

            $directory = $parent;
        }

        if (str_replace('\\', '/', $directory) === $project) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($directory.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest);
        self::assertIsString($manifest['name'] ?? null, \sprintf('Le composer.json de « %s » ne porte pas de nom.', $directory));

        return $manifest['name'];
    }
}
