<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use App\Core\Entity\Role;
use App\Core\Entity\User;
use App\Core\Enum\CoreRole;
use App\Core\Enum\SupportedLocale;
use App\Core\Repository\RoleRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le modèle que la story pose, vérifié sur une vraie base plutôt que relu.
 *
 * Trois promesses tiennent ici, et aucune n'est visible depuis un écran : un utilisateur
 * porte **exactement un** rôle, son mot de passe n'est ni lisible ni réversible, et les
 * trois rôles du socle existent parce qu'une migration les a semés — pas parce qu'un
 * test les a créés en passant.
 */
final class UserPersistenceTest extends KernelTestCase
{
    #[Test]
    #[DataProvider('coreRoles')]
    public function the_three_core_roles_are_seeded_by_the_migration(CoreRole $code): void
    {
        self::bootKernel();

        $roles = self::getContainer()->get(RoleRepository::class);

        $role = $roles->findOneByCode($code);

        self::assertInstanceOf(
            Role::class,
            $role,
            \sprintf('Le rôle du socle « %s » n\'existe pas après migration : « les trois rôles du socle existent comme rôles nommés » n\'est pas tenu.', $code->value),
        );
        self::assertNotSame('', trim($role->getName()), \sprintf('Le rôle « %s » a été semé sans libellé.', $code->value));
    }

    /**
     * @return iterable<string, array{CoreRole}>
     */
    public static function coreRoles(): iterable
    {
        foreach (CoreRole::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    /**
     * « Un utilisateur porte exactement un rôle » — donc une association à valeur unique
     * et non nulle, pas une collection. Le mapping est la seule chose qui le dise : rendre
     * la colonne nullable ou passer en `ManyToMany` ne casserait aucun autre test.
     */
    #[Test]
    public function a_user_carries_exactly_one_role(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $metadata = $entityManager->getClassMetadata(User::class);
        $mapping = $metadata->getAssociationMapping('role');

        self::assertTrue(
            $metadata->isSingleValuedAssociation('role'),
            'Le rôle d\'un utilisateur est une collection : « exactement un rôle » n\'est plus exprimé par le modèle.',
        );
        self::assertInstanceOf(ManyToOneAssociationMapping::class, $mapping);
        self::assertFalse(
            $mapping->joinColumns[0]->nullable,
            'La colonne de rôle est nullable : un compte sans rôle deviendrait représentable.',
        );
    }

    #[Test]
    public function two_accounts_cannot_share_an_email(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        Accounts::create($container, 'doublon@example.test', 'un-mot-de-passe-assez-long');

        $this->expectException(UniqueConstraintViolationException::class);

        Accounts::create($container, 'doublon@example.test', 'un-autre-mot-de-passe');
    }

    /**
     * « Jamais lisible, jamais réversible, préfixe d'algorithme conforme à `auto` ».
     *
     * Le hachage est vérifié par le hasher du projet — pas par une comparaison de chaînes
     * — parce que c'est lui qui décide de l'algorithme : un test qui coderait en dur
     * `bcrypt` interdirait à `auto` de faire son travail le jour où la plateforme offre
     * mieux.
     */
    #[Test]
    public function a_password_is_stored_hashed_and_never_in_clear(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $plain = 'un-mot-de-passe-assez-long';

        $user = Accounts::create($container, 'hachage@example.test', $plain);

        $hasher = $container->get(UserPasswordHasherInterface::class);

        $entityManager = $container->get(EntityManagerInterface::class);

        // **Écart assumé à la règle 3 — « aucun SQL en dehors d'un repository » — et il
        // est déclaré plutôt que pris en silence.** L'assertion porte sur ce que la
        // *colonne* contient, pas sur ce que l'entité rend : relire par l'ORM ferait
        // répondre l'identity map avec l'objet que le test vient d'écrire, et le test
        // passerait même si rien n'avait jamais atteint la base. Un `SELECT` ciblé sur la
        // colonne est le seul moyen de le vérifier, et la méthode de repository qu'il
        // faudrait créer pour s'y conformer — « rends-moi le hachage brut d'un compte » —
        // n'aurait aucun appelant en production et exposerait une lecture que rien
        // d'autre ne doit faire.
        /** @var array<string, mixed>|false $row */
        $row = $entityManager->getConnection()
            ->executeQuery('SELECT password FROM `user` WHERE email = ?', [$user->getUserIdentifier()])
            ->fetchAssociative();

        self::assertIsArray($row, 'Le compte créé n\'a pas de ligne en base.');

        $stored = $row['password'];
        self::assertIsString($stored);

        self::assertNotSame($plain, $stored, 'Le mot de passe est stocké en clair.');
        self::assertStringNotContainsString($plain, $stored, 'Le mot de passe apparaît tel quel dans la valeur stockée.');
        self::assertNotSame($plain, base64_decode($stored, true), 'Le mot de passe est encodé, pas haché — donc réversible.');
        self::assertMatchesRegularExpression(
            '/^\$(2y|argon2i|argon2id)\$/',
            $stored,
            'La valeur stockée ne porte pas de préfixe d\'algorithme : `password_hashers: auto` n\'a pas haché ce mot de passe.',
        );
        self::assertTrue($hasher->isPasswordValid($user, $plain), 'Le hachage stocké ne valide pas le mot de passe d\'origine.');
    }

    #[Test]
    public function an_account_carries_its_interface_language_and_its_enabled_state(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $user = Accounts::create($container, 'langue@example.test', 'un-mot-de-passe-assez-long', false, SupportedLocale::Nl);

        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reloaded = $entityManager->find(User::class, $user->getId());

        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(SupportedLocale::Nl, $reloaded->getLanguage(), 'La langue du compte n\'a pas survécu à l\'aller-retour en base.');
        self::assertFalse($reloaded->isEnabled(), 'L\'état actif/désactivé n\'a pas survécu à l\'aller-retour en base.');
        self::assertSame('langue@example.test', $reloaded->getUserIdentifier(), 'L\'identifiant d\'un utilisateur doit être son email.');
    }

    /**
     * AD-8 : aucun code ne teste un nom de rôle. Le rôle métier est une **donnée**, et le
     * seul rôle Symfony qu'un compte porte est `ROLE_USER` — celui qui distingue un
     * visiteur d'un utilisateur connecté, rien de plus.
     */
    #[Test]
    public function a_business_role_never_becomes_a_symfony_role(): void
    {
        self::bootKernel();

        $user = Accounts::create(self::getContainer(), 'ad8@example.test', 'un-mot-de-passe-assez-long', role: CoreRole::SuperAdmin);

        self::assertSame(
            ['ROLE_USER'],
            $user->getRoles(),
            'Un rôle métier a fui dans les rôles Symfony : AD-8 interdit qu\'une décision d\'autorisation puisse s\'adosser à son nom.',
        );
    }
}
