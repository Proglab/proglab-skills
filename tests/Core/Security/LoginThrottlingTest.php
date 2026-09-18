<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use App\Core\Enum\SupportedLocale;
use App\Tests\Core\Quality\GateFiles;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\RateLimiter\DefaultLoginRateLimiter;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * La matrice d'edge cases de la story 1.7, ligne par ligne.
 *
 * Deux précautions traversent toute la classe.
 *
 * **L'état du limiteur ne vit pas en base**, donc DAMA ne l'annule pas : il vit dans le
 * pool `cache.rate_limiter`, un adaptateur de fichiers qui survit à la méthode, à la
 * classe et à l'exécution. `setUp()` l'efface, sans quoi la suite deviendrait dépendante
 * de son ordre — et deux `make test` lancés dans le même quart d'heure partageraient les
 * compteurs du limiteur global.
 *
 * **Les secondes attendues se lisent sur le limiteur**, jamais sur le texte rendu
 * (`LoginThrottling::secondsLeft()`). Un test qui relirait le message pour vérifier le
 * message ne vérifierait rien — et c'est précisément l'écart qu'AD-18 existe pour
 * fermer : l'exception native, elle, ne connaît que des minutes arrondies au supérieur.
 */
final class LoginThrottlingTest extends WebTestCase
{
    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    private const string WRONG_PASSWORD = 'le-mauvais-mot-de-passe';

    private const string EMAIL = 'marc@example.test';

    /**
     * Le palier court de `config/packages/rate_limiter.yaml` : cinq échecs par minute sur
     * le couple identifiant + IP. Le refus tombe à la tentative **suivante**.
     */
    private const int MAX_ATTEMPTS = 5;

    /**
     * L'intervalle du même palier, en secondes.
     */
    private const int WINDOW = 60;

    /**
     * Le palier long de `config/packages/rate_limiter.yaml` : vingt échecs par quart
     * d'heure sur l'adresse seule, quels que soient les identifiants essayés.
     */
    private const int MAX_ADDRESS_ATTEMPTS = 20;

    /**
     * L'intervalle du palier long, en secondes — le plafond du ralentissement.
     */
    private const int LONG_WINDOW = 900;

    /**
     * La marge accordée aux comparaisons de délai.
     *
     * Elle couvre du **temps d'horloge réel** : deux allers-retours HTTP et une écriture
     * par réflexion séparent le calcul fait par la page de la lecture faite par le test.
     * Une seconde suffisait sur cette machine et rougissait pour une raison de temps sur
     * un runner froid. Trois restent discriminantes : les valeurs fausses que ces tests
     * cherchent diffèrent de cinq secondes, d'une fenêtre entière ou d'un quart d'heure.
     */
    private const int CLOCK_SLACK = 3;

    private const string ANOTHER_IP = '203.0.113.7';

    protected function setUp(): void
    {
        LoginThrottling::forget();
    }

    /**
     * Sous le seuil, rien n'a changé depuis la story 1.6 : 422, message générique, et
     * aucune mention de délai.
     */
    #[Test]
    public function the_failures_below_the_threshold_are_left_untouched(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::PASSWORD);

