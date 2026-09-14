<?php

declare(strict_types=1);

namespace App\Core\Controller;

use App\Core\Dto\Input\PasswordRequestInput;
use App\Core\Dto\Input\PasswordResetInput;
use App\Core\Service\PasswordReset;
use App\Core\Service\PasswordResetRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Les deux pages publiques du mot de passe oublié.
 *
 * **Le premier formulaire du socle dont le POST atteigne réellement un contrôleur.** Celui
 * de `/login` est intercepté par le pare-feu ; la boucle « hydrater le DTO, valider,
 * rerendre en 422 » qu'on lit ci-dessous est donc le précédent que les Epics 2 à 6
 * copieront, et c'est pour cela qu'elle est courte et écrite deux fois plutôt que
 * factorisée dans une abstraction que personne n'a encore de raison d'écrire.
 *
 * **Pas de `symfony/form`** (décision D-1) : le contrôleur lit la charge utile, construit
 * un DTO `final readonly`, appelle `ValidatorInterface`, et passe les violations au
 * template, qui pose `aria-invalid` et `aria-describedby`. Le jeton CSRF est posé à la main
 * en Twig par `csrf_token()`, comme sur `/login`.
 *
 * **Ce contrôleur traduit, il ne décide pas.** Il ne sait pas si l'adresse existe, ni
 * pourquoi un lien est mort : `PasswordResetRequest` rend `void` dans tous les cas et
 * `PasswordReset` rend un booléen sans raison. C'est ce qui rend la réponse indiscernable
 * par construction plutôt que par discipline.
 *
 * **`#[IsCsrfTokenValid]` est restreint à POST** : l'attribut vérifie sinon le jeton sur
 * **toutes** les méthodes, donc aussi sur le GET qui rend le formulaire. Un POST sans
 * jeton valide lève une `InvalidCsrfTokenException`, que l'`ExceptionListener` du pare-feu
 * transforme en redirection vers `/login` — une écriture refusée, ce qui est le seul point
 * d'AD-18 ; la page de connexion est un point de chute honnête pour quelqu'un dont la
 * session vient d'expirer.
 *
 * **Le limiteur est celui de cette story**, nommé à part dans
 * `config/packages/rate_limiter.yaml` : ni `login_attempt` ni `login_address` ne couvrent
 * cette page — ils ne comptent que les échecs d'authentification du pare-feu, et une
 * demande de lien n'en produit aucun. Les secondes restantes sont **lues** sur le
 * limiteur, jamais déduites d'un message (AD-18, précédent de `LoginFailureListener`).
 */
#[Route('/password')]
final class PasswordController extends AbstractController
{
    /**
     * Les deux identifiants de jeton CSRF, un par formulaire.
     *
     * Deux plutôt qu'un : un jeton valable sur la demande ne doit pas l'être sur la
     * réinitialisation. Ils sont nommés ici et lus en Twig — un couplage qui n'est visible
     * qu'à un seul bout se casse en silence.
     */
    public const string CSRF_REQUEST = 'password_request';

    public const string CSRF_RESET = 'password_reset';

    /**
     * Le type de Flash du socle, et le seul pour l'instant.
     */
    public const string FLASH_SUCCESS = 'success';

