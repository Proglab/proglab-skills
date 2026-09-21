<?php

declare(strict_types=1);

namespace App\Tests\Core\Initialization;

use App\Core\Dto\Input\FirstSuperAdminInput;
use App\Core\Entity\User;
use App\Core\Enum\CoreRole;
use App\Core\Enum\DerivativeState;
use App\Core\Enum\SupportedLocale;
use App\Core\Exception\InitializationFailed;
use App\Core\Repository\RoleRepository;
use App\Core\Repository\UserRepository;
use App\Core\Service\DerivativeInitializer;
use App\Tests\Core\Security\Accounts;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Les règles de la story 1.11, là où elles vivent.
 *
 * `InitCommand` n'en porte aucune : elle lit l'état que ce service rend, enchaîne deux
 * commandes Doctrine, pose trois questions et traduit le tout en code de sortie. Tout ce
 * qui est *décidé* l'est ici, donc c'est ici que porte la couverture — et c'est ce que
 * l'AC 4 de la story demande en exigeant un service.
 *
 * **Deux des trois états seulement sont atteignables par la base de test.** `Empty` et
 * `Initialized` se posent en créant ou non un compte ; `Uninitialized` demanderait de
 * supprimer la table des comptes, donc du DDL, donc un `implicit commit` MySQL qui
 * viderait la transaction de DAMA et laisserait la suite sans schéma. Le troisième cas
 * est donc exercé sur un repository doublé — c'est précisément pour cela qu'un repository
 * de ce standard n'est pas `final` —, et la vraie table absente est prouvée de bout en
 * bout par `FreshDerivativeTest`, hors PHPUnit.
 */
final class DerivativeInitializerTest extends KernelTestCase
{
    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    // ---------------------------------------------------------------------------------
    // L'état du dérivé
    // ---------------------------------------------------------------------------------

    #[Test]
    public function an_absent_account_table_reads_as_uninitialized(): void
    {
        $users = self::createStub(UserRepository::class);
        $users->method('hasAccountTable')->willReturn(false);

        self::assertSame(DerivativeState::Uninitialized, self::initializerWith($users)->state());
    }

    #[Test]
    public function an_account_table_without_any_account_reads_as_empty(): void
    {
        self::assertSame(DerivativeState::Empty, self::initializer()->state(), 'La base de test porte le schéma et aucun compte : c\'est la définition d\'`Empty`.');
    }

    #[Test]
    public function a_single_account_makes_the_derivative_initialized(): void
    {
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        self::assertSame(DerivativeState::Initialized, self::initializer()->state(), '« Base déjà peuplée » commence au premier compte, pas à un seuil.');
        self::assertSame(1, self::initializer()->accountCount());
    }

    /**
     * L'état ne compte pas les comptes désactivés à part : un dérivé dont le seul compte
     * est désactivé n'est pas vierge, et `app:init` doit y refuser la création silencieuse
     * d'un second Super admin.
     */
    #[Test]
    public function a_disabled_account_still_counts_as_initialized(): void
    {
        Accounts::create(self::getContainer(), 'karim@example.test', self::PASSWORD, false);

        self::assertSame(DerivativeState::Initialized, self::initializer()->state());
    }

    // ---------------------------------------------------------------------------------
    // Le refus sur un dérivé déjà initialisé
    // ---------------------------------------------------------------------------------

    #[Test]
    public function an_initialized_derivative_refuses_a_new_super_admin_by_default(): void
    {
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);
        Accounts::create(self::getContainer(), 'lea@example.test', self::PASSWORD);

        $this->expectException(InitializationFailed::class);
        // Le nombre est dans le message parce que c'est lui qui dit à l'opérateur
        // *sur quoi* il est tombé : deux comptes, ce n'est pas un résidu de test.
        $this->expectExceptionMessageMatches('/\b2\b/');

