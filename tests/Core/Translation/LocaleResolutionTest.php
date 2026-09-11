<?php

declare(strict_types=1);

namespace App\Tests\Core\Translation;

use App\Core\Entity\Language;
use App\Core\Enum\SupportedLocale;
use App\Core\EventListener\LocaleListener;
use App\Core\Repository\LanguageRepository;
use App\Core\Service\ActiveLocales;
use App\Tests\Core\Quality\GateFiles;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\EventListener\LocaleListener as SymfonyLocaleListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * Une seule autorité sur la locale de la requête, et la matrice d'edge cases qui la
 * décrit ligne par ligne.
 *
 * La chaîne est explicite et fermée : `?lang=` validé contre les langues **actives** et
 * mémorisé en session → session → première langue active. Ni `Accept-Language`, ni
 * négociation de contenu, ni préfixe de langue dans l'URL (AD-6). La story 1.6 n'y
 * insérera qu'un barreau « champ du compte », entre la session et la première langue
 * active ; rien n'est écrit ici pour l'anticiper.
 *
 * Le repli est **calculé à la lecture**, jamais écrit : désactiver une langue ne réécrit
 * aucune ligne, et surtout pas la session de qui l'utilisait.
 */
final class LocaleResolutionTest extends WebTestCase
{
    #[Test]
    public function with_no_preference_the_locale_is_the_first_active_language(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('fr', self::localeOf($client), 'Sans préférence, la locale doit être la première langue active dans l\'ordre de l\'enum.');
        self::assertSame('fr', self::renderedLanguage($client), 'Le document ne déclare pas la locale résolue dans son attribut `lang` (WCAG 3.1.1).');
    }

    #[Test]
    public function an_explicit_choice_is_applied_and_remembered_in_the_session(): void
    {
        $client = self::createClient();

        $client->request('GET', '/?lang=nl');
        self::assertResponseIsSuccessful();
        self::assertSame('nl', self::localeOf($client), 'Un `?lang=` valide et actif doit être appliqué à la requête.');
        self::assertSame('nl', self::renderedLanguage($client), 'Le document ne porte pas `<html lang="nl">` après un choix explicite.');

        $client->request('GET', '/');
        self::assertSame('nl', self::localeOf($client), 'Le choix n\'a pas été mémorisé en session : la page suivante repasse en français.');
    }

    /**
     * « Ignoré ; la résolution continue comme si le paramètre était absent » — aucune
     * erreur, aucune redirection. Un lien périmé ou bricolé à la main ne casse pas une
     * page.
     */
    #[Test]
    public function an_unknown_language_is_ignored_without_error_or_redirect(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?lang=de');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('fr', self::localeOf($client), 'Un `?lang=` inconnu doit être ignoré, pas appliqué.');
    }

