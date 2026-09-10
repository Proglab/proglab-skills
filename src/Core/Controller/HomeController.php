<?php

declare(strict_types=1);

namespace App\Core\Controller;

use App\Core\Service\ModuleRegistry;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'accueil. L'Epic 3 en fera la roadmap ; ici elle prouve seulement que
 * l'application répond, et que le socle voit les modules déclarés.
 */
final class HomeController extends AbstractController
{
    public function __construct(private readonly ModuleRegistry $modules)
    {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    #[Template('home/index.html.twig')]
    public function index(): array
    {
        return ['modules' => $this->modules->names()];
    }
}
