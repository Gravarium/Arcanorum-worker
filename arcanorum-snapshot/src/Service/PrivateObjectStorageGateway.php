<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Hosting\PrivateFileStorage;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;

final readonly class PrivateObjectStorageGateway implements PrivateObjectStorageInterface
{
    public function __construct(
        private readonly PrivateFileStorage $storage,
        private readonly PrivateObjectStorageSelection $selection,
    ) {
    }

    public function write(string $objectId, string $contents): void
    {
        $this->requireEnabled();
        $this->storage->write($objectId, $contents);
    }

    public function read(string $objectId, int $maximumBytes): string
    {
        $this->requireEnabled();

        return $this->storage->read($objectId, $maximumBytes);
    }

    public function delete(string $objectId): void
    {
        $this->requireEnabled();
        $this->storage->delete($objectId);
    }

    private function requireEnabled(): void
    {
        if (!$this->selection->isEnabled()) {
            throw new PrivateStorageOperationFailed();
        }
    }
}
