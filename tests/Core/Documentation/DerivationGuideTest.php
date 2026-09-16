<?php

declare(strict_types=1);

namespace App\Tests\Core\Documentation;

use App\Tests\Core\Quality\GateFiles;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * `docs/DERIVATION.md` répond seul à « où va cette classe » (story 1.12, FR-2). Un guide
 * que rien ne relit dérive au premier refactor ; ce test tient les faits que le guide
 * partage avec un fichier du dépôt, pas sa prose.
 *
 * **Quatre familles d'ancres, et elles seules :**
 *
 * - (a) les dossiers de couche réellement présents sous `src/Core/`, balayés ;
 * - (b) les quatre fichiers de `config/` qui portent un glob `src/Module/*`, dont le glob
 *   est lu dans le YAML parsé, à sa clé **de production** ;
 * - (c) les quatre éditions qu'un module coûte dans `deptrac.yaml`, vérifiées dans la
 *   **structure parsée** du fichier pour chaque module de `tests/Fixtures/Module/` — jamais
 *   dans ses commentaires, qui peuvent rester justes pendant que la recette change ;
 * - (d) les statuts de story du bloc `# Story Status:` de `sprint-status.yaml`, et les
 *   quatre champs d'en-tête que FR-14 lira. Cette famille se saute quand `_bmad-output/`
 *   est absent, comme chez un dérivé qui ne livre pas ses artefacts BMAD ; les statuts
 *   se sautent aussi quand seul `sprint-status.yaml` manque.
 *
 * **Toutes les ancres cherchées dans le guide le sont entre accents graves, sans
 * exception** — une sous-chaîne nue passe au vert pour une mauvaise raison (`review` est
 * déjà dans `bmad-code-review`, `Message` dans `MessageHandler`). Ce sont exactement :
 *
 * - (a) `` `<Dossier>/` `` pour chaque dossier ;
 * - (b) `` `<chemin du fichier>` `` et `` `<glob>` `` pour chaque fichier ;
 * - (c) `` `Module<Nom>` ``, `` `(src|tests/Fixtures)/Module/<Nom>/.*` ``,
 *   `` `Module<Nom>: ['+AnyLayer', 'Module<Nom>', 'CoreContract']` ``, `` `AnyRoot` ``,
 *   `` `UndeclaredModule` `` et `` `must_not` `` — avec `<Nom>` écrit tel quel, puisque le
 *   guide décrit la recette et non un module ;
 * - (d) `` `<statut>` `` et `` `<champ>` ``.
 *
 * Une ancre doit donc tenir sur une seule ligne du guide. Le guide reste libre de sa
 * forme — titres, tableaux, blocs de code —, pas de ses faits.
 */
final class DerivationGuideTest extends TestCase
{
    private const string GUIDE = 'docs/DERIVATION.md';

    /**
     * Le nom générique qu'emploie la recette du guide, là où deptrac porte `Demo`.
     */
    private const string PLACEHOLDER = '<Nom>';

    // --- (a) Les dossiers de couche réellement présents sous src/Core/ -------------

    #[Test]
    #[DataProvider('lesDossiersDeCoucheDuSocle')]
    public function every_layer_directory_of_the_core_is_named_by_the_guide(string $directory): void
    {
        self::assertGuideNames(
            \sprintf('%s/', $directory),
            \sprintf('le dossier de couche `%s/` de `src/Core/`', $directory),
        );
    }

    /**
     * Balayés, pas recopiés : un dossier de couche ajouté sous `src/Core/` rougit ce test
     * avant que quiconque ait pensé au guide.
     *
     * @return Generator<string, array{string}>
     */
    public static function lesDossiersDeCoucheDuSocle(): Generator
    {
        yield from self::directoriesOf('src/Core');
    }

    // --- (b) Les quatre fichiers de configuration porteurs d'un glob src/Module/* --

