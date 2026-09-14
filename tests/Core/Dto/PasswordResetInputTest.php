<?php

declare(strict_types=1);

namespace App\Tests\Core\Dto;

use App\Core\Dto\Input\PasswordResetInput;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Les règles que portent les deux saisies de la réinitialisation — au niveau où elles
 * vivent.
 *
 * **Pourquoi ici et pas dans un POST HTTP.** La validation d'un DTO est une règle, et une
 * règle se teste sans noyau : un seuil ou un message vérifié à travers six couches HTTP est
 * un test lent qui échoue pour dix raisons différentes. `PasswordResetTest` garde donc de
 * ces cas exactement ce qu'il est seul à pouvoir prouver — que le contrôleur rerend la page
 * en **422** avec le champ marqué, et que le lien reste utilisable.
 *
 * Le validateur est construit à la main, sans conteneur ni translator : `getMessage()` rend
 * alors le message **brut**, donc la clé pour celui que le socle a écrit. C'est ce qu'on
 * veut ici — que la clé soit celle qu'on croit. Que le catalogue la traduise en français
 * est une autre affaire, et c'est `PasswordResetTest` qui lit le texte rendu.
 */
#[CoversClass(PasswordResetInput::class)]
final class PasswordResetInputTest extends TestCase
{
    /**
     * Une phrase de passe évaluée exactement `STRENGTH_MEDIUM` : la borne basse de ce que
     * la décision D-2 accepte.
     */
    private const string AT_THE_THRESHOLD = 'sardine-bleue-42';

    #[Test]
    public function two_matching_entries_at_the_threshold_are_valid(): void
    {
        self::assertSame([], self::violationsOn(self::AT_THE_THRESHOLD, self::AT_THE_THRESHOLD));
    }

