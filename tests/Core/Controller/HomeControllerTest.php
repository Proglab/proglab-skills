<?php

declare(strict_types=1);

namespace App\Tests\Core\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    #[Test]
    public function it_serves_a_minimal_home_page(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_home');
        self::assertSelectorCount(1, 'h1');
    }

    /**
     * Le seul endroit du dépôt où l'on regarde une page réellement rendue plutôt qu'un
     * fichier.
     *
     * `AssetPipelineTest` vérifie que `base.html.twig` appelle `importmap()`, mais une
     * recherche de sous-chaîne dans un fichier ne voit pas la faute ordinaire : un
     * gabarit d'écran qui surcharge `{% block javascripts %}` sans `{{ parent() }}`, ou
     * un appel commenté en Twig. La page part alors sans feuille de style et sans
     * JavaScript, avec un code 200 et sa `h1` — donc verte partout ailleurs.
     */
    #[Test]
    public function the_theme_and_the_import_map_reach_the_rendered_page(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertSelectorExists('script[type="importmap"]', 'La page ne porte aucune import map : rien de ce que la chaîne d\'assets installe n\'atteint le navigateur.');
        self::assertSelectorExists('link[rel="stylesheet"]', 'La page ne porte aucune feuille de style : le thème est compilé et jamais servi.');
    }

    /**
     * La chaîne complète, en un test : le glob découvre les modules, chacun se déclare
     * par un contrat de `Core/Contract/`, le socle les lit à travers ce seul contrat, et
     * la page les rend. Sans cette assertion, supprimer le bloc `{% if modules %}` du
     * gabarit laissait toute la suite verte.
     */
    #[Test]
    public function it_renders_the_modules_the_glob_discovered(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertSelectorTextContains('[data-modules]', 'Demo');
        self::assertSelectorTextContains('[data-modules]', 'Billing');
    }
}
