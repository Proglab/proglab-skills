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
];
