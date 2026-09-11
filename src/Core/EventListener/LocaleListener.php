<?php

declare(strict_types=1);

namespace App\Core\EventListener;

use App\Core\Enum\SupportedLocale;
use App\Core\Service\ActiveLocales;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * La seule autorité sur la locale d'une requête.
 *
 * La chaîne est **explicite et fermée**, et elle se lit dans cet ordre :
 *
 *   1. `?lang=`, validé contre les langues **actives** — et mémorisé en session ;
 *   2. la session ;
 *   3. la première langue active, ou la langue de repli s'il n'y en a aucune.
 *
 * Ni `Accept-Language`, ni négociation de contenu : un en-tête envoyé par le navigateur
 * n'est pas un choix, et une interface qui change de langue selon la machine est une
 * interface dont personne ne sait pourquoi elle a changé. Aucun préfixe de langue dans
 * l'URL non plus (AD-6) : le chemin ne dépend pas de la langue.
 *
 * **La couture annoncée par la story 1.5 n'a pas été posée, et elle ne le sera pas.** Un
 * quatrième barreau « langue du compte connecté » est infaisable à cette priorité : ce
 * listener tourne à **20**, le pare-feu de Symfony à **8** — il n'y a pas encore de token,
 * donc `getUser()` rendrait `null` sur chaque requête. Descendre sous 8 déplacerait le
 * problème sur le translator, que `LocaleAwareListener` synchronise à la priorité **15** :
 * la locale serait posée trop tard pour être traduite.
 *
 * La story 1.6 a donc fait l'inverse : `App\Core\EventListener\LoginLocaleListener` écrit
 * la langue du compte **en session**, sous `SESSION_KEY`, au moment de la connexion
 * réussie. La chaîne ci-dessous n'a pas bougé, et le comportement promis par l'UX —
 * « après connexion, la langue du profil prend le relais » — est tenu par le barreau 2.
 *
 * **Priorité 20**, donc avant le `LocaleListener` de Symfony (16) et après le routeur
 * (32). Celui de Symfony n'écrit ensuite que s'il trouve un attribut de route `_locale`
 * ou si la négociation `Accept-Language` est activée : ni l'un ni l'autre n'existe ici,
 * et c'est pour cela qu'une seule autorité suffit.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
final readonly class LocaleListener
{
    /**
     * Au-dessus du `LocaleListener` de Symfony, sous le routeur.
     */
    public const int PRIORITY = 20;

    /**
     * La clé de session qui porte la langue retenue.
     *
     * **Elle n'est jamais écrite par un repli**, et c'est l'invariant qui compte : la
     * session garde le choix d'origine même quand la langue vient d'être désactivée, si
     * bien qu'un utilisateur la retrouve le jour où un administrateur la réactive.
     *
     * Deux écritures existent, et deux seulement : un `?lang=` valide et actif, ci-dessous
     * ; la langue du compte, écrite à la connexion réussie par
     * `App\Core\EventListener\LoginLocaleListener`. La seconde écrase la première, ce qui
     * *est* le comportement promis — « après connexion, la langue du profil prend le
     * relais ».
     */
    public const string SESSION_KEY = '_locale';

    public function __construct(private ActiveLocales $active)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $request->setLocale($this->resolve($request)->value);
    }

    private function resolve(Request $request): SupportedLocale
    {
        $chosen = $this->offered($this->requested($request));

        if (null !== $chosen) {
            // Le seul moment où la session est écrite. Lire la session plus haut
            // suffirait à la démarrer sur chaque requête anonyme ; l'écrire ici ne la
            // démarre que quand quelqu'un a réellement choisi.
            $request->getSession()->set(self::SESSION_KEY, $chosen->value);

            return $chosen;
        }

        // Pas de barreau « langue du compte » ici : elle est écrite en session à la
        // connexion, et c'est donc le barreau ci-dessous qui la sert. Voir le docblock
        // de cette classe pour la raison — elle tient à deux priorités d'écouteurs.

        $remembered = $this->offered($this->remembered($request));

        // Le repli est calculé, pas écrit : la session garde le choix d'origine même
        // quand la langue vient d'être désactivée.
        return $remembered ?? $this->active->first();
    }

    /**
     * Une langue proposée par la requête devient une langue résolue seulement si elle est
     * supportée **et** active. Sinon la résolution continue comme si rien n'avait été
     * demandé : aucune erreur, aucune redirection.
     */
    private function offered(?string $code): ?SupportedLocale
    {
        if (null === $code || '' === $code) {
            return null;
        }

        $locale = SupportedLocale::tryFrom($code);

        return null !== $locale && $this->active->has($locale) ? $locale : null;
    }

    /**
     * La valeur brute de `?lang=`, et non `query->getString()` : celui-ci lève une
     * `BadRequestException` — donc une 400 — dès que le paramètre n'est pas scalaire,
     * comme dans `?lang[]=nl`. La matrice d'edge cases promet l'inverse : un `?lang=`
     * inutilisable est ignoré, sans erreur ni redirection. Tout ce qui n'est pas une
     * chaîne est donc traité comme absent, exactement comme la session l'est ci-dessous.
     */
    private function requested(Request $request): ?string
    {
        $requested = $request->query->all()['lang'] ?? null;

        return \is_string($requested) ? $requested : null;
    }

    /**
     * `hasPreviousSession()` et non `getSession()` : lire la session sur une requête qui
     * n'en porte pas encore la démarrerait, et poserait un cookie de session à chaque
     * visite anonyme.
     */
    private function remembered(Request $request): ?string
    {
        if (!$request->hasPreviousSession()) {
            return null;
        }

        $remembered = $request->getSession()->get(self::SESSION_KEY);

        return \is_string($remembered) ? $remembered : null;
    }
}
