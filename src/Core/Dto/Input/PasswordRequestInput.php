<?php

declare(strict_types=1);

namespace App\Core\Dto\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ce que le formulaire « Mot de passe oublié ? » envoie : une adresse, et rien d'autre.
 *
 * **Le premier DTO d'entrée du socle** (décision D-1). `symfony/form` n'entre pas : le
 * contrôleur hydrate cet objet depuis la requête, appelle `ValidatorInterface`, et rerend
 * la même page en 422 avec les erreurs. C'est le précédent que les formulaires des Epics 2
 * à 6 copieront.
 *
 * **Les contraintes ne portent aucun message à nous**, et c'est ce qui rend la
 * non-divulgation tenable : les messages du composant sont déjà traduits dans les trois
 * langues du socle, et aucun d'eux ne parle d'un compte. Une erreur de **format** est la
 * seule chose que cette page puisse dire — « cette adresse n'existe pas » serait
 * exactement la fuite que la story existe pour fermer.
 */
final readonly class PasswordRequestInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email = '',
    ) {
    }
}
