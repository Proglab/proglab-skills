<?php

declare(strict_types=1);

namespace App\Tests\Core\Template;

use App\Tests\Core\Quality\GateFiles;
use App\Tests\Core\Theme\ThemeSheet;
use DOMDocument;
use DOMElement;
use LibXMLError;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;
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
 *
 * Ce test tient aussi la **surface de marque** (UX-DR-2), au même titre que le `<title>` :
 * les deux `<link rel="icon">` du gabarit et le `h1` de l'accueil. Le nom du dérivé et son
 * icône se remplacent sans éditer un fichier de `src/Core/` ni de `config/` (NFR-6), et
 * rien d'autre que ces tests ne le vérifie.
 */
final class BaseTemplateTest extends WebTestCase
{
    private const string TEMPLATE = 'templates/base.html.twig';

    private const string ASSETS = 'assets/';

    /**
     * Le répertoire de marque : les deux fichiers qu'un dérivé écrase pour changer
     * d'icône, et rien de plus (UX-DR-2, NFR-6).
     */
    private const string BRAND = self::ASSETS.'brand';

    private const string ICON = self::BRAND.'/favicon.svg';

    private const string FALLBACK = self::BRAND.'/favicon.png';

    /**
     * Les `--primary` du thème, convertis en sRGB — la seule forme qu'un fichier d'image
     * sait porter. Recopiées, comme `ThemeTokensTest` recopie ce qu'aucun fichier du
     * dépôt ne définit deux fois ; le test qui les lit exige que toute valeur inconnue
     * soit convertie et inscrite ici plutôt que devinée.
     *
     * @var array<string, string>
     */
    private const array PRIMARY_IN_SRGB = [
        'oklch(0.205 0 0)' => '#171717',
        'oklch(0.922 0 0)' => '#e5e5e5',
    ];

    /**
     * Le dossier des artefacts BMAD — et la **borne** des tests qui décrivent l'icône par
     * défaut du socle.
     *
     * Ces tests-là décrivent un carré : une feuille `<style>` interne, un bloc `@media`
     * sombre, une règle `fill` de chaque côté, une forme carrée, les couleurs du thème.
     * Aucune de ces propriétés n'est due par le logo d'un dérivé — le contrat de
     * rebranding l'invite précisément à poser le sien, avec ses couleurs en attributs et
     * sans feuille interne s'il le veut. Sans borne, la porte d'un dérivé parfaitement
     * conforme rougirait.
     *
     * **La borne est un signal du dépôt, jamais l'artefact sous test.** Un condensat du
     * fichier d'icône se désarmerait en corrigeant une virgule dans son commentaire, et
     * confondrait « ce dérivé a posé sa marque » avec « quelqu'un a retouché le socle ».
     * `_bmad-output/` sépare les deux sans ambiguïté : le socle le livre, un dérivé qui
     * ne publie pas ses artefacts BMAD ne l'a pas. C'est le patron de
     * `DerivationGuideTest`, qui saute ses statuts de story pour la même raison.
     *
     * Ce qui ne se borne **jamais** : les propriétés que le socle garantit à toute icône,
     * celle d'un dérivé comprise — XML bien formé, en-tête PNG réel, dimension minimale,
     * deux `<link>` qui résolvent vers des fichiers réels. Elles doivent rougir chez le
     * dérivé aussi ; c'est leur raison d'être.
     */
    private const string BMAD_OUTPUT = '_bmad-output';

    /**
     * Le repli matriciel du socle : son condensat, et la valeur de `--primary` pour
     * laquelle il a été peint.
     *
     * Le `<style>` du SVG se lit ; les pixels d'un PNG, non — la porte tourne sur un PHP
     * sans extension d'image. **Ce couple prouve donc une seule chose : le repli n'a pas
     * bougé depuis un épinglage fait à la main, pour une valeur de `--primary` écrite à la
     * main.** Il ne prouve pas la couleur des pixels, et il ne rend pas l'oubli impossible
     * — recopier la nouvelle valeur dans la constante est un chemin vert plus court que
     * régénérer le fichier. Ce qu'il fait, et qui valait la peine : rendre l'oubli
     * **visible**, au moment exact où il se produirait.
     *
     * Le condensat porte sur les octets bruts. `.gitattributes` marque `*.png` binaire :
     * git ne les réécrit sur aucun poste, et un PNG ne porte pas de commentaire qu'une
     * relecture pourrait retoucher.
     */
    private const string FALLBACK_DIGEST = 'a3497d13d636121dc15f61d556a0ab6f6abed0300263e0cb16e9c49c35bfa7cf';

