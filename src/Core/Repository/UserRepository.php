<?php

declare(strict_types=1);

namespace App\Core\Repository;

use App\Core\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Le repository des comptes — et rien de plus que ce que le socle demande réellement.
 *
 * Le provider `entity` de Symfony charge par propriété (`email`) et n'a besoin d'aucune
 * méthode d'ici : la connexion n'en a donc jamais ouvert. La story 1.9 est la première à
 * poser une vraie question — « qui porte cette adresse ? » — depuis un cas d'usage, et
 * c'est cette question qui vit ci-dessous.
 *
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Le compte qui porte cette adresse, actif ou non.
     *
     * **L'état n'est pas filtré ici, et c'est délibéré.** La règle « un compte désactivé
     * ne reprend pas son accès seul » appartient à `App\Core\Service\PasswordResetRequest`,
     * qui doit répondre la même chose à une adresse désactivée et à une adresse inconnue.
     * Une requête qui ne rendrait que les comptes actifs rendrait les deux cas
     * indiscernables **pour l'appelant aussi** — donc impossibles à traiter différemment
     * le jour où l'un des deux devra l'être, et invisibles dans un journal d'audit.
     *
     * La méthode est écrite plutôt que laissée au `__call` magique de Doctrine : celui-là
     * rend `object|null` et PHPStan ne peut rien en dire.
     */
    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }
}
