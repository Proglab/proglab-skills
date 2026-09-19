<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Module\Demo\Enum;

/**
 * L'enum d'un module, et rien d'autre.
 *
 * Elle n'existe que pour donner à `ModuleWiringTest` une classe réelle dans le dossier
 * `Enum/` d'un module : sans elle, l'assertion « le conteneur ne définit pas ceci »
 * porterait sur un dossier vide et ne prouverait rien. Un enum n'est pas instanciable,
 * donc un conteneur qui prétendrait le construire ne peut qu'échouer — c'est pour cela
 * que `config/services.yaml` l'exclut des deux côtés de la frontière.
 */
enum DemoWidgetStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
