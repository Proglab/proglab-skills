<?php

declare(strict_types=1);

namespace App\Tests\Core\Mail;

use App\Core\Enum\SupportedLocale;
use App\Core\Message\SendEmail;
use App\Core\MessageHandler\SendEmailHandler;
use App\Core\Service\EmailSender;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Mime\Email;

/**
 * Ce que le worker fait d'un `SendEmail` : le rendu, la locale, et les refus.
 *
 * **Pourquoi un `KernelTestCase` et pas un test unitaire.** Ce qui est en jeu ici n'est
 * pas « `EmailSender` a-t-il appelé `send()` » — un mock le dirait — mais « le sujet et
 * le corps sortent-ils dans la langue portée par le message ». Cette langue ne se lit
 * nulle part ailleurs que dans le texte rendu, et le rendu est fait par le pipeline du
 * Mailer (`MessageListener` puis `BodyRenderer`) sur le vrai translator et le vrai Twig.
 * Un double ne prouverait que le câblage de la classe, jamais la propriété.
 *
 * **Pourquoi `assertEmailCount()` et non `assertQueuedEmailCount()`.** Le standard route
 * d'ordinaire `SendEmailMessage` vers un transport asynchrone, ce qui rend tous les
 * envois « queued ». Ce socle ne le route **pas** (voir `config/packages/messenger.yaml`)
 * : c'est notre propre `SendEmail` qui traverse la file, et le `send()` du worker est
 * direct. Les événements observés portent donc `queued = false`.
 */
