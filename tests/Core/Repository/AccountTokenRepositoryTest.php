<?php

declare(strict_types=1);

namespace App\Tests\Core\Repository;

use App\Core\Entity\AccountToken;
use App\Core\Entity\User;
use App\Core\Enum\AccountTokenPurpose;
use App\Core\Repository\AccountTokenRepository;
use App\Tests\Core\Security\Accounts;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les deux requêtes de l'entité partagée d'AD-15 — et surtout le prédicat que rien d'autre
 * n'exerce : **l'objet du jeton**.
 *
 * `AccountToken` est partagée entre la réinitialisation de mot de passe (story 1.9) et
 * l'invitation (story 2.6). Aujourd'hui le socle n'émet que des jetons `PasswordReset`,
 * donc `token.purpose = :purpose` peut être retiré des deux requêtes sans qu'un seul test
 * fonctionnel ne bouge. Ce qui se casserait alors est à l'Epic 2 :
 *
 * - un lien d'invitation deviendrait ouvrable comme lien de réinitialisation, et
 *   changerait un mot de passe sur un compte qui n'a pas encore été accepté ;
 * - une demande de mot de passe oublié supprimerait l'invitation en attente du même
 *   compte, sans rien dire à personne.
 *
 * Aucun de ces deux chemins n'existe encore, donc aucun test fonctionnel ne peut les
 * atteindre. Ils s'épinglent au niveau du repository, qui est l'endroit où le prédicat
 * vit — c'est le seul moment où l'écrire coûte quelques lignes plutôt qu'une migration.
 */
#[CoversClass(AccountTokenRepository::class)]
final class AccountTokenRepositoryTest extends KernelTestCase
{
    private const string EMAIL = 'marc@example.test';

    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    #[Test]
    public function a_live_token_is_found_by_its_fingerprint(): void
    {
        self::bootKernel();

        $plain = 'un-jeton-de-reinitialisation';
        $this->persist($this->token($this->account(), AccountTokenPurpose::PasswordReset, $plain));

        self::assertInstanceOf(
            AccountToken::class,
            $this->tokens()->findLiveByHash(AccountTokenRepository::fingerprint($plain), AccountTokenPurpose::PasswordReset, new DateTimeImmutable()),
        );
    }

    /**
     * Le jeton d'invitation ne se laisse pas ouvrir comme un jeton de réinitialisation.
     */
    #[Test]
    public function a_token_issued_for_another_purpose_is_not_found(): void
    {
        self::bootKernel();

        $plain = 'un-jeton-d-invitation';
        $this->persist($this->token($this->account(), AccountTokenPurpose::Invitation, $plain));

        self::assertNull(
            $this->tokens()->findLiveByHash(AccountTokenRepository::fingerprint($plain), AccountTokenPurpose::PasswordReset, new DateTimeImmutable()),
            'Un jeton émis pour une invitation est retrouvé par la recherche de réinitialisation : le lien d\'invitation de l\'Epic 2 changerait un mot de passe.',
        );
    }

    /**
     * Et l'invalidation ne déborde pas non plus : une demande de mot de passe oublié ne
     * doit pas emporter l'invitation en attente du même compte.
     */
    #[Test]
    public function invalidating_one_purpose_leaves_the_other_alone(): void
    {
        self::bootKernel();

        $user = $this->account();

        $invitation = 'un-jeton-d-invitation';
        $reset = 'un-jeton-de-reinitialisation';

        $this->persist($this->token($user, AccountTokenPurpose::Invitation, $invitation));
        $this->persist($this->token($user, AccountTokenPurpose::PasswordReset, $reset));

        $removed = $this->tokens()->invalidateLiveFor($user, AccountTokenPurpose::PasswordReset, new DateTimeImmutable());

        self::assertSame(1, $removed, 'L\'invalidation a emporté autre chose que le jeton de réinitialisation.');
        self::assertInstanceOf(
            AccountToken::class,
            $this->tokens()->findLiveByHash(AccountTokenRepository::fingerprint($invitation), AccountTokenPurpose::Invitation, new DateTimeImmutable()),
            'Une demande de mot de passe oublié a supprimé l\'invitation en attente du compte.',
        );
    }

    /**
     * Et elle ne déborde pas non plus d'un compte sur l'autre.
     */
    #[Test]
    public function invalidating_one_account_leaves_the_other_alone(): void
    {
        self::bootKernel();

        $mine = 'mon-jeton';
        $theirs = 'son-jeton';

        $this->persist($this->token($this->account(), AccountTokenPurpose::PasswordReset, $mine));
        $other = Accounts::create(self::getContainer(), 'karim@example.test', self::PASSWORD);
        $this->persist($this->token($other, AccountTokenPurpose::PasswordReset, $theirs));

        $this->tokens()->invalidateLiveFor($this->account(), AccountTokenPurpose::PasswordReset, new DateTimeImmutable());

        self::assertNull($this->tokens()->findLiveByHash(AccountTokenRepository::fingerprint($mine), AccountTokenPurpose::PasswordReset, new DateTimeImmutable()));
        self::assertInstanceOf(
            AccountToken::class,
            $this->tokens()->findLiveByHash(AccountTokenRepository::fingerprint($theirs), AccountTokenPurpose::PasswordReset, new DateTimeImmutable()),
            'L\'invalidation a emporté le lien d\'un autre compte.',
        );
    }

    /**
     * Le nom de la méthode dit « vivants » : un jeton déjà expiré n'est pas son affaire,
     * et l'élargir ferait d'une demande la purge silencieuse d'autre chose.
     */
    #[Test]
    public function invalidating_leaves_the_already_expired_alone(): void
    {
        self::bootKernel();

        $user = $this->account();

        $expired = $this->token($user, AccountTokenPurpose::PasswordReset, 'un-jeton-perime');
        $expired->setExpiresAt(new DateTimeImmutable('-1 hour'));
        $this->persist($expired);

        self::assertSame(0, $this->tokens()->invalidateLiveFor($user, AccountTokenPurpose::PasswordReset, new DateTimeImmutable()));
    }

    // -------------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------------

    private function account(): User
    {
        return self::getContainer()->get(\App\Core\Repository\UserRepository::class)->findOneByEmail(self::EMAIL)
            ?? Accounts::create(self::getContainer(), self::EMAIL, self::PASSWORD);
    }

    private function token(User $user, AccountTokenPurpose $purpose, string $plain): AccountToken
    {
        $now = new DateTimeImmutable();

        return new AccountToken()
            ->setUser($user)
            ->setPurpose($purpose)
            ->setTokenHash(AccountTokenRepository::fingerprint($plain))
            ->setCreatedAt($now)
            ->setExpiresAt($now->add(new DateInterval('PT1H')));
    }

    private function persist(AccountToken $token): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $entityManager->persist($token);
        $entityManager->flush();
    }

    private function tokens(): AccountTokenRepository
    {
        return self::getContainer()->get(AccountTokenRepository::class);
    }
}
