<?php

declare(strict_types=1);

namespace App\Tests\Core\Initialization;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

/**
 * AC 1 de la story, prouvé en entier : « un dérivé fraîchement cloné, une base inexistante,
 * une seule commande, et la seule intervention demandée est la saisie des identifiants ».
 *
 * **Le seul test du dépôt qui atteint l'état `Uninitialized`, et le seul qui pouvait.**
 * Créer une base et jouer des migrations, c'est du DDL ; MySQL y répond par un *implicit
 * commit* qui viderait la transaction de DAMA et laisserait la suite tourner sans
 * isolation. Le DDL est donc joué **hors de PHPUnit**, dans un vrai sous-processus, sur une
 * base jetable au nom aléatoire — ce qui, accessoirement, est aussi la seule mise en scène
 * honnête de « je viens de cloner le socle ».
 *
 * `TestCase` et non `KernelTestCase` : rien ici n'a besoin du noyau, et ne pas le démarrer
 * évite d'ouvrir une transaction DAMA sur la base de la suite pendant qu'un autre processus
 * en crée une autre.
 *
 * **Le sous-processus tourne en `APP_ENV=dev`, et il le faut.** C'est d'abord la mise en
 * scène honnête — un développeur qui vient de cloner le socle lance `app:init` en
 * développement. C'est surtout la seule qui fonctionne : `config/bundles.php` enregistre
 * `DAMADoctrineTestBundle` sous `test`, or DAMA remplace le driver par un driver statique
 * qui enveloppe tout dans une transaction à savepoints — et le DDL des migrations y
 * déclenche l'*implicit commit* de MySQL, donc « SAVEPOINT DOCTRINE_4 does not exist » au
 * premier flush. Vérifié. En `dev`, ni DAMA, ni le `dbname_suffix` de `when@test` : la base
 * ouverte porte exactement le nom passé dans le DSN.
 */
final class FreshDerivativeTest extends TestCase
{
    private const string EMAIL = 'fabrice@example.test';
    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    /** Le nom de la base jetable, tiré au sort à chaque méthode. */
    private string $database;

    protected function setUp(): void
    {
        $this->database = 'proglab_init_'.bin2hex(random_bytes(6));
    }

    /**
     * La base jetable disparaît même quand le test échoue — sinon une exécution rouge
     * laisserait une base orpheline par tentative sur le serveur du développeur.
     */
    protected function tearDown(): void
    {
        $server = self::connectTo(null);

        $server->executeStatement(\sprintf(
            'DROP DATABASE IF EXISTS `%s`',
            str_replace('`', '', $this->database),
        ));

        $server->close();
    }

    #[Test]
    public function one_command_turns_a_fresh_clone_into_a_usable_derivative(): void
    {
        [$exitCode, $output] = $this->runInit();

        self::assertSame(Command::SUCCESS, $exitCode, \sprintf("« app:init » doit sortir en 0 sur une base inexistante.\n%s", $output));
        self::assertStringContainsString(self::EMAIL, $output, 'Le rapport final doit nommer l\'adresse créée.');
        self::assertStringNotContainsString(self::PASSWORD, $output, 'Le mot de passe saisi ne doit apparaître nulle part dans la sortie.');
    }

