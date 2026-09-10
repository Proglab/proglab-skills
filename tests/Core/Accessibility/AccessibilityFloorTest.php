<?php

declare(strict_types=1);

namespace App\Tests\Core\Accessibility;

use App\Tests\Core\Quality\GateFiles;
use App\Tests\Core\Theme\ThemeSheet;
use DOMElement;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;

/**
 * Le plancher d'accessibilité, vérifié plutôt que relu.
 *
 * C'est la sixième catégorie de la porte de qualité — `make a11y` en local, le job
 * « Accessibility » en ligne. Le contrat de l'epic dit « vérifié par un test automatisé
 * en CI, jamais par relecture » : une catégorie nommée est ce qui rend l'échec lisible.
 *
 * **Ce que ce contrôle peut prouver.** Il n'y a pas de navigateur ici. Sont mesurables :
 * la structure du DOM réellement rendu (repères, hiérarchie de titres, labels,
 * alternatives textuelles), les sources déclarées en `@source` par `assets/styles/app.css`
 * (`outline-none` sans remplacement, révélation au survol sans équivalent au focus) et
 * les valeurs du thème (contraste calculé, pas recopié).
 *
 * **Ce qu'il ne peut pas prouver, et qui est donc écrit ici plutôt que découvert plus
 * tard :** l'ordre de tabulation réel, le rendu à 320 px, ce qu'annonce un lecteur
 * d'écran. Les règles 1 et 7 reçoivent en conséquence un contrôle **étroit et nommé** —
 * la règle 1 ne vérifie que le lien `aria-invalid` → `aria-describedby`, la règle 7 ne
 * vérifie que les utilitaires `hover:` qui révèlent. Ces deux écarts se ferment par la
 * relecture humaine décrite dans le README, pas par une assertion.
 */
final class AccessibilityFloorTest extends WebTestCase
{
    /**
     * Le `<nav>` est explicitement exempté, avec son motif : la Sidebar appartient à la
     * story 2.3, et un `<nav>` vide n'est pas un repère — c'est un repère annoncé
     * « navigation » qui ne contient rien. Le gabarit expose donc le bloc et ne rend
     * l'élément que rempli. La moitié « la page porte `<nav aria-label>` » du premier
     * critère de la story 1.4 ne se referme qu'à la 2.3 ; c'est une couture assumée,
     * écrite ici pour qu'elle ne se perde pas en silence.
     */
    private const string NAV_EXEMPTION = 'le repère `<nav>` est exempté jusqu\'à la story 2.3 (Sidebar) — voir la constante NAV_EXEMPTION';

    #[Test]
    public function every_page_starts_with_a_skip_link_to_its_main_landmark(): void
    {
        $offences = [];

        foreach ($this->pages() as $page => $crawler) {
            $focusable = $crawler->filter('body a[href], body button, body input, body select, body textarea, body [tabindex]:not([tabindex="-1"])');

            if (0 === $focusable->count()) {
                $offences[] = \sprintf('%s : aucun élément focalisable — le lien d\'évitement a disparu.', $page);

                continue;
            }

            $first = $focusable->first();
            $target = $first->attr('href');

            if ('a' !== $first->nodeName() || null === $target || !str_starts_with($target, '#')) {
                $offences[] = \sprintf('%s : le premier élément focalisable n\'est pas un lien d\'évitement (WCAG 2.4.1).', $page);

                continue;
            }

            if (!self::hasElementWithId($crawler, substr($target, 1))) {
                $offences[] = \sprintf('%s : le lien d\'évitement pointe vers « %s », qui n\'existe pas dans la page.', $page, $target);
            }
        }

        self::assertSame([], $offences, self::report('lien d\'évitement (WCAG 2.4.1)', $offences));
    }

    #[Test]
    public function every_page_carries_its_landmarks(): void
    {
        $offences = [];

        foreach ($this->pages() as $page => $crawler) {
            if (1 !== $crawler->filter('header')->count()) {
                $offences[] = \sprintf('%s : la page doit porter exactement un repère `<header>`.', $page);
            }

            $main = $crawler->filter('main');

            if (1 !== $main->count()) {
                $offences[] = \sprintf('%s : la page doit porter exactement un repère `<main>`.', $page);

                continue;
            }

            if ('-1' !== $main->attr('tabindex')) {
                $offences[] = \sprintf('%s : le `<main>` n\'est pas focalisable (`tabindex="-1"`) — le lien d\'évitement y déplacerait le défilement sans le focus.', $page);
            }

            foreach (self::elementsOf($crawler->filter('nav')) as $nav) {
                if ('' === trim($nav->getAttribute('aria-label')) && '' === trim($nav->getAttribute('aria-labelledby'))) {
                    $offences[] = \sprintf('%s : un `<nav>` rendu sans `aria-label` — plusieurs navigations indistinctes dans la liste des repères. (%s)', $page, self::NAV_EXEMPTION);
                }
            }
        }

        self::assertSame([], $offences, self::report('repères de page (WCAG 1.3.1)', $offences));
    }

