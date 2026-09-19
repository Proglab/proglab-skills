<?php

declare(strict_types=1);

namespace App\Tests\Core\Documentation;

use App\Tests\Core\Quality\GateFiles;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * `docs/DERIVATION.md` répond seul à « où va cette classe » (story 1.12, FR-2). Un guide
 * que rien ne relit dérive au premier refactor ; ce test tient les faits que le guide
 * partage avec un fichier du dépôt, pas sa prose.
 *
 * **Cinq familles d'ancres, et elles seules :**
 *
 * - (a) les dossiers de couche réellement présents sous `src/Core/`, et les dossiers du
 *   module de démonstration dont le guide recopie l'inventaire — balayés, pas recopiés ;
 * - (b) les quatre fichiers de `config/` qui portent un glob `src/Module/*`, dont le glob
 *   est lu dans le YAML parsé, à sa clé **de production** ;
 * - (c) les quatre éditions qu'un module coûte dans `deptrac.yaml`, vérifiées dans la
 *   **structure parsée** du fichier pour chaque module de `tests/Fixtures/Module/` **et de
 *   `src/Module/`** — jamais dans ses commentaires, qui peuvent rester justes pendant que
 *   la recette change. Cette famille lit aussi le fichier **à l'envers** : toute exclusion
 *   du `must_not` d'`UndeclaredModule` doit porter ses trois autres éditions, parce que
 *   l'exclusion sans bloc de couche est le seul oubli qui rouvre la frontière en silence ;
 * - (d) les statuts de story du bloc `# Story Status:` de `sprint-status.yaml`, et les
 *   quatre champs d'en-tête que FR-14 lira. Cette famille se saute quand `_bmad-output/`
 *   est absent, comme chez un dérivé qui ne livre pas ses artefacts BMAD ; les statuts
 *   se sautent aussi quand seul `sprint-status.yaml` manque ;
 * - (e) le répertoire de marque du contrat de rebranding (UX-DR-2), que le guide,
 *   `assets/styles/brand.css` et `README.md` doivent nommer à l'identique — trois énoncés
 *   du même contrat qui divergent, c'est un dérivé qui édite le mauvais fichier.
 *
 * **Toutes les ancres cherchées dans le guide le sont entre accents graves, sans
 * exception** — une sous-chaîne nue passe au vert pour une mauvaise raison (`review` est
 * déjà dans `bmad-code-review`, `Message` dans `MessageHandler`). Ce sont exactement :
 *
 * - (a) `` `<Dossier>/` `` pour chaque dossier de `src/Core/`, cherché dans tout le
 *   guide ; et `` `<Dossier>/ `` — ancre **ouvrante** — pour chaque dossier du module de
 *   démonstration, cherché dans le seul bloc d'inventaire, puisque le tableau des couches
 *   nomme déjà presque tous ces dossiers ailleurs ;
 * - (b) `` `<chemin du fichier>` `` et `` `<glob>` `` pour chaque fichier ;
 * - (c) `` `Module<Nom>` ``, `` `(src|tests/Fixtures)/Module/<Nom>/.*` ``,
 *   `` `Module<Nom>: ['+AnyLayer', 'Module<Nom>', 'CoreContract']` ``, `` `AnyRoot` ``,
 *   `` `UndeclaredModule` `` et `` `must_not` `` — avec `<Nom>` écrit tel quel, puisque le
 *   guide décrit la recette et non un module ;
 * - (d) `` `<statut>` `` et `` `<champ>` `` ;
 * - (e) `` `assets/brand/` ``.
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

    /**
     * L'ouverture du bloc d'inventaire du module de démonstration, dans le guide.
     *
     * C'est la seule contrainte de forme que ce fichier impose au guide, et elle est
     * assumée : l'ancre doit être cherchée **dans la liste**, pas dans tout le fichier.
     * Le tableau des couches nomme déjà treize des quatorze dossiers de `Demo/`, donc une
     * recherche globale est verte quoi qu'il arrive — mesuré : en vidant le bloc entier,
     * treize cas sur quatorze restaient verts, et la régression que cette story vient de
     * corriger dans le guide (`Exception/` rangé parmi les dossiers vides, `Enum/`,
     * `Message/` et `MessageHandler/` déclarés absents) passait sans un mot.
     */
    private const string INVENTORY_OPENING = 'Il contient :';

    /**
     * Le guide ne se contente pas de renvoyer au module de démonstration : il en recopie
     * l'inventaire, parce qu'un lecteur qui cherche « où va cette classe » doit voir la
     * liste sans ouvrir un second fichier. Un inventaire recopié périme, et il l'a déjà
     * fait — la story 1.15 y a trouvé `Exception/` rangé parmi les dossiers vides et une
     * phrase affirmant que `Enum/`, `Message/` et `MessageHandler/` n'existaient pas.
     *
     * Balayé, donc, comme les dossiers de `src/Core/` juste au-dessus : le jour où une
     * story donne un dossier de plus au module de démonstration, le guide rougit avant
     * que quiconque y ait pensé — y compris quand le tableau des couches nomme déjà ce
     * dossier, puisque c'est le bloc d'inventaire qui est lu.
     *
     * **L'ancre est ouvrante, pas fermante** : `` `Enum/ `` et non `` `Enum/` ``. Un
     * dossier est listé aussi bien par son nom seul (`` `Twig/` ``) que par un fichier
     * qu'il contient (`` `Enum/DemoWidgetStatus.php` ``) ou par un sous-dossier
     * (`` `Dto/Input/` ``) — les trois formes sont dans la liste, et exiger la forme
     * fermée obligerait à écrire deux fois chaque dossier qui porte un fichier.
     */
    #[Test]
    #[DataProvider('lesDossiersDuModuleDeDemonstration')]
    public function every_directory_of_the_demonstration_module_is_listed_by_the_guide(string $directory): void
    {
        self::assertStringContainsString(
            \sprintf('`%s/', $directory),
            self::demonstrationInventory(),
            \sprintf(
                'L\'inventaire du module de démonstration — le bloc de « %s » ouvert par « %s » — ne liste pas '
                .'le dossier `%s/`. Le guide décrit donc un module qui n\'est plus celui du dépôt.',
                self::GUIDE,
                self::INVENTORY_OPENING,
                $directory,
            ),
        );
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function lesDossiersDuModuleDeDemonstration(): Generator
    {
        yield from self::directoriesOf('tests/Fixtures/Module/Demo');
    }

    /**
     * La liste qui suit `INVENTORY_OPENING` dans le guide, jusqu'à la ligne vide qui la
     * ferme. Les continuations indentées d'un point de liste en font partie.
     */
    private static function demonstrationInventory(): string
    {
        $lines = preg_split('/\R/', GateFiles::read(self::GUIDE));

        if (false === $lines) {
            throw new RuntimeException(\sprintf('« %s » n\'a pas pu être découpé en lignes.', self::GUIDE));
        }

        $inventory = [];
        $collecting = false;

        foreach ($lines as $line) {
            if (!$collecting) {
                $collecting = str_contains($line, self::INVENTORY_OPENING);

                continue;
            }

            if ('' === trim($line)) {
                // La ligne vide qui sépare l'ouverture de la liste ne la ferme pas.
                if ([] === $inventory) {
                    continue;
                }

                break;
            }

            $inventory[] = $line;
        }

        if ([] === $inventory) {
            throw new RuntimeException(\sprintf('« %s » ne porte plus de liste après « %s » : le bloc d\'inventaire du module de démonstration a changé de forme, et plus rien ne le relit.', self::GUIDE, self::INVENTORY_OPENING));
        }

        return implode("\n", $inventory);
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
     * Le `resource` ne suffit pas : c'est l'`exclude` du même bloc qui décide de ce qu'un
     * module a le droit de proposer comme service, et le guide l'énonce dossier par
     * dossier. Sans cette ancre, un septième dossier ajouté à `config/services.yaml`
     * laisserait le guide mentir sans que rien ne rougisse.
     *
     * La ligne entière est ancrée, pas les six noms un par un : c'est la forme que le
     * lecteur recopie, et un chemin qui cesserait de descendre jusqu'au module
     * n'excluerait plus rien tout en gardant les six noms.
     */
    #[Test]
    public function the_exclusion_list_of_the_module_glob_is_named_by_the_guide(): void
    {
        $exclude = self::valuesAt('config/services.yaml', ['services', 'App\\Module\\', 'exclude'])[0] ?? null;

        self::assertIsString(
            $exclude,
            '`config/services.yaml` ne porte plus de liste d\'exclusion sous `services.App\\Module\\.exclude` : le guide doit suivre.',
        );
        self::assertGuideNames($exclude, 'la liste d\'exclusion du glob des modules');
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
    #[DataProvider('lesModulesDeclares')]
    public function the_layer_block_of_every_declared_module_is_the_one_the_guide_prescribes(string $module): void
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
    #[DataProvider('lesModulesDeclares')]
    public function the_ruleset_line_of_every_declared_module_is_the_one_the_guide_prescribes(string $module): void
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
    #[DataProvider('lesModulesDeclares')]
    public function every_declared_module_is_named_in_any_root_as_the_guide_prescribes(string $module): void
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
    #[DataProvider('lesModulesDeclares')]
    public function every_declared_module_is_excluded_from_the_undeclared_module_net_as_the_guide_prescribes(string $module): void
    {
        self::assertContains(
            ['type' => 'directory', 'value' => \sprintf(self::COLLECTOR, $module)],
            self::exclusionsOfTheNet(),
            \sprintf('Le `must_not` de `UndeclaredModule` n\'exclut plus `%s` : le guide doit suivre.', \sprintf(self::COLLECTOR, $module)),
        );

        self::assertGuideNames('UndeclaredModule', 'le filet des modules non déclarés');
        self::assertGuideNames('must_not', 'la liste d\'exclusion du filet `UndeclaredModule`');
    }

    /**
     * Le filet lu **à l'envers** : on part de l'exclusion, pas du module.
     *
     * L'état dangereux est l'exclusion écrite sans le bloc de couche : le module sort du
     * filet sans entrer dans aucune couche de racine, sa couche technique hérite de
     * `+AnyRoot`, il atteint `Core`, et deptrac — qui ne vérifie pas qu'un ruleset nomme
     * une couche déclarée — sort en 0.
     *
     * **Ce que ce sens ferme en propre, et qu'il faut savoir pour ne pas le croire seul
     * garant.** Depuis que la famille (c) balaie `src/Module/`, un module *qui existe*
     * dans l'une des deux racines est déjà tenu par les quatre méthodes ci-dessus :
     * l'exclusion sans bloc de couche fait rougir
     * `the_layer_block_of_every_declared_module_is_the_one_the_guide_prescribes()` avant
     * d'arriver ici. Ce que ce test ajoute, c'est l'**exclusion orpheline** — un module
     * renommé ou supprimé dont l'exclusion est restée. Aucun répertoire, donc aucun cas
     * chez les quatre autres ; l'exclusion dort dans le fichier, et le jour où un
     * répertoire reprend ce nom, il naît hors du filet et sans couche de racine.
     *
     * On ne cherche pas à rendre cet état impossible dans deptrac — il n'en a pas les
     * moyens. On le rend visible ici, en nommant l'édition qui manque.
     */
    #[Test]
    #[DataProvider('lesExclusionsDuFiletDesModulesNonDeclares')]
    public function every_exclusion_of_the_undeclared_module_net_carries_its_three_other_editions(
        string $exclusion,
        ?string $module,
    ): void {
        self::assertNotNull(
            $module,
            \sprintf(
                'L\'exclusion « %s » du `must_not` d\'`UndeclaredModule` ne suit pas la forme `%s` prescrite par '
                .'le guide : impossible d\'en déduire le module dont il faudrait vérifier les trois autres éditions.',
                $exclusion,
                \sprintf(self::COLLECTOR, self::PLACEHOLDER),
            ),
        );

        $name = \sprintf(self::LAYER, $module);
        $collector = \sprintf(self::COLLECTOR, $module);

        // D'abord l'orphelin, et dans cet ordre : une exclusion dont plus aucun
        // répertoire ne porte le nom a beau avoir ses quatre éditions intactes, elle est
        // un piège armé — le jour où un répertoire reprend ce nom, il naît avec des
        // éditions que personne n'a relues. Le dire avant de parler d'édition manquante,
        // sinon le message enverrait chercher une ligne à écrire là où il faut en
        // supprimer quatre.
        self::assertContains(
            $module,
            self::declaredModuleNames(),
            \sprintf(
                '`deptrac.yaml` exclut `%s` du `must_not` d\'`UndeclaredModule`, mais aucune des deux racines '
                .'ne porte de module « %s » : c\'est une exclusion orpheline. Retirez les quatre éditions de ce '
                .'module, ou rendez-lui son répertoire — telle quelle, elle attend le premier dérivé qui '
                .'reprendra ce nom pour le faire naître hors du filet.',
                $collector,
                $module,
            ),
        );

        self::assertNotNull(
            self::layer($name),
            \sprintf(
                '`deptrac.yaml` exclut `%s` du `must_not` d\'`UndeclaredModule` sans déclarer le bloc de couche '
                .'`%s` : le module n\'a plus aucune couche de racine, sa couche technique hérite de `+AnyRoot`, '
                .'et il atteint `Core` sans violation. C\'est l\'édition manquante — voir l\'étape 6 du guide.',
                $collector,
                $name,
            ),
        );

        self::assertSame(
            self::rulesetOf($module),
            self::deptracNode('ruleset')[$name] ?? null,
            \sprintf(
                '`deptrac.yaml` exclut `%s` du `must_not` d\'`UndeclaredModule` sans lui donner sa ligne de '
                .'ruleset `%s` : c\'est l\'édition manquante — voir l\'étape 6 du guide.',
                $collector,
                \sprintf("%s: ['%s']", $name, implode("', '", self::rulesetOf($module))),
            ),
        );

        $anyRoot = self::deptracNode('ruleset')['AnyRoot'] ?? null;

        self::assertIsArray($anyRoot, '`deptrac.yaml` ne déclare plus la couche de vocabulaire `AnyRoot` : le guide doit suivre.');
        self::assertContains(
            $name,
            $anyRoot,
            \sprintf(
                '`deptrac.yaml` exclut `%s` du `must_not` d\'`UndeclaredModule` sans ajouter `%s` à `AnyRoot` : '
                .'c\'est l\'édition manquante — voir l\'étape 6 du guide.',
                $collector,
                $name,
            ),
        );
    }

    /**
     * Les modules nommés par le `must_not` du filet, lus dans le fichier plutôt que dans
     * les deux racines : c'est précisément quand les deux listes divergent que ce test a
     * quelque chose à dire.
     *
     * **Une exclusion hors forme est rendue comme un cas, jamais comme une exception.**
     * PHPUnit matérialise un fournisseur en entier avant d'exécuter quoi que ce soit :
     * lever ici ferait disparaître **tous** les cas, y compris ceux qui sont bien formés
     * et qu'on voulait justement continuer de vérifier. Le cas hors forme porte un module
     * `null`, et c'est la première assertion du test qui le nomme.
     *
     * **La clé du cas porte son rang avant sa valeur**, pour la même raison. PHPUnit
     * invalide le fournisseur entier sur une clé dupliquée (« The key … has already been
     * defined », puis « No tests found in class ») — or dupliquer la ligne du `must_not`
     * en oubliant de la renommer est précisément le copier-coller que l'étape 6 du guide
     * prescrit. Le rang rend la clé unique quoi que porte le fichier ; la valeur reste
     * dans la charge utile et dans les messages.
     *
     * @return Generator<string, array{string, string|null}>
     */
    public static function lesExclusionsDuFiletDesModulesNonDeclares(): Generator
    {
        $shape = '#^'.str_replace('%s', '(?P<module>[^/]+)', preg_quote(self::COLLECTOR, '#')).'$#';
        $found = false;

        foreach (self::exclusionsOfTheNet() as $index => $exclusion) {
            $value = \is_array($exclusion) ? $exclusion['value'] ?? null : null;
            $printable = \is_string($value) ? $value : \sprintf('une exclusion %s', get_debug_type($exclusion));
            $module = \is_string($value) && 1 === preg_match($shape, $value, $matches) ? $matches['module'] : null;
            $found = true;

            yield \sprintf('n° %d — %s', $index + 1, $printable) => [$printable, $module];
        }

        if (!$found) {
            throw new RuntimeException('Le `must_not` d\'`UndeclaredModule` n\'exclut plus aucun module : plus aucun module n\'est déclaré dans `deptrac.yaml`.');
        }
    }

    /**
     * Les quatre méthodes ci-dessus prouvent que chaque édition est là ; celle-ci prouve
     * qu'il n'y en a pas une cinquième. Un module nommé ailleurs dans `deptrac.yaml` — une
     * `skip_violations`, un second ruleset — est une recette que le guide ne décrit pas.
     */
    #[Test]
    #[DataProvider('lesModulesDeclares')]
    public function a_declared_module_costs_nothing_else_in_deptrac(string $module): void
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
     * Les deux racines où vit un module, et si l'une d'elles a le droit d'être vide.
     *
     * `src/Module/` l'est chez le socle livré tel quel — la famille (c) n'a alors rien à
     * y balayer, et ce n'est pas un test à sauter : il n'y a simplement pas de module.
     * `tests/Fixtures/Module/` vide, en revanche, est une suite qui a perdu ses modules
     * de démonstration, et `directoriesOf()` le refuse.
     *
     * @var array<string, bool> chemin de la racine => elle peut être vide
     */
    private const array MODULE_ROOTS = [
        'tests/Fixtures/Module' => false,
        'src/Module' => true,
    ];

    /**
     * Balayés dans les deux racines : un module ajouté sans ses éditions deptrac rougit
     * ici, qu'il soit un module de démonstration du socle ou **le module métier d'un
     * dérivé**. La recette de l'étape 6 est la même pour les deux, donc son contrôle
     * aussi.
     *
     * La clé du cas porte la racine ; le collecteur de `deptrac.yaml`, lui, ne la porte
     * pas — voir `a_module_name_belongs_to_one_root_only()`, qui refuse qu'un même nom
     * existe des deux côtés.
     *
     * **Aucun paramètre, et ce n'est pas un choix de style** : PHPUnit refuse un
     * fournisseur qui en déclare un, fût-il optionnel (« Data Provider method … expects
     * an argument »). La racine balayée est donc une propriété — voir `sweeping()` —,
     * pour que les deux sondes interrogent **ce** fournisseur-ci, celui que les cinq
     * tests consomment, et non une copie privée qui pourrait rester juste pendant que lui
     * régresse.
     *
     * @return Generator<string, array{string}>
     */
    public static function lesModulesDeclares(): Generator
    {
        foreach (self::MODULE_ROOTS as $root => $mayBeEmpty) {
            foreach (self::directoriesOf($root, $mayBeEmpty) as $module) {
                yield $root.'/'.$module[0] => $module;
            }
        }
    }

    /**
     * Les noms de module portés par un répertoire, les deux racines confondues.
     *
     * @return list<string>
     */
    private static function declaredModuleNames(): array
    {
        return array_map(
            static fn (array $module): string => $module[0],
            iterator_to_array(self::lesModulesDeclares(), false),
        );
    }

    /**
     * Les noms de module portés par un répertoire dans **les deux** racines à la fois.
     *
     * @return list<string>
     */
    private static function moduleNamesSharedByBothRoots(): array
    {
        $seen = [];

        foreach (self::declaredModuleNames() as $module) {
            $seen[$module] = ($seen[$module] ?? 0) + 1;
        }

        return array_keys(array_filter($seen, static fn (int $count): bool => $count > 1));
    }

    /**
     * La racine que `directoriesOf()` balaie quand une sonde l'a détournée, et `null`
     * partout ailleurs — c'est-à-dire presque toujours : le dépôt est le cas normal.
     */
    private static ?string $sweptRoot = null;

    /**
     * Monte les racines d'un dérivé imaginaire sous `var/`, y fait pointer le balayage,
     * rend ce que la sonde y a lu, et nettoie quoi qu'il arrive.
     *
     * **Dans `var/`, jamais dans `src/`.** Créer un répertoire dans l'arbre réel change
     * le mtime de `src/Module/`, ce qui périme une `DirectoryResource` du conteneur ;
     * `Kernel::$freshCache` mémorisant la fraîcheur par processus, le dump reste périmé
     * pour tout le reste du run et le premier test qui reconstruit le conteneur en
     * processus tombe sur `Bundle::$container must not be accessed before
     * initialization`. Mesuré : `make test` rouge deux fois sur deux, `phpunit` nu vert.
     * C'est aussi la règle maison — le dépôt réel ne contient jamais le cas de test
     * (`tests/Core/BoundaryTest.php`, `tests/Core/Quality/SandboxTrait.php`) —, et un
     * reliquat sous `var/`, que le `finally` ne poserait qu'en cas de processus tué,
     * n'est vu par personne.
     *
     * @template T
     *
     * @param list<string>  $directories chemins à créer, relatifs au bac à sable
     * @param callable(): T $probe
     *
     * @return T
     */
    private static function sweeping(array $directories, callable $probe): mixed
    {
        $sandbox = \sprintf('%s/var/documentation/%s', GateFiles::projectDir(), bin2hex(random_bytes(8)));
        $filesystem = new Filesystem();

        $filesystem->mkdir(array_map(static fn (string $path): string => $sandbox.'/'.$path, $directories));
        self::$sweptRoot = $sandbox;

        try {
            return $probe();
        } finally {
            self::$sweptRoot = null;
            $filesystem->remove($sandbox);
        }
    }

    /**
     * Le socle est livré avec `src/Module/` vide : rien de ce que balaie la famille (c)
     * n'y existe encore, donc rien ne prouverait, chez lui, que la racine d'un dérivé est
     * bien balayée. Ce test monte les deux racines d'un dérivé imaginaire dans un bac à
     * sable (voir `sweeping()`) pour poser la question.
     *
     * **La sonde interroge `lesModulesDeclares()` lui-même**, le fournisseur que les cinq
     * tests consomment, et non une copie privée. C'est ce qui la rend sensible aux deux
     * régressions possibles : restreindre `MODULE_ROOTS` aux fixtures, et ramener le
     * fournisseur à `directoriesOf('tests/Fixtures/Module')` — l'état exact d'avant la
     * story. Les deux la font rougir ; sur le dépôt seul, aucune des deux ne se verrait,
     * `src/Module/` étant vide.
     */
    #[Test]
    public function the_module_of_a_derivative_is_swept_like_a_fixture_one(): void
    {
        $probe = 'DerivationGuideProbe';

        // Les deux racines : celle du dérivé porte la sonde, celle des fixtures existe
        // parce qu'elle n'a pas le droit d'être vide.
        $modules = self::sweeping(
            ['src/Module/'.$probe, 'tests/Fixtures/Module/Demo'],
            static fn (): array => iterator_to_array(self::lesModulesDeclares(), false),
        );

        self::assertContains(
            [$probe],
            $modules,
            'Le fournisseur de la famille (c) ne balaie pas `src/Module/` : les éditions deptrac d\'un module de dérivé ne sont vérifiées par personne.',
        );
    }

    /**
     * Un nom de module appartient à une racine, et à une seule.
     *
     * Le collecteur que le guide prescrit — `(src|tests/Fixtures)/Module/<Nom>/.*` — ne
     * porte **pas** la racine : `src/Module/Billing/` et `tests/Fixtures/Module/Billing/`
     * tomberaient dans la même couche `ModuleBilling`, dont le ruleset s'autorise
     * elle-même. Les deux se verraient donc l'un l'autre en violation de « Module →
     * Module interdit », et le module du dérivé passerait les cinq tests de cette famille
     * sans avoir ajouté une seule ligne à `deptrac.yaml` — tout étant déjà écrit pour la
     * fixture.
     *
     * Le correctif est ici et non dans `deptrac.yaml` : élargir le collecteur à la racine
     * changerait la recette que le guide prescrit, et un nom réservé se dit mieux qu'il ne
     * se contourne. `Billing` et `Demo` sont pris.
     *
     * **Le cas est rejoué en bac à sable avant d'être cherché dans le dépôt.** Chez le
     * socle, `src/Module/` est vide, donc l'assertion sur le dépôt passe quoi qu'il
     * arrive — supprimer ce test ne changerait rien à la suite —, et le seul état qui la
     * ferait parler est justement celui que `the_module_root_ships_empty()` refuse.
     */
    #[Test]
    public function a_module_name_belongs_to_one_root_only(): void
    {
        $collision = 'Billing';

        $detected = self::sweeping(
            ['src/Module/'.$collision, 'tests/Fixtures/Module/'.$collision],
            static fn (): array => self::moduleNamesSharedByBothRoots(),
        );

        self::assertSame(
            [$collision],
            $detected,
            'La comparaison des deux racines ne voit pas un nom porté des deux côtés : l\'assertion sur le dépôt, juste en dessous, ne prouverait plus rien.',
        );

        $shared = self::moduleNamesSharedByBothRoots();

        self::assertSame(
            [],
            $shared,
            \sprintf(
                'Le module « %s » existe dans les deux racines. Le collecteur `%s` ne distingue pas `src/` de '
                .'`tests/Fixtures/` : les deux tomberaient dans la même couche de racine, se verraient l\'un '
                .'l\'autre, et le module du dérivé hériterait des éditions deptrac de la fixture sans en écrire '
                .'aucune. Choisissez un autre nom.',
                implode(' », « ', $shared),
                \sprintf(self::COLLECTOR, self::PLACEHOLDER),
            ),
        );
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

    // --- (e) Le répertoire de marque, énoncé trois fois ------------------------------

    /**
     * Ce que le contrat de rebranding (UX-DR-2) désigne : le répertoire de marque et les
     * deux fichiers qu'un dérivé y écrase, sans éditer un fichier de `src/Core/` ni de
     * `config/`.
     *
     * Les deux noms de fichier sont ancrés, pas seulement le répertoire : un énoncé qui
     * ne nommerait que le SVG laisserait le dérivé n'en remplacer qu'un — son logo sur
     * un moteur, le carré du socle sur l'autre, sans aucun signal.
     *
     * @var list<string>
     */
    private const array BRAND_ANCHORS = ['assets/brand/', 'favicon.svg', 'favicon.png'];

    /**
     * Les trois endroits où le contrat de rebranding est énoncé. Le dérivé lit celui
     * qu'il croise en premier ; les trois doivent donc dire la même chose.
     *
     * @var list<string>
     */
    private const array REBRANDING_STATEMENTS = [self::GUIDE, 'assets/styles/brand.css', 'README.md'];

    #[Test]
    #[DataProvider('lesEnoncesDuContratDeRebranding')]
    public function the_three_statements_of_the_rebranding_contract_name_the_same_brand_files(string $path, string $anchor): void
    {
        self::assertStringContainsString(
            \sprintf('`%s`', $anchor),
            GateFiles::read($path),
            \sprintf(
                '« %s » ne nomme pas `%s` : les trois énoncés du contrat de rebranding (UX-DR-2) ne disent plus la même chose, et un dérivé ira remplacer le mauvais fichier — ou n\'en remplacera qu\'un.',
                $path,
                $anchor,
            ),
        );
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function lesEnoncesDuContratDeRebranding(): Generator
    {
        foreach (self::REBRANDING_STATEMENTS as $path) {
            foreach (self::BRAND_ANCHORS as $anchor) {
                yield $path.' — '.$anchor => [$path, $anchor];
            }
        }
    }

    /**
     * La limite que la story 1.14 a refermée, et que le guide ne doit plus décrire.
     *
     * Le guide nomme ses limites assumées, ce qui est sa valeur ; une limite comblée qui
     * y reste est pire qu'une limite non écrite, parce qu'elle envoie un dérivé chercher
     * un contournement pour un problème qui n'existe plus.
     */
    #[Test]
    public function the_guide_no_longer_calls_the_brand_surface_incomplete(): void
    {
        self::assertStringNotContainsString(
            'La surface de marque n\'est pas encore complète',
            GateFiles::read(self::GUIDE),
            \sprintf('« %s » décrit encore la surface de marque comme incomplète, alors que le favicon et le `h1` sont livrés.', self::GUIDE),
        );
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
     * `$path` est résolu sous le dépôt, sauf pendant une sonde de `sweeping()` — c'est le
     * seul point où la racine bascule, et il bascule pour tout le monde, fournisseur
     * public compris.
     *
     * @param bool $mayBeEmpty un répertoire sans aucun sous-dossier est un état légitime
     *                         — `src/Module/` chez le socle livré tel quel
     *
     * @return Generator<string, array{string}>
     */
    private static function directoriesOf(string $path, bool $mayBeEmpty = false): Generator
    {
        $root = (self::$sweptRoot ?? GateFiles::projectDir()).'/'.$path;
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

        if (!$found && !$mayBeEmpty) {
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
     * Les entrées du `must_not` du filet `UndeclaredModule`, telles qu'elles sont écrites.
     *
     * @return list<mixed>
     */
    private static function exclusionsOfTheNet(): array
    {
        $net = self::layer('UndeclaredModule');

        if (null === $net) {
            throw new RuntimeException('`deptrac.yaml` ne déclare plus le filet `UndeclaredModule` : le guide doit suivre.');
        }

        $collectors = $net['collectors'] ?? null;
        $excluded = [];

        foreach (\is_array($collectors) ? $collectors : [] as $collector) {
            if (\is_array($collector) && \is_array($collector['must_not'] ?? null)) {
                array_push($excluded, ...array_values($collector['must_not']));
            }
        }

        return $excluded;
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
