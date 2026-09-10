<?php

declare(strict_types=1);

namespace App\Tests\Core\Theme;

use App\Tests\Core\Quality\GateFiles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * La chaîne d'assets se lit dans des fichiers de configuration, donc elle se teste en les
 * lisant — le précédent est `BoundaryTest`, qui teste `deptrac.yaml` de la même façon.
 *
 * Deux promesses tiennent ici. La première : aucun bundler, aucune étape Node (AD-1) — un
 * binaire Tailwind autonome, téléchargé par le bundle. La seconde : **le même** binaire
 * d'une release à l'autre. Non épinglée, `binary_version` récupère le dernier CLI publié,
 * si bien qu'une version majeure de Tailwind peut arriver sur un runner sans cache un
 * jour où personne n'a touché à un fichier CSS.
 */
final class AssetPipelineTest extends TestCase
{
    private const string TAILWIND = 'config/packages/symfonycasts_tailwind.yaml';

    #[Test]
    public function the_tailwind_binary_is_pinned(): void
    {
        self::assertTrue(ThemeSheet::exists(self::TAILWIND), \sprintf('« %s » est absent.', self::TAILWIND));

        $config = Yaml::parse(GateFiles::read(self::TAILWIND));

        self::assertIsArray($config);
        self::assertIsArray($config['symfonycasts_tailwind'] ?? null, \sprintf('« %s » ne configure pas le bundle.', self::TAILWIND));

        $version = $config['symfonycasts_tailwind']['binary_version'] ?? null;

        self::assertIsString(
            $version,
            \sprintf('`binary_version` manque dans « %s » : deux releases du même dérivé ne se bâtiraient pas avec le même binaire Tailwind.', self::TAILWIND),
        );
        self::assertMatchesRegularExpression(
            '/^v\d+\.\d+\.\d+$/',
            $version,
            \sprintf('`binary_version: %s` n\'épingle pas une version exacte.', $version),
        );
    }

    #[Test]
    public function the_tailwind_entrypoint_is_explicit(): void
    {
        $config = Yaml::parse(GateFiles::read(self::TAILWIND));

        self::assertIsArray($config);
        self::assertIsArray($config['symfonycasts_tailwind'] ?? null);

        $input = $config['symfonycasts_tailwind']['input_css'] ?? null;

        self::assertIsArray($input, '`input_css` doit être explicite : la valeur par défaut du bundle est un détail d\'implémentation.');
        self::assertCount(1, $input, 'Un seul point d\'entrée CSS : deux feuilles compilées séparément, ce sont deux thèmes qui divergent.');
        self::assertIsString($input[0] ?? null);
        self::assertStringEndsWith(ThemeSheet::APP, str_replace('\\', '/', $input[0]));
    }

    /**
     * `missing_import_mode: warn` partout, c'est un import cassé qui part silencieusement
     * en production et qu'un utilisateur découvre. En développement, il doit crier.
     */
    #[Test]
    public function a_broken_asset_import_fails_loudly_in_development(): void
    {
        $config = Yaml::parse(GateFiles::read('config/packages/asset_mapper.yaml'));

        self::assertIsArray($config);
        self::assertIsArray($config['framework'] ?? null);
        self::assertIsArray($config['framework']['asset_mapper'] ?? null, '`framework.asset_mapper` n\'est pas configuré.');

        self::assertSame(
            'strict',
            $config['framework']['asset_mapper']['missing_import_mode'] ?? null,
            '`missing_import_mode: strict` manque : une faute de frappe dans un `@import` passerait inaperçue jusqu\'en production.',
        );
        self::assertSame(
            ['assets/'],
            $config['framework']['asset_mapper']['paths'] ?? null,
            '`assets/` n\'est pas le chemin mappé : rien de ce que cette story livre ne serait servi.',
        );
    }

    /**
     * `assets/vendor/` est commité (AD-1, déploiement sans réseau sortant) : le
     * `.gitignore` de la recette AssetMapper l'exclut par défaut, et l'oublier fait
     * échouer le premier déploiement d'un serveur client, pas la CI.
     */
    #[Test]
    public function the_javascript_vendor_directory_is_committed(): void
    {
        $ignore = GateFiles::read('.gitignore');

        foreach (GateFiles::activeLines('.gitignore') as $line => $contents) {
            self::assertNotSame(
                '/assets/vendor/',
                trim($contents),
                \sprintf('.gitignore:%d exclut `assets/vendor/`, que le socle commite pour se déployer sans réseau sortant.', $line),
            );
        }

        self::assertStringContainsString(
            '/public/assets/',
            $ignore,
            'La sortie de `asset-map:compile` (`public/assets/`) doit être ignorée : c\'est une compilation, pas une source.',
        );
    }

