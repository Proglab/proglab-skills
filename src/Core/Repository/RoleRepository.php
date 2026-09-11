<?php

declare(strict_types=1);

namespace App\Core\Repository;

use App\Core\Entity\Role;
use App\Core\Enum\CoreRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * La seule requête que le socle a besoin de poser sur les rôles : retrouver l'un des
 * trois rôles du socle par son code.
 *
 * Nommée d'après l'intention de l'appelant, pas d'après le mécanisme. Elle n'accepte
 * qu'un `CoreRole` : un rôle créé depuis l'écran de gestion n'a pas de code, donc il
 * n'est pas atteignable par ici — et c'est voulu.
 *
 * Pas `final`, comme tout repository de ce standard.
 *
 * @extends ServiceEntityRepository<Role>
 */
class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    public function findOneByCode(CoreRole $code): ?Role
    {
        return $this->findOneBy(['code' => $code]);
    }
}
