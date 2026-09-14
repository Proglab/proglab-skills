<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use App\Tests\Core\Quality\GateFiles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Yaml\Yaml;

/**
 * Le limiteur **nommé à part** de la story 1.9, et la preuve qu'il est bien à part.
 *
 * La demande de réinitialisation est une porte ouverte qui envoie un email : sans borne,
 * elle est un moyen d'inonder n'importe quelle boîte aux lettres et de fabriquer autant de
 * lignes qu'on veut dans la file. `login_throttling` ne la couvre pas — il ne regarde que
 * les échecs d'authentification du pare-feu, et cette page n'en produit aucun.
 *
 * Les deux précautions de `LoginThrottlingTest` s'appliquent telles quelles : l'état du
 * limiteur vit dans un pool de fichiers que DAMA n'annule pas, donc `setUp()` l'efface ;
 * et les secondes attendues se lisent **sur le limiteur**, jamais dans le texte rendu.
 */
final class PasswordResetThrottlingTest extends WebTestCase
{
    private const string PATH = '/password/forgot';

    private const string EMAIL = 'marc@example.test';

    /**
     * Le palier de `config/packages/rate_limiter.yaml`. Une valeur écrite ici et lue
     * là-bas : `the_limit_is_the_one_the_configuration_declares()` refuse toute dérive
     * entre les deux.
     */
    private const int LIMIT = 5;

    /**
     * La marge accordée aux comparaisons de délai — du temps d'horloge réel sépare le
     * calcul fait par la page de la lecture faite par le test.
     */
    private const int CLOCK_SLACK = 3;

    private const string ANOTHER_IP = '203.0.113.7';

    protected function setUp(): void
    {
        PasswordThrottling::forget();
    }

    #[Test]
    public function the_demands_below_the_threshold_are_served(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, 'un-mot-de-passe-assez-long');

