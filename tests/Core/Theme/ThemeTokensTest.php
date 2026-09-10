<?php

declare(strict_types=1);

namespace App\Tests\Core\Theme;

use App\Tests\Core\Quality\GateFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le thème du socle est vérifiable, pas promis.
 *
 * `DESIGN.md` fixe la liste des rôles et des jetons nommés ; `assets/styles/theme.css`
 * la tient. Ce test refuse un rôle manquant, un rôle sans variante sombre, et un jeton
 * de typographie, de rayon, d'espacement ou de largeur de contenu absent.
 *
 * La liste vit ici plutôt que d'être lue dans `DESIGN.md` à chaque exécution, pour la
 * raison qui a fait vivre `FLOOR` dans `QualityGateParityTest` : un dérivé peut
 * supprimer `_bmad-output/`, et sa suite doit continuer de vérifier son thème.
 * `design_md_declares_exactly_these_roles()` referme l'écart tant que le document est là.
 */
final class ThemeTokensTest extends TestCase
{
    /**
     * Le chemin du spine UX dont la liste de rôles est la transcription. Absent chez un
     * dérivé qui a nettoyé ses artefacts de planification — le test se saute alors.
     */
    private const string DESIGN = '_bmad-output/planning-artifacts/ux-designs/ux-proglab-skills-2026-09-09/DESIGN.md';

    /**
     * Les rôles de couleur de `DESIGN.md` (frontmatter `colors:`, l.14-79), sans le
     * suffixe `-dark` : chacun existe en clair **et** en sombre, et c'est exactement ce
     * que ce test vérifie.
     *
     * @var list<string>
     */
    private const array ROLES = [
        'background',
        'foreground',
        'page',
        'card',
        'card-foreground',
        'muted',
        'muted-foreground',
        'muted-strong',
        'primary',
        'primary-foreground',
        'secondary',
        'secondary-foreground',
        'accent',
        'accent-foreground',
        'destructive',
        'destructive-foreground',
        'border',
        'input',
        'ring',
        'track',
        'sidebar',
        'sidebar-foreground',
        'sidebar-accent',
        'status-done',
        'status-done-foreground',
        'status-doing',
        'status-todo',
        'status-ready',
        'status-unreadable',
    ];

    /**
     * Les douze rôles typographiques (`DESIGN.md` l.80-144). Chacun porte sa taille, sa
     * graisse et son interligne : une taille sans sa graisse laisse la hiérarchie se
     * rejouer classe par classe dans les templates.
     *
     * @var list<string>
     */
    private const array TYPE_ROLES = [
        'display',
        'heading',
        'subheading',
        'lead',
        'body',
        'body-sm',
        'label',
        'meta',
        'caption',
        'badge',
        'stat',
        'mono',
    ];

    /**
     * Rayons (l.145-151), espacement nommé et largeurs de contenu (l.152-174), hauteurs
     * de composants (l.175-330) et durées de mouvement (l.647-652).
     *
     * @var list<string>
     */
    private const array NAMED_TOKENS = [
        '--font-sans',
        '--font-mono',
        '--radius',
        '--radius-sm',
        '--radius-md',
        '--radius-lg',
        '--radius-xl',
        '--radius-full',
        '--spacing-gutter-desktop',
        '--spacing-gutter-mobile',
        '--spacing-card-padding',
        '--spacing-card-gap',
        '--spacing-row-padding',
        '--spacing-sidebar-width',
        '--spacing-header-height',
        '--spacing-touch-target-min',
        '--spacing-control-height',
        '--spacing-badge-height',
        '--container-content',
        '--container-content-table',
        '--motion-fade',
        '--motion-panel',
    ];

    /**
     * Ce qu'un dérivé a le droit de redéfinir (`DESIGN.md` l.398-409). Tout le reste
     * hérite, et `theme.css` reste un `git diff` propre au report d'un correctif.
     *
     * @var list<string>
     */
    private const array REBRANDABLE = [
        'primary',
        'primary-foreground',
        'ring',
        'input',
        'destructive',
        'destructive-foreground',
        'sidebar',
        'sidebar-foreground',
        'sidebar-accent',
    ];

