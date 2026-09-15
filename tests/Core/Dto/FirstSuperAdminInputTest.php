<?php

declare(strict_types=1);

namespace App\Tests\Core\Dto;

use App\Core\Dto\Input\FirstSuperAdminInput;
use App\Core\Entity\User;
use Doctrine\ORM\Mapping\Column;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Les deux saisies de `app:init`, au niveau où leurs règles vivent.
 *
 * `InitCommandTest` prouve que la commande **repose la question** quand le DTO refuse ; ce
 * qui est refusé, et ce qui ne l'est pas, se décide ici — sans noyau, sans console.
 *
 * **Les deux bornes hautes ferment le même mode de panne, à deux endroits.** Au-delà de
 * `MAX_PASSWORD_LENGTH` octets le hacheur lève une `InvalidPasswordException` ; au-delà de
 * 180 caractères, c'est `flush()` qui lève « Data too long for column ». Ni l'une ni
 * l'autre n'est rattrapée sur le chemin console : la commande rendrait une trace et
 * sortirait en 255 au lieu de reposer la question. Elles ne sont donc pas un confort.
 *
 * Les deux ne comptent pas la même chose — octets pour le mot de passe, points de code pour
 * l'adresse — et c'est la seule façon de coller à ce que comptent respectivement
 * `CheckPasswordLengthTrait` et un `varchar(180)` en `utf8mb4`.
 */
#[CoversClass(FirstSuperAdminInput::class)]
final class FirstSuperAdminInputTest extends TestCase
{
    private const string EMAIL = 'fabrice@example.test';
    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    #[Test]
    public function a_plain_address_and_a_plain_password_are_valid(): void
    {
        self::assertSame([], self::violationsOn(self::EMAIL, self::PASSWORD));
    }

    #[Test]
    public function an_empty_address_is_refused_on_the_email_field(): void
    {
        self::assertSame(['email'], self::violationsOn('', self::PASSWORD));
    }

