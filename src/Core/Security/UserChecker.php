<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Le seul contrôle d'état de compte du socle : un compte désactivé n'entre pas.
 *
 * **`checkPreAuth` et non `checkPostAuth`, et c'est tout le sujet.** `checkPostAuth` ne se
 * déclenche qu'après une vérification **réussie** du mot de passe : le message « ce compte
 * est désactivé » deviendrait alors la preuve que le mot de passe était bon, et le refus
 * cesserait d'être identique dans les deux cas. `checkPreAuth` tombe avant — le
 * `UserCheckerListener` s'exécute sur `CheckPassportEvent` à la priorité 256, la
 * vérification des identifiants à 0.
 *
 * L'exception est une `CustomUserMessageAccountStatusException` et pas une
 * `DisabledException` : `AuthenticatorManager` remplace toute `AccountStatusException par
 * une `BadCredentialsException` générique — sauf celle-là. C'est le mécanisme natif par
 * lequel un message de statut est déclaré « sûr à montrer », et c'est ce qui rend le
 * message dédié possible sans écrire de handler.
 *
 * `checkPostAuth()` reste vide, et le restera tant qu'aucune règle ne dépend d'une
 * vérification réussie du mot de passe — un « mot de passe expiré » en serait une.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isEnabled()) {
            throw new CustomUserMessageAccountStatusException(LoginFailureMessage::ACCOUNT_DISABLED);
        }
    }

    /**
     * La signature recopie celle du framework, paramètre commenté compris : le `$token`
     * est passé par `UserCheckerListener` depuis Symfony 7.3 et deviendra obligatoire en
     * 8.0. Le déclarer ici reviendrait à le figer avant l'heure ; l'ignorer sans le dire
     * ferait croire à un oubli.
     */
    public function checkPostAuth(UserInterface $user /* , ?TokenInterface $token = null */): void
    {
    }
}
