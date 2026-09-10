<?php

declare(strict_types=1);

namespace App\Tests\Core\Theme;

use App\Tests\Core\Quality\GateFiles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Un seul kit visuel, une seule bibliothèque d'icônes (DESIGN.md, Do's and Don'ts).
 *
 * Le toolkit **copie** le code d'un composant dans le dépôt au lieu d'en dépendre : rien
 * dans `composer.lock` ne dira jamais qu'un composant Bootstrap s'est glissé à côté d'un
 * composant shadcn, et le bundle n'a aucune configuration où épingler le kit — le choix
 * ne vit que dans un `--kit` tapé en ligne de commande. La preuve doit donc venir des
 * fichiers copiés eux-mêmes, confrontés aux catalogues que le toolkit embarque.
 */
final class KitIntegrityTest extends TestCase
{
    private const string KIT = 'shadcn';
    private const string ICON_SET = 'tabler';
    private const string COMPONENTS = 'templates/components';

    /**
     * Les six composants que les stories 1.4, 1.6, 1.9 et 1.10 consommeront. Le reste du
     * kit se copie story par story, quand un écran l'utilise — un composant copié « au
     * cas où » est du code non lu que personne ne maintient.
     *
     * @var list<string>
     */
    private const array EXPECTED = ['Alert', 'Badge', 'Button', 'Card', 'Input', 'Label'];

