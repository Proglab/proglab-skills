<?php

declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Message\SendEmail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Le seul endroit du socle qui appelle `MailerInterface::send()`.
 *
 * Il traduit le sujet dans la langue portée par le message, compose le `TemplatedEmail`
 * et l'envoie. Rien de tout cela n'a besoin du bus ni d'une enveloppe : cette classe
 * s'exerce seule, ce qui est exactement le gain d'avoir séparé la règle du handler.
 *
 * **Le rendu a lieu ici, donc dans le worker.** `MailerInterface::send()` déclenche le
 * pipeline du Mailer de façon synchrone — `SendEmailMessage` n'est routé nulle part
 * (`config/packages/messenger.yaml`) —, et c'est `BodyRenderer` qui rend le gabarit. Le
 * corps part donc avec l'état du moment de l'envoi, pas celui du moment du dispatch.
 *
 * **La langue ne peut pas venir du translator.** Dans un worker il n'y a pas de requête,
 * donc pas de `_locale` : le translator rendrait tout dans `framework.default_locale`. Le
 * sujet est donc traduit ici avec la locale explicite du message, et le gabarit reçoit
 * cette locale dans son contexte sous la clé `locale`, à passer en quatrième argument de
 * chaque `|trans` — `templates/emails/base.html.twig` montre la forme, et
 * `tests/Core/Mail/SendEmailHandlerTest.php` refuse toute retombée sur la langue par
 * défaut.
 */
final readonly class EmailSender
{
    /**
     * Les deux clés que cette classe ajoute au contexte du gabarit, en plus de celles du
     * message. Elles sont **réservées** : un contexte qui les porterait déjà est écrasé,
     * et pas l'inverse — voir l'ordre de l'union dans `send()`.
     */
    private const string CONTEXT_LOCALE = 'locale';
    private const string CONTEXT_SUBJECT = 'subject';

    /**
     * Le domaine de traduction des emails du socle, `translations/emails.{fr,en,nl}.yaml`.
     */
    private const string DOMAIN = 'emails';

    /**
     * Le translator est référencé par son identifiant de service plutôt que par son
     * type, parce que deux interfaces sont nécessaires et qu'aucun alias n'existe pour
     * la seconde : `TranslatorInterface` traduit, `TranslatorBagInterface` donne accès au
     * catalogue — sans quoi une clé absente ne peut pas être distinguée d'une clé
     * traduite (voir `subject()`).
     */
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire(service: 'translator')]
        private TranslatorInterface&TranslatorBagInterface $translator,
        private Environment $twig,
    ) {
    }

    /**
     * @throws UnrecoverableMessageHandlingException quand le message est structurellement
     *                                               invalide — rejouer n'y changerait rien
     * @throws TransportExceptionInterface           quand c'est le SMTP qui a échoué —
     *                                               là, rejouer a un sens
     */
    public function send(SendEmail $message): void
    {
        $recipient = $this->recipient($message);
        $template = $this->template($message);
        $subject = $this->subject($message);

        $email = new TemplatedEmail()
            ->to($recipient)
            ->subject($subject)
            ->htmlTemplate($template)
            // **Les deux clés de la classe sont à gauche de l'union, et c'est le sujet
            // même de cette ligne.** L'union PHP conserve la valeur de l'opérande
            // **gauche** pour toute clé présente des deux côtés : avec `$message->context`
            // à gauche, un message portant `context: ['locale' => 'en']` rendrait son
            // corps en anglais et son sujet dans la langue du message. Les deux clés sont
            // réservées, donc elles gagnent.
            ->context([
                self::CONTEXT_LOCALE => $message->locale->value,
                self::CONTEXT_SUBJECT => $subject,
            ] + $message->context);

        // `Auto-Submitted` dit aux répondeurs automatiques et aux systèmes de tickets de
        // ne pas répondre (RFC 3834). Les trois pieds de page le demandent en toutes
        // lettres à un humain ; cet en-tête le dit aux machines, qui sont celles qui
        // construisent les boucles de messages.
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');

        $this->mailer->send($email);
    }

    /**
     * Un destinataire vide ou syntaxiquement faux ne deviendra jamais valide : le message
     * part directement en file d'échec au lieu de brûler trois tentatives. Rejouer trois
     * fois une adresse absente n'est pas du retry, c'est du bruit dans
     * `messenger:failed:show`.
     */
    private function recipient(SendEmail $message): Address
    {
        if ('' === trim($message->toEmail)) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Le message « %s » n\'a pas de destinataire.', $message->subjectKey));
        }

        try {
            return new Address(trim($message->toEmail), trim($message->toName));
        } catch (RfcComplianceException $exception) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Le destinataire du message « %s » n\'est pas une adresse.', $message->subjectKey), previous: $exception);
        }
    }

    /**
     * Le gabarit est vérifié **avant** l'appel au mailer.
     *
     * Sans cette vérification, un nom de gabarit erroné remonterait comme une
     * `LoaderError` au moment du rendu — une exception quelconque, donc trois tentatives
     * et trois fois la même erreur. Un gabarit qui n'existe pas n'apparaîtra pas au
     * prochain essai.
     */
    private function template(SendEmail $message): string
    {
        if (!$this->twig->getLoader()->exists($message->template)) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Le gabarit d\'email « %s » n\'existe pas.', $message->template));
        }

        return $message->template;
    }

    /**
     * Le sujet, traduit dans la langue du message.
     *
     * **La clé est vérifiée avant d'être traduite, exactement comme le gabarit.** Sans
     * cette garde, `trans()` sur une clé absente rend la clé elle-même : l'email part
     * avec « user.password_reset.subject » en objet, sans exception, sans reprise et
     * sans trace. C'est le seul mode d'échec de cette classe qui ne se voit nulle part,
     * et c'est pour cela qu'il est refusé ici plutôt que corrigé plus tard.
     *
     * Le contexte sert de paramètres, et ses clés sont présentées au translator entourées
     * de `%` — `['widget' => 'Établi']` devient `['%widget%' => 'Établi']`. C'est ce qui
     * permet à un seul tableau de servir des deux côtés : le gabarit Twig lit `widget`,
     * le catalogue écrit `%widget%`. Un `null` devient une chaîne vide, `strtr()` ne
     * sachant pas quoi faire d'autre.
     */
    private function subject(SendEmail $message): string
    {
        if (!$this->translator->getCatalogue($message->locale->value)->has($message->subjectKey, self::DOMAIN)) {
            throw new UnrecoverableMessageHandlingException(\sprintf('La clé de sujet « %s » n\'existe pas dans le domaine « %s ».', $message->subjectKey, self::DOMAIN));
        }

        $parameters = [];

        foreach ($message->context as $key => $value) {
            $parameters['%'.$key.'%'] = null === $value ? '' : (string) $value;
        }

        return $this->translator->trans(
            $message->subjectKey,
            $parameters,
            self::DOMAIN,
            $message->locale->value,
        );
    }
}
