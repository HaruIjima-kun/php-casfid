<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/public')
    ->name('*.php')
    ->notName('*.blade.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'concat_space' => ['spacing' => 'one'],
        'native_function_invocation' => ['include' => ['@all']],
        'no_superfluous_phpdoc_tags' => false,
    ])
    ->setFinder($finder);
