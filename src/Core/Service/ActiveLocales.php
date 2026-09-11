<?php

declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Enum\SupportedLocale;
use App\Core\Repository\LanguageRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Les langues que le socle **offre** réellement, et le repli quand il n'en offre aucune.
 *
 * C'est le seul endroit qui traduit « des lignes en base » en « une liste ordonnée de
 * langues ». Tout le reste du socle — l'écouteur de locale aujourd'hui, le sélecteur de
 * la story 1.6 et l'écran de gestion de la story 2.9 demain — lit cette liste et jamais
 * la table.
 *
 * **Le repli est calculé, jamais écrit.** Zéro ligne active ne déclenche aucune écriture :
 * la liste est vide, et `first()` rend la langue de repli de l'enum — celle qui est aussi
 * `framework.default_locale`. Désactiver la dernière langue ne doit pas réactiver une
 * ligne dans le dos de qui l'a désactivée.
 *
 * **Pourquoi cette classe n'est pas `readonly`, contrairement à la règle du standard.**
 * Le spec demande un `final readonly` dont le résultat est « mémorisé pour la requête ».
 * Les deux sont incompatibles en PHP : une classe `readonly` ne peut assigner aucune
 * propriété après construction, donc aucune mémorisation n'y est possible. La
 * mémorisation l'emporte, parce que c'est elle qui tient la contrainte « une requête
 * indexée par requête HTTP » — sans elle, l'écouteur, le sélecteur et un futur en-tête
 * de compte interrogeraient la base trois fois pour la même réponse. Aucun cache
 * applicatif n'est posé : la mémoire ne vit que dans l'instance partagée.
 *
 * **Et c'est pour cela que la classe est réinitialisable.** Sous PHP-FPM, l'instance meurt
 * avec la requête et la question ne se pose pas. Dans un worker Messenger — celui de la
 * story 1.8 — ou sous un runtime persistant, elle survit à tout : `ResetInterface`
 * (autoconfiguré en tag `kernel.reset`) fait vider la mémoire entre deux messages, sans
 * quoi un worker servirait indéfiniment la liste qu'il a lue à son démarrage.
 */
final class ActiveLocales implements ResetInterface
{
    /**
     * @var list<SupportedLocale>|null
     */
    private ?array $active = null;

    public function __construct(private readonly LanguageRepository $languages)
    {
    }

    /**
     * Les langues actives, dans l'ordre de repli. Peut être vide.
     *
     * @return list<SupportedLocale>
     */
    public function all(): array
    {
        return $this->active ??= $this->languages->findActiveCodes();
    }

    public function has(SupportedLocale $locale): bool
    {
        return \in_array($locale, $this->all(), true);
    }

    /**
     * La première langue active, ou la langue de repli quand plus rien ne l'est.
     */
    public function first(): SupportedLocale
    {
        return $this->all()[0] ?? SupportedLocale::fallback();
    }

    /**
     * Oublie la liste mémorisée. Le prochain appel relit la table.
     */
    public function reset(): void
    {
        $this->active = null;
    }
}
