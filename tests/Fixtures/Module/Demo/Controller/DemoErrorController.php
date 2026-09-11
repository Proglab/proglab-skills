<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Module\Demo\Controller;

use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Deux actions qui lèvent, et rien d'autre.
 *
 * Le socle n'a encore ni firewall, ni exception métier, ni la moindre action capable
 * d'échouer : sans ces deux routes, une 403 et une 500 ne peuvent être provoquées qu'en
 * rendant le template à la main — ce qui prouverait le template et **pas** le chemin qui
 * y mène. Le `TwigErrorRenderer` natif, le choix du fichier par statut, la sous-requête
 * d'erreur et son héritage de locale ne s'exercent qu'à travers la pile HTTP complète.
 *
 * **Ce que ces deux routes ne prouvent pas, et qu'il faut lire ici plutôt que découvrir
 * plus tard.** La 403 levée ici n'est pas un accès refusé : c'est une exception
 * construite à la main. La vraie — celle d'un utilisateur authentifié devant une zone
 * fermée — n'existera qu'avec le firewall de la story 1.6 et les permissions de
 * l'Epic 2. Ce qui est prouvé ici est le rendu de la page, pas la décision qui y mène.
 *
 * Elles vivent dans `tests/Fixtures/Module/` — donc câblées par les mêmes globs que les
 * modules d'un client (`config/routes.yaml`, `config/services.yaml`, `deptrac.yaml`), et
 * exclues du plancher d'accessibilité par le filtre `App\Tests\` de `pages()`.
 */
#[Route('/demo/errors', name: 'app_demo_error_')]
final class DemoErrorController extends AbstractController
{
    /**
     * Une 403 traversant la pile HTTP complète, sans firewall.
     */
    #[Route('/forbidden', name: 'forbidden', methods: ['GET'])]
    public function forbidden(): Response
    {
        throw new AccessDeniedHttpException('Fixture : un refus fabriqué, pas une décision de sécurité.');
    }

    /**
     * Une 500 : une exception qui n'est pas une `HttpExceptionInterface`, donc le cas que
     * personne n'avait prévu — celui que Symfony journalise en `critical`.
     */
    #[Route('/unexpected', name: 'unexpected', methods: ['GET'])]
    public function unexpected(): Response
    {
        throw new RuntimeException('Fixture : la panne inattendue que la story 1.10 doit rendre lisible.');
    }
}