    /**
     * Pour chaque fichier : le chemin de clés du glob **de production**, et sa valeur.
     *
     * Lu dans le YAML parsé plutôt que cherché dans le texte : chacun des quatre fichiers
     * recopie son glob sous `when@test`, et une recherche de chaîne resterait verte sur le
     * miroir après la disparition du glob de production.
     *
     * @var array<string, array{list<string>, string}>
     */
    private const array MODULE_GLOBS = [
        'config/services.yaml' => [['services', 'App\\Module\\', 'resource'], '../src/Module/*/'],
        'config/routes.yaml' => [['module_controllers', 'resource'], '../src/Module/*/Controller/**/*.php'],
        'config/packages/doctrine.yaml' => [['doctrine', 'orm', 'mappings', 'Module', 'dir'], '%kernel.project_dir%/src/Module'],
        'config/packages/translation.yaml' => [['framework', 'translator', 'paths'], '%kernel.project_dir%/src/Module'],
    ];

    /**
     * @param list<string> $keys
     */
    #[Test]
    #[DataProvider('lesGlobsDesModules')]
    public function every_config_file_carrying_a_module_glob_still_carries_it_and_is_named_by_the_guide(
        string $path,
        array $keys,
        string $glob,
    ): void {
        self::assertContains(
            $glob,
            self::valuesAt($path, $keys),
            \sprintf('« %s » ne porte plus `%s` sous `%s` : le câblage des modules a changé, le guide doit suivre.', $path, $glob, implode('.', $keys)),
        );

        self::assertGuideNames($path, 'le fichier porteur d\'un glob des modules');
        self::assertGuideNames($glob, \sprintf('le glob des modules de `%s`', $path));
    }

    /**
     * @return Generator<string, array{string, list<string>, string}>
     */
    public static function lesGlobsDesModules(): Generator
    {
        foreach (self::MODULE_GLOBS as $path => [$keys, $glob]) {
            yield $path => [$path, $keys, $glob];
        }
    }

    // --- (c) Ce que coûte un module dans deptrac.yaml -------------------------------

    /**
     * Les trois formes que la recette du guide prescrit, et que chaque module de
     * démonstration porte déjà. `%s` est le nom du module : `Demo` dans `deptrac.yaml`,
     * `<Nom>` dans le guide.
     */
    private const string LAYER = 'Module%s';

    private const string COLLECTOR = '(src|tests/Fixtures)/Module/%s/.*';

    /**
     * @var list<string>
     */
    private const array RULESET = ['+AnyLayer', 'Module%s', 'CoreContract'];

    /**
     * Combien de clés ou de valeurs de la structure de `deptrac.yaml` sont **exactement**
     * le nom de couche d'un module (son bloc, la clé et la ligne de son ruleset,
     * `AnyRoot`), et combien sont exactement son collecteur (son bloc, le `must_not` de
     * `UndeclaredModule`), quand il a reçu ses quatre éditions et rien d'autre. Égalité,
     * pas sous-chaîne : un module `Demographics` ne compte pas pour `Demo`.
     */
    private const int LAYER_NAME_OCCURRENCES = 4;

    private const int COLLECTOR_OCCURRENCES = 2;

    #[Test]
    #[DataProvider('lesModulesDeDemonstration')]
    public function the_layer_block_of_every_fixture_module_is_the_one_the_guide_prescribes(string $module): void
    {
        $name = \sprintf(self::LAYER, $module);
        $layer = self::layer($name);

        self::assertNotNull(
            $layer,
            \sprintf('`deptrac.yaml` ne déclare plus le bloc de couche `%s` : la recette a changé de forme, le guide doit suivre.', $name),
        );
        self::assertSame(
            [['type' => 'directory', 'value' => \sprintf(self::COLLECTOR, $module)]],
            $layer['collectors'] ?? null,
            \sprintf('Le bloc de couche `%s` de `deptrac.yaml` ne collecte plus `%s` seul : le guide doit suivre.', $name, \sprintf(self::COLLECTOR, $module)),
        );

        self::assertGuideNames(\sprintf(self::LAYER, self::PLACEHOLDER), 'le nom du bloc de couche d\'un module');
        self::assertGuideNames(\sprintf(self::COLLECTOR, self::PLACEHOLDER), 'le collecteur du bloc de couche d\'un module');
    }