    private const string FALLBACK_PAINTED_FOR = 'oklch(0.205 0 0)';

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
     * La surface de marque, du point de vue d'un dérivé renommé : le nom du produit va au
     * `<title>`, et **seulement** au `<title>`.
     *
     * Le `h1` nomme la page (DESIGN.md l. 515). Ce n'est pas un détail de rédaction :
     * `page_focus_controller` place le focus sur ce `h1` après chaque navigation Turbo,
     * donc un `h1` qui porte le nom du produit fait annoncer « Menuiserie Dubois » à un
     * lecteur d'écran à chaque page atteinte, au lieu de la page atteinte (WCAG 2.4.6).
     * Le problème d'origine est réglé pareil : plus de « Socle ERP » en dur, et un seul
     * nom de produit sur la page.
     *
     * **Le dérivé est renommé le temps du rendu, et ce n'est pas un détail non plus.** Le
     * défaut d'`APP_NAME` est « Socle ERP », exactement le texte que le `h1` codait en
     * dur : comparer la page au paramètre tel quel resterait vert sur le code cassé.
     *
     * Le test lit la page **rendue**, pas la source du gabarit : le `h1` appartient au
     * template de page (règle 6), et c'est là qu'un nom en dur se réintroduirait.
     */
    #[Test]
    public function a_renamed_derivative_names_itself_in_the_title_and_the_page_in_the_heading(): void
    {
        $name = 'Menuiserie Dubois';
        $crawler = self::renderAs($name);
        $heading = trim($crawler->filter('h1')->text());

        self::assertSame(
            'Accueil',
            $heading,
            'Le `h1` de l\'accueil ne nomme pas la page : `page_focus_controller` y place le focus après chaque navigation Turbo, donc un lecteur d\'écran annonce ce texte à la place de la page atteinte (WCAG 2.4.6).',
        );
        self::assertStringNotContainsString(
            $name,
            $heading,
            'Le nom du produit est revenu dans le `h1` : il appartient au `<title>`, et à lui seul.',
        );
        self::assertSame(
            'Accueil — '.$name,
            $crawler->filter('title')->text(),
            'Le `<title>` ne suit pas le nom du dérivé : `APP_NAME` ne l\'atteint plus.',
        );
    }

    /**
     * `APP_NAME=` vide, l'état qu'un dérivé atteint en une ligne de `.env.local`.
     *
     * `app.name` vaut `%env(APP_NAME)%` sans processeur `default:` : rien ne rattrape une
     * variable vidée. Un titre de page qui en dépendrait deviendrait un `h1` vide — un
     * titre que la règle 6 du plancher compte comme présent et qu'aucun lecteur d'écran
     * n'annonce.
     */
    #[Test]
    public function an_empty_derivative_name_never_empties_the_heading(): void
    {
        $crawler = self::renderAs('');

        self::assertSame(
            'Accueil',
            trim($crawler->filter('h1')->text()),
            'Un `APP_NAME` vide vide le `h1` : le titre de la page ne doit pas dépendre du nom du produit.',
        );
        // La **forme** du titre, pas seulement sa présence : « Accueil — » n'est pas vide
        // et ne nomme rien de plus que « Accueil ». Un séparateur promet une seconde
        // moitié ; s'il n'y en a pas, il ne doit pas être composé. La règle miroir du
        // plancher tient les deux bouts sur toute page rendue.
        self::assertSame(
            'Accueil',
            trim($crawler->filter('title')->text()),
            'Un `APP_NAME` vide laisse un séparateur orphelin dans le `<title>` : le gabarit compose « — » sans avoir de nom de dérivé à y accrocher (WCAG 2.4.2).',
        );
    }