        for ($attempt = 1; $attempt <= self::LIMIT; ++$attempt) {
            $client->submit(self::requestForm($client));

            self::assertResponseIsSuccessful(\sprintf('La demande n°%d doit être servie : le limiteur se déclenche trop tôt.', $attempt));
        }
    }

    #[Test]
    public function the_demand_after_the_last_one_is_refused_with_its_retry_after(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, 'un-mot-de-passe-assez-long');
        self::spendEveryAttempt($client);

        $client->submit(self::requestForm($client));

        self::assertResponseStatusCodeSame(429, 'Un refus de débit n\'est pas une entrée invalide : il se dit en 429.');
        self::assertResponseHasHeader('Retry-After', 'La RFC 9110 veut cet en-tête sur un 429 : sans lui, un client automatique réessaie au hasard.');

        $header = $client->getResponse()->headers->get('Retry-After');
        self::assertIsString($header);

        $expected = PasswordThrottling::secondsLeft(self::getContainer());

        self::assertEqualsWithDelta(
            $expected,
            (int) $header,
            self::CLOCK_SLACK,
            'Les secondes de l\'en-tête ne sont pas celles du limiteur : elles ont été déduites au lieu d\'être lues.',
        );

        self::assertSelectorTextContains('[role="alert"]', 'Patientez', 'Le refus doit dire combien de temps attendre, en toutes lettres.');
    }

    /**
     * La ligne qui compte : ce limiteur n'est pas celui de la connexion. Épuiser l'un ne
     * doit pas fermer l'autre — sinon cinq demandes de lien empêcheraient de se connecter,
     * et cinq erreurs de mot de passe empêcheraient d'en demander un nouveau, ce qui est
     * exactement l'inverse du service rendu.
     */
    #[Test]
    public function spending_this_limiter_leaves_the_login_one_untouched(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, 'un-mot-de-passe-assez-long');

        self::spendEveryAttempt($client);
        $client->submit(self::requestForm($client));
        self::assertResponseStatusCodeSame(429);

        $client->submit(self::loginForm($client, 'le-mauvais-mot-de-passe'));

        self::assertResponseStatusCodeSame(
            422,
            'Une salve de demandes de lien a ralenti la connexion : les deux limiteurs partagent leur compteur.',
        );
        self::assertSame(
            5,
            LoginThrottling::remainingAttempts(self::getContainer(), self::EMAIL) + 1,
            'Le limiteur de connexion a été entamé par les demandes de lien.',
        );
    }

    #[Test]
    public function spending_the_login_limiter_leaves_this_one_untouched(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, 'un-mot-de-passe-assez-long');

        for ($attempt = 0; $attempt <= 5; ++$attempt) {
            $client->submit(self::loginForm($client, 'le-mauvais-mot-de-passe'));
        }

        self::assertResponseStatusCodeSame(429, 'Le ralentissement de connexion devrait être atteint.');

        $client->submit(self::requestForm($client));

        self::assertResponseIsSuccessful('Une salve d\'échecs de connexion a fermé la demande de lien : les deux limiteurs partagent leur compteur.');
        self::assertSame(
            self::LIMIT - 1,
            PasswordThrottling::remainingAttempts(self::getContainer()),
            'Le limiteur de la demande n\'a pas compté sa propre soumission, ou il a compté celles de la connexion.',
        );
    }

    /**
     * Le limiteur est indexé sur l'adresse : une autre machine n'hérite pas du refus.
     */
    #[Test]
    public function another_address_does_not_inherit_the_refusal(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, 'un-mot-de-passe-assez-long');
        self::spendEveryAttempt($client);

        $client->submit(self::requestForm($client));
        self::assertResponseStatusCodeSame(429);

        $client->submit(self::requestForm($client), [], ['REMOTE_ADDR' => self::ANOTHER_IP]);

        self::assertResponseIsSuccessful('Le refus a suivi autre chose que l\'adresse.');
    }

    /**
     * Une requête **sans adresse** est bornée, et elle ne borne personne d'autre.
     *
     * `getClientIp()` rend `null` quand `REMOTE_ADDR` est absent — une requête forgée, un
     * serveur mal configuré. Deux choses doivent rester vraies : ces requêtes ne
     * contournent pas le limiteur, et leur seau n'est pas celui d'un client qui, lui, a une
     * adresse.
     *
     * **Ce test ne discrimine pas la clé sentinelle du contrôleur**, et il ne prétend pas
     * le faire : `create(null)` et `create('sans-adresse')` produisent deux seaux
     * différents mais tout aussi partagés, donc les deux passent ici. Ce qu'il tient est la
     * propriété, pas son implémentation — et elle rougirait si quelqu'un indexait le
     * limiteur sur autre chose que le client.
     */
    #[Test]
    public function a_request_without_an_address_does_not_close_the_page_for_everyone(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, 'un-mot-de-passe-assez-long');

        for ($attempt = 0; $attempt <= self::LIMIT; ++$attempt) {
            $client->submit(self::requestForm($client), [], ['REMOTE_ADDR' => null]);
        }

        self::assertResponseStatusCodeSame(429, 'Les requêtes sans adresse doivent quand même être bornées, entre elles.');

        $client->submit(self::requestForm($client), [], ['REMOTE_ADDR' => self::ANOTHER_IP]);

        self::assertResponseIsSuccessful('Des requêtes sans adresse ont fermé la page pour un client qui, lui, en a une : le seau est global.');
    }

    /**
     * Un refus de débit ne doit rien envoyer : c'est toute la raison d'être du limiteur.
     */
    #[Test]
    public function a_refused_demand_sends_nothing(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, 'un-mot-de-passe-assez-long');
        self::spendEveryAttempt($client);

        $client->submit(self::requestForm($client));

        self::assertResponseStatusCodeSame(429);
        self::assertSame(0, PasswordTokens::queuedCount(self::getContainer()), 'Une demande refusée a quand même mis un email en file.');

        // Une seule ligne, et non cinq : chaque demande servie a tué la précédente
        // (AD-15). Ce qui est vérifié ici est qu'aucune **sixième** n'est née.
        self::assertCount(1, PasswordTokens::rows(self::getContainer()), 'Une demande refusée a fait naître un jeton de plus.');
    }

    /**
     * Le limiteur est **nommé**, distinct des deux de la story 1.7, et sa valeur est celle
     * que ce fichier lit — pas une copie qui dériverait en silence.
     */
    #[Test]
    public function the_limiter_is_declared_apart_from_the_two_of_the_login(): void
    {
        /** @var array{framework: array{rate_limiter: array<string, array<string, mixed>>}} $config */
        $config = Yaml::parse(GateFiles::read('config/packages/rate_limiter.yaml'));

        $limiters = $config['framework']['rate_limiter'];

        self::assertArrayHasKey('password_request', $limiters, 'Le limiteur de la demande doit être déclaré à part, avec son propre nom.');
        self::assertArrayHasKey('login_attempt', $limiters);
        self::assertArrayHasKey('login_address', $limiters);

        self::assertSame(self::LIMIT, $limiters['password_request']['limit'] ?? null, 'Le palier déclaré a changé sans que ce test le sache.');
        self::assertNotSame(
            $limiters['login_attempt'],
            $limiters['password_request'],
            'Le limiteur de la demande est la copie de celui de la connexion : le nommer à part n\'apporte alors rien.',
        );
    }

    // -------------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------------

    private static function spendEveryAttempt(KernelBrowser $client): void
    {
        for ($attempt = 0; $attempt < self::LIMIT; ++$attempt) {
            $client->submit(self::requestForm($client));
        }
    }

    private static function requestForm(KernelBrowser $client): Form
    {
        $form = $client->request('GET', self::PATH)->filter('form')->form();

        $form['email'] = self::EMAIL;

        return $form;
    }

    private static function loginForm(KernelBrowser $client, string $password): Form
    {
        $form = $client->request('GET', '/login')->filter('form')->form();

        $form['_username'] = self::EMAIL;
        $form['_password'] = $password;

        return $form;
    }
}