    #[Test]
    #[DataProvider('lesModulesDeDemonstration')]
    public function the_ruleset_line_of_every_fixture_module_is_the_one_the_guide_prescribes(string $module): void
    {
        $name = \sprintf(self::LAYER, $module);

        self::assertSame(
            self::rulesetOf($module),
            self::deptracNode('ruleset')[$name] ?? null,
            \sprintf('La ligne de ruleset `%s` de `deptrac.yaml` n\'est plus `%s` : le guide doit suivre.', $name, implode(', ', self::rulesetOf($module))),
        );

        self::assertGuideNames(
            \sprintf("%s: ['%s']", \sprintf(self::LAYER, self::PLACEHOLDER), implode("', '", self::rulesetOf(self::PLACEHOLDER))),
            'la ligne de ruleset d\'un module',
        );
    }

    #[Test]
    #[DataProvider('lesModulesDeDemonstration')]
    public function every_fixture_module_is_named_in_any_root_as_the_guide_prescribes(string $module): void
    {
        $name = \sprintf(self::LAYER, $module);
        $anyRoot = self::deptracNode('ruleset')['AnyRoot'] ?? null;

        self::assertIsArray($anyRoot, '`deptrac.yaml` ne déclare plus la couche de vocabulaire `AnyRoot` : le guide doit suivre.');
        self::assertContains(
            $name,
            $anyRoot,
            \sprintf('`AnyRoot` ne nomme plus `%s` dans `deptrac.yaml` : le guide doit suivre.', $name),
        );

        self::assertGuideNames('AnyRoot', 'la couche de vocabulaire où un module ajoute son nom');
    }

    #[Test]
    #[DataProvider('lesModulesDeDemonstration')]
    public function every_fixture_module_is_excluded_from_the_undeclared_module_net_as_the_guide_prescribes(string $module): void
    {
        $net = self::layer('UndeclaredModule');

        self::assertNotNull($net, '`deptrac.yaml` ne déclare plus le filet `UndeclaredModule` : le guide doit suivre.');

        $collectors = $net['collectors'] ?? null;
        $excluded = [];

        foreach (\is_array($collectors) ? $collectors : [] as $collector) {
            if (\is_array($collector) && \is_array($collector['must_not'] ?? null)) {
                array_push($excluded, ...array_values($collector['must_not']));
            }
        }

        self::assertContains(
            ['type' => 'directory', 'value' => \sprintf(self::COLLECTOR, $module)],
            $excluded,
            \sprintf('Le `must_not` de `UndeclaredModule` n\'exclut plus `%s` : le guide doit suivre.', \sprintf(self::COLLECTOR, $module)),
        );

        self::assertGuideNames('UndeclaredModule', 'le filet des modules non déclarés');
        self::assertGuideNames('must_not', 'la liste d\'exclusion du filet `UndeclaredModule`');
    }

    /**
     * Les quatre méthodes ci-dessus prouvent que chaque édition est là ; celle-ci prouve
     * qu'il n'y en a pas une cinquième. Un module nommé ailleurs dans `deptrac.yaml` — une
     * `skip_violations`, un second ruleset — est une recette que le guide ne décrit pas.
     */
    #[Test]
    #[DataProvider('lesModulesDeDemonstration')]
    public function a_fixture_module_costs_nothing_else_in_deptrac(string $module): void
    {
        $structure = self::deptracNode(null);
        $name = \sprintf(self::LAYER, $module);
        $directory = \sprintf(self::COLLECTOR, $module);
        $names = self::occurrences($structure, $name);
        $directories = self::occurrences($structure, $directory);

        self::assertSame(
            self::LAYER_NAME_OCCURRENCES,
            $names,
            \sprintf('`deptrac.yaml` nomme `%s` %d fois au lieu de %d (bloc de couche, clé et ligne de ruleset, `AnyRoot`) : un module ne coûte plus les quatre éditions du guide.', $name, $names, self::LAYER_NAME_OCCURRENCES),
        );
        self::assertSame(
            self::COLLECTOR_OCCURRENCES,
            $directories,
            \sprintf('`deptrac.yaml` vise `%s` %d fois au lieu de %d (bloc de couche, `must_not` de `UndeclaredModule`) : un module ne coûte plus les quatre éditions du guide.', $directory, $directories, self::COLLECTOR_OCCURRENCES),
        );
    }

