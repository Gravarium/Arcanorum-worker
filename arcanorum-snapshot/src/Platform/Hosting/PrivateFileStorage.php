<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final readonly class PrivateFileStorage implements PrivateObjectStorageInterface, HostingAdapterDefinitionInterface
{
    public function __construct(
        private string $publicDirectory,
        private string $storageDirectory,
        private CapabilityProbeInterface $storageCapabilityProbe,
        private HostingCapabilityGuard $capabilityGuard,
        private int $maximumObjectBytes = 67108864,
    ) {
        if ($maximumObjectBytes < 1) {
            throw new \InvalidArgumentException('Die maximale Objektgröße muss positiv sein.');
        }
    }

    public function identifier(): string
    {
        return 'storage.private_files';
    }

    public function service(): HostingService
    {
        return HostingService::Storage;
    }

    /**
     * @return list<HostingCapability>
     */
    public function requiredCapabilities(): array
    {
        return [HostingCapability::PrivateStorage];
    }

    public function priority(): int
    {
        return 100;
    }

    public function isFallback(): bool
    {
        return false;
    }

    public function write(string $objectId, string $contents): void
    {
        $this->requirePrivateStorageCapability();

        if (strlen($contents) > $this->maximumObjectBytes) {
            throw new PrivateStorageOperationFailed();
        }

        $root = $this->privateRoot();
        $target = $this->pathForObjectId($root, $objectId);
        if (is_link($target)) {
            throw new PrivateStorageOperationFailed();
        }

        $temporary = $root.DIRECTORY_SEPARATOR.'.write-'.bin2hex(random_bytes(16));
        $handle = @fopen($temporary, 'x+b');
        if (false === $handle) {
            throw new PrivateStorageOperationFailed();
        }

        try {
            if (!@chmod($temporary, 0600)) {
                throw new PrivateStorageOperationFailed();
            }

            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = @fwrite($handle, substr($contents, $offset));
                if (false === $written) {
                    throw new PrivateStorageOperationFailed();
                }

                if (0 === $written) {
                    throw new PrivateStorageOperationFailed();
                }

                $offset += $written;
            }

            if (!@fflush($handle)) {
                throw new PrivateStorageOperationFailed();
            }

            if (!@fclose($handle)) {
                throw new PrivateStorageOperationFailed();
            }

            // Das atomare Umbenennen ersetzt den Zieleintrag, ohne einem Symlink-Ziel zu folgen.
            if (!@rename($temporary, $target)) {
                throw new PrivateStorageOperationFailed();
            }
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }

            if (file_exists($temporary)) {
                @unlink($temporary);
            } elseif (is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function read(string $objectId, int $maximumBytes): string
    {
        if ($maximumBytes < 1 || $maximumBytes > $this->maximumObjectBytes) {
            throw new PrivateStorageOperationFailed();
        }

        $this->requirePrivateStorageCapability();

        $root = $this->privateRoot();
        $path = $this->pathForObjectId($root, $objectId);
        if (!file_exists($path) || is_link($path)) {
            throw new PrivateStorageOperationFailed();
        }

        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            throw new PrivateStorageOperationFailed();
        }

        try {
            $openedStat = fstat($handle);
            $pathStat = @lstat($path);
            if (false === $openedStat) {
                throw new PrivateStorageOperationFailed();
            }

            if (false === $pathStat) {
                throw new PrivateStorageOperationFailed();
            }

            if (!$this->isSamePrivateRegularFile($openedStat, $pathStat)) {
                throw new PrivateStorageOperationFailed();
            }

            if (0 !== ($openedStat['mode'] & 0077)) {
                throw new PrivateStorageOperationFailed();
            }

            if ($openedStat['size'] > $maximumBytes) {
                throw new PrivateStorageOperationFailed();
            }

            $contents = @stream_get_contents($handle, $maximumBytes + 1);
            if (false === $contents) {
                throw new PrivateStorageOperationFailed();
            }

            if (strlen($contents) > $maximumBytes) {
                throw new PrivateStorageOperationFailed();
            }

            return $contents;
        } finally {
            @fclose($handle);
        }
    }

    public function delete(string $objectId): void
    {
        $this->requirePrivateStorageCapability();

        $root = $this->privateRoot();
        $path = $this->pathForObjectId($root, $objectId);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        $pathStat = @lstat($path);
        if (false === $pathStat) {
            return;
        }

        if (!$this->isSamePrivateRegularFile($pathStat, $pathStat)) {
            throw new PrivateStorageOperationFailed();
        }

        if (0 !== ($pathStat['mode'] & 0077)) {
            throw new PrivateStorageOperationFailed();
        }

        if (!@unlink($path)) {
            throw new PrivateStorageOperationFailed();
        }
    }

    private function requirePrivateStorageCapability(): void
    {
        try {
            $evidence = null;
            foreach ($this->storageCapabilityProbe->probe() as $candidate) {
                if (null !== $evidence || HostingCapability::PrivateStorage !== $candidate->capability) {
                    throw new PrivateStorageOperationFailed();
                }

                $evidence = $candidate;
            }

            if (null === $evidence) {
                throw new PrivateStorageOperationFailed();
            }

            $this->capabilityGuard->requireVerifiedEvidence(
                $evidence,
                HostingCapability::PrivateStorage,
            );
        } catch (HostingCapabilityUnavailable) {
            throw new PrivateStorageOperationFailed();
        }
    }

    private function privateRoot(): string
    {
        if (is_link($this->storageDirectory)) {
            throw new PrivateStorageOperationFailed();
        }

        $publicRoot = realpath($this->publicDirectory);
        $storageRoot = realpath($this->storageDirectory);
        if (false === $publicRoot) {
            throw new PrivateStorageOperationFailed();
        }

        if (false === $storageRoot) {
            throw new PrivateStorageOperationFailed();
        }

        if (!is_dir($publicRoot)) {
            throw new PrivateStorageOperationFailed();
        }

        if (!is_dir($storageRoot)) {
            throw new PrivateStorageOperationFailed();
        }

        if ($this->isWithin($storageRoot, $publicRoot)) {
            throw new PrivateStorageOperationFailed();
        }

        $storagePermissions = fileperms($storageRoot);
        $parentRoot = realpath(dirname($storageRoot));
        $parentPermissions = false === $parentRoot ? false : fileperms($parentRoot);
        if (false === $storagePermissions) {
            throw new PrivateStorageOperationFailed();
        }

        if (false === $parentPermissions) {
            throw new PrivateStorageOperationFailed();
        }

        if (0 !== ($storagePermissions & 0077)) {
            throw new PrivateStorageOperationFailed();
        }

        if (0 !== ($parentPermissions & 0022)) {
            throw new PrivateStorageOperationFailed();
        }

        return $storageRoot;
    }

    private function pathForObjectId(string $root, string $objectId): string
    {
        if (1 !== preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $objectId)) {
            throw new PrivateStorageOperationFailed();
        }

        return $root.DIRECTORY_SEPARATOR.$objectId.'.bin';
    }

    /**
     * @param array<string|int, int>|false $openedStat
     * @param array<string|int, int>|false $pathStat
     */
    private function isSamePrivateRegularFile(array|false $openedStat, array|false $pathStat): bool
    {
        if (false === $openedStat) {
            return false;
        }

        if (false === $pathStat) {
            return false;
        }

        if (!isset($openedStat['mode'], $openedStat['dev'], $openedStat['ino'], $openedStat['size'])) {
            return false;
        }

        if (!isset($pathStat['mode'], $pathStat['dev'], $pathStat['ino'], $pathStat['size'])) {
            return false;
        }

        return 0100000 === ($openedStat['mode'] & 0170000)
            && 0100000 === ($pathStat['mode'] & 0170000)
            && $openedStat['dev'] === $pathStat['dev']
            && $openedStat['ino'] === $pathStat['ino'];
    }

    private function isWithin(string $path, string $root): bool
    {
        if ($path === $root) {
            return true;
        }

        $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR);
        if ('' === $normalizedRoot) {
            return str_starts_with($path, DIRECTORY_SEPARATOR);
        }

        return str_starts_with($path, $normalizedRoot.DIRECTORY_SEPARATOR);
    }
}
