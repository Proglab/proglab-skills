<?php

declare(strict_types=1);

namespace App\Tests\Core\Translation;

use App\Core\Entity\Language;
use App\Core\Enum\SupportedLocale;
use App\Core\Repository\LanguageRepository;
use App\Tests\Core\Quality\GateFiles;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Yaml\Yaml;

/**
 * La parité des catalogues, et la coïncidence des trois déclarations de langue.
 *
 * Le premier critère de la story — « tout texte d'interface existe en français, en
 * anglais et en néerlandais » — n'est pas vérifiable par relecture : un catalogue se
 * désaligne une clé à la fois, et la clé manquante se rend en affichant son propre nom.
 * Ce test parcourt donc **tous** les catalogues du dépôt, ceux du socle comme ceux d'un
 * module, et refuse toute clé qui manquerait ou serait vide dans l'une des trois langues.
 *
 * Il tient aussi la seconde promesse de l'AD-14, celle qui se casse en silence : les
 * langues **supportées** sont déclarées en code (l'enum), la compilation des catalogues
 * les liste toutes (`enabled_locales`), et les langues **actives** sont des lignes en
 * base. Trois déclarations, une seule vérité — donc un test qui les met face à face.
 *
 * Aucune catégorie n'est ajoutée à la porte de qualité : la parité appartient à
 * « Tests », comme le dit la spec.
 */
final class CatalogParityTest extends KernelTestCase
{
    /**
     * Les racines où un catalogue peut vivre : exactement celles que
     * `config/packages/translation.yaml` donne au translator — le `default_path` du socle,
     * puis la racine des modules et son miroir de fixtures.
     *
     * Elles sont balayées **récursivement**, parce que le Finder du framework les balaie
     * ainsi : un module qui range ses catalogues sous `Resources/translations/` est
     * compilé et servi, donc il doit être contrôlé. Un scanner qui regarde moins que le
     * translator est un scanner qui déclare la parité tenue sur des fichiers qu'il n'a
     * jamais vus.
     */
    private const array ROOTS = [
        'translations',
        'src/Module',
        'tests/Fixtures/Module',
    ];

    /**
     * Les extensions que le translator charge réellement pour un catalogue de ce socle :
     * les deux orthographes du YAML, et le XLIFF que Symfony lit nativement.
     */
    private const array EXTENSIONS = ['yaml', 'yml', 'xlf'];

    #[Test]
    public function every_catalogue_ships_a_file_for_each_supported_language(): void
    {
        $offences = [];

        foreach (self::catalogues() as $catalogue) {
            foreach (SupportedLocale::codes() as $code) {
                if (!isset($catalogue['files'][$code])) {
                    $offences[] = \sprintf(
                        'domaine « %s », locale « %s » : « %s/%s.%s.{%s} » est absent.',
                        $catalogue['domain'],
                        $code,
                        $catalogue['dir'],
                        $catalogue['domain'],
                        $code,
                        implode(',', self::EXTENSIONS),
                    );
                }
            }
        }

        self::assertSame([], $offences, self::report($offences));
    }