    /**
     * Balayés : un module de démonstration ajouté sans ses éditions deptrac rougit ici.
     *
     * @return Generator<string, array{string}>
     */
    public static function lesModulesDeDemonstration(): Generator
    {
        yield from self::directoriesOf('tests/Fixtures/Module');
    }

    // --- (d) Les statuts de story, et les champs de l'en-tête de story -------------

    /**
     * Le dossier des artefacts BMAD. Un dérivé peut ne pas le livrer : la famille (d) se
     * saute alors, parce qu'elle n'a plus rien à garder — la roadmap n'aurait rien à lire.
     */
    private const string BMAD_OUTPUT = '_bmad-output';

    /**
     * La source des statuts. Son absence saute aussi les statuts, même quand
     * `_bmad-output/` existe : un dérivé peut n'avoir pas encore planifié de sprint.
     */
    private const string SPRINT_STATUS = self::BMAD_OUTPUT.'/implementation-artifacts/sprint-status.yaml';

    private const string SKIPPED = 'Les artefacts BMAD sont absents (`_bmad-output/` ou `sprint-status.yaml`) : rien à garder.';

    #[Test]
    #[DataProvider('lesStatutsDeStory')]
    public function every_story_status_of_the_sprint_file_is_named_by_the_guide(?string $status): void
    {
        if (null === $status) {
            self::markTestSkipped(self::SKIPPED);
        }

        self::assertGuideNames($status, 'le statut de story de `sprint-status.yaml`');
    }

    /**
     * Relus dans le bloc `# Story Status:` de `sprint-status.yaml`, qui documente ses
     * propres valeurs légales : un statut renommé là rougit ici.
     *
     * @return Generator<string, array{string|null}>
     */
    public static function lesStatutsDeStory(): Generator
    {
        if (!is_file(GateFiles::projectDir().'/'.self::SPRINT_STATUS)) {
            yield 'sans sprint-status.yaml' => [null];

            return;
        }

        $sprintStatus = GateFiles::read(self::SPRINT_STATUS);

        if (1 !== preg_match('/^# Story Status:\R(?P<body>(?:#.*\R)*)/m', $sprintStatus, $block)) {
            throw new RuntimeException('`sprint-status.yaml` ne porte plus le bloc « # Story Status: ».');
        }

        if (0 === (int) preg_match_all('/^#\s*-\s*(?P<status>[a-z-]+):/m', $block['body'], $matches)) {
            throw new RuntimeException('`sprint-status.yaml` ne liste plus aucun statut de story.');
        }

        foreach ($matches['status'] as $status) {
            yield $status => [$status];
        }
    }

    /**
     * Les quatre champs que FR-14 lira dans l'en-tête YAML d'un fichier de story. Aucun
     * fichier du dépôt ne les définit ailleurs que le guide lui-même et la spec de la
     * story 1.12 : ils sont donc recopiés ici, le patron que `ThemeTokensTest` assume pour
     * la même raison.
     *
     * @var list<string>
     */
    private const array STORY_HEADER_FIELDS = ['priority', 'difficulty', 'assignee', 'depends_on'];

