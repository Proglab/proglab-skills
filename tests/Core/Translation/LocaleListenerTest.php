<?php

declare(strict_types=1);

namespace App\Tests\Core\Translation;

use App\Core\Enum\SupportedLocale;
use App\Core\EventListener\LocaleListener;
use App\Core\Repository\LanguageRepository;
use App\Core\Service\ActiveLocales;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * L'écouteur, sans noyau — pour la seule chose que le chemin HTTP ne sait pas observer.
 *
 * `LocaleResolutionTest` couvre toute la matrice d'edge cases sur de vraies requêtes.
 * Ce qu'il ne peut pas voir, c'est **si la session a été démarrée** : le
 * `SessionListener` de Symfony l'enregistre à `kernel.response`, et une session
 * enregistrée n'est plus « démarrée ». Une assertion posée après la réponse reste donc
 * verte quoi qu'il arrive — vérifié, et c'est pour ça que ce test-ci existe.
 *
 * L'enjeu est réel : lire la session suffit à la démarrer, et une session démarrée pose
 * un cookie. Un visiteur qui n'a rien choisi ne doit rien recevoir, sur aucune page
 * anonyme du socle — les pages d'erreur de la story 1.10 comprises. L'écouteur ne
 * consulte donc la session que si la requête en porte déjà une
 * (`hasPreviousSession()`), et ne l'écrit que sur un choix explicite.
 */
final class LocaleListenerTest extends TestCase
{
    #[Test]
    public function a_request_without_a_choice_does_not_start_the_session(): void
    {
        $session = new Session(new MockArraySessionStorage());

        $request = Request::create('/');
        $request->setSession($session);

        self::listener()(self::event($request));

        self::assertSame('fr', $request->getLocale());
        self::assertFalse(
            $session->isStarted(),
            'L\'écouteur a démarré la session alors que personne n\'a choisi de langue : chaque page anonyme poserait un cookie de session.',
        );
    }

    /**
     * Le miroir du précédent : un choix explicite, lui, mérite d'être retenu — et donc
     * d'ouvrir une session.
     */
    #[Test]
    public function an_explicit_choice_starts_the_session_and_writes_the_choice(): void
    {
        $session = new Session(new MockArraySessionStorage());

        $request = Request::create('/?lang=nl');
        $request->setSession($session);

        self::listener()(self::event($request));

        self::assertSame('nl', $request->getLocale());
        self::assertTrue($session->isStarted(), 'Un choix explicite doit ouvrir la session : il n\'y a nulle part ailleurs où le retenir.');
        self::assertSame('nl', $session->get(LocaleListener::SESSION_KEY));
    }

    /**
     * Une sous-requête n'est pas une navigation : c'est le même document. Poser la locale
     * à nouveau la ferait dépendre de l'ordre des fragments rendus.
     */
    #[Test]
    public function a_sub_request_is_left_alone(): void
    {
        $request = Request::create('/?lang=nl');
        $request->setLocale('en');

        self::listener()(self::event($request, HttpKernelInterface::SUB_REQUEST));

        self::assertSame('en', $request->getLocale());
    }

    private static function listener(): LocaleListener
    {
        $languages = self::createStub(LanguageRepository::class);
        $languages->method('findActiveCodes')->willReturn(SupportedLocale::cases());

        return new LocaleListener(new ActiveLocales($languages));
    }

    private static function event(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $type);
    }
}
