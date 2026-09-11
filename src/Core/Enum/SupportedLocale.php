<?php

declare(strict_types=1);

namespace App\Core\Enum;

/**
 * Les langues **supportées** par le socle — la seule déclaration qui existe (AD-14).
 *
 * Supportée ≠ active. Une langue supportée a des catalogues compilés et peut être
 * proposée ; une langue **active** est une ligne de la table des langues, et c'est elle
 * qui décide de ce que l'application offre réellement (`App\Core\Service\ActiveLocales`).
 * Aucune variable d'environnement ne porte l'une ni l'autre.
 *
 * **L'ordre des cas est l'ordre de repli.** Le français vient d'abord parce que c'est la
 * langue source du socle et la valeur de `framework.default_locale` ; quand plus rien
 * n'est actif, c'est sur lui qu'on retombe. Réordonner ces trois lignes change le
 * comportement de la résolution de locale — `CatalogParityTest` tient la coïncidence
 * entre cet ordre, `enabled_locales` et la langue par défaut.
 *
 * Cet enum vit dans `Core/Enum/` et non dans `Core/Contract/` : rien de cette story ne
 * traverse la porte publique d'un module, et le ruleset `CoreContract: ~` interdit à
 * cette porte de dépendre de quoi que ce soit.
 */
enum SupportedLocale: string
{
    case Fr = 'fr';
    case En = 'en';
    case Nl = 'nl';

    /**
     * Les codes, dans l'ordre de repli — la forme que `enabled_locales` et le translator
     * attendent.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $locale): string => $locale->value, self::cases());
    }

    /**
     * La langue sur laquelle on retombe quand aucune n'est active. C'est la première de
     * l'ordre de repli, et donc la langue par défaut du framework.
     */
    public static function fallback(): self
    {
        return self::cases()[0];
    }
}
