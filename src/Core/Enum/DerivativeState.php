<?php

declare(strict_types=1);

namespace App\Core\Enum;

/**
 * Dans quel état est ce dérivé, vu depuis `app:init`.
 *
 * **Trois valeurs, et elles seules.** C'est le seul vocabulaire partagé entre
 * `App\Core\Service\DerivativeInitializer`, qui décide de l'état, et
 * `App\Core\Command\InitCommand`, qui enchaîne ce qui manque. La commande ne prend qu'une
 * décision — un `match` sur cet enum — et l'état lui est rendu tout fait.
 *
 * **Pourquoi trois et pas quatre.** Distinguer « la base n'existe pas » de « la base
 * existe mais son schéma est absent » obligerait la couche de règle à lire une exception
 * DBAL, ce que `deptrac.yaml` interdit à un service. Et cela n'achèterait rien :
 * `doctrine:database:create --if-not-exists` est déjà un no-op sur une base existante. Les
 * deux cas fusionnent donc en `Uninitialized`, et la commande rapporte ce que Doctrine lui
 * répond plutôt que de le prédire.
 *
 * Adossé à une chaîne pour que l'état puisse être journalisé et relu tel quel ; aucune
 * de ces valeurs n'est affichée à l'utilisateur — la mise en forme appartient à la
 * commande.
 */
enum DerivativeState: string
{
    /**
     * La table des comptes n'est pas atteignable : base absente, ou base présente sans
     * schéma. C'est le seul état sur lequel `app:init` touche au schéma.
     */
    case Uninitialized = 'uninitialized';

    /** Le schéma est en place et aucun compte n'existe : le cas nominal d'un clone frais. */
    case Empty = 'empty';

    /** Au moins un compte existe — actif ou non. « Base déjà peuplée » commence ici. */
    case Initialized = 'initialized';
}