    #[Test]
    public function the_javascript_entrypoint_pulls_the_stylesheet_in(): void
    {
        self::assertTrue(ThemeSheet::exists('assets/app.js'), '`assets/app.js` est absent : rien n\'est déclaré en point d\'entrée.');

        self::assertStringContainsString(
            "import './styles/app.css';",
            GateFiles::read('assets/app.js'),
            'Le point d\'entrée n\'importe pas la feuille de style : AssetMapper ne la mettrait pas dans la map, et `importmap()` ne générerait aucun `<link>`.',
        );

        $importmap = require GateFiles::projectDir().'/importmap.php';

        self::assertIsArray($importmap);
        self::assertSame(
            ['path' => './assets/app.js', 'entrypoint' => true],
            $importmap['app'] ?? null,
            '`importmap.php` ne déclare pas `app` comme point d\'entrée : `importmap(\'app\')` n\'aurait rien à rendre.',
        );

        // Les deux feuilles du kit shadcn sont épinglées comme dépendances CSS : le kit
        // est un design system Tailwind, il ne produit rien de stylé sans elles.
        foreach (['shadcn/dist/tailwind.css', 'tw-animate-css/dist/tw-animate.css'] as $sheet) {
            self::assertArrayHasKey($sheet, $importmap, \sprintf('« %s » n\'est pas épinglée dans `importmap.php`.', $sheet));
        }
    }

    /**
     * Le gabarit doit réellement brancher la chaîne, sinon le thème est compilé et
     * jamais servi. La story 1.4 remplace ce fichier ; d'ici là, ces deux lignes sont
     * tout ce qu'il porte.
     */
    #[Test]
    public function the_base_template_renders_the_importmap(): void
    {
        $template = GateFiles::read('templates/base.html.twig');

        self::assertStringContainsString('importmap(', $template, '`base.html.twig` n\'appelle pas `importmap()` : ni la feuille ni le JS n\'atteignent la page.');
    }

    /**
     * « Aucune étape Node » se vérifie par ce qui n'est pas là. Un `package.json` apparu
     * pour une seule dépendance, et le marché d'AD-1 est rompu : il faut alors un
     * runtime Node en CI, un second lockfile à tenir à jour et une seconde source de
     * vulnérabilités que `composer audit` ne voit pas.
     */
    #[Test]
    public function nothing_in_the_chain_needs_node(): void
    {
        $forbidden = [
            'package.json' => 'un manifeste npm',
            'package-lock.json' => 'un lockfile npm',
            'yarn.lock' => 'un lockfile yarn',
            'pnpm-lock.yaml' => 'un lockfile pnpm',
            'node_modules' => 'un répertoire de dépendances npm',
            'webpack.config.js' => 'une configuration Webpack Encore',
            'vite.config.js' => 'une configuration Vite',
            'tailwind.config.js' => 'une configuration Tailwind en JavaScript — Tailwind 4 se configure en CSS',
        ];

        foreach ($forbidden as $path => $what) {
            self::assertFileDoesNotExist(
                GateFiles::projectDir().'/'.$path,
                \sprintf('« %s » est %s : la chaîne d\'assets du socle n\'a pas d\'étape Node (AD-1).', $path, $what),
            );
        }
    }

