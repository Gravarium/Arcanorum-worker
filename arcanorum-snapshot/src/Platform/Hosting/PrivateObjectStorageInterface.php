<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

interface PrivateObjectStorageInterface
{
    public function write(string $objectId, string $contents): void;

    public function read(string $objectId): string;

    public function delete(string $objectId): void;
}
