<?php

declare(strict_types=1);

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

/** @param array<string, mixed> $context */
return static function (array $context): Kernel {
    return new Kernel((string) $context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
