<?php

declare(strict_types=1);

namespace App\Tests\Module\Backup\Service;

use App\Module\Backup\Service\BackupOperationFailed;
use App\Module\Backup\Service\DatabaseBackupService;
use App\Module\Backup\Service\PostgresBackupExecutorInterface;
use App\Platform\Hosting\HostingCapabilityGuard;
use App\Platform\Hosting\PrivateStorageProbe;
use PHPUnit\Framework\TestCase;

final class DatabaseBackupServiceTest extends TestCase
{
    public function testCreatesEncryptedBackupAndSupportsVerifyDownloadAndDelete(): void
    {
        $paths = $this->createPaths();
        $temporaryDownload = null;

        try {
            $service = $this->createService($paths);
            self::assertTrue($service->availability()['available']);

            $created = $service->create();
            self::assertSame([$created['id']], array_column($service->list(), 'id'));
            self::assertGreaterThan(0, $created['size_bytes']);

            $archivePath = $paths['private'].DIRECTORY_SEPARATOR.'database-backups'.DIRECTORY_SEPARATOR.$created['id'].'.dump.enc';
            self::assertFileExists($archivePath);
            self::assertStringNotContainsString('private database payload', (string) file_get_contents($archivePath));
            $permissions = fileperms($archivePath);
            self::assertNotFalse($permissions);
            self::assertSame(0, $permissions & 0077);

            $service->verify($created['id']);
            $temporaryDownload = $service->decryptedArchivePath($created['id']);
            self::assertSame('private database payload', file_get_contents($temporaryDownload));

            $service->delete($created['id']);
            self::assertSame([], $service->list());
            self::assertFileDoesNotExist($archivePath);
        } finally {
            if (null !== $temporaryDownload && is_file($temporaryDownload)) {
                unlink($temporaryDownload);
            }
            $this->removeTree($paths['root']);
        }
    }

