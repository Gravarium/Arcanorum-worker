<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Hosting\HostingSecurityFeature;
use App\Platform\Hosting\HostingSecurityFeatureSelectionStoreInterface;
use App\Platform\Hosting\PrivateFileStorage;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;

final readonly class PrivateObjectStorageGateway implements PrivateObjectStorageInterface
{
    private bool $enabled;

    public function __construct(
        private PrivateFileStorage $storage,
        HostingSecurityFeatureSelectionStoreInterface $selectionStore,
    ) {
        try {
            $enabled = in_array(HostingSecurityFeature::PrivateStorage, $selectionStore->selectedFeatures(), true);
        } catch (\Throwable) {
            $enabled = false;
        }

        $this->enabled = $enabled;
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
        if (!$this->enabled) {
            throw new PrivateStorageOperationFailed();
        }
    }
}
