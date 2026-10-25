<?php

declare(strict_types=1);

namespace App\Service;

final class NativePhpRuntimeInspector implements PhpRuntimeInspectorInterface
{
    public function version(): string
    {
        return PHP_VERSION;
    }

    public function sapi(): string
    {
        return PHP_SAPI;
    }

    public function iniValue(string $name): ?string
    {
        $value = ini_get($name);

        return false === $value ? null : $value;
    }

    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }
}
