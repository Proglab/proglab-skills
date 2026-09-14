<?php

declare(strict_types=1);

namespace App\Core\Repository;

use App\Core\Entity\AccountToken;
use App\Core\Entity\User;
use App\Core\Enum\AccountTokenPurpose;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Les deux seules questions que le socle pose sur les jetons de compte.
 *
 * Pas `final`, comme tout repository de ce standard : un test unitaire de service doit
 * pouvoir la doubler.
 *
 * **`fingerprint()` vit ici, et ce n'est pas un hasard.** La forme de l'empreinte et la
 * façon de retrouver une ligne par elle sont la même décision : `findLiveByHash()` ne
 * peut pas trouver ce que `fingerprint()` n'a pas produit. Les séparer laisserait deux
 * classes libres de diverger sur l'algorithme, et la divergence serait silencieuse — un
 * lien qui ne marche jamais, sans erreur nulle part.
 *
 * @extends ServiceEntityRepository<AccountToken>
 */
class AccountTokenRepository extends ServiceEntityRepository
{
    /**
     * L'algorithme d'empreinte des jetons.
     *
     * SHA-256 et non le hacheur de mots de passe : un jeton de 32 octets tirés au hasard
     * n'est pas devinable par dictionnaire, donc rien ne justifie de ralentir sa
     * vérification — et un hachage lent, dont le sel change à chaque appel, interdirait
     * la seule chose dont on a besoin : retrouver la ligne **par son empreinte** en une
     * égalité indexée, au lieu de relire tous les jetons vivants pour les comparer un
     * par un.
     */
    private const string ALGORITHM = 'sha256';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountToken::class);
    }

    /**
     * L'empreinte d'un jeton en clair — la seule forme sous laquelle il entre en base.
     */
    public static function fingerprint(string $plainToken): string
    {
        return hash(self::ALGORITHM, $plainToken);
    }

    /**
     * Le jeton encore vivant qui porte cette empreinte, s'il y en a un.
     *
     * Vivant veut dire trois choses à la fois : jamais consommé, pas encore expiré, et
     * émis pour cet usage. Elles sont dans la requête et non chez l'appelant — un `if`
     * après coup est un `if` qu'un second appelant oubliera, et c'est ce qui transforme
     * un lien mort en lien ouvrable.
     *
     * `$now` est passé plutôt que lu ici : une requête qui consulte l'horloge ne peut pas
     * être vérifiée sur un instant choisi, et deux appels d'une même transaction ne
     * verraient pas le même « maintenant ».
     */
    public function findLiveByHash(string $tokenHash, AccountTokenPurpose $purpose, DateTimeImmutable $now): ?AccountToken
    {
        $found = $this->createQueryBuilder('token')
            ->andWhere('token.tokenHash = :hash')
            ->andWhere('token.purpose = :purpose')
            ->andWhere('token.usedAt IS NULL')
            ->andWhere('token.expiresAt > :now')
            ->setParameter('hash', $tokenHash)
            ->setParameter('purpose', $purpose)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();

        // `getOneOrNullResult()` est typé `mixed` : le mode d'hydratation est un argument
        // d'exécution, donc aucune analyse statique ne peut savoir ce qui en sort. Le test
        // de type est la seule façon honnête de tenir la signature de cette méthode.
        return $found instanceof AccountToken ? $found : null;
    }

    /**
     * Retire les jetons encore vivants de ce compte pour cet usage, et rend leur nombre.
     *
     * AD-15 : un seul lien vivant à la fois. Une nouvelle demande rend caduque la
     * précédente, sans quoi deux liens ouvrent le même compte et le second n'invalide pas
     * le premier — exactement ce qu'un lien à usage unique existe pour empêcher.
     *
     * **Un `DELETE`, et non une date d'usage posée.** `usedAt` dit « le destinataire l'a
     * ouvert » ; l'écrire sur un lien que personne n'a ouvert mentirait à l'écran que
     * l'Epic 2 posera sur cette table. Il n'y a rien à garder d'un lien périmé par un plus
     * récent : ce qu'il portait, le nouveau le porte.
     *
     * Les jetons **expirés** ne sont pas touchés — le nom de la méthode le dit, et leur
     * rétention est une question d'archivage (entrée dans `deferred-work.md`), pas un
     * effet de bord de la demande suivante.
     *
     * **Portée réelle de l'invariant : les demandes séquentielles.** Deux demandes
     * simultanées pour le même compte exécutent chacune ce `DELETE` avant l'`INSERT` de
     * l'autre, donc chacune ne supprime rien et toutes deux insèrent : deux liens vivants
     * coexistent. Rien ici ne l'empêche — ni verrou de ligne, ni index unique sur
     * `(user_id, purpose)` conditionné à `used_at IS NULL`, que MySQL ne sait pas
     * exprimer. Le préjudice est borné : les deux liens ouvrent le même compte, le premier
     * consommé tue l'autre (`usedAt`), et il faut avoir soumis deux fois le formulaire dans
     * la même fraction de seconde — ce que le limiteur de la story borne déjà par ailleurs.
     */
    public function invalidateLiveFor(User $user, AccountTokenPurpose $purpose, DateTimeImmutable $now): int
    {
        return $this->createQueryBuilder('token')
            ->delete()
            ->andWhere('token.user = :user')
            ->andWhere('token.purpose = :purpose')
            ->andWhere('token.usedAt IS NULL')
            ->andWhere('token.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('purpose', $purpose)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
