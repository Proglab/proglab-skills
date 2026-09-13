<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    // La recette de dama/doctrine-test-bundle vit dans recipes-contrib : Composer
    // affiche IGNORING au lieu de l'appliquer, donc cette ligne est à la main. Elle va
    // par paire avec le bloc <extensions> de phpunit.dist.xml — les deux ou aucune.
    // tests/Core/Quality/DatabaseIsolationTest.php refuse de les voir séparées.
    DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    Symfony\UX\Icons\UXIconsBundle::class => ['all' => true],
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfonycasts\TailwindBundle\SymfonycastsTailwindBundle::class => ['all' => true],
    // La recette de ce paquet vit dans `recipes-contrib`, que ce projet n'exécute pas
    // (`extra.symfony.allow-contrib: false`) : la ligne s'écrit donc à la main. Sans
    // elle, `tailwind_merge` n'existe pas et chaque composant du kit échoue au rendu.
    TalesFromADev\Twig\Extra\Tailwind\Bridge\Symfony\Bundle\TalesFromADevTwigExtraTailwindBundle::class => ['all' => true],
    Symfony\UX\Toolkit\UXToolkitBundle::class => ['dev' => true, 'test' => true],
    Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
    // Turbo Drive. Trois critères de la story 1.4 sont écrits en termes de navigation
    // Turbo — `data-turbo-permanent`, `connect()` rejoué à chaque remplacement de body,
    // focus après remplacement — et `symfony/ux-turbo` est dans la table Stack de
    // l'architecture (AR-25). Il n'ajoute que du confort : chaque chemin du socle
    // fonctionne en pleine page sans lui.
    Symfony\UX\Turbo\TurboBundle::class => ['all' => true],
    // Le premier magasin de journaux du dépôt (story 1.10). Sans lui, le service `logger`
    // n'existe pas, `ErrorListener::logException()` sort sans écrire une ligne, et une 500
    // en production ne laisse aucune trace nulle part. Aucun code applicatif ne s'ajoute :
    // le listener natif sait déjà router par canal et lire `#[WithLogLevel]`.
    //
    // `config/packages/monolog.yaml` est **réécrit à la main** par-dessus la recette Flex :
    // une configuration que personne ne relit ne tient le mot « canal » que par accident.
    // `tests/Core/Observability/ErrorLoggingTest.php` en vérifie la forme et l'effet.
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    // La sécurité du socle (story 1.6) : entité User, provider, pare-feu et hachage.
    // `config/packages/security.yaml` est écrit à la main — la recette de Flex pose un
    // provider `users_in_memory` et un pare-feu sans authenticator, qui ne laisse
    // entrer personne en silence.
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
];