        for ($attempt = 1; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $client->submit(self::loginForm($client, self::EMAIL, self::WRONG_PASSWORD));

            self::assertResponseStatusCodeSame(422, \sprintf('L\'échec n°%d doit rester le 422 de la story 1.6.', $attempt));
            self::assertSelectorTextSame(
                '[role="alert"]',
                'Email ou mot de passe incorrect.',
                \sprintf('L\'échec n°%d porte déjà un délai : le ralentissement se déclenche trop tôt.', $attempt),
            );
        }
    }

    /**
     * Le seuil atteint : la tentative de plus est refusée, en 429, avec les secondes
     * restantes derrière le message générique.
     */
    #[Test]
    public function the_attempt_after_the_last_one_is_refused_with_the_remaining_seconds(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::PASSWORD);
        self::spendEveryAttempt($client);

        $crawler = $client->submit(self::loginForm($client, self::EMAIL, self::WRONG_PASSWORD));

        self::assertResponseStatusCodeSame(429, 'Un refus de débit n\'est pas une entrée invalide : il se dit en 429.');
        self::assertSelectorCount(1, '[role="alert"]', 'Le délai s\'ajoute dans le bloc d\'alerte existant, jamais dans une seconde région.');
        self::assertCount(1, $crawler->filter('main [role="alert"]'), 'Le bloc doit rester dans `<main>`, sinon `page-focus` ne le trouve pas.');

        $alert = self::alertText($crawler);

        self::assertStringStartsWith(
            'Email ou mot de passe incorrect.',
            $alert,
            'Le refus reste indifférencié : le délai s\'ajoute au message générique, il ne le remplace pas.',
        );
        self::assertStringNotContainsString(
            'minute',
            $alert,
            'Le message parle en minutes : il a été déduit de l\'exception native, dont le seuil est un `ceil()` de minutes (AD-18).',
        );

        self::assertSelectorNotExists(
            'button[type="submit"][disabled]',
            'Le bouton de connexion est désactivé pendant le ralentissement : la story l\'interdit, et sans JavaScript rien ne viendrait le réactiver.',
        );

        // La page ralentie reste une page de connexion utilisable : c'est ce que
        // `LoginTest` assure du 422, et le chemin 429 n'en hérite pas.
        self::assertSelectorExists('input[name="_csrf_token"]', 'La page ralentie ne livre plus de jeton CSRF : la soumission suivante échouerait pour une autre raison que le délai (AD-18).');
        self::assertSame(
            self::EMAIL,
            $crawler->filter('input[name="_username"]')->attr('value'),
            'L\'adresse saisie est perdue au rendu 429 : il faut la retaper alors que le délai court déjà.',
        );

        self::assertSame(0, LoginThrottling::remainingAttempts(self::getContainer(), self::EMAIL), 'Le limiteur devrait être à sec après cinq échecs.');

        $expected = LoginThrottling::secondsLeft(self::getContainer(), self::EMAIL);

        self::assertGreaterThan(1, $expected, 'La fenêtre d\'une minute vient de s\'ouvrir : il doit rester bien plus d\'une seconde.');
        self::assertLessThanOrEqual(self::WINDOW, $expected, 'Le délai annoncé dépasse la fenêtre du palier court.');

        // Une seconde de tolérance, et une seule : la page a calculé son nombre pendant la
        // requête, le test lit le sien juste après, et la frontière d'une seconde peut
        // tomber entre les deux.
        self::assertEqualsWithDelta(
            $expected,
            $rendered = self::renderedDelay($crawler),
            self::CLOCK_SLACK,
            'Le nombre affiché ne vient pas du limiteur : c\'est la valeur que le critère demande de lire sur lui.',
        );

        // RFC 9110 : un 429 dit quand revenir. La même valeur que la phrase, sans quoi un
        // client automatique réessaierait avant l'écran — ou longtemps après.
        self::assertSame(
            (string) $rendered,
            $client->getResponse()->headers->get('Retry-After'),
            'La réponse 429 ne porte pas d\'en-tête `Retry-After` accordé au délai affiché.',
        );
    }

    /**
     * Le palier long, celui qu'une machine rencontre et qu'un humain ne voit jamais.
     *
     * Une adresse qui essaie **un** mot de passe sur chacun de mille comptes ne déclenche
     * jamais le palier court : aucun identifiant n'atteint cinq échecs. C'est l'attaque en
     * largeur, et c'est le limiteur global — indexé sur l'adresse seule — qui la borne.
     * C'est lui, aussi, qui porte le plafond à un quart d'heure.
     */
    #[Test]
    public function one_address_sweeping_many_identifiers_is_stopped_by_the_long_tier(): void
    {
        $client = self::createClient();

        $refused = null;
        $attempts = 0;

        // Sept identifiants distincts, trois échecs chacun : vingt-et-une tentatives, dont
        // aucune n'approche le seuil local de cinq.
        for ($account = 1; null === $refused && $account <= 7; ++$account) {
            for ($try = 0; $try < 3; ++$try) {
                $crawler = $client->submit(self::loginForm($client, \sprintf('cible-%d@example.test', $account), self::WRONG_PASSWORD));
                ++$attempts;

                if (Response::HTTP_TOO_MANY_REQUESTS === $client->getResponse()->getStatusCode()) {
                    $refused = $crawler;

                    break;
                }
            }
        }

        self::assertNotNull(
            $refused,
            'Vingt-et-un échecs répartis sur sept identifiants n\'ont jamais été refusés : rien ne borne l\'attaque en largeur, et une adresse peut arroser autant de comptes qu\'elle veut.',
        );
        self::assertSame(
            self::MAX_ADDRESS_ATTEMPTS + 1,
            $attempts,
            'Le refus n\'est pas tombé à la tentative qui suit le vingtième échec : ce n\'est pas le palier long qui a parlé.',
        );

        $delay = self::renderedDelay($refused);

        self::assertGreaterThan(
            self::WINDOW,
            $delay,
            'Le délai annoncé tient dans la fenêtre du palier court : c\'est lui qui a refusé, et le plafond à un quart d\'heure n\'est porté par personne.',
        );
        self::assertLessThanOrEqual(
            self::LONG_WINDOW,
            $delay,
            'Le délai annoncé dépasse la fenêtre du palier long : le ralentissement n\'est plus plafonné à un quart d\'heure.',
        );
    }

    /**
     * Le compte à rebours **décroît** d'une soumission à l'autre, et ne repart jamais en
     * arrière : c'est la promesse que porte un délai fini, et elle tient à deux choses.
     *
     * `fixed_window` ancre `getRetryAfter()` sur la fin du seau courant, donc le temps qui
     * passe le rapproche. Et une tentative refusée n'ajoute rien **au limiteur qui l'a
     * refusée** : `FixedWindowLimiter::reserve()` lève sa `MaxWaitDurationExceededException`
     * avant `Window::add()`, donc s'acharner ne repousse pas l'échéance qu'il annonce.
     *
     * **La portée de cette propriété est le palier court, et elle s'arrête là.**
     * `AbstractRequestRateLimiter::doConsume()` sollicite les deux limiteurs, et celui qui
     * n'est pas encore épuisé, lui, compte : s'acharner draine donc le palier long, et le
     * jour où il parle à son tour le délai annoncé saute de quelques secondes à un quart
     * d'heure. C'est l'escalade voulue, et
     * `one_address_sweeping_many_identifiers_is_stopped_by_the_long_tier()` la couvre. Ce
     * test-ci reste sous ce seuil : deux refus, loin des vingt du palier long.
     */
    #[Test]
    public function the_announced_delay_shrinks_from_one_submission_to_the_next(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::PASSWORD);
        self::spendEveryAttempt($client);

        $first = self::renderedDelay($client->submit(self::loginForm($client, self::EMAIL, self::WRONG_PASSWORD)));
        self::assertResponseStatusCodeSame(429);

        // Cinq secondes de plus, sans les attendre.
        LoginThrottling::rewind(self::getContainer(), 5, self::EMAIL);

        $second = self::renderedDelay($client->submit(self::loginForm($client, self::EMAIL, self::WRONG_PASSWORD)));
        self::assertResponseStatusCodeSame(429);

        self::assertEqualsWithDelta(
            $first - 5,
            $second,
            self::CLOCK_SLACK,
            'Le compte à rebours ne décroît pas du temps écoulé : s\'acharner repousse l\'échéance au lieu de la rapprocher.',
        );
    }

    /**
     * Le ralentissement précède la vérification du mot de passe — il est levé sur
     * `CheckPassportEvent` à la priorité 2080. Les bons identifiants ne le contournent
     * donc pas, et aucune session ne s'ouvre.
     */
    #[Test]
    public function the_right_password_is_refused_while_the_delay_runs(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::PASSWORD);
        self::spendEveryAttempt($client);

        $crawler = $client->submit(self::loginForm($client, self::EMAIL, self::PASSWORD));

        self::assertResponseStatusCodeSame(429, 'Le bon mot de passe a été vérifié avant le ralentissement.');
        self::assertStringContainsString('Patientez', self::alertText($crawler), 'Le refus ralenti doit annoncer son délai, même quand le mot de passe était juste.');
        self::assertFalse(self::isSignedIn($client), 'Une session s\'est ouverte pendant le ralentissement.');
    }

    /**
     * Le délai écoulé, la connexion aboutit — sans aucune intervention : rien n'est à
     * débloquer à la main, la fenêtre se périme d'elle-même.
     */
    #[Test]
    public function the_right_password_signs_in_once_the_window_has_rolled_over(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::PASSWORD);
        self::spendEveryAttempt($client);

        LoginThrottling::rewind(self::getContainer(), self::WINDOW + 1, self::EMAIL);

        $client->submit(self::loginForm($client, self::EMAIL, self::PASSWORD));

        self::assertResponseRedirects('/', null, 'La fenêtre est périmée : la connexion doit aboutir sans que personne n\'ait rien débloqué.');
    }

    /**
     * Une seconde restante s'écrit au singulier, et ce singulier vient du pluriel ICU —
     * jamais d'un `if` dans le template.
     */
    #[Test]
    public function a_single_remaining_second_is_written_in_the_singular(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::PASSWORD);
        self::spendEveryAttempt($client);

        LoginThrottling::rewind(self::getContainer(), self::WINDOW - 1, self::EMAIL);

        $crawler = $client->submit(self::loginForm($client, self::EMAIL, self::WRONG_PASSWORD));

        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString(
            'Patientez 1 seconde avant de réessayer.',
            self::alertText($crawler),
            'Le message accorde « seconde » au pluriel alors qu\'il n\'en reste qu\'une.',
        );
    }

    /**
     * Le pluriel ICU tient dans les **trois** langues.
     *
     * `CatalogParityTest` prouve que la clé existe partout et n'est vide nulle part ; il ne
     * lit pas le motif. Une accolade oubliée dans le catalogue néerlandais passerait donc
     * sa garde et ne se verrait qu'au rendu d'une page néerlandaise ralentie — c'est-à-dire
     * chez le premier utilisateur concerné.
     */
    #[Test]
    public function the_delay_sentence_is_pluralised_in_every_language(): void
    {
        self::bootKernel();

        $translator = self::getContainer()->get(TranslatorInterface::class);

        foreach (SupportedLocale::codes() as $code) {
            $one = $translator->trans('login.error.retry_after', ['seconds' => 1], 'security+intl-icu', $code);
            $many = $translator->trans('login.error.retry_after', ['seconds' => 40], 'security+intl-icu', $code);

            self::assertStringContainsString('1', $one, \sprintf('La phrase « %s » n\'insère pas le nombre reçu.', $code));
            self::assertStringContainsString('40', $many, \sprintf('La phrase « %s » n\'insère pas le nombre reçu.', $code));
            self::assertStringNotContainsString('{', $one, \sprintf('Le motif ICU « %s » n\'a pas été formaté au singulier : il est rendu brut.', $code));
            self::assertStringNotContainsString('{', $many, \sprintf('Le motif ICU « %s » n\'a pas été formaté au pluriel : il est rendu brut.', $code));
            self::assertNotSame($one, $many, \sprintf('Le catalogue « %s » rend la même phrase à une seconde qu\'à quarante : son pluriel ne distingue rien.', $code));
        }
    }

    /**
     * Ce qu'un succès **ne fait pas** : `DefaultLoginRateLimiter` est lisible sans
     * consommation, donc `LoginThrottlingListener::onSuccessfulLogin()` ne remet rien à
     * zéro. Les échecs déjà comptés le restent jusqu'à l'expiration de la fenêtre.
     */
    #[Test]
    public function a_success_does_not_give_back_the_attempts_already_spent(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::PASSWORD);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $client->submit(self::loginForm($client, self::EMAIL, self::WRONG_PASSWORD));
        }

        $client->submit(self::loginForm($client, self::EMAIL, self::PASSWORD));
        self::assertResponseRedirects('/');

        self::assertSame(
            self::MAX_ATTEMPTS - 3,
            LoginThrottling::remainingAttempts(self::getContainer(), self::EMAIL),
            'Le succès a rendu les tentatives déjà dépensées : trouver le mot de passe offrirait une nouvelle salve.',
        );

        $client->request('GET', '/logout');

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $client->submit(self::loginForm($client, self::EMAIL, self::WRONG_PASSWORD));
            self::assertResponseStatusCodeSame(422);
        }

        $client->submit(self::loginForm($client, self::EMAIL, self::WRONG_PASSWORD));

        self::assertResponseStatusCodeSame(429, 'Trois échecs, un succès puis deux échecs font cinq : le sixième doit être refusé.');
    }

    /**
     * Le palier court est indexé sur identifiant **et** IP : une seconde adresse n'hérite
     * pas du refus de la première. Seul le limiteur global par IP la borne, et il n'a rien
     * vu venir d'elle.
     */
    #[Test]
    public function another_address_does_not_inherit_the_local_refusal(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::PASSWORD);
        self::spendEveryAttempt($client);

        $client->submit(self::loginForm($client, self::EMAIL, self::WRONG_PASSWORD));
        self::assertResponseStatusCodeSame(429, 'La première adresse devrait être ralentie.');

        $client->submit(self::loginForm($client, self::EMAIL, self::PASSWORD), [], ['REMOTE_ADDR' => self::ANOTHER_IP]);

        self::assertResponseRedirects('/', null, 'Le refus local a suivi l\'identifiant au lieu du couple identifiant + adresse.');
    }

    /**
     * Le ralentissement l'emporte sur le contrôle CSRF : il est levé à la priorité 2080,
     * bien avant le badge CSRF. Un POST sans jeton reçoit donc exactement le même refus,
     * et n'apprend rien de plus.
     */
    #[Test]
    public function a_missing_csrf_token_does_not_escape_the_delay(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::PASSWORD);
        self::spendEveryAttempt($client);

        $client->request('POST', '/login', [
            '_username' => self::EMAIL,
            '_password' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(429, 'Un POST sans jeton CSRF doit tomber sur le ralentissement, pas sur un autre chemin.');
        self::assertStringContainsString('Patientez', self::alertText($client->getCrawler()));
        self::assertFalse(self::isSignedIn($client), 'Un POST sans jeton CSRF ne doit ouvrir aucune session.');
    }

    /**
     * Une rafale de tentatives ne doit pas vider le buffer `fingers_crossed` : c'est ce
     * que l'entrée de `deferred-work.md` de la story 1.10 anticipait, et c'est ce test qui
     * tranche si `429` doit rejoindre `excluded_http_codes`.
     */
    #[Test]
    public function a_throttled_refusal_does_not_flush_the_log_buffer(): void
    {
        // Le client garde le mode debug par défaut, contrairement à `ErrorLoggingTest`.
        // Ce n'est pas un détail : le pool de cache du limiteur est indexé sur le
        // conteneur compilé, donc un client `debug => false` en utilise un **autre** — que
        // le `forget()` du `setUp()` n'aurait pas vidé, et dont les compteurs
        // survivraient d'une exécution de la suite à la suivante. La forme de production
        // du handler est exercée de toute façon : `when@test` déclare le même
        // `fingers_crossed`, quel que soit le mode debug.
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::PASSWORD);
        self::spendEveryAttempt($client);

        $handler = self::testHandler();
        $handler->clear();

        $client->submit(self::loginForm($client, self::EMAIL, self::WRONG_PASSWORD));

        self::assertResponseStatusCodeSame(429);
        self::assertSame(
            [],
            $handler->getRecords(),
            'Un refus ralenti a vidé le buffer de journaux : une rafale de tentatives rendrait le journal illisible, et `429` doit alors rejoindre `excluded_http_codes`.',
        );
    }

    /**
     * Le sel du limiteur n'est jamais vide, **même quand `APP_SECRET` l'est**.
     *
     * `DefaultLoginRateLimiter` refuse de se construire sur un secret vide. Or `.env`
     * laisse `APP_SECRET` vide et n'a aucun chemin de production, là où `.env.test` le
     * remplit : sous test, le processeur `default:` ne se replie donc jamais, et cette
     * branche — la seule que la production emprunte — ne serait exercée par rien. Réduire
     * l'expression à `%env(APP_SECRET)%` garderait la suite verte et ferait répondre 500 à
     * chaque connexion en production.
     *
     * Le test lit donc l'expression **réellement configurée** et la résout avec une
     * variable d'environnement vide, exactement comme le conteneur de production le ferait.
     */
    #[Test]
    public function the_limiter_secret_is_never_empty_when_app_secret_is(): void
    {
        self::bootKernel();

        $parsed = Yaml::parseFile(GateFiles::projectDir().'/config/services.yaml');
        self::assertIsArray($parsed);

        $services = $parsed['services'] ?? null;
        self::assertIsArray($services);

        $definition = $services[DefaultLoginRateLimiter::class] ?? null;
        self::assertIsArray($definition, 'Le limiteur de connexion n\'est plus défini dans `config/services.yaml`.');

        $arguments = $definition['arguments'] ?? null;
        self::assertIsArray($arguments);

        $expression = $arguments['$secret'] ?? null;
        self::assertIsString($expression, 'Le limiteur de connexion ne reçoit plus de `$secret` explicite dans `config/services.yaml`.');
        self::assertSame(1, preg_match('/^%env\((?P<inner>.+)\)%$/', $expression, $matches), \sprintf('Le sel « %s » ne vient plus d\'une variable d\'environnement.', $expression));

        // La production : la variable existe et vaut la chaîne vide.
        $emptyAppSecret = static fn (string $name): string => '';

        $inner = $matches['inner'];

        if (str_contains($inner, ':')) {
            [$prefix, $name] = explode(':', $inner, 2);
            $secret = new EnvVarProcessor(self::getContainer())->getEnv($prefix, $name, $emptyAppSecret);
        } else {
            $secret = $emptyAppSecret($inner);
        }

        self::assertIsString($secret);
        self::assertNotSame(
            '',
            $secret,
            'Le sel du limiteur se résout à la chaîne vide quand `APP_SECRET` est vide : `DefaultLoginRateLimiter` lève « A non-empty secret is required » et la connexion répond 500 en production.',
        );
    }

    // -------------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------------

    /**
     * Les cinq échecs que le palier court autorise. Le refus tombe à la tentative
     * suivante, pas à celle-ci.
     */
    private static function spendEveryAttempt(KernelBrowser $client): void
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $client->submit(self::loginForm($client, self::EMAIL, self::WRONG_PASSWORD));
            self::assertResponseStatusCodeSame(422, \sprintf('La tentative n°%d devrait encore passer le limiteur.', $attempt + 1));
        }
    }

    private static function loginForm(KernelBrowser $client, string $email, string $password): Form
    {
        $form = $client->request('GET', '/login')->filter('form')->form();

        $form['_username'] = $email;
        $form['_password'] = $password;

        return $form;
    }

    private static function alertText(Crawler $crawler): string
    {
        return $crawler->filter('[role="alert"]')->text(null, true);
    }

    /**
     * Le nombre de secondes réellement écrit dans la page.
     */
    private static function renderedDelay(Crawler $crawler): int
    {
        $alert = self::alertText($crawler);

        if (1 !== preg_match('/Patientez (\d+) secondes? avant de réessayer\./u', $alert, $matches)) {
            self::fail(\sprintf('La page ne porte aucun délai en secondes : « %s ».', $alert));
        }

        return (int) $matches[1];
    }

    private static function testHandler(): TestHandler
    {
        $handler = self::getContainer()->get('monolog.handler.testing');

        self::assertInstanceOf(TestHandler::class, $handler, 'Le handler `testing` de `when@test` a disparu : plus rien n\'observe ce que le socle journalise.');

        return $handler;
    }

    /**
     * Aucune page du socle n'est protégée : on demande la page de connexion, qui renvoie
     * sur l'accueil quand une session est ouverte.
     */
    private static function isSignedIn(KernelBrowser $client): bool
    {
        $client->request('GET', '/login');

        return $client->getResponse()->isRedirect();
    }
}