    #[Test]
    #[DataProvider('lesChampsDEnTeteDeStory')]
    public function every_story_header_field_is_named_by_the_guide(?string $field): void
    {
        if (null === $field) {
            self::markTestSkipped(self::SKIPPED);
        }

        self::assertGuideNames($field, 'le champ d\'en-tête de story');
    }

    /**
     * @return Generator<string, array{string|null}>
     */
    public static function lesChampsDEnTeteDeStory(): Generator
    {
        if (!self::bmadOutputExists()) {
            yield 'sans _bmad-output' => [null];

            return;
        }

        foreach (self::STORY_HEADER_FIELDS as $field) {
            yield $field => [$field];
        }
    }

    // --- Lecture ---------------------------------------------------------------------

    /**
     * Toute ancre est cherchée entre accents graves : voir le docblock de la classe.
     */
    private static function assertGuideNames(string $anchor, string $what): void
    {
        $quoted = \sprintf('`%s`', $anchor);

        self::assertStringContainsString(
            $quoted,
            GateFiles::read(self::GUIDE),
            \sprintf('Le guide ne nomme pas %s : %s.', $what, $quoted),
        );
    }

    /**
     * @return Generator<string, array{string}>
     */
    private static function directoriesOf(string $path): Generator
    {
        $root = GateFiles::projectDir().'/'.$path;
        $entries = is_dir($root) ? scandir($root) : false;

        if (false === $entries) {
            throw new RuntimeException(\sprintf('« %s » est absent ou illisible.', $path));
        }

        $found = false;

        foreach ($entries as $entry) {
            if (!str_starts_with($entry, '.') && is_dir($root.'/'.$entry)) {
                $found = true;

                yield $entry => [$entry];
            }
        }

        if (!$found) {
            throw new RuntimeException(\sprintf('« %s » ne contient aucun dossier.', $path));
        }
    }

    /**
     * La valeur au bout d'un chemin de clés, toujours rendue en liste — un nœud absent
     * rend une liste vide, et l'assertion nomme alors ce qui manque.
     *
     * @param list<string> $keys
     *
     * @return list<mixed>
     */
    private static function valuesAt(string $path, array $keys): array
    {
        $node = Yaml::parse(GateFiles::read($path));

        foreach ($keys as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                return [];
            }

            $node = $node[$key];
        }

        return \is_array($node) ? array_values($node) : [$node];
    }

    /**
     * La racine `deptrac:` du fichier parsé, ou l'un de ses nœuds de premier niveau.
     *
     * @return array<array-key, mixed>
     */
    private static function deptracNode(?string $key): array
    {
        $config = Yaml::parse(GateFiles::read('deptrac.yaml'));
        $node = \is_array($config) ? $config['deptrac'] ?? null : null;

        if (null !== $key) {
            $node = \is_array($node) ? $node[$key] ?? null : null;
        }

        if (!\is_array($node)) {
            throw new RuntimeException(\sprintf('`deptrac.yaml` ne porte plus `%s`.', null === $key ? 'deptrac' : 'deptrac.'.$key));
        }

        return $node;
    }

    /**
     * Les clés et les valeurs scalaires égales à `$needle`, à toute profondeur.
     *
     * @param array<array-key, mixed> $node
     */
    private static function occurrences(array $node, string $needle): int
    {
        $count = 0;

        foreach ($node as $key => $value) {
            if ($needle === $key) {
                ++$count;
            }

            if (\is_array($value)) {
                $count += self::occurrences($value, $needle);
            } elseif ($needle === $value) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function layer(string $name): ?array
    {
        foreach (self::deptracNode('layers') as $layer) {
            if (\is_array($layer) && $name === ($layer['name'] ?? null)) {
                return $layer;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function rulesetOf(string $module): array
    {
        return array_map(static fn (string $entry): string => \sprintf($entry, $module), self::RULESET);
    }

    private static function bmadOutputExists(): bool
    {
        return is_dir(GateFiles::projectDir().'/'.self::BMAD_OUTPUT);
    }
}
