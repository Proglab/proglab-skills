<?php

declare(strict_types=1);

/*
 * Quality gate, as Castor tasks. Copy to the project root and commit it.
 *
 *   castor                 list every task
 *   castor qa              run the full gate, exactly as CI does
 *   castor qa:stan
 *   castor qa:cs
 *
 * Same contract as the Makefile alternative: every tool runs from the
 * jakzal/phpqa image — one pinned version of each, identical locally and in
 * CI, extensions preinstalled. (Only php-cs-fixer has dependencies that can
 * clash with the application's; PHPStan and deptrac are self-contained phars.)
 *
 * Pick Castor over Make when you want tasks that are real PHP — typed
 * arguments, IDE completion, conditionals that stay readable. Pick Make when
 * you want zero extra tooling. Do not commit both: two entry points that
 * drift apart is worse than either.
 *
 * Every task carries an alias matching the Makefile target of the same name, so
 * `castor stan` and `make stan` are the same command and documentation written for
 * one entry point stays true for the other.
 *
 * Requires Docker, plus Castor itself (https://castor.jolicode.com).
 */

namespace qa;

use Castor\Attribute\AsTask;

use function Castor\exit_code;
use function Castor\io;
use function Castor\run;

// Pinned on purpose. `jakzal/phpqa:alpine` is a moving tag rebuilt regularly, so a
// green pipeline can start failing with no code change because PHPStan gained a rule.
// Bump this deliberately, in its own commit, and read what changed.
// Pick the -phpX.Y- variant matching the project's PHP version.
const PHPQA_IMAGE = 'jakzal/phpqa:1.125.1-php8.4-alpine';

/**
 * Build the docker invocation for a phpqa tool.
 *
 * @param list<string> $command
 *
 * @return list<string>
 */
function phpqa(array $command): array
{
    // castor.php lives at the project root, so __DIR__ is the project root.
    $projectDir = __DIR__;

    $docker = ['docker', 'run', '--init', '--rm'];

    // A TTY makes the output readable locally and breaks the run in CI.
    if (stream_isatty(\STDIN)) {
        $docker[] = '-it';
    }

    return [
        ...$docker,
        '-v', $projectDir . ':/project',
        // PHPStan and friends want a writable /tmp that survives between runs.
        '-v', $projectDir . '/var/phpqa:/tmp',
        '-w', '/project',
        PHPQA_IMAGE,
        ...$command,
    ];
}

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
    // The Symfony extension reads the compiled container to resolve service
    // ids and route names. Without it, half the rules go quiet instead of
    // failing, which is worse than failing.
    containerCache();

    $command = [
        'phpstan', 'analyse',
        '--configuration=phpstan.dist.neon',
        '--memory-limit=1G',
    ];

    if ($generateBaseline) {
        // Only for adopting the standard on an existing codebase. The
        // baseline may shrink over time; it must never grow.
        $command[] = '--generate-baseline=phpstan-baseline.neon';
    }

    return exit_code(phpqa($command));
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
    return exit_code(phpqa([
        'php-cs-fixer', 'fix', '--config=.php-cs-fixer.dist.php',
    ]));
}

#[AsTask(name: 'cs-check', description: 'Verify code style without changing anything', aliases: ['cs-check'])]
function csCheck(): int
{
    return exit_code(phpqa([
        'php-cs-fixer', 'fix', '--config=.php-cs-fixer.dist.php', '--dry-run', '--diff',
    ]));
}

#[AsTask(description: 'Verify the layer contract', aliases: ['deptrac'])]
function deptrac(): int
{
    // deptrac is on demand: no deptrac.yaml, nothing to verify, and `qa`
    // stays green. Copy the file from the skill's assets/ to adopt it.
    if (!file_exists('deptrac.yaml')) {
        io()->note('No deptrac.yaml — layer contract not enforced on this project.');

        return 0;
    }

    return exit_code(phpqa([
        'deptrac', 'analyse',
        '--config-file=deptrac.yaml',
        // Lists dependencies on classes outside every layer, so a src/
        // directory nobody collected stays visible. Not --fail-on-uncovered:
        // vendor classes are uncovered too, and it would fail on the first
        // AbstractController.
        '--report-uncovered',
    ]));
}

#[AsTask(description: 'Known vulnerabilities, PHP and JavaScript', aliases: ['audit'])]
function audit(): int
{
    // composer audit, not local-php-security-checker: that one is archived and
    // its own repository points here. It is still in the phpqa image, which is
    // exactly why it needs saying.
    $php = exit_code(['composer', 'audit']);
    // JavaScript dependencies have vulnerabilities too, and without npm in the
    // loop nothing else reports them.
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

#[AsTask(description: 'Pull the latest phpqa image', aliases: ['update'])]
function update(): void
{
    io()->section('Updating ' . PHPQA_IMAGE);
    run(['docker', 'pull', PHPQA_IMAGE]);
}
