<?php

declare(strict_types=1);

/*
 * Porte de qualité, sous forme de tâches Castor. Copier à la racine du projet et
 * la committer.
 *
 *   castor                 liste toutes les tâches
 *   castor qa              exécute la porte complète, exactement comme la CI
 *   castor qa:stan
 *   castor qa:cs
 *
 * Même contrat que l'alternative Makefile : chaque outil est une dépendance
 * require-dev, exécutée via vendor/bin/ — une version épinglée de chacun,
 * résolue par composer.lock, identique en local et en CI.
 *
 * Préférer Castor à Make quand on veut des tâches en PHP réel — arguments
 * typés, complétion IDE, conditions qui restent lisibles. Préférer Make quand
 * on veut zéro outillage supplémentaire. Ne pas committer les deux : deux
 * points d'entrée qui divergent, c'est pire que l'un ou l'autre.
 *
 * Chaque tâche porte un alias correspondant à la cible Makefile du même nom, si
 * bien que `castor stan` et `make stan` sont la même commande et qu'une
 * documentation écrite pour un point d'entrée reste vraie pour l'autre.
 *
 * Nécessite Castor lui-même (https://castor.jolicode.com).
 */

namespace qa;

use Castor\Attribute\AsTask;

use function Castor\exit_code;
use function Castor\io;

#[AsTask(name: 'qa', namespace: '', description: 'Run the full quality gate, exactly as CI does')]
function qa(): int
{
    $failures = 0;

    foreach ([
        'code style' => csCheck(...),
        'static analysis' => stan(...),
        'layer contract' => deptrac(...),
        'linters' => lint(...),
        'vulnerabilities' => audit(...),
        'tests' => test(...),
    ] as $label => $task) {
        io()->section(ucfirst($label));

        if (0 !== $task()) {
            io()->error(\sprintf('%s failed.', ucfirst($label)));
            ++$failures;
        }
    }

    if ($failures > 0) {
        io()->error(\sprintf('%d check(s) failed.', $failures));

        return 1;
    }

    io()->success('Everything passed.');

    return 0;
}

#[AsTask(description: 'Run the test suite', aliases: ['test'])]
function test(): int
{
    return exit_code(['vendor/bin/phpunit']);
}

#[AsTask(name: 'container-cache', description: 'Compile the container so PHPStan can read service ids', aliases: ['container-cache'])]
function containerCache(): int
{
    return exit_code(['php', 'bin/console', 'cache:warmup', '--env=dev']);
}

#[AsTask(description: 'Static analysis at level max', aliases: ['stan'])]
function stan(bool $generateBaseline = false): int
{
    // L'extension Symfony lit le conteneur compilé pour résoudre les ids de
    // service et les noms de route. Sans cela, la moitié des règles se taisent
    // au lieu d'échouer, ce qui est pire qu'échouer.
    containerCache();

    $command = [
        'vendor/bin/phpstan', 'analyse',
        '--configuration=phpstan.dist.neon',
        '--memory-limit=1G',
    ];

    if ($generateBaseline) {
        // Uniquement pour adopter le standard sur une base de code existante.
        // La baseline peut rétrécir avec le temps ; elle ne doit jamais
        // grandir.
        $command[] = '--generate-baseline=phpstan-baseline.neon';
    }

    return exit_code($command);
}

#[AsTask(name: 'stan-baseline', description: 'Freeze existing errors (adopting the standard on an existing codebase)', aliases: ['stan-baseline'])]
function stanBaseline(): int
{
    $code = stan(generateBaseline: true);

    io()->note('Commit phpstan-baseline.neon and uncomment the includes line in phpstan.dist.neon.');
    io()->note('The baseline may shrink, never grow.');

    return $code;
}

#[AsTask(description: 'Fix code style', aliases: ['cs'])]
function cs(): int
{
    return exit_code([
        'vendor/bin/php-cs-fixer', 'fix', '--config=.php-cs-fixer.dist.php',
    ]);
}

#[AsTask(name: 'cs-check', description: 'Verify code style without changing anything', aliases: ['cs-check'])]
function csCheck(): int
{
    return exit_code([
        'vendor/bin/php-cs-fixer', 'fix', '--config=.php-cs-fixer.dist.php', '--dry-run', '--diff',
    ]);
}

#[AsTask(description: 'Verify the layer contract', aliases: ['deptrac'])]
function deptrac(): int
{
    // deptrac est à la demande : pas de deptrac.yaml, rien à vérifier, et `qa`
    // reste vert. Copier le fichier depuis assets/ du skill pour l'adopter.
    if (!file_exists('deptrac.yaml')) {
        io()->note('No deptrac.yaml — layer contract not enforced on this project.');

        return 0;
    }

    return exit_code([
        'vendor/bin/deptrac', 'analyse',
        '--config-file=deptrac.yaml',
        // Liste les dépendances vers des classes extérieures à toute couche,
        // pour qu'un répertoire src/ que personne n'a collecté reste visible.
        // Pas --fail-on-uncovered : les classes vendor sont non couvertes
        // elles aussi, et cela échouerait dès le premier AbstractController.
        '--report-uncovered',
    ]);
}

#[AsTask(description: 'Known vulnerabilities, PHP and JavaScript', aliases: ['audit'])]
function audit(): int
{
    // composer audit, pas local-php-security-checker : celui-là est archivé et
    // son propre dépôt renvoie ici.
    $php = exit_code(['composer', 'audit']);
    // Les dépendances JavaScript ont aussi des vulnérabilités, et sans npm dans
    // la boucle rien d'autre ne les signale.
    $js = exit_code(['php', 'bin/console', 'importmap:audit']);

    return 0 === $php && 0 === $js ? 0 : 1;
}

#[AsTask(description: 'Lint the container, templates, YAML and the Doctrine mapping', aliases: ['lint'])]
function lint(): int
{
    $failed = 0;

    foreach ([
        ['php', 'bin/console', 'lint:container'],
        ['php', 'bin/console', 'lint:twig', 'templates/'],
        ['php', 'bin/console', 'lint:yaml', 'config/'],
        ['php', 'bin/console', 'doctrine:schema:validate', '--skip-sync'],
    ] as $command) {
        if (0 !== exit_code($command)) {
            ++$failed;
        }
    }

    return $failed > 0 ? 1 : 0;
}
