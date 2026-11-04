<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

use Symfony\Contracts\Service\ResetInterface;

interface PrivateObjectStorageInterface extends ResetInterface
{
    public function write(string $objectId, string $contents): void;

    /**
     * Reads an object without returning more than the caller's validated metadata permits.
     * The required limit is checked before any object bytes are read.
     */
    public function read(string $objectId, int $maximumBytes): string;

    public function delete(string $objectId): void;
}