#[CoversClass(EmailSender::class)]
#[CoversClass(SendEmailHandler::class)]
#[UsesClass(SendEmail::class)]
final class SendEmailHandlerTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    /**
     * Le gabarit du module de démonstration, le seul consommateur livré par cette story.
     */
    private const string TEMPLATE = 'emails/demo.html.twig';

    /**
     * Le sujet, rendu dans la langue du message et dans aucune autre.
     *
     * La locale par défaut du socle est le français (`framework.default_locale`), et
     * c'est elle qu'un worker sans requête utiliserait si le message ne portait pas la
     * sienne. Les deux autres langues sont donc les cas qui comptent : elles échouent si
     * quoi que ce soit retombe sur le défaut.
     */
    #[Test]
    #[DataProvider('localisedSubjects')]
    public function it_renders_the_subject_in_the_locale_carried_by_the_message(
        SupportedLocale $locale,
        string $expectedSubject,
    ): void {
        self::bootKernel();

        $this->handle(new SendEmail(
            'recipient@example.test',
            'Alice Demo',
            $locale,
            'demo.subject',
            self::TEMPLATE,
            ['widget' => 'Établi n°7'],
        ));

        self::assertEmailCount(1);
        self::assertSame($expectedSubject, $this->sentEmail()->getSubject());
    }

    /**
     * @return Generator<string, array{SupportedLocale, string}>
     */
    public static function localisedSubjects(): Generator
    {
        yield 'français, la langue source' => [SupportedLocale::Fr, 'Démonstration : Établi n°7'];
        yield 'anglais' => [SupportedLocale::En, 'Demonstration: Établi n°7'];
        yield 'néerlandais' => [SupportedLocale::Nl, 'Demonstratie: Établi n°7'];
    }

    /**
     * Le corps, lui aussi rendu dans la langue du message — pied de page traduit compris,
     * alors qu'aucune requête ne porte de locale dans un worker.
     */
    #[Test]
    public function it_renders_the_body_in_the_locale_carried_by_the_message(): void
    {
        self::bootKernel();

        $this->handle(new SendEmail(
            'recipient@example.test',
            'Alice Demo',
            SupportedLocale::Nl,
            'demo.subject',
            self::TEMPLATE,
            ['widget' => 'Établi n°7'],
        ));

        $email = $this->sentEmail();

        // Le contexte du message est dans le corps : c'est lui, et pas une entité, qui
        // traverse la file.
        self::assertEmailHtmlBodyContains($email, 'Établi n°7');

        // Et le texte du gabarit est néerlandais, donc le pied de page a été traduit avec
        // la locale du message et non avec celle du worker.
        self::assertEmailHtmlBodyContains($email, 'automatisch bericht');
    }

    /**
     * Un destinataire vide ne deviendra jamais valide : trois tentatives n'y changeraient
     * rien, elles ne feraient que du bruit dans la file d'échec.
     */
    #[Test]
    public function it_refuses_a_message_without_a_recipient(): void
    {
        self::bootKernel();

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handle(new SendEmail(
            '   ',
            'Personne',
            SupportedLocale::Fr,
            'demo.subject',
            self::TEMPLATE,
            ['widget' => 'Établi n°7'],
        ));
    }

    /**
     * Une adresse syntaxiquement invalide relève du même refus : le message est
     * structurellement faux, pas victime d'un réseau en panne.
     */
    #[Test]
    public function it_refuses_a_message_whose_recipient_is_not_an_address(): void
    {
        self::bootKernel();

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handle(new SendEmail(
            'pas-une-adresse',
            'Personne',
            SupportedLocale::Fr,
            'demo.subject',
            self::TEMPLATE,
            ['widget' => 'Établi n°7'],
        ));
    }

    /**
     * Un gabarit inexistant non plus. Le refus est levé **avant** l'appel au mailer :
     * sans cela, l'erreur Twig remonterait comme une panne quelconque et brûlerait trois
     * tentatives.
     */
    #[Test]
    public function it_refuses_a_message_whose_template_does_not_exist(): void
    {
        self::bootKernel();

        try {
            $this->handle(new SendEmail(
                'recipient@example.test',
                'Alice Demo',
                SupportedLocale::Fr,
                'demo.subject',
                'emails/nothing_here.html.twig',
                [],
            ));

            self::fail('Un gabarit inexistant doit être refusé sans retry.');
        } catch (UnrecoverableMessageHandlingException) {
            self::assertEmailCount(0);
        }
    }

    /**
     * Messenger rejoue. Le handler n'a aucun état à lui : rejouer le même message envoie
     * le même email une seconde fois, et rien d'autre ne se produit.
     *
     * C'est la limite assumée de cette story — l'effet de bord est chez le serveur SMTP,
     * pas dans notre base, donc aucune idempotence n'est atteignable ici. Elle est
     * consignée dans `deferred-work.md`, et ce test en est la preuve plutôt que
     * l'affirmation.
     */
    #[Test]
    public function handling_the_same_message_twice_sends_it_twice_and_nothing_else(): void
    {
        self::bootKernel();

        $message = new SendEmail(
            'recipient@example.test',
            'Alice Demo',
            SupportedLocale::Fr,
            'demo.subject',
            self::TEMPLATE,
            ['widget' => 'Établi n°7'],
        );

        $this->handle($message);
        $this->handle($message);

        self::assertEmailCount(2);
        self::assertSame(
            $this->sentEmail(0)->getSubject(),
            $this->sentEmail(1)->getSubject(),
        );
    }

    /**
     * L'enregistrement du handler, que rien d'autre n'exerce.
     *
     * Les trois tests de ce répertoire contournent le bus — celui-ci instancie le handler
     * à la main, `EmailQueueTest` s'arrête à l'enveloppe, `EmailFailurePolicyTest`
     * remplace le bus. Retirer `#[AsMessageHandler]` les laisserait donc **tous verts**,
     * pendant qu'en production chaque email lèverait `NoHandlerForMessageException`.
     * C'est ce que cette assertion ferme, et rien d'autre.
     */
    #[Test]
    public function the_container_registers_exactly_one_handler_for_send_email(): void
    {
        self::bootKernel();

        $descriptors = iterator_to_array(
            self::getContainer()->get('messenger.bus.default.messenger.handlers_locator')->getHandlers(
                new Envelope(new SendEmail(
                    'recipient@example.test',
                    'Alice Demo',
                    SupportedLocale::Fr,
                    'demo.subject',
                    self::TEMPLATE,
                )),
            ),
            false,
        );

        self::assertCount(1, $descriptors);
        self::assertStringContainsString(SendEmailHandler::class, $descriptors[0]->getName());
    }

    /**
     * Les deux clés que `EmailSender` ajoute au contexte sont **réservées** : un message
     * qui les porterait ne peut pas les détourner.
     *
     * Le cas est concret et silencieux : un `SendEmail` en néerlandais avec
     * `context: ['locale' => 'en']` sortirait avec un sujet néerlandais et un corps
     * anglais, sans que rien ne le signale. C'est l'ordre de l'union dans
     * `EmailSender::send()` qui le décide, et l'ordre inverse est le défaut naturel de
     * PHP — d'où ce test.
     */
    #[Test]
    public function the_message_cannot_override_the_reserved_context_keys(): void
    {
        self::bootKernel();

        $this->handle(new SendEmail(
            'recipient@example.test',
            'Alice Demo',
            SupportedLocale::Nl,
            'demo.subject',
            self::TEMPLATE,
            ['widget' => 'Établi n°7', 'locale' => 'en', 'subject' => 'Détourné'],
        ));

        $email = $this->sentEmail();

        // La locale du message gagne : le gabarit est rendu en néerlandais.
        self::assertEmailHtmlBodyContains($email, 'lang="nl"');
        self::assertEmailHtmlBodyContains($email, 'automatisch bericht');

        // Et le sujet traduit gagne sur celui que le contexte proposait.
        self::assertSame('Demonstratie: Établi n°7', $email->getSubject());
        self::assertEmailHtmlBodyContains($email, '<title>Demonstratie: Établi n°7</title>');
    }

    /**
     * Une clé de sujet absente du catalogue est refusée, exactement comme un gabarit
     * inexistant.
     *
     * Sans cette garde, `trans()` rend la clé elle-même : l'email part avec
     * « user.password_reset.subject » en objet, sans exception, sans reprise et sans
     * trace. C'est le seul mode d'échec de `EmailSender` qui ne se voit nulle part.
     */
    #[Test]
    public function it_refuses_a_message_whose_subject_key_does_not_exist(): void
    {
        self::bootKernel();

        try {
            $this->handle(new SendEmail(
                'recipient@example.test',
                'Alice Demo',
                SupportedLocale::Fr,
                'demo.subject_that_nobody_wrote',
                self::TEMPLATE,
                ['widget' => 'Établi n°7'],
            ));

            self::fail('Une clé de sujet absente doit être refusée sans retry.');
        } catch (UnrecoverableMessageHandlingException) {
            self::assertEmailCount(0);
        }
    }

    /**
     * L'expéditeur et l'en-tête anti-boucle, que rien d'autre ne vérifie.
     *
     * `MAILER_SENDER` est la seule ligne de configuration que le spec de la story ne
     * prévoyait pas : elle a été ajoutée après un échec constaté — `Symfony\Component\Mime\Message`
     * refuse d'envoyer un message sans `From`. Une configuration ajoutée en réaction à
     * une panne mérite le test qui la tient.
     *
     * `Auto-Submitted` va avec : les trois pieds de page demandent à un humain de ne pas
     * répondre, cet en-tête le dit aux répondeurs automatiques (RFC 3834).
     */
    #[Test]
    public function every_email_carries_the_configured_sender_and_the_anti_loop_header(): void
    {
        self::bootKernel();

        $this->handle(new SendEmail(
            'recipient@example.test',
            'Alice Demo',
            SupportedLocale::Fr,
            'demo.subject',
            self::TEMPLATE,
            ['widget' => 'Établi n°7'],
        ));

        $email = $this->sentEmail();

        self::assertEmailAddressContains($email, 'From', 'no-reply@localhost');
        self::assertEmailHeaderSame($email, 'Auto-Submitted', 'auto-generated');
    }

    /**
     * Un `null` dans le contexte devient une chaîne vide dans le sujet.
     *
     * `strtr()` ne sait rien faire d'un `null`, et la signature du message autorise
     * `scalar|null` : la conversion est donc une décision d'`EmailSender`, pas un effet
     * de bord du translator.
     */
    #[Test]
    public function a_null_context_value_becomes_an_empty_string_in_the_subject(): void
    {
        self::bootKernel();

        $this->handle(new SendEmail(
            'recipient@example.test',
            'Alice Demo',
            SupportedLocale::Fr,
            'demo.subject',
            self::TEMPLATE,
            ['widget' => null],
        ));

        self::assertSame('Démonstration : ', $this->sentEmail()->getSubject());
    }

    /**
     * Un nom d'affichage vide est accepté : tous les destinataires n'en ont pas un, et
     * c'est le **destinataire** qui est obligatoire, pas son nom.
     */
    #[Test]
    public function an_empty_display_name_is_accepted(): void
    {
        self::bootKernel();

        $this->handle(new SendEmail(
            'recipient@example.test',
            '',
            SupportedLocale::Fr,
            'demo.subject',
            self::TEMPLATE,
            ['widget' => 'Établi n°7'],
        ));

        $email = $this->sentEmail();

        self::assertEmailAddressContains($email, 'To', 'recipient@example.test');
        self::assertSame('', $email->getTo()[0]->getName());
    }

    /**
     * Le handler est instancié à la main, avec le service du conteneur : c'est exactement
     * ce que fait le worker, sans worker.
     */
    private function handle(SendEmail $message): void
    {
        new SendEmailHandler(self::getContainer()->get(EmailSender::class))($message);
    }

    /**
     * `getMailerMessage()` rend un `RawMessage` : le sujet et le corps n'existent que sur
     * un `Email`, et c'est bien un `Email` que `EmailSender` compose.
     */
    private function sentEmail(int $index = 0): Email
    {
        $email = self::getMailerMessage($index);
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }
}
