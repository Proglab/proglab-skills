<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use App\Kernel;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Le ralentissement de connexion vu depuis un test : l'oublier, le lire, et le vieillir.
 *
 * L'état du limiteur ne vit **pas** en base : il vit dans le pool `cache.rate_limiter`,
 * qui est un adaptateur de fichiers sous `var/cache/test/`. DAMA n'a donc aucune prise
 * dessus — il survit à la méthode de test qui l'a produit, à la classe entière, et même
 * à l'exécution : la fenêtre du limiteur global dure un quart d'heure, donc deux `make
 * test` lancés coup sur coup partageraient leurs compteurs. Toute classe de test qui
 * soumet le formulaire de connexion appelle donc `forget()` dans son `setUp()`.
 *
 * Les deux autres méthodes existent parce que deux lignes de la matrice de la story ne
 * sont pas atteignables autrement :
 *
 * - **« Après le délai »** et **« une seconde restante »** demandent qu'une minute
 *   s'écoule. `FixedWindowLimiter` lit l'heure par `microtime(true)` et non par une
 *   `ClockInterface` : ni `MockClock` ni aucun réglage de configuration ne peut la
 *   décaler. `rewind()` recule donc le `timer` de la fenêtre stockée, ce qui est très
 *   exactement ce que le temps ferait — le compteur de coups est conservé, et la fenêtre
 *   se périme d'elle-même quand le recul dépasse son intervalle. Ce n'est **pas** un
 *   `reset()` : remettre le limiteur à zéro prouverait seulement qu'un limiteur vide
 *   laisse passer, ce que tout autre test de connexion prouve déjà.
 * - **Les secondes attendues** se lisent sur le limiteur (`peek()`, qui consomme zéro
 *   jeton), jamais sur le texte rendu : un test qui relit le message pour vérifier le
 *   message ne vérifie rien.
 *
 * Les deux services atteints ici sont des alias publics posés sous `when@test` dans
 * `config/services.yaml`, avec leur consigne de retrait.
 */