    /**
     * Une clé absente d'une des trois langues se rend en affichant son propre nom —
     * « home.index.title » au milieu d'une page. Une clé présente mais vide rend une
     * chaîne vide, ce qui est pire : rien ne se voit.
     */
    #[Test]
    public function every_key_is_present_and_filled_in_each_supported_language(): void
    {
        $offences = [];
        $source = SupportedLocale::codes()[0];

        foreach (self::catalogues() as $catalogue) {
            if (!isset($catalogue['files'][$source])) {
                continue; // Déjà signalé par le test ci-dessus.
            }

            $expected = array_keys(self::keysOf($catalogue['files'][$source]));

            foreach (SupportedLocale::codes() as $code) {
                if (!isset($catalogue['files'][$code])) {
                    continue;
                }

                $actual = self::keysOf($catalogue['files'][$code]);

                foreach ($expected as $key) {
                    if (!\array_key_exists($key, $actual)) {
                        $offences[] = \sprintf(
                            'domaine « %s », locale « %s » : la clé « %s » est absente.',
                            $catalogue['domain'],
                            $code,
                            $key,
                        );

                        continue;
                    }

                    if ('' === trim($actual[$key])) {
                        $offences[] = \sprintf(
                            'domaine « %s », locale « %s » : la clé « %s » est vide.',
                            $catalogue['domain'],
                            $code,
                            $key,
                        );
                    }
                }

                foreach (array_keys($actual) as $key) {
                    if (!\in_array($key, $expected, true)) {
                        $offences[] = \sprintf(
                            'domaine « %s », locale « %s » : la clé « %s » n\'existe pas dans la langue source « %s ».',
                            $catalogue['domain'],
                            $code,
                            $key,
                            $source,
                        );
                    }
                }
            }
        }

        self::assertSame([], $offences, self::report($offences));
    }

    /**
     * Un catalogue posé dans une langue que l'enum ne déclare pas n'est compilé par
     * personne : `enabled_locales` ferme la liste, et le fichier reste lettre morte sans
     * que rien ne le dise.
     */
    #[Test]
    public function no_catalogue_ships_a_language_the_enum_does_not_declare(): void
    {
        $offences = [];

        foreach (self::catalogues() as $catalogue) {
            foreach (array_keys($catalogue['files']) as $code) {
                if (!\in_array($code, SupportedLocale::codes(), true)) {
                    $offences[] = \sprintf(
                        'domaine « %s », locale « %s » : cette langue n\'est pas supportée — l\'enum des locales est la seule déclaration.',
                        $catalogue['domain'],
                        $code,
                    );
                }
            }
        }

        self::assertSame([], $offences, self::report($offences));
    }

    /**
     * Le test existe pour lui-même : un scanner qui ne trouve rien est vert, et la
     * parité serait « vérifiée » sur zéro catalogue.
     */
    #[Test]
    public function the_scanner_actually_finds_the_catalogues_of_the_socle_and_of_a_module(): void
    {
        $domains = array_map(
            static fn (array $catalogue): string => $catalogue['domain'],
            self::catalogues(),
        );

        self::assertContains('messages', $domains, 'Le catalogue du socle est introuvable : la parité ne regarde rien.');
        self::assertContains('demo', $domains, 'Le catalogue du module de démonstration est introuvable : « un module livre ses catalogues » n\'est plus vérifié.');
    }

    /**
     * Les trois déclarations de langue, face à face.
     *
     * `enabled_locales` reste la liste **complète** des langues supportées : « actif »
     * filtre l'offre, jamais la compilation des catalogues. C'est la tension que la revue
     * d'architecture demandait d'écrire noir sur blanc, et elle est écrite ici.
     */
    #[Test]
    public function the_enum_the_enabled_locales_and_the_seeded_rows_coincide(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $supported = SupportedLocale::codes();

        self::assertSame(
            $supported,
            $container->getParameter('kernel.enabled_locales'),
            'Le `enabled_locales` du framework a dérivé de l\'enum des langues supportées : un catalogue cesserait d\'être compilé sans que rien ne le dise.',
        );

        self::assertSame(
            $supported[0],
            $container->getParameter('kernel.default_locale'),
            'La langue source du socle n\'est plus la première de l\'enum : l\'ordre de repli et la langue par défaut ne diraient plus la même chose.',
        );

        /** @var LanguageRepository $languages */
        $languages = $container->get(LanguageRepository::class);

        $seeded = array_map(
            static fn (Language $language): string => $language->getCode()->value,
            $languages->findAll(),
        );

        sort($seeded);
        $expected = $supported;
        sort($expected);

        self::assertSame(
            $expected,
            $seeded,
            'La table des langues ne porte pas exactement les langues supportées : la migration de semis et l\'enum ont divergé.',
        );
    }

