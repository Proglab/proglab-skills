<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use App\Core\Enum\SupportedLocale;
use App\Core\Repository\LanguageRepository;
use App\Core\Service\ActiveLocales;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;

/**
 * La matrice d'edge cases de la story 1.6, ligne par ligne.
 *
 * Deux d'entre elles ne se vérifient pas par une assertion sur un fragment : « email
 * inconnu » doit rendre **la même réponse** qu'un mot de passe faux, et « compte
 * désactivé » la même quelle que soit la justesse du mot de passe. Les deux sont donc
 * testées par comparaison de la page entière, à la normalisation près de ce qui change
 * légitimement d'une soumission à l'autre — le jeton CSRF, et l'adresse ressaisie.
 * Comparer deux fragments laisserait passer exactement la fuite qu'on cherche à fermer :
 * une classe, un attribut ou un ordre de nœuds qui diffère.
 */
final class LoginTest extends WebTestCase
{
    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    /**
     * Le ralentissement de la story 1.7 pose un état **hors base** : le pool
     * `cache.rate_limiter` est un adaptateur de fichiers, que DAMA n'annule pas et qui
     * survit à l'exécution entière. Cette classe multiplie les échecs de connexion, donc
     * sans cette remise à zéro elle s'auto-ralentit dès qu'on la relance dans le quart
     * d'heure qui suit. Le noyau est démarré puis refermé parce que `createClient()`
     * refuse de s'exécuter derrière un noyau déjà démarré.
     */
    protected function setUp(): void
    {
        LoginThrottling::forget();
    }

    #[Test]
    public function the_login_page_renders_for_an_anonymous_visitor(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_login');
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorNotExists('[role="alert"]', 'Une page de connexion ouverte sans erreur ne porte aucun bloc d\'alerte.');
        self::assertSelectorExists('input[name="_username"][autocomplete="email"]', 'Le champ email ne porte pas son jeton `autocomplete` (WCAG 1.3.5).');
        self::assertSelectorExists('input[name="_password"][autocomplete="current-password"]', 'Le champ mot de passe ne porte pas son jeton `autocomplete` (WCAG 1.3.5).');
        self::assertSelectorExists('input[name="_csrf_token"]', 'Le formulaire de connexion ne porte aucun jeton CSRF (AD-18).');
        self::assertSelectorExists('input[name="_username"][autofocus]', 'Sans erreur, le focus au chargement appartient au premier champ.');

        self::assertCount(2, $crawler->filter('form label'), 'Chaque champ de la connexion porte un label réel — jamais un placeholder seul (règle 3).');
    }

    /**
     * Le sélecteur de langue est une navigation de **liens**, jamais un `<select>` qui
     * soumettrait au changement : au clavier, chaque flèche rechargerait la page
     * (WCAG 3.2.2). La maquette dit l'inverse et c'est le spine qui l'emporte.
     */
    #[Test]
    public function the_language_selector_is_a_navigation_of_links(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/login');

        $links = $crawler->filter('nav[aria-label] a[hreflang]');

        self::assertCount(3, $links, 'Le sélecteur de langue doit offrir un lien par langue active.');
        self::assertSelectorNotExists('form select', 'La connexion ne porte aucun `<select>` : le choix de langue est fait de liens.');

        foreach (SupportedLocale::codes() as $code) {
            self::assertCount(
                1,
                $crawler->filter(\sprintf('nav a[hreflang="%s"][lang="%s"]', $code, $code)),
                \sprintf('Le lien vers « %s » ne déclare pas sa propre langue.', $code),
            );
        }

        self::assertCount(
            1,
            $crawler->filter('nav a[aria-current="true"]'),
            'Exactement une langue doit être marquée comme courante.',
        );
    }

    /**
     * Zéro langue active est un état que la matrice de la story 1.5 liste. Le `<nav>` ne
     * doit alors pas être rendu du tout : un repère « navigation » vide est une entrée qui
     * ne mène nulle part dans la liste que parcourt un lecteur d'écran.
     */
    #[Test]
    public function the_language_selector_disappears_when_no_language_is_active(): void
    {
        $client = self::createClient();
        self::disableEveryLanguage();

        $client->request('GET', '/login');

        self::assertResponseIsSuccessful('La connexion doit rester servie quand plus aucune langue n\'est active.');
        self::assertSelectorNotExists('nav', 'Un `<nav>` vide est rendu alors qu\'aucune langue n\'est offerte.');
    }

