<?php

declare(strict_types=1);

namespace App\Core\Entity;

use App\Core\Enum\CoreRole;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un rôle, c'est-à-dire une **donnée** — jamais un `ROLE_` de Symfony.
 *
 * Entité au format maker : des colonnes, aucune règle. Les permissions qu'un rôle porte
 * arrivent avec FR-7 à l'Epic 2 ; ici il n'a qu'un libellé et, pour les trois rôles du
 * socle, un code.
 *
 * **`code` est nullable, et c'est le point de la décision du 2026-09-11.** Les trois
 * rôles du socle en portent un — la prise stable que la migration, la commande
 * d'initialisation de la story 1.11 et l'Epic 5 demandent ; ceux qu'un administrateur
 * créera n'en portent aucun. Le renommage reste donc libre : rien ne cherche jamais un
 * rôle sur son libellé. **Aucune décision d'autorisation ne s'adosse à ce code** — AD-8
 * tient.
 *
 * Pas de `repositoryClass:`, comme `Language` : `deptrac.yaml` refuse à la couche
 * `Entity` de voir la couche `Repository`, et le contrat de couches l'emporte sur la
 * forme du maker. `App\Core\Repository\RoleRepository` s'injecte par son type.
 */
#[ORM\Entity]
class Role
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Le code du socle, ou `null` pour un rôle créé depuis l'écran de gestion.
     *
     * Unique : deux lignes ne peuvent pas prétendre être le même rôle du socle. MySQL
     * autorise plusieurs `NULL` sous un index unique, ce qui est exactement ce qu'il
     * faut ici.
     */
    #[ORM\Column(length: 32, unique: true, nullable: true, enumType: CoreRole::class)]
    private ?CoreRole $code = null;

    #[ORM\Column(length: 100)]
    private string $name;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?CoreRole
    {
        return $this->code;
    }

    public function setCode(?CoreRole $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }
}