    public function testRejectsTamperedEncryptedArchiveAndManifest(): void
    {
        $paths = $this->createPaths();
        try {
            $service = $this->createService($paths);
            $created = $service->create();
            $archivePath = $paths['private'].DIRECTORY_SEPARATOR.'database-backups'.DIRECTORY_SEPARATOR.$created['id'].'.dump.enc';
            file_put_contents($archivePath, 'changed');
            chmod($archivePath, 0600);

            $this->expectException(BackupOperationFailed::class);
            $service->verify($created['id']);
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testRejectsModifiedManifestBeforeListing(): void
    {
        $paths = $this->createPaths();
        try {
            $service = $this->createService($paths);
            $created = $service->create();
            $manifestPath = $paths['private'].DIRECTORY_SEPARATOR.'database-backups'.DIRECTORY_SEPARATOR.$created['id'].'.json';
            file_put_contents($manifestPath, '{}');
            chmod($manifestPath, 0600);

            $this->expectException(BackupOperationFailed::class);
            $service->list();
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testMissingEncryptionKeyFailsClosed(): void
    {
        $paths = $this->createPaths();
        try {
            $service = $this->createService($paths, 'not-a-key');
            self::assertFalse($service->availability()['available']);
            $this->expectException(BackupOperationFailed::class);
            $service->create();
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testMissingSeparateBackupDirectoryDisablesBackups(): void
    {
        $paths = $this->createPaths();
        try {
            $service = $this->createService($paths, null, false, 'private database payload', '');
            self::assertFalse($service->availability()['available']);
            $this->expectException(BackupOperationFailed::class);
            $service->list();
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testFailedDumpDoesNotPublishBackup(): void
    {
        $paths = $this->createPaths();
        try {
            $service = $this->createService($paths, null, true);
            try {
                $service->create();
                self::fail('Ein Fehler des Dump-Werkzeugs muss gemeldet werden.');
            } catch (BackupOperationFailed) {
                self::assertSame([], $service->list());
            }
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testRejectsBackupDestinationInsideApplicationTree(): void
    {
        $paths = $this->createPaths();
        $applicationPrivate = $paths['app'].DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'private';
        try {
            $service = $this->createService($paths, null, false, 'private database payload', $applicationPrivate);
            self::assertFalse($service->availability()['available']);
            self::assertDirectoryDoesNotExist($applicationPrivate.DIRECTORY_SEPARATOR.'database-backups');
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testRejectsBackupDestinationInsidePublicWebRoot(): void
    {
        $paths = $this->createPaths();
        $publicPrivate = $paths['public'].DIRECTORY_SEPARATOR.'private-backups';
        try {
            $service = $this->createService($paths, null, false, 'private database payload', $publicPrivate);
            self::assertFalse($service->availability()['available']);
            self::assertDirectoryDoesNotExist($publicPrivate.DIRECTORY_SEPARATOR.'database-backups');
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testRetentionRemovesOldestEntriesAfterTenBackups(): void
    {
        $paths = $this->createPaths();
        try {
            $service = $this->createService($paths);
            for ($index = 0; $index < 11; ++$index) {
                $service->create();
            }

            self::assertCount(10, $service->list());
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testSecretStreamHandlesExactAndPartialChunkBoundaries(): void
    {
        $paths = $this->createPaths();
        $downloadPath = null;
        try {
            foreach ([65536, 131072, 131089] as $length) {
                $payload = str_repeat('A', $length);
                $service = $this->createService($paths, null, false, $payload);
                $created = $service->create();
                $service->verify($created['id']);
                $downloadPath = $service->decryptedArchivePath($created['id']);
                self::assertSame($payload, file_get_contents($downloadPath));
                unlink($downloadPath);
                $downloadPath = null;
                $service->delete($created['id']);
            }
        } finally {
            if (null !== $downloadPath && is_file($downloadPath)) {
                unlink($downloadPath);
            }
            $this->removeTree($paths['root']);
        }
    }

    /** @param array{root: string, app: string, public: string, private: string} $paths */
    private function createService(array $paths, ?string $key = null, bool $failDump = false, string $payload = 'private database payload', ?string $privateDirectory = null): DatabaseBackupService
    {
        $executor = new readonly class($failDump, $payload) implements PostgresBackupExecutorInterface {
            public function __construct(private bool $failDump, private string $payload)
            {
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function dump(string $targetPath): void
            {
                if ($this->failDump) {
                    throw new BackupOperationFailed();
                }

                if (false === file_put_contents($targetPath, $this->payload)) {
                    throw new BackupOperationFailed();
                }
            }

            public function verifyArchive(string $archivePath): void
            {
                if ($this->payload !== file_get_contents($archivePath)) {
                    throw new BackupOperationFailed();
                }
            }
        };

        return new DatabaseBackupService(
            $executor,
            new PrivateStorageProbe($paths['public'], $privateDirectory ?? $paths['private']),
            new HostingCapabilityGuard(),
            $paths['app'],
            $paths['public'],
            $privateDirectory ?? $paths['private'],
            $key ?? str_repeat('a1', 32),
        );
    }

    /** @return array{root: string, app: string, public: string, private: string} */
    private function createPaths(): array
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arcanorum-backup-test-'.bin2hex(random_bytes(8));
        $application = $root.DIRECTORY_SEPARATOR.'app';
        $public = $application.DIRECTORY_SEPARATOR.'public';
        $private = $root.DIRECTORY_SEPARATOR.'private';
        if (!mkdir($root, 0700) || !mkdir($application, 0700) || !mkdir($public, 0700) || !mkdir($private, 0700)) {
            throw new \RuntimeException('Testverzeichnis konnte nicht angelegt werden.');
        }

        return ['root' => $root, 'app' => $application, 'public' => $public, 'private' => $private];
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        foreach (false === $items ? [] : $items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $this->removeTree($path.DIRECTORY_SEPARATOR.$item);
        }
        rmdir($path);
    }
}