    /**
     * La clé de limiteur des requêtes sans adresse IP.
     *
     * Ce n'est pas une adresse : c'est un nom qu'aucune adresse ne peut prendre, pour que
     * ces requêtes forment un seau à elles et ne se confondent ni avec un client réel, ni
     * avec le seau global que `create(null)` produirait.
     */
    private const string UNKNOWN_CLIENT = 'sans-adresse';

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly PasswordResetRequest $passwordResetRequest,
        private readonly PasswordReset $passwordReset,
        private readonly RateLimiterFactoryInterface $passwordRequestLimiter,
    ) {
    }

    /**
     * La demande. Toute adresse reçoit exactement la même page.
     */
    #[Route('/forgot', name: 'app_password_request', methods: ['GET', 'POST'])]
    #[IsCsrfTokenValid(self::CSRF_REQUEST, tokenKey: '_csrf_token', methods: 'POST')]
    public function request(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->renderRequestPage();
        }

        // Le limiteur d'abord : une rafale de soumissions malformées est aussi une
        // rafale, et la borner ne demande pas de savoir ce qu'elle contenait.
        //
        // **La sentinelle, et ce qu'elle achète exactement.** `getClientIp()` rend `null`
        // quand la requête ne porte pas de `REMOTE_ADDR` — une requête forgée, un serveur
        // mal configuré. `RateLimiterFactory::create()` concatène alors sans rien dire
        // (`$this->config['id'].'-'.$key`, donc `password_request-`) : ces requêtes se
        // partagent un seau, ce qui est le comportement voulu, mais sous une clé vide que
        // personne ne reconnaît en lisant le pool, et qu'aucun appel du composant ne
        // promet de garder. La valeur ci-dessous nomme ce seau et le met hors d'atteinte
        // d'une adresse réelle.
        //
        // Ce n'est donc **pas** un correctif de comportement : sans elle, la page ne se
        // ferme pas davantage — `getClientIp()` rend soit `null`, soit une vraie adresse,
        // jamais la chaîne vide, donc aucun client légitime ne tombe dans ce seau-là.
        // `PasswordResetThrottlingTest::a_request_without_an_address_does_not_close_the_page_for_everyone()`
        // épingle la propriété qui compte — ces requêtes sont bornées, et elles ne bornent
        // personne d'autre — et elle reste vraie des deux façons.
        $limit = $this->passwordRequestLimiter->create($request->getClientIp() ?? self::UNKNOWN_CLIENT)->consume();

        if (!$limit->isAccepted()) {
            // Les secondes sont lues sur le limiteur, comme `LoginFailureListener` les lit
            // sur le sien : l'en-tête et la phrase affichée portent donc le **même**
            // nombre — deux valeurs qui divergeraient, c'est un client automatique qui
            // réessaie avant l'écran, ou après.
            $seconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            $response = $this->renderRequestPage(retryAfterSeconds: $seconds, status: Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) $seconds);

            return $response;
        }

        $input = new PasswordRequestInput(self::string($request, 'email'));
        $violations = $this->validator->validate($input);

        if ($violations->count() > 0) {
            return $this->renderRequestPage($input->email, $violations, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->passwordResetRequest->request($input->email);

        // Le champ est rendu vide, et la confirmation est la même dans les trois cas de la
        // matrice : c'est ce qui rend les réponses identiques au caractère près, hors
        // jeton CSRF.
        return $this->renderRequestPage(confirmed: true);
    }

    /**
     * La réinitialisation. Un lien mort rend toujours la même page, en 410.
     */
    #[Route('/reset/{token}', name: 'app_password_reset', methods: ['GET', 'POST'])]
    #[IsCsrfTokenValid(self::CSRF_RESET, tokenKey: '_csrf_token', methods: 'POST')]
    public function reset(Request $request, string $token): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->passwordReset->accepts($token) ? $this->renderResetPage() : $this->renderExpiredPage();
        }

        $input = new PasswordResetInput(
            self::string($request, 'password'),
            self::string($request, 'confirmation'),
        );

        // L'ordre compte : un lien mort le reste quelle que soit la saisie, et rerendre le
        // formulaire pour un jeton qui n'ouvre plus rien laisserait croire qu'il suffit de
        // corriger sa saisie.
        if (!$this->passwordReset->accepts($token)) {
            return $this->renderExpiredPage();
        }

        $violations = $this->validator->validate($input);

        if ($violations->count() > 0) {
            // Le jeton n'est pas consommé : le lien reste utilisable, et c'est le service
            // qui le garantit — il n'a tout simplement pas été appelé.
            return $this->renderResetPage($violations);
        }

        if (!$this->passwordReset->reset($token, $input->password)) {
            // Le lien est mort entre la vérification et l'action — une seconde soumission
            // simultanée, par exemple. Même page que partout ailleurs.
            return $this->renderExpiredPage();
        }

        $this->addFlash(self::FLASH_SUCCESS, 'password.reset.done');

        // 303 et non 302 : la réponse à un POST qui a agi est une autre ressource, et le
        // rechargement de la page d'arrivée ne doit pas resoumettre. Le flux se termine
        // sur la connexion — personne n'est connecté automatiquement.
        return $this->redirectToRoute('app_login', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @param ConstraintViolationListInterface<int, \Symfony\Component\Validator\ConstraintViolationInterface>|null $violations
     */
    private function renderRequestPage(
        string $email = '',
        ?ConstraintViolationListInterface $violations = null,
        int $status = Response::HTTP_OK,
        bool $confirmed = false,
        ?int $retryAfterSeconds = null,
    ): Response {
        return $this->render('password/request.html.twig', [
            'email' => $email,
            'errors' => self::byField($violations),
            'confirmed' => $confirmed,
            'retry_after_seconds' => $retryAfterSeconds,
        ], new Response(status: $status));
    }

    /**
     * @param ConstraintViolationListInterface<int, \Symfony\Component\Validator\ConstraintViolationInterface>|null $violations
     */
    private function renderResetPage(?ConstraintViolationListInterface $violations = null): Response
    {
        return $this->render('password/reset.html.twig', [
            'errors' => self::byField($violations),
        ], new Response(status: null === $violations ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * Une seule page pour « inconnu », « malformé », « expiré », « déjà utilisé » et
     * « compte désactivé », et un seul statut.
     *
     * **410 et non 404** : « a existé, n'existe plus » est le statut juste, et il ne se
     * laisse pas distinguer d'un jeton jamais émis — un 404 dirait à qui essaie des jetons
     * au hasard lesquels ont existé. La page ne porte **aucun formulaire** : elle propose
     * de recommencer, elle ne laisse pas croire qu'un mot de passe saisi ici servirait à
     * quelque chose.
     */
    private function renderExpiredPage(): Response
    {
        return $this->render('password/expired.html.twig', [], new Response(status: Response::HTTP_GONE));
    }

    /**
     * La valeur d'un champ de la charge utile, ou une chaîne vide.
     *
     * `all()` et non `getString()` : celui-ci lève une `BadRequestException` — donc un 400
     * — dès que le paramètre n'est pas scalaire, comme dans `email[]=x`. La matrice promet
     * un 422 avec une erreur de format sur une entrée qu'on n'a pas su lire, pas une page
     * d'erreur ; tout ce qui n'est pas une chaîne est donc traité comme vide, et les
     * contraintes du DTO tranchent.
     */
    private static function string(Request $request, string $field): string
    {
        $value = $request->getPayload()->all()[$field] ?? null;

        return \is_string($value) ? $value : '';
    }

    /**
     * Les messages de violation, rangés par nom de champ.
     *
     * Le template n'a besoin de rien d'autre : un champ en erreur porte `aria-invalid` et
     * un `aria-describedby` vers le paragraphe qui porte ce message — la seule façon dont
     * le socle promet de doubler la couleur d'une bordure (règle 1).
     *
     * @param ConstraintViolationListInterface<int, \Symfony\Component\Validator\ConstraintViolationInterface>|null $violations
     *
     * @return array<string, string>
     */
    private static function byField(?ConstraintViolationListInterface $violations): array
    {
        $errors = [];

        foreach ($violations ?? [] as $violation) {
            $field = $violation->getPropertyPath();

            // Le premier message par champ suffit : deux messages sous un même champ se
            // lisent comme une liste de reproches, et le second n'aide jamais à corriger
            // le premier.
            $errors[$field] ??= (string) $violation->getMessage();
        }

        return $errors;
    }
}
