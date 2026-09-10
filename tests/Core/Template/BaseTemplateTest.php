<?php

declare(strict_types=1);

namespace App\Tests\Core\Template;

use App\Tests\Core\Quality\GateFiles;
use DOMElement;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le gabarit de base rend le contrat de UX-DR-6 à la lettre.
 *
 * `AccessibilityFloorTest` vérifie les mêmes repères comme une **règle** — sur toute page
 * du socle, avec le message d'échec d'une porte de qualité. Ce test-ci vérifie le
 * **contrat exact** du gabarit : l'ordre du DOM, les attributs au caractère près, le
 * `<title>` composé, et le fait que le `h1` n'appartient pas au gabarit. Les deux
 * existent parce qu'ils échouent pour des raisons différentes : l'un dit « cette page
 * n'est plus accessible », l'autre dit « le gabarit ne fait plus ce que le spine décrit ».
 */
final class BaseTemplateTest extends WebTestCase
{
    private const string TEMPLATE = 'templates/base.html.twig';

    #[Test]
    public function the_skip_link_is_the_first_focusable_element_and_targets_the_main_landmark(): void
    {
        $crawler = self::render();

        $first = $crawler->filter('body a, body button, body input, body select, body textarea, body [tabindex]')->first();

        self::assertSame(
            'a',
            $first->nodeName(),
            'Le premier élément focalisable du document n\'est pas un lien : « Aller au contenu » n\'est plus en tête de `templates/base.html.twig`.',
        );

        self::assertSame(
            '#contenu',
            $first->attr('href'),
            'Le premier élément focalisable ne pointe pas vers « #contenu » — le contrat de UX-DR-6 nomme cette ancre à la lettre.',
        );

        self::assertStringContainsString(
            'Aller au contenu',
            trim($first->text()),
            'Le lien d\'évitement a perdu son intitulé « Aller au contenu ».',
        );
    }

    /**
     * `sr-only` hors focus, visible au focus : un lien d'évitement toujours visible
     * encombre chaque page, un lien d'évitement jamais visible est un piège pour qui
     * navigue au clavier sans lecteur d'écran.
     */
    #[Test]
    public function the_skip_link_is_hidden_until_it_takes_focus(): void
    {
        $classes = self::render()->filter('a[href="#contenu"]')->attr('class') ?? '';

        self::assertStringContainsString('sr-only', $classes, \sprintf('Le lien d\'évitement de « %s » n\'est plus `sr-only` : il est visible en permanence.', self::TEMPLATE));
        self::assertStringContainsString('focus:not-sr-only', $classes, \sprintf('Le lien d\'évitement de « %s » ne redevient pas visible au focus : il ne sert plus qu\'aux lecteurs d\'écran.', self::TEMPLATE));
    }

    #[Test]
    public function the_main_landmark_carries_the_anchor_the_focus_and_the_controller(): void
    {
        $crawler = self::render();
        $main = $crawler->filter('main');

        self::assertCount(1, $main, \sprintf('« %s » doit rendre exactement un `<main>`.', self::TEMPLATE));
        self::assertSame('contenu', $main->attr('id'), 'Le `<main>` a perdu son `id="contenu"`, la cible du lien d\'évitement.');
        self::assertSame('-1', $main->attr('tabindex'), 'Le `<main>` a perdu son `tabindex="-1"` : le lien d\'évitement y déplace le défilement, jamais le focus.');
        self::assertSame('page-focus', $main->attr('data-controller'), 'Le `<main>` ne porte plus `data-controller="page-focus"` : plus rien ne place le focus après une navigation Turbo.');
    }

    /**
     * L'ordre du DOM, pas seulement la présence : un lien d'évitement rendu après le
     * `<main>` passe toutes les assertions de présence et ne sert à rien.
     */
    #[Test]
    public function the_document_order_is_skip_link_then_header_then_main(): void
    {
        $html = self::render()->html();

        $skip = strpos($html, 'href="#contenu"');
        $header = strpos($html, '<header');
        $main = strpos($html, '<main');

        self::assertIsInt($skip);
        self::assertIsInt($header, \sprintf('« %s » ne rend plus de repère `<header>`.', self::TEMPLATE));
        self::assertIsInt($main);

        self::assertLessThan($header, $skip, 'Le lien d\'évitement est rendu après le `<header>` : ce n\'est plus le premier élément focalisable.');
        self::assertLessThan($main, $header, 'Le `<header>` est rendu après le `<main>` : l\'ordre des repères ne suit plus l\'ordre de lecture.');
    }

