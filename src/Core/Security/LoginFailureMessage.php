<?php

declare(strict_types=1);

namespace App\Core\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

/**
 * Ce que la page de connexion a le droit de dire d'un échec — deux phrases, pas une de
 * plus.
 *
 * C'est le seul endroit qui traduit une exception d'authentification en clé de message,
 * et il existe parce que la page est rendue par **deux** chemins : le contrôleur en GET,
 * l'écouteur d'échec en 422 ou en 429. Deux traductions de la même exception, c'est deux
 * façons de fuiter.
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
 * **Le ralentissement ne change pas le message, il l'allonge** (story 1.7). Un refus
 * ralenti garde le message générique — dire « vous êtes ralenti » à la place révélerait
 * qu'un identifiant a été essayé cinq fois, donc qu'il intéresse quelqu'un — et lui ajoute
 * le délai restant. Le délai est un **nombre de secondes déjà calculé** : cette classe ne
 * voit ni la requête, ni le limiteur, ni l'horloge.
 *
 * Cette classe vit dans `src/Core/Security/` et n'y construit **aucune** `Response` : le
 * ruleset `Security:` de `deptrac.yaml` n'autorise que `Security, Entity, Dto, Enum`, donc
 * la couche `Http` lui est fermée. C'est pour cette même raison que le 422 est posé par un
 * écouteur et non par un `failure_handler`, et que c'est cet écouteur — et non cette
 * classe — qui lit `peek()` sur le limiteur.
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
     * @param string   $key               La clé de traduction de la phrase principale
     * @param int|null $retryAfterSeconds Le délai restant, ou `null` hors ralentissement
     */
    private function __construct(
        public string $key,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    /**
     * Le message à afficher, ou `null` quand il n'y a rien à afficher.
     *
     * `$retryAfterSeconds` n'est retenu que pour un refus de débit : le passer sur un
     * autre échec ferait apparaître un compte à rebours là où rien n'attend.
     *
     * **Le plancher à une seconde est ici, et pas dans l'écouteur.** Il n'est pas
     * cosmétique : le limiteur rend une date arrondie à la seconde inférieure, donc la
     * soustraction faite par l'appelant peut valoir zéro — voire un nombre négatif si la
     * fenêtre expire entre le calcul et la lecture de l'horloge. « Patientez 0 seconde »
     * serait à la fois faux et absurde. Une seconde de trop est la bonne erreur : le
     * bouton reste actif et la soumission suivante recalcule. Étant une règle, il vit dans
     * la classe qui porte les règles, où un `TestCase` sans conteneur l'épingle.
     */
    public static function of(?AuthenticationException $exception, ?int $retryAfterSeconds = null): ?self
    {
        if (null === $exception) {
            return null;
        }

        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            // Le délai peut manquer : le contrôleur relit l'erreur laissée en session et
            // n'a aucun limiteur sous la main. Le message générique seul reste vrai — il
            // est simplement moins utile —, là où inventer un nombre serait faux.
            return new self(self::INVALID_CREDENTIALS, null === $retryAfterSeconds ? null : max(1, $retryAfterSeconds));
        }

        if ($exception instanceof CustomUserMessageAccountStatusException
            && self::ACCOUNT_DISABLED === $exception->getMessageKey()
        ) {
            return new self(self::ACCOUNT_DISABLED);
        }

        return new self(self::INVALID_CREDENTIALS);
    }
}