    /**
     * Les deux icônes, et le fait qu'elles désignent des fichiers réels.
     *
     * Vérifier la présence des balises ne prouverait rien : un `href` mort passerait. Ce
     * test résout chaque `href` rendu dans la chaîne d'assets et exige qu'il soit le
     * chemin public d'un fichier réellement présent sous `assets/brand/` — le répertoire
     * qu'un dérivé écrase, et le seul geste que son rebranding graphique demande.
     *
     * L'ordre et le repli sont vérifiés **comme déclarations**, et rien de plus. Ce que la
     * page promet est : deux icônes, le SVG d'abord. Laquelle un moteur affiche réellement
     * n'a pas été mesuré — la tentative et son échec sont consignés dans le spec de la
     * story 1.14 —, et aucune assertion de ce fichier ne prétend le savoir. Le seul fait
     * observé est que le SVG est demandé à chaque chargement, jamais écarté.
     */
    #[Test]
    public function the_head_declares_two_icons_that_resolve_to_real_brand_files(): void
    {
        $icons = self::render()->filter('link[rel="icon"]');

        self::assertCount(
            2,
            $icons,
            \sprintf('« %s » ne déclare pas deux `<link rel="icon">` : sans icône, chaque navigateur demande `/favicon.ico` et reçoit un 404 ; sans repli matriciel, Safari le redemande quand même.', self::TEMPLATE),
        );

        $published = self::publishedBrandAssets();
        $sources = [];

        foreach ($icons->each(static fn (Crawler $icon): ?string => $icon->attr('href')) as $href) {
            self::assertIsString($href, 'Un `<link rel="icon">` du gabarit n\'a pas de `href`.');
            self::assertArrayHasKey(
                $href,
                $published,
                \sprintf(
                    '« %s » ne désigne aucun fichier de `assets/brand/` servi par AssetMapper. Chemins publics connus : %s.',
                    $href,
                    '' === implode(', ', array_keys($published)) ? 'aucun' : implode(', ', array_keys($published)),
                ),
            );

            $sources[] = $published[$href];
        }

        self::assertStringEndsWith('.svg', $sources[0] ?? '', 'La première icône déclarée n\'est pas le SVG : c\'est lui qui porte la marque et sa media query sombre, et c\'est l\'ordre que le spec fixe.');
        self::assertStringEndsWith('.png', $sources[1] ?? '', 'La seconde icône déclarée n\'est pas le repli matriciel : sans lui, un moteur qui ne rend pas les SVG redemande `/favicon.ico` et reçoit le 404 que cette story ferme.');
    }

    /**
     * Les icônes appartiennent au gabarit, pas à une page.
     *
     * Le gabarit rendu **nu** — une page qui ne redéfinit aucun bloc — les porte quand
     * même. Déclarées dans un `{% block %}` qu'une page devrait remplir, elles
     * disparaîtraient des pages d'erreur de la story 1.10, qui n'écrivent même pas leur
     * titre.
     */
    #[Test]
    public function the_icons_are_carried_by_the_template_and_not_by_the_page(): void
    {
        self::assertSame(
            2,
            preg_match_all('/<link[^>]+rel="icon"/i', self::renderBareTemplate()),
            \sprintf('Une page qui ne redéfinit aucun bloc perd les icônes de « %s » : elles appartiennent au gabarit, pas au template de page.', self::TEMPLATE),
        );
    }

