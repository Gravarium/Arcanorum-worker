<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final readonly class PrivateStorageProbe implements CapabilityProbeInterface
{
    public function __construct(
        private string $publicDirectory,
        private string $storageDirectory,
    ) {
    }

    /**
     * @return iterable<CapabilityEvidence>
     */
    public function probe(): iterable
    {
        yield $this->inspect();
    }

    private function inspect(): CapabilityEvidence
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $publicRoot = realpath($this->publicDirectory);

        if (false === $publicRoot) {
            return $this->evidence(
                CapabilityStatus::NotAvailable,
                'Der öffentliche Webpfad konnte nicht geprüft werden.',
                $checkedAt,
            );
        }

        if (!str_starts_with($this->storageDirectory, DIRECTORY_SEPARATOR)
            || $this->containsParentTraversal($this->storageDirectory)
            || $this->isWithin($this->normalizePath($this->storageDirectory), $publicRoot)
        ) {
            return $this->evidence(
                CapabilityStatus::Failed,
                'Der Speicherpfad liegt innerhalb des öffentlichen Webpfads oder ist nicht absolut.',
                $checkedAt,
            );
        }

        if ($this->hasUnsafePublicAncestor($this->storageDirectory, $publicRoot)) {
            return $this->evidence(
                CapabilityStatus::Failed,
                'Ein vorhandener Pfadbestandteil führt in den öffentlichen Webpfad.',
                $checkedAt,
            );
        }

        $resolvedStorage = realpath($this->storageDirectory);
        if (false !== $resolvedStorage && $this->isWithin($resolvedStorage, $publicRoot)) {
            return $this->evidence(
                CapabilityStatus::Failed,
                'Der Speicherpfad liegt innerhalb des öffentlichen Webpfads.',
                $checkedAt,
            );
        }

        $resolvedParent = realpath(dirname($this->storageDirectory));
        if (false !== $resolvedParent) {
            $candidateStorage = $resolvedParent.DIRECTORY_SEPARATOR.basename($this->storageDirectory);
            if ($this->isWithin($candidateStorage, $publicRoot)) {
                return $this->evidence(
                    CapabilityStatus::Failed,
                    'Der Speicherpfad liegt innerhalb des öffentlichen Webpfads.',
                    $checkedAt,
                );
            }
        }

        if (is_link($this->storageDirectory)) {
            return $this->evidence(
                CapabilityStatus::Failed,
                'Der private Speicher darf kein symbolischer Link sein.',
                $checkedAt,
            );
        }

        if (!is_dir($this->storageDirectory)
            && !@mkdir($this->storageDirectory, 0700, true)
            && !is_dir($this->storageDirectory)
        ) {
            return $this->evidence(
                CapabilityStatus::NotAvailable,
                'Der private Speicher kann nicht sicher angelegt werden.',
                $checkedAt,
            );
        }

        $storageRoot = realpath($this->storageDirectory);
        if (false === $storageRoot) {
            return $this->evidence(
                CapabilityStatus::NotAvailable,
                'Der private Speicherpfad konnte nicht aufgelöst werden.',
                $checkedAt,
            );
        }

        if ($this->isWithin($storageRoot, $publicRoot)) {
            return $this->evidence(
                CapabilityStatus::Failed,
                'Der Speicherpfad liegt innerhalb des öffentlichen Webpfads.',
                $checkedAt,
            );
        }

        $parentRoot = realpath(dirname($storageRoot));
        $storagePermissions = fileperms($storageRoot);
        $parentPermissions = false === $parentRoot ? false : fileperms($parentRoot);
        if (false === $storagePermissions || false === $parentPermissions) {
            return $this->evidence(
                CapabilityStatus::NotAvailable,
                'Die Dateirechte des privaten Speichers konnten nicht geprüft werden.',
                $checkedAt,
            );
        }

        if (0 !== ($storagePermissions & 0077) || 0 !== ($parentPermissions & 0022)) {
            return $this->evidence(
                CapabilityStatus::Failed,
                'Der private Speicher oder sein Elternverzeichnis ist für andere Nutzer zu weit freigegeben.',
                $checkedAt,
            );
        }

        $probeFile = $storageRoot.DIRECTORY_SEPARATOR.'.fortress-check-'.bin2hex(random_bytes(8));
        $handle = @fopen($probeFile, 'x+b');
        if (false === $handle) {
            return $this->evidence(
                CapabilityStatus::NotAvailable,
                'Der private Speicher ist nicht beschreibbar.',
                $checkedAt,
            );
        }

        $content = 'fortress-storage-check';
        $written = false;
        $flushed = false;
        $closed = false;
        $removed = false;

        try {
            $written = fwrite($handle, $content);
            $flushed = fflush($handle);
        } finally {
            $closed = fclose($handle);
            $removed = @unlink($probeFile);
        }

        if (false === $written || strlen($content) !== $written || !$flushed || !$closed || !$removed) {
            return $this->evidence(
                CapabilityStatus::NotAvailable,
                'Der Schreibtest im privaten Speicher war nicht erfolgreich.',
                $checkedAt,
            );
        }

        return $this->evidence(
            CapabilityStatus::Verified,
            'Der private Speicher liegt außerhalb des Webpfads, ist auf den Dienstnutzer begrenzt und wurde erfolgreich geprüft.',
            $checkedAt,
        );
    }

    private function containsParentTraversal(string $path): bool
    {
        return in_array('..', explode(DIRECTORY_SEPARATOR, $path), true);
    }

    private function hasUnsafePublicAncestor(string $path, string $publicRoot): bool
    {
        $current = DIRECTORY_SEPARATOR;
        $segments = explode(DIRECTORY_SEPARATOR, trim($this->normalizePath($path), DIRECTORY_SEPARATOR));

        foreach ($segments as $segment) {
            if ('' === $segment) {
                continue;
            }

            $current = DIRECTORY_SEPARATOR === $current
                ? $current.$segment
                : $current.DIRECTORY_SEPARATOR.$segment;

            if (!file_exists($current) && !is_link($current)) {
                continue;
            }

            $resolvedPath = realpath($current);
            if (false === $resolvedPath || $this->isWithin($resolvedPath, $publicRoot)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                if ([] !== $segments) {
                    array_pop($segments);
                }

                continue;
            }

            $segments[] = $segment;
        }

        return DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
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

    private function evidence(CapabilityStatus $status, string $details, \DateTimeImmutable $checkedAt): CapabilityEvidence
    {
        return new CapabilityEvidence(
            HostingCapability::PrivateStorage,
            $status,
            self::class,
            $details,
            $checkedAt,
        );
    }
}
