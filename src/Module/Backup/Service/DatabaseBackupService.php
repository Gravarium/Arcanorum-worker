<?php

declare(strict_types=1);

namespace App\Module\Backup\Service;

use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingCapabilityGuard;
use App\Platform\Hosting\PrivateStorageProbe;

/**
 * @phpstan-type BackupMetadata array{version: int, id: string, created_at: string, size_bytes: int, sha256: string, key_id: string, mac: string}
 * @phpstan-type FileStat array{dev: int, ino: int, mode: int, size: int, mtime: int}
 */
final readonly class DatabaseBackupService
{
    private const int RETAINED_BACKUPS = 10;
    private const int STREAM_CHUNK_BYTES = 65536;
    private const int MAX_MANIFEST_BYTES = 8192;

    public function __construct(
        private PostgresBackupExecutorInterface $executor,
        private PrivateStorageProbe $storageProbe,
        private HostingCapabilityGuard $capabilityGuard,
        private string $applicationDirectory,
        private string $publicDirectory,
        private string $privateDirectory,
        private string $encryptionKey,
    ) {
    }

    /** @return array{available: bool, reason: string} */
    public function availability(): array
    {
        if (null === $this->masterKey()) {
            return ['available' => false, 'reason' => 'Der Sicherungsschlüssel ist nicht sicher eingerichtet.'];
        }

        try {
            if (!$this->executor->isAvailable()) {
                return ['available' => false, 'reason' => 'Die erforderlichen PostgreSQL-Sicherungswerkzeuge sind nicht verfügbar.'];
            }
            $this->backupDirectory();
        } catch (\Throwable) {
            return ['available' => false, 'reason' => 'Der private Sicherungsspeicher ist nicht verfügbar.'];
        }

        return ['available' => true, 'reason' => ''];
    }

    /** @return list<array{id: string, created_at: string, size_bytes: int}> */
    public function list(): array
    {
        $this->requireAvailable();
        $directory = $this->backupDirectory();
        $entries = $this->readEntries($directory);
        usort($entries, static fn (array $left, array $right): int => $right['created_at'] <=> $left['created_at']);

        return $entries;
    }

    /** @return array{id: string, created_at: string, size_bytes: int} */
    public function create(): array
    {
        $this->requireAvailable();

        try {
            $result = $this->withLock(function (string $directory): array {
                $this->removeOldTemporaryFiles($directory);
                $plainPath = $this->temporaryPath($directory, 'plain');
                $encryptedPath = $this->temporaryPath($directory, 'encrypted');
                $manifestPath = $this->temporaryPath($directory, 'manifest');
                $id = bin2hex(random_bytes(16));
                $publishedArchive = $directory.DIRECTORY_SEPARATOR.$id.'.dump.enc';
                $publishedManifest = $directory.DIRECTORY_SEPARATOR.$id.'.json';
                $archivePublished = false;
                $manifestPublished = false;

                try {
                    $this->createEmptyPrivateFile($plainPath);
                    $this->executor->dump($plainPath);
                    $this->assertPrivateRegularFile($plainPath);
                    if (0 === filesize($plainPath)) {
                        throw new BackupOperationFailed();
                    }

                    $this->executor->verifyArchive($plainPath);
                    $this->encryptFile($plainPath, $encryptedPath);
                    $this->assertPrivateRegularFile($encryptedPath);

                    $size = filesize($encryptedPath);
                    $digest = hash_file('sha256', $encryptedPath);
                    if (false === $size || 0 === $size || false === $digest
                        || file_exists($publishedArchive) || file_exists($publishedManifest)
                    ) {
                        throw new BackupOperationFailed();
                    }

                    if (!@rename($encryptedPath, $publishedArchive)) {
                        throw new BackupOperationFailed();
                    }
                    $archivePublished = true;

                    $createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
                    $manifest = $this->signedManifest([
                        'version' => 1,
                        'id' => $id,
                        'created_at' => $createdAt,
                        'size_bytes' => $size,
                        'sha256' => $digest,
                        'key_id' => substr(hash('sha256', $this->masterKey() ?? ''), 0, 16),
                    ]);
                    $this->writeManifest($manifestPath, $manifest);
                    if (!@rename($manifestPath, $publishedManifest)) {
                        throw new BackupOperationFailed();
                    }
                    $manifestPublished = true;

                    $retainedEntries = $this->readEntries($directory);
                    usort($retainedEntries, static fn (array $left, array $right): int => $right['created_at'] <=> $left['created_at']);
                    foreach (array_slice($retainedEntries, self::RETAINED_BACKUPS) as $entry) {
                        $this->deleteUnlocked($directory, $entry['id']);
                    }

                    return ['id' => $id, 'created_at' => $createdAt, 'size_bytes' => $size];
                } catch (\Throwable $exception) {
                    if ($archivePublished && !$manifestPublished && is_file($publishedArchive) && !is_link($publishedArchive)) {
                        @unlink($publishedArchive);
                    }

                    if ($exception instanceof BackupOperationFailed) {
                        throw $exception;
                    }

                    throw new BackupOperationFailed();
                } finally {
                    foreach ([$plainPath, $encryptedPath, $manifestPath] as $temporaryPath) {
                        if (is_file($temporaryPath) && !is_link($temporaryPath)) {
                            @unlink($temporaryPath);
                        }
                    }
                }
            });
        } catch (BackupOperationFailed $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new BackupOperationFailed();
        }

        if (!is_array($result) || !is_string($result['id'] ?? null) || !is_string($result['created_at'] ?? null)
            || !is_int($result['size_bytes'] ?? null)
        ) {
            throw new BackupOperationFailed();
        }

        return ['id' => $result['id'], 'created_at' => $result['created_at'], 'size_bytes' => $result['size_bytes']];
    }

    public function verify(string $id): void
    {
        $this->requireAvailable();
        $directory = $this->backupDirectory();
        $metadata = $this->metadataForId($directory, $id);
        $plainPath = null;
        try {
            $plainPath = $this->temporaryPath($directory, 'verify');
            $this->decryptToPath($directory, $metadata, $plainPath);
            $this->executor->verifyArchive($plainPath);
        } catch (\Throwable $exception) {
            if ($exception instanceof BackupOperationFailed) {
                throw $exception;
            }

            throw new BackupOperationFailed();
        } finally {
            if (is_string($plainPath) && is_file($plainPath) && !is_link($plainPath)) {
                @unlink($plainPath);
            }
        }
    }

    /** Returns a verified, decrypted, owner-only temporary file. The caller must remove it after use. */
    public function decryptedArchivePath(string $id): string
    {
        $this->requireAvailable();
        $directory = $this->backupDirectory();
        $metadata = $this->metadataForId($directory, $id);
        $plainPath = null;

        try {
            $plainPath = $this->temporaryPath($directory, 'download');
            $this->decryptToPath($directory, $metadata, $plainPath);
            $this->executor->verifyArchive($plainPath);

            return $plainPath;
        } catch (\Throwable $exception) {
            if (is_string($plainPath) && is_file($plainPath) && !is_link($plainPath)) {
                @unlink($plainPath);
            }

            if ($exception instanceof BackupOperationFailed) {
                throw $exception;
            }

            throw new BackupOperationFailed();
        }
    }

    public function delete(string $id): void
    {
        $this->requireAvailable();
        $this->withLock(function (string $directory) use ($id): void {
            $this->deleteUnlocked($directory, $id);
        });
    }

    private function requireAvailable(): void
    {
        $availability = $this->availability();
        if (!$availability['available']) {
            throw new BackupOperationFailed();
        }
    }

    private function masterKey(): ?string
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/i', $this->encryptionKey)) {
            return null;
        }

        $key = hex2bin($this->encryptionKey);
        if (false === $key || SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES !== strlen($key)) {
            return null;
        }

        return $key;
    }

    private function encryptionKey(): ?string
    {
        $masterKey = $this->masterKey();
        if (null === $masterKey) {
            return null;
        }

        return hash_hkdf('sha256', $masterKey, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 'arcanorum/database-backup/encryption/v1');
    }

    private function manifestKey(): ?string
    {
        $masterKey = $this->masterKey();
        if (null === $masterKey) {
            return null;
        }

        return hash_hkdf('sha256', $masterKey, 32, 'arcanorum/database-backup/manifest-mac/v1');
    }

    private function backupDirectory(): string
    {
        $applicationRoot = realpath($this->applicationDirectory);
        $publicRoot = realpath($this->publicDirectory);
        if (false === $applicationRoot || false === $publicRoot || !$this->pathIsWithin($publicRoot, $applicationRoot)
            || is_link($this->privateDirectory)
        ) {
            throw new BackupOperationFailed();
        }

        $evidence = null;
        foreach ($this->storageProbe->probe() as $candidate) {
            if (null !== $evidence || HostingCapability::PrivateStorage !== $candidate->capability) {
                throw new BackupOperationFailed();
            }
            $evidence = $candidate;
        }
        if (null === $evidence || CapabilityStatus::Verified !== $evidence->effectiveStatus()) {
            throw new BackupOperationFailed();
        }
        $this->capabilityGuard->requireVerifiedEvidence($evidence, HostingCapability::PrivateStorage);

        $privateRoot = realpath($this->privateDirectory);
        if (false === $privateRoot || $this->pathIsWithin($privateRoot, $applicationRoot)) {
            throw new BackupOperationFailed();
        }

        $directory = $privateRoot.DIRECTORY_SEPARATOR.'database-backups';
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700))) {
            throw new BackupOperationFailed();
        }
        if (!@chmod($directory, 0700)) {
            throw new BackupOperationFailed();
        }

        $resolvedDirectory = realpath($directory);
        $permissions = fileperms($directory);
        if (false === $resolvedDirectory || dirname($resolvedDirectory) !== $privateRoot
            || $this->pathIsWithin($resolvedDirectory, $publicRoot)
            || false === $permissions || 0 !== ($permissions & 0077)
        ) {
            throw new BackupOperationFailed();
        }

        return $resolvedDirectory;
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    /** @param callable(string): mixed $operation */
    private function withLock(callable $operation): mixed
    {
        $directory = $this->backupDirectory();
        $lockPath = dirname($directory).DIRECTORY_SEPARATOR.'.database-backups.lock';
        if (is_link($lockPath)) {
            throw new BackupOperationFailed();
        }
        $handle = @fopen($lockPath, 'c+b');
        if (false === $handle) {
            throw new BackupOperationFailed();
        }

        try {
            if (!@chmod($lockPath, 0600)) {
                throw new BackupOperationFailed();
            }
            $opened = $this->fileStat($handle);
            $pathStat = $this->pathStat($lockPath);
            if (false === $opened || false === $pathStat || !$this->sameFile($opened, $pathStat)
                || 0 !== ($opened['mode'] & 0077) || !@flock($handle, LOCK_EX | LOCK_NB)
            ) {
                throw new BackupOperationFailed();
            }

            return $operation($directory);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /** @return list<array{id: string, created_at: string, size_bytes: int}> */
    private function readEntries(string $directory): array
    {
        $entries = [];
        $manifestPaths = glob($directory.DIRECTORY_SEPARATOR.'*.json');
        foreach (false === $manifestPaths ? [] : $manifestPaths as $path) {
            $id = basename($path, '.json');
            $metadata = $this->metadataForId($directory, $id);
            $archive = $directory.DIRECTORY_SEPARATOR.$id.'.dump.enc';
            $archiveStat = $this->pathStat($archive);
            if (false === $archiveStat || !$this->isPrivateRegularFile($archiveStat)) {
                throw new BackupOperationFailed();
            }

            $entries[] = ['id' => $id, 'created_at' => $metadata['created_at'], 'size_bytes' => $metadata['size_bytes']];
        }

        return $entries;
    }

    /** @return BackupMetadata */
    private function metadataForId(string $directory, string $id): array
    {
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $id)) {
            throw new BackupOperationFailed();
        }
        $manifestPath = $directory.DIRECTORY_SEPARATOR.$id.'.json';
        $contents = $this->readPrivateFile($manifestPath, self::MAX_MANIFEST_BYTES);
        try {
            $manifest = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new BackupOperationFailed();
        }

        if (!is_array($manifest) || 7 !== count($manifest) || 1 !== ($manifest['version'] ?? null) || $id !== ($manifest['id'] ?? null)
            || !is_string($manifest['created_at'] ?? null) || 1 !== preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/', $manifest['created_at'])
            || !is_int($manifest['size_bytes'] ?? null) || $manifest['size_bytes'] < 1
            || !is_string($manifest['sha256'] ?? null) || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $manifest['sha256'])
            || !is_string($manifest['key_id'] ?? null) || !is_string($manifest['mac'] ?? null)
        ) {
            throw new BackupOperationFailed();
        }

        $unsigned = [
            'version' => 1,
            'id' => $id,
            'created_at' => $manifest['created_at'],
            'size_bytes' => $manifest['size_bytes'],
            'sha256' => $manifest['sha256'],
            'key_id' => $manifest['key_id'],
        ];
        $masterKey = $this->masterKey();
        $manifestKey = $this->manifestKey();
        if (null === $masterKey || null === $manifestKey) {
            throw new BackupOperationFailed();
        }
        $expectedKeyId = substr(hash('sha256', $masterKey), 0, 16);
        $expectedMac = hash_hmac('sha256', $this->encodeJson($unsigned), $manifestKey);
        if (!hash_equals($expectedKeyId, $manifest['key_id']) || !hash_equals($expectedMac, $manifest['mac'])) {
            throw new BackupOperationFailed();
        }

        $archivePath = $directory.DIRECTORY_SEPARATOR.$id.'.dump.enc';
        $archiveStat = $this->pathStat($archivePath);
        if (false === $archiveStat || !$this->isPrivateRegularFile($archiveStat) || $archiveStat['size'] !== $manifest['size_bytes']) {
            throw new BackupOperationFailed();
        }

        return [...$unsigned, 'mac' => $manifest['mac']];
    }

    /**
     * @param array{version: int, id: string, created_at: string, size_bytes: int, sha256: string, key_id: string} $unsigned
     *
     * @return BackupMetadata
     */
    private function signedManifest(array $unsigned): array
    {
        $manifestKey = $this->manifestKey();
        if (null === $manifestKey) {
            throw new BackupOperationFailed();
        }

        return [...$unsigned, 'mac' => hash_hmac('sha256', $this->encodeJson($unsigned), $manifestKey)];
    }

    /** @param array<string, scalar> $data */
    private function encodeJson(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            throw new BackupOperationFailed();
        }
    }

    /** @param BackupMetadata $manifest */
    private function writeManifest(string $path, array $manifest): void
    {
        $this->writePrivateFile($path, $this->encodeJson($manifest));
    }

    private function deleteUnlocked(string $directory, string $id): void
    {
        $this->metadataForId($directory, $id);
        $archive = $directory.DIRECTORY_SEPARATOR.$id.'.dump.enc';
        $manifest = $directory.DIRECTORY_SEPARATOR.$id.'.json';
        if (!@unlink($archive) || !@unlink($manifest)) {
            throw new BackupOperationFailed();
        }
    }

    /** @param BackupMetadata $metadata */
    private function decryptToPath(string $directory, array $metadata, string $targetPath): void
    {
        $archivePath = $directory.DIRECTORY_SEPARATOR.$metadata['id'].'.dump.enc';
        $digest = hash_file('sha256', $archivePath);
        if (false === $digest || !hash_equals($metadata['sha256'], $digest)) {
            throw new BackupOperationFailed();
        }

        $this->createEmptyPrivateFile($targetPath);
        $input = @fopen($archivePath, 'rb');
        $output = @fopen($targetPath, 'wb');
        if (false === $input || false === $output) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new BackupOperationFailed();
        }

        try {
            $header = $this->readExact($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $key = $this->encryptionKey();
            if (null === $key || SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES !== strlen($header)) {
                throw new BackupOperationFailed();
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);

            $sawFinal = false;
            while (!feof($input)) {
                $ciphertext = $this->readUpTo($input, self::STREAM_CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                if ('' === $ciphertext) {
                    break;
                }
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);
                if (false === $pulled || !is_string($pulled[0] ?? null) || !is_int($pulled[1] ?? null)) {
                    throw new BackupOperationFailed();
                }
                [$plaintext, $tag] = $pulled;
                if (!$this->writeAll($output, $plaintext)) {
                    throw new BackupOperationFailed();
                }
                if (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $tag) {
                    $sawFinal = true;
                    if ('' !== $this->readUpTo($input, 1)) {
                        throw new BackupOperationFailed();
                    }
                    break;
                }
                if (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE !== $tag
                    || strlen($ciphertext) !== self::STREAM_CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                ) {
                    throw new BackupOperationFailed();
                }
            }

            if (!$sawFinal || !@fflush($output)) {
                throw new BackupOperationFailed();
            }
        } catch (\Throwable $exception) {
            if ($exception instanceof BackupOperationFailed) {
                throw $exception;
            }

            throw new BackupOperationFailed();
        } finally {
            @fclose($input);
            @fclose($output);
        }

        $this->assertPrivateRegularFile($targetPath);
    }

    private function encryptFile(string $sourcePath, string $targetPath): void
    {
        $input = @fopen($sourcePath, 'rb');
        if (false === $input) {
            throw new BackupOperationFailed();
        }
        $output = @fopen($targetPath, 'x+b');
        if (false === $output) {
            fclose($input);
            throw new BackupOperationFailed();
        }

        try {
            if (!@chmod($targetPath, 0600)) {
                throw new BackupOperationFailed();
            }
            $key = $this->encryptionKey();
            if (null === $key) {
                throw new BackupOperationFailed();
            }
            $pushState = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            if (!is_string($pushState[0] ?? null) || !is_string($pushState[1] ?? null)) {
                throw new BackupOperationFailed();
            }
            [$state, $header] = $pushState;
            if (!$this->writeAll($output, $header)) {
                throw new BackupOperationFailed();
            }

            do {
                $plaintext = $this->readUpTo($input, self::STREAM_CHUNK_BYTES);
                $isFinal = feof($input);
                if ('' === $plaintext && !$isFinal) {
                    throw new BackupOperationFailed();
                }
                $tag = $isFinal
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plaintext, '', $tag);
                if (!$this->writeAll($output, $ciphertext)) {
                    throw new BackupOperationFailed();
                }
            } while (!$isFinal);

            if (!@fflush($output)) {
                throw new BackupOperationFailed();
            }
        } catch (\Throwable $exception) {
            if ($exception instanceof BackupOperationFailed) {
                throw $exception;
            }

            throw new BackupOperationFailed();
        } finally {
            @fclose($input);
            @fclose($output);
        }
    }

    private function createEmptyPrivateFile(string $path): void
    {
        $handle = @fopen($path, 'x+b');
        if (false === $handle) {
            throw new BackupOperationFailed();
        }
        $permissionsSet = @chmod($path, 0600);
        $closed = @fclose($handle);
        if (!$permissionsSet || !$closed) {
            @unlink($path);
            throw new BackupOperationFailed();
        }
    }

    private function writePrivateFile(string $path, string $contents): void
    {
        $handle = @fopen($path, 'x+b');
        if (false === $handle) {
            throw new BackupOperationFailed();
        }
        try {
            if (!@chmod($path, 0600) || !$this->writeAll($handle, $contents) || !@fflush($handle)) {
                throw new BackupOperationFailed();
            }
        } finally {
            @fclose($handle);
        }
        $this->assertPrivateRegularFile($path);
    }

    /** @param resource $handle */
    private function writeAll($handle, string $contents): bool
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = @fwrite($handle, substr($contents, $offset));
            if (false === $written || 0 === $written) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }

    /** @param resource $handle */
    /** @param resource $handle @param positive-int $length */
    private function readExact($handle, int $length): string
    {
        $contents = '';
        while (strlen($contents) < $length && !feof($handle)) {
            $chunk = fread($handle, max(1, $length - strlen($contents)));
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $contents .= $chunk;
        }

        return $contents;
    }

    /** @param resource $handle */
    /** @param resource $handle @param positive-int $length */
    private function readUpTo($handle, int $length): string
    {
        $contents = '';
        while (strlen($contents) < $length && !feof($handle)) {
            $chunk = fread($handle, max(1, $length - strlen($contents)));
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $contents .= $chunk;
        }

        return $contents;
    }

    private function assertPrivateRegularFile(string $path): void
    {
        $opened = @fopen($path, 'rb');
        if (false === $opened) {
            throw new BackupOperationFailed();
        }
        try {
            $openedStat = $this->fileStat($opened);
            $pathStat = $this->pathStat($path);
            if (false === $openedStat || false === $pathStat || !$this->isPrivateRegularFile($openedStat)
                || !$this->isPrivateRegularFile($pathStat) || !$this->sameFile($openedStat, $pathStat)
            ) {
                throw new BackupOperationFailed();
            }
        } finally {
            fclose($opened);
        }
    }

    private function readPrivateFile(string $path, int $maximumBytes): string
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            throw new BackupOperationFailed();
        }
        try {
            $openedStat = $this->fileStat($handle);
            $pathStat = $this->pathStat($path);
            if (false === $openedStat || false === $pathStat || !$this->isPrivateRegularFile($openedStat)
                || !$this->isPrivateRegularFile($pathStat) || !$this->sameFile($openedStat, $pathStat)
                || $openedStat['size'] > $maximumBytes
            ) {
                throw new BackupOperationFailed();
            }
            $contents = stream_get_contents($handle, $maximumBytes + 1);
            if (false === $contents || strlen($contents) > $maximumBytes) {
                throw new BackupOperationFailed();
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /** @param FileStat $stat */
    private function isPrivateRegularFile(array $stat): bool
    {
        return 0100000 === ($stat['mode'] & 0170000) && 0 === ($stat['mode'] & 0077);
    }

    /**
     * @param FileStat $left
     * @param FileStat $right
     */
    private function sameFile(array $left, array $right): bool
    {
        return $left['dev'] === $right['dev'] && $left['ino'] === $right['ino']
            && 0100000 === ($left['mode'] & 0170000) && 0100000 === ($right['mode'] & 0170000);
    }

    private function temporaryPath(string $directory, string $purpose): string
    {
        return $directory.DIRECTORY_SEPARATOR.'.tmp-'.$purpose.'-'.bin2hex(random_bytes(16));
    }

    private function removeOldTemporaryFiles(string $directory): void
    {
        $cutoff = time() - 86400;
        foreach ([
            [$directory, '.tmp-*', $cutoff],
            [$directory, '.pg-restore-list-*', $cutoff],
            [$directory, '.pgpass-*', time() - 3600],
            [dirname($directory), '.pgpass-*', time() - 3600],
        ] as [$root, $pattern, $olderThan]) {
            $paths = glob($root.DIRECTORY_SEPARATOR.$pattern);
            foreach (false === $paths ? [] : $paths as $path) {
                if (is_link($path)) {
                    throw new BackupOperationFailed();
                }
                $stat = $this->pathStat($path);
                if (false === $stat) {
                    continue;
                }
                if (!$this->isPrivateRegularFile($stat)) {
                    throw new BackupOperationFailed();
                }
                if ($stat['mtime'] < $olderThan && !@unlink($path)) {
                    throw new BackupOperationFailed();
                }
            }
        }
    }

    /**
     * @param resource $handle
     *
     * @return FileStat|false
     */
    private function fileStat($handle): array|false
    {
        $stat = @fstat($handle);

        return false === $stat ? false : $this->normalizeStat($stat);
    }

    /** @return FileStat|false */
    private function pathStat(string $path): array|false
    {
        $stat = @lstat($path);

        return false === $stat ? false : $this->normalizeStat($stat);
    }

    /**
     * @param array<int|string, int> $stat
     *
     * @return FileStat|false
     */
    private function normalizeStat(array $stat): array|false
    {
        if (!isset($stat['dev'], $stat['ino'], $stat['mode'], $stat['size'], $stat['mtime'])) {
            return false;
        }

        return [
            'dev' => $stat['dev'],
            'ino' => $stat['ino'],
            'mode' => $stat['mode'],
            'size' => $stat['size'],
            'mtime' => $stat['mtime'],
        ];
    }
}