    /**
     * Les deux icônes sont déclarées pour ce qu'elles **sont**, lu dans les fichiers.
     *
     * **Ce test ne se borne jamais.** Il tient des propriétés dues par toute icône, celle
     * d'un dérivé comprise : la page déclare le bon type, et le repli est une image
     * matricielle réelle et utilisable. Un dérivé qui remplace son repli par un SVG
     * renommé `.png` doit rougir ici, c'est tout l'intérêt. La **forme carrée**, elle,
     * décrit le défaut du socle et vit dans le test borné correspondant.
     *
     * Trois choses tiennent ici, et chacune a un mode d'échec silencieux.
     *
     * `type` évite au moteur de télécharger un fichier pour découvrir qu'il ne sait pas
     * le rendre. `sizes="any"` décrit le SVG pour ce qu'il est — une image sans dimension
     * propre ; le repli, lui, n'en porte **aucun** — un `sizes` figé décrirait un fichier
     * que le contrat de rebranding invite justement à remplacer, et un dérivé en 180×180
     * devrait alors éditer le gabarit, hors des « deux fichiers, et rien d'autre » que ce
     * même gabarit promet. Aucun des deux attributs n'est ici pour forcer un choix de
     * moteur : ce que ce test vérifie est ce que la page **déclare**.
     *
     * Et le repli est lu dans son **en-tête**, pas dans son extension : c'est le seul
     * fichier que Safari verra, et un SVG renommé `.png` ou un fichier vide passeraient
     * une assertion sur le nom.
     */
    #[Test]
    public function the_two_icons_are_declared_as_what_they_really_are(): void
    {
        $links = self::render()->filter('link[rel="icon"]')->each(static fn (Crawler $icon): array => [
            'type' => $icon->attr('type'),
            'sizes' => $icon->attr('sizes'),
        ]);

        self::assertCount(2, $links, \sprintf('« %s » ne déclare pas deux `<link rel="icon">`.', self::TEMPLATE));

        self::assertSame('image/svg+xml', $links[0]['type'], 'La première icône ne déclare pas son type : un moteur qui ne rend pas les SVG doit le savoir sans télécharger le fichier.');
        self::assertSame('any', $links[0]['sizes'], 'Le SVG n\'a plus `sizes="any"` : la page ne dit plus qu\'il est sans dimension propre, alors que c\'est la seule des deux icônes qui l\'est.');

        self::assertSame('image/png', $links[1]['type'], 'Le repli ne déclare pas son type.');
        self::assertNull(
            $links[1]['sizes'],
            \sprintf('Le repli porte un `sizes` dans « %s » : le gabarit décrit un fichier que le contrat invite à remplacer, et un dérivé qui change de dimensions devrait éditer le gabarit (NFR-6).', self::TEMPLATE),
        );

        $header = getimagesize(GateFiles::projectDir().'/'.self::FALLBACK);

        self::assertIsArray($header, \sprintf('« %s » n\'est pas une image lisible : c\'est pourtant le seul fichier qu\'un moteur sans SVG affichera.', self::FALLBACK));
        self::assertSame(\IMAGETYPE_PNG, $header[2], \sprintf('« %s » n\'est pas un PNG réel — seule son extension le disait.', self::FALLBACK));
        self::assertGreaterThanOrEqual(16, $header[0], \sprintf('« %s » est plus petit que 16 px : aucun onglet n\'en fera quelque chose.', self::FALLBACK));
    }

    /**
     * L'icône est du XML bien formé — la condition de tout le reste.
     *
     * Un SVG mal formé n'est pas rendu « approximativement » : XML 1.0 §2.5 fait de la
     * moindre faute une erreur **fatale**, et le moteur n'affiche rien. La media query
     * sombre, le carré, la couleur de marque : tout ce que ce fichier promet disparaît
     * d'un coup, sans qu'aucune page cesse de se rendre. Le piège rencontré est un
     * commentaire XML contenant deux tirets consécutifs, ce qu'aucune recherche de
     * sous-chaîne ne voit — d'où le parse réel.
     *
     * **Ce test ne se borne jamais** : un dérivé dont le logo ne parse pas n'affiche
     * aucune icône, exactement comme le socle. C'est une propriété due à tout le monde.
     */
    #[Test]
    public function the_svg_icon_is_well_formed_xml(): void
    {
        self::assertSame(
            'svg',
            self::iconDocument()->documentElement?->localName,
            \sprintf('La racine de « %s » n\'est pas un `<svg>`.', self::ICON),
        );
    }