        self::initializer()->guardAgainstExistingAccounts(force: false);
    }

    #[Test]
    public function force_is_the_only_way_through_on_an_initialized_derivative(): void
    {
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        self::initializer()->guardAgainstExistingAccounts(force: true);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function an_empty_derivative_needs_no_force(): void
    {
        self::initializer()->guardAgainstExistingAccounts(force: false);

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------------------
    // Les rôles du socle, vérifiés et jamais semés
    // ---------------------------------------------------------------------------------

    #[Test]
    public function the_three_core_roles_come_from_the_migration(): void
    {
        self::initializer()->verifyCoreRoles();

        $roles = self::getContainer()->get(RoleRepository::class);

        foreach (CoreRole::cases() as $code) {
            self::assertNotNull($roles->findOneByCode($code), \sprintf('Le rôle « %s » doit être semé par la migration, pas par la commande.', $code->value));
        }
    }

    #[Test]
    public function a_missing_core_role_fails_loudly_and_names_the_migration(): void
    {
        self::forgetCoreRoles();

        $this->expectException(InitializationFailed::class);
        $this->expectExceptionMessageMatches('/Version20260911130140/');

        self::initializer()->verifyCoreRoles();
    }

    // ---------------------------------------------------------------------------------
    // La création du premier Super admin
    // ---------------------------------------------------------------------------------

    /**
     * L'état écrit est relu **par le repository**, jamais sur l'objet que le service rend.
     *
     * Ce n'est pas un détail de style : depuis la story 1.16, le service ne rend plus
     * l'entité mais un DTO à deux champs, qui ne peut rien dire du rôle, de l'activation ni
     * de la langue. Relire la ligne prouve d'ailleurs davantage : `persisted()` vide le
     * contexte Doctrine avant de chercher, donc ces trois assertions portent sur les
     * colonnes et non sur l'objet que le service vient de construire.
     */
    #[Test]
    public function it_creates_an_enabled_french_super_admin(): void
    {
        self::initializer()->createFirstSuperAdmin(new FirstSuperAdminInput('fabrice@example.test', self::PASSWORD));

        $user = self::persisted('fabrice@example.test');

        self::assertSame(CoreRole::SuperAdmin, $user->getRole()->getCode(), 'Le premier compte doit porter le rôle Super admin du socle.');
        self::assertTrue($user->isEnabled(), 'Un compte créé désactivé ne pourrait pas se connecter : la commande n\'aurait servi à rien.');
        self::assertSame(SupportedLocale::Fr, $user->getLanguage());
    }

    /**
     * Ce que l'appelant reçoit : un contrat, pas une entité.
     *
     * `Dto/Output/` est resté vide quinze stories pendant que ce service rendait un `User`
     * à `InitCommand` — c'est-à-dire une entité franchissant la frontière de la couche
     * service, et une commande capable de la muter. Le DTO porte `id` et `email`, exactement
     * ce que les deux appelants consomment : le message de succès lit l'adresse, la suite
     * prouve l'écriture par l'identifiant.
     */
    #[Test]
    public function it_returns_the_contract_of_the_account_it_wrote_and_never_the_entity(): void
    {
        $created = self::initializer()->createFirstSuperAdmin(new FirstSuperAdminInput('fabrice@example.test', self::PASSWORD));

        // Que ce soit un `AccountOutput` et non un `User` est tenu par le type de
        // retour, et PHPStan au niveau max le prouve statiquement : l'affirmer ici serait
        // une assertion toujours vraie. Ce qui reste à vérifier, c'est ce que le DTO *dit*.
        self::assertSame('fabrice@example.test', $created->email);
        self::assertSame(
            self::persisted('fabrice@example.test')->getId(),
            $created->id,
            'L\'identifiant rendu doit être celui de la ligne écrite : c\'est lui qui prouve le `flush()`.',
        );
    }

    /**
     * Le hachage passe par le hasher **du projet** — `password_hashers: auto`. Un test qui
     * vérifierait un algorithme écrit en dur prouverait la création sur un mécanisme que la
     * connexion n'utilise pas.
     */
    #[Test]
    public function the_password_is_really_hashed_by_the_project_hasher(): void
    {
        self::initializer()->createFirstSuperAdmin(new FirstSuperAdminInput('fabrice@example.test', self::PASSWORD));

        $user = self::persisted('fabrice@example.test');

        self::assertNotSame(self::PASSWORD, $user->getPassword(), 'Le mot de passe est stocké en clair.');
        self::assertTrue(
            self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::PASSWORD),
            'Le hachage ne se relit pas avec le hasher du projet : le compte créé ne pourra pas se connecter.',
        );
    }

    #[Test]
    public function it_refuses_an_email_that_is_already_taken(): void
    {
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        $this->expectException(InitializationFailed::class);
        $this->expectExceptionMessageMatches('/marc@example\.test/');

        self::initializer()->createFirstSuperAdmin(new FirstSuperAdminInput('marc@example.test', self::PASSWORD));
    }

    #[Test]
    public function it_writes_nothing_when_the_email_is_already_taken(): void
    {
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        try {
            self::initializer()->createFirstSuperAdmin(new FirstSuperAdminInput('marc@example.test', self::PASSWORD));
        } catch (InitializationFailed) {
            // Attendue : ce qui se vérifie est l'absence d'effet de bord.
        }

        self::assertSame(1, self::initializer()->accountCount(), 'Une adresse refusée ne doit laisser aucune ligne derrière elle.');
    }

    #[Test]
    public function creating_without_the_core_role_fails_loudly(): void
    {
        self::forgetCoreRoles();

        $this->expectException(InitializationFailed::class);

        self::initializer()->createFirstSuperAdmin(new FirstSuperAdminInput('fabrice@example.test', self::PASSWORD));
    }

    // ---------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------

    /**
     * La ligne réellement écrite, relue **depuis les colonnes**.
     *
     * Le `clear()` n'est pas décoratif, et c'est le seul détail qui rend ce helper honnête.
     * `findOneBy()` émet bien une requête à chaque appel, mais l'hydrateur retrouve
     * l'entité dans l'identity map et rend **la même instance** que celle que le service
     * vient de construire (`UnitOfWork::createEntity()` sort tôt sans `HINT_REFRESH`) :
     * sans vider le contexte, `isEnabled()` ou `getLanguage()` reliraient l'objet en
     * mémoire, et une colonne oubliée dans le mapping passerait inaperçue. Vidé, l'objet
     * rendu est hydraté depuis la base, et les assertions portent sur ce qui y est écrit.
     *
     * DAMA annule la transaction en fin de test ; `clear()` ne fait que détacher, il ne la
     * touche pas.
     */
    private static function persisted(string $email): User
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail($email);

        self::assertNotNull($user, \sprintf('Aucun compte « %s » n\'a été écrit.', $email));

        return $user;
    }

    private static function initializer(): DerivativeInitializer
    {
        return self::getContainer()->get(DerivativeInitializer::class);
    }

    /**
     * Le service monté sur un repository doublé, pour le seul état que la base de test ne
     * peut pas atteindre sans DDL.
     */
    private static function initializerWith(UserRepository $users): DerivativeInitializer
    {
        $container = self::getContainer();

        return new DerivativeInitializer(
            $users,
            $container->get(RoleRepository::class),
            $container->get(UserPasswordHasherInterface::class),
            $container->get(EntityManagerInterface::class),
        );
    }

    /**
     * Les trois rôles semés par la migration, retirés le temps d'une méthode.
     *
     * DAMA annule la transaction à la fin du test, donc la table les retrouve. Aucun
     * compte n'existe encore à cet instant, donc aucune clé étrangère ne s'y oppose.
     */
    private static function forgetCoreRoles(): void
    {
        $container = self::getContainer();

        $roles = $container->get(RoleRepository::class);
        $entityManager = $container->get(EntityManagerInterface::class);

        foreach ($roles->findAll() as $role) {
            $entityManager->remove($role);
        }

        $entityManager->flush();
    }
}
