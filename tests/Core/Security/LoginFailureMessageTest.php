<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use App\Core\Security\LoginFailureMessage;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

/**
 * La règle « quelle exception donne quel message », sans conteneur.
 *
 * Elle était jusqu'ici prouvée uniquement à travers `WebTestCase`, ce qui laissait une
 * branche entière hors de portée : le ralentissement **sans** secondes, celui qu'emprunte
 * `SecurityController` quand il relit l'erreur laissée en session et n'a aucun limiteur
 * sous la main. Un test fonctionnel ne peut pas y accéder — l'écouteur consomme cette
 * erreur avant que le contrôleur ne la voie.
 *
 * C'est aussi ici que le **plancher à une seconde** est épinglé. Il a été déplacé de
 * l'écouteur vers cette classe pour cette raison précise : dans l'écouteur, il était une
 * expression qu'aucun test ne pouvait distinguer de sa propre copie ; ici, c'est une
 * règle, et la supprimer rougit.
 */
final class LoginFailureMessageTest extends TestCase
{
    #[Test]
    public function no_exception_means_nothing_to_display(): void
    {
        self::assertNull(
            LoginFailureMessage::of(null),
            'Une page de connexion ouverte sans erreur ne doit porter aucun message.',
        );
    }

    #[Test]
    public function a_disabled_account_gets_its_own_message(): void
    {
        $message = LoginFailureMessage::of(new CustomUserMessageAccountStatusException(LoginFailureMessage::ACCOUNT_DISABLED));

        self::assertNotNull($message);
        self::assertSame(LoginFailureMessage::ACCOUNT_DISABLED, $message->key);
        self::assertNull($message->retryAfterSeconds, 'Un compte désactivé n\'attend aucun délai : rien ne se débloquera tout seul.');
    }

    /**
     * Le refus indifférencié : tout le reste reçoit le même message. Le sous-type ne
     * change rien — c'est ce qui empêche la page de dire si le compte existe.
     */
    #[Test]
    #[DataProvider('refusalsThatMustLookAlike')]
    public function every_other_refusal_gets_the_generic_message(AuthenticationException $exception): void
    {
        $message = LoginFailureMessage::of($exception);

        self::assertNotNull($message);
        self::assertSame(LoginFailureMessage::INVALID_CREDENTIALS, $message->key);
        self::assertNull($message->retryAfterSeconds);
    }

    /**
     * @return Generator<string, array{AuthenticationException}>
     */
    public static function refusalsThatMustLookAlike(): Generator
    {
        yield 'mot de passe faux' => [new BadCredentialsException()];
        yield 'statut de compte inconnu de cette classe' => [new CustomUserMessageAccountStatusException('quelque.autre.cle')];
        yield 'échec générique' => [new AuthenticationException()];
    }

    /**
     * Le ralentissement **garde** le message générique et lui ajoute le délai : dire
     * « vous êtes ralenti » à la place révélerait qu'un identifiant a été essayé cinq fois.
     */
    #[Test]
    public function a_throttled_refusal_keeps_the_generic_message_and_carries_the_delay(): void
    {
        $message = LoginFailureMessage::of(new TooManyLoginAttemptsAuthenticationException(1), 40);

        self::assertNotNull($message);
        self::assertSame(LoginFailureMessage::INVALID_CREDENTIALS, $message->key);
        self::assertSame(40, $message->retryAfterSeconds);
    }

    /**
     * La branche du contrôleur : l'exception est connue, le délai ne l'est pas. Le message
     * générique seul reste vrai — il est seulement moins utile —, là où inventer un nombre
     * serait faux.
     */
    #[Test]
    public function a_throttled_refusal_without_a_delay_says_nothing_of_a_delay(): void
    {
        $message = LoginFailureMessage::of(new TooManyLoginAttemptsAuthenticationException(1));

        self::assertNotNull($message);
        self::assertSame(LoginFailureMessage::INVALID_CREDENTIALS, $message->key);
        self::assertNull($message->retryAfterSeconds, 'Sans limiteur sous la main, aucun nombre ne doit être inventé.');
    }

    /**
     * Le plancher. La soustraction faite par l'appelant peut rendre zéro — la date du
     * limiteur est arrondie à la seconde inférieure — voire un nombre négatif si la
     * fenêtre expire entre le calcul et la lecture de l'horloge.
     */
    #[Test]
    #[DataProvider('delaysThatMustNotBeShownAsIs')]
    public function a_delay_is_never_shown_as_zero_or_negative(int $computed): void
    {
        $message = LoginFailureMessage::of(new TooManyLoginAttemptsAuthenticationException(1), $computed);

        self::assertNotNull($message);
        self::assertSame(
            1,
            $message->retryAfterSeconds,
            \sprintf('« Patientez %d secondes » est faux et absurde : le plancher à une seconde a disparu.', $computed),
        );
    }

    /**
     * @return Generator<string, array{int}>
     */
    public static function delaysThatMustNotBeShownAsIs(): Generator
    {
        yield 'la fenêtre expire dans la seconde courante' => [0];
        yield 'la fenêtre a expiré pendant le rendu' => [-3];
        yield 'une seconde, qui est déjà le plancher' => [1];
    }
}