    /**
     * Un carré noir disparaît dans un onglet sombre.
     *
     * Le défaut du socle doit être présentable **avant** d'avoir été remplacé, d'où la
     * media query interne au SVG — un favicon n'hérite d'aucune feuille de style, il ne
     * peut adapter sa couleur que lui-même.
     *
     * L'assertion porte sur la **déclaration**, pas sur le fichier : un `circle { … }`
     * qui ne désigne aucun nœud du document, ou une règle sombre posée sur un autre
     * sélecteur que la règle claire, laisseraient le carré noir dans l'onglet sombre tout
     * en gardant « prefers-color-scheme » quelque part dans le texte.
     *
     * **Test borné** (`BMAD_OUTPUT`) : il exige une feuille `<style>` interne et une règle
     * `fill` de chaque côté d'un `@media`. C'est la forme du défaut du socle, pas une
     * obligation du contrat de rebranding — un logo de dérivé peut porter ses couleurs en
     * attributs, sans feuille du tout.
     */
    #[Test]
    public function the_default_icon_repaints_under_a_dark_scheme_the_very_shape_it_draws(): void
    {
        self::skipUnlessTheDefaultIconsAreUnderTest();

        $fills = self::iconFills();

        self::assertGreaterThan(
            0,
            self::iconDocument()->getElementsByTagName($fills['selector'])->length,
            \sprintf('« %s » peint « %s », qui ne désigne aucun nœud du document : la règle ne s\'applique à rien.', self::ICON, $fills['selector']),
        );
        self::assertNotSame(
            $fills['light'],
            $fills['dark'],
            \sprintf('« %s » peint « %s » de la même couleur dans les deux schémas : sa media query sombre ne change rien.', self::ICON, $fills['selector']),
        );
    }

    /**
     * Les deux couleurs du SVG **sont** les deux `--primary` du thème.
     *
     * Une image n'hérite d'aucune feuille de style et un favicon est rendu hors du
     * document : la couleur doit être écrite en dur dans le fichier de marque, il n'y a
     * pas d'autre moyen. C'est donc une valeur dupliquée, et `HardcodedColorTest` ne la
     * voit pas — il scanne `templates/`, `src/` et `assets/controllers/`, jamais
     * `assets/brand/`. Sans ce test, un `--primary` recoloré dans `theme.css` laisserait
     * l'icône sur l'ancienne teinte, en silence.
     *
     * **Test borné** (`BMAD_OUTPUT`) : une règle qui exigerait le `--primary` du socle sur
     * le logo d'un dérivé ferait rougir la porte d'un dérivé parfaitement conforme au
     * contrat de rebranding. Dans le socle, en revanche, elle **échoue** — elle ne se
     * retire pas.
     *
     * La table de conversion est explicite : convertir oklch en sRGB dans un test coûte
     * plus cher que la garde ne vaut, et une valeur absente de la table est un échec qui
     * dit exactement quoi faire — convertir, et inscrire le couple ici.
     */
    #[Test]
    public function the_default_svg_icon_carries_the_two_primary_colours_of_the_theme(): void
    {
        self::skipUnlessTheDefaultIconsAreUnderTest();

        $fills = self::iconFills();

        foreach (['light' => ThemeSheet::LIGHT, 'dark' => ThemeSheet::FORCED_DARK] as $scheme => $selector) {
            $oklch = ThemeSheet::block(ThemeSheet::THEME, $selector)['--primary'] ?? null;

            self::assertIsString($oklch, \sprintf('« %s » ne déclare plus `--primary` dans « %s ».', ThemeSheet::THEME, $selector));
            self::assertArrayHasKey(
                $oklch,
                self::PRIMARY_IN_SRGB,
                \sprintf('`--primary` vaut « %s » et cette valeur n\'est pas dans la table de conversion de ce test : convertissez-la en sRGB, inscrivez le couple ici, puis repeignez « %s » et régénérez « %s ».', $oklch, self::ICON, self::FALLBACK),
            );
            self::assertSame(
                self::PRIMARY_IN_SRGB[$oklch],
                $fills[$scheme],
                \sprintf(
                    'La couleur ne correspond plus au thème : « %s » ne peint plus le `--primary` %s de « %s » (« %s »). Repeignez l\'icône, et régénérez « %s » dans la même foulée — sa garde à lui est le couple `FALLBACK_DIGEST` / `FALLBACK_PAINTED_FOR`.',
                    self::ICON,
                    'light' === $scheme ? 'clair' : 'sombre',
                    ThemeSheet::THEME,
                    $oklch,
                    self::FALLBACK,
                ),
            );
        }
    }

