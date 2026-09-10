<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Module\Demo\Service;

use App\Core\Contract\ModuleDescriptor;

/**
 * La seule chose qu'un module voit du socle : un contrat de `Core/Contract/`.
 *
 * `final readonly`, nommé d'après sa responsabilité, jamais `<Chose>Service`.
 */
final readonly class DemoDescriptor implements ModuleDescriptor
{
    public function name(): string
    {
        return 'Demo';
    }

    public function translationDomain(): string
    {
        return 'demo';
    }
}
