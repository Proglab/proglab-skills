<?php

declare(strict_types=1);

namespace App\Core\Dto\Output;

/**
 * Un compte réduit à ce qui l'identifie : son identifiant et son adresse.
 *
 * **Le premier DTO de `Dto/Output/`, et il ferme une dette nommée.**
 * `App\Core\Service\DerivativeInitializer::createFirstSuperAdmin()` rendait l'entité `User`
 * à `App\Core\Command\InitCommand` pendant quinze stories — c'est-à-dire une entité
 * franchissant la frontière de la couche service, et une commande tenant un objet qu'elle
 * pouvait muter sans qu'aucune règle ne s'y oppose. La règle 4 du socle n'a pas d'exception
 * console : un service rend un contrat, et le contrat est cette classe.
 *
 * **Il est nommé d'après sa forme, pas d'après l'opération qui le produit** — le guide de
 * dérivation demande « un DTO Output par *forme* de données, pas par endpoint », et
 * `FirstSuperAdminOutput`, son premier nom, désignait l'appelant plutôt que le contenu.
 * Tout ce qui a besoin de désigner un compte sans l'exposer réutilise donc cette classe
 * telle quelle. **Ce qu'il ne faut pas faire, en revanche, c'est l'épaissir** : un compte
 * avec son rôle, son état et sa langue est une *autre* forme, et donc une autre classe.
 *
 * **Deux champs, et pas un de plus.** L'adresse, parce que le message de succès la relit
 * pour dire à l'opérateur avec quoi se connecter ; l'identifiant, parce que c'est lui qui
 * prouve que la ligne a été écrite et non seulement construite. Ce que le service écrit de
 * plus se relit par le repository, et c'est ce que fait `DerivativeInitializerTest`.
 *
 * Aucune méthode `fromEntity()` : la construction appartient au service qui possède le
 * `flush()`, parce que lui seul sait que l'identifiant est désormais non nul.
 */
final readonly class AccountOutput
{
    public function __construct(
        public int $id,
        public string $email,
    ) {
    }
}