    #[Test]
    #[DataProvider('malformedAddresses')]
    public function a_malformed_address_is_refused_on_the_email_field(string $email): void
    {
        self::assertSame(['email'], self::violationsOn($email, self::PASSWORD));
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function malformedAddresses(): Generator
    {
        yield 'sans arobase' => ['fabrice.example.test'];
        yield 'sans domaine' => ['fabrice@'];
        yield 'sans partie locale' => ['@example.test'];
        yield 'avec une espace' => ['fabrice @example.test'];
    }

    /**
     * Une adresse bien formée mais trop longue pour la colonne.
     *
     * Sans cette borne, elle passe la validation, passe le contrôle d'unicité du service, et
     * fait lever « Data too long for column 'email' » à `flush()` — une trace et une sortie
     * en 255 au milieu d'une installation.
     */
    #[Test]
    public function a_wellformed_address_too_long_for_the_column_is_refused(): void
    {
        $domain = '@example.test';
        $tooLong = str_repeat('a', FirstSuperAdminInput::EMAIL_MAX_LENGTH + 1 - \strlen($domain)).$domain;

        self::assertSame(FirstSuperAdminInput::EMAIL_MAX_LENGTH + 1, \strlen($tooLong));
        self::assertSame(['email'], self::violationsOn($tooLong, self::PASSWORD));
    }

    #[Test]
    public function an_address_of_exactly_the_column_length_is_accepted(): void
    {
        $domain = '@example.test';
        $atTheLimit = str_repeat('a', FirstSuperAdminInput::EMAIL_MAX_LENGTH - \strlen($domain)).$domain;

        self::assertSame(FirstSuperAdminInput::EMAIL_MAX_LENGTH, \strlen($atTheLimit));
        self::assertSame([], self::violationsOn($atTheLimit, self::PASSWORD));
    }

    /**
     * La borne du DTO et la colonne de l'entité, confrontées.
     *
     * `deptrac.yaml` interdit à la couche `Dto` de voir la couche `Entity`, donc le nombre
     * est recopié dans le DTO. Ce test est ce qui l'empêche de diverger : il lit la longueur
     * déclarée sur `App\Core\Entity\User::$email` par réflexion sur l'attribut de mapping —
     * un test n'appartient à aucune couche, il a le droit de regarder les deux.
     */
    #[Test]
    public function the_declared_maximum_matches_the_column_it_protects(): void
    {
        $column = new ReflectionProperty(User::class, 'email')->getAttributes(Column::class);

        self::assertCount(1, $column, 'La propriété `User::$email` ne porte plus d\'attribut de mapping.');

        self::assertSame(
            FirstSuperAdminInput::EMAIL_MAX_LENGTH,
            $column[0]->newInstance()->length,
            'La borne du DTO a divergé de la colonne qu\'elle protège : « Data too long » est redevenu atteignable.',
        );
    }

    #[Test]
    public function an_empty_password_is_refused_on_the_password_field(): void
    {
        self::assertSame(['password'], self::violationsOn(self::EMAIL, ''));
    }

    /**
     * La borne haute n'est pas une politique : au-delà, `UserPasswordHasher` lève une
     * `InvalidPasswordException` (`CheckPasswordLengthTrait`) que rien n'attrape sur le
     * chemin console — la commande rendrait une trace au lieu de reposer la question.
     */
    #[Test]
    public function a_password_longer_than_the_hasher_accepts_is_refused(): void
    {
        self::assertSame(
            ['password'],
            self::violationsOn(self::EMAIL, str_repeat('a', PasswordHasherInterface::MAX_PASSWORD_LENGTH + 1)),
        );
    }

    #[Test]
    public function a_password_of_exactly_the_maximum_length_is_accepted(): void
    {
        $atTheLimit = str_repeat('a', PasswordHasherInterface::MAX_PASSWORD_LENGTH);

        self::assertSame(PasswordHasherInterface::MAX_PASSWORD_LENGTH, \strlen($atTheLimit));
        self::assertSame([], self::violationsOn(self::EMAIL, $atTheLimit));
    }

    /**
     * `countUnit: COUNT_BYTES` : le hacheur compte des octets, pas des points de code.
     * Sans cette option, une phrase de passe accentuée de 4 096 caractères — donc 8 192
     * octets — passerait la validation et ferait lever le hacheur juste après.
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
        self::assertSame(['password'], self::violationsOn(self::EMAIL, $accented));
    }

    /**
     * **L'écart assumé, épinglé par un test plutôt que par un paragraphe.**.
     *
     * Décision de Fabrice du 2026-09-15 : le chemin console ne mesure pas la robustesse,
     * là où `PasswordResetInput` refuse tout ce qui est sous 80 bits d'entropie. Le seul
     * appelant est la personne qui installe le dérivé, devant son propre terminal, et un
     * refus incompréhensible pendant une installation n'a pas la page de secours qu'a la
     * réinitialisation.
     *
     * `azerty123` est exactement ce que le jumeau HTTP rejette
     * (`PasswordResetInputTest::the_strength_refusal_states_the_criterion`). Le voir passer
     * ici est ce qui distingue une décision d'un oubli : ajouter `PasswordStrength` au DTO
     * fait rougir ce test, et celui qui le lira trouvera la raison au lieu de « corriger ».
     */
    #[Test]
    public function the_console_path_deliberately_does_not_measure_strength(): void
    {
        self::assertSame([], self::violationsOn(self::EMAIL, 'azerty123'));

        self::assertSame(
            [],
            array_filter(
                iterator_to_array(self::validator()->validate(new FirstSuperAdminInput(self::EMAIL, 'azerty123'))),
                static fn (object $violation): bool => $violation->getConstraint() instanceof PasswordStrength,
            ),
            'Une contrainte de robustesse a été ajoutée à ce DTO : lis la décision du 2026-09-15 dans son docblock avant de la garder.',
        );
    }

    /**
     * Les noms de champs en erreur, dans l'ordre du DTO.
     *
     * Les comparer en tableau plutôt qu'un par un prouve aussi l'**absence** des autres.
     *
     * @return list<string>
     */
    private static function violationsOn(string $email, string $password): array
    {
        $fields = [];

        foreach (self::validator()->validate(new FirstSuperAdminInput($email, $password)) as $violation) {
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