    #[Test]
    public function an_active_account_signs_in_and_lands_on_the_home_page(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        $client->submit(self::loginForm($client, 'marc@example.test', self::PASSWORD));

        self::assertResponseRedirects('/', null, 'Une connexion réussie renvoie sur l\'accueil.');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_home');
    }

    #[Test]
    public function a_wrong_password_redisplays_the_form_in_422_with_a_single_generic_alert(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        $crawler = $client->submit(self::loginForm($client, 'marc@example.test', 'le-mauvais-mot-de-passe'));

        self::assertResponseStatusCodeSame(422, 'Un échec de connexion réaffiche le formulaire en 422, il ne redirige pas.');
        self::assertSelectorCount(1, '[role="alert"]');
        self::assertSame('-1', $crawler->filter('[role="alert"]')->attr('tabindex'), 'Le bloc d\'erreur doit être focalisable par programme pour recevoir le focus au rendu.');
        // `Same` et non `Contains` : c'est ce qui prouve que le premier échec ne porte
        // **rien de plus** que le message générique — pas le délai que la story 1.7 ajoute
        // à partir du sixième.
        self::assertSelectorTextSame('[role="alert"]', 'Email ou mot de passe incorrect.');
        self::assertSelectorNotExists('[aria-invalid="true"]', 'L\'erreur de connexion n\'appartient à aucun champ : aucun champ n\'est marqué en erreur.');
        self::assertSame('marc@example.test', $crawler->filter('input[name="_username"]')->attr('value'), 'L\'adresse saisie doit être conservée.');
        self::assertNull(
            $crawler->filter('input[name="_password"]')->attr('value'),
            'Le mot de passe est réémis dans le HTML du 422 : une symétrie appliquée par erreur au champ email le renverrait en clair dans la page, l\'historique du navigateur et les caches intermédiaires.',
        );
        self::assertCount(1, $crawler->filter('main [role="alert"]'), 'Le bloc d\'erreur doit être rendu dans `<main>`, sinon le contrôleur `page-focus` ne le trouve pas.');
        self::assertSelectorNotExists(
            '[autofocus]',
            'Un `autofocus` au rendu 422 pose le focus après le bloc d\'erreur : sans JavaScript — la ligne de base d\'AD-17 — le message ne serait jamais annoncé.',
        );
    }

    /**
     * La matrice le dit ainsi : réponse strictement identique — même statut, même
     * message, même DOM.
     */
    #[Test]
    public function an_unknown_email_answers_exactly_like_a_wrong_password(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        $client->submit(self::loginForm($client, 'marc@example.test', 'le-mauvais-mot-de-passe'));
        $known = self::normalise($client, 'marc@example.test');
        self::assertResponseStatusCodeSame(422);

        $client->submit(self::loginForm($client, 'fantome@example.test', 'le-mauvais-mot-de-passe'));
        $unknown = self::normalise($client, 'fantome@example.test');

        self::assertResponseStatusCodeSame(422);
        self::assertSame($known, $unknown, 'Un email inconnu et un mot de passe faux doivent produire la même page : sinon la comparaison des deux réponses révèle quels comptes existent.');
    }

