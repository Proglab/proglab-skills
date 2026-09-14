<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use App\Core\Entity\User;
use App\Core\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Security\Core\User\EquatableInterface;

/**
 * AD-9, et le seul test qui le prouve : changer un mot de passe ferme les autres sessions.
 *
 * **Comment le mécanisme fonctionne, parce que le test n'a de sens qu'avec.** Le compte
 * porte un entier, `securityToken`, que `App\Core\Service\PasswordReset` incrémente. À
 * chaque requête, `ContextListener` recharge l'utilisateur depuis le provider et compare
 * l'objet **désérialisé de la session** au **fraîchement chargé** : dès que `User`
 * implémente `EquatableInterface`, c'est `isEqualTo()` — et lui seul — qui tranche. La
 * session porte encore l'ancienne valeur, la base porte la nouvelle, la comparaison échoue,
 * et le token est jeté.
 *
 * **Deux navigateurs, pas deux onglets.** Chaque `KernelBrowser` a son propre magasin de
 * cookies, donc sa propre session : c'est la seule façon d'exercer « une session ouverte
 * ailleurs » dans une suite fonctionnelle.
 *
 * **Le contrôle par mot de passe ne suffit pas, et c'est ce que le second test montre.**
 * Sans `EquatableInterface`, Symfony compare déjà les hachages et fermerait cette
 * session-ci ; mais la désactivation d'un compte (Epic 2) et la réinitialisation du second
 * facteur (Epic 5) ne changent aucun hachage, et AD-9 les nomme toutes les trois. Le jeton
 * de sécurité est le mécanisme commun ; ce test épingle qu'il **est** le critère.
 */
final class SessionInvalidationTest extends WebTestCase
{
    private const string EMAIL = 'marc@example.test';

    private const string OLD_PASSWORD = 'un-mot-de-passe-assez-long';

    private const string NEW_PASSWORD = 'girafe-tranquille-42-bureau';

    protected function setUp(): void
    {
        PasswordThrottling::forget();
    }

    #[Test]
    public function a_session_opened_elsewhere_is_no_longer_authenticated_after_the_reset(): void
    {
        $elsewhere = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::OLD_PASSWORD);

        $elsewhere->submit(self::loginForm($elsewhere, self::OLD_PASSWORD));
        self::assertResponseRedirects('/');
        self::assertTrue(self::isSignedIn($elsewhere), 'La session de départ n\'a pas été ouverte : le test ne prouverait rien.');

        self::resetThePasswordFromAnotherBrowser();

        self::assertFalse(
            self::isSignedIn($elsewhere),
            'La session ouverte ailleurs est toujours authentifiée après le changement de mot de passe (AD-9).',
        );
    }

    /**
     * Le jeton de sécurité **est** le critère, et pas seulement un champ de plus.
     *
     * L'incrémenter sans rien toucher d'autre suffit à fermer la session : c'est ce qui
     * rendra la désactivation d'un compte et la réinitialisation du second facteur
     * mécanisables sans réécrire quoi que ce soit ici.
     */
    #[Test]
    public function bumping_the_security_token_alone_closes_the_session(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::OLD_PASSWORD);

        $client->submit(self::loginForm($client, self::OLD_PASSWORD));
        self::assertTrue(self::isSignedIn($client));

        $user = self::freshUser();
        $user->setSecurityToken($user->getSecurityToken() + 1);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertFalse(self::isSignedIn($client), 'Incrémenter le jeton de sécurité ne ferme pas la session : AD-9 n\'est pas câblé.');
    }

    /**
     * Le compte implémente bien le contrat que `ContextListener` interroge. Sans lui, la
     * comparaison retomberait sur le hachage du mot de passe — ce qui marche pour cette
     * story et pour aucune des deux suivantes.
     */
    #[Test]
    public function the_account_carries_the_contract_the_context_listener_reads(): void
    {
        // `class_implements()` et non `is_subclass_of()` : celui-ci est décidable
        // statiquement, donc PHPStan le réduit à `true` et l'assertion cesse d'en être
        // une. Celui-là rend un tableau que l'analyse ne resserre pas, et le test mesure
        // donc bien ce qu'il dit mesurer.
        self::assertContains(
            EquatableInterface::class,
            class_implements(User::class),
            '`User` n\'implémente plus `EquatableInterface` : la comparaison retombe sur le hachage du mot de passe, et le jeton de sécurité ne ferme plus rien.',
        );
    }

    /**
     * La session de celui qui vient de changer son mot de passe n'est pas rouverte : le
     * flux se termine sur `/login`, il ne connecte personne (contrainte de la story).
     */
    #[Test]
    public function the_reset_does_not_sign_anyone_in(): void
    {
        $client = self::createClient();
        Accounts::create(self::getContainer(), self::EMAIL, self::OLD_PASSWORD);

        $token = self::askForALink($client);
        $client->submit(self::resetForm($client, $token));

        self::assertResponseRedirects('/login', 303);
        self::assertFalse(self::isSignedIn($client), 'La réinitialisation a ouvert une session : le flux doit se terminer sur la page de connexion.');
    }

    // -------------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------------

    /**
     * Le second navigateur fait le parcours complet — demande, lien, deux saisies.
     *
     * Il est créé après le premier, et c'est possible parce que `createClient()` ne refuse
     * que de s'exécuter derrière un noyau **démarré** : le noyau du premier client est
     * refermé le temps de cet appel, et sa session vit dans son propre magasin de cookies,
     * pas dans le conteneur.
     */
    private static function resetThePasswordFromAnotherBrowser(): void
    {
        self::ensureKernelShutdown();

        $browser = self::createClient();

        $token = self::askForALink($browser);
        $browser->submit(self::resetForm($browser, $token));

        self::assertResponseRedirects('/login', 303, 'La réinitialisation faite depuis l\'autre navigateur a échoué : le test suivant ne prouverait rien.');
    }

    private static function askForALink(KernelBrowser $client): string
    {
        $form = $client->request('GET', '/password/forgot')->filter('form')->form();
        $form['email'] = self::EMAIL;

        $client->submit($form);

        return PasswordTokens::sentToken(self::getContainer());
    }

    private static function resetForm(KernelBrowser $client, string $token): Form
    {
        $form = $client->request('GET', '/password/reset/'.$token)->filter('form')->form();

        $form['password'] = self::NEW_PASSWORD;
        $form['confirmation'] = self::NEW_PASSWORD;

        return $form;
    }

    private static function loginForm(KernelBrowser $client, string $password): Form
    {
        $form = $client->request('GET', '/login')->filter('form')->form();

        $form['_username'] = self::EMAIL;
        $form['_password'] = $password;

        return $form;
    }

    private static function freshUser(): User
    {
        $container = self::getContainer();

        $container->get(EntityManagerInterface::class)->clear();

        $user = $container->get(UserRepository::class)->findOneByEmail(self::EMAIL);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * Aucune page du socle n'est protégée : la seule façon de demander à l'application
     * « suis-je connecté ? » est de lui demander la page de connexion, qui renvoie sur
     * l'accueil quand une session est ouverte.
     */
    private static function isSignedIn(KernelBrowser $client): bool
    {
        $client->request('GET', '/login');

        return $client->getResponse()->isRedirect();
    }
}