    /**
     * « Les trois sont actives à l'installation » — le critère, lu sur la table qu'une
     * migration vient de remplir.
     */
    #[Test]
    public function a_freshly_migrated_clone_has_the_three_languages_active(): void
    {
        self::bootKernel();

        /** @var LanguageRepository $languages */
        $languages = self::getContainer()->get(LanguageRepository::class);

        $disabled = array_values(array_map(
            static fn (Language $language): string => $language->getCode()->value,
            array_filter(
                $languages->findAll(),
                static fn (Language $language): bool => !$language->isEnabled(),
            ),
        ));

        self::assertSame([], $disabled, 'Une langue est semée inactive : les trois doivent être actives à l\'installation.');
    }

    // ------------------------------------------------------------------
    // Lecture des catalogues

    /**
     * @return list<array{domain: string, dir: string, files: array<string, string>}>
     */
    private static function catalogues(): array
    {
        $roots = array_values(array_filter(
            array_map(
                static fn (string $root): string => GateFiles::projectDir().'/'.$root,
                self::ROOTS,
            ),
            is_dir(...),
        ));

        if ([] === $roots) {
            return [];
        }

        $found = new Finder()
            ->files()
            ->in($roots)
            ->name(array_map(static fn (string $extension): string => '*.'.$extension, self::EXTENSIONS))
            ->sortByName();

        /** @var array<string, array<string, array<string, string>>> $byDir */
        $byDir = [];

        foreach ($found as $file) {
            // Deux points, comme le Finder du framework : `domaine.locale.extension`.
            if (1 !== preg_match('/^(?P<domain>[^.]+)\.(?P<locale>[^.]+)\.[^.]+$/', $file->getFilename(), $matches)) {
                continue;
            }

            $dir = str_replace(GateFiles::projectDir().'/', '', str_replace('\\', '/', $file->getPath()));

            $byDir[$dir][$matches['domain']][$matches['locale']] = str_replace('\\', '/', $file->getPathname());
        }

        $catalogues = [];

        foreach ($byDir as $dir => $domains) {
            foreach ($domains as $domain => $localeFiles) {
                $catalogues[] = [
                    'domain' => $domain,
                    'dir' => $dir,
                    'files' => $localeFiles,
                ];
            }
        }

        return $catalogues;
    }

    /**
     * Les clés d'un catalogue, aplaties en clés pointées — la forme sous laquelle un
     * template les demande au translator.
     *
     * @return array<string, string>
     */
    private static function keysOf(string $file): array
    {
        if (str_ends_with($file, '.xlf')) {
            // Un catalogue XLIFF est relu par le loader du framework plutôt qu'à la main :
            // c'est lui qui en tire les identifiants, et une seconde façon de les lire
            // serait une seconde vérité.
            $flat = [];

            foreach (new XliffFileLoader()->load($file, 'fr', 'catalogue')->all('catalogue') as $key => $value) {
                $flat[(string) $key] = \is_scalar($value) ? (string) $value : '';
            }

            return $flat;
        }

        $parsed = Yaml::parseFile($file);

        if (null === $parsed) {
            return [];
        }

        if (!\is_array($parsed)) {
            throw new RuntimeException(\sprintf('« %s » n\'est pas un catalogue : la racine du YAML n\'est pas une table.', $file));
        }

        return self::flatten($parsed, '');
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, string>
     */
    private static function flatten(array $values, string $prefix): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;

            if (\is_array($value)) {
                $flat = array_merge($flat, self::flatten($value, $path));

                continue;
            }

            $flat[$path] = \is_scalar($value) ? (string) $value : '';
        }

        return $flat;
    }

    /**
     * @param list<string> $offences
     */
    private static function report(array $offences): string
    {
        if ([] === $offences) {
            return '';
        }

        return "Parité des catalogues rompue :\n  - ".implode("\n  - ", $offences);
    }
}