    #[Test]
    public function a_disabled_account_is_refused_with_its_own_message(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), 'karim@example.test', self::PASSWORD, false);

        $client->submit(self::loginForm($client, 'karim@example.test', self::PASSWORD));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorCount(1, '[role="alert"]');
        self::assertSelectorTextContains('[role="alert"]', 'Ce compte est désactivé. Adressez-vous à votre administrateur.');
        self::assertFalse(self::isSignedIn($client), 'Un compte désactivé ne doit ouvrir aucune session.');
    }

    /**
     * Le contrôle est un `checkPreAuth`, donc antérieur à la vérification du mot de
     * passe : rien dans la réponse ne dit si le mot de passe était bon.
     */
    #[Test]
    public function a_disabled_account_answers_the_same_whatever_the_password(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), 'karim@example.test', self::PASSWORD, false);

        $client->submit(self::loginForm($client, 'karim@example.test', self::PASSWORD));
        $right = self::normalise($client, 'karim@example.test');

        $client->submit(self::loginForm($client, 'karim@example.test', 'le-mauvais-mot-de-passe'));
        $wrong = self::normalise($client, 'karim@example.test');

        self::assertSame($right, $wrong, 'Le refus d\'un compte désactivé doit être identique que le mot de passe soit bon ou faux.');
    }

    #[Test]
    public function a_stale_csrf_token_is_refused_like_any_other_failure(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        $client->request('GET', '/login');
        $client->request('POST', '/login', [
            '_username' => 'marc@example.test',
            '_password' => self::PASSWORD,
            '_csrf_token' => 'un-jeton-perime',
        ]);

        self::assertResponseStatusCodeSame(422, 'Un jeton CSRF invalide est un échec d\'authentification, pas une erreur technique.');
        self::assertSelectorTextContains('[role="alert"]', 'Email ou mot de passe incorrect.');
        self::assertFalse(self::isSignedIn($client), 'Un jeton CSRF invalide ne doit ouvrir aucune session.');
    }

    /**
     * Le jeton **absent**, et non périmé : c'est le chemin qu'emprunte un POST scripté
     * depuis un autre site, qui n'a aucun moyen d'en fabriquer un. Un jeton vide et un
     * jeton faux ne suivent pas le même chemin dans `FormLoginAuthenticator` — le premier
     * n'arrive même pas au vérificateur.
     */
    #[Test]
    public function a_missing_csrf_token_is_refused_like_any_other_failure(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        $client->request('GET', '/login');
        $client->request('POST', '/login', [
            '_username' => 'marc@example.test',
            '_password' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(422, 'Un POST sans jeton CSRF doit être refusé comme n\'importe quel échec de connexion.');
        self::assertSelectorTextContains('[role="alert"]', 'Email ou mot de passe incorrect.');
        self::assertFalse(self::isSignedIn($client), 'Un POST sans jeton CSRF ne doit ouvrir aucune session.');
    }

    /**
     * L'erreur laissée en session par le handler d'échec natif est consommée par
     * `LoginFailureListener`. Sans ce nettoyage, la page de connexion rouverte plus tard
     * porterait une alerte pour un échec déjà montré — une erreur fantôme, sur une page
     * que personne n'a soumise.
     */
    #[Test]
    public function a_failed_attempt_does_not_haunt_the_next_login_page(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        $client->submit(self::loginForm($client, 'marc@example.test', 'le-mauvais-mot-de-passe'));
        self::assertResponseStatusCodeSame(422);

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[role="alert"]', 'Une page de connexion ouverte à neuf porte l\'alerte d\'un échec précédent.');
        self::assertSame(
            '',
            $crawler->filter('input[name="_username"]')->attr('value'),
            'L\'adresse de la tentative précédente est repréremplie : sur un poste partagé, elle est montrée à la personne suivante.',
        );
    }

    #[Test]
    public function an_authenticated_visitor_is_sent_home_instead_of_the_login_page(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        $client->submit(self::loginForm($client, 'marc@example.test', self::PASSWORD));
        self::assertResponseRedirects('/');

        $client->request('GET', '/login');

        self::assertResponseRedirects('/', null, 'Une session ouverte n\'a rien à faire sur la page de connexion.');
    }

    /**
     * `/logout` tout nu : aucun paramètre, et aucune page rendue au préalable qui aurait
     * pu en fabriquer un. C'est l'exception nommée d'AD-18 — la déconnexion doit aboutir
     * depuis un favori, un lien recopié, une page servie par un cache ou une session
     * expirée, soit précisément les états où aucun jeton n'est disponible.
     */
    #[Test]
    public function logging_out_closes_the_session(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        $client->submit(self::loginForm($client, 'marc@example.test', self::PASSWORD));
        self::assertTrue(self::isSignedIn($client), 'La connexion n\'a pas ouvert de session.');

        $client->request('GET', '/logout');

        self::assertResponseRedirects('/');
        self::assertFalse(self::isSignedIn($client), 'La déconnexion n\'a pas refermé la session.');
    }

    /**
     * Le porteur de la garantie, depuis que le jeton est parti.
     *
     * L'exception d'AD-18 tient tout entière sur `SameSite=Lax` : c'est lui, et lui seul,
     * qui empêche un `<img src="…/logout">` posé sur un site tiers d'emporter le cookie de
     * session. Sans ce test, un dérivé qui passe la clé à `none` rouvre le vecteur que la
     * déconnexion sans jeton laisse ouvert, et rien ne rougit.
     */
    #[Test]
    public function the_session_cookie_keeps_the_samesite_policy_the_logout_exception_rests_on(): void
    {
        self::createClient();

        $options = self::getContainer()->getParameter('session.storage.options');

        self::assertSame(
            'lax',
            $options['cookie_samesite'] ?? null,
            'Le cookie de session ne porte plus `SameSite=Lax` : la déconnexion est exemptée de CSRF (AD-18, exception nommée dans `config/packages/security.yaml`) parce qu\'une requête de sous-ressource n\'emporte pas ce cookie. Sans cette politique, `/logout` redevient déclenchable depuis un site tiers.',
        );
    }

    /**
     * L'autre moitié du renversement : l'URL que l'application fabrique elle-même ne
     * transporte plus rien à fuir. Le jeton de déconnexion voyageait en query string d'un
     * GET, donc dans les journaux d'accès et les en-têtes `Referer` — une protection qui
     * fuit là où elle s'applique (AD-18, raison (b) de l'exception nommée).
     *
     * C'est le générateur de la sécurité qui répond, jamais un chemin recopié : c'est lui
     * que `logout_path()` appelle côté Twig. Le pare-feu y est **nommé** plutôt que déduit
     * du token courant, qui n'existe pas hors requête.
     */
    #[Test]
    public function the_logout_link_the_application_renders_carries_no_token(): void
    {
        self::createClient();

        $container = self::getContainer();
        $path = $container->get('security.logout_url_generator')->getLogoutPath('main');

        self::assertNull(
            parse_url($path, \PHP_URL_QUERY),
            'Le lien de déconnexion porte encore une query string : le jeton y fuirait dans les journaux d\'accès et les en-têtes `Referer`.',
        );
        self::assertSame(
            $container->get('router')->generate('app_logout'),
            $path,
            'Le lien rendu n\'est pas l\'URL nue de la route : la sécurité y ajoute quelque chose que le routeur ne met pas.',
        );
    }

    // -------------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------------

    /**
     * Le formulaire réellement rendu, jeton CSRF compris — jamais un POST assemblé à la
     * main : c'est la seule façon de prouver que la page livre un jeton utilisable.
     */
    private static function loginForm(KernelBrowser $client, string $email, string $password): Form
    {
        $form = $client->request('GET', '/login')->filter('form')->form();

        $form['_username'] = $email;
        $form['_password'] = $password;

        return $form;
    }

    /**
     * La page rendue, débarrassée de ce qui change légitimement d'une soumission à
     * l'autre : le jeton CSRF, et l'adresse ressaisie dans le champ.
     */
    private static function normalise(KernelBrowser $client, string $email): string
    {
        $body = (string) $client->getResponse()->getContent();

        $body = (string) preg_replace('/(name="_csrf_token" value=")[^"]*/', '$1JETON', $body);

        return str_replace($email, 'ADRESSE', $body);
    }

    /**
     * Toutes les langues désactivées d'un coup — le repli de la story 1.5, vu depuis la
     * page de connexion.
     */
    private static function disableEveryLanguage(): void
    {
        $container = self::getContainer();

        $languages = $container->get(LanguageRepository::class);
        $entityManager = $container->get(EntityManagerInterface::class);

        foreach ($languages->findAll() as $language) {
            $language->setEnabled(false);
        }

        $entityManager->flush();

        // Le service mémorise sa liste pour la durée de la requête ; le test vient de la
        // changer sous lui.
        $container->get(ActiveLocales::class)->reset();
    }

    /**
     * Aucune page du socle n'est encore protégée : la seule façon de demander à
     * l'application « suis-je connecté ? » est de lui demander la page de connexion, qui
     * renvoie sur l'accueil quand une session est ouverte.
     */
    private static function isSignedIn(KernelBrowser $client): bool
    {
        $client->request('GET', '/login');

        return $client->getResponse()->isRedirect();
    }
}
