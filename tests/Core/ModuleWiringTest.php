<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Core\Contract\ModuleDescriptor;
use App\Core\Service\ModuleRegistry;
use App\Tests\Fixtures\Module\Billing\Service\BillingDescriptor;
use App\Tests\Fixtures\Module\Demo\Entity\DemoWidget;
use App\Tests\Fixtures\Module\Demo\Service\DemoDescriptor;
use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver as BundleMappingDriver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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

    #[Test]
    public function the_translation_catalogue_of_a_module_is_read(): void
    {
        self::bootKernel();

        $translator = self::getContainer()->get(TranslatorInterface::class);

        self::assertSame(
            'Widgets de démonstration',
            $translator->trans('widget.index.title', [], 'demo', 'fr'),
        );
    }
}
