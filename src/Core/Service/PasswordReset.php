<?php

declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Entity\AccountToken;
use App\Core\Enum\AccountTokenPurpose;
use App\Core\Repository\AccountTokenRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * « Quelqu'un ouvre un lien, puis choisit un mot de passe. ».
 *
 * Deux questions, et elles sont volontairement séparées : `accepts()` répond au GET — ce
 * lien ouvre-t-il encore quelque chose ? — et `reset()` au POST. Les deux posent la même
 * question à la base, et c'est voulu : entre l'affichage du formulaire et sa soumission, le
 * lien a pu expirer, être remplacé par une demande plus récente, ou le compte a pu être
 * désactivé. Rejouer la question au moment d'agir est ce qui rend le rejeu inoffensif.
 *
 * **Un jeton n'est consommé que si le mot de passe change vraiment.** Tout se passe dans
 * une seule transaction : la relecture du jeton, le rehachage, l'incrément du jeton de
 * sécurité et la date d'usage. Une saisie refusée par la validation n'arrive jamais
 * jusqu'ici — le contrôleur rerend en 422 — donc le lien reste utilisable, ce que la
 * matrice exige.
 *
 * **Le compte désactivé est revérifié à chaque passage**, et pas seulement à l'émission :
 * un lien émis avant la désactivation ne doit pas survivre à celle-ci.
 */
final readonly class PasswordReset
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountTokenRepository $tokens,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * Ce lien ouvre-t-il encore quelque chose ?
     *
     * Une seule réponse booléenne pour les quatre façons dont un lien peut être mort —
     * inconnu, malformé, expiré, déjà consommé — plus la cinquième, le compte désactivé.
     * Les distinguer permettrait à qui essaie des jetons au hasard d'apprendre lesquels
     * ont existé ; c'est pour cela que l'appelant ne reçoit pas de raison.
     */
    public function accepts(string $plainToken): bool
    {
        return null !== $this->live($plainToken, new DateTimeImmutable());
    }

    /**
     * Change le mot de passe et consomme le jeton, ou ne fait rien.
     *
     * Rend `false` exactement quand `accepts()` aurait rendu `false` — au moment de
     * l'action, pas au moment de l'affichage.
     */
    public function reset(string $plainToken, string $plainPassword): bool
    {
        return $this->entityManager->wrapInTransaction(function () use ($plainToken, $plainPassword): bool {
            $now = new DateTimeImmutable();

            // La relecture a lieu **dans** la transaction : c'est ce qui rend le rejeu
            // inoffensif. Deux soumissions simultanées du même lien lisent la même ligne,
            // et la seconde ne trouve plus de jeton vivant.
            $token = $this->live($plainToken, $now);

            if (null === $token) {
                return false;
            }

            $user = $token->getUser();

            $user->setPassword($this->hasher->hashPassword($user, $plainPassword));

            // AD-9 : les autres sessions du compte sont closes à la requête suivante.
            // L'incrément et le nouveau hachage sont dans la même transaction — la
            // comparaison d'utilisateur (`User::isEqualTo()`) ne lit que le premier, donc
            // les deux ne peuvent pas se désynchroniser.
            $user->setSecurityToken($user->getSecurityToken() + 1);

            $token->setUsedAt($now);

            return true;
        });
    }

    private function live(string $plainToken, DateTimeImmutable $now): ?AccountToken
    {
        $token = $this->tokens->findLiveByHash(
            AccountTokenRepository::fingerprint($plainToken),
            AccountTokenPurpose::PasswordReset,
            $now,
        );

        if (null === $token || !$token->getUser()->isEnabled()) {
            return null;
        }

        return $token;
    }
}