    /**
     * L'attribut `lang`, et sa **forme**.
     *
     * « Non vide » ne suffit pas : une locale Symfony s'écrit `fr_BE`, une étiquette de
     * langue HTML s'écrit `fr-BE` (BCP 47). Recopiée telle quelle, la valeur est ignorée
     * par les lecteurs d'écran, qui retombent sur la langue du système — un défaut muet
     * qui n'apparaît qu'au premier dérivé régionalisé.
     */
    #[Test]
    public function every_page_declares_its_language(): void
    {
        $offences = [];

        foreach ($this->pages() as $page => $crawler) {
            $lang = $crawler->filter('html')->attr('lang');

            if (null === $lang || '' === trim($lang)) {
                $offences[] = \sprintf('%s : `<html>` n\'a pas d\'attribut `lang` — un lecteur d\'écran choisit alors sa voix au hasard (WCAG 3.1.1).', $page);

                continue;
            }

            if (1 !== preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', trim($lang))) {
                $offences[] = \sprintf('%s : `lang="%s"` n\'est pas une étiquette BCP 47 — une locale Symfony (`fr_BE`) se transpose en `fr-BE` avant d\'atteindre le HTML (WCAG 3.1.1).', $page, $lang);
            }
        }

        self::assertSame([], $offences, self::report('langue de la page (WCAG 3.1.1)', $offences));
    }

    /**
     * Chaque page rendue a un titre qui la nomme (WCAG 2.4.2).
     *
     * Le gabarit compose « <page> — <nom du dérivé> ». Une page qui n'écrit pas son bloc
     * `title` — les pages d'erreur de la story 1.10 sont nommément concernées — rendrait
     * sans garde « — Socle ERP » : un titre qui commence par un tiret cadratin et ne dit
     * rien de la page. Cette règle est ce qui empêche la garde de disparaître.
     */
    #[Test]
    public function every_page_has_a_title_that_names_it(): void
    {
        $offences = [];

        foreach ($this->pages() as $page => $crawler) {
            $title = $crawler->filter('title');

            if (1 !== $title->count()) {
                $offences[] = \sprintf('%s : la page ne rend pas de `<title>`.', $page);

                continue;
            }

            $text = trim($title->text());

            if ('' === $text) {
                $offences[] = \sprintf('%s : le `<title>` est vide.', $page);

                continue;
            }

            if (str_starts_with($text, '—') || str_contains($text, '  —')) {
                $offences[] = \sprintf('%s : le `<title>` vaut « %s » — le nom de la page manque, et le gabarit compose quand même son séparateur (WCAG 2.4.2).', $page, $text);
            }
        }

        self::assertSame([], $offences, self::report('titre de page (WCAG 2.4.2)', $offences));
    }

    /**
     * La région d'annonces est persistante et vide au rendu. Sous Turbo Drive, une
     * navigation ne recharge pas la page : sans elle, ni le titre ni un message présent
     * au rendu n'est annoncé.
     */
    #[Test]
    public function every_page_carries_the_persistent_live_region(): void
    {
        $offences = [];

        foreach ($this->pages() as $page => $crawler) {
            $region = $crawler->filter('#annonces');

            if (1 !== $region->count()) {
                $offences[] = \sprintf('%s : la région live `#annonces` est absente ou en double.', $page);

                continue;
            }

            $node = $region->getNode(0);

            if ('polite' !== $region->attr('aria-live')) {
                $offences[] = \sprintf('%s : `#annonces` n\'est pas `aria-live="polite"`.', $page);
            }

            if (!str_contains($region->attr('class') ?? '', 'sr-only')) {
                $offences[] = \sprintf('%s : `#annonces` n\'est pas `sr-only` — le texte recopié s\'afficherait deux fois.', $page);
            }

            if (!$node instanceof DOMElement || !$node->hasAttribute('data-turbo-permanent')) {
                $offences[] = \sprintf('%s : `#annonces` n\'a pas `data-turbo-permanent` — Turbo la recrée et l\'annonce se perd.', $page);
            }
        }

        self::assertSame([], $offences, self::report('région d\'annonces sous Turbo Drive', $offences));
    }

