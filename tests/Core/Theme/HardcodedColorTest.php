<?php

declare(strict_types=1);

namespace App\Tests\Core\Theme;

use App\Tests\Core\Quality\GateFiles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * « Aucune couleur codée en dur dans un template » (DESIGN.md, Do's and Don'ts).
 *
 * C'est la promesse la plus facile à trouer : un écran pressé écrit `text-red-500`, la
 * page paraît juste, et le rebranding cesse silencieusement de fonctionner sur ce
 * fragment-là. Un thème ne se relit pas, il se teste.
 *
 * Le scan porte sur les trois répertoires que `assets/styles/app.css` déclare en
 * `@source` — `templates/`, `src/` et `assets/controllers/` — et pas sur les seuls
 * templates : une classe écrite en PHP ou dans un contrôleur Stimulus compile dans la
 * feuille livrée exactement comme une classe écrite en Twig.
 *
 * Ce que ce test ne voit pas, et qui est assumé : le `href` du favicon de la recette
 * Symfony dans `templates/base.html.twig` porte un `%23fff` **percent-encodé** à
 * l'intérieur d'une image `data:`. Il disparaît avec ce gabarit à la story 1.4, qui
 * livre le vrai `base.html.twig` du socle.
 */
final class HardcodedColorTest extends TestCase
{
    /**
     * Les palettes Tailwind par défaut. Aucune n'a sa place ici : le socle est
     * achromatique et chaque teinte qu'il utilise a un rôle nommé.
     */
    private const string PALETTES = 'red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|grey|zinc|neutral|stone';

    /**
     * Les propriétés Tailwind qui prennent une couleur.
     */
    private const string COLOR_UTILITIES = 'bg|text|border|ring|outline|fill|stroke|from|via|to|decoration|shadow|accent|caret|divide|placeholder';

    /**
     * @var array<string, string> motif => ce qu'il attrape
     */
    private const array FORBIDDEN = [
        // #fff, #ffffff, #ffffffff — la forme la plus directe.
        '/#[0-9a-f]{3}(?:[0-9a-f]{1}|[0-9a-f]{3}|[0-9a-f]{5})?\b/i' => 'une couleur hexadécimale',
        // rgb(), hsl(), oklch(), color-mix()… — une valeur littérale reste une valeur
        // littérale, quelle que soit son espace colorimétrique.
        '/\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color-mix)\s*\(/i' => 'une fonction de couleur littérale',
        // bg-red-500, text-gray-400, border-emerald-200…
        '/\b(?:'.self::COLOR_UTILITIES.')-(?:'.self::PALETTES.')-\d{2,3}\b/' => 'une couleur de la palette Tailwind',
        // bg-white, text-black — pas de palier, mais tout aussi codées en dur.
        '/\b(?:'.self::COLOR_UTILITIES.')-(?:white|black)\b/' => 'une couleur absolue Tailwind',
        // style="color: white" et consorts.
        '/\b(?:color|background|background-color|border-color|outline-color|fill|stroke)\s*:\s*(?:white|black|red|green|blue|grey|gray|silver|orange|yellow|purple|pink)\b/i' => 'une couleur nommée en CSS',
    ];

    #[Test]
    public function no_template_hardcodes_a_color(): void
    {
        $offences = [];

        foreach (self::scanned() as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen(GateFiles::projectDir()) + 1));
            $lines = preg_split('/\R/', (string) file_get_contents($file->getPathname()));

            if (false === $lines) {
                continue;
            }

            foreach ($lines as $index => $line) {
                foreach (self::FORBIDDEN as $pattern => $what) {
                    if (1 === preg_match($pattern, $line, $matches)) {
                        $offences[] = \sprintf(
                            '%s:%d porte %s (« %s ») — le rebranding s\'arrête à cette ligne.',
                            $relative,
                            $index + 1,
                            $what,
                            trim($matches[0]),
                        );
                    }
                }
            }
        }

        self::assertSame([], $offences, "Une couleur codée en dur :\n".implode("\n", $offences));
    }

    /**
     * Le test doit pouvoir échouer : si un répertoire est vide ou mal résolu, l'assertion
     * ci-dessus est verte pour la mauvaise raison — et elle l'est en silence, répertoire
     * par répertoire, d'où la boucle plutôt qu'un compte global.
     */
    #[Test]
    public function the_scan_actually_reads_the_declared_sources(): void
    {
        foreach (self::SOURCES as $directory => $pattern) {
            self::assertNotSame(
                0,
                iterator_count(self::finderFor($directory, $pattern)),
                \sprintf('« %s » est déclaré en `@source` et n\'a fourni aucun fichier à scanner.', $directory),
            );
        }
    }

    /**
     * Les trois `@source` de `assets/styles/app.css`, chacun avec l'extension qui l'a
     * fait entrer dans le scan. En ajouter un là-bas sans l'ajouter ici rouvre le trou.
     *
     * Les motifs de `Finder` ne sont pas cloisonnés par répertoire : les trois
     * s'appliquent aux trois. C'est plus large que nécessaire, jamais plus étroit.
     *
     * @var array<string, string>
     */
    private const array SOURCES = [
        'templates' => '*.twig',
        'src' => '*.php',
        'assets/controllers' => '*.js',
    ];

    private static function scanned(): Finder
    {
        $finder = new Finder()->files()->sortByName();

        foreach (self::SOURCES as $directory => $pattern) {
            $finder->in(GateFiles::projectDir().'/'.$directory)->name($pattern);
        }

        return $finder;
    }

    private static function finderFor(string $directory, string $pattern): Finder
    {
        return new Finder()
            ->files()
            ->in(GateFiles::projectDir().'/'.$directory)
            ->name($pattern);
    }
}
