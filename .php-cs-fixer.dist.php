<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(array_filter([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/public',
        __DIR__ . '/bin',
    ], 'is_dir'));

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
