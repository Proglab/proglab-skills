<?php

declare(strict_types=1);

namespace App\Tests\Core\Translation;

use App\Core\Enum\SupportedLocale;
use App\Core\Repository\LanguageRepository;
use App\Core\Service\ActiveLocales;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * La mémorisation, et sa fin.
 *
 * `ActiveLocales` n'est pas `readonly` — le seul écart de tout le socle à la règle du
 * standard — et la mémorisation est la seule raison de cet écart. Sans un test qui la
 * mesure, retirer le `??=` laisserait toute la suite verte : la liste serait relue à
 * chaque appel, l'écart au standard n'aurait plus de motif, et personne ne le saurait.
 *
 * L'autre moitié est la contrepartie de la première : une mémoire qui ne s'efface jamais
 * est une liste périmée dès qu'un processus survit à la requête. Le worker Messenger de
 * la story 1.8 est exactement ce processus.
 */
final class ActiveLocalesTest extends TestCase
{
    #[Test]
    public function the_table_is_read_once_for_the_whole_request(): void
    {
        $languages = self::createMock(LanguageRepository::class);
        $languages
            ->expects(self::once())
            ->method('findActiveCodes')
            ->willReturn(SupportedLocale::cases());

        $active = new ActiveLocales($languages);

        $active->all();
        $active->has(SupportedLocale::Nl);
        $active->first();
        $active->all();
    }

    /**
     * `ResetInterface` est ce que l'autoconfiguration traduit en tag `kernel.reset` : un
     * worker qui traite un second message repart d'une mémoire vide.
     */
    #[Test]
    public function resetting_the_service_makes_it_read_the_table_again(): void
    {
        $languages = self::createMock(LanguageRepository::class);
        $languages
            ->expects(self::exactly(2))
            ->method('findActiveCodes')
            ->willReturn([SupportedLocale::Fr]);

        $active = new ActiveLocales($languages);

        self::assertArrayHasKey(
            ResetInterface::class,
            class_implements($active),
            'Sans `ResetInterface`, aucun tag `kernel.reset` n\'est posé et la mémoire survit à la requête.',
        );

        $active->all();
        $active->reset();
        $active->all();
    }
}
