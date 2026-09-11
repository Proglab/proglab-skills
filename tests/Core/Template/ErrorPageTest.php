<?php

declare(strict_types=1);

namespace App\Tests\Core\Template;

use App\Tests\Core\Quality\GateFiles;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;

/**
 * Les quatre pages d'erreur, prouvées sur le **vrai chemin**.
 *
 * Rien ici ne rend un template directement : c'est le `TwigErrorRenderer` natif qui doit
 * être atteint, sinon le test prouve un fichier Twig et pas une page servie. Ce renderer
 * ne prend la main que hors debug (`TwigErrorRenderer::render()` : `if ($debug || !$template = …)`),
 * et `phpunit.dist.xml` ne pose que `APP_ENV=test` — donc `APP_DEBUG` vaut 1 et un client
 * ordinaire verrait la page de debug du framework. D'où le `['debug' => false]` sur
 * chaque client de ce fichier : c'est lui qui met le test sur le chemin de la production.
 *
 * **Conséquence à connaître.** Un conteneur non-debug n'est jamais vérifié en fraîcheur
 * (`ConfigCache` sans métadonnées) : après une modification de `config/`, `var/cache/test/`
 * garde l'ancien conteneur non-debug jusqu'à `php bin/console cache:clear --env=test`. Un
 * clone frais — la CI — ne connaît pas ce piège, une machine de développement si.
 *
 * `AccessibilityFloorTest` regarde les mêmes quatre templates comme une **règle** de DOM ;
 * ce fichier-ci vérifie leur **contenu** et le chemin qui les sert.
 */
final class ErrorPageTest extends WebTestCase
{
    /**
     * Le seul point d'extension : `TwigErrorRenderer::findTemplate()` essaie
     * `error{status}.html.twig` puis `error.html.twig`, **sans repli par famille**. Sans le
     * générique, un 405 ou un 502 sort par le `HtmlErrorRenderer` nu.
     */
    private const string DIRECTORY = 'templates/bundles/TwigBundle/Exception';

    /**
     * Les trois variables que le renderer passe au template. Elles existent, et aucune
     * n'est rendue — c'est la contrainte « aucun détail technique dans le DOM », prise du
     * côté de la source plutôt que du côté du rendu.
     *
     * @var list<string>
     */
    private const array RENDERER_VARIABLES = ['status_code', 'status_text', 'exception'];

    /**
     * Ce qui ne doit jamais atteindre le texte d'une page d'erreur. Le balayage porte sur
     * le **texte du `<body>`** et non sur le HTML brut : l'empreinte d'un asset généré par
     * `importmap()` peut contenir « 404 » par hasard, et le test deviendrait intermittent
     * pour une raison qui n'a rien à voir avec la page.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_IN_THE_DOM = [
        '403', '404', '405', '500',
        'Forbidden', 'Not Found', 'Method Not Allowed', 'Internal Server Error',
        'Exception', 'Throwable', 'Error 5', 'Oops',
        'App\\', 'Symfony\\', '.php',
    ];

    /**
     * La version française de référence, au mot près depuis *Voice and Tone*.
     *
     * Les titres n'y figurent pas — les pages d'erreur sont « spine-only » dans
     * `EXPERIENCE.md`, et le tableau ne donne que le corps du message. Ils sont donc
     * écrits ici comme la décision qu'ils sont : courts, sans code HTTP ni nom de classe,
     * et c'est ce fichier qui les gèle.
     *
     * @var array<string, array{title: string, message: string}>
     */
    private const array FRENCH = [
        'error403' => [
            'title' => 'Accès non autorisé',
            'message' => 'Vous n\'avez pas accès à cette page. Si vous pensez que c\'est une erreur, adressez-vous à votre administrateur.',
        ],
        'error404' => [
            'title' => 'Page introuvable',
            'message' => 'Cette page n\'existe pas.',
        ],
        'generic' => [
            'title' => 'Une erreur est survenue',
            'message' => 'Quelque chose n\'a pas fonctionné. Réessayez ; si cela persiste, prévenez votre administrateur.',
        ],
    ];

    private const string BACK_HOME = 'Retour à l\'accueil';

