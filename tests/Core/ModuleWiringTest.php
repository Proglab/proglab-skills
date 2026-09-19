<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Core\Contract\ModuleDescriptor;
use App\Core\Service\ModuleRegistry;
use App\Tests\Core\Quality\GateFiles;
use App\Tests\Fixtures\Module\Billing\Service\BillingDescriptor;
use App\Tests\Fixtures\Module\Demo\Entity\DemoWidget;
use App\Tests\Fixtures\Module\Demo\Enum\DemoWidgetStatus;
use App\Tests\Fixtures\Module\Demo\Exception\DemoWidgetNotFound;
use App\Tests\Fixtures\Module\Demo\Message\RefreshDemoWidget;
use App\Tests\Fixtures\Module\Demo\MessageHandler\RefreshDemoWidgetHandler;
use App\Tests\Fixtures\Module\Demo\Service\DemoDescriptor;
use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver as BundleMappingDriver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use DOMDocument;
use DOMElement;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Un module est découvert par les globs de `config/`, et rien d'autre.
 *
 * Les modules de démonstration vivent sous `tests/Fixtures/Module/`, sur un miroir
 * `when@test` des quatre globs de production. Aucun fichier de `config/` ni de
 * `src/Core/` n'a été touché pour les accueillir : c'est exactement ce que ce test
 * affirme.
 */
final class ModuleWiringTest extends WebTestCase
{
    /**
     * Le chemin de clés du bloc de découverte du socle : la référence des deux autres.
     *
     * @var list<string>
     */
    private const array CORE_BLOCK = ['services', 'App\\Core\\'];

    /**
     * Les deux blocs de découverte des modules, et le nom sous lequel un échec désigne
     * celui qui est en retard sur l'autre.
     *
     * @var array<string, list<string>>
     */
    private const array MODULE_BLOCKS = [
        'le glob des modules' => ['services', 'App\\Module\\'],
        'son miroir `when@test`' => ['when@test', 'services', 'App\\Tests\\Fixtures\\Module\\'],
    ];

    #[Test]
    public function the_entity_of_a_module_is_mapped_by_the_orm(): void
    {
        self::bootKernel();

        $metadata = self::getContainer()->get(EntityManagerInterface::class)
            ->getClassMetadata(DemoWidget::class);

        self::assertSame('demo_widget', $metadata->getTableName());
        self::assertSame(['id', 'label'], $metadata->getFieldNames());
    }

    #[Test]
    public function the_route_of_a_module_is_loaded(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/widgets');

        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_demo_widget_index');
    }