    /**
     * Règle 1 — la couleur seule ne porte jamais une information.
     *
     * Contrôle **étroit et nommé** : sans navigateur, « la page en niveaux de gris ne
     * perd rien » n'est pas mesurable. Ce qui l'est, c'est le seul mécanisme par lequel
     * le socle promet de doubler la couleur d'un texte — un champ en erreur relié à son
     * message. Un champ `aria-invalid` sans `aria-describedby` vers un texte existant
     * signale son erreur par sa bordure rouge et rien d'autre.
     */
    #[Test]
    public function rule_1_no_field_signals_its_error_by_colour_alone(): void
    {
        $offences = [];

        foreach ($this->pages() as $page => $crawler) {
            foreach (self::elementsOf($crawler->filter('[aria-invalid="true"]')) as $field) {
                $described = self::words($field->getAttribute('aria-describedby'));

                if ([] === $described) {
                    $offences[] = \sprintf('%s : un champ `aria-invalid` sans `aria-describedby` — seule sa bordure dit qu\'il est en erreur (règle 1).', $page);

                    continue;
                }

                foreach ($described as $id) {
                    if (!self::hasElementWithId($crawler, $id)) {
                        $offences[] = \sprintf('%s : `aria-describedby="%s"` pointe vers un élément absent de la page (règle 1).', $page, $id);
                    }
                }
            }
        }

        self::assertSame([], $offences, self::report('règle 1 — la couleur seule ne porte jamais une information', $offences));
    }

    /**
     * Règle 2 — le contraste, mesuré et non recopié.
     *
     * Les combinaisons porteuses de sens de `DESIGN.md § Colors`, en clair et en sombre.
     * `Contrast` refait la chaîne complète depuis les `oklch` de `theme.css` : c'est ce
     * qui tient la promesse « ratios remesurés après tout rebranding ».
     *
     * @var list<array{string, string, float, string}>
     */
    private const array MEANINGFUL_PAIRS = [
        ['--foreground', '--background', 4.5, 'corps et titres'],
        ['--muted-foreground', '--card', 4.5, 'méta, en-têtes de tableau, aide de champ'],
        ['--muted-strong', '--page', 4.5, 'texte secondaire sur le fond de page'],
        ['--destructive-foreground', '--destructive', 4.5, 'bouton destructif'],
    ];

    /**
     * Les deux défauts du kit sous un seuil, mesurés eux aussi — et excusés seulement par
     * le commentaire que `theme.css` porte, jamais par une liste dans ce fichier.
     *
     * @var list<array{string, string, float, string}>
     */
    private const array COMPONENT_PAIRS = [
        ['--input', '--card', 3.0, 'contour des champs de formulaire'],
        ['--ring', '--background', 3.0, 'anneau de focus'],
    ];

    #[Test]
    public function rule_2_every_meaningful_colour_pair_meets_its_threshold(): void
    {
        $offences = [];

        foreach (self::themes() as $theme => $palette) {
            foreach (self::MEANINGFUL_PAIRS as [$front, $back, $threshold, $usage]) {
                $ratio = Contrast::ratio($palette, $front, $back);

                if ($ratio < $threshold) {
                    $offences[] = \sprintf(
                        '%s (%s) : `%s` sur `%s` mesure %.2f:1, sous le seuil de %.1f:1 — %s.',
                        ThemeSheet::THEME,
                        $theme,
                        $front,
                        $back,
                        $ratio,
                        $threshold,
                        $usage,
                    );
                }
            }
        }

        self::assertSame([], $offences, self::report('règle 2 — contraste texte/fond (WCAG 1.4.3)', $offences));
    }

    /**
     * Un défaut du kit sous un seuil est conservé et **documenté comme choix assumé**,
     * jamais consigné en dette (`DESIGN.md § Colors`, principe « défauts shadcn »).
     *
     * D'où la forme de ce test : il ne porte aucune liste d'exceptions. Il mesure, et
     * quand la mesure passe sous le seuil il exige que `theme.css` nomme le jeton et
     * écrive « choix assumé » à côté. Supprimer ce commentaire ne supprime donc pas
     * l'échec — c'est ce qui distingue une exception assumée d'une baseline.
     */
    #[Test]
    public function rule_2_a_shadcn_default_below_threshold_is_excused_only_by_the_theme_naming_it(): void
    {
        $assumed = self::assumedDefaults();
        $offences = [];

        foreach (self::themes() as $theme => $palette) {
            foreach (self::COMPONENT_PAIRS as [$front, $back, $threshold, $usage]) {
                $ratio = Contrast::ratio($palette, $front, $back);

                if ($ratio >= $threshold || \in_array($front, $assumed, true)) {
                    continue;
                }

                $offences[] = \sprintf(
                    '%s (%s) : `%s` sur `%s` mesure %.2f:1, sous le seuil de %.1f:1 (%s), et aucun commentaire de la feuille ne le nomme comme « choix assumé ».',
                    ThemeSheet::THEME,
                    $theme,
                    $front,
                    $back,
                    $ratio,
                    $threshold,
                    $usage,
                );
            }
        }

        self::assertSame([], $offences, self::report('règle 2 — contraste des composants (WCAG 1.4.11)', $offences));
    }

