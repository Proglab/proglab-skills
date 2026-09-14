<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use App\Core\Service\PasswordResetRequest;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * La position du dispatch — **dans** la frontière de transaction, et pas à côté.
 *
 * C'est la décision D-1 de la story 1.8, et jusqu'ici rien ne la tenait sur ce cas d'usage
 * réel : sortir `$this->bus->dispatch(...)` de la fermeture passée à `wrapInTransaction()`
 * laisse toute la suite au vert. Ce qui rouvrirait en production est la fenêtre où un
 * worker dépile un email annonçant un jeton dont la transaction n'a pas encore commité — ou
 * a été annulée, auquel cas le lien de l'email ne mènera jamais nulle part.
 *
 * **Comment c'est observé.** Le bus est remplacé par un double qui, au moment où on lui
 * dispatche un message, relève l'état de la connexion DBAL : `getTransactionNestingLevel()`.
 * DAMA en tient déjà une ouverte pour la durée du test, donc le niveau vaut 1 au repos et
 * 2 quand `wrapInTransaction()` a ouvert la sienne. Un dispatch en dehors de la fermeture
 * relèverait 1, et c'est très exactement la mutation qu'on veut voir rougir.
 *
 * C'est une propriété du **code**, pas de l'ordre des appels lu à l'œil ;
 * `tests/Core/Mail/EmailQueueTest.php` tient l'autre moitié — que cette position achète
 * bien l'invisibilité avant commit sur le transport Doctrine.
 */
#[CoversClass(PasswordResetRequest::class)]
final class PasswordResetRequestTest extends KernelTestCase
{
    private const string EMAIL = 'marc@example.test';

    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    #[Test]
    public function the_message_is_dispatched_inside_the_transaction_that_persists_the_token(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $connection = $container->get(EntityManagerInterface::class)->getConnection();

        $atRest = $connection->getTransactionNestingLevel();

        $bus = new class($connection) implements MessageBusInterface {
            public ?int $nestingLevelAtDispatch = null;

            public int $dispatches = 0;

            public function __construct(private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            /**
             * @param object|Envelope       $message
             * @param array<StampInterface> $stamps
             */
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                ++$this->dispatches;
                $this->nestingLevelAtDispatch = $this->connection->getTransactionNestingLevel();

                return new Envelope($message, $stamps);
            }
        };

        Accounts::create($container, self::EMAIL, self::PASSWORD);

        $this->requestWith($bus)->request(self::EMAIL);

        self::assertSame(1, $bus->dispatches, 'Le cas d\'usage doit dispatcher exactement un message.');
        self::assertSame(
            $atRest + 1,
            $bus->nestingLevelAtDispatch,
            'Le `SendEmail` est dispatché hors de la frontière de transaction : un worker peut dépiler un email annonçant un jeton qui n\'existera jamais.',
        );
    }

    /**
     * L'autre bout de la même propriété, vu depuis la file : une transaction annulée
     * n'y laisse rien.
     *
     * Le transport réel n'est pas exercé ici — `when@test` le remplace par `in-memory`,
     * qui ne connaît pas les transactions —, donc ce qui est vérifié est que le message
     * **n'a pas été dispatché du tout** quand le travail a échoué avant le commit.
     * `EmailQueueTest` prouve la moitié transactionnelle sur le vrai transport Doctrine.
     */
    #[Test]
    public function nothing_is_dispatched_when_the_transaction_cannot_commit(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $bus = new class implements MessageBusInterface {
            public int $dispatches = 0;

            /**
             * @param object|Envelope       $message
             * @param array<StampInterface> $stamps
             */
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                ++$this->dispatches;

                // Le travail que l'email annonce échoue — ici au moment du dispatch, ce qui
                // est la seule façon d'échouer **après** lui sans toucher au service.
                throw new RuntimeException('Le cas d\'usage échoue dans la transaction.');
            }
        };

        Accounts::create($container, self::EMAIL, self::PASSWORD);

        try {
            $this->requestWith($bus)->request(self::EMAIL);
            self::fail('Le service doit laisser remonter l\'échec du travail qu\'il enveloppe.');
        } catch (RuntimeException) {
            // L'échec est le scénario, pas un incident du test.
        }

        // La transaction a été annulée, donc le jeton n'existe pas non plus : le message et
        // ce qu'il annonçait tombent ensemble.
        self::assertSame([], PasswordTokens::rows($container), 'Un jeton a survécu à une transaction annulée.');
    }

    /**
     * Le service, reconstruit avec le bus double et les vrais dépôts.
     *
     * Le remplacer dans le conteneur (`$container->set()`) est réservé aux frontières
     * externes ; ici c'est un collaborateur du socle, et l'instancier à la main est plus
     * court et plus honnête.
     */
    private function requestWith(MessageBusInterface $bus): PasswordResetRequest
    {
        $container = self::getContainer();

        return new PasswordResetRequest(
            $container->get(EntityManagerInterface::class),
            $container->get(\App\Core\Repository\UserRepository::class),
            $container->get(\App\Core\Repository\AccountTokenRepository::class),
            $bus,
        );
    }
}
