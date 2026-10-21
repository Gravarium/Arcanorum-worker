<?php

declare(strict_types=1);

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

/** @param array{APP_ENV: string, APP_DEBUG: bool|string|int} $context */
return static function (array $context): Kernel {
    $environment = $context['APP_ENV'] ?? 'prod';
    if (!is_string($environment) || '' === $environment) {
        throw new \InvalidArgumentException('A valid application environment is required.');
    }

    return new Kernel($environment, in_array($context['APP_DEBUG'] ?? false, [true, 1, '1'], true));
};
