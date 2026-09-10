<?php

declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Contract\ModuleDescriptor;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Ce que le socle sait des modules présents : leurs noms, et rien de plus.
 *
 * Le socle ne connaît aucune classe de module — seulement le contrat que chacun
 * implémente (AD-13). C'est la moitié « socle » de la porte publique : sans un
 * consommateur, `Core/Contract/` serait une intention, pas un mécanisme.
 */
final readonly class ModuleRegistry
{
    /**
     * @param iterable<ModuleDescriptor> $modules
     */
    public function __construct(
        #[AutowireIterator('app.module')] private iterable $modules,
    ) {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];

        foreach ($this->modules as $module) {
            $names[] = $module->name();
        }

        sort($names);

        return $names;
    }
}
