<?php

declare(strict_types=1);

namespace App\Core\Dto\Input;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Les deux saisies de la page de réinitialisation.
 *
 * **La robustesse se vérifie hors ligne** (décision D-2). `PasswordStrength` calcule un
 * score d'entropie localement, sans réseau et sans service tiers ; le seuil retenu est
 * `STRENGTH_MEDIUM` et non `STRENGTH_STRONG`, parce que `STRONG` refuse des phrases de
 * passe que la plupart des gens jugent raisonnables — et un refus incompréhensible sur une
 * page de récupération d'accès renvoie l'utilisateur vers son administrateur, c'est-à-dire
 * exactement ce que cette story existe pour éviter.
 *
 * **Le message de robustesse est le nôtre pour la même raison.** Le défaut du composant —
 * « La force du mot de passe est trop faible. Veuillez utiliser un mot de passe plus
 * fort. » — énonce un verdict sans son critère, et le raisonnement ci-dessus ne tient pas
 * si le refus reste indevinable. Il l'est d'autant plus que le score de Symfony
 * (`unique × log₂(pool) + (longueur − unique) × log₂(unique)`, seuil à 80 bits) dépend
 * massivement de la **longueur** et presque pas de la complexité : douze caractères mêlant
 * majuscules, chiffres et symboles sont refusés, seize minuscules liées par des tirets
 * passent. Personne ne devine cela. La clé `password.too_weak` dit donc le critère, et
 * `templates/password/reset.html.twig` l'affiche **avant** la saisie plutôt qu'après
 * l'échec.
 *
 * `NotCompromisedPassword` est **écarté**, pas oublié : il ferait dépendre cette page d'un
 * service tiers et ajouterait `symfony/http-client` au socle. L'écart au durcissement du
 * skill sécurité est consigné dans `deferred-work.md` avec son déclencheur — le jour où le
 * socle aura déjà un client HTTP pour autre chose, la contrainte coûte une ligne.
 *
 * **Le message d'égalité est le nôtre, et il n'a pas de paramètre.** Le message par défaut
 * d'`EqualTo` est « Cette valeur doit être identique à {{ compared_value }} » : rendu tel
 * quel, il réémettrait le mot de passe saisi dans le HTML de la page d'erreur, donc dans
 * l'historique du navigateur et dans les caches intermédiaires. La clé vit dans
 * `translations/validators.{fr,en,nl}.yaml`, le domaine que le validateur consulte.
 *
 * **La borne haute n'est pas un confort : sans elle, la page rend une 500.** Le hacheur
 * refuse tout mot de passe de plus de `PasswordHasherInterface::MAX_PASSWORD_LENGTH`
 * octets (`CheckPasswordLengthTrait`) en levant une `InvalidPasswordException`, que rien
 * ne rattrape sur ce chemin. Et `PasswordStrength` ne la couvre pas du tout : plus une
 * chaîne est longue, plus son entropie est haute. La contrainte lit donc la constante du
 * composant plutôt qu'un nombre recopié — le jour où elle bouge, la validation suit.
 *
 * `countUnit: COUNT_BYTES` pour la même raison : le hacheur compte des **octets**
 * (`\strlen()`), et le défaut de `Length` compte des points de code. Sans cette option,
 * 4 096 caractères accentués — donc plus de 4 096 octets — passeraient la validation et
 * feraient lever le hacheur juste après.
 */
final readonly class PasswordResetInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: PasswordHasherInterface::MAX_PASSWORD_LENGTH, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: 'password.too_weak')]
        public string $password = '',
        /**
         * La contrainte est portée par la **confirmation** et non par le mot de passe :
         * c'est sur ce champ-là que le message doit apparaître, et c'est celui-là que
         * l'utilisateur doit corriger.
         */
        #[Assert\EqualTo(propertyPath: 'password', message: 'password.confirmation_mismatch')]
        public string $confirmation = '',
    ) {
    }
}
