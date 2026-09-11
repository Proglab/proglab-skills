<?php

declare(strict_types=1);

namespace App\Tests\Core\Observability;

use App\Tests\Core\Quality\GateFiles;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * « Journalisée par canal » est une propriété, pas un fichier de configuration.
 *
 * Le second critère de la story demande qu'une 500 laisse une trace. Le socle n'avait
 * aucun magasin de journaux : `symfony/monolog-bundle` n'étant pas installé, le service
 * `logger` n'existait pas, et `ErrorListener::logException()` sortait sur
 * `if (!$logger = $this->getLogger($logChannel)) return;` — une 500 en production ne
 * laissait donc **aucune** trace, nulle part. Installer le bundle suffit : aucun code
 * applicatif n'est à écrire, le listener natif sait déjà router par canal et lire
 * `#[WithLogLevel]`.
 *
 * Ce qui reste à prouver est ce que personne ne relit : que le canal est bien celui
 * annoncé, que le niveau distingue la panne du simple 404, et que la forme de production
 * — `fingers_crossed` — laisse réellement passer la première et avale le second.
 *
 * **Ce que cette story ne pose pas, et qui n'est donc pas une omission.** Aucun processor
 * n'est installé : la ligne d'une 500 porte exactement ce que Symfony y met, et rien de
 * plus. C'est suffisant tant que le socle n'a ni utilisateur connecté ni formulaire — dès
 * que l'un des deux existe, le masquage des données personnelles et l'identifiant de
 * corrélation deviennent nécessaires, et ils appartiennent à la story d'observabilité. Le
 * test qui le dit est `no_processor_adds_anything_to_the_line_yet()` : il échouera le jour
 * où quelqu'un en ajoutera un sans revenir ici.
 */
final class ErrorLoggingTest extends WebTestCase
{
    private const string CONFIG = 'config/packages/monolog.yaml';

    /**
     * Le canal sur lequel Symfony tague `exception_listener`
     * (`framework-bundle/Resources/config/web.php` : `->tag('monolog.logger', ['channel' => 'request'])`).
     */
    private const string CHANNEL = 'request';

    #[Test]
    public function an_unexpected_failure_writes_one_critical_line_on_its_channel(): void
    {
        $client = self::createClient(['debug' => false]);
        $handler = self::testHandler();

        $client->request('GET', '/demo/errors/unexpected');

        self::assertResponseStatusCodeSame(500);

        $critical = self::recordsAt($handler, Level::Critical);

        self::assertCount(
            1,
            $critical,
            'Une 500 doit laisser exactement une ligne `critical` : sans elle, une panne en production ne réveille personne.',
        );

        self::assertSame(
            self::CHANNEL,
            $critical[0]->channel,
            'La ligne n\'est pas écrite sur le canal annoncé — un filtre de handler par canal ne la verrait jamais.',
        );
    }

    /**
     * Une page introuvable n'est pas une panne.
     *
     * Symfony la journalise au niveau qu'il lui donne — `error`, parce que c'est une
     * `HttpExceptionInterface` de statut < 500 —, et c'est justement pour cela que `error`
     * n'est pas un seuil d'alerte utilisable : sans exclusion, chaque adresse tapée de
     * travers déclencherait le vidage du buffer. `excluded_http_codes` est ce qui fait
     * qu'elle ne réveille personne.
     */
    #[Test]
    public function a_missing_page_wakes_nobody(): void
    {
        $client = self::createClient(['debug' => false]);
        $handler = self::testHandler();

        $client->request('GET', '/une-page-qui-n-existe-pas');

        self::assertResponseStatusCodeSame(404);

        self::assertSame(
            [],
            self::recordsAt($handler, Level::Critical),
            'Une 404 a écrit une ligne `critical` : le statut d\'un client devient une panne, et le seuil d\'alerte perd tout son sens.',
        );

        self::assertSame(
            [],
            $handler->getRecords(),
            'Une 404 a déclenché le vidage du buffer : `excluded_http_codes` ne couvre plus 404, et chaque adresse tapée de travers déverse la trace complète de sa requête.',
        );
    }

    /**
     * La 403 non plus n'est pas une panne — même raison, autre statut.
     */
    #[Test]
    public function a_refused_access_wakes_nobody(): void
    {
        $client = self::createClient(['debug' => false]);
        $handler = self::testHandler();

        $client->request('GET', '/demo/errors/forbidden');

        self::assertResponseStatusCodeSame(403);

        self::assertSame(
            [],
            self::recordsAt($handler, Level::Critical),
            'Une 403 a écrit une ligne `critical` : un accès refusé n\'est pas une panne.',
        );

        // L'assertion qui porte réellement le nom de ce test. Une `AccessDeniedHttpException`
        // est journalisée en `error` — exactement `action_level` —, donc sans 403 dans
        // `excluded_http_codes` chaque accès refusé viderait le buffer entier. L'absence de
        // `critical` ne peut pas le voir : un vidage n'en produit jamais.
        self::assertSame(
            [],
            $handler->getRecords(),
            'Une 403 a déclenché le vidage du buffer : `excluded_http_codes` ne couvre plus 403, et chaque accès refusé déverse la trace complète de sa requête.',
        );
    }

