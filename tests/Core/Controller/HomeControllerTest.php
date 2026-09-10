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