final readonly class LoginThrottling
{
    /**
     * L'adresse que `KernelBrowser` présente par défaut. Le limiteur indexe sur
     * identifiant + IP : la nommer est ce qui permet à un test d'en présenter une autre.
     */
    public const string IP = '127.0.0.1';

    private const string POOL = 'app.test.rate_limiter_pool';

    private const string LIMITER = 'app.test.login_rate_limiter';

    /**
     * Vide le magasin du limiteur — le seul endroit où vit l'état du ralentissement.
     *
     * **Le rituel entier est ici**, et les trois classes de test qui soumettent le
     * formulaire de connexion se contentent de l'appeler depuis leur `setUp()`. Il ouvre
     * son propre noyau plutôt que de passer par `KernelTestCase` : `createClient()` refuse
     * de s'exécuter derrière un noyau déjà démarré, et un `bootKernel()` suivi d'un
     * `ensureKernelShutdown()` recopié trois fois est exactement la duplication que ce
     * helper existe pour éviter. Le pool est un fichier partagé par tous les noyaux du
     * processus : le vider depuis celui-ci le vide pour le client qui suivra.
     */
    public static function forget(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();

        try {
            $pool = $kernel->getContainer()->get(self::POOL);

            if (!$pool instanceof CacheItemPoolInterface) {
                throw new RuntimeException(\sprintf('L\'alias de test « %s » n\'expose plus le pool du limiteur : l\'état du ralentissement fuirait d\'une méthode de test à l\'autre.', self::POOL));
            }

            $pool->clear();
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * Les secondes qu'il reste à attendre, lues sur le limiteur lui-même — **brutes**.
     *
     * Aucun plancher ici, volontairement : recopier celui de la production ferait comparer
     * une formule à sa propre copie, et le supprimer du code ne rougirait rien. Le
     * plancher est une règle de `LoginFailureMessage`, et c'est `LoginFailureMessageTest`
     * qui l'épingle.
     */
    public static function secondsLeft(ContainerInterface $container, string $email, string $ip = self::IP): int
    {
        $retryAfter = self::limiter($container)->peek(self::request($email, $ip))->getRetryAfter();

        return $retryAfter->getTimestamp() - time();
    }

    /**
     * Les jetons encore disponibles avant le refus, lus sur le limiteur lui-même.
     */
    public static function remainingAttempts(ContainerInterface $container, string $email, string $ip = self::IP): int
    {
        return self::limiter($container)->peek(self::request($email, $ip))->getRemainingTokens();
    }

    /**
     * Recule la fenêtre des limiteurs de `$seconds` secondes.
     *
     * Un limiteur sans fenêtre stockée est sauté — c'est le cas normal de celui qu'aucune
     * tentative n'a encore touché —, mais n'en vieillir **aucune** est un helper devenu
     * inopérant : le test qui l'appelle échouerait alors en accusant la connexion.
     */
    public static function rewind(ContainerInterface $container, int $seconds, string $email, string $ip = self::IP): void
    {
        $rewound = 0;

        foreach (self::limiters($container, $email, $ip) as $limiter) {
            $storage = self::storageOf($limiter);
            $window = $storage->fetch(self::idOf($limiter));

            if (null === $window) {
                continue;
            }

            if (!$window instanceof Window) {
                throw new RuntimeException(\sprintf('Le limiteur ne stocke plus une `Window` mais un « %s » : `rewind()` ne sait plus vieillir cet état.', $window::class));
            }

            $timer = new ReflectionProperty($window, 'timer');
            $started = $timer->getValue($window);

            if (!\is_float($started)) {
                throw new RuntimeException('La fenêtre du limiteur ne porte plus d\'horodatage flottant : `rewind()` ne peut plus la vieillir.');
            }

            $timer->setValue($window, $started - $seconds);
            $storage->save($window);

            ++$rewound;
        }

        if (0 === $rewound) {
            throw new RuntimeException(\sprintf('Aucune fenêtre à vieillir pour « %s » depuis %s : le helper n\'a rien fait, et le test qui l\'appelle croira tester le délai.', $email, $ip));
        }
    }

    private static function limiter(ContainerInterface $container): PeekableRequestRateLimiterInterface
    {
        $limiter = $container->get(self::LIMITER);

        if (!$limiter instanceof PeekableRequestRateLimiterInterface) {
            throw new RuntimeException(\sprintf('L\'alias de test « %s » n\'expose plus un limiteur lisible sans consommation : les secondes attendues ne peuvent plus être lues ailleurs que dans le message rendu.', self::LIMITER));
        }

        return $limiter;
    }

    /**
     * La requête telle que le pare-feu la présente au limiteur : l'identifiant soumis est
     * posé en attribut par `LoginThrottlingListener::checkPassport()`, et c'est de lui
     * que `DefaultLoginRateLimiter` tire la clé locale.
     */
    private static function request(string $email, string $ip): Request
    {
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return $request;
    }

    /**
     * @return list<LimiterInterface>
     */
    private static function limiters(ContainerInterface $container, string $email, string $ip): array
    {
        $found = new ReflectionMethod(self::limiter($container), 'getLimiters')
            ->invoke(self::limiter($container), self::request($email, $ip));

        if (!\is_array($found)) {
            throw new RuntimeException('`DefaultLoginRateLimiter::getLimiters()` ne rend plus une liste de limiteurs.');
        }

        $limiters = [];

        foreach ($found as $limiter) {
            if (!$limiter instanceof LimiterInterface) {
                throw new RuntimeException('`DefaultLoginRateLimiter::getLimiters()` rend autre chose qu\'un limiteur.');
            }

            $limiters[] = $limiter;
        }

        return $limiters;
    }

    private static function storageOf(LimiterInterface $limiter): StorageInterface
    {
        $storage = new ReflectionProperty($limiter, 'storage')->getValue($limiter);

        if (!$storage instanceof StorageInterface) {
            throw new RuntimeException('Le limiteur ne porte plus son magasin sous « storage ».');
        }

        return $storage;
    }

    private static function idOf(LimiterInterface $limiter): string
    {
        $id = new ReflectionProperty($limiter, 'id')->getValue($limiter);

        if (!\is_string($id)) {
            throw new RuntimeException('Le limiteur ne porte plus son identifiant sous « id ».');
        }

        return $id;
    }
}
