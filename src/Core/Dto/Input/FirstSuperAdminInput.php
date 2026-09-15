<?php

declare(strict_types=1);

namespace App\Core\Dto\Input;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Les deux saisies de `app:init` : l'adresse du premier Super admin, et son mot de passe.
 *
 * **Pas de contrôle de robustesse ici, et c'est une décision, pas un oubli**
 * (Fabrice, 2026-09-15). `App\Core\Dto\Input\PasswordResetInput` — le jumeau HTTP — refuse
 * tout ce qui est sous 80 bits d'entropie ; ce DTO-ci ne mesure rien. Le seul appelant de
 * ce chemin est la personne qui installe le dérivé, devant son propre terminal, et un
 * refus incompréhensible pendant une installation n'a pas la page de secours qu'a la
 * réinitialisation. Le triplet de la story 1.9 n'est donc **ni extrait ni dupliqué** :
 * aucun `Validator/` n'est créé, et `PasswordResetInput` n'est pas touché.
 *
 * Qui relira ce fichier en cherchant `PasswordStrength` doit trouver ce paragraphe plutôt
 * qu'un trou — c'est exactement ce qu'une première relecture « corrigerait » en croyant
 * bien faire.
 *
 * **La borne haute, elle, n'est pas une politique : c'est une protection.** Le hacheur
 * refuse tout mot de passe de plus de `PasswordHasherInterface::MAX_PASSWORD_LENGTH`
 * octets (`CheckPasswordLengthTrait`) en levant une `InvalidPasswordException` que rien
 * n'attrape sur le chemin console — la commande rendrait une trace au lieu de reposer la
 * question. La contrainte lit la constante du composant plutôt qu'un nombre recopié.
 *
 * `countUnit: COUNT_BYTES` pour la même raison que sur le jumeau HTTP : le hacheur compte
 * des **octets** (`\strlen()`), là où le défaut de `Length` compte des points de code.
 * Sans cette option, une phrase de passe accentuée de 4 096 caractères passerait la
 * validation et ferait lever le hacheur juste après.
 *
 * **Les deux bornes hautes ne comptent pas la même chose, et c'est voulu** : l'adresse en
 * points de code, parce qu'elle doit tenir dans un `varchar(180)` ; le mot de passe en
 * octets, parce que c'est ainsi que le hacheur compte.
 *
 * **La confirmation n'est pas ici.** Elle n'est pas une propriété de ce qu'on crée : c'est
 * une question posée deux fois à un terminal, et `App\Core\Command\InitCommand` la
 * compare avant de construire ce DTO. Le jumeau HTTP la porte parce qu'un formulaire doit
 * pouvoir marquer *quel champ* corriger ; une console n'a pas de champ à marquer.
 */
final readonly class FirstSuperAdminInput
{
    /**
     * La longueur de la colonne `email` de `App\Core\Entity\User`
     * (`#[ORM\Column(length: 180, unique: true)]`).
     *
     * **Recopiée, et non lue sur l'entité** : `deptrac.yaml` n'accorde à la couche `Dto` que
     * `Dto`, `Enum` et les racines — référencer `App\Core\Entity\User` d'ici serait une
     * violation du contrat de couches. `FirstSuperAdminInputTest` confronte donc ce nombre
     * au mapping de l'entité par réflexion, pour que les deux ne puissent pas diverger en
     * silence.
     *
     * Sans cette borne, une adresse bien formée de 200 caractères passe la validation,
     * passe le contrôle d'unicité du service, et fait lever « Data too long for column » à
     * `flush()` — donc une trace et une sortie en 255 sur la commande d'installation,
     * exactement le mode de panne que la borne du mot de passe ci-dessous existe pour
     * fermer.
     */
    public const int EMAIL_MAX_LENGTH = 180;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        /**
         * En points de code, contrairement au mot de passe : une colonne `varchar(180)` en
         * `utf8mb4` compte des **caractères**, là où le hacheur compte des octets.
         */
        #[Assert\Length(max: self::EMAIL_MAX_LENGTH)]
        public string $email = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: PasswordHasherInterface::MAX_PASSWORD_LENGTH, countUnit: Assert\Length::COUNT_BYTES)]
        public string $password = '',
    ) {
    }
}