    /**
     * La deuxième ligne de la matrice : la base existe déjà, son schéma non.
     *
     * C'est le cas d'un dérivé dont l'hébergeur fournit une base vide — chez beaucoup, le
     * compte applicatif n'a pas le droit de faire `CREATE DATABASE`. `--if-not-exists`
     * rend alors la création silencieuse au lieu de faire sortir la commande en échec, et
     * les migrations font tout le travail. Sans ce cas, rien ne distingue cette option
     * d'un oubli.
     */
    #[Test]
    public function an_existing_but_empty_database_is_migrated_rather_than_recreated(): void
    {
        $server = self::connectTo(null);
        $server->executeStatement(\sprintf('CREATE DATABASE `%s`', str_replace('`', '', $this->database)));
        $server->close();

        // Le témoin. Une base recréée le perdrait — c'est la preuve que la création a bien
        // été sautée, et elle ne dépend d'aucune phrase : la prose de
        // `doctrine:database:create` appartient à l'amont et peut être reformulée sans que
        // le comportement change.
        $witness = self::connectTo($this->database);
        $witness->executeStatement('CREATE TABLE temoin_hebergeur (id INT PRIMARY KEY)');
        $witness->close();

        [$exitCode, $output] = $this->runInit();

        self::assertSame(Command::SUCCESS, $exitCode, \sprintf("Une base déjà créée mais vide doit se migrer sans erreur.\n%s", $output));

        $connection = self::connectTo($this->database);
        $tables = $connection->createSchemaManager()->tableExists('temoin_hebergeur');
        $accounts = $connection->fetchAllAssociative('SELECT email FROM `user`');
        $connection->close();

        self::assertTrue($tables, 'La base fournie par l\'hébergeur a été recréée : son contenu antérieur a disparu.');
        self::assertCount(1, $accounts, 'Le compte doit être créé sur la base fournie, pas sur une autre.');
    }

    /**
     * Des identifiants faux ne doivent **jamais** se déguiser en « dérivé non initialisé ».
     *
     * `UserRepository::hasAccountTable()` ne traite comme « table introuvable » que l'erreur
     * MySQL 1049 (« Unknown database ») ; tout le reste remonte. Remplacer cette
     * discrimination par un `catch (ConnectionException)` aveugle laisserait la commande
     * annoncer un état faux à un opérateur dont le mot de passe a simplement tourné, puis
     * tenter de créer une base qu'il n'a pas le droit de créer. C'est ce cas-ci qui
     * l'interdit.
     */
    #[Test]
    public function wrong_credentials_are_never_reported_as_an_uninitialized_derivative(): void
    {
        [$exitCode, $output] = self::runInitOn(self::dsnFor($this->database, password: 'pas-le-bon-mot-de-passe'));

        self::assertNotSame(Command::SUCCESS, $exitCode, 'Des identifiants faux ne peuvent pas produire une initialisation réussie.');
        self::assertStringNotContainsString(
            'introuvable',
            $output,
            'La commande annonce « la table des comptes est introuvable » alors que c\'est la connexion qui est refusée : l\'échec de connexion a été avalé.',
        );
        self::assertFalse(self::databaseExists($this->database), 'Rien ne doit être créé quand la connexion est refusée.');
    }

    #[Test]
    public function it_seeds_the_three_core_roles_through_the_migration(): void
    {
        $this->runInit();

        $connection = self::connectTo($this->database);
        $codes = $connection->fetchFirstColumn('SELECT code FROM role ORDER BY id');
        $connection->close();

        self::assertSame(['super_admin', 'admin', 'user'], $codes, 'Les trois rôles du socle doivent être en base — semés par la migration, jamais par la commande.');
    }

    #[Test]
    public function it_creates_exactly_one_enabled_super_admin_with_a_hashed_password(): void
    {
        $this->runInit();

        $connection = self::connectTo($this->database);
        $accounts = $connection->fetchAllAssociative(
            'SELECT u.email, u.enabled, u.password, u.language, r.code FROM `user` u JOIN role r ON r.id = u.role_id',
        );
        $connection->close();

        self::assertCount(1, $accounts, 'Une initialisation crée un compte, et un seul.');

        $account = self::asText($accounts[0]);

        self::assertSame(self::EMAIL, $account['email']);
        self::assertSame('super_admin', $account['code']);
        self::assertSame('1', $account['enabled'], 'Un compte créé désactivé ne pourrait pas se connecter.');
        self::assertSame('fr', $account['language']);
        self::assertNotSame(self::PASSWORD, $account['password'], 'Le mot de passe est stocké en clair.');
        self::assertStringStartsWith('$', $account['password'], 'Le hachage doit porter le préfixe d\'algorithme que « password_hashers: auto » produit.');

        // Le hachage se relit avec **ce qui a été tapé**, et pas à un caractère près.
        // `password_hashers: auto` produit un hachage natif, donc `password_verify()` suffit
        // ici et évite de démarrer un noyau. C'est la seule preuve de bout en bout que le
        // saut de ligne de l'entrée standard n'est pas resté collé au secret, et qu'aucun
        // rognage ne l'a raccourci.
        self::assertTrue(
            password_verify(self::PASSWORD, $account['password']),
            'Le mot de passe saisi n\'ouvre pas le compte créé : la lecture de l\'entrée l\'a altéré.',
        );
    }