    /**
     * La même ligne de la matrice, sur la forme qui ne ressemble pas à une chaîne : un
     * `?lang[]=nl` arrive comme un tableau. Lire le paramètre avec `getString()` lèverait
     * là une `BadRequestException` — une 400 sur une page publique, pour un lien bricolé à
     * la main ou un crawler qui recopie mal une URL.
     */
    #[Test]
    public function an_unusable_lang_parameter_is_ignored_without_error_or_redirect(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?lang[]=nl');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('fr', self::localeOf($client), 'Un `?lang=` qui n\'est pas une chaîne doit être ignoré comme un paramètre absent, sans erreur ni redirection.');
    }

    #[Test]
    public function a_supported_but_inactive_language_is_ignored_like_an_unknown_one(): void
    {
        $client = self::createClient();
        self::setEnabled(false, SupportedLocale::Nl);

        $client->request('GET', '/?lang=nl');

        self::assertResponseIsSuccessful();
        self::assertSame('fr', self::localeOf($client), '`?lang=` est validé contre les langues **actives**, pas contre les langues supportées.');
    }

    /**
     * La ligne la plus subtile de la matrice : la session porte `nl`, `nl` vient d'être
     * désactivée. Le repli est calculé — et la session n'est pas réécrite, donc
     * réactiver la langue rend l'utilisateur à son choix d'origine.
     */
    #[Test]
    public function a_language_disabled_mid_session_falls_back_without_rewriting_the_session(): void
    {
        $client = self::createClient();

        $client->request('GET', '/?lang=nl');
        self::assertSame('nl', self::localeOf($client));

        self::setEnabled(false, SupportedLocale::Nl);

        $client->request('GET', '/');
        self::assertSame('fr', self::localeOf($client), 'Une langue désactivée pendant la session doit retomber sur la première langue active.');

        self::setEnabled(true, SupportedLocale::Nl);

        $client->request('GET', '/');
        self::assertSame('nl', self::localeOf($client), 'La session a été réécrite pendant le repli : le choix d\'origine est perdu alors que la langue est redevenue active.');
    }

    #[Test]
    public function every_language_disabled_falls_back_to_the_default_locale(): void
    {
        $client = self::createClient();
        self::setEnabled(false, ...SupportedLocale::cases());

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame(
            self::getContainer()->getParameter('kernel.default_locale'),
            self::localeOf($client),
            'Zéro ligne active doit retomber sur la langue par défaut, sans lever d\'exception.',
        );
    }

    /**
     * Les langues actives viennent de la table, et de rien d'autre : ni d'une variable
     * d'environnement, ni d'une constante (AD-14).
     */
    #[Test]
    public function the_active_languages_are_read_from_the_table(): void
    {
        self::bootKernel();

        /** @var ActiveLocales $active */
        $active = self::getContainer()->get(ActiveLocales::class);

        self::assertSame(SupportedLocale::cases(), $active->all());

        self::setEnabled(false, SupportedLocale::Nl);
        self::ensureKernelShutdown();
        self::bootKernel();

        /** @var ActiveLocales $reread */
        $reread = self::getContainer()->get(ActiveLocales::class);

        self::assertSame(
            [SupportedLocale::Fr, SupportedLocale::En],
            $reread->all(),
            'Désactiver une ligne de la table ne retire pas la langue de l\'offre : les langues actives ne viennent donc pas de la base.',
        );
    }

    /**
     * AD-6 : aucun chemin d'URL ne gagne de préfixe de langue. Changer de langue ne
     * change pas l'URL — c'est ce qui permet de partager un lien sans transporter la
     * langue de celui qui l'a copié.
     */
    #[Test]
    public function changing_the_language_does_not_change_the_path(): void
    {
        $client = self::createClient();

        $client->request('GET', '/?lang=nl');

        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_home');
        self::assertSame('/', self::requestOf($client)->getPathInfo(), 'Le chemin a changé avec la langue : AD-6 refuse tout préfixe de locale.');
    }

    #[Test]
    public function no_route_carries_a_locale_prefix(): void
    {
        self::bootKernel();

        $router = self::getContainer()->get(RouterInterface::class);

        $offences = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_contains($route->getPath(), '{_locale}')) {
                $offences[] = \sprintf('%s (%s)', $route->getPath(), $name);
            }
        }

        self::assertSame([], $offences, 'Une route porte un préfixe de locale — AD-6 dit que les chemins sont invariables.');
    }

    /**
     * Un seul écouteur pose la locale, et il passe **avant** celui de Symfony.
     *
     * Priorité supérieure veut dire « s'exécute plus tôt ». Celui de Symfony n'écrit
     * ensuite que s'il trouve un attribut de route `_locale` ou si la négociation
     * `Accept-Language` est activée — deux choses que ce socle n'a pas. L'ordre reste
     * affirmé ici parce qu'il redeviendrait décisif le jour où l'une des deux
     * apparaîtrait.
     */
    #[Test]
    public function a_single_listener_sets_the_locale_and_it_runs_before_the_one_of_symfony(): void
    {
        self::bootKernel();

        $dispatcher = self::getContainer()->get('event_dispatcher');

        $ours = [];
        $symfony = null;

        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            // Un écouteur enregistré comme service arrive sous la forme `[objet,
            // méthode]` ; les closures et les fonctions nommées ne nous intéressent pas.
            if (!\is_array($listener) || !\is_callable($listener) || !\is_object($listener[0])) {
                continue;
            }

            $priority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, $listener);

            if ($listener[0] instanceof LocaleListener) {
                $ours[] = $priority;
            }

            if ($listener[0] instanceof SymfonyLocaleListener && 'onKernelRequest' === $listener[1]) {
                $symfony = $priority;
            }
        }

        self::assertCount(1, $ours, 'Le socle doit enregistrer exactement un écouteur de locale sur `kernel.request`.');
        self::assertSame(20, $ours[0], 'L\'écouteur de locale du socle n\'est plus à la priorité 20.');
        self::assertIsInt($symfony, 'Le `LocaleListener` de Symfony n\'écoute plus `kernel.request` : la comparaison de priorité ne veut plus rien dire.');
        self::assertGreaterThan($symfony, $ours[0], 'L\'écouteur du socle ne passe plus avant celui de Symfony : la locale qu\'il pose n\'est plus celle que le reste de la requête voit en premier.');
    }

    /**
     * « Aucun autre point du socle n'appelle `setLocale()` » — la règle, mesurée sur les
     * sources plutôt que promise en commentaire. Deux autorités sur la locale, c'est une
     * page dont la langue dépend de l'ordre des écouteurs.
     */
    #[Test]
    public function nothing_else_in_the_socle_sets_a_locale(): void
    {
        $offences = [];

        // L'exemption est nominative : exempter le répertoire laisserait un second
        // écouteur posé à côté échapper à la règle que ce test existe pour tenir.
        $files = new Finder()
            ->files()
            ->in(GateFiles::projectDir().'/src')
            ->name('*.php')
            ->notName('LocaleListener.php');

        foreach ($files as $file) {
            if (str_contains($file->getContents(), '->setLocale(')) {
                $offences[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        self::assertSame([], $offences, 'Ce fichier appelle `setLocale()` en dehors de l\'écouteur : la locale de la requête n\'a plus une seule autorité.');
    }

    // ------------------------------------------------------------------

    /**
     * La requête **Symfony** de la dernière navigation — celle que le noyau a traitée, et
     * donc celle que l'écouteur a modifiée.
     */
    private static function requestOf(KernelBrowser $client): Request
    {
        return $client->getRequest();
    }

    private static function localeOf(KernelBrowser $client): string
    {
        return self::requestOf($client)->getLocale();
    }

    private static function renderedLanguage(KernelBrowser $client): string
    {
        return $client->getCrawler()->filter('html')->attr('lang') ?? '';
    }

    private static function setEnabled(bool $enabled, SupportedLocale ...$codes): void
    {
        $container = self::getContainer();

        /** @var LanguageRepository $languages */
        $languages = $container->get(LanguageRepository::class);

        $entityManager = $container->get(EntityManagerInterface::class);

        foreach ($codes as $code) {
            $language = $languages->findOneBy(['code' => $code]);

            self::assertInstanceOf(Language::class, $language, \sprintf('La langue « %s » n\'est pas en base : la migration de semis ne l\'a pas insérée.', $code->value));

            $language->setEnabled($enabled);
        }

        $entityManager->flush();
    }
}
