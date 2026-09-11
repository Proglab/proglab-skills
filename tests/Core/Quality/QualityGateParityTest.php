<?php

declare(strict_types=1);

namespace App\Tests\Core\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La porte de qualité a deux façades — `make qa` en local, la CI en ligne — et elles
 * vérifient la même liste de catégories.
 *
 * Une porte de qualité ne se casse pas, elle se désaligne : quelqu'un ajoute un job à la
 * CI et oublie le `Makefile`, ou l'inverse, et pendant des mois la commande locale que
 * tout le monde exécute avant de pousser vérifie moins que la CI. Rien ne le signale.
 *
 * La parité porte sur les **catégories**, pas sur les commandes : un `Makefile` enchaîne
 * des cibles, un workflow déclare des jobs, et les deux formes diffèrent par nature —
 * `lint` et `audit` sont deux cibles pour la seule catégorie « Linters and audits ». Le
 * `Makefile` porte donc la table de correspondance, en commentaires `# qa-category:`, et
 * ce test refuse toute orpheline des deux côtés.
 */
final class QualityGateParityTest extends TestCase
{
    /**
     * Les catégories que la porte doit avoir : les cinq de la story 1.2, plus
     * « Accessibility » que la story 1.4 y ajoute. La liste peut grandir encore, mais
     * aucune de celles-ci ne peut disparaître en silence, sinon la porte cesse de
     * vérifier ce pour quoi elle a été construite.
     */
    private const array FLOOR = [
        'Accessibility',
        'Code style',
        'Layer contract',
        'Linters and audits',
        'Static analysis',
        'Tests',
    ];

    #[Test]
    public function every_target_the_gate_runs_is_claimed_by_exactly_one_category(): void
    {
        $claimed = [];

        foreach (self::categories() as $category => $targets) {
            foreach ($targets as $target) {
                self::assertArrayNotHasKey(
                    $target,
                    $claimed,
                    \sprintf('La cible « %s » est revendiquée deux fois, dont par « %s ».', $target, $category),
                );

                $claimed[$target] = $category;
            }
        }

        $prerequisites = self::qaPrerequisites();

        self::assertSame(
            [],
            array_values(array_diff($prerequisites, array_keys($claimed))),
            'Cette cible est un prérequis de `qa` qu\'aucun commentaire `# qa-category:` ne rattache à une catégorie : elle s\'exécute en local sans qu\'aucun job de CI ne lui corresponde.',
        );

        self::assertSame(
            [],
            array_values(array_diff(array_keys($claimed), $prerequisites)),
            'Cette cible est rattachée à une catégorie mais n\'est pas un prérequis de `qa` : la catégorie est annoncée et ne s\'exécute jamais en local.',
        );
    }

    #[Test]
    public function both_facades_check_the_same_list_of_categories(): void
    {
        $local = array_keys(self::categories());
        $ci = GateFiles::jobNames();

        sort($local);
        sort($ci);

        self::assertSame(
            $local,
            $ci,
            \sprintf(
                "Les deux façades divergent.\nOrpheline dans `make qa` : %s\nOrpheline dans la CI : %s",
                self::listOrDash(array_diff($local, $ci)),
                self::listOrDash(array_diff($ci, $local)),
            ),
        );
    }

    /**
     * Ce que chaque catégorie doit réellement lancer, des deux côtés.
     *
     * Comparer des noms ne suffit pas : retirer `--dry-run` du job « Code style » fait
     * réécrire les fichiers par php-cs-fixer et sortir en 0 — le job reste vert pour
     * toujours, sur n'importe quelle entrée, et aucune assertion de parité ne bouge. Une
     * catégorie peut ainsi rester présente par son nom et vide de substance.
     *
     * @var array<string, list<string>>
     */
    private const array INVOCATIONS = [
        'Static analysis' => ['phpstan analyse', '--configuration=phpstan.dist.neon'],
        'Code style' => ['php-cs-fixer fix', '--config=.php-cs-fixer.dist.php', '--dry-run'],
        'Layer contract' => ['deptrac analyse', '--config-file=deptrac.yaml', '--report-uncovered'],
        // Le garde-fou d'isolation et les migrations sont dans la liste : ce sont eux qui
        // font que la façade locale vérifie autant que la façade en ligne.
        // `tailwind:build` est dans la liste pour la même raison que les migrations : en
        // test, le compilateur du bundle s'efface quand la feuille compilée manque, et
        // `@import 'tailwindcss'` fait alors échouer tout test fonctionnel qui rend une
        // page. Sur un clone frais, la retirer d'une façade rend cette façade rouge.
        'Tests' => ['debug:config dama_doctrine_test', 'doctrine:migrations:migrate', 'tailwind:build', 'phpunit'],
        'Linters and audits' => [
            'composer validate --strict',
            'lint:container',
            // Épinglé séparément : c'est la seule étape de la porte qui **construit** le
            // conteneur de production, donc la seule qui résolve pour de vrai les
            // références de `config/packages/*.yaml` sous `when@prod`. La perdre des deux
            // façades ne casserait aucune autre assertion.
            'lint:container --env=prod',
            'lint:twig',
            // Les arguments sont épinglés avec la commande : perdre `translations/` d'une
            // seule des deux façades ne casserait aucune parité, et un catalogue mal formé
            // passerait la porte du côté qui ne le regarde plus.
            'lint:yaml config/ .github/ translations/',
            'doctrine:schema:validate --skip-sync',
            'composer audit',
            // Il n'y a pas de `npm audit` sur cette stack : sans cette ligne, rien ne
            // signalerait jamais qu'un paquet JavaScript épinglé dans `importmap.php` a
            // une faille publiée. Elle est nommée ici parce qu'une catégorie peut rester
            // présente par son nom et se vider de sa substance.
            'importmap:audit',
        ],
        // `--testsuite Accessibility` est dans la liste, pas seulement `phpunit` : sans
        // l'option, le job rejouerait la suite par défaut — la catégorie resterait verte
        // en permanence tout en ne vérifiant plus jamais le plancher d'accessibilité.
        // `tailwind:build` y est pour la même raison que dans « Tests » : le plancher rend
        // de vraies pages, et sans la feuille compilée chaque rendu échoue.
        //
        // La troisième ligne est la seule étape Node du dépôt, et elle appartient à cette
        // catégorie plutôt qu'à « Tests » : ce qu'elle exécute est du comportement
        // d'accessibilité — le focus replacé après une navigation Turbo, un message
        // recopié dans la région d'annonces. Sans elle nommée ici, une façade pourrait
        // cesser d'exécuter les deux contrôleurs Stimulus sans que rien ne le signale, et
        // les quatre lignes de la matrice d'edge cases qu'ils portent redeviendraient
        // muettes.
        'Accessibility' => [
            // Les migrations y sont pour la même raison que dans « Tests », et depuis que
            // le plancher rend des pages qui lisent la table des langues : sur un clone
            // frais, retirer cette ligne d'une façade la rend rouge pendant que la porte
            // reste verte de l'autre côté.
            'doctrine:migrations:migrate',
            'tailwind:build',
            'phpunit --testsuite Accessibility',
            'node --test "tests/js/**/*.test.js"',
        ],
    ];