    #[Test]
    public function the_service_of_a_module_is_discovered_behind_the_core_contract(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            ModuleDescriptor::class,
            self::getContainer()->get(DemoDescriptor::class),
        );
        self::assertInstanceOf(
            ModuleDescriptor::class,
            self::getContainer()->get(BillingDescriptor::class),
        );
    }

    /**
     * La parité des deux racines dans le conteneur : un module ne propose jamais comme
     * service ce que le socle exclut.
     *
     * **Ce test prouve une non-définition, et pas une non-résolution.**
     * `getContainer()->has(X)` répondrait « non » pour les trois classes ci-dessous quoi
     * qu'il arrive : elles sont inutilisées, donc un service privé qu'elles auraient
     * produit serait de toute façon supprimé par la passe de nettoyage, et le test
     * resterait vert avec le glob cassé. La preuve porte donc sur le **dump de débogage
     * du conteneur** (`debug.container.dump`, ce que lit `debug:container`), écrit par
     * `ContainerBuilderDebugDumpPass` en `TYPE_BEFORE_REMOVING` : il liste les
     * définitions telles que les globs les ont produites, suppression comprise. Ne pas
     * « simplifier » ceci en `has()`.
     *
     * Le contrôle positif est `the_message_handler_of_a_module_stays_a_service()` juste
     * en dessous : sans lui, une erreur de lecture du dump rendrait ce test vert pour
     * tout.
     */
    #[Test]
    #[DataProvider('lesClassesDeDonneesDuModuleDeDemonstration')]
    public function no_data_class_of_a_module_is_offered_to_the_container(string $class): void
    {
        // `X::class` est résolu à la compilation, sans autoload : sans cette ligne, le
        // test resterait vert sur une classe de fixture supprimée ou déplacée, et
        // n'affirmerait plus rien du tout.
        self::assertTrue(
            class_exists($class),
            \sprintf('La classe de fixture « %s » n\'existe pas : sans elle, ce test ne prouve rien.', $class),
        );

        self::assertNotContains(
            $class,
            self::definitionIdsOfTheTestContainer(),
            \sprintf(
                'Le conteneur définit « %s » comme service. Le glob des modules de `config/services.yaml` '
                .'n\'exclut pas le dossier de données qui la porte, là où celui du socle l\'exclut. '
                .'**Ce test est le seul endroit qui le voit** : ces classes sont des services privés que '
                .'personne n\'injecte, donc la passe de suppression les retire avant que '
                .'`DefinitionErrorExceptionPass` ne les examine, et `lint:container` sort en 0 dans les deux '
                .'environnements. Ne comptez pas sur la porte pour rattraper ceci.',
                $class,
            ),
        );
    }

    /**
     * Les trois dossiers que le glob des modules oubliait, un par ligne de la matrice.
     * Les classes sont référencées par `::class` : une faute de frappe ne peut pas rendre
     * ce test vert pour une mauvaise raison.
     *
     * @return Generator<string, array{string}>
     */
    public static function lesClassesDeDonneesDuModuleDeDemonstration(): Generator
    {
        yield 'Enum/' => [DemoWidgetStatus::class];
        yield 'Exception/' => [DemoWidgetNotFound::class];
        yield 'Message/' => [RefreshDemoWidget::class];
    }

    /**
     * `MessageHandler/` n'est pas `Message/` : un handler *est* un service, et c'est
     * `#[AsMessageHandler]` qui le tague. Le glob du socle le laisse passer, celui des
     * modules doit faire pareil — une exclusion trop large casserait le seul câblage
     * asynchrone qu'un module puisse avoir.
     */
    #[Test]
    public function the_message_handler_of_a_module_stays_a_service(): void
    {
        self::assertContains(
            RefreshDemoWidgetHandler::class,
            self::definitionIdsOfTheTestContainer(),
            'Le glob des modules n\'enregistre plus les handlers de message : l\'exclusion porte sur '
            .'`Message` seul, jamais sur `MessageHandler`.',
        );
    }

    /**
     * Les deux globs de modules et celui du socle, comparés sur leur liste d'exclusion.
     *
     * Ils vivent l'un sous l'autre dans le même fichier pour que la divergence se voie à
     * l'œil ; ce test est ce qui la voit quand personne ne regarde. Chaque glob a son
     * propre cas, donc le message nomme celui qui est en retard — modifier le glob de
     * production sans son miroir `when@test`, ou l'inverse, rougit ici.
     *
     * **L'assertion porte sur le chemin autant que sur la liste**, et le chemin attendu
     * est le `resource` du bloc lui-même. Comparer les seules listes laisserait passer
     * `exclude: '../src/Module/{…}'`, où le segment d'étoile du `resource` a disparu : la
     * liste reste la bonne, mais plus aucune exclusion ne tombe sur un module, et
     * `Entity/DemoWidget` redevient une définition. C'est le glob entier qui doit être le
     * `resource` suivi de la liste du socle, à la virgule et à l'ordre près.
     *
     * @param list<string> $keys
     */
    #[Test]
    #[DataProvider('lesBlocsDeModules')]
    public function every_module_glob_excludes_what_the_core_excludes(string $label, array $keys): void
    {
        $module = self::discoveryBlock($keys);

        self::assertSame(
            $module['resource'].self::excludedDirectoriesOf(self::CORE_BLOCK),
            $module['exclude'],
            \sprintf(
                '%s (`config/services.yaml`, clé `%s`) n\'exclut pas exactement ce que le glob du socle exclut, '
                .'sous le chemin de son propre `resource`. Attendu : le `resource` du bloc suivi de la liste du '
                .'socle — mêmes dossiers, `Contract` compris (une exclusion n\'est pas une autorisation, elle '
                .'empêche l\'erreur de devenir un service), et même chemin (une exclusion qui ne descend plus '
                .'jusqu\'au module ne retire plus rien).',
                $label,
                implode('.', $keys),
            ),
        );
    }

    /**
     * L'oracle des deux assertions ci-dessus, tenu par sa propre règle.
     *
     * `every_module_glob_excludes_what_the_core_excludes()` ne lit du bloc du socle que
     * sa liste `{…}` : écrire `exclude: '../src/Core/*&#47;{…}'` la laisserait intacte
     * alors que l'exclusion ne descendrait plus sur `src/Core/Entity`, `…/Enum` ni
     * `…/Message` — le défaut que les stories 1.8 et 1.11 ont refermé — et les deux cas
     * resteraient verts, `lint:container` aussi, pour la raison que leur message de
     * défaut documente. Le socle obéit donc à la même règle que les modules : son
     * `exclude` est son `resource` suivi de sa liste.
     */
    #[Test]
    public function the_core_glob_excludes_under_its_own_resource(): void
    {
        $core = self::discoveryBlock(self::CORE_BLOCK);

        self::assertSame(
            $core['resource'].self::excludedDirectoriesOf(self::CORE_BLOCK),
            $core['exclude'],
            'Le glob du socle (`config/services.yaml`, clé `services.App\\Core\\`) n\'exclut plus sous le chemin '
            .'de son propre `resource` : sa liste de dossiers a beau être la bonne, elle ne retire plus rien. '
            .'C\'est aussi l\'oracle de `every_module_glob_excludes_what_the_core_excludes()`, qui resterait '
            .'vert sur un socle cassé.',
        );
    }

    /**
     * @return Generator<string, array{string, list<string>}>
     */
    public static function lesBlocsDeModules(): Generator
    {
        foreach (self::MODULE_BLOCKS as $label => $keys) {
            yield $label => [$label, $keys];
        }
    }

    #[Test]
    public function the_core_sees_the_modules_through_the_contract_alone(): void
    {
        self::bootKernel();

        $names = self::getContainer()->get(ModuleRegistry::class)->names();

        // La présence, pas l'égalité : le glob de production est actif en test aussi,
        // donc affirmer la liste exacte ferait casser un test du socle au premier vrai
        // module d'un dérivé — la friction que le glob existe pour supprimer.
        self::assertContains('Demo', $names);
        self::assertContains('Billing', $names);
    }

    /**
     * Les cinq tests ci-dessus tombent tous sur le miroir `when@test`. Celui-ci regarde
     * le câblage de **production** tel que le conteneur l'a réellement compilé : sans
     * lui, supprimer le mapping `Module` de `doctrine.yaml` ou casser le glob de
     * `routes.yaml` passerait au vert, puisque `src/Module/` est vide et que rien ne
     * l'observe.
     *
     * Un seul des quatre câblages est observable ici, et il faut savoir pourquoi. Le
     * mapping Doctrine vise un **répertoire existant** (`src/Module`), donc le conteneur
     * en garde la trace même vide. Les trois autres sont des globs : tant que
     * `src/Module/` ne contient aucun module, ils ne correspondent à rien, et ni le
     * routeur ni le conteneur n'enregistrent quoi que ce soit à observer. C'est la
     * couture que les Design Notes nomment, mesurée ici plutôt que supposée — le premier
     * vrai module d'un dérivé la referme.
     */
    #[Test]
    public function the_production_wiring_of_the_module_root_is_compiled_in(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $moduleRoot = str_replace(
            '\\',
            '/',
            $container->getParameter('kernel.project_dir').'/src/Module',
        );

        $driver = $container->get(EntityManagerInterface::class)
            ->getConfiguration()
            ->getMetadataDriverImpl();

        // DoctrineBundle enveloppe la chaîne pour y brancher les générateurs d'id.
        if ($driver instanceof BundleMappingDriver) {
            $driver = $driver->getDriver();
        }

        self::assertInstanceOf(MappingDriverChain::class, $driver);

        $mappedDirs = [];

        foreach ($driver->getDrivers() as $nested) {
            if ($nested instanceof AttributeDriver) {
                foreach ($nested->getPaths() as $path) {
                    $mappedDirs[] = str_replace('\\', '/', $path);
                }
            }
        }

        self::assertContains($moduleRoot, $mappedDirs);
    }

    /**
     * `src/Module/` est livré vide : le module de démonstration vit en fixture, donc rien
     * ne part chez un client. C'est une décision de la spec, pas un accident.
     */
    #[Test]
    public function the_module_root_ships_empty(): void
    {
        $root = \dirname(__DIR__, 2).'/src/Module';

        $entries = scandir($root);
        self::assertIsArray($entries);

        self::assertSame([], array_values(array_diff($entries, ['.', '..', '.gitkeep'])));
    }

    /**
     * Le critère « un module livre ses catalogues » vaut pour les **trois** langues :
     * un module qui ne livrerait que le français rendrait sa clé en clair aux deux
     * autres, et le socle ne s'en apercevrait pas.
     *
     * `tests/Core/Translation/CatalogParityTest.php` tient la parité comme une règle,
     * sur tous les catalogues du dépôt. Celui-ci tient le câblage : le translator
     * parcourt bien la racine des modules, dans chacune des trois langues.
     */
    #[Test]
    #[DataProvider('lesCataloguesDuModuleDeDemonstration')]
    public function the_translation_catalogue_of_a_module_is_read(string $locale, string $expected): void
    {
        self::bootKernel();

        $translator = self::getContainer()->get(TranslatorInterface::class);

        self::assertSame(
            $expected,
            $translator->trans('widget.index.title', [], 'demo', $locale),
            \sprintf('Le catalogue « demo.%s.yaml » du module de démonstration n\'est pas lu.', $locale),
        );
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function lesCataloguesDuModuleDeDemonstration(): Generator
    {
        yield 'français' => ['fr', 'Widgets de démonstration'];
        yield 'anglais' => ['en', 'Demonstration widgets'];
        yield 'néerlandais' => ['nl', 'Demonstratiewidgets'];
    }

    // --- Lecture -------------------------------------------------------------------

    /**
     * Les identifiants de service du dump de débogage du conteneur de test — la même
     * source que `php bin/console --env=test debug:container --show-hidden`, lue en
     * processus plutôt que par un `Process`.
     *
     * Le dump est écrit par `ContainerBuilderDebugDumpPass`, enregistrée en
     * `TYPE_BEFORE_REMOVING` : il porte donc les définitions telles que les globs les ont
     * produites, y compris celles qu'un service inutilisé perdrait ensuite. C'est ce qui
     * fait la différence entre « cette classe n'est pas un service » et « ce service
     * n'était utilisé par personne ».
     *
     * @return list<string>
     */
    private static function definitionIdsOfTheTestContainer(): array
    {
        self::bootKernel();
        $container = self::getContainer();

        // Le chemin du dump, composé plutôt que lu dans `debug.container.dump`. Ce
        // paramètre-là n'existe **que** si `kernel.debug` est vrai
        // (`FrameworkExtension::registerDebugConfiguration()`) : le lire ferait mourir
        // les quatre cas sur une `ParameterNotFoundException` sous `APP_DEBUG=0`, et le
        // message ci-dessous — qui dit exactement quoi faire — ne serait jamais lu. Les
        // deux paramètres ci-dessus existent dans tous les environnements, et leur
        // composition est celle que `FrameworkExtension` donne au dump.
        $dump = \sprintf(
            '%s/%s.xml',
            $container->getParameter('kernel.build_dir'),
            $container->getParameter('kernel.container_class'),
        );

        if (!is_file($dump)) {
            throw new RuntimeException(\sprintf('« %s » n\'existe pas : le conteneur de test n\'est pas en mode débogage, et rien ne dit alors ce que les globs ont produit. Ce test demande `APP_DEBUG=1`.', $dump));
        }

        $document = new DOMDocument();

        if (!$document->load($dump)) {
            throw new RuntimeException(\sprintf('« %s » n\'a pas pu être lu comme du XML.', $dump));
        }

        $ids = [];

        foreach ($document->getElementsByTagName('service') as $service) {
            $id = $service->getAttribute('id');

            // **Une définition refusée est présente dans le dump, et il ne faut surtout
            // pas la compter.** Quand une classe est écartée du glob, `FileLoader` ne
            // l'oublie pas : il enregistre `$class` comme définition abstraite taguée
            // `container.excluded` (`addContainerExcludedTag()`), et une classe abstraite
            // ou une interface reçoit en plus un id préfixé `.abstract.`. Les compter
            // reviendrait à lire « refusée » comme « enregistrée » : `#[Exclude]` sur le
            // handler tuerait le seul câblage asynchrone d'un module sans rien faire
            // rougir, et une exclusion écrite à la granularité fichier ferait rougir le
            // test des trois classes de données en affirmant l'exact contraire de la
            // vérité. Ce helper ne rend donc que ce que le conteneur a réellement retenu.
            if ('' === $id || str_starts_with($id, '.abstract.') || self::isExcluded($service)) {
                continue;
            }

            $ids[] = $id;
        }

        if ([] === $ids) {
            throw new RuntimeException(\sprintf('« %s » ne déclare aucun service : le dump n\'a pas la forme attendue.', $dump));
        }

        return $ids;
    }

    /**
     * Le `<service>` porte-t-il le tag `container.excluded` ?
     *
     * Les enfants directs seulement : un service anonyme imbriqué a ses propres `<tag>`,
     * et `getElementsByTagName()` les remonterait ici.
     */
    private static function isExcluded(DOMElement $service): bool
    {
        foreach ($service->childNodes as $child) {
            if ($child instanceof DOMElement
                && 'tag' === $child->nodeName
                && 'container.excluded' === $child->getAttribute('name')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le `resource` et l'`exclude` d'un bloc de découverte de `config/services.yaml`.
     *
     * Lu dans le YAML parsé : une recherche de chaîne confondrait les deux globs de
     * modules, qui sont volontairement écrits l'un sous l'autre.
     *
     * @param list<string> $keys
     *
     * @return array{resource: string, exclude: string}
     */
    private static function discoveryBlock(array $keys): array
    {
        $node = Yaml::parse(GateFiles::read('config/services.yaml'));

        foreach ($keys as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                throw new RuntimeException(\sprintf('`config/services.yaml` ne porte plus de bloc de découverte sous `%s`.', implode('.', $keys)));
            }

            $node = $node[$key];
        }

        $resource = \is_array($node) ? $node['resource'] ?? null : null;
        $exclude = \is_array($node) ? $node['exclude'] ?? null : null;

        if (!\is_string($resource) || !\is_string($exclude)) {
            throw new RuntimeException(\sprintf('Le bloc `%s` de `config/services.yaml` ne porte plus un `resource` et un `exclude` en chaîne.', implode('.', $keys)));
        }

        return ['resource' => $resource, 'exclude' => $exclude];
    }

    /**
     * La liste `{…}` d'un bloc de découverte, accolades comprises — la forme telle qu'elle
     * est écrite, pour que la comparaison porte aussi sur l'ordre : deux listes identiques
     * se comparent à l'œil, deux listes équivalentes non.
     *
     * @param list<string> $keys
     */
    private static function excludedDirectoriesOf(array $keys): string
    {
        $exclude = self::discoveryBlock($keys)['exclude'];

        if (1 !== preg_match('/(?P<directories>\{[^{}]+\})$/', $exclude, $matches)) {
            throw new RuntimeException(\sprintf('L\'exclusion du bloc `%s` de `config/services.yaml` ne se termine plus par une liste de dossiers entre accolades.', implode('.', $keys)));
        }

        return $matches['directories'];
    }
}
