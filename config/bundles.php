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
];