    #[Test]
    public function the_socle_ships_its_three_stylesheets(): void
    {
        foreach ([ThemeSheet::APP, ThemeSheet::THEME, ThemeSheet::BRAND] as $sheet) {
            self::assertTrue(
                ThemeSheet::exists($sheet),
                \sprintf('« %s » est absent : le thème du socle n\'a pas de feuille où vivre.', $sheet),
            );
        }
    }

    #[Test]
    #[DataProvider('roles')]
    public function every_color_role_of_the_design_is_declared(string $role): void
    {
        self::assertArrayHasKey(
            '--'.$role,
            ThemeSheet::block(ThemeSheet::THEME, ThemeSheet::LIGHT),
            \sprintf('Le rôle « --%s » de DESIGN.md n\'est pas déclaré dans le bloc clair de %s.', $role, ThemeSheet::THEME),
        );
    }

    /**
     * Une variante sombre orpheline est le trou le plus discret d'un thème : l'écran
     * paraît juste tant que personne n'ouvre le mode sombre.
     *
     * Un rôle a deux façons légitimes de suivre le sombre : être redéclaré dans les deux
     * blocs sombres, ou aliaser un rôle qui l'est (`--status-done: var(--primary)`) —
     * c'est cet alias qui fait suivre le statut « Terminé » au rebranding.
     */
    #[Test]
    #[DataProvider('roles')]
    public function every_color_role_carries_a_dark_variant(string $role): void
    {
        $light = ThemeSheet::block(ThemeSheet::THEME, ThemeSheet::LIGHT);
        $seen = [];
        $current = $role;

        while (1 === preg_match('/^var\(\s*(?P<target>--[a-z0-9-]+)\s*\)$/i', $light['--'.$current] ?? '', $matches)) {
            self::assertArrayNotHasKey($current, $seen, \sprintf('« --%s » est un alias circulaire.', $role));
            $seen[$current] = true;

            $current = substr(strtolower($matches['target']), 2);

            self::assertContains(
                $current,
                self::ROLES,
                \sprintf('« --%s » est l\'alias de « --%s », qui n\'est pas un rôle de DESIGN.md.', $role, $current),
            );
        }

        foreach ([ThemeSheet::SYSTEM_DARK, ThemeSheet::FORCED_DARK] as $selector) {
            self::assertArrayHasKey(
                '--'.$current,
                ThemeSheet::block(ThemeSheet::THEME, $selector),
                \sprintf(
                    'Le rôle « --%s » n\'a pas de variante sombre dans le bloc « %s » : l\'écran paraîtra juste jusqu\'à ce que quelqu\'un ouvre le mode sombre.',
                    $current,
                    $selector,
                ),
            );
        }
    }

    /**
     * Le sombre est écrit deux fois, faute de pouvoir mettre un `@media` dans une liste
     * de sélecteurs. Une copie qui dérive de l'autre, c'est un thème qui n'est juste que
     * dans un des deux chemins — et celui qu'on regarde en développement est rarement
     * celui qui casse.
     */
    #[Test]
    public function the_two_dark_blocks_never_diverge(): void
    {
        self::assertSame(
            ThemeSheet::block(ThemeSheet::THEME, ThemeSheet::SYSTEM_DARK),
            ThemeSheet::block(ThemeSheet::THEME, ThemeSheet::FORCED_DARK),
            \sprintf(
                'Le sombre suivi par « %s » et celui forcé par « %s » ont divergé.',
                ThemeSheet::SYSTEM_DARK,
                ThemeSheet::FORCED_DARK,
            ),
        );
    }

    /**
     * Les alias de statut suivent le rebranding parce qu'ils *sont* les rôles du socle,
     * pas des copies de leurs valeurs. Changer `--primary` dans `brand.css` doit
     * repeindre le badge « Terminé » sans qu'aucun autre fichier bouge.
     */
    #[Test]
    public function the_done_status_follows_primary(): void
    {
        $values = ThemeSheet::block(ThemeSheet::THEME, ThemeSheet::LIGHT);

        self::assertSame(
            'var(--primary)',
            $values['--status-done'] ?? null,
            'Le statut « Terminé » doit aliaser `--primary`, sinon un dérivé qui recolore sa marque garde un badge noir.',
        );
        self::assertSame(
            'var(--primary-foreground)',
            $values['--status-done-foreground'] ?? null,
            'Le texte du statut « Terminé » doit aliaser `--primary-foreground`.',
        );
    }

