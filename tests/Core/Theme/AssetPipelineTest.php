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

    private const string CONTROLLERS_MANIFEST = 'assets/controllers.json';

    /**
     * L'interrupteur qui démarre Turbo.
     *
     * `assets/controllers.json` est la seule chose qui fasse importer `@hotwired/turbo` :
     * le passer à `false` n'écrit aucune erreur nulle part et ne fait échouer aucun des
     * autres tests. La page se rend, la porte reste verte, et pourtant `data-turbo-permanent`
     * ne reporte plus rien, `page-focus` n'est plus rejoué à chaque navigation, et les deux
     * contrôleurs Stimulus deviennent du code écrit contre un mécanisme absent. C'est le
     * comportement le plus structurant de la story 1.4, et il n'avait aucun test qui puisse
     * échouer.
     */
    #[Test]
    public function turbo_is_still_switched_on_by_the_controllers_manifest(): void
    {
        $core = self::turboSection('turbo-core');

        self::assertTrue(
            $core['enabled'] ?? false,
            \sprintf('`turbo-core` est désactivé dans « %s » : Turbo ne démarre plus, `data-turbo-permanent` ne reporte plus la région d\'annonces, et `page-focus` n\'est plus rejoué à chaque navigation — sans qu\'aucune page cesse de se rendre.', self::CONTROLLERS_MANIFEST),
        );
    }

    /**
     * Le seul reliquat de la recette `ux-turbo`, coupé et expliqué ici.
     *
     * `config/packages/ux_turbo.yaml` a reçu son commentaire ; `controllers.json` est du
     * JSON et n'en accepte aucun, donc l'explication vit dans le test qui tient la règle.
     *
     * L'élément `<turbo-stream-source>` de Mercure était **auto-importé sur chaque page**
     * alors que `mercure-turbo-stream` est désactivé et que Mercure n'est nulle part dans
     * la stack : ni bundle, ni hub, ni configuration. C'est du JavaScript téléchargé et
     * évalué par tout visiteur de tout dérivé, pour une fonctionnalité que personne n'a
     * demandée. Le standard range Mercure au niveau « à la demande » — il s'active sur un
     * besoin réel, jamais par anticipation, et il n'y a ici aucun déclencheur.
     *
     * Le remettre à `true` est une décision légitime le jour où Mercure entre vraiment ;
     * ce test veut simplement que ce soit une décision.
     */
    #[Test]
    public function the_mercure_stream_source_element_is_not_shipped_on_every_page(): void
    {
        $autoimports = self::turboSection('turbo-core')['autoimport'] ?? [];

        self::assertIsArray($autoimports);

        foreach ($autoimports as $asset => $enabled) {
            if (!\is_string($asset) || !str_contains($asset, 'mercure')) {
                continue;
            }

            self::assertFalse(
                $enabled,
                \sprintf('« %s » auto-importe « %s » sur chaque page, alors que `mercure-turbo-stream` est désactivé et que Mercure n\'est nulle part dans la stack.', self::CONTROLLERS_MANIFEST, $asset),
            );
        }

        self::assertFalse(
            self::turboSection('mercure-turbo-stream')['enabled'] ?? false,
            \sprintf('« %s » active `mercure-turbo-stream` sans qu\'aucun hub Mercure n\'existe dans la stack.', self::CONTROLLERS_MANIFEST),
        );
    }

    /**
     * Une entrée du paquet `@symfony/ux-turbo` dans `assets/controllers.json`.
     *
     * @return array<array-key, mixed>
     */
    private static function turboSection(string $name): array
    {
        $manifest = json_decode(GateFiles::read(self::CONTROLLERS_MANIFEST), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest);

        $controllers = $manifest['controllers'] ?? null;

        self::assertIsArray($controllers, \sprintf('« %s » ne déclare aucun contrôleur.', self::CONTROLLERS_MANIFEST));

        $turbo = $controllers['@symfony/ux-turbo'] ?? null;

        self::assertIsArray($turbo, \sprintf('« %s » ne connaît plus `@symfony/ux-turbo` : Turbo Drive n\'est jamais démarré.', self::CONTROLLERS_MANIFEST));

        $section = $turbo[$name] ?? null;

        self::assertIsArray($section, \sprintf('« %s » ne déclare plus « %s » sous `@symfony/ux-turbo`.', self::CONTROLLERS_MANIFEST, $name));

        return $section;
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
     * L'autre moitié du point d'entrée : celle qui démarre le JavaScript.
     *
     * `the_javascript_entrypoint_pulls_the_stylesheet_in()` n'assure que l'import CSS.
     * Retirer la ligne `import './stimulus_bootstrap.js';` tue **tout** ce que la story 1.4
     * a livré côté navigateur d'un seul coup — les deux contrôleurs Stimulus, et Turbo avec
     * eux, puisque c'est le loader de `@symfony/stimulus-bundle` qui importe les
     * contrôleurs déclarés par `controllers.json`. Aucune page ne cesse de se rendre, aucun
     * autre test ne bouge.
     */
    #[Test]
    public function the_javascript_entrypoint_starts_stimulus(): void
    {
        self::assertStringContainsString(
            "import './stimulus_bootstrap.js';",
            GateFiles::read('assets/app.js'),
            'Le point d\'entrée n\'importe plus `stimulus_bootstrap.js` : les deux contrôleurs Stimulus **et** Turbo sont morts, sans qu\'aucune page cesse de se rendre.',
        );

        $bootstrap = GateFiles::read('assets/stimulus_bootstrap.js');

        self::assertStringContainsString(
            'startStimulusApp(',
            $bootstrap,
            '`assets/stimulus_bootstrap.js` n\'appelle plus `startStimulusApp()` : le fichier est importé et ne démarre rien.',
        );
        self::assertStringContainsString(
            '@symfony/stimulus-bundle',
            $bootstrap,
            '`startStimulusApp()` ne vient plus de `@symfony/stimulus-bundle` : c\'est son loader qui découvre `assets/controllers/` et qui importe les contrôleurs de `controllers.json`.',
        );
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
     * `asset()` est appelée par le gabarit — donc sur **chaque page**, en production.
     *
     * `symfony/asset-mapper` mappe et digère les fichiers ; c'est `symfony/asset` qui
     * fournit la fonction Twig qui les nomme. Rangée en `require-dev`, elle disparaîtrait
     * d'un `composer install --no-dev` : le premier déploiement d'un serveur client
     * tomberait sur « Unknown "asset" function », sur toutes les pages à la fois, et
     * aucun test de ce dépôt ne s'exécuterait jamais dans cette configuration.
     *
     * `BundleDependencyTest` ne couvre que les bundles : ce composant n'en est pas un.
     */
    #[Test]
    public function the_asset_component_is_a_production_dependency(): void
    {
        $composer = json_decode(GateFiles::read('composer.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);

        $require = $composer['require'] ?? null;
        $requireDev = $composer['require-dev'] ?? null;

        self::assertIsArray($require);
        self::assertIsArray($requireDev);

        self::assertArrayHasKey(
            'symfony/asset',
            $require,
            '`symfony/asset` n\'est pas en `require` : la fonction Twig `asset()` n\'existe pas, et le gabarit l\'appelle sur chaque page.',
        );
        self::assertArrayNotHasKey(
            'symfony/asset',
            $requireDev,
            '`symfony/asset` est en `require-dev` : sous `composer install --no-dev`, toute page du dérivé tomberait.',
        );
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
     * La borne de l'exception, juste à côté de la règle qu'elle borne.
     *
     * Fabrice a accordé pendant la story 1.4 une exception écrite dans le bloc gelé du
     * spec : **les tests ont le droit d'utiliser Node, la chaîne d'assets non.** Quatre
     * lignes de la matrice d'edge cases décrivent du comportement JavaScript — le focus
     * replacé après une navigation Turbo, un message recopié dans la région
     * d'annonces — et il n'existe pas de DOM en PHP pour les prouver.
     *
     * Une exception écrite en commentaire n'est pas une exception bornée : elle s'élargit
     * au premier qui trouve pratique de mettre « juste un petit `package.json` » à la
     * racine. Celle-ci a deux frontières, et ce test les tient toutes les deux.
     *
     * **Un.** Le seul Node du dépôt vit sous `tests/js/`. Le test précédent refuse les
     * artefacts à la racine ; celui-ci les refuse *partout ailleurs*, ce qui interdit
     * aussi bien `assets/package.json` que `src/Module/Truc/node_modules/`.
     *
     * **Deux.** La flèche ne s'inverse jamais : `tests/js/` lit `assets/`, et ni
     * `assets/`, ni `importmap.php`, ni la cible de build ne connaissent `tests/js/`. Le
     * jour où l'un d'eux le nommerait, la chaîne servie au navigateur dépendrait d'un
     * répertoire que `.gitignore` ne commite même pas entièrement — et AD-1 serait rompu
     * sans qu'aucun `package.json` n'ait jamais touché la racine.
     */
    #[Test]
    public function the_node_exception_is_confined_to_the_test_directory(): void
    {
        $found = self::nodeArtefacts();

        self::assertContains(
            'tests/js/package.json',
            $found,
            'Le manifeste de l\'exception est absent : `tests/js/` est l\'endroit — le seul — où Node a le droit d\'exister dans ce dépôt.',
        );
        self::assertContains(
            'tests/js/package-lock.json',
            $found,
            'Le lockfile n\'est pas commité : deux exécutions de la porte n\'installeraient pas les mêmes paquets, et `npm ci` n\'aurait rien à lire.',
        );

        foreach ($found as $path) {
            self::assertStringStartsWith(
                'tests/js/',
                $path,
                \sprintf('« %s » est un artefact Node hors de `tests/js/` : l\'exception de la story 1.4 ne couvre que les tests, jamais la chaîne d\'assets (AD-1).', $path),
            );
        }

        // La flèche, dans l'autre sens. `assets/vendor/` est écarté du balayage : c'est
        // la sortie d'`importmap:install`, pas une source que quelqu'un édite, et
        // `every_pinned_javascript_dependency_is_present_on_disk` la tient déjà à son
        // manifeste.
        $chain = ['importmap.php', 'config/packages/symfonycasts_tailwind.yaml', 'config/packages/asset_mapper.yaml'];

        foreach (self::filesUnder('assets', ['vendor']) as $file) {
            $chain[] = $file;
        }

        foreach ($chain as $path) {
            self::assertStringNotContainsString(
                'tests/js',
                GateFiles::read($path),
                \sprintf('« %s » dépend de `tests/js/` : la chaîne d\'assets servie au navigateur ne doit jamais lire le seul répertoire du dépôt qui a le droit à Node.', $path),
            );
        }
    }

    /**
     * Le glob que les deux façades lancent doit réellement désigner des fichiers.
     *
     * `node --test "tests/js/**\/*.test.js"` sur un glob qui ne matche rien affiche
     * « tests 0 » et **sort en 0**. Un renommage de fichier, un déplacement de répertoire,
     * une convention de nommage qui change, et la moitié JavaScript de la catégorie
     * « Accessibility » devient un no-op vert : la porte continue de dire que les deux
     * contrôleurs Stimulus sont vérifiés alors qu'elle n'en exécute plus une ligne.
     *
     * Le glob n'est pas recopié ici, il est lu dans le `Makefile` — sinon ce test
     * vérifierait sa propre constante pendant que la façade dérive à côté.
     * `QualityGateParityTest` tient déjà l'égalité entre les deux façades.
     */
    #[Test]
    public function the_javascript_test_glob_actually_matches_files(): void
    {
        $recipe = GateFiles::recipe('a11y');

        self::assertSame(
            1,
            preg_match('/node --test "(?P<glob>[^"]+)"/', $recipe, $matches),
            'La cible `a11y` ne lance plus `node --test` sur un glob : la moitié JavaScript de la catégorie a disparu.',
        );

        $glob = $matches['glob'];
        $matched = self::filesMatching($glob);

        self::assertNotSame(
            [],
            $matched,
            \sprintf('Le glob « %s » ne désigne aucun fichier : `node --test` afficherait « tests 0 » et sortirait en 0 — une catégorie de la porte verte en permanence et vide de substance.', $glob),
        );
    }

    /**
     * Les fichiers du dépôt que désigne un glob à la manière de `node --test` : `**` vaut
     * un nombre quelconque de répertoires, `*` un segment sans séparateur.
     *
     * @return list<string>
     */
    private static function filesMatching(string $glob): array
    {
        $base = strtok($glob, '*');
        $base = false === $base ? '' : rtrim($base, '/');

        if ('' === $base || !is_dir(GateFiles::projectDir().'/'.$base)) {
            return [];
        }

        $pattern = preg_quote($glob, '#');
        $pattern = str_replace('\*\*/', '@@ANY@@', $pattern);
        $pattern = str_replace('\*', '[^/]*', $pattern);
        $pattern = str_replace('@@ANY@@', '(?:[^/]+/)*', $pattern);

        $matched = [];

        foreach (self::filesUnder($base, ['node_modules']) as $file) {
            if (1 === preg_match('#^'.$pattern.'$#', $file)) {
                $matched[] = $file;
            }
        }

        return $matched;
    }

    /**
     * Le plancher de version Node, écrit une fois et lu partout.
     *
     * Il vivait dans trois têtes et aucun fichier : pas d'`engines` dans le manifeste, un
     * README qui disait « 22 ou plus récent », une CI épinglée sur 24, et un harnais qui
     * exige en réalité 22.15 — `module.registerHooks()` n'existe pas avant. Un poste en
     * 22.0 à 22.14 échouait sur une erreur de type sans le moindre rapport avec ce qu'il
     * fallait faire.
     *
     * `engines` est maintenant la source, et `engine-strict=true` en fait un refus d'npm
     * plutôt qu'un avertissement. La CI lit ce même champ par `node-version-file`, si bien
     * que la version du runner ne peut plus diverger du plancher sans qu'on l'ait décidé.
     */
    #[Test]
    public function the_node_version_floor_is_declared_once_and_read_everywhere(): void
    {
        $manifest = json_decode(GateFiles::read('tests/js/package.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest);
        self::assertIsArray($manifest['engines'] ?? null, '`tests/js/package.json` ne déclare pas d\'`engines` : le plancher de version Node n\'est écrit nulle part et se découvre sur une erreur de type.');

        $floor = $manifest['engines']['node'] ?? null;

        self::assertIsString($floor);
        self::assertSame(
            1,
            preg_match('/^>=(?P<version>\d+\.\d+\.\d+)$/', $floor, $matches),
            \sprintf('`engines.node` vaut « %s » : le plancher s\'écrit `>=x.y.z`, une borne exacte qu\'un outil sait lire.', $floor),
        );

        self::assertStringContainsString(
            'engine-strict=true',
            GateFiles::read('tests/js/.npmrc'),
            '`engine-strict=true` manque : sans lui, npm se contente d\'un avertissement et `engines` reste décoratif.',
        );

        // La CI lit le manifeste plutôt qu'une version épinglée à la main : c'est ce qui
        // rend l'alignement mécanique et non déclaratif.
        $accessibility = GateFiles::job(GateFiles::jobIdsByName()['Accessibility'] ?? '');
        $steps = $accessibility['steps'] ?? [];

        self::assertIsArray($steps);

        $versionFiles = [];

        foreach ($steps as $step) {
            if (\is_array($step) && \is_string($step['uses'] ?? null) && str_starts_with($step['uses'], 'actions/setup-node') && \is_array($step['with'] ?? null)) {
                $versionFiles[] = $step['with']['node-version-file'] ?? null;
            }
        }

        self::assertSame(
            ['tests/js/package.json'],
            $versionFiles,
            'Le job « Accessibility » n\'aligne pas sa version de Node sur `engines.node` : une CI épinglée à la main diverge du plancher sans que rien ne le dise.',
        );

        // Et le README, qui est ce qu'un humain lit avant de lancer la porte.
        $version = $matches['version'];
        $short = implode('.', \array_slice(explode('.', $version), 0, 2));

        self::assertStringContainsString(
            'Node '.$short,
            GateFiles::read('README.md'),
            \sprintf('Le README ne nomme pas le plancher « Node %s » que `tests/js/package.json` déclare.', $short),
        );
    }

    /**
     * Les artefacts Node du dépôt, en chemins relatifs à la racine.
     *
     * Le balayage n'entre ni dans `vendor/`, ni dans `var/`, ni dans `public/assets/`,
     * ni dans `.git/` — trois sorties d'outil et un répertoire de contrôle de version,
     * dont aucun n'est écrit à la main. Il n'entre pas non plus dans un `node_modules/`
     * qu'il vient de trouver : le répertoire lui-même est ce qui compte, son contenu ne
     * dit rien de plus.
     *
     * @return list<string>
     */
    private static function nodeArtefacts(): array
    {
        $artefacts = ['package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'node_modules'];
        $pruned = ['.git', 'vendor', 'var', 'node_modules', 'public/assets'];

        $root = GateFiles::projectDir();
        $found = [];
        $queue = [''];

        while ([] !== $queue) {
            $relative = array_pop($queue);
            $entries = scandir('' === $relative ? $root : $root.'/'.$relative);

            self::assertIsArray($entries);

            foreach ($entries as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }

                $path = '' === $relative ? $entry : $relative.'/'.$entry;

                if (\in_array($entry, $artefacts, true)) {
                    $found[] = $path;
                }

                if (!\in_array($path, $pruned, true) && !\in_array($entry, $pruned, true) && is_dir($root.'/'.$path)) {
                    $queue[] = $path;
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Les fichiers d'un répertoire du dépôt, en chemins relatifs, récursivement.
     *
     * @param list<string> $skip noms de sous-répertoires à ne pas parcourir
     *
     * @return list<string>
     */
    private static function filesUnder(string $directory, array $skip = []): array
    {
        $root = GateFiles::projectDir();
        $files = [];
        $queue = [$directory];

        while ([] !== $queue) {
            $relative = array_pop($queue);
            $entries = scandir($root.'/'.$relative);

            self::assertIsArray($entries);

            foreach ($entries as $entry) {
                if ('.' === $entry || '..' === $entry || \in_array($entry, $skip, true)) {
                    continue;
                }

                $path = $relative.'/'.$entry;

                if (is_dir($root.'/'.$path)) {
                    $queue[] = $path;
                } else {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
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