    /**
     * Le second interrupteur du « vrai chemin », et celui qu'on ne voit pas venir.
     *
     * Symfony 7.4 choisit son renderer d'erreur sur le **mode d'exécution** avant de le
     * choisir sur le format : `error_handler.error_renderer.default` est construit par
     * `RuntimeModeErrorRendererSelector::select()` avec `%kernel.runtime_mode.web%`, et ce
     * paramètre retombe sur `container.runtime_mode`, que le conteneur compilé calcule en
     * `web=0` dès que `PHP_SAPI` vaut `cli`. Sous PHPUnit, donc, **toujours**. Sans cette
     * ligne, `['debug' => false]` ne suffit pas : le `CliErrorRenderer` prend la main, il
     * dumpe la classe et la trace, et le test prouverait le contraire de ce qu'il croit.
     *
     * Le réglage est posé ici et nulle part ailleurs : ce fichier est le seul à avoir
     * besoin que le conteneur se croie derrière un serveur web.
     */
    private const string WEB_RUNTIME_MODE = 'web=1';

    /**
     * L'état de `APP_RUNTIME_MODE` avant que ce fichier y touche — dans les **deux**
     * tableaux, et « absent » distingué de « vide ». Une variable d'environnement posée par
     * la machine qui lance la suite doit se retrouver telle quelle après.
     *
     * @var array{mixed, mixed}|null
     */
    private ?array $runtimeMode = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runtimeMode = self::captureEnv('APP_RUNTIME_MODE');