    /**
     * La région d'annonces, au caractère près. Chacun de ces quatre attributs porte une
     * conséquence distincte : sans `id`, `announce` ne la trouve pas ; sans `aria-live`,
     * rien n'est annoncé ; sans `sr-only`, le texte recopié s'affiche deux fois à
     * l'écran ; sans `data-turbo-permanent`, Turbo la recrée à chaque navigation et un
     * lecteur d'écran ne perçoit pas la mutation d'une région qui vient d'apparaître.
     */
    #[Test]
    public function the_live_region_is_rendered_exactly_as_the_spine_describes_it(): void
    {
        $region = self::render()->filter('#annonces');

        self::assertCount(1, $region, \sprintf('« %s » ne rend plus la région live `#annonces`.', self::TEMPLATE));
        self::assertSame('polite', $region->attr('aria-live'), '`#annonces` n\'est plus une région live `polite` — `announce` la passe en `assertive` au cas par cas, elle ne naît jamais ainsi.');
        self::assertStringContainsString('sr-only', $region->attr('class') ?? '', '`#annonces` n\'est plus `sr-only` : le texte recopié s\'afficherait une seconde fois à l\'écran.');
        $node = $region->getNode(0);

        self::assertTrue($node instanceof DOMElement && $node->hasAttribute('data-turbo-permanent'), '`#annonces` a perdu `data-turbo-permanent` : Turbo la recrée à chaque navigation et l\'annonce se perd.');
        self::assertSame('', trim($region->text()), '`#annonces` doit naître vide : un texte présent au rendu n\'est pas une mutation, et n\'est donc pas annoncé.');
    }

    #[Test]
    public function the_title_is_the_page_name_then_the_derivative_name(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        /** @var string $appName */
        $appName = self::getContainer()->getParameter('app.name');

        self::assertSame(
            'Accueil — '.$appName,
            $client->getCrawler()->filter('title')->text(),
            'Le `<title>` ne suit plus « <page> — <nom du dérivé> » (WCAG 2.4.2). Le nom du dérivé vient de `APP_NAME`, jamais d\'un réglage en base.',
        );
    }

    /**
     * Une page qui n'écrit pas son bloc `title` n'obtient pas « — Socle ERP ».
     *
     * Le cas n'est pas théorique : les pages d'erreur de la story 1.10 héritent du gabarit
     * et ne posent pas toutes leur titre. Sans garde, elles s'annonceraient par un
     * séparateur suivi du nom du dérivé — un titre qui ne nomme pas la page, ce que
     * WCAG 2.4.2 demande précisément d'éviter.
     */
    #[Test]
    public function a_page_that_writes_no_title_block_falls_back_to_the_derivative_name_alone(): void
    {
        /** @var string $appName */
        $appName = self::getContainer()->getParameter('app.name');

        self::assertSame(
            $appName,
            self::titleOf(self::renderBareTemplate()),
            'Le `<title>` d\'une page sans bloc `title` doit être le seul nom du dérivé : le séparateur n\'a rien à séparer.',
        );
    }

    /**
     * `lang` est une étiquette BCP 47, pas une locale Symfony.
     *
     * `fr_BE` recopié tel quel est ignoré par les lecteurs d'écran, qui retombent sur la
     * langue du système. Inatteignable avant la story 1.5, qui pose les catalogues et les
     * premières locales régionales — d'où ce test, qui rend le gabarit sur une locale
     * régionale plutôt que d'attendre qu'un dérivé la découvre.
     */
    #[Test]
    public function the_language_attribute_is_a_bcp_47_tag_not_a_symfony_locale(): void
    {
        self::assertStringContainsString(
            '<html lang="fr-BE">',
            self::renderBareTemplate('fr_BE'),
            'Le gabarit recopie la locale Symfony telle quelle : `fr_BE` n\'est pas une étiquette de langue HTML (WCAG 3.1.1).',
        );
    }

