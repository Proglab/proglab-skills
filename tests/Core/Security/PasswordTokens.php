<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use App\Core\Entity\AccountToken;
use App\Core\Entity\User;
use App\Core\Enum\AccountTokenPurpose;
use App\Core\Message\SendEmail;
use App\Core\Repository\AccountTokenRepository;
use App\Core\Service\PasswordResetRequest;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Les jetons de réinitialisation vus depuis un test : en poser un, lire celui qui vient
 * d'être envoyé, le vieillir, et regarder ce que la base en garde.
 *
 * Trois classes de test en ont besoin — la matrice, le ralentissement et l'invalidation de
 * session — plus le plancher d'accessibilité, qui doit fabriquer un lien valide pour que
 * `/password/reset/{token}` rende un écran et non la page « lien expiré ». Le rituel vit
 * donc ici une fois, comme celui du limiteur de connexion vit dans `LoginThrottling`.
 *
 * **Le jeton en clair n'est jamais lu en base : il est lu sur la file.** C'est le seul
 * endroit où il existe encore après la requête, et c'est aussi ce qui rend la promesse
 * vérifiable — `theDatabaseNeverHoldsThePlainToken()` de `PasswordResetTest` interroge la
 * table et n'y trouve que des empreintes.
 */
final readonly class PasswordTokens
{
    /**
     * Le jeton en clair du dernier `SendEmail` mis en file, lu sur le transport
     * `in-memory` que `when@test` substitue au transport Doctrine.
     *
     * C'est le chemin réel : le contrôleur appelle le service, le service dispatche, et
     * ce que le destinataire recevra est ce que l'enveloppe porte. Fabriquer le jeton
     * dans le test à la place ferait vérifier le lien sur une valeur que la production
     * n'a jamais produite.
     */
    public static function sentToken(ContainerInterface $container): string
    {
        $sent = self::queue($container)->getSent();

        if ([] === $sent) {
            throw new RuntimeException('Aucun email n\'a été mis en file : il n\'y a pas de lien à ouvrir.');
        }

        $message = end($sent)->getMessage();

        if (!$message instanceof SendEmail) {
            throw new RuntimeException(\sprintf('La file porte un « %s » et non un `SendEmail`.', get_debug_type($message)));
        }

        $token = $message->context[PasswordResetRequest::CONTEXT_TOKEN] ?? null;

        if (!\is_string($token) || '' === $token) {
            throw new RuntimeException(\sprintf('Le message ne porte aucun jeton sous la clé « %s » : le lien de l\'email serait vide.', PasswordResetRequest::CONTEXT_TOKEN));
        }

        return $token;
    }

    /**
     * Le nombre d'emails mis en file depuis le démarrage du noyau.
     */
    public static function queuedCount(ContainerInterface $container): int
    {
        return \count(self::queue($container)->getSent());
    }

    /**
     * Pose un jeton vivant sans passer par le service, et rend sa valeur en clair.
     *
     * Réservé aux appelants qui ont besoin d'un lien **ouvrable** sans exercer la demande
     * — le plancher d'accessibilité, qui rend la page, et les scénarios qui veulent un
     * jeton d'une date précise. La matrice, elle, passe toujours par le vrai chemin.
     */
    public static function issue(ContainerInterface $container, User $user, ?DateTimeImmutable $expiresAt = null): string
    {
        $entityManager = $container->get(EntityManagerInterface::class);

        $plain = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable();

        $token = new AccountToken();
        $token->setUser($user)
            ->setPurpose(AccountTokenPurpose::PasswordReset)
            ->setTokenHash(AccountTokenRepository::fingerprint($plain))
            ->setCreatedAt($now)
            ->setExpiresAt($expiresAt ?? $now->add(new DateInterval(PasswordResetRequest::LIFETIME)));

        $entityManager->persist($token);
        $entityManager->flush();

        return $plain;
    }

    /**
     * Vieillit un jeton jusqu'à le périmer.
     *
     * L'expiration est une date en base, pas une horloge : la reculer est très exactement
     * ce que le temps ferait, et c'est la seule façon d'exercer « lien ouvert après une
     * heure » sans dormir une heure. `symfony/clock` n'est pas une dépendance de
     * production du socle, donc aucun service n'accepte d'horloge injectée.
     */
    public static function expire(ContainerInterface $container, string $plainToken): void
    {
        $entityManager = $container->get(EntityManagerInterface::class);
        $tokens = $container->get(AccountTokenRepository::class);

        $token = $tokens->findOneBy(['tokenHash' => AccountTokenRepository::fingerprint($plainToken)]);

        if (!$token instanceof AccountToken) {
            throw new RuntimeException('Le jeton à périmer n\'existe pas : le test croirait vérifier l\'expiration.');
        }

        $token->setExpiresAt(new DateTimeImmutable('-1 second'));
        $entityManager->flush();
    }

    /**
     * Tout ce que la table des jetons contient, lu en SQL brut.
     *
     * Brut, et pas par l'ORM : la promesse à tenir est « la base ne garde que l'empreinte
     * », donc il faut regarder les colonnes elles-mêmes, y compris celles qu'aucune
     * propriété d'entité n'exposerait.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(ContainerInterface $container): array
    {
        return $container->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeQuery('SELECT * FROM account_token')
            ->fetchAllAssociative();
    }

    private static function queue(ContainerInterface $container): InMemoryTransport
    {
        $transport = $container->get('messenger.transport.async');

        if (!$transport instanceof InMemoryTransport) {
            throw new RuntimeException('Le transport `async` n\'est plus `in-memory` sous test : la suite contacterait une vraie file.');
        }

        return $transport;
    }
}