    /**
     * Le second critère d'acceptation : relancée juste après, la commande refuse, nomme ce
     * qu'elle a trouvé et ne touche à rien. `InitCommandTest` le tient déjà en unitaire ;
     * ici c'est le même processus, la même base et le même schéma qu'AC 1 vient de créer —
     * c'est ce qui prouve que le second passage ne rejoue **pas** les migrations.
     */
    #[Test]
    public function running_it_again_refuses_and_leaves_everything_alone(): void
    {
        $this->runInit();

        [$exitCode, $output] = $this->runInit();

        self::assertSame(Command::FAILURE, $exitCode, 'Une seconde initialisation doit sortir en échec.');
        self::assertStringContainsString('--force', $output, 'Le refus doit nommer l\'issue de secours.');
        self::assertStringNotContainsString('doctrine:migrations:migrate', $output, 'Le second passage ne doit pas retoucher au schéma.');

        $connection = self::connectTo($this->database);
        $accounts = $connection->fetchAllAssociative('SELECT id FROM `user`');
        $connection->close();

        self::assertCount(1, $accounts, 'Un refus ne doit créer aucun compte.');
    }

    // -------------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------------

    /**
     * `bin/console app:init` dans un vrai sous-processus, sur la base jetable.
     *
     * Les trois réponses sont poussées sur son entrée standard. Le flux n'est alors pas un
     * terminal, donc la commande ne demande pas au terminal de couper l'écho — il n'y en a
     * aucun à couper : c'est la condition exacte que `InitCommand::readsFromATerminal()`
     * teste, et c'est ce qui rend ce chemin scriptable.
     *
     * `SHELL_VERBOSITY` est remis à `0` : `phpunit.dist.xml` le pose à `-1`, et
     * `Application::configureIO()` en déduit `setInteractive(false)` — le sous-processus
     * sortirait en `INVALID` avant la première question.
     *
     * `Process` avec son `cwd`, jamais un `cd` passé à un shell : sous Windows, `cd` sans
     * `/d` ne change pas de volume (le motif de `tests/Core/Quality/SandboxTrait.php`).
     *
     * @return array{int, string}
     */
    private function runInit(): array
    {
        [$exitCode, $output] = self::runInitOn(self::dsnFor($this->database));

        // **Le filet qui protège la base de la suite.** Si le DSN passé ci-dessus ne
        // prenait pas — c'est arrivé, et sans bruit : `SYMFONY_DOTENV_VARS` hérité du père
        // rendait à `.env.test` le droit de le réécrire —, le sous-processus initialiserait
        // `proglab_skills_test` et y **commiterait** un compte, hors de toute transaction
        // DAMA. Le reste de la suite deviendrait rouge pour une raison introuvable. Cette
        // vérification transforme ce scénario en un échec qui dit son nom.
        self::assertTrue(
            self::databaseExists($this->database),
            \sprintf(
                "La base jetable « %s » n'existe pas après « app:init » : le DSN n'a pas atteint le sous-processus, "
                ."ou la préparation du schéma a échoué.\n%s",
                $this->database,
                $output,
            ),
        );

        return [$exitCode, $output];
    }