    /**
     * Règle 3 — un vrai label, jamais un placeholder seul.
     */
    #[Test]
    public function rule_3_every_form_control_has_a_real_label(): void
    {
        $offences = [];

        foreach ($this->pages() as $page => $crawler) {
            foreach (self::elementsOf($crawler->filterXPath('//input|//select|//textarea')) as $control) {
                $type = strtolower($control->getAttribute('type'));

                if ('input' === $control->nodeName && \in_array($type, ['hidden', 'submit', 'button', 'reset', 'image'], true)) {
                    continue;
                }

                if (self::hasAccessibleName($crawler, $control)) {
                    continue;
                }

                $offences[] = \sprintf(
                    '%s : un `<%s%s>` sans label réel%s (règle 3, WCAG 3.3.2).',
                    $page,
                    $control->nodeName,
                    '' === $type ? '' : ' type="'.$type.'"',
                    '' === trim($control->getAttribute('placeholder')) ? '' : ' — un `placeholder` n\'en est pas un',
                );
            }
        }

        self::assertSame([], $offences, self::report('règle 3 — un vrai label sur chaque champ', $offences));
    }

    /**
     * Règle 4 — un indicateur de focus visible, toujours.
     *
     * Le piège classique de Tailwind : `outline-none` posé pour faire propre, sans
     * remplacement. Les composants du kit le posent légitimement et le remplacent par
     * `focus-visible:ring-*` ; c'est cette paire que le contrôle exige, fichier par
     * fichier. Supprimer sans remplacer n'est jamais permis.
     */
    #[Test]
    public function rule_4_no_source_removes_the_focus_outline_without_replacing_it(): void
    {
        $offences = [];

        foreach (self::focusSources() as $file => $contents) {
            $isStyleSheet = str_ends_with($file, '.css');

            preg_match_all('/(?<![\w:-])outline-none\b|outline\s*:\s*none/', $contents, $matches, \PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [, $offset]) {
                // La portée, et non le fichier : un fichier qui remplace l'anneau
                // quelque part n'excuse pas une suppression ailleurs. Pour une feuille
                // de style c'est la règle CSS qui porte la déclaration ; pour un
                // template ou un contrôleur, la liste de classes qui porte l'utilitaire.
                $scope = $isStyleSheet
                    ? self::ruleAround($contents, $offset)
                    : self::classListAround($contents, $offset);

                if (self::replacesTheFocusRing($scope, $isStyleSheet)) {
                    continue;
                }

                $offences[] = \sprintf(
                    '%s:%d supprime l\'anneau de focus sans le remplacer (règle 4, WCAG 2.4.7).',
                    $file,
                    self::lineAt($contents, $offset),
                );
            }
        }

        self::assertSame([], $offences, self::report('règle 4 — un indicateur de focus visible', $offences));
    }

    /**
     * Le plancher du thème — et c'est **le dernier** bloc `:focus-visible` qui compte.
     *
     * En CSS, la dernière déclaration de même spécificité gagne. Regarder le premier bloc
     * laisserait un second, écrit plus bas, ramener `outline: none` sans que rien ne le
     * remarque : la feuille déclarerait un plancher qu'elle annule elle-même.
     */
    #[Test]
    public function rule_4_the_theme_declares_the_focus_visible_floor(): void
    {
        $sheet = ThemeSheet::stripComments(GateFiles::read(ThemeSheet::THEME));

        preg_match_all('/:focus-visible\s*\{(?P<body>[^}]*)\}/', $sheet, $blocks, \PREG_SET_ORDER);

        $last = end($blocks);

        self::assertIsArray(
            $last,
            \sprintf('« %s » ne déclare plus de plancher `:focus-visible` : chaque élément interactif écrit à la main perd son anneau (règle 4, WCAG 2.4.7).', ThemeSheet::THEME),
        );

        self::assertMatchesRegularExpression(
            '/outline\s*:\s*(?!none)\S/',
            $last['body'],
            \sprintf('Le dernier bloc `:focus-visible` de « %s » ne dessine pas de contour — c\'est lui qui gagne, et supprimer sans remplacer n\'est jamais permis (règle 4).', ThemeSheet::THEME),
        );
    }

    /**
     * Règle 5 — un texte alternatif qui décrit, pas qui existe.
     */
    #[Test]
    public function rule_5_every_image_and_icon_only_control_carries_its_alternative(): void
    {
        $offences = [];

        foreach ($this->pages() as $page => $crawler) {
            foreach (self::elementsOf($crawler->filterXPath('//img')) as $image) {
                if (!$image->hasAttribute('alt')) {
                    $offences[] = \sprintf('%s : une `<img>` sans attribut `alt` — un lecteur d\'écran annoncerait son nom de fichier (règle 5).', $page);
                }
            }

            foreach (self::elementsOf($crawler->filterXPath('//svg')) as $svg) {
                $hidden = 'true' === $svg->getAttribute('aria-hidden');
                $named = '' !== trim($svg->getAttribute('aria-label')) || '' !== trim($svg->getAttribute('aria-labelledby'));

                if (!$hidden && !$named) {
                    $offences[] = \sprintf('%s : un `<svg>` ni `aria-hidden="true"` ni nommé — une icône est décorative ou porteuse de sens, jamais entre les deux (règle 5).', $page);
                }
            }

            foreach (self::elementsOf($crawler->filterXPath('//a|//button')) as $control) {
                if ('' !== trim($control->textContent)) {
                    continue;
                }

                if ('' === trim($control->getAttribute('aria-label')).trim($control->getAttribute('aria-labelledby')).trim($control->getAttribute('title'))) {
                    $offences[] = \sprintf('%s : un `<%s>` sans intitulé visible ni `aria-label` — il serait annoncé « bouton », sans plus (règle 5).', $page, $control->nodeName);
                }
            }
        }

        self::assertSame([], $offences, self::report('règle 5 — alternatives textuelles', $offences));
    }

