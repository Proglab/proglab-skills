<?php

declare(strict_types=1);

namespace App\Core\Enum;

/**
 * À quoi sert un jeton de compte — la colonne qui rend l'entité partagée d'AD-15 lisible.
 *
 * **Pourquoi cette colonne existe alors qu'un seul cas est livré.** L'entité est le
 * mécanisme commun à la réinitialisation de mot de passe (story 1.9) et à l'invitation
 * (story 2.6) : sans objet explicite, l'invitation devrait deviner à quoi sert une ligne,
 * et les deux durées de vie — une heure ici, sept jours là-bas — ne seraient distinguables
 * que par l'écart entre `created_at` et `expires_at`. C'est-à-dire par une soustraction,
 * sur une donnée qu'un administrateur lira un jour dans un écran.
 *
 * **Ce que cet enum ne porte pas, volontairement : la durée de vie.** Elle appartient au
 * cas d'usage, et le cas d'usage de l'invitation n'est pas livré. L'écrire ici reviendrait
 * à livrer une moitié de l'Epic 2 sans le test qui la tiendrait —
 * `App\Core\Service\PasswordResetRequest::LIFETIME` porte la seule que cette story exerce.
 *
 * Les valeurs sont en `snake_case`, comme les codes de `CoreRole` : ce sont des données de
 * base, pas des identifiants PHP.
 */
enum AccountTokenPurpose: string
{
    case PasswordReset = 'password_reset';
    case Invitation = 'invitation';
}
