<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Module\Demo\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entité au format maker : des données, aucune règle.
 *
 * Elle n'existe que pour prouver que le mapping Doctrine trouve un module sans
 * qu'aucun fichier de `config/` ait été modifié pour l'accueillir. Aucune migration
 * ne la crée : la suite lit ses métadonnées, jamais une table.
 */
#[ORM\Entity]
class DemoWidget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $label = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }
}
