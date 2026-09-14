<?php

declare(strict_types=1);

namespace App\Tests\Core\Accessibility;

use App\Core\Entity\User;
use App\Core\Enum\SupportedLocale;
use App\Core\Repository\UserRepository;
use App\Tests\Core\Security\Accounts;
use App\Tests\Core\Security\PasswordTokens;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * De quoi faire rendre un écran à une route qui porte un paramètre.
 *
 * **Pourquoi cette classe existe.** Le balayage du plancher n'acceptait que les routes GET
 * **sans** paramètre : le socle n'en avait aucune, et en inventer une valeur aurait fait
 * rendre une page d'erreur au lieu d'un écran. La story 1.9 en pose la première —
 * `/password/reset/{token}` —, et la laisser dehors la ferait sortir du plancher **en
 * silence**, avec toutes celles des epics suivantes : une classe entière d'écrans du
 * dérivé cesserait d'être vérifiée sans qu'aucun test ne bouge.
 *
 * **La règle est donc inversée : une route à paramètre doit être nommée ici, ou le
 * plancher échoue.** Il n'y a pas de liste d'exemptions, et pas de silence possible — la
 * story qui pose une route à paramètre pose son échantillon en même temps, ou elle voit
 * « Accessibility » rougir en nommant sa route.
 *
 * **Un échantillon peut avoir besoin d'une ligne en base**, et c'est le cas ici : un jeton
 * inventé rendrait la page « lien expiré », qui est une autre page — utile à vérifier, mais
 * pas celle qu'on cherche. Les lignes créées vivent dans la transaction que DAMA annule à
 * la fin de la méthode de test, comme n'importe quelle autre écriture d'un test
 * fonctionnel.
 */
final readonly class RouteSamples
{
    /**
     * L'adresse du compte fabriqué pour les échantillons — distincte de celles des tests
     * fonctionnels, pour que personne ne croie qu'elle est partagée.
     */
    private const string EMAIL = 'plancher@example.test';

    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    /**
     * Les paramètres qui font rendre un écran à cette route, ou `null` si elle n'est pas
     * connue.
     *
     * `null` n'est pas « pas de paramètre » : c'est « personne n'a dit comment rendre
     * cette page », et l'appelant en fait un échec nommé.
     *
     * @return array<string, string>|null
     */
    public static function for(string $route, ContainerInterface $container): ?array
    {
        return match ($route) {
            'app_password_reset' => ['token' => PasswordTokens::issue($container, self::someone($container))],
            default => null,
        };
    }

    /**
     * Un compte actif, créé une fois par méthode de test.
     *
     * **Il est cherché avant d'être créé**, et ce n'est pas une optimisation : le jour où
     * un second bras du `match` ci-dessus aura besoin d'un compte, deux appels dans la
     * même méthode de test rencontreraient la contrainte d'unicité sur `user.email` — et
     * le plancher échouerait sur une clé dupliquée au lieu du message qu'il sait écrire.
     *
     * Il passe par `Accounts` plutôt que par un `new User()` : un compte de plancher dont
     * le mot de passe n'aurait pas traversé le hacheur du projet serait un compte que la
     * production ne sait pas construire, et la page rendue ne serait pas celle qu'un
     * utilisateur voit.
     */
    private static function someone(ContainerInterface $container): User
    {
        $existing = $container->get(UserRepository::class)->findOneByEmail(self::EMAIL);

        if ($existing instanceof User) {
            return $existing;
        }

        try {
            return Accounts::create($container, self::EMAIL, self::PASSWORD, language: SupportedLocale::Fr);
        } catch (Throwable $exception) {
            throw new RuntimeException('Le compte d\'échantillon du plancher n\'a pas pu être créé : les routes à paramètre ne peuvent plus rendre d\'écran.', previous: $exception);
        }
    }
}