    /**
     * « Sans donnée personnelle » est tenu par ce qu'on n'écrit pas.
     *
     * Le `context` porte ce que l'appelant a passé — ici l'unique clé `exception` que
     * `ErrorListener` y met — et `extra` porte ce qu'un processor a ajouté. `extra` vide
     * est donc la preuve qu'aucun processor n'est installé : ni utilisateur courant, ni
     * identifiant de corrélation, ni masquage. C'est un état de fait daté, pas une cible :
     * la story d'observabilité posera les trois, et ce test devra être renégocié avec elle
     * plutôt que contourné.
     */
    #[Test]
    public function no_processor_adds_anything_to_the_line_yet(): void
    {
        $client = self::createClient(['debug' => false]);
        $handler = self::testHandler();

        $client->request('GET', '/demo/errors/unexpected');

        $critical = self::recordsAt($handler, Level::Critical);

        self::assertCount(1, $critical);
        self::assertSame(['exception'], array_keys($critical[0]->context));
        self::assertSame(
            [],
            $critical[0]->extra,
            'Un processor enrichit désormais les lignes de journal : relire `sensitive-data` avant de le garder — le socle n\'a ni masquage, ni politique de rétention.',
        );
    }

    /**
     * La forme de production, que seul un fichier peut dire.
     *
     * Aucun test fonctionnel ne boote l'environnement `prod`, et c'est pourtant le seul
     * qui compte pour le second critère. La configuration est donc lue ici comme un
     * contrat : un handler par environnement, `main` en `fingers_crossed` hors
     * développement, et les statuts qui ne déclenchent pas le vidage.
     */
    #[Test]
    public function the_configuration_declares_one_handler_per_environment(): void
    {
        foreach (['when@dev', 'when@test', 'when@prod'] as $environment) {
            self::assertNotSame(
                [],
                self::handlersOf($environment),
                \sprintf('« %s » ne déclare aucun handler pour « %s » : cet environnement n\'écrit nulle part.', self::CONFIG, $environment),
            );
        }

        foreach (['when@test', 'when@prod'] as $environment) {
            $main = self::handlersOf($environment)['main'] ?? null;

            self::assertIsArray($main, \sprintf('« %s » n\'a plus de handler `main`.', $environment));

            self::assertSame(
                'fingers_crossed',
                $main['type'] ?? null,
                \sprintf('Le handler `main` de « %s » n\'est plus en `fingers_crossed` : une requête qui fonctionne se remettrait à écrire, et la trace qui mène à l\'échec disparaîtrait.', $environment),
            );

            $excluded = $main['excluded_http_codes'] ?? null;

            self::assertIsArray($excluded, \sprintf('Le handler `main` de « %s » ne déclare plus `excluded_http_codes`.', $environment));

            foreach ([403, 404] as $status) {
                self::assertContains(
                    $status,
                    $excluded,
                    \sprintf('Le handler `main` de « %s » ne filtre plus les %d : un statut de client déverserait la trace complète de sa requête.', $environment, $status),
                );
            }
        }

        // Et les deux listes face à face. `when@test` se présente comme « la forme de
        // production » : un filtre qui diverge d'un seul nom de canal, et ce que la suite
        // observe cesse d'être ce que la production écrit.
        $test = self::handlersOf('when@test')['main'] ?? null;
        $prod = self::handlersOf('when@prod')['main'] ?? null;

        self::assertIsArray($test);
        self::assertIsArray($prod);

        foreach (['channels', 'excluded_http_codes', 'action_level'] as $key) {
            self::assertSame(
                $prod[$key] ?? null,
                $test[$key] ?? null,
                \sprintf('Le `%s` du handler `main` diverge entre `when@test` et `when@prod` : la suite n\'observe plus la forme de production.', $key),
            );
        }
    }

    /**
     * Les handlers déclarés par un bloc `when@<env>` du fichier, lus tels qu'ils sont
     * écrits plutôt que tels que le conteneur les a fusionnés : c'est la **déclaration**
     * qui est le contrat, et c'est elle qu'une relecture doit pouvoir suivre.
     *
     * @return array<array-key, mixed>
     */
    private static function handlersOf(string $environment): array
    {
        $config = Yaml::parse(GateFiles::read(self::CONFIG));

        if (!\is_array($config) || !\is_array($block = $config[$environment] ?? null)) {
            return [];
        }

        if (!\is_array($monolog = $block['monolog'] ?? null) || !\is_array($handlers = $monolog['handlers'] ?? null)) {
            return [];
        }

        return $handlers;
    }

    /**
     * Le handler de test, tel que `when@test` le déclare.
     *
     * `TestHandler` garde ses enregistrements en mémoire : un test vert n'écrit rien sur
     * le disque, et la suite ne dépend d'aucun fichier de log à nettoyer.
     */
    private static function testHandler(): TestHandler
    {
        $handler = self::getContainer()->get('monolog.handler.testing');

        self::assertInstanceOf(
            TestHandler::class,
            $handler,
            \sprintf('« %s » ne déclare plus de handler `testing` de type `test` sous `when@test` : rien ne peut plus observer ce que le socle journalise.', self::CONFIG),
        );

        return $handler;
    }

    /**
     * @return list<LogRecord>
     */
    private static function recordsAt(TestHandler $handler, Level $level): array
    {
        return array_values(array_filter(
            $handler->getRecords(),
            static fn (LogRecord $record): bool => $record->level === $level,
        ));
    }
}