    /**
     * Le repli matriciel du socle : le même carré, et un épinglage humain qui n'a pas
     * bougé.
     *
     * **Ce test ne lit aucun pixel, et il ne prétend pas le contraire** : la porte tourne
     * sur un PHP sans extension d'image — `.github/workflows/ci.yml` installe
     * `ctype, iconv, intl, pdo_mysql` —, et exiger `ext-gd` pour vérifier la couleur d'un
     * carré l'exigerait sur chaque serveur client.
     *
     * Ce qu'il prouve, exactement : le fichier n'a pas changé depuis qu'une personne a
     * écrit `FALLBACK_PAINTED_FOR`, et cette valeur est encore celle du thème. Ce qu'il ne
     * prouve **pas** : la couleur des pixels — ni aujourd'hui, ni au moment de
     * l'épinglage, où elle fut mesurée à la main (932 pixels à `0x171717`). Il ne rend pas
     * non plus l'oubli impossible : recopier la nouvelle valeur dans la constante est un
     * chemin vers le vert plus court que régénérer le fichier. Il le rend **visible**, au
     * moment exact où il se produirait, et c'est ce qui vaut la machinerie.
     *
     * **Test borné** (`BMAD_OUTPUT`) : la forme carrée et la couleur du thème décrivent le
     * défaut du socle, pas le logo d'un dérivé. Les propriétés dues à tout repli —
     * en-tête PNG réel, dimension minimale — sont tenues par
     * `the_two_icons_are_declared_as_what_they_really_are()`, qui ne se tait jamais.
     */
    #[Test]
    public function the_default_raster_fallback_is_the_square_pinned_for_the_current_primary_colour(): void
    {
        self::skipUnlessTheDefaultIconsAreUnderTest();

        $header = getimagesize(GateFiles::projectDir().'/'.self::FALLBACK);

        self::assertIsArray($header, \sprintf('« %s » n\'est pas une image lisible.', self::FALLBACK));
        self::assertSame($header[1], $header[0], \sprintf('« %s » n\'est pas carré : ce n\'est plus le même carré que le SVG du socle.', self::FALLBACK));

        self::assertSame(
            self::FALLBACK_DIGEST,
            hash_file('sha256', GateFiles::projectDir().'/'.self::FALLBACK),
            \sprintf(
                '« %s » a changé sans que `FALLBACK_DIGEST` suive. Si vous l\'avez régénéré, épinglez le nouveau condensat et vérifiez `FALLBACK_PAINTED_FOR` dans le même commit : c\'est le seul lien entre ce fichier et la couleur du thème, et le laisser dériver rend la garde muette pour tout le monde.',
                self::FALLBACK,
            ),
        );

        self::assertSame(
            ThemeSheet::block(ThemeSheet::THEME, ThemeSheet::LIGHT)['--primary'] ?? null,
            self::FALLBACK_PAINTED_FOR,
            \sprintf(
                'La couleur ne correspond plus au thème : « %s » a été épinglé pour `--primary: %s`, que « %s » ne déclare plus. Régénérez le repli, puis mettez à jour `FALLBACK_PAINTED_FOR` **et** `FALLBACK_DIGEST`. Aucun test ne lit ses pixels : ce couple est tout ce qui signale l\'oubli.',
                self::FALLBACK,
                self::FALLBACK_PAINTED_FOR,
                ThemeSheet::THEME,
            ),
        );
    }

    /**
     * La borne des tests qui décrivent le carré par défaut du socle (voir `BMAD_OUTPUT`).
     *
     * Elle lit un signal du dépôt, jamais l'artefact sous test : un dérivé a le droit de
     * poser le logo qu'il veut, le socle doit tenir la description du sien.
     */
    private static function skipUnlessTheDefaultIconsAreUnderTest(): void
    {
        if (is_dir(GateFiles::projectDir().'/'.self::BMAD_OUTPUT)) {
            return;
        }

        self::markTestSkipped(\sprintf(
            '« %s/ » est absent : ce test décrit l\'icône **par défaut du socle**, et il n\'y a rien à décrire ici. '
            .'Votre marque est la vôtre — remesurez son contraste (`assets/styles/brand.css`). '
            .'Les propriétés que le socle garantit à toute icône, la vôtre comprise, sont tenues par les autres tests de ce fichier et ne se taisent jamais.',
            self::BMAD_OUTPUT,
        ));
    }

