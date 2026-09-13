<?php

declare(strict_types=1);

namespace App\Core\Enum;

/**
 * Les trois rôles que le socle livre — Super admin, Admin, User — dans leur ordre
 * d'affichage.
 *
 * **Ce n'est pas un rôle Symfony, et rien ici n'autorise quoi que ce soit.** AD-8
 * interdit à tout code de tester un nom de rôle : un rôle est une **donnée**, que
 * l'Epic 2 reliera à des permissions. Ce que cet enum apporte est une *prise stable* sur
 * les trois lignes que la migration sème — la commande d'initialisation de la story 1.11
 * doit pouvoir retrouver le Super admin, et « le Super admin ne se supprime pas » doit
 * pouvoir s'écrire, sans que ni l'une ni l'autre ne cherche sur un libellé qu'un
 * administrateur peut renommer.
 *
 * Les rôles qu'un administrateur créera à l'Epic 2 n'ont **pas** de code : la colonne est
 * nullable, et seuls ces trois-là en portent un.
 *
 * L'ordre des cas est l'ordre d'affichage — du plus large au plus étroit — et il est
 * aussi celui dans lequel la migration insère les lignes.
 */
enum CoreRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case User = 'user';
}