    /**
     * Le mécanisme qui fait tenir tout le contrat de rebranding, et qui tient à un mot.
     *
     * `@theme` fige la valeur à la compilation : `bg-primary` sortirait en
     * `background-color: oklch(0.205 0 0)`, et une redéfinition de `--primary` dans
     * `brand.css` ne repeindrait plus rien. `@theme inline` fait sortir
     * `var(--primary)`, résolu à l'exécution — c'est ce qui fait qu'un dérivé change une
     * variable et que tout le reste suit, y compris le mode sombre.
     */
    #[Test]
    #[DataProvider('roles')]
    public function every_color_role_is_exposed_inline_so_a_rebrand_cascades(string $role): void
    {
        $exposed = ThemeSheet::block(ThemeSheet::THEME, '@theme inline {');

        self::assertSame(
            \sprintf('var(--%s)', $role),
            $exposed['--color-'.$role] ?? null,
            \sprintf(
                'Tailwind doit exposer « --color-%s » comme `var(--%s)` dans le bloc `@theme inline` : autrement la valeur est figée à la compilation et redéfinir « --%s » dans `brand.css` ne repeint rien.',
                $role,
                $role,
                $role,
            ),
        );
    }

    #[Test]
    #[DataProvider('typeRoles')]
    public function every_typographic_role_carries_its_size_weight_and_leading(string $role): void
    {
        $values = ThemeSheet::values(ThemeSheet::THEME);

        foreach (['', '--font-weight', '--line-height'] as $facet) {
            self::assertArrayHasKey(
                '--text-'.$role.$facet,
                $values,
                \sprintf('Le jeton « --text-%s%s » manque : le rôle « %s » de DESIGN.md se rejouerait classe par classe.', $role, $facet, $role),
            );
        }
    }

    #[Test]
    #[DataProvider('namedTokens')]
    public function every_named_token_is_declared(string $token): void
    {
        self::assertArrayHasKey(
            $token,
            ThemeSheet::values(ThemeSheet::THEME),
            \sprintf('Le jeton nommé « %s » manque : sa valeur se répéterait dans chaque template qui en a besoin.', $token),
        );
    }

    /**
     * `DESIGN.md` l.647-652 : sous `prefers-reduced-motion: reduce`, toutes les durées
     * tombent à zéro. Un jeton de durée qui ne se remet pas à zéro est un jeton qui ment.
     */
    #[Test]
    public function motion_tokens_fall_to_zero_under_reduced_motion(): void
    {
        $css = implode("\n", ThemeSheet::activeLines(ThemeSheet::THEME));

        $reduced = preg_split('/@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)/', $css);

        self::assertIsArray($reduced);
        self::assertArrayHasKey(
            1,
            $reduced,
            'Aucun bloc `prefers-reduced-motion: reduce` : les durées du thème s\'appliquent à qui a demandé qu\'elles ne s\'appliquent pas.',
        );

        foreach (['--motion-fade', '--motion-panel'] as $token) {
            self::assertSame(
                1,
                preg_match('/'.preg_quote($token, '/').'\s*:\s*0m?s\s*;/', $reduced[1]),
                \sprintf('« %s » ne retombe pas à 0 sous `prefers-reduced-motion: reduce`.', $token),
            );
        }
    }

