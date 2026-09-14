<?php

declare(strict_types=1);

namespace App\Tests\Core\Mail;

use App\Core\Enum\SupportedLocale;
use App\Core\Message\SendEmail;
use Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Les deux lignes de la matrice que ni `EmailQueueTest` ni `SendEmailHandlerTest` ne
 * couvraient : « SMTP injoignable » et « appel direct du mailer ».
 *
 * **Aucun worker n'est lancé en sous-processus et aucun SMTP n'est contacté.** Le
 * `Worker` de Messenger est piloté **en processus**, sur les transports `in-memory://` du
 * conteneur de test et sur le **vrai** répartiteur d'événements — donc sur les vrais
 * écouteurs de retry et de file d'échec, configurés par
 * `config/packages/messenger.yaml`. Seul le bus est un double, parce que c'est le seul
 * moyen de faire échouer l'envoi sans SMTP : c'est le « SMTP injoignable » de la matrice,
 * réduit à ce qu'il produit — une exception qui remonte du handler.
 *
 * **Ce que ces tests prouvent, et ce qu'ils ne prouvent pas.** Ils prouvent le nombre de
 * tentatives, le fait que chaque reprise est réellement **différée** dans le temps, la
 * destination finale et la persistance du message dans la file d'échec — le tout sur la
 * configuration que le conteneur a compilée. L'horloge est simulée
 * (`ClockSensitiveTrait`) et le transport `in-memory` l'interroge pour décider qu'un
 * message différé redevient disponible, donc la suite ne dort jamais : attendre les
 * vrais délais coûterait sept secondes à chaque exécution.
 *
 * Ce qu'ils ne prouvent pas : la **durée** exacte de chaque report, que l'horloge
 * simulée court-circuite par construction. Elle est vérifiée séparément, sur la stratégie
 * de retry elle-même, là où elle est calculée.
 */
