<?php

declare(strict_types=1);

/*
 * Style de code de la porte de qualité.
 *
 *   make cs        # corrige
 *   make cs-check  # vérifie seulement, c'est ce que la CI exécute
 *
 * Le style ne vaut pas la peine d'être débattu, ce qui est exactement pourquoi il est
 * décidé une fois par un outil et ne se rediscute jamais en revue.
 */

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,

        // Le jeu le plus élevé que php-cs-fixer 3.95 expose, et le socle tourne sur
        // PHP 8.5 (`composer.json` : `"php": ">=8.5"`). Le gabarit du skill s'arrête à
        // `@PHP82Migration` parce qu'il vise des projets plus anciens.
        '@PHP85Migration' => true,

        // Le standard exige le typage strict dans chaque fichier.
        'declare_strict_types' => true,

        // Les conditions Yoda se lisent mal ; le reste de @Symfony est conservé.
        'yoda_style' => false,

        // Garder les imports rangés et déterministes.
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,

        // Les virgules finales rendent les diffs longs d'une ligne au lieu de deux.
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters', 'match'],
        ],

        // PHP moderne que le standard attend de toute façon.
        'nullable_type_declaration_for_default_null_value' => true,
        'modernize_types_casting' => true,
        'void_return' => true,

        // PHPUnit : attributs uniquement, jamais d'annotations docblock.
        // `php_unit_test_annotation` vient de @Symfony:risky et appartient a l'ere des
        // annotations : en style `prefix` il renomme des methodes qui portent deja
        // #[Test], ce qui contredit `php_unit_method_casing => false` juste en dessous.
        'php_unit_test_annotation' => false,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'php_unit_method_casing' => false, // it_does_something() est intentionnel
    ])
    ->setFinder(
        new PhpCsFixer\Finder()
            ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/migrations'])
            ->append([__FILE__]),
    );