    /**
     * Règle 6 — une hiérarchie de titres qui a un sens, pas un style.
     */
    #[Test]
    public function rule_6_every_page_has_exactly_one_h1_and_never_skips_a_level(): void
    {
        $offences = [];

        foreach ($this->pages() as $page => $crawler) {
            $levels = [];

            foreach (self::elementsOf($crawler->filterXPath('//h1|//h2|//h3|//h4|//h5|//h6')) as $heading) {
                $levels[] = (int) substr($heading->nodeName, 1);
            }

            $ones = \count(array_filter($levels, static fn (int $level): bool => 1 === $level));

            if (1 !== $ones) {
                $offences[] = \sprintf('%s : la page porte %d `h1` au lieu d\'un seul (règle 6, WCAG 1.3.1).', $page, $ones);
            }

            // Le premier titre est comparé à `h1`, pas ignoré : une page qui rend `h3`
            // puis `h1` compte bien un seul `h1` et ne saute aucun niveau vers le bas —
            // elle commence pourtant deux crans trop bas, et c'est la même faute.
            $previous = null;

            foreach ($levels as $level) {
                if (null === $previous) {
                    if (1 !== $level) {
                        $offences[] = \sprintf('%s : la page commence par un `h%d` — le premier titre d\'une page est son `h1` (règle 6, WCAG 1.3.1).', $page, $level);
                    }
                } elseif ($level > $previous + 1) {
                    $offences[] = \sprintf('%s : la hiérarchie saute de `h%d` à `h%d` — le niveau décrit la structure, la taille se règle en CSS (règle 6).', $page, $previous, $level);
                }

                $previous = $level;
            }
        }

        self::assertSame([], $offences, self::report('règle 6 — hiérarchie de titres', $offences));
    }

    /**
     * Les utilitaires Tailwind qui **révèlent** quelque chose. Une teinte de fond au
     * survol est permise et attendue ; faire apparaître une action ne l'est pas.
     */
    private const string REVEALING = 'block|flex|grid|inline|inline-flex|inline-block|table|visible|opacity-100';

    /**
     * Règle 7 — rien d'important ne doit dépendre du survol seul.
     *
     * Contrôle **étroit et nommé** : un menu ouvert par un écouteur `mouseenter` en
     * JavaScript n'est pas détectable ici. Ce qui l'est, c'est la forme que prend
     * réellement cette faute sur cette stack — un utilitaire `hover:` qui révèle, sans
     * son équivalent au focus sur le même élément.
     */
    #[Test]
    public function rule_7_no_source_reveals_something_on_hover_alone(): void
    {
        $offences = [];

        foreach (self::sourceLines() as $file => $lines) {
            foreach ($lines as $number => $line) {
                preg_match_all('/(?<![\w:-])(?P<prefix>group-)?hover:(?P<utility>'.self::REVEALING.')\b/', $line, $matches, \PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $utility = $match['utility'];
                    $group = '' === $match['prefix'] ? '' : 'group-';

                    $equivalents = [
                        $group.'focus:'.$utility,
                        $group.'focus-visible:'.$utility,
                        $group.'focus-within:'.$utility,
                        'focus-within:'.$utility,
                    ];

                    foreach ($equivalents as $equivalent) {
                        if (str_contains($line, $equivalent)) {
                            continue 2;
                        }
                    }

                    $offences[] = \sprintf(
                        '%s:%d révèle au survol seul (`%shover:%s`) sans équivalent au focus — invisible au clavier et au doigt (règle 7).',
                        $file,
                        $number,
                        $group,
                        $utility,
                    );
                }
            }
        }

        self::assertSame([], $offences, self::report('règle 7 — rien derrière le survol seul', $offences));
    }

