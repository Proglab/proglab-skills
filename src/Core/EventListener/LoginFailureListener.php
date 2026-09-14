<?php

declare(strict_types=1);

namespace App\Core\EventListener;

use App\Core\Security\LoginFailureMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\RateLimiter\DefaultLoginRateLimiter;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Twig\Environment;

/**
 * Un échec de connexion réaffiche la page en **422**, au lieu de rediriger — et en **429**
 * quand c'est le ralentissement qui a refusé.
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
 * l'a emporté sur `repositoryClass:` à la story 1.5. C'est la même raison qui met ici, et
 * pas dans `LoginFailureMessage`, la lecture du limiteur : `peek()` prend une `Request` et
 * rend un objet du composant RateLimiter.
 *
 * L'ordre d'exécution compte et n'est pas évident : `AuthenticatorManager` appelle d'abord
 * `onAuthenticationFailure()` de l'authenticator — qui stocke l'exception en session et
 * construit une redirection — **puis** dispatche cet événement, dont la réponse gagne.
 * L'erreur laissée en session est donc consommée ici : sans cela, la page de connexion
 * rouverte plus tard afficherait une alerte pour un échec déjà montré.
 *
 * **Deux priorités, et chacune répond à un problème distinct** (story 1.7).
 *
 * `rethrowServiceFailure()` est à **256**, donc *avant* la consommation native de
 * `LoginThrottlingListener::onFailedLogin()` (priorité 0). L'exception qu'il relance
 * interrompt la propagation, si bien qu'une base injoignable ne brûle aucune tentative :
 * sans cela, une panne de quelques minutes ralentirait l'utilisateur une fois le service
 * rétabli — le limiteur aurait compté des échecs qui n'en étaient pas.
 *
 * `__invoke()` est à **-64**, donc *après* cette même consommation : le `peek()` lit le
 * limiteur une fois la tentative courante comptée.
 *
 * **Ce que l'ordre change vraiment, et ce qu'il ne change pas.** Sur le palier court,
 * rien : une tentative déjà refusée n'y est pas comptée —
 * `FixedWindowLimiter::reserve()` lève sa `MaxWaitDurationExceededException` **avant**
 * `Window::add()`, et `consume()` la rattrape — donc les deux ordres rendraient le même
 * nombre (vérifié en inversant la priorité : aucun test ne bouge). Sur le palier long,
 * si : `AbstractRequestRateLimiter::doConsume()` sollicite **les deux** limiteurs, et
 * celui qui n'est pas encore épuisé, lui, compte. Chaque refus draine donc le palier
 * long, et c'est ce qui fait l'escalade — quelques secondes d'abord, jusqu'à un quart
 * d'heure quand l'acharnement l'a vidé à son tour.
 */
final readonly class LoginFailureListener
{
    public function __construct(
        private Environment $twig,
        private DefaultLoginRateLimiter $loginRateLimiter,
    ) {
    }

    /**
     * Une panne d'infrastructure n'est pas un mauvais mot de passe.
     *
     * Une `AuthenticationServiceException` — la base injoignable pendant le chargement de
     * l'utilisateur, par exemple — habillée en « Email ou mot de passe incorrect. » serait
     * une panne qui ne remonte nulle part : pas de 5xx, pas d'alerte, et un utilisateur
     * convaincu de s'être trompé. On la relaie donc telle quelle ; elle sort du pare-feu
     * et devient une vraie erreur serveur.
     *
     * **La priorité haute est ce qui rend la panne gratuite.** L'exception interrompt la
     * propagation de l'événement, donc `LoginThrottlingListener::onFailedLogin()`, qui
     * vient après, ne consomme rien : un incident d'infrastructure ne dépense aucune
     * tentative, et personne ne se retrouve ralenti par une panne qu'il n'a pas causée.
     *
     * La `TooManyLoginAttemptsAuthenticationException` n'est pas une panne : c'est un
     * refus attendu, et c'est `__invoke()` qui la traite.
     */
    #[AsEventListener(priority: 256)]
    public function rethrowServiceFailure(LoginFailureEvent $event): void
    {
        if (($exception = $event->getException()) instanceof AuthenticationServiceException) {
            throw $exception;
        }
    }

    #[AsEventListener(priority: -64)]
    public function __invoke(LoginFailureEvent $event): void
    {
        $exception = $event->getException();
        $request = $event->getRequest();

        $throttled = $exception instanceof TooManyLoginAttemptsAuthenticationException;

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

        $message = LoginFailureMessage::of($exception, $throttled ? $this->secondsLeft($request) : null);

        $response = new Response(
            $this->twig->render('security/login.html.twig', [
                'last_username' => $lastUsername,
                'error_message' => $message,
            ]),
            $throttled ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        // Le délai est déjà calculé, et la RFC 9110 lui donne son en-tête. Il porte la
        // **même** valeur que la phrase affichée — deux nombres qui divergeraient, c'est
        // un client automatique qui réessaie avant l'écran, ou après.
        if (null !== $message?->retryAfterSeconds) {
            $response->headers->set('Retry-After', (string) $message->retryAfterSeconds);
        }

        $event->setResponse($response);
    }

    /**
     * Les secondes qu'il reste à attendre, lues sur le limiteur — brutes.
     *
     * `peek()` consomme **zéro** jeton : c'est une lecture, elle ne prolonge pas le délai
     * qu'elle rapporte. Le nombre ne peut pas venir de l'exception : son seuil est un
     * `ceil()` de **minutes**, donc « trois secondes restantes » y devient « une minute »
     * (AD-18).
     *
     * Le résultat peut valoir zéro, ou moins : `getRetryAfter()` rend une date arrondie à
     * la seconde inférieure, et l'horloge avance entre les deux appels. C'est
     * `LoginFailureMessage` qui pose le plancher à une seconde, parce que c'est une règle
     * et que les règles s'épinglent sans conteneur.
     */
    private function secondsLeft(Request $request): int
    {
        return $this->loginRateLimiter->peek($request)->getRetryAfter()->getTimestamp() - time();
    }
}
