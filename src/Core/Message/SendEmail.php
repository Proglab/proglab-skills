<?php

declare(strict_types=1);

namespace App\Core\Message;

use App\Core\Enum\SupportedLocale;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Le seul chemin d'envoi d'email du socle (AD-21, AR-10).
 *
 * Un cas d'usage qui veut envoyer un email dispatche ce message ; il n'appelle jamais
 * `MailerInterface` lui-même. Le routage vit sur l'attribut ci-dessous plutôt que dans le
 * bloc `routing:` de `config/packages/messenger.yaml` : ce bloc est réservé aux messages
 * **tiers**, sur lesquels on ne peut pas poser d'attribut. Le fichier de configuration
 * garde en revanche l'explication de ce qui n'y est **pas** routé —
 * `Symfony\Component\Mailer\Messenger\SendEmailMessage` — et pourquoi.
 *
 * **Ce que porte la charge utile, et pourquoi c'est une contrainte et non un style.**
 * Des scalaires et une locale, jamais une entité, jamais un objet `Email`, jamais rien
 * qui dépende de la requête en cours. Une entité sérialisée est un instantané : le worker
 * la désérialise plus tard, sur des données déjà périmées, et un proxy Doctrine ne se
 * désérialise pas du tout. La locale est explicite pour la même raison — dans un worker
 * il n'y a pas de requête, donc pas de langue à deviner, et le rendu doit se faire dans
 * celle de l'utilisateur qui recevra l'email.
 *
 * **Un seul tableau de contexte**, qui sert à la fois de paramètres du sujet et de
 * variables du gabarit. Deux tableaux de scalaires côte à côte divergent au premier
 * usage : quelqu'un ajoute une variable au gabarit, oublie le sujet, et le sujet part
 * avec un `%placeholder%` en clair. `App\Core\Service\EmailSender` dit comment les clés
 * sont présentées au translator.
 *
 * Ce message est un objet de données, pas un service : `config/services.yaml` exclut
 * `Message/` du glob de découverte du socle.
 */
#[AsMessage('async')]
final readonly class SendEmail
{
    /**
     * @param string                     $toEmail    l'adresse du destinataire
     * @param string                     $toName     son nom d'affichage ; une chaîne vide est acceptée
     * @param SupportedLocale            $locale     la langue du rendu, sujet compris
     * @param string                     $subjectKey la clé du sujet dans le domaine de traduction `emails`
     * @param string                     $template   le chemin du gabarit Twig, par exemple `emails/demo.html.twig`
     * @param array<string, scalar|null> $context    les variables du gabarit, qui sont aussi
     *                                               les paramètres du sujet
     */
    public function __construct(
        public string $toEmail,
        public string $toName,
        public SupportedLocale $locale,
        public string $subjectKey,
        public string $template,
        public array $context = [],
    ) {
    }
}
