<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Hosting\PrivateFileStorage;
use App\Platform\Hosting\PrivateObjectStorageInterface;

final readonly class PrivateObjectStorageGateway implements PrivateObjectStorageInterface
{
    public function __construct(private PrivateFileStorage $storage)
    {
    }

    public function write(string $objectId, string $contents): void
    {
        $this->storage->write($objectId, $contents);
    }

    public function read(string $objectId, int $maximumBytes): string
    {
        return $this->storage->read($objectId, $maximumBytes);
    }

    public function delete(string $objectId): void
    {
        $this->storage->delete($objectId);
    }
}