    /**
     * Le seul test de ce répertoire qui exécute réellement quelque chose.
     *
     * Tout le reste lit des fichiers ; celui-ci lance le binaire autonome et regarde ce
     * qui sort. C'est la seule façon de prouver l'affirmation centrale de la story —
     * un CSS produit sans npm — plutôt que de la déduire de la configuration. La sortie
     * est supprimée d'abord : un fichier laissé par un `--watch` rendrait ce test vert
     * sans que rien n'ait été compilé.
     */
    #[Test]
    public function the_stylesheet_is_really_compiled_by_the_standalone_binary(): void
    {
        $built = GateFiles::projectDir().'/var/tailwind/app.built.css';
        $previous = is_file($built) ? file_get_contents($built) : null;

        // La feuille est supprimée avant le build, sinon celle qu'un `--watch` a laissée
        // rendrait ce test vert sans que rien n'ait été compilé. Elle est rendue si le
        // build échoue : en environnement de test, une feuille absente fait tomber tout
        // test fonctionnel qui rend une page, et un échec de téléchargement du binaire
        // deviendrait une cascade au lieu d'un échec.
        if (null !== $previous) {
            unlink($built);
        }

        try {
            [$exitCode, $output] = self::execute([\PHP_BINARY, 'bin/console', 'tailwind:build']);

            self::assertSame(0, $exitCode, \sprintf("`tailwind:build` a échoué :\n%s", $output));
            self::assertFileExists($built, '`tailwind:build` n\'a produit aucune feuille.');
        } finally {
            if (!is_file($built) && \is_string($previous)) {
                file_put_contents($built, $previous);
            }
        }

        $css = (string) file_get_contents($built);

        // Un jeton que seul le socle ajoute : sa présence prouve que c'est bien
        // `theme.css` qui a été compilé, et pas une feuille par défaut du kit.
        self::assertStringContainsString(
            '--muted-strong:',
            $css,
            'La feuille compilée ne porte pas les jetons du socle : `input_css` ne pointe pas sur `assets/styles/app.css`.',
        );

        // Et une classe qui n'existe que parce qu'un template la demande : c'est la
        // preuve que les `@source` déclarés sont réellement scannés.
        self::assertStringContainsString(
            'data-slot',
            $css,
            'Aucune règle issue des composants copiés : les `@source` de `app.css` ne couvrent pas `templates/`.',
        );

        // Le contrat de rebranding, observé sur la sortie plutôt que déduit de la source :
        // `@theme inline` doit faire sortir `var(--primary)`, résolu à l'exécution. Avec
        // un `@theme` ordinaire, la valeur serait figée ici et `brand.css` ne repeindrait
        // plus rien.
        self::assertMatchesRegularExpression(
            '/\.bg-primary\s*\{\s*background-color:\s*var\(--primary\)/',
            $css,
            '`bg-primary` ne compile pas en `var(--primary)` : un dérivé qui redéfinit `--primary` dans `brand.css` ne repeindrait rien.',
        );

        // `@custom-variant dark` est la ligne qui fait tenir le sombre à deux chemins.
        // Le remplacer par le one-liner du kit — `(&:is(.dark *))` — ne casse aucun
        // jeton et ne se voit nulle part ailleurs : chaque utilitaire `dark:` cesserait
        // simplement de répondre à `prefers-color-scheme`.
        foreach ([':where(:not(.light, .light *))', ':where(.dark, .dark *)'] as $guard) {
            self::assertStringContainsString(
                '.dark\:border-input'.$guard,
                $css,
                \sprintf('Aucun utilitaire `dark:` n\'est émis avec la garde « %s » : le sombre ne répond plus que sur un des deux chemins.', $guard),
            );
        }
    }

    /**
     * `assets/vendor/` est commité, et `.gitignore` ne suffit pas à le prouver :
     * `composer.json` lance `importmap:install` en auto-script, donc la CI reconstruit le
     * répertoire depuis le réseau avant d'exécuter quoi que ce soit. Un vendor absent du
     * dépôt resterait vert ici et ne se découvrirait qu'au premier déploiement.
     */
    #[Test]
    public function every_pinned_javascript_dependency_is_present_on_disk(): void
    {
        $importmap = require GateFiles::projectDir().'/importmap.php';

        self::assertIsArray($importmap);

        $vendor = GateFiles::projectDir().'/assets/vendor';
        $installed = require $vendor.'/installed.php';

        self::assertIsArray($installed);

        $pinned = 0;

        foreach ($importmap as $name => $entry) {
            if (!\is_array($entry) || !isset($entry['version'])) {
                continue;
            }

            ++$pinned;

            // Deux formes selon l'entrée : un paquet nu est téléchargé dans un répertoire
            // à son nom, une entrée qui porte déjà un chemin de fichier l'est en fichier.
            self::assertTrue(
                is_file($vendor.'/'.$name) || is_dir($vendor.'/'.$name),
                \sprintf('« %s » est épinglé dans `importmap.php` et absent d\'`assets/vendor/` : un serveur qui ne relance pas `importmap:install` servirait une page sans lui.', $name),
            );

            $recorded = $installed[$name] ?? null;

            self::assertIsArray(
                $recorded,
                \sprintf('« %s » est épinglé dans `importmap.php` et absent d\'`assets/vendor/installed.php`.', $name),
            );
            self::assertSame(
                $entry['version'],
                $recorded['version'] ?? null,
                \sprintf('« %s » n\'est pas dans `assets/vendor/installed.php` à la version qu\'`importmap.php` épingle : le répertoire commité a dérivé de son manifeste.', $name),
            );
        }

        self::assertGreaterThan(0, $pinned, '`importmap.php` n\'épingle aucune dépendance : le test ne vérifie rien.');
    }

    /**
     * `Process` avec son `cwd`, jamais un `cd` passé à un shell : sous Windows `cd` sans
     * `/d` ne change pas de volume.
     *
     * @param list<string> $command
     *
     * @return array{int, string}
     */
    private static function execute(array $command): array
    {
        $process = new Process($command, GateFiles::projectDir(), null, null, 300.0);
        $process->run();

        return [$process->getExitCode() ?? 1, $process->getOutput().$process->getErrorOutput()];
    }
}
