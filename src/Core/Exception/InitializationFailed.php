<?php

declare(strict_types=1);

namespace App\Core\Exception;

use RuntimeException;

/**
 * Les trois façons dont l'initialisation d'un dérivé s'arrête — le premier fichier de ce
 * dossier.
 *
 * **Aucun `#[WithHttpStatus]`, et ce n'est pas un oubli.** Ces échecs ne franchissent
 * jamais HTTP : le seul appelant de `App\Core\Service\DerivativeInitializer` est
 * `App\Core\Command\InitCommand`, qui les attrape et les traduit en code de sortie. Porter
 * un statut ici laisserait croire qu'un contrôleur pourrait les rencontrer, et ferait
 * rendre une page d'erreur sur un chemin qui n'a pas de page.
 *
 * **Trois constructeurs nommés plutôt qu'un message à relire.** La commande doit pouvoir
 * rapporter *pourquoi* elle s'arrête, et une convention de préfixe dans une chaîne n'est
 * ni typée ni traduisible en assertion de test. Chacun compose sa phrase ici, une fois,
 * et chacune nomme ce que l'opérateur doit faire ensuite — c'est la seule chose qu'il
 * voit.
 *
 * **Ce fichier ne connaît aucun enum, et c'est le contrat de couches qui l'impose.** Le
 * ruleset `Exception` de `deptrac.yaml` n'autorise que `Exception` et les racines : un
 * `CoreRole` en paramètre y échouerait. Le code du rôle arrive donc en `string`, et c'est
 * le service — qui, lui, voit `Enum` — qui le déplie.
 */
final class InitializationFailed extends RuntimeException
{
    /**
     * Le rôle du socle attendu est absent de la base.
     *
     * Le message nomme la migration qui le sème, parce que c'est elle qu'il faut jouer :
     * ni la commande ni le service ne recréent ces lignes — les migrations sont l'unique
     * source de vérité du schéma **et** de ces trois rôles.
     */
    public static function coreRoleMissing(string $code, string $migration): self
    {
        return new self(\sprintf(
            'Le rôle du socle « %s » est absent de la base. Il est semé par la migration %s : '
            .'joue « php bin/console doctrine:migrations:migrate » avant de relancer l\'initialisation.',
            $code,
            $migration,
        ));
    }

    /**
     * Une adresse ne peut porter qu'un compte — la colonne est unique, et laisser Doctrine
     * lever sa contrainte rendrait une trace au lieu d'une phrase.
     */
    public static function emailAlreadyTaken(string $email): self
    {
        return new self(\sprintf(
            'L\'adresse « %s » porte déjà un compte : aucun compte n\'a été créé. Choisis une autre adresse.',
            $email,
        ));
    }

    /**
     * Le défaut de la commande sur un dérivé en service.
     *
     * Le nombre de comptes est dans le message parce qu'il dit à l'opérateur *sur quoi* il
     * est tombé — une base de recette repeuplée, ou le dérivé de production. `--force` est
     * nommé parce que c'est l'issue de secours quand plus personne ne peut se connecter,
     * et elle ne doit pas être devinée.
     */
    public static function alreadyInitialized(int $accounts): self
    {
        return new self(\sprintf(
            'Ce dérivé porte déjà %d compte%s : rien n\'a été touché. '
            .'Relance avec « --force » pour créer malgré tout un Super admin supplémentaire.',
            $accounts,
            1 === $accounts ? '' : 's',
        ));
    }
}
