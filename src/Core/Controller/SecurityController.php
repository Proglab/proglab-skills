<?php

declare(strict_types=1);

namespace App\Core\Controller;

use App\Core\Security\LoginFailureMessage;
use LogicException;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Les deux routes du pare-feu, dans une seule classe — et deux méthodes dont l'une n'est
 * jamais atteinte pour la moitié de ce qu'elle déclare.
 *
 * `login()` rend le formulaire en GET. Le **POST de la même route n'arrive jamais ici** :
 * `check_path` vaut `app_login`, donc `FormLoginAuthenticator` intercepte la requête bien
 * avant le routeur d'action. La route déclare quand même `POST` pour que l'URL existe
 * avec ce verbe — sans quoi le `RouterListener`, qui s'exécute à la priorité 32 contre 8
 * pour le pare-feu, répondrait 405 avant que la sécurité n'ait vu la requête.
 *
 * `logout()` n'est jamais atteinte du tout, pour la même raison. Son corps vide est le
 * corps correct.
 *
 * Aucun DTO d'entrée ni FormType : `form_login` lit `_username` et `_password` bruts sur
 * la requête et aucun contrôleur ne traite cette entrée. C'est la règle 4 du standard lue
 * strictement — des DTO aux deux extrémités *de ce que le code traite* — et non
 * contournée : installer `symfony/form` pour décrire un formulaire que personne ne
 * soumet au serveur PHP ajouterait un FormType, un DTO et une validation que rien
 * n'exécuterait jamais.
 */
final class SecurityController extends AbstractController
{
    /**
     * @return array{last_username: string, error_message: LoginFailureMessage|null}|Response
     */
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    #[Template('security/login.html.twig')]
    public function login(AuthenticationUtils $authenticationUtils): array|Response
    {
        // Une session ouverte n'a rien à faire sur la page de connexion.
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return [
            'last_username' => $authenticationUtils->getLastUsername(),
            // En pratique toujours `null` : l'écouteur d'échec rend la page lui-même et
            // consomme l'erreur de session au passage. La lecture reste ici parce que
            // c'est la porte par laquelle une erreur *pourrait* arriver — un point
            // d'entrée de pare-feu qui redirige vers `/login`, demain.
            'error_message' => LoginFailureMessage::of($authenticationUtils->getLastAuthenticationError()),
        ];
    }

    /**
     * Interceptée par le pare-feu ; ce corps ne s'exécute jamais.
     */
    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new LogicException('Cette méthode est interceptée par la clé `logout` du pare-feu et ne doit jamais être atteinte.');
    }
}
