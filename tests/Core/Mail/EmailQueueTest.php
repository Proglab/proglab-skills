<?php

declare(strict_types=1);

namespace App\Tests\Core\Mail;

use App\Core\Enum\SupportedLocale;
use App\Core\Message\SendEmail;
use App\Tests\Fixtures\Mail\DemoEmailDispatch;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Le critère central de la story : un `SendEmail` n'est consommable qu'après le commit
 * de la transaction qui l'a produit (AD-21, décision **D-1**).
 *
 * **Comment il se prouve, et pourquoi ça marche.** Le mécanisme retenu est le partage de
 * connexion : le transport `doctrine://default` écrit sur la connexion DBAL de
 * l'application, donc son `INSERT` rejoint la transaction ouverte par le cas d'usage.
 * DAMA ouvre une transaction par méthode de test et l'annule à la fin — **cette
 * transaction est la transaction ouverte du scénario**. Il suffit alors de lire la table
 * depuis une seconde connexion, créée à la main et n'appartenant à aucune transaction,
 * pour observer la propriété elle-même plutôt que l'ordre des appels dans le code.
 *
 * **Ce que ce test couvre en deux moitiés, et pourquoi.** Sous `when@test`, le transport
 * `async` est `in-memory://` : c'est ce qui permet à la suite de tourner sans worker et
 * sans base pour la file. La moitié « bus » exerce donc le cas d'usage de démonstration
 * jusqu'à l'enveloppe. La moitié « transport réel » fabrique le transport Doctrine à la
 * main — même DSN, même connexion — et c'est elle qui tient la propriété transactionnelle.
 * Les deux ensemble couvrent la matrice ; aucune des deux seule n'y suffit, et c'est dit
 * ici plutôt que découvert plus tard.
 */
#[CoversNothing]
final class EmailQueueTest extends KernelTestCase
{
    private const string QUEUE = 'default';

    /**
     * Mise en file nominale : le cas d'usage dispatche, et une enveloppe porteuse de
     * scalaires part sur le transport.
     */
    #[Test]
    public function the_use_case_puts_exactly_one_send_email_on_the_queue(): void
    {
        self::bootKernel();

        $this->useCase()->notify(SupportedLocale::Nl, 'Établi n°7', 'recipient@example.test');

        $sent = $this->inMemoryTransport()->getSent();
        self::assertCount(1, $sent);

        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendEmail::class, $message);

