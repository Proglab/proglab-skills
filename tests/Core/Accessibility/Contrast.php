<?php

declare(strict_types=1);

namespace App\Tests\Core\Accessibility;

use RuntimeException;

/**
 * La mesure de contraste, faite ici plutôt que recopiée d'un tableau.
 *
 * `DESIGN.md § Colors` promet des ratios « à remesurer après rebranding ». Une constante
 * de test recopiée depuis ce tableau ne remesure rien : elle reste vraie pendant que
 * `theme.css` change sous elle. La chaîne complète est donc refaite à chaque exécution —
 * `oklch` du thème → Oklab → sRGB linéaire → luminance relative → ratio WCAG — à partir
 * des valeurs que `ThemeSheet` lit dans la feuille livrée.
 *
 * Les matrices Oklab → sRGB linéaire viennent de la spécification CSS Color 4
 * (https://www.w3.org/TR/css-color-4/#color-conversion-code) ; la formule de luminance et
 * celle du ratio viennent de WCAG 2.1 (https://www.w3.org/TR/WCAG21/#dfn-relative-luminance,
 * #dfn-contrast-ratio).
 *
 * Vérifié contre le tableau de `DESIGN.md` : 19,79 / 4,73 / 7,15 / 4,76 en clair, 18,96 /
 * 6,91 / 10,59 / 6,84 en sombre, pour des valeurs annoncées à ≈ 19 / 4,74 / ≈ 7,2 / 4,77
 * et ≈ 19 / ≈ 7,1 / ≈ 10 / 6,8. L'écart est celui des hex approchés du spine, pas de la
 * conversion.
 */
final readonly class Contrast
{
    /**
     * Le ratio WCAG entre deux couleurs du thème, exprimées en `oklch()`.
     *
     * @param array<string, string> $palette les variables du bloc de thème, telles que
     *                                       `ThemeSheet::block()` les rend
     */
    public static function ratio(array $palette, string $first, string $second): float
    {
        $one = self::luminance(self::resolve($palette, $first));
        $two = self::luminance(self::resolve($palette, $second));

        $lighter = max($one, $two);
        $darker = min($one, $two);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * La valeur d'une variable, `var(--autre)` suivi jusqu'à sa cible.
     *
     * Les alias de statut du socle (`--status-done: var(--primary)`) sont des alias
     * sémantiques : les suivre est la seule façon de mesurer ce que l'écran affiche
     * réellement.
     *
     * @param array<string, string> $palette
     *
     * @return array{float, float, float} sRGB linéaire
     */
    private static function resolve(array $palette, string $token, int $depth = 0): array
    {
        if ($depth > 8) {
            throw new RuntimeException(\sprintf('« %s » tourne en rond dans le thème.', $token));
        }

        $value = $palette[$token] ?? throw new RuntimeException(\sprintf('Le thème ne déclare aucune variable « %s ».', $token));

        if (1 === preg_match('/^var\(\s*(?P<target>--[a-z0-9-]+)\s*\)$/i', $value, $matches)) {
            return self::resolve($palette, strtolower($matches['target']), $depth + 1);
        }

        return self::parse($value);
    }

    /**
     * `oklch(L C H)` → sRGB linéaire, composantes ramenées dans [0, 1].
     *
     * Un canal hors gamut après conversion est écrêté, comme le fait un navigateur : la
     * couleur affichée est celle du gamut, et c'est elle qu'on mesure.
     *
     * @return array{float, float, float}
     */
    public static function parse(string $value): array
    {
        if (1 !== preg_match('/^oklch\(\s*(?P<l>[\d.]+%?)\s+(?P<c>[\d.]+%?)\s+(?P<h>[\d.]+)(?:deg)?\s*(?:\/\s*(?P<a>[\d.]+%?)\s*)?\)$/i', trim($value), $matches)) {
            throw new RuntimeException(\sprintf('« %s » n\'est pas une couleur `oklch()` : la mesure de contraste ne sait pas la lire.', $value));
        }

        // L'alpha n'est pas ignoré : il est refusé.
        //
        // Une couleur translucide ne se mesure pas seule — son ratio dépend de ce qu'il y
        // a derrière, donc de l'empilement réel des éléments, que ce contrôle n'a aucun
        // moyen de connaître. La traiter comme opaque rendrait un ratio faux **en
        // silence**, et un ratio faux en silence est le pire mode d'échec possible pour
        // une mesure de contraste. Le jour où une paire mesurée porte un `/ 10%`, ce
        // message dit quoi faire : composer la couleur sur son fond avant de la mesurer.
        $alpha = $matches['a'] ?? '';

        if ('' !== $alpha && abs(self::number($alpha) - 1.0) > 1.0e-9) {
            throw new RuntimeException(\sprintf('« %s » est translucide : son ratio dépend de ce qu\'il y a derrière, que ce contrôle ne connaît pas. Composez la couleur sur son fond avant de la mesurer plutôt que de la traiter comme opaque.', $value));
        }

        $lightness = self::number($matches['l']);
        $chroma = self::number($matches['c'], 0.4);
        $hue = (float) $matches['h'];

        $a = $chroma * cos(deg2rad($hue));
        $b = $chroma * sin(deg2rad($hue));

        $long = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $medium = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $short = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            self::clamp(4.0767416621 * $long - 3.3077115913 * $medium + 0.2309699292 * $short),
            self::clamp(-1.2684380046 * $long + 2.6097574011 * $medium - 0.3413193965 * $short),
            self::clamp(-0.0041960863 * $long - 0.7034186147 * $medium + 1.7076147010 * $short),
        ];
    }

    /**
     * @param array{float, float, float} $linear
     */
    public static function luminance(array $linear): float
    {
        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    private static function number(string $raw, float $percentBase = 1.0): float
    {
        return str_ends_with($raw, '%')
            ? (float) rtrim($raw, '%') / 100 * $percentBase
            : (float) $raw;
    }

    private static function clamp(float $channel): float
    {
        return max(0.0, min(1.0, $channel));
    }
}
