<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Module\Billing\Service;

use App\Core\Contract\ModuleDescriptor;

/**
 * Un second module, réduit à un service.
 *
 * Il existe parce que « `Module` → `Module` interdit » est invérifiable avec un seul
 * module : deptrac nomme une couche par module, et il en faut deux pour que la règle
 * soit autre chose qu'une promesse.
 */
final readonly class BillingDescriptor implements ModuleDescriptor
{
    public function name(): string
    {
        return 'Billing';
    }

    public function translationDomain(): string
    {
        return 'billing';
    }
}
