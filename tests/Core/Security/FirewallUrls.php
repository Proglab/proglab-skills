<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Les URLs que le pare-feu fabrique lui-même, rendues utilisables depuis un test.
 *
 * La déconnexion porte un jeton CSRF (`logout.enable_csrf`), donc `/logout` tout nu est
 * refusé en 403 : viser cette URL à la main ne testerait que la protection CSRF. L'URL
 * juste est celle que `logout_path()` rend côté Twig, et c'est le même générateur qui la
 * produit ici — un test qui recopierait la forme de l'URL cesserait de la vérifier.
 *
 * **Pourquoi c'est plus long que `getLogoutPath()`.** Le générateur lit la session par la
 * pile de requêtes, et entre deux requêtes d'un `KernelBrowser` cette pile est vide : la
 * requête précédente y est donc remise le temps de l'appel. Et le jeton qui vient d'être
 * créé n'existe que dans l'objet Session de cette requête-là, déjà refermé par la réponse
 * — d'où l'enregistrement explicite, sans lequel la requête suivante relirait une session
 * dépourvue du jeton et recevrait un 403.
 */
final readonly class FirewallUrls
{
    /**
     * Le seul pare-feu applicatif du socle, nommé plutôt que déduit.
     *
     * Sans nom, le générateur lit le pare-feu « courant », que le `FirewallMap` pose
     * pendant une requête — une information qui ne survit pas fiablement au redémarrage de
     * noyau qu'un `KernelBrowser` fait entre deux requêtes, et le générateur lève alors
     * « This request is not behind a firewall ». Le nommer est aussi ce qui rendra ce
     * helper lisible le jour où l'Epic 6 posera le second pare-feu, sans état, sur `/api`.
     */
    private const string FIREWALL = 'main';

    /**
     * Le chemin de déconnexion, jeton CSRF compris.
     *
     * Le client doit avoir déjà émis une requête : c'est elle qui porte la session dans
     * laquelle le jeton est écrit.
     */
    public static function logoutPath(KernelBrowser $client, ContainerInterface $container): string
    {
        $requests = $container->get(RequestStack::class);
        $generator = $container->get('security.logout_url_generator');

        $request = $client->getRequest();
        $requests->push($request);

        try {
            $path = $generator->getLogoutPath(self::FIREWALL);
        } finally {
            $requests->pop();
        }

        // Le jeton vient d'être écrit dans une session que la réponse précédente avait
        // refermée ; le stockage de jetons l'a rouverte pour l'écrire, et c'est à nous de
        // la refermer pour que la requête suivante le retrouve.
        $request->getSession()->save();

        return $path;
    }

    /**
     * Le même chemin, signé **seulement** s'il est celui de la déconnexion.
     *
     * C'est la sécurité qui dit quel chemin lui appartient, pas un nom de route recopié :
     * renommer la route ou déplacer le chemin ne fait pas mentir ce test.
     */
    public static function signed(KernelBrowser $client, ContainerInterface $container, string $path): string
    {
        $logout = self::logoutPath($client, $container);

        return $path === parse_url($logout, \PHP_URL_PATH) ? $logout : $path;
    }
}