    /**
     * Le mode sombre suit `prefers-color-scheme` par défaut et se force par la classe
     * `dark` sur `<html>` (EXPERIENCE.md l.367-370). `color-scheme` est le levier des
     * deux : `light dark` laisse le système décider, la classe le fige.
     */
    #[Test]
    public function dark_mode_follows_the_system_and_can_be_forced_by_a_class(): void
    {
        $css = ThemeSheet::stripComments(GateFiles::read(ThemeSheet::THEME));

        self::assertSame(
            1,
            preg_match('/@media\s*\(\s*prefers-color-scheme\s*:\s*dark\s*\)\s*\{\s*'.preg_quote(ThemeSheet::SYSTEM_DARK, '/').'/s', $css),
            'Le sombre ne suit pas `prefers-color-scheme` : il faudrait cliquer une bascule pour qu\'un système sombre soit respecté.',
        );
        self::assertSame(
            1,
            preg_match('/'.preg_quote(ThemeSheet::FORCED_DARK, '/').'/', $css),
            'La classe `dark` sur `<html>` ne force rien : la bascule de l\'Epic 5 n\'aurait aucun effet.',
        );

        // `:root:not(.light)` est ce qui rend « forcer clair sous un système sombre »
        // possible sans troisième bloc.
        self::assertLessThan(
            strpos($css, ThemeSheet::FORCED_DARK),
            strpos($css, ThemeSheet::SYSTEM_DARK),
            'Le bloc forcé doit venir après le bloc système : à spécificité égale, c\'est l\'ordre qui décide, et une bascule qui perd contre le système ne sert à rien.',
        );

        foreach ([ThemeSheet::SYSTEM_DARK, ThemeSheet::FORCED_DARK] as $selector) {
            self::assertSame(
                1,
                preg_match('/'.preg_quote($selector, '/').'\s*(?:[^}]*?)color-scheme\s*:\s*dark\s*;/s', $css),
                \sprintf('« %s » ne déclare pas `color-scheme: dark` : les contrôles natifs et les barres de défilement resteraient clairs.', $selector),
            );
        }
    }

    /**
     * Le principe « défauts shadcn » (`DESIGN.md` l.432-440) : un défaut du kit sous un
     * seuil WCAG est conservé **et documenté**, jamais consigné en dette. Non documenté,
     * il se lit comme un oubli et quelqu'un le « corrige » à la première revue.
     */
    #[Test]
    public function the_two_sub_threshold_kit_defaults_are_documented_as_deliberate(): void
    {
        $css = GateFiles::read(ThemeSheet::THEME);

        foreach (['--input', '--ring'] as $token) {
            self::assertMatchesRegularExpression(
                '/'.preg_quote($token, '/').'\b.{0,800}?choix assumé/su',
                $css,
                \sprintf('« %s » est un défaut du kit sous le seuil WCAG et rien ne dit qu\'il est assumé : la prochaine revue le prendra pour une dette.', $token),
            );
        }
    }

    /**
     * `brand.css` est le seul fichier qu'un dérivé édite. Il est livré sans aucune
     * déclaration active — sinon le socle imposerait une marque — et chaque variable
     * qu'il autorise y est nommée en commentaire, avec sa valeur du socle.
     */
    #[Test]
    public function the_brand_sheet_ships_empty_and_only_opens_what_may_be_rebranded(): void
    {
        foreach (ThemeSheet::declarations(ThemeSheet::BRAND) as $declaration) {
            self::assertContains(
                substr($declaration['name'], 2),
                self::REBRANDABLE,
                \sprintf(
                    '%s:%d redéfinit « %s », qui n\'est pas dans le contrat de rebranding : le socle ne peut plus reporter un correctif de thème.',
                    ThemeSheet::BRAND,
                    $declaration['line'],
                    $declaration['name'],
                ),
            );
        }

        $brand = GateFiles::read(ThemeSheet::BRAND);

        foreach (self::REBRANDABLE as $role) {
            self::assertStringContainsString(
                '--'.$role,
                $brand,
                \sprintf('« --%s » est rebrandable et n\'est pas proposé dans %s : personne ne saura qu\'il l\'est.', $role, ThemeSheet::BRAND),
            );
        }

        self::assertStringContainsString(
            'contraste',
            $brand,
            'Rien ne rappelle de remesurer le contraste après un rebranding (DESIGN.md l.407-409).',
        );
    }

