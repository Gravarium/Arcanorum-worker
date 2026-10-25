<?php

declare(strict_types=1);

namespace App\Module\Advertising\Domain;

final readonly class SafeAssetPath
{
    private function __construct(public string $path)
    {
    }

    public static function fromString(string $path): self
    {
        if (strlen($path) > 1024
            || 1 !== preg_match('/\A\/(?!\/)[A-Za-z0-9._~\/-]+\z/D', $path)
            || str_contains($path, '..')
            || str_contains($path, '//')
            || !str_starts_with($path, '/media/')
        ) {
            throw new \InvalidArgumentException('Advertising assets must use a safe same-origin path.');
        }

        return new self($path);
    }
}
