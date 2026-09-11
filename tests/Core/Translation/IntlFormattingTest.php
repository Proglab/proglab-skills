<?php

declare(strict_types=1);

namespace App\Tests\Core\Translation;

use App\Core\Enum\SupportedLocale;
use App\Tests\Core\Quality\GateFiles;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use NumberFormatter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * Les dates et les nombres sortent dans la locale de la requête, et pas en anglais.
 *
 * **Ce que ce test ne peut pas encore prouver, et il faut le lire avant le reste.**
 * Aucun écran du socle ne rend aujourd'hui une date ni un nombre : l'accueil affiche un
 * titre et une liste de noms de modules. Le formatage est donc vérifié sur un template
 * rendu **en test**, pas sur une page du socle. L'écart est réel — il se referme à la
 * première story qui affiche une date (la pastille de dernier déploiement de l'Epic 3,
 * l'horodatage du journal d'audit de l'Epic 4) et ce test devra alors regarder la page.
 *
 * Le piège que ce test existe pour fermer est silencieux : `symfony/intl` sans
 * `ext-intl` ne sait formater qu'en anglais. Sans lui, une date néerlandaise sortirait
 * en anglais, sans erreur, et n'importe quel critère « la page est en néerlandais »
 * resterait vert.
 */
final class IntlFormattingTest extends KernelTestCase
{
    /**
     * Un mardi soir, dans le fuseau du serveur client.
     */
    private const string MOMENT = '2026-09-11 18:42:00';

    private const float AMOUNT = 1234567.89;

    #[Test]
    public function the_intl_extension_is_loaded(): void
    {
        self::assertTrue(
            \extension_loaded('intl'),
            '`ext-intl` est absent : `symfony/intl` se replie alors sur des données anglaises et formate tout en anglais, en silence.',
        );
    }

    /**
     * `ext-intl` déclaré dans `composer.json` casse le `composer install` de tout job de
     * CI qui ne l'installe pas — et deux des six ne l'installaient pas.
     */
    #[Test]
    public function every_ci_job_installs_the_intl_extension_that_composer_requires(): void
    {
        self::assertStringContainsString(
            '"ext-intl"',
            GateFiles::read('composer.json'),
            '`ext-intl` n\'est plus une dépendance déclarée : rien ne garantit qu\'il soit là où le code tourne.',
        );

        $offences = [];

        foreach (array_values(GateFiles::jobIdsByName()) as $jobId) {
            $extensions = self::phpExtensionsOf($jobId);

            if (!str_contains($extensions, 'intl')) {
                $offences[] = $jobId;
            }
        }

        self::assertSame(
            [],
            $offences,
            'Ce job de CI n\'installe pas `intl` alors que `composer.json` l\'exige : son `composer install` échoue avant d\'avoir rien vérifié.',
        );
    }

    /**
     * Trois locales, une seule date : trois sorties correctes et distinctes.
     */
    #[Test]
    public function a_date_is_rendered_in_the_locale_of_the_request(): void
    {
        $rendered = [];

        foreach (SupportedLocale::cases() as $locale) {
            $rendered[$locale->value] = self::render(
                '{{ moment|format_date(\'long\') }}',
                $locale,
            );
        }

        self::assertStringContainsString('septembre', $rendered['fr'], 'La date française ne sort pas en français.');
        self::assertStringContainsString('September', $rendered['en'], 'La date anglaise ne sort pas en anglais.');
        self::assertStringContainsString('september', $rendered['nl'], 'La date néerlandaise ne sort pas en néerlandais.');

        self::assertCount(
            \count(SupportedLocale::cases()),
            array_unique(array_values($rendered)),
            \sprintf('Deux locales rendent la même date — le formatage ignore la locale : %s', json_encode($rendered, \JSON_UNESCAPED_UNICODE)),
        );

        foreach (SupportedLocale::cases() as $locale) {
            self::assertSame(
                self::icuDate($locale),
                $rendered[$locale->value],
                \sprintf('Le rendu Twig en « %s » ne correspond pas à ce qu\'ICU produit pour cette locale.', $locale->value),
            );
        }
    }

    #[Test]
    public function a_number_is_rendered_in_the_locale_of_the_request(): void
    {
        $rendered = [];

        foreach (SupportedLocale::cases() as $locale) {
            $rendered[$locale->value] = self::render('{{ amount|format_number }}', $locale);
        }

        // Les séparateurs de groupe varient d'une version d'ICU à l'autre (le français
        // est passé à l'espace fine insécable) : on affirme donc sur ce qui ne bouge
        // pas, la virgule ou le point décimal.
        self::assertStringEndsWith('567,89', $rendered['fr'], 'Le nombre français n\'utilise pas la virgule décimale.');
        self::assertSame('1,234,567.89', $rendered['en'], 'Le nombre anglais n\'utilise pas le point décimal et la virgule de groupe.');
        self::assertSame('1.234.567,89', $rendered['nl'], 'Le nombre néerlandais n\'utilise pas le point de groupe et la virgule décimale.');

        self::assertCount(
            \count(SupportedLocale::cases()),
            array_unique(array_values($rendered)),
            \sprintf('Deux locales rendent le même nombre — le formatage ignore la locale : %s', json_encode($rendered, \JSON_UNESCAPED_UNICODE)),
        );

        foreach (SupportedLocale::cases() as $locale) {
            self::assertSame(
                self::icuNumber($locale),
                $rendered[$locale->value],
                \sprintf('Le rendu Twig en « %s » ne correspond pas à ce qu\'ICU produit pour cette locale.', $locale->value),
            );
        }
    }

    // ------------------------------------------------------------------

    /**
     * Le template est rendu avec la locale posée sur une requête, comme une page l'aurait
     * — c'est `Request::setLocale()` qui aligne la locale PHP par défaut, et c'est elle
     * que lisent les filtres Intl de Twig.
     */
    private static function render(string $template, SupportedLocale $locale): string
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $container = self::getContainer();

        $request = Request::create('/');
        $request->setLocale($locale->value);
        $container->get('request_stack')->push($request);

        /** @var Environment $twig */
        $twig = $container->get('twig');

        return $twig->createTemplate($template)->render([
            'moment' => self::moment(),
            'amount' => self::AMOUNT,
        ]);
    }

    private static function moment(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::MOMENT, new DateTimeZone('Europe/Brussels'));
    }

    private static function icuDate(SupportedLocale $locale): string
    {
        $formatter = new IntlDateFormatter(
            $locale->value,
            IntlDateFormatter::LONG,
            IntlDateFormatter::NONE,
            new DateTimeZone('Europe/Brussels'),
        );

        $formatted = $formatter->format(self::moment());

        if (!\is_string($formatted)) {
            throw new RuntimeException(\sprintf('ICU n\'a pas su formater la date en « %s ».', $locale->value));
        }

        return $formatted;
    }

    private static function icuNumber(SupportedLocale $locale): string
    {
        $formatted = new NumberFormatter($locale->value, NumberFormatter::DECIMAL)->format(self::AMOUNT);

        if (!\is_string($formatted)) {
            throw new RuntimeException(\sprintf('ICU n\'a pas su formater le nombre en « %s ».', $locale->value));
        }

        return $formatted;
    }

    /**
     * Les extensions PHP que le job installe, telles que `shivammathur/setup-php` les
     * reçoit.
     */
    private static function phpExtensionsOf(string $jobId): string
    {
        $steps = GateFiles::job($jobId)['steps'] ?? null;

        if (!\is_array($steps)) {
            throw new RuntimeException(\sprintf('Le job « %s » n\'a aucune étape.', $jobId));
        }

        foreach ($steps as $step) {
            if (!\is_array($step)) {
                continue;
            }

            $uses = $step['uses'] ?? null;

            if (!\is_string($uses) || !str_starts_with($uses, 'shivammathur/setup-php')) {
                continue;
            }

            $with = $step['with'] ?? null;
            $extensions = \is_array($with) ? $with['extensions'] ?? null : null;

            return \is_string($extensions) ? $extensions : '';
        }

        throw new RuntimeException(\sprintf('Le job « %s » n\'installe aucun PHP : il ne peut pas exécuter la porte.', $jobId));
    }
}