    /**
     * Le piège que `brand.css` annonce lui-même, tenu par un test plutôt que par la
     * bonne volonté du dérivé.
     *
     * Une variable de marque se redéfinit **trois fois** : dans `:root` pour le clair, et
     * dans les deux blocs sombres — celui que le système déclenche, celui que la classe
     * `dark` force. En oublier un, c'est un thème juste sur un seul des deux chemins
     * sombres, et l'écran paraît correct chez qui n'ouvre jamais l'autre.
     *
     * Le socle livre `brand.css` vide, donc ce test n'a rien à vérifier ici. Il existe
     * pour le dérivé, qui hérite de la suite avec le fichier.
     */
    #[Test]
    public function a_brand_override_is_declared_on_all_three_paths(): void
    {
        $light = ThemeSheet::block(ThemeSheet::BRAND, ThemeSheet::LIGHT);
        $system = ThemeSheet::block(ThemeSheet::BRAND, ThemeSheet::SYSTEM_DARK);
        $forced = ThemeSheet::block(ThemeSheet::BRAND, ThemeSheet::FORCED_DARK);

        foreach (array_keys($light) as $name) {
            self::assertArrayHasKey(
                $name,
                $system,
                \sprintf('« %s » est redéfini en clair dans %s et pas dans le bloc sombre du système : le mode sombre garderait la valeur du socle.', $name, ThemeSheet::BRAND),
            );
            self::assertArrayHasKey(
                $name,
                $forced,
                \sprintf('« %s » est redéfini en clair dans %s et pas dans le bloc sombre forcé : la bascule de l\'Epic 5 garderait la valeur du socle.', $name, ThemeSheet::BRAND),
            );
        }

        self::assertSame(
            array_keys($system),
            array_keys($forced),
            'Les deux blocs sombres de la marque ne redéfinissent pas les mêmes variables : le thème diverge selon le chemin emprunté.',
        );
    }

    /**
     * L'ordre des imports **est** le contrat de rebranding : Tailwind, le kit, le socle,
     * puis la marque. Une marque importée avant le socle ne gagne rien.
     */
    #[Test]
    public function the_entrypoint_imports_tailwind_then_the_socle_then_the_brand(): void
    {
        preg_match_all('/@import\s+[\'"]?([^\'";]+)/', GateFiles::read(ThemeSheet::APP), $matches);

        $positions = [];

        foreach (['tailwindcss', 'theme.css', 'brand.css'] as $needle) {
            foreach ($matches[1] as $index => $import) {
                if (str_contains($import, $needle)) {
                    $positions[$needle] = $index;

                    break;
                }
            }

            self::assertArrayHasKey($needle, $positions, \sprintf('%s n\'importe pas « %s ».', ThemeSheet::APP, $needle));
        }

        self::assertLessThan($positions['theme.css'], $positions['tailwindcss'], 'Tailwind doit être importé avant le thème du socle.');
        self::assertLessThan($positions['brand.css'], $positions['theme.css'], 'La marque doit être importée après le socle, sinon elle ne le surcharge pas.');
    }

    /**
     * Le garde-fou du garde-fou : tant que `DESIGN.md` est là, la liste ci-dessus doit
     * être sa transcription exacte — ni un rôle oublié, ni un rôle inventé (`popover*`,
     * `chart-*`, `sidebar-border` n'entrent pas).
     */
    #[Test]
    public function design_md_declares_exactly_these_roles(): void
    {
        if (!ThemeSheet::exists(self::DESIGN)) {
            self::markTestSkipped('Le spine UX n\'est pas dans ce dépôt : la liste de ce test fait foi seule.');
        }

        $lines = preg_split('/\R/', GateFiles::read(self::DESIGN));

        self::assertIsArray($lines);

        $roles = [];
        $inside = false;

        foreach ($lines as $line) {
            if ('colors:' === rtrim($line)) {
                $inside = true;

                continue;
            }

            if (!$inside) {
                continue;
            }

            // La clé de premier niveau suivante (`typography:`) referme le bloc.
            if ('' !== rtrim($line) && !str_starts_with($line, ' ')) {
                break;
            }

            if (1 !== preg_match('/^ {2}(?P<role>[a-z0-9-]+):/', $line, $matches)) {
                continue;
            }

            $role = $matches['role'];
            $roles[str_ends_with($role, '-dark') ? substr($role, 0, -5) : $role] = true;
        }

        self::assertTrue($inside, 'DESIGN.md n\'a pas de bloc `colors:`.');

        $expected = self::ROLES;
        $actual = array_keys($roles);
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual, 'La liste de rôles de ce test a divergé de DESIGN.md.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function roles(): iterable
    {
        foreach (self::ROLES as $role) {
            yield $role => [$role];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function typeRoles(): iterable
    {
        foreach (self::TYPE_ROLES as $role) {
            yield $role => [$role];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namedTokens(): iterable
    {
        foreach (self::NAMED_TOKENS as $token) {
            yield $token => [$token];
        }
    }
}