    #[Test]
    public function every_target_of_the_gate_is_a_real_rule_of_the_makefile(): void
    {
        foreach (self::categories() as $category => $targets) {
            foreach ($targets as $target) {
                self::assertMatchesRegularExpression(
                    '/^'.preg_quote($target, '/').':/m',
                    GateFiles::read('Makefile'),
                    \sprintf('« %s » rattache la catégorie « %s » à une cible qui n\'est pas une règle : `make` répondrait « No rule to make target ».', $target, $category),
                );
            }
        }
    }

    #[Test]
    public function each_category_runs_its_tool_in_check_mode_on_both_facades(): void
    {
        $jobIds = GateFiles::jobIdsByName();

        foreach (self::INVOCATIONS as $category => $fragments) {
            self::assertArrayHasKey($category, $jobIds, \sprintf('Aucun job de CI ne porte le nom « %s ».', $category));

            $ci = implode("\n", GateFiles::runSteps($jobIds[$category]));

            $local = '';

            foreach (self::categories()[$category] ?? [] as $target) {
                $local .= GateFiles::recipe($target);
            }

            foreach ($fragments as $fragment) {
                self::assertStringContainsString(
                    $fragment,
                    $ci,
                    \sprintf('Le job « %s » ne lance plus « %s » : la catégorie est annoncée et ne vérifie plus rien.', $category, $fragment),
                );
                self::assertStringContainsString(
                    $fragment,
                    $local,
                    \sprintf('La cible locale de « %s » ne lance plus « %s » : `make qa` vérifie moins que la CI.', $category, $fragment),
                );
            }
        }
    }

    #[Test]
    public function no_category_of_this_story_has_been_dropped(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(self::FLOOR, array_keys(self::categories()))),
            'Cette catégorie a été retirée de la porte. La liste peut grandir, elle ne rétrécit pas.',
        );
    }

    /**
     * La table de correspondance, telle que le `Makefile` la déclare :
     * `# qa-category: <Catégorie> = <cible> [<cible>…]`.
     *
     * @return array<string, list<string>>
     */
    private static function categories(): array
    {
        preg_match_all(
            '/^#\s*qa-category:\s*(?P<category>[^=]+?)\s*=\s*(?P<targets>.+?)\s*$/m',
            GateFiles::read('Makefile'),
            $matches,
            \PREG_SET_ORDER,
        );

        self::assertNotSame([], $matches, 'Le `Makefile` ne déclare aucune catégorie `# qa-category:`.');

        $categories = [];

        foreach ($matches as $match) {
            $categories[$match['category']] = GateFiles::words($match['targets']);
        }

        return $categories;
    }

    /**
     * Les prérequis de la cible `qa`, commentaire d'aide `##` retiré.
     *
     * @return list<string>
     */
    private static function qaPrerequisites(): array
    {
        self::assertSame(
            1,
            preg_match('/^qa:(?P<prerequisites>[^\n#]*)/m', GateFiles::read('Makefile'), $matches),
            'Le `Makefile` n\'a pas de cible `qa`.',
        );

        return GateFiles::words($matches['prerequisites']);
    }

    /**
     * @param array<array-key, string> $values
     */
    private static function listOrDash(array $values): string
    {
        return [] === $values ? '—' : implode(', ', $values);
    }
}
