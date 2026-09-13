<?php

declare(strict_types=1);

namespace App\Tests\Core\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * La déclaration de hachage **de production**, épinglée par lecture de fichier.
 *
 * C'est le seul réglage de sécurité du socle qu'aucun test fonctionnel ne peut atteindre :
 * `config/packages/security.yaml` redéclare `password_hashers` sous `when@test`, donc la
 * suite entière s'exécute sur la déclaration de test. Passer la ligne de production à
 * `plaintext` — ou y nommer un algorithme en dur — laisserait les 268 tests au vert et
 * livrerait des mots de passe lisibles.
 *
 * Le contrôle se fait donc sur le fichier, comme `TestDatabaseEngineTest` le fait pour le
 * moteur de base de données : une lecture, pas un conteneur.
 *
 * **Deux choses sont exigées, et la seconde est la moins évidente.** L'algorithme est
 * `auto`, qui choisit le meilleur que la plateforme offre et fait migrer les hachages avec
 * `needsRehash()` ; et la surcharge de test garde `auto` elle aussi, en ne baissant que
 * le coût. Une surcharge de test qui nommerait `bcrypt` ferait vérifier la connexion sur
 * un algorithme que la production n'utilise pas.
 */
final class PasswordHashingTest extends TestCase
{
    private const string CONFIG = 'config/packages/security.yaml';

    private const string HASHED_INTERFACE = 'Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface';

    /**
     * Les algorithmes qui ne hachent pas, ou qui sont cassés. Aucun n'a sa place dans ce
     * fichier, ni en production ni en test.
     */
    private const array FORBIDDEN = ['plaintext', 'md5', 'sha1', 'sha256'];

    #[Test]
    public function production_hashes_passwords_with_the_auto_algorithm(): void
    {
        self::assertSame(
            'auto',
            self::hashersOf('security'),
            'La déclaration de production ne vaut plus « auto » : un algorithme figé dans un fichier que plus personne ne relit, ou pire, pas de hachage du tout.',
        );
    }

    /**
     * La surcharge de test baisse le **coût**, jamais l'algorithme.
     */
    #[Test]
    public function the_test_override_lowers_the_cost_without_changing_the_algorithm(): void
    {
        $override = self::hashersOf('when@test');

        self::assertIsArray($override, 'Le bloc `when@test` ne redéclare plus le hachage ; s\'il est retiré, retirer aussi ce test.');
        self::assertSame(
            'auto',
            $override['algorithm'] ?? null,
            'La surcharge de test nomme un algorithme : la suite vérifierait alors la connexion sur un mécanisme que la production n\'utilise pas.',
        );
    }

    /**
     * Le filet : aucune ligne active du fichier ne nomme un algorithme qui ne hache pas.
     */
    #[Test]
    public function no_active_line_names_an_algorithm_that_does_not_hash(): void
    {
        foreach (GateFiles::activeLines(self::CONFIG) as $number => $line) {
            foreach (self::FORBIDDEN as $algorithm) {
                self::assertStringNotContainsStringIgnoringCase(
                    $algorithm,
                    $line,
                    \sprintf('« %s » ligne %d nomme « %s ».', self::CONFIG, $number, $algorithm),
                );
            }
        }
    }

    /**
     * La déclaration de `password_hashers` sous une racine du fichier — `security` pour la
     * production, `when@test` pour la surcharge.
     */
    private static function hashersOf(string $root): mixed
    {
        $parsed = Yaml::parse(GateFiles::read(self::CONFIG));

        self::assertIsArray($parsed);

        $section = $parsed[$root] ?? null;

        self::assertIsArray($section, \sprintf('« %s » ne déclare rien sous « %s ».', self::CONFIG, $root));

        if ('security' !== $root) {
            $section = $section['security'] ?? null;

            self::assertIsArray($section, \sprintf('« %s » ne déclare rien sous « %s.security ».', self::CONFIG, $root));
        }

        $hashers = $section['password_hashers'] ?? null;

        self::assertIsArray($hashers, \sprintf('« %s » ne déclare pas `password_hashers` sous « %s ».', self::CONFIG, $root));

        return $hashers[self::HASHED_INTERFACE] ?? null;
    }
}