        $_ENV['APP_RUNTIME_MODE'] = $_SERVER['APP_RUNTIME_MODE'] = self::WEB_RUNTIME_MODE;
    }

    protected function tearDown(): void
    {
        if (null !== $this->runtimeMode) {
            self::restoreEnv('APP_RUNTIME_MODE', $this->runtimeMode);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_missing_page_renders_the_404_template(): void
    {
        $crawler = self::renderError('GET', '/une-page-qui-n-existe-pas', 404);

        self::assertSame(self::FRENCH['error404']['title'], self::headingOf($crawler));
        self::assertStringContainsString(self::FRENCH['error404']['message'], $crawler->filter('main')->text());
        self::assertSame(self::BACK_HOME, self::backLinkOf($crawler));
    }

    /**
     * La 403 est provoquée par une fixture qui lève, jamais par un accès réellement
     * refusé : le socle n'a pas encore de firewall (story 1.6). Ce qui est prouvé ici est
     * le rendu de la page, pas la décision qui y mène — l'écart est réel et il est écrit
     * dans `DemoErrorController` autant qu'ici.
     */
    #[Test]
    public function a_refused_access_renders_the_403_template(): void
    {
        $crawler = self::renderError('GET', '/demo/errors/forbidden', 403);

        self::assertSame(self::FRENCH['error403']['title'], self::headingOf($crawler));
        self::assertStringContainsString(self::FRENCH['error403']['message'], $crawler->filter('main')->text());
        self::assertSame(self::BACK_HOME, self::backLinkOf($crawler));
    }

    #[Test]
    public function an_unexpected_failure_renders_the_500_template(): void
    {
        $crawler = self::renderError('GET', '/demo/errors/unexpected', 500);

        self::assertSame(self::FRENCH['generic']['title'], self::headingOf($crawler));
        self::assertStringContainsString(self::FRENCH['generic']['message'], $crawler->filter('main')->text());
        self::assertSame(self::BACK_HOME, self::backLinkOf($crawler));
    }

    /**
     * Le repli, et la raison d'être du quatrième fichier : un POST sur une route déclarée
     * `GET` produit un 405, pour lequel aucun `error405.html.twig` n'existe et pour lequel
     * `findTemplate()` n'a **aucun** repli par famille.
     */
    #[Test]
    public function a_status_without_its_own_template_falls_back_to_the_generic_one(): void
    {
        $crawler = self::renderError('POST', '/', 405);

        self::assertSame(self::FRENCH['generic']['title'], self::headingOf($crawler));
        self::assertStringContainsString(self::FRENCH['generic']['message'], $crawler->filter('main')->text());
        self::assertSame(self::BACK_HOME, self::backLinkOf($crawler));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function errorPaths(): iterable
    {
        yield 'error404' => ['GET', '/une-page-qui-n-existe-pas', 404];
        yield 'error403' => ['GET', '/demo/errors/forbidden', 403];
        yield 'error500' => ['GET', '/demo/errors/unexpected', 500];
        yield 'error (générique)' => ['POST', '/', 405];
    }

    #[Test]
    #[DataProvider('errorPaths')]
    public function no_error_page_lets_a_technical_detail_reach_the_dom(string $method, string $path, int $status): void
    {
        $text = self::renderError($method, $path, $status)->filter('body')->text();

        foreach (self::FORBIDDEN_IN_THE_DOM as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $text,
                \sprintf('La page servie pour %s « %s » laisse « %s » atteindre le DOM — une page d\'erreur dit quoi faire ensuite, jamais ce qui a échoué.', $method, $path, $needle),
            );
        }
    }

    /**
     * Le même interdit, pris à la source : les trois variables du renderer existent dans
     * chaque template, et aucune n'y est écrite. Une page peut cesser d'être servie ; un
     * fichier qui ne nomme pas la variable ne peut pas la rendre.
     *
     * Les commentaires Twig sont retirés d'abord. Ce qui est interdit est de **rendre** ces
     * variables, pas de les nommer en expliquant pourquoi on ne les rend pas — et un
     * commentaire qui échouerait ici ferait échouer la porte avec un message qui parle du
     * DOM, sur un texte que le DOM n'a jamais vu.
     */
    #[Test]
    public function no_error_template_renders_a_variable_of_the_renderer(): void
    {
        foreach (self::templates() as $template) {
            $source = self::withoutTwigComments(GateFiles::read($template));

            foreach (self::RENDERER_VARIABLES as $variable) {
                self::assertStringNotContainsString(
                    $variable,
                    $source,
                    \sprintf('« %s » nomme « %s » : les variables du `TwigErrorRenderer` existent, aucune n\'atteint le DOM.', $template, $variable),
                );
            }
        }
    }

    /**
     * Les templates d'erreur n'héritent que du gabarit et ne remplissent que `title` et
     * `body` : ni `header`, ni `nav`, ni `footer`, ni `stylesheets`, ni `javascripts`. Un
     * dérivé remplace ces fichiers en place, au même titre que le logo — rien ici ne doit
     * les rendre difficiles à remplacer.
     */
    #[Test]
    public function every_error_template_is_a_short_page_over_the_base_layout(): void
    {
        foreach (self::templates() as $template) {
            $source = GateFiles::read($template);

            self::assertMatchesRegularExpression(
                '/\{%\s*extends\s+[\'"]base\.html\.twig[\'"]\s*%\}/',
                $source,
                \sprintf('« %s » n\'hérite pas de `base.html.twig` : la page d\'erreur perdrait le lien d\'évitement, les repères et la région d\'annonces.', $template),
            );

            preg_match_all('/\{%\s*block\s+(?P<name>\w+)/', $source, $blocks);

            self::assertSame(
                ['title', 'body'],
                $blocks['name'],
                \sprintf('« %s » ne doit remplir que `title` et `body`.', $template),
            );
        }
    }

    /**
     * En développement, la page de debug reste servie telle quelle : le renderer Twig
     * n'intervient qu'hors debug, et cette story ne change rien à ce que voit un
     * développeur.
     *
     * Deux assertions, et la positive est la plus importante : « ne contient pas le titre
     * français » serait aussi vrai d'un corps vide ou d'une réponse tronquée. Le nom de la
     * classe levée est ce que la page de debug est **faite** pour montrer — et exactement
     * ce que la page de production ne doit jamais laisser passer.
     */
    #[Test]
    public function the_debug_page_is_untouched_in_development(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);
        $client->request('GET', '/une-page-qui-n-existe-pas');

        $content = (string) $client->getResponse()->getContent();

        self::assertResponseStatusCodeSame(404);

        self::assertStringContainsString(
            'NotFoundHttpException',
            $content,
            'La page de debug ne nomme plus l\'exception levée : ce que le développeur vient y chercher a disparu.',
        );

        self::assertStringNotContainsString(
            self::FRENCH['error404']['title'],
            $content,
            'La page de debug a été remplacée par le template d\'erreur : `kernel.debug` ne protège plus le développeur.',
        );
    }

    /**
     * La propriété la plus facile à casser plus tard, et la seule qui compte vraiment le
     * jour où elle sert.
     *
     * La résolution de locale lit les langues **actives** en base à chaque requête
     * principale : une base injoignable lève donc dans `LocaleListener`, avant même le
     * contrôleur. La sous-requête d'erreur, elle, sort sur `!isMainRequest()` — la locale
     * retombe sur `default_locale`, les catalogues sont des fichiers, `app_name` vient
     * d'une variable d'environnement, et rien du chemin de rendu ne rouvre une connexion.
     *
     * Le DSN pointe un port fermé plutôt qu'un hôte inexistant : la connexion est refusée
     * immédiatement, là où une résolution DNS ferait attendre le test.
     *
     * **Et il faut faire taire DAMA le temps du test.** `StaticDriver::connect()` indexe
     * ses connexions sur `dama.connection_key` — le *nom* de la connexion, jamais ses
     * paramètres : réécrire `DATABASE_URL` ne changerait donc rien, le bundle resservirait
     * la connexion déjà ouverte par les tests précédents et la page rendrait 200. Les
     * connexions statiques sont rendues le temps de ce seul test, et remises aussitôt : la
     * transaction que DAMA a ouverte pour ce test n'est pas touchée, seule la création
     * d'une **nouvelle** connexion cesse d'être interceptée — et c'est précisément celle
     * qui doit échouer.
     */
    #[Test]
    public function the_500_renders_with_the_database_unreachable(): void
    {
        $restore = self::captureEnv('DATABASE_URL');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'mysql://root:@127.0.0.1:1/proglab_skills_injoignable?serverVersion=8.4&charset=utf8mb4';
        StaticDriver::setKeepStaticConnections(false);

        try {
            $crawler = self::renderError('GET', '/', 500);

            self::assertSame(self::FRENCH['generic']['title'], self::headingOf($crawler));
            self::assertSame(
                'fr',
                $crawler->filter('html')->attr('lang'),
                'La page d\'erreur n\'est pas retombée sur `default_locale` : quelque chose de son chemin de rendu a encore besoin de la base.',
            );
        } finally {
            StaticDriver::setKeepStaticConnections(true);

            self::restoreEnv('DATABASE_URL', $restore);

            self::ensureKernelShutdown();
        }
    }

    /**
     * La sous-requête d'erreur hérite de la locale de la requête principale, elle ne la
     * recalcule pas : c'est ce qui rend la page d'erreur traduite **et** insensible à une
     * base injoignable, par le même mécanisme.
     *
     * **La 403 et non la 404, et ce n'est pas un détail.** `RouterListener` a la priorité
     * 32, `App\Core\EventListener\LocaleListener` la priorité 20 : sur une adresse
     * inconnue, le routeur lève **avant** que la locale soit résolue, et la page d'erreur
     * sort forcément en `default_locale`. Une 404 ne peut donc pas être traduite par le
     * choix de langue de la requête — la ligne « Erreur après `?lang=nl` » de la matrice
     * suppose une locale déjà résolue, ce qui n'est vrai qu'une fois le routage passé.
     * L'écart est réel ; le fermer demanderait de résoudre la locale avant le routeur, et
     * personne n'en a besoin aujourd'hui.
     */
    #[Test]
    public function an_error_after_a_language_choice_is_rendered_in_that_language(): void
    {
        $crawler = self::renderError('GET', '/demo/errors/forbidden?lang=nl', 403);

        self::assertSame('nl', $crawler->filter('html')->attr('lang'));
        self::assertSame('Geen toegang', self::headingOf($crawler));
        self::assertSame('Terug naar de startpagina', self::backLinkOf($crawler));
    }

    /**
     * Le contrôle doit pouvoir échouer pour la bonne raison.
     *
     * Un conteneur non-debug n'est jamais vérifié en fraîcheur : si `var/cache/test/` en
     * garde un compilé avant que ce répertoire de templates existe, `@Twig` n'a aucun
     * chemin, `findTemplate()` ne trouve rien, et **tous** les tests ci-dessus échouent en
     * disant « pas de `h1` » — un message qui envoie corriger le mauvais fichier. Cette
     * assertion-ci nomme la vraie cause.
     */
    #[Test]
    public function the_renderer_can_actually_find_the_four_templates(): void
    {
        self::createClient(['debug' => false]);

        $loader = self::getContainer()->get('twig')->getLoader();

        foreach (self::templates() as $template) {
            $logical = '@Twig/Exception/'.substr($template, \strlen(self::DIRECTORY) + 1);

            self::assertTrue(
                $loader->exists($logical),
                \sprintf('Twig ne résout pas « %s ». Si le fichier est bien là, c\'est le conteneur non-debug de `var/cache/test/` qui est périmé : `php bin/console cache:clear --env=test`, ou supprimez `var/cache/test/`.', $logical),
            );
        }
    }

    /**
     * Turbo n'a rien à rattraper : une réponse d'erreur est une page HTML complète, pas un
     * fragment. Quand Turbo n'obtient aucune réponse exploitable il replie en navigation
     * pleine page — et cette navigation rend exactement la même page. Ce qui est
     * vérifiable ici sans navigateur : l'en-tête `Accept` de Turbo ne change ni le type de
     * contenu ni la page servie.
     */
    #[Test]
    public function an_error_stays_a_full_page_even_when_turbo_asked_for_a_stream(): void
    {
        $client = self::createClient(['debug' => false]);
        $client->request('GET', '/une-page-qui-n-existe-pas', server: [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        self::assertSame(self::FRENCH['error404']['title'], self::headingOf($client->getCrawler()));
        self::assertCount(1, $client->getCrawler()->filter('html head title'));
    }

    /**
     * Un client hors debug — le seul chemin sur lequel le `TwigErrorRenderer` intervient.
     */
    private static function renderError(string $method, string $path, int $status): Crawler
    {
        $client = self::createClient(['debug' => false]);
        $client->catchExceptions(true);
        $client->request($method, $path);

        self::assertResponseStatusCodeSame($status);

        return $client->getCrawler();
    }

    private static function headingOf(Crawler $crawler): string
    {
        $heading = $crawler->filter('main h1');

        self::assertCount(1, $heading, 'La page d\'erreur ne porte pas exactement un `h1`.');

        return trim($heading->text());
    }

    private static function backLinkOf(Crawler $crawler): string
    {
        $link = $crawler->filter('main a[href="/"]');

        self::assertCount(1, $link, 'La page d\'erreur ne porte pas de lien de retour vers `app_home`.');

        return trim($link->text());
    }

    /**
     * Les templates d'erreur, **balayés** et jamais listés.
     *
     * Une liste en dur laisserait un cinquième fichier posé là hors de ces contrôles, en
     * silence — et c'est exactement ce qu'une page d'erreur ne doit jamais pouvoir faire.
     * `AccessibilityFloorTest::errorPages()` balaie le même répertoire de la même façon.
     *
     * Racine du répertoire seulement, et `*.html.twig` seulement : `findTemplate()` ne
     * résout que `@Twig/Exception/error{statut}.html.twig`, donc un partial rangé dans un
     * sous-répertoire n'est pas une page d'erreur et n'a pas à répondre de ces règles.
     *
     * @return list<string> chemins relatifs à la racine du dépôt, triés
     */
    private static function templates(): array
    {
        $found = new Finder()
            ->files()
            ->in(GateFiles::projectDir().'/'.self::DIRECTORY)
            ->depth('== 0')
            ->name('*.html.twig')
            ->sortByName();

        $templates = [];

        foreach ($found as $file) {
            $templates[] = self::DIRECTORY.'/'.str_replace('\\', '/', $file->getRelativePathname());
        }

        self::assertNotSame([], $templates, \sprintf('« %s » ne contient aucun template : le balayage ne regarde rien.', self::DIRECTORY));

        return $templates;
    }

    /**
     * Nommer une variable pour expliquer qu'on ne la rend pas n'est pas la rendre.
     */
    private static function withoutTwigComments(string $source): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $source);
    }

    /**
     * L'état d'une variable d'environnement dans les deux tableaux que lit le conteneur —
     * `null` disant « absente », ce qu'une chaîne vide ne dirait pas.
     *
     * @return array{mixed, mixed}
     */
    private static function captureEnv(string $name): array
    {
        return [
            \array_key_exists($name, $_ENV) ? $_ENV[$name] : null,
            \array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null,
        ];
    }

    /**
     * @param array{mixed, mixed} $captured
     */
    private static function restoreEnv(string $name, array $captured): void
    {
        [$env, $server] = $captured;

        if (null === $env) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $env;
        }

        if (null === $server) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $server;
        }
    }
}
