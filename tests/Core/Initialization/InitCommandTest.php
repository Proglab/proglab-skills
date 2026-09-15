<?php

declare(strict_types=1);

namespace App\Tests\Core\Initialization;

use App\Core\Enum\CoreRole;
use App\Core\Repository\RoleRepository;
use App\Core\Repository\UserRepository;
use App\Core\Service\DerivativeInitializer;
use App\Tests\Core\Security\Accounts;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Le câblage de `app:init`, et rien d'autre.
 *
 * Les règles sont exercées par `DerivativeInitializerTest` ; ce qui se vérifie ici est ce
 * qu'une commande apporte en propre — le nom se résout, l'option se binde, les questions
 * se posent dans l'ordre, et **le code de sortie est le bon**, puisque c'est la seule
 * chose qu'un script d'installation regarde. La commande est le seul point d'entrée vers
 * la création d'un compte à tous les droits : « on croyait que ça refuserait » est une
 * phrase qu'on entend après coup, donc les deux branches de `--force` sont couvertes.
 *
 * **Aucun de ces cas n'atteint l'état `Uninitialized`, et c'est ce qui les rend jouables
 * ici.** La base de test porte déjà son schéma, donc la commande n'exécute ni
 * `doctrine:database:create` ni `doctrine:migrations:migrate` : aucun DDL, donc aucun
 * `implicit commit` MySQL pour vider la transaction de DAMA. Le chemin `Uninitialized` est
 * prouvé en entier par `FreshDerivativeTest`, dans un vrai sous-processus.
 */
final class InitCommandTest extends KernelTestCase
{
    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    #[Test]
    public function it_creates_the_first_super_admin_on_an_empty_derivative(): void
    {
        $tester = self::tester();
        $tester->setInputs(['fabrice@example.test', self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('fabrice@example.test');

        self::assertNotNull($user, 'Le chemin nominal doit écrire le compte.');
        self::assertSame(CoreRole::SuperAdmin, $user->getRole()->getCode());
        self::assertStringContainsString('fabrice@example.test', $tester->getDisplay(), 'Le rapport final doit nommer l\'adresse créée.');
    }

    /**
     * Le rapport dit ce qu'il a **sauté**, pas seulement ce qu'il a fait : une commande
     * relancée qui se tairait laisserait croire qu'elle a rejoué les migrations.
     */
    #[Test]
    public function it_touches_no_schema_when_the_tables_are_already_there(): void
    {
        $tester = self::tester();
        $tester->setInputs(['fabrice@example.test', self::PASSWORD, self::PASSWORD]);
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertStringNotContainsString('doctrine:migrations:migrate', $display, 'Sur un schéma déjà en place, aucune migration ne doit être jouée.');
        self::assertMatchesRegularExpression('/saut|déjà/i', $display, 'Le rapport doit dire que la préparation du schéma a été sautée.');
    }

    #[Test]
    public function a_populated_derivative_is_refused_and_nothing_is_touched(): void
    {
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);
        Accounts::create(self::getContainer(), 'lea@example.test', self::PASSWORD);

        $tester = self::tester();

        self::assertSame(Command::FAILURE, $tester->execute([]), 'Un dérivé peuplé doit sortir en échec : c\'est le code de sortie qui arrête un script d\'installation.');

        $display = $tester->getDisplay();

        self::assertStringContainsString('2', $display, 'Le refus doit nommer le nombre de comptes existants.');
        self::assertStringContainsString('--force', $display, 'Le refus doit nommer l\'issue de secours, sinon elle se devine.');
        self::assertSame(2, self::initializer()->accountCount(), 'Un refus ne doit créer aucun compte.');
    }

    /**
     * Le refus tombe **avant** la première question : demander un mot de passe pour ensuite
     * refuser ferait saisir un secret pour rien.
     */
    #[Test]
    public function a_populated_derivative_is_refused_without_asking_anything(): void
    {
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        $tester = self::tester();
        $tester->execute([]);

        self::assertStringNotContainsString('Mot de passe', $tester->getDisplay(), 'Aucune question ne doit être posée sur un dérivé déjà initialisé.');
    }

    #[Test]
    public function force_creates_an_extra_super_admin_on_a_populated_derivative(): void
    {
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        $tester = self::tester();
        $tester->setInputs(['secours@example.test', self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]), $tester->getDisplay());
        self::assertSame(2, self::initializer()->accountCount(), '`--force` ajoute un compte, il ne remplace personne.');

        $created = self::getContainer()->get(UserRepository::class)->findOneByEmail('secours@example.test');

        self::assertNotNull($created);
        self::assertSame(CoreRole::SuperAdmin, $created->getRole()->getCode());
        self::assertStringContainsString('[WARNING]', $tester->getDisplay(), 'Créer un second compte à tous les droits doit être annoncé comme un avertissement.');
    }

