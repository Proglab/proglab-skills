<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Module\Demo\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Une classe de contrôleur par ressource, préfixe de chemin au niveau de la classe,
 * chemin anglais sans préfixe de langue (AD-6).
 */
#[Route('/demo/widgets', name: 'app_demo_widget_')]
final class DemoWidgetController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return new Response('demo widgets');
    }
}
