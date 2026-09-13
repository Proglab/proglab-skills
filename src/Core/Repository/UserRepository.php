<?php

declare(strict_types=1);

namespace App\Core\Repository;

use App\Core\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Le repository des comptes — et rien de plus que ce que le provider exige.
 *
 * Le provider `entity` de Symfony charge par propriété (`email`) et n'a besoin d'aucune
 * méthode d'ici. Il n'y a donc **aucune requête à écrire** : une méthode ajoutée « pour
 * plus tard » serait une requête que personne n'appelle et que rien ne teste.
 *
 * La classe existe quand même, pour deux raisons concrètes : elle est le point d'ancrage
 * de la première requête réelle (l'écran Utilisateurs de l'Epic 2), et elle est le seul
 * endroit où le contrat de couches autorisera à l'écrire.
 *
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }
}