    /**
     * Les deux bornes du seuil, et pas deux illustrations.
     *
     * `bateau-jaune-31` est évalué `WEAK` : abaisser le seuil d'un cran le ferait passer.
     * `sardine-bleue-42`, lui, est exactement `MEDIUM` : monter le seuil à `STRONG` le
     * refuserait — c'est le test ci-dessus qui tient ce bout-là. Les deux ensemble épinglent
     * `STRENGTH_MEDIUM` et rien d'autre, ce qui est très précisément ce que D-2 tranche.
     */
    #[Test]
    #[DataProvider('passwordsBelowTheThreshold')]
    public function a_password_below_the_threshold_is_refused_on_the_password_field(string $password): void
    {
        self::assertSame(['password'], self::violationsOn($password, $password));
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function passwordsBelowTheThreshold(): Generator
    {
        yield 'très faible' => ['azerty123'];
        yield 'faible, juste sous le seuil' => ['bateau-jaune-31'];
    }

    #[Test]
    public function an_empty_password_is_refused(): void
    {
        self::assertSame(['password'], self::violationsOn('', ''));
    }

    /**
     * La borne haute, et ce qu'elle évite : au-delà, `UserPasswordHasher` lève une
     * `InvalidPasswordException` que rien ne rattrape sur ce chemin — donc une 500 sur la
     * page de récupération d'accès, exactement là où l'utilisateur est le plus démuni.
     *
     * Le mot de passe est construit en **octets**, comme le hacheur compte
     * (`CheckPasswordLengthTrait`), et la limite est lue sur la constante du composant :
     * un nombre recopié ici cesserait de suivre le jour où elle bouge.
     */
    #[Test]
    public function a_password_longer_than_the_hasher_accepts_is_refused(): void
    {
        $tooLong = str_repeat('a', PasswordHasherInterface::MAX_PASSWORD_LENGTH + 1);

        self::assertSame(['password'], self::violationsOn($tooLong, $tooLong));
    }

    /**
     * Et la borne elle-même passe : la contrainte refuse ce que le hacheur refuse, pas un
     * caractère de moins.
     */
    #[Test]
    public function a_password_of_exactly_the_maximum_length_is_accepted(): void
    {
        // Un motif varié, et pas 4 096 fois la même lettre : celle-là serait refusée par
        // `PasswordStrength` — un seul caractère distinct, donc presque aucune entropie —
        // et le test passerait au vert pour la mauvaise raison.
        $atTheLimit = str_repeat('aB3-', intdiv(PasswordHasherInterface::MAX_PASSWORD_LENGTH, 4));

        self::assertSame(PasswordHasherInterface::MAX_PASSWORD_LENGTH, \strlen($atTheLimit));
        self::assertSame([], self::violationsOn($atTheLimit, $atTheLimit));
    }

    /**
     * `countUnits: COUNT_BYTES` : le hacheur compte des octets, pas des points de code.
     * Sans cette option, une chaîne de 4 096 caractères accentués — donc 8 192 octets —
     * passerait la validation et ferait lever le hacheur juste après.
     */
    #[Test]
    public function the_maximum_is_counted_in_bytes_like_the_hasher_counts(): void
    {
        $accented = str_repeat('é', PasswordHasherInterface::MAX_PASSWORD_LENGTH);

        self::assertSame(
            PasswordHasherInterface::MAX_PASSWORD_LENGTH,
            mb_strlen($accented),
            'Le cas de test ne pèse plus le double en octets : il ne distingue plus les deux façons de compter.',
        );
        self::assertSame(['password'], self::violationsOn($accented, $accented));
    }

    /**
     * L'erreur d'égalité appartient au champ de **confirmation** : c'est celui-là que
     * l'utilisateur doit corriger, et c'est là que le message doit s'afficher.
     */
    #[Test]
    public function a_mismatched_confirmation_is_refused_on_the_confirmation_field(): void
    {
        self::assertSame(['confirmation'], self::violationsOn(self::AT_THE_THRESHOLD, 'autre-chose-entierement'));
    }

    /**
     * **Le message d'égalité est le nôtre, et il n'a aucun paramètre.**.
     *
     * Le défaut d'`EqualTo` est « This value should be equal to {{ compared_value }} », et
     * `{{ compared_value }}` **est le mot de passe saisi** : rendu tel quel, il repartirait
     * dans le HTML du 422, donc dans l'historique du navigateur et dans les caches
     * intermédiaires.
     *
     * La contrainte **pose** ces paramètres dans tous les cas — ce n'est pas ce qu'on
     * regarde. Ce qui compte est que le gabarit du message n'en consomme aucun : c'est le
     * message **rendu** qu'on lit ici, et retirer le `message:` personnalisé du DTO le fait
     * rougir immédiatement.
     */
    #[Test]
    public function the_mismatch_message_never_carries_the_submitted_password(): void
    {
        $violations = self::validator()->validate(new PasswordResetInput(self::AT_THE_THRESHOLD, 'autre-chose-entierement'));

        self::assertCount(1, $violations);

        $violation = $violations->get(0);

        self::assertSame('password.confirmation_mismatch', $violation->getMessageTemplate());

        $rendered = (string) $violation->getMessage();

        self::assertStringNotContainsString(self::AT_THE_THRESHOLD, $rendered, 'Le message d\'égalité réémet le mot de passe saisi.');
        self::assertStringNotContainsString('autre-chose-entierement', $rendered, 'Le message d\'égalité réémet la confirmation saisie.');
    }

    /**
     * Les noms de champs en erreur, dans l'ordre du DTO.
     *
     * Les comparer en tableau plutôt qu'un par un est ce qui prouve aussi l'**absence** des
     * autres : une contrainte qui se déclencherait en trop sur le mauvais champ marquerait
     * une saisie que l'utilisateur n'a pas à corriger.
     *
     * @return list<string>
     */
    private static function violationsOn(string $password, string $confirmation): array
    {
        $fields = [];

        foreach (self::validator()->validate(new PasswordResetInput($password, $confirmation)) as $violation) {
            $fields[] = $violation->getPropertyPath();
        }

        return array_values(array_unique($fields));
    }

    /**
     * Le validateur nu — attributs lus sur le DTO, aucun service, aucun translator.
     */
    private static function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
