<?php

declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Entity\AccountToken;
use App\Core\Enum\AccountTokenPurpose;
use App\Core\Message\SendEmail;
use App\Core\Repository\AccountTokenRepository;
use App\Core\Repository\UserRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * « Quelqu'un demande un lien de réinitialisation » — et la règle de non-divulgation.
 *
 * **La règle vit ici, pas dans le contrôleur, et c'est le point le plus important de cette
 * classe.** Le contrôleur reçoit un DTO valide et appelle `request()` ; ce service décide
 * de ne rien faire pour un compte inconnu ou désactivé, et rend la même chose dans tous
 * les cas — `void`, sans exception et sans valeur de retour à interpréter. Un `if` dans le
 * contrôleur serait la première règle métier posée hors d'un service, et le premier
 * endroit où un dérivé ajouterait par erreur un message différent selon le cas.
 *
 * **Le jeton en clair ne sort d'ici que par le message.** Il est tiré par
 * `random_bytes(32)`, la table `account_token` n'en garde que l'empreinte SHA-256
 * (`AccountTokenRepository::fingerprint()`), et la seule copie lisible part dans le
 * contexte du `SendEmail` pour que le gabarit compose l'URL. Il n'est jamais journalisé,
 * jamais rendu dans une réponse.
 *
 * **Il a pourtant un second porteur, et il faut le savoir.** En production le transport
 * est `doctrine://default` : le message sérialisé — jeton en clair compris — vit dans
 * `messenger_messages.body` entre le commit et sa consommation par le worker. C'est
 * normalement quelques secondes, et la ligne disparaît avec le message. Ce n'est plus
 * quelques secondes si l'envoi échoue trois fois : le message part alors sur le transport
 * `failed`, où il **reste** jusqu'à ce que quelqu'un le rejoue ou le supprime, et le jeton
 * y reste lisible bien après l'heure où il a cessé d'ouvrir quoi que ce soit. L'entrée est
 * dans `deferred-work.md` avec ce qui la refermerait.
 *
 * **Le dispatch a lieu à l'intérieur de `wrapInTransaction()`**, comme la décision D-1 de
 * la story 1.8 l'impose : le transport `doctrine://default` écrit sur la connexion DBAL de
 * l'application, donc l'`INSERT` du message rejoint cette transaction. Le worker ne peut
 * pas dépiler un email annonçant un jeton qui n'existe pas — et si la transaction échoue,
 * le message disparaît avec le jeton qu'il annonçait. L'ordre inverse laisserait cette
 * fenêtre ouverte ; `tests/Core/Mail/EmailQueueTest.php` le montre sur le transport réel.
 */
final readonly class PasswordResetRequest
{
    /**
     * La durée de vie d'un lien, au format `DateInterval` — une heure, comme le dit la
     * page de confirmation en toutes lettres.
     *
     * Elle vit ici et non sur `AccountTokenPurpose` : c'est une décision de ce cas
     * d'usage, et l'invitation de l'Epic 2 posera la sienne à côté.
     */
    public const string LIFETIME = 'PT1H';

    /**
     * Le gabarit de l'email, et la clé de son sujet dans le domaine `emails`.
     */
    public const string TEMPLATE = 'emails/password_reset.html.twig';

    public const string SUBJECT_KEY = 'password_reset.subject';

    /**
     * La clé sous laquelle le jeton en clair voyage dans le contexte du message.
     *
     * C'est le gabarit qui compose l'URL, avec `url()` : un email est rendu par le worker,
     * où il n'y a pas de requête, donc pas d'hôte à deviner — `framework.router.default_uri`
     * le donne. Composer l'URL ici obligerait ce service à connaître le routeur pour un
     * gain nul.
     */
    public const string CONTEXT_TOKEN = 'token';

    /**
     * Le nombre d'octets tirés au hasard.
     *
     * 32 octets, soit 64 caractères hexadécimaux dans l'URL : bien au-delà de ce qu'une
     * recherche exhaustive peut couvrir pendant l'heure où le lien vit.
     */
    private const int TOKEN_BYTES = 32;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private AccountTokenRepository $tokens,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Émet un lien pour cette adresse — ou ne fait rien, sans le dire.
     *
     * Trois chemins, une seule sortie observable :
     *
     * - adresse inconnue : rien ;
     * - compte désactivé : rien. Un compte désactivé ne reprend pas son accès seul ;
     *   le rendre joignable par cette page annulerait la désactivation ;
     * - compte actif : les jetons vivants du compte sont retirés (AD-15 — un seul lien à
     *   la fois), un nouveau est persisté, et son email part en file.
     */
    public function request(string $email): void
    {
        $user = $this->users->findOneByEmail($email);

        if (null === $user || !$user->isEnabled()) {
            return;
        }

        $now = new DateTimeImmutable();

        // Le jeton est tiré **avant** la transaction parce qu'il n'en dépend pas, et il
        // n'est utilisé qu'à l'intérieur : ce qui compte est que son empreinte et le
        // message qui le porte soient écrits sous la même frontière.
        $plainToken = bin2hex(random_bytes(self::TOKEN_BYTES));

        $this->entityManager->wrapInTransaction(function () use ($user, $now, $plainToken): void {
            $this->tokens->invalidateLiveFor($user, AccountTokenPurpose::PasswordReset, $now);

            $token = new AccountToken();
            $token->setUser($user)
                ->setPurpose(AccountTokenPurpose::PasswordReset)
                ->setTokenHash(AccountTokenRepository::fingerprint($plainToken))
                ->setCreatedAt($now)
                ->setExpiresAt($now->add(new DateInterval(self::LIFETIME)));

            $this->entityManager->persist($token);

            // **Dedans, et c'est tout le sujet** (story 1.8, décision D-1). L'INSERT du
            // message rejoint cette transaction : il n'est visible d'aucune autre
            // connexion tant qu'elle n'est pas commitée, et il disparaît avec elle si
            // elle est annulée.
            $this->bus->dispatch(new SendEmail(
                $user->getEmail(),
                // Le socle ne porte pas encore de nom d'affichage sur un compte ;
                // `SendEmail` accepte une chaîne vide, et l'adresse suffit.
                '',
                $user->getLanguage(),
                self::SUBJECT_KEY,
                self::TEMPLATE,
                [self::CONTEXT_TOKEN => $plainToken],
            ));
        });
    }
}