    /**
     * NFR-6, sur la marque graphique — et cette fois sur un **comportement**.
     *
     * La moitié basse est une absence : aucun fichier de `src/Core/` ni de `config/` ne
     * nomme les icônes, donc aucune édition du socle n'est nécessaire pour changer de
     * marque. Prise seule, elle était déjà vraie avant cette story et le resterait si les
     * icônes disparaissaient.
     *
     * La moitié haute la rend vérifiable : le chemin public servi est **recalculé depuis
     * les octets du fichier**, sans passer par AssetMapper. Écraser un fichier de
     * `assets/brand/` change donc l'URL que la page sert, et un `href` figé en dur dans le
     * gabarit — le raccourci qui casserait le rebranding — échoue ici.
     *
     * La forme du condensat appartient à AssetMapper : si elle change, ce test rougit et
     * se met à jour. La propriété qu'il garde, elle, ne change pas — l'URL suit le
     * contenu.
     */
    #[Test]
    public function overwriting_a_brand_file_changes_the_served_url_and_edits_no_file_of_the_core(): void
    {
        $hrefs = self::render()->filter('link[rel="icon"]')->each(static fn (Crawler $icon): ?string => $icon->attr('href'));

        self::assertSame(
            [self::expectedPublicPath(self::ICON), self::expectedPublicPath(self::FALLBACK)],
            $hrefs,
            'Le chemin servi ne se déduit plus du contenu des fichiers de `assets/brand/` : écraser un fichier ne changerait pas l\'URL, et le navigateur garderait l\'ancienne icône en cache.',
        );

        $offences = [];

        $finder = new Finder()
            ->files()
            ->in([GateFiles::projectDir().'/src/Core', GateFiles::projectDir().'/config'])
            ->name(['*.php', '*.yaml', '*.yml']);

        foreach ($finder as $file) {
            if (str_contains((string) file_get_contents($file->getPathname()), 'brand/favicon')) {
                $offences[] = str_replace('\\', '/', substr($file->getPathname(), \strlen(GateFiles::projectDir()) + 1));
            }
        }

        self::assertSame(
            [],
            $offences,
            "Les fichiers d'icône sont nommés hors du gabarit : un dérivé devrait éditer `src/Core/` ou `config/` pour changer sa marque (NFR-6).\n".implode("\n", $offences),
        );
    }

    /**
     * Le chemin public qu'AssetMapper donne à un fichier, recalculé depuis ses octets.
     *
     * Volontairement indépendant du service : c'est ce qui fait de l'assertion une preuve
     * que l'URL suit le contenu, et non la comparaison d'AssetMapper avec lui-même.
     * Reproduit `MappedAssetFactory::getPublicPath()` — condensat `xxh128`, ré-encodé en
     * base64 URL-safe et tronqué à sept caractères.
     */
    private static function expectedPublicPath(string $path): string
    {
        $digest = hash_file('xxh128', GateFiles::projectDir().'/'.$path);

        self::assertIsString($digest, \sprintf('« %s » est illisible.', $path));

        $short = strtr(substr(base64_encode((string) hex2bin($digest)), 0, 7), '+/', '-_');
        $logicalPath = substr($path, \strlen(self::ASSETS));

        return '/assets/'.preg_replace('/\.(\w+)$/', '-'.$short.'\\0', $logicalPath);
    }

    /**
     * L'icône, parsée. Le parse **est** l'assertion : un SVG mal formé n'est pas rendu du
     * tout, donc tout test qui lirait le fichier en texte parlerait d'une icône que
     * personne ne voit.
     */
    private static function iconDocument(): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument();
        $loaded = $document->loadXML(GateFiles::read(self::ICON));
        $errors = array_map(
            static fn (LibXMLError $error): string => \sprintf('ligne %d : %s', $error->line, trim($error->message)),
            libxml_get_errors(),
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue(
            $loaded && [] === $errors,
            \sprintf("« %s » n'est pas du XML bien formé : aucun navigateur ne rend cette icône.\n%s", self::ICON, implode("\n", $errors)),
        );

        return $document;
    }

