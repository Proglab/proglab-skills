<?php

declare(strict_types=1);

namespace App\Core\EventListener;

use App\Core\Security\LoginFailureMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Twig\Environment;

/**
 * Un échec de connexion réaffiche la page en **422**, au lieu de rediriger.
 *
 * `form_login` redirige nativement vers `login_path` et laisse l'erreur en session ; la
 * page suivante l'affiche. Le critère de la story demande l'inverse — un 422 qui réaffiche
 * le formulaire — et ce n'est pas un détail de forme : c'est ce que Turbo Drive attend
 * pour remplacer la page au lieu d'ignorer la réponse, et c'est ce qui garde l'adresse
 * saisie sans la faire transiter par une redirection.
 *
 * **Pourquoi un écouteur et non un `failure_handler`.** Un
 * `AuthenticationFailureHandlerInterface` reçoit une `Request` et rend une `Response` —
 * donc il vit dans `src/Core/Security/`, dont le ruleset de `deptrac.yaml` n'autorise que
 * `Security, Entity, Dto, Enum` : la couche `Http` lui est fermée. `src/Core/EventListener/`
 * n'a **aucune** couche technique dans ce fichier, c'est la porte que `LocaleListener`
 * emprunte déjà, et le contrat de couches l'emporte sur la forme idiomatique — comme il
 * l'a emporté sur `repositoryClass:` à la story 1.5.
 *
 * L'ordre d'exécution compte et n'est pas évident : `AuthenticatorManager` appelle d'abord
 * `onAuthenticationFailure()` de l'authenticator — qui stocke l'exception en session et
 * construit une redirection — **puis** dispatche cet événement, dont la réponse gagne.
 * L'erreur laissée en session est donc consommée ici : sans cela, la page de connexion
 * rouverte plus tard afficherait une alerte pour un échec déjà montré.
 */
#[AsEventListener(event: LoginFailureEvent::class)]
final readonly class LoginFailureListener
{
    public function __construct(private Environment $twig)
    {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        // Une panne d'infrastructure n'est pas un mauvais mot de passe. Une
        // `AuthenticationServiceException` — la base injoignable pendant le chargement de
        // l'utilisateur, par exemple — habillée en « Email ou mot de passe incorrect. »
        // serait une panne qui ne remonte nulle part : pas de 5xx, pas d'alerte, et un
        // utilisateur convaincu de s'être trompé. On la relaie donc telle quelle ; elle
        // sort du pare-feu et devient une vraie erreur serveur.
        if (($exception = $event->getException()) instanceof AuthenticationServiceException) {
            throw $exception;
        }

        $request = $event->getRequest();

        $lastUsername = '';

        if ($request->hasSession()) {
            $session = $request->getSession();

            $session->remove(SecurityRequestAttributes::AUTHENTICATION_ERROR);

            $remembered = $session->get(SecurityRequestAttributes::LAST_USERNAME);
            $lastUsername = \is_string($remembered) ? $remembered : '';

            // Et l'adresse est retirée dans la foulée. `FormLoginAuthenticator` l'écrit en
            // session pour que la page suivante la repropose ; ici la page suivante est
            // celle qu'on rend juste en dessous, qui la porte déjà dans son propre HTML.
            // La laisser en session la ferait ressurgir sur un GET `/login` ultérieur —
            // c'est-à-dire montrer l'adresse d'une tentative précédente à la personne
            // suivante sur un poste partagé.
            $session->remove(SecurityRequestAttributes::LAST_USERNAME);
        }

        $event->setResponse(new Response(
            $this->twig->render('security/login.html.twig', [
                'last_username' => $lastUsername,
                'error_message' => LoginFailureMessage::of($exception),
            ]),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        ));
    }
}
