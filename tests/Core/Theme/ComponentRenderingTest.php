<?php

declare(strict_types=1);

namespace App\Tests\Core\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Le kit est câblé, pas seulement copié.
 *
 * Un composant du kit dépend de trois choses qui vivent ailleurs que dans son fichier :
 * `html_cva` (twig/html-extra), `tailwind_merge`
 * (tales-from-a-dev/twig-tailwind-extra, dont la recette `recipes-contrib` n'est pas
 * exécutée par ce projet et dont la ligne de `config/bundles.php` s'écrit donc à la
 * main), et le répertoire de composants anonymes de `twig_component.yaml`. Il en manque
 * une, et chaque écran des stories 1.4, 1.6, 1.9 et 1.10 tombe au rendu — pas au lint.
 */
final class ComponentRenderingTest extends KernelTestCase
{
    #[Test]
    #[DataProvider('components')]
    public function a_component_of_the_kit_renders(string $template, string $expected): void
    {
        self::bootKernel();

        self::assertStringContainsString($expected, self::twig()->createTemplate($template)->render());
    }

    /**
     * Les classes de thème du socle doivent survivre au `tailwind_merge` du kit : c'est
     * lui qui décide, à chaque rendu, quelle classe l'emporte.
     */
    #[Test]
    public function a_theme_class_passed_by_the_caller_wins_over_the_kit_default(): void
    {
        self::bootKernel();

        $html = self::twig()->createTemplate('<twig:Button class="rounded-md">Enregistrer</twig:Button>')->render();

        self::assertStringContainsString('rounded-md', $html);
        self::assertStringNotContainsString('rounded-lg', $html, '`tailwind_merge` n\'a pas arbitré : les deux rayons coexistent et c\'est l\'ordre du CSS qui tranchera.');
    }

    private static function twig(): Environment
    {
        return self::getContainer()->get('twig');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function components(): iterable
    {
        yield 'Button' => ['<twig:Button>Enregistrer</twig:Button>', 'data-slot="button"'];
        yield 'Button destructif' => ['<twig:Button variant="destructive">Supprimer</twig:Button>', 'data-variant="destructive"'];
        yield 'Input' => ['<twig:Input type="email" name="email" />', 'data-slot="input"'];
        yield 'Label' => ['<twig:Label for="email">Adresse email</twig:Label>', 'data-slot="label"'];
        yield 'Alert' => ['<twig:Alert variant="destructive"><twig:Alert:Title>Échec</twig:Alert:Title></twig:Alert>', 'data-slot="alert"'];
        yield 'Badge' => ['<twig:Badge>Terminé</twig:Badge>', 'data-slot="badge"'];
        yield 'Card' => ['<twig:Card><twig:Card:Header><twig:Card:Title>Identité</twig:Card:Title></twig:Card:Header></twig:Card>', 'data-slot="card"'];

        // `DESIGN.md` documente ces deux titres comme un `h2` et un `h3`. Le kit rend un
        // `<div>` ; la prop `as` ajoutée au socle est ce qui permet à un écran de leur
        // donner un vrai niveau de titre plutôt que de rejouer la hiérarchie à côté.
        yield 'Card:Title en h2' => ['<twig:Card:Title as="h2">Identité</twig:Card:Title>', '<h2'];
        yield 'Alert:Title en h3' => ['<twig:Alert:Title as="h3">Échec</twig:Alert:Title>', '<h3'];

        // Et le rôle typographique du socle, à la place du `cn-font-heading` que le kit
        // référence sans jamais le définir.
        yield 'Card:Title porte le rôle heading' => ['<twig:Card:Title>Identité</twig:Card:Title>', 'text-heading'];
    }
}
