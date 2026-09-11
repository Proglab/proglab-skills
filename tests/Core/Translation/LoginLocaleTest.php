<?php

declare(strict_types=1);

namespace App\Tests\Core\Translation;

use App\Core\Enum\SupportedLocale;
use App\Tests\Core\Security\Accounts;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ce que l'UX promet : après connexion, la langue du profil prend le relais.
 *
 * Le barreau « compte » n'a pas été inséré dans `LocaleListener` — il ne peut pas l'être,
 * voir le commentaire de cette classe-là. La langue du compte est **écrite en session** à
 * la connexion réussie, sous la clé que la chaîne de résolution lit déjà. Ce test vérifie
 * l'effet promis par l'UX, pas le mécanisme : c'est ce qui permettra de changer de
 * mécanisme sans réécrire le test.
 */
final class LoginLocaleTest extends WebTestCase
{
    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    #[Test]
    public function after_signing_in_the_account_language_is_served(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), 'sophie@example.test', self::PASSWORD, language: SupportedLocale::Nl);

        self::signIn($client, 'sophie@example.test');

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('nl', $client->getRequest()->getLocale(), 'La langue du compte n\'est pas servie après la connexion.');
        self::assertSame('nl', self::renderedLanguage($client), 'Le document ne déclare pas la langue du compte après connexion.');
    }

    /**
     * La ligne de la matrice : un `?lang=` choisi **avant** de se connecter ne survit pas
     * à la connexion — la langue du compte l'écrase.
     */
    #[Test]
    public function a_language_chosen_before_signing_in_does_not_survive_the_login(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), 'sophie@example.test', self::PASSWORD, language: SupportedLocale::Nl);

        $client->request('GET', '/login?lang=en');
        self::assertSame('en', $client->getRequest()->getLocale(), 'Le `?lang=` de la page de connexion doit être appliqué avant la connexion.');

        self::signIn($client, 'sophie@example.test');

        $client->request('GET', '/');

        self::assertSame('nl', $client->getRequest()->getLocale(), 'Le choix fait avant la connexion a survécu : la langue du compte doit l\'écraser.');
    }

    /**
     * Le `?lang=` de la connexion, avant toute session : la page elle-même est traduite.
     */
    #[Test]
    public function the_login_page_is_served_in_the_requested_language(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login?lang=nl');

        self::assertResponseIsSuccessful();
        self::assertSame('nl', self::renderedLanguage($client));
        self::assertSelectorTextContains('h1', 'Aanmelden');

        $client->request('GET', '/login');
        self::assertSame('nl', self::renderedLanguage($client), 'Le choix de langue fait sur la connexion doit être mémorisé en session.');
    }

    private static function signIn(KernelBrowser $client, string $email): void
    {
        $form = $client->request('GET', '/login')->filter('form')->form();

        $form['_username'] = $email;
        $form['_password'] = self::PASSWORD;

        $client->submit($form);

        self::assertResponseRedirects('/', null, 'La connexion du test n\'a pas abouti.');
    }

    private static function renderedLanguage(KernelBrowser $client): string
    {
        return $client->getCrawler()->filter('html')->attr('lang') ?? '';
    }
}