    /**
     * Le sélecteur peint par la feuille interne de l'icône, et ses deux valeurs de
     * `fill` — celle du schéma clair, celle du bloc `prefers-color-scheme: dark`.
     *
     * Les commentaires CSS sont retirés d'abord : ils ne peignent rien, et les compter
     * ferait passer une icône monochrome documentée pour une icône bicolore.
     *
     * @return array{selector: string, light: string, dark: string}
     */
    private static function iconFills(): array
    {
        $style = '';

        foreach (self::iconDocument()->getElementsByTagName('style') as $node) {
            $style .= $node->textContent;
        }

        $style = (string) preg_replace('#/\*.*?\*/#s', '', $style);

        self::assertSame(
            1,
            preg_match('/@media\s*\(\s*prefers-color-scheme\s*:\s*dark\s*\)\s*\{(?P<body>[^{}]*\{[^{}]*\}[^{}]*)\}/s', $style, $dark),
            \sprintf('« %s » ne porte pas de bloc `@media (prefers-color-scheme: dark)` : le carré du socle disparaît dans un onglet sombre.', self::ICON),
        );

        $light = self::fillRule(str_replace($dark[0], '', $style), 'la règle du schéma clair');
        $darkRule = self::fillRule($dark['body'], 'la règle du bloc sombre');

        self::assertSame(
            $light['selector'],
            $darkRule['selector'],
            \sprintf('« %s » peint « %s » en clair et « %s » en sombre : le bloc sombre ne repeint pas la forme dessinée.', self::ICON, $light['selector'], $darkRule['selector']),
        );

        return ['selector' => $light['selector'], 'light' => $light['value'], 'dark' => $darkRule['value']];
    }

    /**
     * L'unique règle `sélecteur { fill: … }` d'un fragment de CSS.
     *
     * @return array{selector: string, value: string}
     */
    private static function fillRule(string $css, string $what): array
    {
        self::assertSame(
            1,
            preg_match_all('/(?P<selector>[a-z][a-z0-9-]*)\s*\{[^{}]*\bfill\s*:\s*(?P<value>[^;}]+)/i', $css, $matches, \PREG_SET_ORDER),
            \sprintf('« %s » ne porte pas exactement une règle `fill` pour %s.', self::ICON, $what),
        );

        return [
            'selector' => strtolower($matches[0]['selector']),
            'value' => strtolower(trim($matches[0]['value'])),
        ];
    }

    /**
     * Les fichiers réellement présents sous `assets/brand/`, indexés par le chemin public
     * qu'AssetMapper leur donne.
     *
     * Balayés sur le disque plutôt que recopiés : c'est ce qui fait que le test compare
     * un `href` rendu à un fichier existant, et non à une constante qui pourrait mentir.
     *
     * @return array<string, string> chemin public => chemin logique
     */
    private static function publishedBrandAssets(): array
    {
        $mapper = self::getContainer()->get(AssetMapperInterface::class);
        $published = [];
        $entries = scandir(GateFiles::projectDir().'/'.self::BRAND);

        self::assertIsArray($entries, \sprintf('« %s » est absent : le répertoire de marque est ce qu\'un dérivé remplace.', self::BRAND));

        foreach ($entries as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $logicalPath = 'brand/'.$entry;
            $asset = $mapper->getAsset($logicalPath);

            if (null !== $asset) {
                $published[$asset->publicPath] = $logicalPath;
            }
        }

        return $published;
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

    /**
     * L'accueil rendue par un dérivé qui s'est renommé.
     *
     * `app.name` vaut `%env(APP_NAME)%` : le conteneur compilé ne fige pas cette valeur,
     * il la relit à chaque instanciation. Poser la variable avant d'amorcer le client
     * suffit donc à rendre la page « du point de vue d'un dérivé », sans toucher un
     * fichier — ce que le test veut précisément prouver.
     */
    private static function renderAs(string $derivativeName): Crawler
    {
        $previous = [$_ENV['APP_NAME'] ?? null, $_SERVER['APP_NAME'] ?? null];

        self::ensureKernelShutdown();
        $_ENV['APP_NAME'] = $_SERVER['APP_NAME'] = $derivativeName;

        try {
            return self::render();
        } finally {
            [$env, $server] = $previous;

            if (null === $env) {
                unset($_ENV['APP_NAME']);
            } else {
                $_ENV['APP_NAME'] = $env;
            }

            if (null === $server) {
                unset($_SERVER['APP_NAME']);
            } else {
                $_SERVER['APP_NAME'] = $server;
            }

            self::ensureKernelShutdown();
        }
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
