<?php

declare(strict_types=1);

namespace App\Core\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Ce que la page de connexion a le droit de dire d'un échec — deux phrases, pas une de
 * plus.
 *
 * C'est le seul endroit qui traduit une exception d'authentification en clé de message,
 * et il existe parce que la page est rendue par **deux** chemins : le contrôleur en GET,
 * l'écouteur d'échec en 422. Deux traductions de la même exception, c'est deux façons de
 * fuiter.
 *
 * **La règle est celle du refus indifférencié.** Tout ce qui n'est pas un refus de statut
 * de compte — mot de passe faux, email inconnu, jeton CSRF absent ou périmé, champ vide —
 * reçoit le message générique. Symfony y aide déjà : avec `expose_security_errors` à sa
 * valeur par défaut, une `UserNotFoundException` est remplacée par une
 * `BadCredentialsException` avant d'arriver ici, donc même le type de l'exception ne dit
 * pas si le compte existe.
 *
 * La seule exception qui survit à ce masquage est la
 * `CustomUserMessageAccountStatusException` que `UserChecker` lève — c'est ce qui rend le
 * message « compte désactivé » possible, et c'est aussi ce qui en fixe le prix : un tiers
 * peut apprendre qu'un compte désactivé existe pour une adresse. Le critère demande un
 * message dédié, et il n'y a pas de message dédié sans cette divulgation.
 *
 * Cette classe vit dans `src/Core/Security/` et n'y construit **aucune** `Response` : le
 * ruleset `Security:` de `deptrac.yaml` n'autorise que `Security, Entity, Dto, Enum`, donc
 * la couche `Http` lui est fermée. C'est pour cette même raison que le 422 est posé par un
 * écouteur et non par un `failure_handler`.
 */
final readonly class LoginFailureMessage
{
    /**
     * « Email ou mot de passe incorrect. » — Voice and Tone, au mot près.
     */
    public const string INVALID_CREDENTIALS = 'security.login.error.invalid_credentials';

    /**
     * « Ce compte est désactivé. Adressez-vous à votre administrateur. ».
     */
    public const string ACCOUNT_DISABLED = 'security.login.error.account_disabled';

    /**
     * La clé de traduction à afficher, ou `null` quand il n'y a rien à afficher.
     */
    public static function of(?AuthenticationException $exception): ?string
    {
        if (null === $exception) {
            return null;
        }

        if ($exception instanceof CustomUserMessageAccountStatusException
            && self::ACCOUNT_DISABLED === $exception->getMessageKey()
        ) {
            return self::ACCOUNT_DISABLED;
        }

        return self::INVALID_CREDENTIALS;
    }
}
