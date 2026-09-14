<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Mail;

use App\Core\Enum\SupportedLocale;
use App\Core\Message\SendEmail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Le seul consommateur de `SendEmail` livré par la story 1.8, et il n'existe que sous
 * test.
 *
 * Livrer une infrastructure que rien n'exerce, c'est livrer une infrastructure non
 * vérifiée : le socle n'a aujourd'hui aucun email à envoyer — le premier est celui de la
 * story 1.9 —, donc le cas d'usage de démonstration vit dans les fixtures, exactement
 * comme `DemoErrorController` prouve les pages d'erreur sans ajouter de route au socle.
 *
 * **Ce que cette classe montre, et qui est le vrai sujet de la story (décision D-1).** La
 * frontière de transaction est **explicite** — `wrapInTransaction()` — et le dispatch a
 * lieu **à l'intérieur**. Le transport `doctrine://default` écrit sur la connexion DBAL
 * de l'application, donc l'`INSERT` du message rejoint cette transaction : il n'est
 * visible d'aucune autre connexion tant qu'elle n'est pas commitée, et il disparaît avec
 * elle si elle est annulée.
 *
 * **Pourquoi l'ordre inverse ne tiendrait pas.** Dispatcher après le commit — ou sans
 * transaction du tout — laisse une fenêtre où le message est en file alors que le travail
 * qu'il annonce n'a pas abouti : le worker peut le dépiler avant que la transaction ne
 * soit commitée, et envoyer un email au sujet d'une ligne qui n'existera jamais.
 * `EmailQueueTest::dispatching_outside_the_transaction_would_leave_the_row_behind()` le
 * montre sur le même transport plutôt que de le déclarer ici.
 *
 * **Conséquence à tenir pour les stories 1.9, 2.6 et 5.2 :** chaque cas d'usage qui envoie
 * un email ouvre sa transaction explicitement et dispatche dedans. Ce n'est pas un
 * middleware qui le fait à sa place — `dispatch_after_current_bus` ne diffère un dispatch
 * que depuis l'intérieur d'un handler, donc uniquement si Messenger servait de bus de
 * commandes, ce que `README.md` interdit.
 *
 * **Pourquoi cette classe n'est pas dans `tests/Fixtures/Module/Demo/`.** Le contrat de
 * couches n'accorde à un module que `App\Core\Contract\` (AD-2), et `SendEmail` vit dans
 * `App\Core\Message\` : un module qui le référence est une violation `ModuleDemo on Core`,
 * que `tests/Core/BoundaryTest.php` tient déjà. Cette classe reste donc hors de la racine
 * des modules, où deptrac ne l'analyse pas, et la question qu'elle soulève — un module
 * client pourra-t-il un jour envoyer un email ? — part dans `deferred-work.md` plutôt que
 * d'être tranchée en ouvrant la frontière.
 */
final readonly class DemoEmailDispatch
{
    /**
     * Le gabarit de démonstration, servi par le chemin Twig `when@test`.
     */
    public const string TEMPLATE = 'emails/demo.html.twig';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Le cas nominal : une frontière de transaction, et le dispatch dedans.
     */
    public function notify(SupportedLocale $locale, string $widgetLabel, string $recipient): void
    {
        $this->entityManager->wrapInTransaction(function () use ($locale, $widgetLabel, $recipient): void {
            // Ici vivrait le travail que l'email annonce — la persistance d'une entité,
            // puis son flush. Le socle n'en a aucun à montrer aujourd'hui, et la seule
            // entité de fixture disponible (`DemoWidget`) n'a volontairement pas de table
            // : aucune migration ne la crée, et en créer une déposerait une table de
            // démonstration dans la base de chaque dérivé.
            //
            // Ce qui compte pour la story est la position de la ligne suivante, pas ce
            // qui la précède.
            $this->bus->dispatch(new SendEmail(
                $recipient,
                'Alice Demo',
                $locale,
                'demo.subject',
                self::TEMPLATE,
                ['widget' => $widgetLabel],
            ));
        });
    }
}