    /**
     * En plus des sept règles : `prefers-reduced-motion` ramène toutes les transitions à
     * 0. Le contrôle porte sur **chaque** jeton de mouvement du thème, pas sur les deux
     * qui existent aujourd'hui — un troisième ajouté sans sa remise à zéro est
     * exactement la régression que cette ligne existe pour attraper.
     */
    #[Test]
    public function reduced_motion_brings_every_motion_token_to_zero(): void
    {
        $declared = array_keys(array_filter(
            ThemeSheet::block(ThemeSheet::THEME, ThemeSheet::LIGHT),
            static fn (string $value, string $name): bool => str_starts_with($name, '--motion-'),
            \ARRAY_FILTER_USE_BOTH,
        ));

        self::assertNotSame([], $declared, \sprintf('« %s » ne déclare plus aucun jeton `--motion-*`.', ThemeSheet::THEME));

        $reduced = ThemeSheet::block(ThemeSheet::THEME, '@media (prefers-reduced-motion: reduce) {');
        $offences = [];

        foreach ($declared as $token) {
            $value = $reduced[$token] ?? null;

            if (null === $value || 1 !== preg_match('/^0m?s$/', $value)) {
                $offences[] = \sprintf('%s : `%s` n\'est pas ramené à zéro sous `prefers-reduced-motion: reduce` (WCAG 2.3.3).', ThemeSheet::THEME, $token);
            }
        }

        self::assertSame([], $offences, self::report('mouvement réduit', $offences));
    }

    /**
     * Le contrôle doit pouvoir échouer.
     *
     * Une page qui ne se rend pas et un répertoire de sources vide rendent toutes les
     * assertions ci-dessus vertes, en silence et pour la mauvaise raison. C'est le mode
     * de défaillance d'un test qui balaye plutôt qu'il n'affirme.
     */
    #[Test]
    public function the_floor_actually_reads_a_rendered_page_and_the_declared_sources(): void
    {
        self::assertNotSame([], $this->pages(), 'Aucune page du socle n\'a été rendue : le contrôle d\'accessibilité ne regarde rien.');

        foreach (self::declaredSources() as $directory) {
            self::assertNotSame(
                0,
                iterator_count(self::finderFor($directory)),
                \sprintf('« %s » est déclaré en `@source` par « %s » et n\'a fourni aucun fichier à scanner.', $directory, ThemeSheet::APP),
            );
        }

        // Et les feuilles de style, que les `@source` ne peuvent pas déclarer : la règle 4
        // les lit, donc elles doivent être là et non vides.
        $read = self::focusSources();

        foreach (self::STYLE_SHEETS as $sheet) {
            self::assertNotSame('', trim($read[$sheet] ?? ''), \sprintf('« %s » n\'est pas lue par la règle 4 : un `outline: none` y passerait la porte.', $sheet));
        }
    }

    // -------------------------------------------------------------------------------
    // Lecture des pages rendues
    // -------------------------------------------------------------------------------

    /** @var array<string, Crawler>|null */
    private ?array $pages = null;

    /**
     * Toute route GET du socle, réellement rendue.
     *
     * Les routes à paramètre sont hors périmètre : le socle n'en a pas encore, et en
     * inventer une valeur ferait rendre une page d'erreur au lieu d'un écran. Elles
     * entrent avec les stories qui les posent.
     *
     * @return array<string, Crawler>
     */
    private function pages(): array
    {
        if (null !== $this->pages) {
            return $this->pages;
        }

        $client = self::createClient();

        $router = self::getContainer()->get('router');

        $pages = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $methods = $route->getMethods();
            $controller = $route->getDefault('_controller');

            // L'application, moins ce qui n'est pas un écran. Le filtre porte sur ce
            // qu'il faut **exclure**, pas sur `App\Core\` : le socle existe pour être
            // dérivé, et un filtre par inclusion mettrait tout `src/Module/*/` — donc
            // tous les écrans du client — hors du plancher, en silence et sans qu'aucun
            // test ne bouge. Seuls les contrôleurs de `tests/Fixtures/Module/` sortent :
            // ils prouvent le câblage par glob des deux racines et ne rendent pas d'écran.
            if (!\is_string($controller) || !str_starts_with($controller, 'App\\') || str_starts_with($controller, 'App\\Tests\\')) {
                continue;
            }

            if (str_contains($route->getPath(), '{')) {
                continue;
            }

            if ([] !== $methods && !\in_array('GET', $methods, true)) {
                continue;
            }

            $client->request('GET', $route->getPath());

            self::assertResponseIsSuccessful(\sprintf('La route « %s » (%s) ne se rend pas : le plancher d\'accessibilité ne peut rien vérifier dessus.', $name, $route->getPath()));

            $pages[\sprintf('%s (%s)', $route->getPath(), $name)] = $client->getCrawler();
        }

