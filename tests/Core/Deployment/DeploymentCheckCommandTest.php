<?php

declare(strict_types=1);

namespace App\Tests\Core\Deployment;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\RequestContext;

/**
 * Le smoke test de la première commande du dépôt.
 *
 * La commande ne porte aucune règle — elle appelle `DeploymentReadiness` et traduit le
 * résultat en code de sortie. Ce qui se vérifie ici est donc le câblage : le nom se
 * résout, le service s'injecte, et **le code de sortie est le bon**, puisque c'est la
 * seule chose qu'un script de déploiement regarde.
 *
 * Les deux branches sont exercées en déplaçant l'hôte du `RequestContext` du conteneur de
 * test, ce qui est exactement le levier que le service lit. En environnement de test,
 * `DEFAULT_URI` vaut le `http://localhost` de `.env` : la branche « tout va bien » ne
 * pourrait pas exister sans ce déplacement.
 */
final class DeploymentCheckCommandTest extends KernelTestCase
{
    #[Test]
    public function an_unreachable_host_fails_the_check(): void
    {
        $tester = self::tester('localhost');

        self::assertSame(Command::FAILURE, $tester->execute([]), 'Un déploiement mal configuré doit sortir en échec : c\'est le code de sortie qui arrête un script.');
        self::assertStringContainsString('DEFAULT_URI', $tester->getDisplay());
    }

    #[Test]
    public function a_reachable_host_passes_the_check(): void
    {
        $tester = self::tester('erp.menuiserie-dubois.be');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('erp.menuiserie-dubois.be', $tester->getDisplay(), 'Le rapport doit dire quel hôte il a validé — une commande qui ne dit rien ne prouve rien.');
    }

    private static function tester(string $host): CommandTester
    {
        $kernel = self::bootKernel();

        // Le contexte du routeur est ce que le service lit, et il est mutable : le
        // déplacer ici est le seul levier qui exerce les deux branches. En test,
        // `DEFAULT_URI` vaut le `http://localhost` de `.env`.
        self::getContainer()->get('router')->getContext()->setHost($host);

        return new CommandTester(new Application($kernel)->find('app:deployment:check'));
    }
}
