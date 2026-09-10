<?php

declare(strict_types=1);

namespace App\Tests\Core\Theme;

use App\Tests\Core\Quality\GateFiles;
use RuntimeException;

/**
 * La lecture des feuilles de style du thème, partagée par les tests de ce répertoire.
 *
 * Un thème ne casse pas, il se troue : un rôle ajouté sans sa variante sombre, une
 * couleur écrite en dur dans un écran pressé, un second kit installé « juste pour ce
 * composant ». Les trois se lisent dans des fichiers — c'est le précédent de
 * `BoundaryTest`, qui teste `deptrac.yaml` en le lisant plutôt qu'en le relisant.
 */
final readonly class ThemeSheet
{
    public const string THEME = 'assets/styles/theme.css';
    public const string BRAND = 'assets/styles/brand.css';
    public const string APP = 'assets/styles/app.css';

    /**
     * Les trois blocs de `theme.css`. Le sombre est écrit deux fois — une fois pour
     * `prefers-color-scheme`, une fois pour la classe `dark` que l'Epic 5 posera sur
     * `<html>` — parce qu'un `@media` ne peut pas entrer dans une liste de sélecteurs.
     * `the_two_dark_blocks_never_diverge()` interdit à cette copie de dériver.
     */
    public const string LIGHT = ':root {';
    public const string SYSTEM_DARK = ':root:not(.light) {';
    public const string FORCED_DARK = ':root.dark {';

    /**
     * Les déclarations de variables CSS d'une feuille, dans l'ordre du fichier.
     *
     * Les lignes commentées ne comptent pas : `brand.css` est livré avec ses variables
     * en commentaire, et une variable commentée est une invitation, pas une
     * redéfinition.
     *
     * @return list<array{name: string, value: string, line: int}>
     */
    public static function declarations(string $path): array
    {
        $found = [];

        foreach (self::activeLines($path) as $line => $contents) {
            if (1 !== preg_match('/^\s*(?P<name>--[a-z0-9-]+)\s*:\s*(?P<value>[^;]+);/i', $contents, $matches)) {
                continue;
            }

            $found[] = [
                'name' => strtolower($matches['name']),
                'value' => trim($matches['value']),
                'line' => $line,
            ];
        }

        return $found;
    }

    /**
     * La dernière valeur déclarée pour chaque variable de la feuille entière.
     *
     * @return array<string, string>
     */
    public static function values(string $path): array
    {
        $values = [];

        foreach (self::declarations($path) as $declaration) {
            $values[$declaration['name']] = $declaration['value'];
        }

        return $values;
    }

    /**
     * Les variables déclarées dans un bloc précis, repéré par son sélecteur.
     *
     * @return array<string, string>
     */
    public static function block(string $path, string $selector): array
    {
        $css = self::stripComments(GateFiles::read($path));
        $start = strpos($css, $selector);

        if (false === $start) {
            throw new RuntimeException(\sprintf('« %s » ne contient aucun bloc « %s ».', $path, $selector));
        }

        $offset = $start + \strlen($selector);
        $depth = 1;
        $length = \strlen($css);
        $body = '';

        while ($offset < $length) {
            $character = $css[$offset];

            if ('{' === $character) {
                ++$depth;
            } elseif ('}' === $character) {
                --$depth;

                if (0 === $depth) {
                    return self::variablesIn($body);
                }
            }

            $body .= $character;
            ++$offset;
        }

        throw new RuntimeException(\sprintf('Le bloc « %s » de « %s » n\'est jamais refermé.', $selector, $path));
    }

    /**
     * @return array<string, string>
     */
    private static function variablesIn(string $body): array
    {
        preg_match_all('/(?P<name>--[a-z0-9-]+)\s*:\s*(?P<value>[^;]+);/i', $body, $matches, \PREG_SET_ORDER);

        $values = [];

        foreach ($matches as $match) {
            $values[strtolower($match['name'])] = trim($match['value']);
        }

        return $values;
    }

    /**
     * Les lignes de la feuille, commentaires `/* … *\/` retirés.
     *
     * @return array<int, string> les lignes actives, indexées par leur numéro (base 1)
     */
    public static function activeLines(string $path): array
    {
        $lines = preg_split('/\R/', self::stripComments(GateFiles::read($path)));

        if (false === $lines) {
            return [];
        }

        $active = [];

        foreach ($lines as $index => $line) {
            $active[$index + 1] = $line;
        }

        return $active;
    }

    /**
     * Retire les commentaires de bloc en préservant les retours à la ligne, pour que les
     * numéros de ligne rapportés restent ceux du fichier.
     */
    public static function stripComments(string $css): string
    {
        return (string) preg_replace_callback(
            '#/\*.*?\*/#s',
            static fn (array $match): string => (string) preg_replace('/[^\n]/', '', $match[0]),
            $css,
        );
    }

    public static function exists(string $path): bool
    {
        return is_file(GateFiles::projectDir().'/'.$path);
    }
}
