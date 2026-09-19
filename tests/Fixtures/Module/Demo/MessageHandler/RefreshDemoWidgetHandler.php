<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Module\Demo\MessageHandler;

use App\Tests\Fixtures\Module\Demo\Message\RefreshDemoWidget;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Le contrôle positif de l'exclusion : `MessageHandler/` n'est pas `Message/`.
 *
 * Un handler *est* un service, et c'est `#[AsMessageHandler]` qui le tague. Il reste donc
 * dans le glob des modules, comme `App\Core\MessageHandler\SendEmailHandler` reste dans
 * celui du socle. `ModuleWiringTest::the_message_handler_of_a_module_stays_a_service()`
 * tient cette moitié de la règle ; sans elle, une exclusion écrite `Message*` casserait
 * le seul câblage asynchrone qu'un module puisse avoir, sans que rien ne le dise.
 *
 * Le corps est vide et le restera : ce fichier prouve un câblage, pas un comportement.
 */
#[AsMessageHandler]
final readonly class RefreshDemoWidgetHandler
{
    public function __invoke(RefreshDemoWidget $message): void
    {
    }
}