    #[Test]
    public function the_toolkit_is_a_generator_not_a_runtime_dependency(): void
    {
        $composer = json_decode(GateFiles::read('composer.json'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);
        self::assertIsArray($composer['require'] ?? null);
        self::assertIsArray($composer['require-dev'] ?? null);

        self::assertArrayHasKey(
            'symfony/ux-toolkit',
            $composer['require-dev'],
            '`symfony/ux-toolkit` doit être en require-dev : il copie du code, il n\'en exécute pas.',
        );
        self::assertArrayNotHasKey(
            'symfony/ux-toolkit',
            $composer['require'],
            '`symfony/ux-toolkit` en `require` embarquerait un générateur en production.',
        );
    }

    #[Test]
    public function every_copied_component_belongs_to_the_declared_kit(): void
    {
        $catalogue = self::componentsOf(self::KIT);
        $copied = self::copiedComponents();

        self::assertNotSame([], $copied, 'Aucun composant n\'a été copié : le kit n\'est pas installé.');

        foreach ($copied as $component) {
            self::assertContains(
                $component,
                $catalogue,
                \sprintf('« %s » n\'appartient pas au kit « %s » : un second kit s\'est glissé dans le projet.', $component, self::KIT),
            );
        }
    }

    /**
     * Le contrôle par nom laisse passer le cas le plus vicieux : un composant qui existe
     * dans deux kits — `button`, `card`, `alert` — copié depuis le mauvais. Ce que ces
     * deux copies n'ont pas en commun, c'est leur vocabulaire de classes. Les tokens
     * qu'un kit rival emploie et que shadcn n'emploie jamais sont donc une signature, et
     * elle se calcule depuis `vendor/` plutôt que de se deviner.
     */
    #[Test]
    public function no_copied_component_speaks_the_vocabulary_of_a_rival_kit(): void
    {
        $ours = self::classVocabularyOf(self::KIT);
        $offences = [];

        foreach (self::kits() as $kit) {
            if (self::KIT === $kit) {
                continue;
            }

            $signature = array_diff(self::classVocabularyOf($kit), $ours);

            // Un contrôle qui ne cherche rien trouve toujours zéro. Si le vocabulaire
            // d'un kit rival est entièrement inclus dans celui de shadcn — ou si le
            // calcul depuis `vendor/` a rendu une liste vide — la boucle ci-dessous
            // parcourt les fichiers pour rien et le test reste vert quoi qu'il arrive.
            self::assertNotSame(
                [],
                $signature,
                \sprintf('Le kit « %s » n\'a aucune classe que shadcn n\'emploie jamais : le contrôle est vide et ne prouve rien.', $kit),
            );

            foreach (self::copiedFiles() as $file) {
                foreach (self::classTokens((string) file_get_contents($file->getPathname())) as $token) {
                    if (\in_array($token, $signature, true)) {
                        $offences[] = \sprintf(
                            '%s/%s emploie « %s », une classe du kit « %s » que shadcn n\'utilise jamais.',
                            self::COMPONENTS,
                            str_replace('\\', '/', $file->getRelativePathname()),
                            $token,
                            $kit,
                        );
                    }
                }
            }
        }

        self::assertSame([], array_values(array_unique($offences)), "Deux kits visuels dans le même projet :\n".implode("\n", array_unique($offences)));
    }

    #[Test]
    public function the_six_components_this_story_owes_are_installed(): void
    {
        $copied = self::copiedComponents();

        foreach (self::EXPECTED as $component) {
            self::assertContains(
                $component,
                $copied,
                \sprintf('Le composant « %s » manque : une story d\'écran devrait le réinventer.', $component),
            );
        }
    }

    /**
     * Une icône n'est pas un détail de style : deux bibliothèques, ce sont deux traits,
     * deux grilles et deux vocabulaires de noms sur le même écran.
     */
    #[Test]
    public function only_one_icon_library_is_configured(): void
    {
        $icons = self::iconsConfig()['ux_icons'] ?? null;

        self::assertIsArray($icons);

        $sets = $icons['icon_sets'] ?? null;

        self::assertIsArray($sets, '`ux_icons.icon_sets` ne déclare aucune bibliothèque : rien ne dit laquelle est la bonne.');
        self::assertSame(
            [self::ICON_SET],
            array_keys($sets),
            \sprintf('Une seule bibliothèque d\'icônes est admise, et c\'est « %s ».', self::ICON_SET),
        );
    }

    #[Test]
    public function no_template_reaches_for_a_second_icon_library(): void
    {
        $offences = [];

        foreach (self::twigFiles() as $file) {
            preg_match_all(
                '/(?:ux_icon\(\s*[\'"]|<twig:ux:icon[^>]*\bname=[\'"])(?P<set>[a-z0-9-]+):/i',
                (string) file_get_contents($file->getPathname()),
                $matches,
            );

            foreach ($matches['set'] as $set) {
                if (self::ICON_SET !== strtolower($set)) {
                    $offences[] = \sprintf('templates/%s référence la bibliothèque « %s ».', str_replace('\\', '/', $file->getRelativePathname()), $set);
                }
            }
        }

        self::assertSame([], $offences, "Une seconde bibliothèque d'icônes :\n".implode("\n", $offences));
    }

    /**
     * Les icônes sont verrouillées dans `assets/icons/` : sans cela, `ux-icons` va les
     * chercher chez Iconify **au moment du rendu**, donc une page de production dépend
     * d'une API tierce et d'un réseau sortant.
     */
    #[Test]
    public function on_demand_icon_download_is_off_outside_dev(): void
    {
        $everywhere = self::iconifyConfig(null);

        self::assertFalse(
            $everywhere['on_demand'] ?? null,
            'Le téléchargement à la demande doit être coupé par défaut : une icône manquante ne doit jamais partir chercher une API tierce en production.',
        );

        $development = self::iconifyConfig('when@dev');

        self::assertTrue($development['on_demand'] ?? null, 'Aucune reprise `when@dev` : le confort de développement a été supprimé avec le risque.');
        self::assertTrue(
            $development['auto_lock'] ?? null,
            '`auto_lock` écrit chaque icône rendue à la demande sur disque : sans lui, on découvre l\'oubli en production.',
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function iconifyConfig(?string $environment): array
    {
        $config = self::iconsConfig();

        if (null !== $environment) {
            $scoped = $config[$environment] ?? null;

            self::assertIsArray($scoped, \sprintf('`config/packages/ux_icons.yaml` n\'a pas de bloc `%s`.', $environment));

            $config = $scoped;
        }

        $icons = $config['ux_icons'] ?? null;

        self::assertIsArray($icons);

        $iconify = $icons['iconify'] ?? null;

        self::assertIsArray($iconify);

        return $iconify;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function iconsConfig(): array
    {
        $config = Yaml::parse(GateFiles::read('config/packages/ux_icons.yaml'));

        self::assertIsArray($config);

        return $config;
    }

    /**
     * @return list<string>
     */
    private static function copiedComponents(): array
    {
        $directory = GateFiles::projectDir().'/'.self::COMPONENTS;

        if (!is_dir($directory)) {
            return [];
        }

        $names = [];

        foreach (new Finder()->files()->in($directory)->name('*.html.twig')->depth('== 0') as $file) {
            $names[] = str_replace('.html.twig', '', $file->getFilename());
        }

        foreach (new Finder()->directories()->in($directory)->depth('== 0') as $child) {
            $names[] = $child->getFilename();
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    private static function copiedFiles(): Finder
    {
        return new Finder()->files()->in(GateFiles::projectDir().'/'.self::COMPONENTS)->name('*.html.twig')->sortByName();
    }

    /**
     * @return list<string>
     */
    private static function kits(): array
    {
        $names = [];

        foreach (new Finder()->directories()->in(self::kitsDirectory())->depth('== 0') as $kit) {
            $names[] = $kit->getFilename();
        }

        sort($names);

        return $names;
    }

    /**
     * Les noms de composants d'un kit, tels que `ux:install` les copierait.
     *
     * @return list<string>
     */
    private static function componentsOf(string $kit): array
    {
        $names = [];

        foreach (new Finder()->files()->in(self::kitsDirectory().'/'.$kit)->name('*.html.twig') as $file) {
            $names[] = str_replace('.html.twig', '', $file->getFilename());
        }

        // `depth('== 0')`, sinon le catalogue ramasse aussi `templates`, `components`,
        // `assets` et `controllers` — les répertoires de structure que chaque composant
        // du kit porte en interne. Un composant étranger nommé `components` passerait
        // alors le contrôle d'appartenance.
        foreach (new Finder()->directories()->in(self::kitsDirectory().'/'.$kit)->depth('== 0') as $directory) {
            $names[] = $directory->getFilename();
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Tous les tokens de classe employés par les templates d'un kit.
     *
     * @return list<string>
     */
    private static function classVocabularyOf(string $kit): array
    {
        $tokens = [];

        foreach (new Finder()->files()->in(self::kitsDirectory().'/'.$kit)->name('*.html.twig') as $file) {
            foreach (self::classTokens((string) file_get_contents($file->getPathname())) as $token) {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * @return list<string>
     */
    private static function classTokens(string $contents): array
    {
        preg_match_all('/[\'"]([^\'"\n]*)[\'"]/', $contents, $matches);

        $tokens = [];

        foreach ($matches[1] as $literal) {
            $words = preg_split('/\s+/', $literal);

            if (false === $words) {
                continue;
            }

            foreach ($words as $token) {
                // Un token de classe : lettres, chiffres, tirets, deux-points de
                // variante. Les fragments Twig et les phrases n'en sont pas.
                if (1 === preg_match('/^[a-z][a-z0-9:_-]{2,}$/', $token)) {
                    $tokens[$token] = true;
                }
            }
        }

        return array_keys($tokens);
    }

    private static function kitsDirectory(): string
    {
        $directory = GateFiles::projectDir().'/vendor/symfony/ux-toolkit/kits';

        self::assertDirectoryExists($directory, '`symfony/ux-toolkit` n\'est pas installé : rien ne peut être confronté à un catalogue.');

        return $directory;
    }

    private static function twigFiles(): Finder
    {
        return new Finder()->files()->in(GateFiles::projectDir().'/templates')->name('*.twig')->sortByName();
    }
}