        return $this->pages = $pages;
    }

    /**
     * Les éléments d'une sélection, à l'exclusion des nœuds qui n'en sont pas (texte,
     * commentaire) : seul un élément porte des attributs.
     *
     * @return list<DOMElement>
     */
    private static function elementsOf(Crawler $nodes): array
    {
        $elements = [];

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * Une liste d'identifiants séparés par des espaces — la forme d'`aria-describedby` et
     * d'`aria-labelledby`.
     *
     * @return list<string>
     */
    private static function words(string $value): array
    {
        $words = preg_split('/\s+/', trim($value));

        if (false === $words) {
            return [];
        }

        return array_values(array_filter($words, static fn (string $word): bool => '' !== $word));
    }

    /**
     * Un élément porte-t-il cet `id` ?
     *
     * En XPath sur une valeur littérale, jamais en sélecteur CSS : un `href="#mon id"` ou
     * un `aria-describedby="2erreurs"` n'est pas un sélecteur CSS valide, et
     * `Crawler::filter()` **lève** dessus. Le plancher sortirait alors en erreur au lieu
     * de nommer l'infraction — un message qui ne dit plus quoi corriger, exactement là où
     * la page est déjà fautive.
     */
    private static function hasElementWithId(Crawler $crawler, string $id): bool
    {
        if ('' === $id) {
            return false;
        }

        return $crawler->filterXPath(\sprintf('//*[@id=%s]', self::xpathLiteral($id)))->count() > 0;
    }

    /**
     * Une chaîne quelconque en littéral XPath — XPath 1.0 n'a pas d'échappement, donc une
     * valeur qui porte les deux sortes de guillemets se recompose par `concat()`.
     */
    private static function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'".$value."'";
        }

        if (!str_contains($value, '"')) {
            return '"'.$value.'"';
        }

        $parts = [];

        foreach (explode("'", $value) as $index => $piece) {
            if ($index > 0) {
                $parts[] = '"\'"';
            }

            $parts[] = "'".$piece."'";
        }

        return 'concat('.implode(', ', $parts).')';
    }

    private static function hasAccessibleName(Crawler $crawler, DOMElement $control): bool
    {
        if ('' !== trim($control->getAttribute('aria-label'))) {
            return true;
        }

        foreach (self::words($control->getAttribute('aria-labelledby')) as $id) {
            if (self::hasElementWithId($crawler, $id)) {
                return true;
            }
        }

        $id = trim($control->getAttribute('id'));

        if ('' !== $id && $crawler->filterXPath(\sprintf('//label[@for=%s]', self::xpathLiteral($id)))->count() > 0) {
            return true;
        }

        for ($parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if ('label' === $parent->nodeName) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------------
    // Lecture des sources et du thème
    // -------------------------------------------------------------------------------

    /**
     * Les répertoires `@source` de `assets/styles/app.css`, lus là-bas plutôt que
     * recopiés ici : c'est la liste qui décide de ce que Tailwind compile, donc la seule
     * qui décrive l'ensemble réel des sources du frontend.
     *
     * @return list<string>
     */
    private static function declaredSources(): array
    {
        preg_match_all('/@source\s+[\'"](?P<path>[^\'"]+)[\'"]/', GateFiles::read(ThemeSheet::APP), $matches);

        $directories = [];

        foreach ($matches['path'] as $path) {
            $resolved = realpath(GateFiles::projectDir().'/assets/styles/'.$path);

            if (false === $resolved) {
                continue;
            }

            $directories[] = trim(str_replace('\\', '/', substr($resolved, \strlen(GateFiles::projectDir()))), '/');
        }

        sort($directories);

        return array_values(array_unique($directories));
    }

    /**
     * Les fichiers des sources déclarées, découpés en lignes numérotées.
     *
     * @return array<string, array<int, string>> chemin relatif => lignes (base 1)
     */
    private static function sourceLines(): array
    {
        $files = [];

        foreach (self::declaredSources() as $directory) {
            foreach (self::finderFor($directory) as $file) {
                $relative = trim(str_replace('\\', '/', substr($file->getPathname(), \strlen(GateFiles::projectDir()))), '/');
                $lines = preg_split('/\R/', (string) file_get_contents($file->getPathname()));

                if (false === $lines) {
                    continue;
                }

                $numbered = [];

                foreach ($lines as $index => $line) {
                    $numbered[$index + 1] = $line;
                }

                $files[$relative] = $numbered;
            }
        }

        ksort($files);

        return $files;
    }

    private static function finderFor(string $directory): Finder
    {
        return new Finder()
            ->files()
            ->in(GateFiles::projectDir().'/'.$directory)
            ->name(['*.twig', '*.php', '*.js']);
    }

    /**
     * Les feuilles de style du socle, que les `@source` de Tailwind ne déclarent pas.
     *
     * `@source` dit à Tailwind **où chercher des classes**, pas où vivent les règles CSS :
     * `assets/styles/` n'y figure donc pas, et ne peut pas y figurer. Or c'est le premier
     * endroit où l'on écrit `outline: none` — celui que la règle 4 vise en priorité. Elles
     * sont donc listées ici, à côté de la liste lue, avec la raison pour laquelle elles ne
     * pouvaient pas en venir.
     *
     * @var list<string>
     */
    private const array STYLE_SHEETS = [ThemeSheet::THEME, ThemeSheet::APP, ThemeSheet::BRAND];

    /**
     * Tout ce que la règle 4 doit lire : les sources déclarées **et** les feuilles de
     * style. Contenu entier, commentaires de bloc neutralisés pour le CSS — un commentaire
     * qui explique la règle n'est pas une infraction à la règle.
     *
     * @return array<string, string> chemin relatif => contenu
     */
    private static function focusSources(): array
    {
        $files = [];

        foreach (self::sourceLines() as $file => $lines) {
            $files[$file] = implode("\n", $lines);
        }

        foreach (self::STYLE_SHEETS as $sheet) {
            $files[$sheet] = ThemeSheet::stripComments(GateFiles::read($sheet));
        }

        ksort($files);

        return $files;
    }

    /**
     * La règle CSS qui entoure une position — son sélecteur et son corps.
     */
    private static function ruleAround(string $contents, int $offset): string
    {
        $before = substr($contents, 0, $offset);
        $open = strrpos($before, '{');

        if (false === $open) {
            return $contents;
        }

        $selectorStart = 0;

        foreach (['}', '{', ';'] as $boundary) {
            $found = strrpos(substr($before, 0, $open), $boundary);

            if (false !== $found && $found + 1 > $selectorStart) {
                $selectorStart = $found + 1;
            }
        }

        $depth = 1;
        $length = \strlen($contents);
        $end = $open + 1;

        while ($end < $length && $depth > 0) {
            $depth += match ($contents[$end]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            ++$end;
        }

        return substr($contents, $selectorStart, $end - $selectorStart);
    }

    /**
     * La liste de classes qui entoure une position — la chaîne entre guillemets qui porte
     * l'utilitaire. À défaut de guillemets, la ligne : mieux vaut une portée trop étroite,
     * qui signale, qu'une portée trop large, qui excuse.
     */
    private static function classListAround(string $contents, int $offset): string
    {
        $before = substr($contents, 0, $offset);

        $open = null;

        foreach (['"', "'"] as $quote) {
            $found = strrpos($before, $quote);

            if (false !== $found && (null === $open || $found > $open)) {
                $open = $found;
            }
        }

        if (null === $open) {
            return self::lineOf($contents, $offset);
        }

        $end = strpos($contents, $contents[$open], $offset);

        if (false === $end) {
            return self::lineOf($contents, $offset);
        }

        return substr($contents, $open, $end - $open + 1);
    }

    /**
     * L'anneau est-il remplacé dans cette portée ?
     *
     * En CSS, le remplacement est une déclaration qui dessine réellement quelque chose —
     * un `box-shadow`, ou un second `outline` non nul. Le sélecteur ne compte pas : écrire
     * `:focus-visible { outline: none }` n'est pas un remplacement, c'est la suppression
     * la mieux déguisée. Côté Tailwind, c'est l'utilitaire `focus-visible:*` de la même
     * liste de classes.
     */
    private static function replacesTheFocusRing(string $scope, bool $isStyleSheet): bool
    {
        if ($isStyleSheet) {
            return 1 === preg_match('/box-shadow\s*:\s*(?!none)\S|outline\s*:\s*(?!none)\S/', $scope);
        }

        return 1 === preg_match('/focus-visible:(?:ring|outline|border|shadow)|:focus-visible/', $scope);
    }

    private static function lineAt(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }

    private static function lineOf(string $contents, int $offset): string
    {
        $start = strrpos(substr($contents, 0, $offset), "\n");
        $start = false === $start ? 0 : $start + 1;
        $end = strpos($contents, "\n", $offset);

        return false === $end ? substr($contents, $start) : substr($contents, $start, $end - $start);
    }

    /**
     * Le thème clair et le thème sombre, chacun complété par le clair : le bloc sombre ne
     * redéclare que ce qui change, et une variable absente y garde donc sa valeur claire.
     *
     * @return array<string, array<string, string>>
     */
    private static function themes(): array
    {
        $light = ThemeSheet::block(ThemeSheet::THEME, ThemeSheet::LIGHT);

        return [
            'clair' => $light,
            'sombre' => array_merge($light, ThemeSheet::block(ThemeSheet::THEME, ThemeSheet::SYSTEM_DARK)),
        ];
    }

    /**
     * Les jetons que `theme.css` nomme comme un défaut du kit conservé sciemment — un
     * jeton en tête d'une ligne d'un commentaire qui écrit « choix assumé ».
     *
     * @return list<string>
     */
    private static function assumedDefaults(): array
    {
        preg_match_all('#/\*.*?\*/#s', GateFiles::read(ThemeSheet::THEME), $blocks);

        $named = [];

        foreach ($blocks[0] as $block) {
            if (!str_contains($block, 'choix assumé')) {
                continue;
            }

            preg_match_all('/^\s*\*\s+(?P<token>--[a-z0-9-]+)\s+\S/m', $block, $tokens);

            foreach ($tokens['token'] as $token) {
                $named[] = strtolower($token);
            }
        }

        return array_values(array_unique($named));
    }

    /**
     * @param list<string> $offences
     */
    private static function report(string $rule, array $offences): string
    {
        return \sprintf("Plancher d'accessibilité — %s :\n%s", $rule, implode("\n", $offences));
    }
}