#[CoversNothing]
final class EmailFailurePolicyTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use MailerAssertionsTrait;

    /**
     * Ce que `retry_strategy` déclare : trois tentatives, pas quatre.
     */
    private const int MAX_RETRIES = 3;

    /**
     * Le plafond du worker piloté en processus. Il est largement au-dessus de
     * `MAX_RETRIES + 1` : il n'est pas là pour borner le scénario — c'est l'inactivité de
     * la file qui s'en charge — mais pour qu'une politique de retry sans fin échoue en
     * une assertion plutôt qu'en épuisement de mémoire.
     */
    private const int WORKER_HARD_STOP = 20;

    /**
     * SMTP injoignable, première moitié : le worker réessaie trois fois, puis la ligne
     * part sur le transport `failed` — et elle y reste.
     *
     * L'assertion centrale est la suite des passages du worker : `[1, 1, 1, 1, 0]` —
     * quatre passages qui consomment un message chacun (l'envoi initial puis trois
     * reprises, chacune différée, sans quoi elles seraient consommées dans le même
     * passage), et un cinquième qui ne trouve plus rien. Vérifié par mutation :
     * `max_retries: 1` la réduit à `[1, 1, 0]`, `max_retries: 5` l'allonge, et les deux
     * font rougir ce test.
     */
    #[Test]
    public function an_unreachable_smtp_is_retried_three_times_then_parked_in_the_failure_transport(): void
    {
        self::bootKernel();

        // L'horloge du conteneur est simulée : c'est elle que le transport `in-memory`
        // interroge pour décider qu'un message différé est redevenu disponible.
        $clock = static::mockTime();

        $async = $this->transport('async');
        $failed = $this->transport('failed');

        $async->send(new Envelope(new SendEmail(
            'recipient@example.test',
            'Alice Demo',
            SupportedLocale::Fr,
            'demo.subject',
            'emails/demo.html.twig',
            ['widget' => 'Établi n°7'],
        )));

        // Un bus qui échoue toujours : le « SMTP injoignable » de la matrice, réduit à ce
        // qu'il produit du point de vue du worker. Tout le reste — la stratégie de retry,
        // le nombre de tentatives, la destination de l'échec — vient du conteneur.
        //
        // Déclaré ici et non dans une méthode privée : une classe anonyme n'a pas de nom
        // à écrire en type de retour, et son compteur ne serait plus lisible.
        $bus = new class implements MessageBusInterface {
            public int $calls = 0;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                ++$this->calls;

                throw new RuntimeException('Connexion impossible avec le serveur SMTP.');
            }
        };

        $dispatcher = $this->eventDispatcher();

        // **Le worker s'arrête dès que la file est vide, et on le relance.** Une reprise
        // n'est jamais disponible tout de suite : le `DelayStamp` la diffère, et le
        // transport `in-memory` honore ce délai sur l'horloge du conteneur. Compter les
        // passages plutôt que les messages est donc ce qui rend le **report** observable,
        // en plus du nombre de tentatives — et faire avancer une horloge simulée entre
        // deux passages coûte zéro seconde, là où attendre les vrais délais en coûterait
        // sept à chaque exécution de la suite.
        $dispatcher->addSubscriber(new class implements EventSubscriberInterface {
            public static function getSubscribedEvents(): array
            {
                return [WorkerRunningEvent::class => 'onWorkerRunning'];
            }

            public function onWorkerRunning(WorkerRunningEvent $event): void
            {
                if ($event->isWorkerIdle()) {
                    $event->getWorker()->stop();
                }
            }
        });

        // Le filet, et il a servi : une politique qui retenterait sans fin (le
        // `forceRetry` de `RecoverableMessageHandlingException`, par exemple) ferait
        // tourner ces passages jusqu'à épuisement de la mémoire du processus PHPUnit —
        // constaté. Avec ce plafond, l'échec reste une assertion.
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(self::WORKER_HARD_STOP));

        /** @var list<int> $consumed */
        $consumed = [];

        for ($round = 0; $round < self::WORKER_HARD_STOP; ++$round) {
            $before = $bus->calls;

            // Un `Worker` neuf par passage : `stop()` pose un drapeau que `run()` ne
            // remet pas à zéro.
            new Worker(['async' => $async], $bus, $dispatcher)->run(['sleep' => 0]);

            $consumed[] = $bus->calls - $before;

            if ($bus->calls === $before) {
                break;
            }

            $clock->sleep(60);
        }

        // Quatre passages qui consomment un message chacun — l'envoi initial, puis trois
        // reprises, chacune réellement différée dans le temps — et un cinquième qui ne
        // trouve plus rien. C'est l'assertion centrale : elle tombe à `[1, 1, 0]` si
        // `max_retries` vaut 1, et s'allonge s'il vaut davantage.
        self::assertSame([1, 1, 1, 1, 0], $consumed);
        self::assertSame(self::MAX_RETRIES + 1, $bus->calls);

        // La ligne est partie sur `failed`, et une seule fois.
        $parked = $failed->getSent();
        self::assertCount(1, $parked);
        self::assertInstanceOf(SendEmail::class, $parked[0]->getMessage());

        // Et elle y reste : rien ne l'a acquittée ni rejetée sur ce transport.
        self::assertSame([], $failed->getAcknowledged());
        self::assertSame([], $failed->getRejected());

        // Le transport nominal, lui, est vide : le message n'y est pas revenu une
        // cinquième fois.
        self::assertSame([], $async->get());
    }

    /**
     * SMTP injoignable, seconde moitié : le délai croît, et la quatrième tentative n'a
     * pas lieu.
     *
     * La stratégie est celle du conteneur, pas une relecture du YAML. Les bornes sont
     * larges parce que `jitter: 0.3` randomise chaque délai de ±30 % — c'est voulu, pour
     * que tous les workers ne rejouent pas à la même milliseconde contre un SMTP qui vient
     * de tomber. Les trois bandes ne se chevauchent pas (1300 < 1400, 2600 < 2800), donc
     * « le délai croît » reste une assertion et pas une approximation.
     *
     * @param int<0, max> $retryCount
     */
    #[Test]
    #[DataProvider('growingDelays')]
    public function the_retry_delay_grows_and_the_fourth_attempt_is_refused(
        int $retryCount,
        bool $retryable,
        int $lowerBound,
        int $upperBound,
    ): void {
        self::bootKernel();

        $strategy = $this->retryStrategy();
        $envelope = new Envelope(new SendEmail(
            'recipient@example.test',
            'Alice Demo',
            SupportedLocale::Fr,
            'demo.subject',
            'emails/demo.html.twig',
        ), [new RedeliveryStamp($retryCount)]);

        self::assertSame($retryable, $strategy->isRetryable($envelope));

        if (!$retryable) {
            return;
        }

        $delay = $strategy->getWaitingTime($envelope);

        self::assertGreaterThanOrEqual($lowerBound, $delay);
        self::assertLessThanOrEqual($upperBound, $delay);
    }

    /**
     * @return Generator<string, array{int<0, max>, bool, int, int}>
     */
    public static function growingDelays(): Generator
    {
        // 1000 ms, 2000 ms, 4000 ms, chacun ±30 % de jitter.
        yield 'première reprise, autour d\'une seconde' => [0, true, 700, 1300];
        yield 'deuxième reprise, autour de deux secondes' => [1, true, 1400, 2600];
        yield 'troisième reprise, autour de quatre secondes' => [2, true, 2800, 5200];
        yield 'quatrième tentative : refusée' => [3, false, 0, 0];
    }

    /**
     * Les deux transports déclarent des **files différentes**, et aucun des deux ne crée
     * sa table à l'exécution.
     *
     * **Pourquoi une assertion sur la configuration et pas sur le comportement.** Sous
     * `when@test` les deux transports sont `in-memory://`, et
     * `InMemoryTransportFactory::createTransport()` ignore intégralement son tableau
     * `$options` (lu dans `vendor/`) : `queue_name` n'a donc aucun effet observable dans
     * la suite. Supprimer `queue_name: failed` laisserait tous les tests verts et
     * enverrait, en production, les messages en échec sur la file que le worker consomme
     * — une boucle infinie, découverte chez le client. La configuration compilée est le
     * seul endroit où cette propriété existe sous test, donc c'est là qu'elle est tenue.
     *
     * C'est bien la configuration **compilée**, lue par la commande qui résout le
     * conteneur, `when@test` compris — pas une relecture du YAML. Seul le DSN diffère de
     * la production ; les options sont les mêmes des deux côtés.
     */
    #[Test]
    public function the_two_transports_declare_different_queues_and_never_set_themselves_up(): void
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $tester->run([
            'command' => 'debug:config',
            'name' => 'framework',
            'path' => 'messenger.transports',
            '--format' => 'json',
        ]);

        // La commande imprime un titre avant le JSON.
        $json = strstr($tester->getDisplay(), '{');
        self::assertIsString($json);

        $transports = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($transports);

        $queues = [];
        $autoSetup = [];

        foreach (['async', 'failed'] as $name) {
            $transport = $transports[$name] ?? null;
            self::assertIsArray($transport);

            $options = $transport['options'] ?? null;
            self::assertIsArray($options);

            $queues[$name] = $options['queue_name'] ?? null;
            $autoSetup[$name] = $options['auto_setup'] ?? null;
        }

        // Deux files distinctes : un message en échec ne revient pas dans la file que le
        // worker dépile.
        self::assertSame(['async' => 'default', 'failed' => 'failed'], $queues);

        // Et aucun DDL à l'exécution, des deux côtés : la table vient d'une migration.
        self::assertSame(['async' => false, 'failed' => false], $autoSetup);
    }

    /**
     * Appel direct du mailer : l'envoi est **synchrone**, et rien ne le rend asynchrone
     * par surprise.
     *
     * C'est le garde-fou du `SendEmailMessage` non routé, et il mécanise le premier
     * critère d'acceptation de la story — `debug:messenger` ne liste que les handlers, pas
     * le routage. Le jour où quelqu'un ajoute `SendEmailMessage: async` dans
     * `config/packages/messenger.yaml`, l'envoi que `App\Core\Service\EmailSender` fait
     * **déjà depuis le worker** repartirait en file une seconde fois. Ce test le dit tout
     * de suite : `assertEmailCount` tombe à zéro.
     *
     * **`assertQueuedEmailCount(0)` n'est pas le garde-fou, et c'est contre-intuitif.**
     * Dès que Messenger est installé, `Mailer::send()` dispatche **toujours** un
     * `MessageEvent` avec `queued = true` sur un **clone** du message
     * (`vendor/symfony/mailer/Mailer.php:50-53`) — pour que les écouteurs puissent
     * l'inspecter — puis passe le message au bus. Comme `SendEmailMessage` n'est routé
     * nulle part, le bus le traite sur place et un second `MessageEvent`, celui-là avec
     * `queued = false`, est dispatché à l'envoi réel. **Le compte « queued » vaut donc 1
     * que l'envoi soit synchrone ou non** : il ne discrimine rien. C'est le compte
     * **non** queued — celui d'`assertEmailCount()`, qui n'existe que si le message a
     * réellement été remis — qui distingue les deux mondes. Vérifié : c'est cette
     * assertion-là qui rougit quand on route `SendEmailMessage`.
     */
    #[Test]
    public function a_direct_call_to_the_mailer_sends_synchronously(): void
    {
        self::bootKernel();

        self::getContainer()->get(MailerInterface::class)->send(
            new Email()
                ->from(new Address('no-reply@example.test'))
                ->to(new Address('recipient@example.test'))
                ->subject('Un envoi direct, hors du chemin du socle')
                ->text('Le corps ne compte pas : ce qui compte est de ne pas être en file.'),
        );

        // Remis pour de vrai, dans l'appel : c'est la définition de « synchrone » ici.
        self::assertEmailCount(1);

        // Et le clone « queued » du Mailer, qui vaut 1 dans les deux cas, est nommé pour
        // que personne ne croie plus tard que ce test l'a oublié.
        self::assertQueuedEmailCount(1);
    }

    /**
     * Et la même chose lue dans le conteneur plutôt que dans le YAML : le routage
     * compilé n'envoie `SendEmailMessage` vers aucun transport, et `SendEmail` vers
     * `async`.
     *
     * `SendersLocator::getSenders()` est ce que `SendMessageMiddleware` interroge à chaque
     * dispatch : c'est la carte réellement en vigueur, résolue `when@test` compris.
     */
    #[Test]
    public function the_compiled_routing_sends_the_mailer_message_nowhere(): void
    {
        self::bootKernel();

        $senders = self::getContainer()->get('messenger.senders_locator');

        $mailerMessage = new Envelope(new SendEmailMessage(
            new Email()
                ->from(new Address('no-reply@example.test'))
                ->to(new Address('recipient@example.test'))
                ->text('.'),
        ));

        self::assertSame([], array_keys(iterator_to_array($senders->getSenders($mailerMessage))));

        $ours = new Envelope(new SendEmail(
            'recipient@example.test',
            'Alice Demo',
            SupportedLocale::Fr,
            'demo.subject',
            'emails/demo.html.twig',
        ));

        self::assertSame(['async'], array_keys(iterator_to_array($senders->getSenders($ours))));
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function retryStrategy(): RetryStrategyInterface
    {
        $strategy = self::getContainer()->get('messenger.retry_strategy_locator')->get('async');
        self::assertInstanceOf(RetryStrategyInterface::class, $strategy);

        return $strategy;
    }

    private function eventDispatcher(): EventDispatcherInterface
    {
        return self::getContainer()->get('event_dispatcher');
    }
}
