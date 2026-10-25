<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$paths = array_values(array_filter([
    __DIR__ . '/src',
    __DIR__ . '/public',
    __DIR__ . '/tests',
], 'is_dir'));

$finder = Finder::create()
    ->files()
    ->name('*.php')
    ->in($paths);

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@Symfony' => true,
    ])
    ->setFinder($finder);
