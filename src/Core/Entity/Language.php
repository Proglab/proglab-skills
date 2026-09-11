<?php

declare(strict_types=1);

namespace App\Core\Entity;

use App\Core\Enum\SupportedLocale;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une langue **active** du socle — la moitié de l'AD-14 qui vit en base.
 *
 * Entité au format maker : des données, aucune règle. Ce qu'« actif » implique se décide
 * dans `App\Core\Service\ActiveLocales`, et le repli quand plus rien ne l'est se calcule
 * à la lecture. Désactiver une langue ne réécrit aucune autre ligne, et surtout pas la
 * préférence de qui l'utilisait.
 *
 * Le code est porté par `SupportedLocale` plutôt que par une chaîne libre : une ligne ne
 * peut donc nommer qu'une langue que le code déclare, et la colonne reste lisible en SQL.
 * La conséquence à connaître : retirer un cas de l'enum casse l'hydratation des lignes
 * qui le portent — un retrait de langue supportée est une migration, pas une édition.
 *
 * Aucun écran ne gère ces lignes : c'est la story 2.9. Cette story livre le mécanisme.
 *
 * **Pas de `repositoryClass:`, et c'est la première entité du dépôt — donc le précédent.**
 * Le format maker écrit `#[ORM\Entity(repositoryClass: LanguageRepository::class)]`, et
 * `deptrac.yaml` le refuse : le ruleset `Entity` n'autorise que `Entity`,
 * `DoctrineMapping` et `Enum`, précisément pour qu'une entité ne puisse jamais atteindre
 * un repository. L'argument n'est pourtant que du mapping — mais ouvrir
 * `Entity → Repository` dans le contrat de couches autoriserait du même coup une entité à
 * *appeler* un repository, ce que le contrat existe pour interdire. Le contrat de couches
 * l'emporte donc sur la forme du maker.
 *
 * Conséquence à connaître : `$entityManager->getRepository(Language::class)` rend un
 * `EntityRepository` générique, pas `LanguageRepository`. Le socle injecte toujours
 * `App\Core\Repository\LanguageRepository` par son type — c'est la seule façon d'atteindre
 * `findActiveCodes()`, et PHPStan le dit à qui tenterait l'autre chemin.
 */
#[ORM\Entity]
class Language
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 5, unique: true, enumType: SupportedLocale::class)]
    private SupportedLocale $code;

    #[ORM\Column]
    private bool $enabled = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): SupportedLocale
    {
        return $this->code;
    }

    public function setCode(SupportedLocale $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }
}
