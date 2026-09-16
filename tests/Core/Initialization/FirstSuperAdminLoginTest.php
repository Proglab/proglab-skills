<?php

declare(strict_types=1);

namespace App\Tests\Core\Initialization;

use App\Core\Dto\Input\FirstSuperAdminInput;
use App\Core\Service\DerivativeInitializer;
use App\Tests\Core\Security\LoginThrottling;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;

/**
 * Le compte fabriqué par le chemin console est-il utilisable par le chemin HTTP ?
 *
 * C'est l'AC 2 de la story, et c'est la seule chose que ce test prouve — mais personne
 * d'autre ne peut la prouver. `DerivativeInitializerTest` vérifie que le hachage se relit
 * avec le hasher du projet ; ce test-ci vérifie la suite : que le firewall accepte ce
 * compte, que le `user_checker` ne le refuse pas, et qu'il atterrit là où
 * `security.yaml:default_target_path` le dit.
 *
 * **Ce que ce test ne peut pas prouver, et le dit en toutes lettres : que le premier Super
 * admin *voie la roadmap*.** L'Epic 3 la pose. AC 2 se ferme ici sur `app_home` telle
 * qu'elle existe aujourd'hui — une page d'accueil —, et la couture est assumée par l'epic.
 *
 * Le compte est créé **par le service** et non par `Accounts::create()` : c'est le chemin
 * de production de `app:init`, et c'est lui qu'on veut voir atterrir sur une page.
 */
final class FirstSuperAdminLoginTest extends WebTestCase
{
    private const string EMAIL = 'fabrice@example.test';
    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    /**
     * Le ralentissement de la story 1.7 vit dans un pool de fichiers que DAMA n'annule
     * pas : sans cette remise à zéro, une exécution rapprochée hériterait des compteurs
     * d'une autre classe de test.
     */
    protected function setUp(): void
    {
        LoginThrottling::forget();
    }

    #[Test]
    public function the_account_created_by_the_console_can_sign_in_and_lands_on_the_home_page(): void
    {
        $client = self::createClient();

        self::getContainer()->get(DerivativeInitializer::class)
            ->createFirstSuperAdmin(new FirstSuperAdminInput(self::EMAIL, self::PASSWORD));

        $client->submit(self::loginForm($client));

        self::assertResponseRedirects('/', null, 'Le premier Super admin doit atterrir sur l\'accueil — `default_target_path: app_home`.');

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_home');
    }

    /**
     * Le formulaire réellement rendu, jeton CSRF compris — jamais un POST assemblé à la
     * main.
     */
    private static function loginForm(KernelBrowser $client): Form
    {
        $form = $client->request('GET', '/login')->filter('form')->form();

        $form['_username'] = self::EMAIL;
        $form['_password'] = self::PASSWORD;

        return $form;
    }
}