    /**
     * Le même sous-processus, sur le DSN qu'on lui donne et sans filet — pour les cas qui
     * attendent précisément que rien ne soit créé.
     *
     * @return array{int, string}
     */
    private static function runInitOn(string $dsn): array
    {
        $process = new Process(
            [\PHP_BINARY, 'bin/console', 'app:init'],
            self::projectDir(),
            [
                'APP_ENV' => 'dev',
                'SHELL_VERBOSITY' => '0',
                'DATABASE_URL' => $dsn,
                // **Sans cette ligne, le DSN ci-dessus est silencieusement écrasé.**
                // `Process` transmet au fils l'environnement du père, `SYMFONY_DOTENV_VARS`
                // compris ; `Dotenv::populate()` y lit la liste des variables qu'un `.env`
                // a posées et s'autorise à les réécrire, donc `.env.test` reprend la main
                // sur DATABASE_URL et le sous-processus repart sur la base de la suite.
                // La vider dit au fils « aucune variable ne vient d'un .env » : la valeur
                // passée en environnement est alors préservée, ce qui est le comportement
                // normal de Dotenv.
                'SYMFONY_DOTENV_VARS' => '',
            ],
            \sprintf("%s\n%s\n%s\n", self::EMAIL, self::PASSWORD, self::PASSWORD),
            300.0,
        );

        $process->run();

        return [$process->getExitCode() ?? 1, $process->getOutput().$process->getErrorOutput()];
    }

    /**
     * Une ligne de résultat ramenée à des chaînes.
     *
     * DBAL type ses colonnes `mixed` — un `tinyint` revient en `int` ou en `string` selon
     * le pilote et l'émulation des requêtes préparées. Tout comparer en chaîne rend les
     * assertions indépendantes de ce détail, sans caster du `mixed` à l'aveugle.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private static function asText(array $row): array
    {
        $text = [];

        foreach ($row as $name => $value) {
            self::assertTrue(null === $value || \is_scalar($value), \sprintf('La colonne « %s » ne rend pas une valeur scalaire.', $name));

            $text[$name] = \is_scalar($value) ? (string) $value : '';
        }

        return $text;
    }

    private static function databaseExists(string $database): bool
    {
        $server = self::connectTo(null);
        $found = $server->fetchOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$database]);
        $server->close();

        return false !== $found;
    }

    /**
     * Une connexion au serveur de la suite — avec une base nommée, ou sans base du tout
     * pour pouvoir en supprimer une.
     */
    private static function connectTo(?string $database): Connection
    {
        $params = new DsnParser(['mysql' => 'pdo_mysql'])->parse(self::baseDsn());

        if (null === $database) {
            unset($params['dbname']);
        } else {
            $params['dbname'] = $database;
        }

        return DriverManager::getConnection($params);
    }

    /**
     * Le DSN de la suite, avec sa base remplacée — et, à la demande, son mot de passe.
     *
     * `$password` sert à un seul cas : vérifier qu'un refus de connexion ne se déguise pas
     * en « dérivé non initialisé ».
     *
     * Reconstruit morceau par morceau plutôt que par un remplacement de chaîne : le nom de
     * la base est le seul segment de chemin d'un DSN, mais un mot de passe ou un hôte peut
     * contenir la même sous-chaîne.
     */
    private static function dsnFor(string $database, ?string $password = null): string
    {
        $parts = parse_url(self::baseDsn());

        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            self::fail(\sprintf('DATABASE_URL n\'est pas une URL exploitable : « %s ».', self::baseDsn()));
        }

        $credentials = $parts['user'] ?? '';
        $password ??= $parts['pass'] ?? null;

        if (null !== $password) {
            $credentials .= ':'.$password;
        }

        return \sprintf(
            '%s://%s%s%s/%s%s',
            $parts['scheme'],
            '' === $credentials ? '' : $credentials.'@',
            $parts['host'],
            isset($parts['port']) ? ':'.$parts['port'] : '',
            $database,
            isset($parts['query']) ? '?'.$parts['query'] : '',
        );
    }

    private static function baseDsn(): string
    {
        $dsn = $_SERVER['DATABASE_URL'] ?? null;

        if (!\is_string($dsn) || '' === $dsn) {
            self::fail('DATABASE_URL est absent : tests/bootstrap.php charge pourtant .env.test.');
        }

        return $dsn;
    }

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 3);
    }
}
