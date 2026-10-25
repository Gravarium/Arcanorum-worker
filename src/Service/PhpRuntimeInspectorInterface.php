<?php

declare(strict_types=1);

namespace App\Service;

interface PhpRuntimeInspectorInterface
{
    public function version(): string;

    public function sapi(): string;

    public function iniValue(string $name): ?string;

    public function extensionLoaded(string $name): bool;
}
