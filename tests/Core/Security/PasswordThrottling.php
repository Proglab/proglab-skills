<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Le limiteur de la demande de réinitialisation vu depuis un test : l'oublier, et lire ce
 * qu'il reste à attendre.
 *
 * C'est le pendant de `LoginThrottling` pour le limiteur **nommé à part** de la story 1.9.
 * Les deux existent séparément parce que les deux limiteurs sont séparés : la preuve que
 * `password_request` n'est pas `login_attempt` est que dépenser l'un ne bouge pas l'autre,
 * et un helper unique cacherait exactement cette distinction.
 *
 * Comme pour la connexion, **les secondes attendues se lisent sur le limiteur** —
 * `peek()` ne consomme aucun jeton — et jamais dans le texte rendu : un test qui relit le
 * message pour vérifier le message ne vérifie rien.
 */
final readonly class PasswordThrottling
{
    /**
     * L'alias public posé sous `when@test` dans `config/services.yaml`, avec sa consigne
     * de retrait.
     */
    private const string LIMITER = 'app.test.password_request_limiter';

    /**
     * Vide le magasin des limiteurs.
     *
     * Le pool `cache.rate_limiter` est **partagé** par tous les limiteurs du socle, donc
     * le rituel est celui de la story 1.7 et il n'est pas dédoublé ici : deux versions
     * d'un même geste divergeraient au premier changement de pool.
     */
    public static function forget(): void
    {
        LoginThrottling::forget();
    }

    /**
     * Les secondes qu'il reste à attendre pour cette adresse IP, brutes.
     */
    public static function secondsLeft(ContainerInterface $container, string $ip = LoginThrottling::IP): int
    {
        return self::peek($container, $ip)->getRetryAfter()->getTimestamp() - time();
    }

    /**
     * Les jetons encore disponibles avant le refus.
     */
    public static function remainingAttempts(ContainerInterface $container, string $ip = LoginThrottling::IP): int
    {
        return self::peek($container, $ip)->getRemainingTokens();
    }

    /**
     * L'état du limiteur, **sans rien consommer**.
     *
     * `LimiterInterface` n'a pas de `peek()` — c'est `DefaultLoginRateLimiter` qui en
     * expose un, parce qu'il implémente `PeekableRequestRateLimiterInterface`. Sur un
     * limiteur nu, la lecture sans consommation s'écrit `consume(0)` : zéro jeton
     * demandé, donc zéro jeton pris, et le `RateLimit` rendu porte quand même les jetons
     * restants et la fin de la fenêtre.
     */
    private static function peek(ContainerInterface $container, string $ip): RateLimit
    {
        return self::limiter($container, $ip)->consume(0);
    }

    private static function limiter(ContainerInterface $container, string $ip): LimiterInterface
    {
        $factory = $container->get(self::LIMITER);

        if (!$factory instanceof RateLimiterFactoryInterface) {
            throw new RuntimeException(\sprintf('L\'alias de test « %s » n\'expose plus le limiteur de la demande : les secondes attendues ne pourraient plus être lues ailleurs que dans le texte rendu.', self::LIMITER));
        }

        return $factory->create($ip);
    }
}
