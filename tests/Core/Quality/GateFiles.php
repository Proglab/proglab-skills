<?php

declare(strict_types=1);

namespace App\Tests\Core\Quality;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * La lecture des fichiers de la porte, partagée par les tests de ce répertoire.
 *
 * Trois d'entre eux lisent le `Makefile` et le workflow de CI ; les parser trois fois
 * ferait trois façons de les lire, et c'est exactement le genre de dérive que ces tests
 * existent pour empêcher.
 */
final readonly class GateFiles
{
    public static function read(string $path): string
    {
        $contents = file_get_contents(self::projectDir().'/'.$path);

        if (false === $contents) {
            throw new RuntimeException(\sprintf('« %s » est absent ou illisible.', $path));
        }

        return $contents;
    }

    /**
     * Les lignes qui font quelque chose. Un commentaire qui explique pourquoi le socle
     * refuse SQLite ou `--no-dev` n'est pas un usage de SQLite ni de `--no-dev` — une
     * porte qui interdirait d'énoncer sa propre règle serait une porte mal posée.
     *
     * @return array<int, string> les lignes actives, indexées par leur numéro (base 1)
     */
    public static function activeLines(string $path): array
    {
        $lines = preg_split('/\R/', self::read($path));

        if (false === $lines) {
            throw new RuntimeException(\sprintf('« %s » n\'a pas pu être découpé en lignes.', $path));
        }

        $active = [];

        foreach ($lines as $index => $line) {
            if (!str_starts_with(ltrim($line), '#')) {
                $active[$index + 1] = $line;
            }
        }

        return $active;
    }

    /**
     * @return list<string>
     */
    public static function words(string $value): array
    {
        $words = preg_split('/\s+/', trim($value));

        if (false === $words) {
            return [];
        }

        return array_values(array_filter($words, static fn (string $word): bool => '' !== $word));
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function job(string $id): array
    {
        $workflow = Yaml::parse(self::read('.github/workflows/ci.yml'));

        if (!\is_array($workflow) || !\is_array($workflow['jobs'] ?? null)) {
            throw new RuntimeException('Le workflow de CI ne déclare aucun job.');
        }

        $job = $workflow['jobs'][$id] ?? null;

        if (!\is_array($job)) {
            throw new RuntimeException(\sprintf('Le workflow de CI n\'a pas de job « %s ».', $id));
        }

        return $job;
    }

    /**
     * Les `name:` des jobs, dans l'ordre du fichier — un job de CI porte le nom de sa
     * catégorie, c'est ce qui rend la parité lisible dans l'interface de GitHub.
     *
     * @return list<string>
     */
    public static function jobNames(): array
    {
        $workflow = Yaml::parse(self::read('.github/workflows/ci.yml'));

        if (!\is_array($workflow) || !\is_array($workflow['jobs'] ?? null)) {
            throw new RuntimeException('Le workflow de CI ne déclare aucun job.');
        }

        $names = [];

        foreach ($workflow['jobs'] as $id => $job) {
            $name = \is_array($job) ? $job['name'] ?? null : null;

            if (!\is_string($name)) {
                throw new RuntimeException(\sprintf('Le job « %s » n\'a pas de `name:` — un job de CI est nommé d\'après sa catégorie.', (string) $id));
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * Les commandes `run:` d'un job, dans l'ordre.
     *
     * @return list<string>
     */
    public static function runSteps(string $jobId): array
    {
        $steps = self::job($jobId)['steps'] ?? null;

        if (!\is_array($steps)) {
            throw new RuntimeException(\sprintf('Le job « %s » n\'a aucune étape.', $jobId));
        }

        $runs = [];

        foreach ($steps as $step) {
            $run = \is_array($step) ? $step['run'] ?? null : null;

            if (\is_string($run)) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * Les `name:` des jobs indexés par leur id, pour aller du nom de catégorie aux
     * commandes que le job exécute réellement.
     *
     * @return array<string, string> nom de job => id de job
     */
    public static function jobIdsByName(): array
    {
        $workflow = Yaml::parse(self::read('.github/workflows/ci.yml'));

        if (!\is_array($workflow) || !\is_array($workflow['jobs'] ?? null)) {
            throw new RuntimeException('Le workflow de CI ne déclare aucun job.');
        }

        $ids = [];

        foreach ($workflow['jobs'] as $id => $job) {
            $name = \is_array($job) ? $job['name'] ?? null : null;

            if (\is_string($name)) {
                $ids[$name] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Le corps d'une règle du `Makefile` — ses lignes de commande, indentées par une
     * tabulation. Une règle absente est une erreur : un prérequis de `qa` qui ne
     * correspond à aucune règle fait échouer `make` sur « No rule to make target ».
     */
    public static function recipe(string $target): string
    {
        $pattern = \sprintf('/^%s:[^\n]*\n(?P<body>(?:\t[^\n]*\n|\n(?=\t))*)/m', preg_quote($target, '/'));

        if (1 !== preg_match($pattern, self::read('Makefile'), $matches)) {
            throw new RuntimeException(\sprintf('Le `Makefile` n\'a pas de règle « %s ».', $target));
        }

        return $matches['body'];
    }

    public static function projectDir(): string
    {
        return str_replace('\\', '/', \dirname(__DIR__, 3));
    }
}
