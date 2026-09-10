<?php

declare(strict_types=1);

namespace App\Core\Contract;

/**
 * Ce qu'un module dit de lui-même au socle.
 *
 * `src/Core/Contract/` est la seule porte que voit un module (AD-2) : le socle connaît
 * ce contrat, jamais la classe qui l'implémente. Rien n'entre ici — ni Doctrine, ni
 * HTTP, ni même un attribut de conteneur : c'est `config/services.yaml` qui pose le tag
 * `app.module` sur toute classe implémentant cette interface, une fois pour toutes.
 *
 * Implémenter cette interface est donc tout ce qu'un module a à faire pour se déclarer
 * (AD-13). Il n'y a aucun fichier du socle à modifier pour l'accueillir.
 */
interface ModuleDescriptor
{
    /**
     * Nom du module, singulier anglais en PascalCase — `Billing`, `Stock`.
     */
    public function name(): string;

    /**
     * Domaine de traduction que le module livre avec ses catalogues.
     */
    public function translationDomain(): string;
}
