<?php

declare(strict_types=1);

namespace App\Core\EventListener;

use App\Core\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * À la connexion réussie, la langue du compte passe devant.
 *
 * Elle est **écrite en session**, sous la clé que `LocaleListener` lit déjà — pas lue par
 * un nouveau barreau de la chaîne de résolution. Ce choix est celui que la chaîne rend
 * obligatoire, et il vaut la peine d'être écrit une fois pour que personne ne réessaye
 * l'autre :
 *
 * `LocaleListener` tourne à la priorité **20**, le pare-feu de Symfony à **8**. À 20, il
 * n'y a pas encore de token — `getUser()` rendrait `null`, sur chaque requête. Descendre
 * sous 8 déplacerait le problème sur le translator, que `LocaleAwareListener` synchronise
 * à la priorité **15** : la locale serait posée après, donc trop tard pour être traduite.
 *
 * Écrire la langue du compte ici produit exactement le comportement décrit par l'UX —
 * « après connexion, la langue du profil prend le relais » — sans toucher à une chaîne de
 * résolution déjà testée, et sans coût sur les requêtes anonymes.
 *
 * **Sur l'ordre vis-à-vis de la régénération de session.** `SessionStrategyListener`
 * s'abonne à `LoginSuccessEvent` **sans priorité** (`getSubscribedEvents()` rend
 * `[LoginSuccessEvent::class => 'onSuccessfulLogin']`), donc à 0 — la même que cet
 * écouteur-ci. Entre deux écouteurs de même priorité, c'est l'ordre d'enregistrement qui
 * tranche, et celui du framework est enregistré avant : la session est régénérée d'abord.
 * Rien ne repose pourtant sur cet ordre — la stratégie par défaut est `migrate`, qui
 * conserve les attributs — et c'est pour cela qu'aucune priorité n'est déclarée ici : en
 * poser une donnerait l'impression que l'écriture en dépend.
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final readonly class LoginLocaleListener
{
    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        $request = $event->getRequest();

        if (!$user instanceof User || !$request->hasSession()) {
            return;
        }

        $request->getSession()->set(LocaleListener::SESSION_KEY, $user->getLanguage()->value);
    }
}
