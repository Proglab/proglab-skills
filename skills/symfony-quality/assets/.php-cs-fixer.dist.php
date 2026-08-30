<?php

declare(strict_types=1);

/*
 * Code style. Copy to the project root and commit it.
 *
 *   make cs        # fix
 *   make cs-check  # verify only, this is what CI runs
 *
 * Style is not worth arguing about, which is exactly why it should be
 * decided once by a tool and never discussed again in review.
 */

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP82Migration' => true,

        // The standard requires strict types in every file.
        'declare_strict_types' => true,

        // Yoda conditions read badly; the rest of @Symfony is kept.
        'yoda_style' => false,

        // Keep imports tidy and deterministic.
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,

        // Trailing commas make diffs one line long instead of two.
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters', 'match'],
        ],

        // Modern PHP the standard expects anyway.
        'nullable_type_declaration_for_default_null_value' => true,
        'modernize_types_casting' => true,
        'void_return' => true,

        // PHPUnit: attributes only, never docblock annotations.
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'php_unit_method_casing' => false, // it_does_something() is intentional
    ])
    ->setFinder(
        (new PhpCsFixer\Finder())
            ->in([__DIR__.'/src', __DIR__.'/tests'])
            ->append([__FILE__])
    );
