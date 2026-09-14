<?php

declare(strict_types=1);

namespace App\Core\MessageHandler;

use App\Core\Message\SendEmail;
use App\Core\Service\EmailSender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Une couche de traduction, exactement comme un contrôleur : il déballe le message,
 * appelle le service, et s'arrête.
 *
 * La couche `Message` de `deptrac.yaml` ne voit ni `Http`, ni `Doctrine`, ni
 * l'`EntityManager` : un handler ne peut donc ni requêter ni `flush()`. Ce n'est pas une
 * limitation subie — composer un email est une règle, et une règle vit dans un service
 * (`App\Core\Service\EmailSender`), où elle s'exerce sans bus et sans enveloppe.
 */
#[AsMessageHandler]
final readonly class SendEmailHandler
{
    public function __construct(private EmailSender $sender)
    {
    }

    public function __invoke(SendEmail $message): void
    {
        $this->sender->send($message);
    }
}