    #[Test]
    public function an_email_already_taken_creates_nothing_and_names_the_address(): void
    {
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        $tester = self::tester();
        $tester->setInputs(['marc@example.test', self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertStringContainsString('marc@example.test', $tester->getDisplay());
        self::assertSame(1, self::initializer()->accountCount(), 'Une adresse refusée ne doit laisser aucune ligne derrière elle.');
    }

    /**
     * La commande est interactive par nature : sans TTY elle sort en `INVALID` plutôt que
     * de fabriquer un compte avec des valeurs par défaut.
     */
    #[Test]
    public function without_interaction_it_refuses_to_invent_an_account(): void
    {
        $tester = self::tester();

        self::assertSame(Command::INVALID, $tester->execute([], ['interactive' => false]));
        self::assertMatchesRegularExpression('/saisie|interacti/i', $tester->getDisplay(), 'Le message doit dire que la commande exige une saisie.');
        self::assertSame(0, self::initializer()->accountCount(), '`--no-interaction` ne doit créer aucun compte.');
    }

    /**
     * La dernière ligne de la matrice, vue depuis la commande.
     *
     * `DerivativeInitializerTest` prouve que le service lève ; ce qui se vérifie ici, c'est
     * que la commande l'**attrape** — un `InitializationFailed` qui s'échapperait rendrait
     * une trace et un code de sortie 255, là où l'opérateur attend une phrase et le nom de
     * la migration à jouer. Et rien ne doit être demandé : la vérification précède les
     * questions.
     */
    #[Test]
    public function a_missing_core_role_is_reported_as_a_failure_rather_than_a_trace(): void
    {
        self::forgetCoreRoles();

        $tester = self::tester();

        self::assertSame(Command::FAILURE, $tester->execute([]), $tester->getDisplay());

        $display = $tester->getDisplay();

        self::assertStringContainsString(DerivativeInitializer::ROLE_SEEDING_MIGRATION, $display, 'Le message doit nommer la migration qui sème les rôles.');
        self::assertStringNotContainsString('Adresse email', $display, 'La vérification des rôles précède les questions : rien ne doit être demandé.');
        self::assertSame(0, self::initializer()->accountCount());
    }

    #[Test]
    public function a_diverging_confirmation_asks_both_password_questions_again(): void
    {
        $tester = self::tester();
        $tester->setInputs([
            'fabrice@example.test',
            self::PASSWORD,
            'pas-du-tout-le-meme',
            self::PASSWORD,
            self::PASSWORD,
        ]);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $display = $tester->getDisplay();

        self::assertSame(1, substr_count($display, 'Adresse email'), 'L\'adresse déjà acceptée ne doit pas être redemandée : seules les deux questions du mot de passe le sont.');
        self::assertSame(2, substr_count($display, 'Confirmez'), 'La confirmation divergente doit reposer les deux questions du mot de passe.');
        self::assertNotNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('fabrice@example.test'));
    }

    #[Test]
    public function a_blank_password_is_refused_and_asked_again(): void
    {
        $tester = self::tester();
        $tester->setInputs([
            'fabrice@example.test',
            '',
            self::PASSWORD,
            self::PASSWORD,
        ]);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame(2, substr_count($tester->getDisplay(), 'Mot de passe'), 'Une saisie vide doit reposer la question du mot de passe.');
        self::assertSame(1, self::initializer()->accountCount(), 'Rien ne doit être écrit tant que la saisie n\'est pas valide.');
    }

    /**
     * La ligne « au-delà de `MAX_PASSWORD_LENGTH` octets » de la matrice, exercée là où elle
     * se produit — sur la console.
     *
     * Elle est bien atteignable : `QuestionHelper::doReadInput()` lit octet par octet
     * (`fread($stream, 1)`) et ne tronque pas la ligne. Sans la borne du DTO, cette saisie
     * arriverait au hacheur, qui lèverait une `InvalidPasswordException` que rien n'attrape
     * ici — la commande rendrait une trace au lieu de reposer la question.
     */
    #[Test]
    public function a_password_beyond_the_hasher_limit_is_refused_and_asked_again(): void
    {
        $tester = self::tester();
        $tester->setInputs([
            'fabrice@example.test',
            str_repeat('a', PasswordHasherInterface::MAX_PASSWORD_LENGTH + 1),
            self::PASSWORD,
            self::PASSWORD,
        ]);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame(2, substr_count($tester->getDisplay(), 'Mot de passe'), 'Une saisie trop longue doit reposer la question du mot de passe.');
        self::assertSame(1, self::initializer()->accountCount());
    }

    /**
     * Un mot de passe encadré d'espaces est enregistré **tel quel**.
     *
     * `Question` rogne par défaut, chemin masqué compris. Un mot de passe rogné est un mot
     * de passe différent de celui qu'on a tapé : la commande annoncerait un succès, et la
     * page de connexion refuserait ensuite la saisie d'origine sans rien pouvoir expliquer.
     * C'est le hacheur du projet qui tranche ici, pas une comparaison de chaînes — c'est lui
     * qui décidera à la connexion.
     */
    #[Test]
    public function a_password_wrapped_in_spaces_is_stored_exactly_as_typed(): void
    {
        $spaced = '  '.self::PASSWORD.' ';

        $tester = self::tester();
        $tester->setInputs(['fabrice@example.test', $spaced, $spaced]);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('fabrice@example.test');
        self::assertNotNull($user);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        self::assertTrue($hasher->isPasswordValid($user, $spaced), 'Le mot de passe a été rogné : l\'opérateur ne pourra pas se connecter avec ce qu\'il a tapé.');
        self::assertFalse($hasher->isPasswordValid($user, self::PASSWORD), 'La version rognée ouvre le compte : les espaces saisies ont été perdues.');
    }

    /**
     * La colonne « Error Handling » de la matrice dit « Sortie sur stderr », et rien ne le
     * prouverait sans ceci.
     *
     * Par défaut, `CommandTester` fusionne les deux flux, et `SymfonyStyle::getErrorStyle()`
     * rend `$this` quand la sortie n'est pas un `ConsoleOutputInterface` : les autres tests
     * passeraient identiquement que `getErrorStyle()` soit utilisé ou non.
     * `capture_stderr_separately` monte un vrai `ConsoleOutput`, ce qui est la seule façon de
     * voir la différence — et c'est elle qui permet à `app:init > rapport.txt` de garder le
     * rapport tout en laissant l'échec remonter.
     */
    #[Test]
    public function the_refusal_goes_to_stderr_and_not_to_the_report(): void
    {
        Accounts::create(self::getContainer(), 'marc@example.test', self::PASSWORD);

        $tester = self::tester();

        self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('--force', $tester->getErrorOutput(), 'Le refus doit partir sur stderr.');
        self::assertStringNotContainsString('--force', $tester->getDisplay(), 'Le refus ne doit pas se mélanger au rapport sur la sortie standard.');
    }

    #[Test]
    public function a_malformed_email_is_refused_and_asked_again(): void
    {
        $tester = self::tester();
        $tester->setInputs([
            'pas-une-adresse',
            'fabrice@example.test',
            self::PASSWORD,
            self::PASSWORD,
        ]);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame(2, substr_count($tester->getDisplay(), 'Adresse email'), 'Une adresse malformée doit reposer la question.');
    }

    // -------------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------------

    private static function tester(): CommandTester
    {
        // `bootKernel()` redémarre le noyau : les comptes posés juste avant par un test
        // seraient toujours en base — DAMA tient la transaction hors du noyau —, mais
        // l'EntityManager serait remplacé sous les pieds du test.
        $kernel = self::$kernel ?? self::bootKernel();

        return new CommandTester(new Application($kernel)->find('app:init'));
    }

    private static function initializer(): DerivativeInitializer
    {
        return self::getContainer()->get(DerivativeInitializer::class);
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