        // La charge utile ne porte que des scalaires et une locale explicite (AR-10) :
        // ni entité, ni objet `Email`, rien qui dépende de la requête en cours.
        self::assertSame('recipient@example.test', $message->toEmail);
        self::assertSame(SupportedLocale::Nl, $message->locale);
        self::assertSame('emails/demo.html.twig', $message->template);
        self::assertSame(['widget' => 'Établi n°7'], $message->context);
    }

    /**
     * Visibilité avant commit — la ligne de la matrice qui, sans ce test, ne serait qu'un
     * commentaire.
     */
    #[Test]
    public function the_row_is_invisible_to_another_connection_while_the_transaction_is_open(): void
    {
        self::bootKernel();

        $transport = $this->doctrineTransport();
        $transport->send(new Envelope($this->message()));

        // La connexion de l'application est dans la transaction : elle voit sa propre
        // écriture.
        self::assertSame(1, $this->countQueued($this->applicationConnection()));

        // Une connexion indépendante, elle, ne voit rien — la transaction n'est pas
        // commitée. C'est la propriété, pas l'ordre des appels.
        $outside = $this->independentConnection();

        try {
            // Le garde-fou de ce test : une seconde connexion pointée sur une **autre**
            // base verrait zéro ligne elle aussi, et l'assertion ci-dessous serait verte
            // sans rien prouver.
            self::assertSame(
                $this->applicationConnection()->getDatabase(),
                $outside->getDatabase(),
            );

            self::assertSame(0, $this->countQueued($outside));
        } finally {
            $outside->close();
        }
    }

    /**
     * Transaction annulée : le message disparaît avec ce qu'il annonçait.
     *
     * La frontière est ouverte par `wrapInTransaction()`, donc — la transaction de DAMA
     * étant déjà ouverte — c'est un point de sauvegarde (savepoint). Un `ROLLBACK TO
     * SAVEPOINT` emporte l'`INSERT` du transport exactement comme un rollback emporterait
     * une transaction de premier niveau : c'est le même mécanisme, et c'est ce qui rend
     * la propriété observable dans une suite isolée par DAMA.
     */
    #[Test]
    public function nothing_stays_queued_when_the_transaction_rolls_back(): void
    {
        self::bootKernel();

        $transport = $this->doctrineTransport();
        $entityManager = $this->entityManager();

        $before = $this->countQueued($this->applicationConnection());

        try {
            $entityManager->wrapInTransaction(function () use ($transport): void {
                $transport->send(new Envelope($this->message()));

                throw new RuntimeException('Le cas d\'usage échoue après le dispatch.');
            });
        } catch (RuntimeException) {
            // L'échec du cas d'usage est le scénario, pas un incident du test.
        }

        self::assertSame($before, $this->countQueued($this->applicationConnection()));
    }

    /**
     * Et l'ordre inverse ne tiendrait pas : dispatcher **après** le commit laisserait la
     * ligne derrière elle quand la suite du cas d'usage échoue. Ce test le montre sur le
     * même transport, pour que la raison du commentaire de `DemoEmailDispatch` soit
     * vérifiable et non déclarative.
     */
    #[Test]
    public function dispatching_outside_the_transaction_would_leave_the_row_behind(): void
    {
        self::bootKernel();

        $transport = $this->doctrineTransport();
        $entityManager = $this->entityManager();

        $before = $this->countQueued($this->applicationConnection());

        // Le dispatch a lieu **avant** la frontière, donc hors d'elle.
        $transport->send(new Envelope($this->message()));

        try {
            $entityManager->wrapInTransaction(static function (): void {
                throw new RuntimeException('Le cas d\'usage échoue après le dispatch.');
            });
        } catch (RuntimeException) {
            // Le même échec que dans le test précédent, au même endroit.
        }

        // Et le message, lui, est resté : un worker peut le dépiler et envoyer un email
        // au sujet d'un travail qui n'a jamais abouti.
        self::assertSame($before + 1, $this->countQueued($this->applicationConnection()));
    }

    private function message(): SendEmail
    {
        return new SendEmail(
            'recipient@example.test',
            'Alice Demo',
            SupportedLocale::Nl,
            'demo.subject',
            'emails/demo.html.twig',
            ['widget' => 'Établi n°7'],
        );
    }

    private function useCase(): DemoEmailDispatch
    {
        $useCase = self::getContainer()->get(DemoEmailDispatch::class);
        self::assertInstanceOf(DemoEmailDispatch::class, $useCase);

        return $useCase;
    }

    private function inMemoryTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * Le **vrai** transport Doctrine, fabriqué à la main sur la connexion de
     * l'application : `when@test` remplace le DSN du transport `async` par
     * `in-memory://`, donc c'est le seul moyen d'exercer ici le transport de production.
     */
    private function doctrineTransport(): TransportInterface
    {
        // Le DSN réellement configuré, jamais une copie écrite à la main : le pointer sur
        // un second magasin — Redis, une autre base — supprimerait la garantie
        // transactionnelle que ce test existe pour tenir, et un DSN en dur laisserait
        // le test vert en continuant d'écrire dans la base de l'application.
        $dsn = $_ENV['MESSENGER_TRANSPORT_DSN'] ?? null;
        self::assertIsString($dsn);

        return new DoctrineTransportFactory(self::getContainer()->get('doctrine'))->createTransport(
            $dsn,
            [],
            new PhpSerializer(),
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function applicationConnection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    /**
     * Une connexion qui n'appartient à aucune transaction en cours — ni celle du cas
     * d'usage, ni celle de DAMA.
     *
     * Les paramètres sont ceux de la connexion de l'application, moins tout ce qui la
     * rend statique : `driverClass` et `wrapperClass` portent respectivement le driver de
     * DAMA et le wrapper de DoctrineBundle, et les reprendre rendrait la seconde
     * connexion identique à la première — le test passerait au vert en ne prouvant rien.
     */
    private function independentConnection(): Connection
    {
        $params = $this->applicationConnection()->getParams();

        $driver = $params['driver'] ?? null;
        $host = $params['host'] ?? null;
        $port = $params['port'] ?? null;
        $dbname = $params['dbname'] ?? null;
        $user = $params['user'] ?? null;
        $password = $params['password'] ?? null;

        // Les six valeurs viennent de la connexion de l'application, aucune n'est
        // inventée : un changement de port, de base ou d'identifiants suit tout seul.
        //
        // Le pilote est la seule à être **épinglée** plutôt que recopiée, et pour une
        // raison de typage et non de paresse : `DriverManager::getConnection()` attend
        // une forme de tableau précise, où `driver` est l'un des noms que DBAL connaît,
        // et une valeur lue dans `$params` est `mixed`. L'assertion fait le travail que
        // la recopie ferait, en plus bruyant : le jour où le socle change de moteur,
        // c'est ce test qui le dit.
        //
        // Repasser `$params` tel quel, moins quelques clés, n'est pas une option : il
        // porte `dbname_suffix`, `idle_connection_ttl` et `dama.connection_key`, que la
        // forme attendue ne connaît pas.
        self::assertSame('pdo_mysql', $driver);
        self::assertIsString($host);
        self::assertIsInt($port);
        self::assertIsString($dbname);
        self::assertIsString($user);
        self::assertIsString($password);

        return DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $host,
            'port' => $port,
            'dbname' => $dbname,
            'user' => $user,
            'password' => $password,
        ]);
    }

    private function countQueued(Connection $connection): int
    {
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?',
            [self::QUEUE],
        );

        // `fetchOne()` rend `mixed`, et selon le pilote un `COUNT(*)` revient en entier
        // ou en chaîne : le compte est vérifié avant d'être converti.
        self::assertTrue(is_numeric($count), 'COUNT(*) doit rendre un nombre.');

        return (int) $count;
    }
}