    /**
     * Le nom du dérivé est une variable d'environnement, pas un réglage : un dérivé qui
     * change `APP_NAME` ne touche aucun fichier de `src/` ni de `config/`.
     */
    #[Test]
    public function the_derivative_name_comes_from_the_environment(): void
    {
        self::bootKernel();

        self::assertSame(
            '%env(APP_NAME)%',
            self::configuredAppName(),
            'Le paramètre `app.name` n\'est plus alimenté par `%env(APP_NAME)%` : un dérivé devrait éditer `config/` pour se renommer.',
        );

        self::assertStringContainsString(
            'app_name',
            GateFiles::read('config/packages/twig.yaml'),
            'Le global Twig `app_name` a disparu de `config/packages/twig.yaml` : le gabarit n\'a plus de nom de dérivé à composer.',
        );
    }

    /**
     * Le `h1` appartient au template de page, jamais au gabarit (règle 6). Un `h1` dans
     * le gabarit donne deux `h1` sur chaque page qui pose le sien, et un titre faux sur
     * chaque page qui l'oublie.
     */
    #[Test]
    public function the_base_template_carries_no_heading_of_its_own(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/<h[1-6][\s>]/i',
            GateFiles::read(self::TEMPLATE),
            \sprintf('« %s » porte un titre. Le `h1` appartient au template de page ; le gabarit n\'expose que le bloc.', self::TEMPLATE),
        );
    }

    /**
     * Repères conditionnels. Un `<nav>` vide est un repère annoncé « navigation » qui ne
     * contient rien, et un `<footer>` vide occupe une place dans la liste des repères
     * d'un lecteur d'écran. Le gabarit expose les deux blocs et ne rend l'élément que
     * s'il a du contenu — la Sidebar de la story 2.3 remplira le `nav`.
     */
    #[Test]
    public function the_nav_and_footer_landmarks_are_exposed_as_blocks_and_rendered_only_when_filled(): void
    {
        $template = GateFiles::read(self::TEMPLATE);

        foreach (['nav', 'footer'] as $block) {
            self::assertMatchesRegularExpression(
                '/\{%\s*block\s+'.$block.'\s/',
                $template,
                \sprintf('« %s » n\'expose plus de bloc `%s` : une page ne peut plus remplir ce repère.', self::TEMPLATE, $block),
            );
        }

        $crawler = self::render();

        self::assertCount(0, $crawler->filter('nav'), 'Le socle ne pose aucune navigation avant la story 2.3 : un `<nav>` vide n\'est pas un repère.');
        self::assertCount(0, $crawler->filter('footer'), 'Le socle ne pose aucun pied de page : un `<footer>` vide n\'est pas un repère.');
    }

    /**
     * La transition du lien d'évitement est la seule que le socle écrive à la main. Elle
     * lit `--motion-fade`, que `theme.css` ramène à 0 sous `prefers-reduced-motion` : le
     * jeton n'a donc pas besoin d'un namespace Tailwind pour tenir cette promesse.
     */
    #[Test]
    public function the_hand_written_transition_reads_the_motion_token(): void
    {
        self::assertStringContainsString(
            'duration-[var(--motion-fade)]',
            GateFiles::read(self::TEMPLATE),
            \sprintf('La transition de « %s » n\'est plus exprimée en `--motion-fade` : `prefers-reduced-motion` ne la ramène plus à zéro.', self::TEMPLATE),
        );
    }

    /**
     * Le gabarit rendu **nu** : une page qui hérite de lui sans redéfinir un seul bloc.
     *
     * Le socle n'a qu'un écran, et il écrit son titre ; passer par une route ne pourrait
     * donc jamais exercer le cas de la page qui l'oublie. Ce rendu-là l'exerce, et il
     * l'exerce sur le vrai fichier — pas sur une copie qui dériverait.
     */
    private static function renderBareTemplate(string $locale = 'fr'): string
    {
        self::bootKernel();

        $container = self::getContainer();

        $request = Request::create('/');
        $request->setLocale($locale);

        $container->get('request_stack')->push($request);

        return $container->get('twig')->createTemplate('{% extends "base.html.twig" %}')->render();
    }

    private static function titleOf(string $html): string
    {
        preg_match('#<title>(?P<title>.*?)</title>#s', $html, $matches);

        return trim($matches['title'] ?? '');
    }

    private static function render(): Crawler
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        return $client->getCrawler();
    }

    /**
     * La valeur **déclarée** du paramètre, pas sa valeur résolue : c'est la déclaration
     * qui dit d'où vient le nom du dérivé.
     */
    private static function configuredAppName(): string
    {
        preg_match('/^\s*app\.name:\s*(?P<value>\S+)\s*$/m', GateFiles::read('config/services.yaml'), $matches);

        return trim($matches['value'] ?? '', "'\" ");
    }
}
