<?php

declare(strict_types=1);

namespace App\Tests\Platform\Hosting;

use App\Platform\Hosting\CapabilityProbeInterface;
use App\Platform\Hosting\HostingCapabilityGuard;
use App\Platform\Hosting\PrivateFileStorage;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Platform\Hosting\PrivateStorageProbe;
use PHPUnit\Framework\TestCase;

final class PrivateFileStorageTest extends TestCase
{
    public function testWritesReadsAndDeletesOwnerOnlyPrivateObject(): void
    {
        $paths = $this->createStorageRoot();

        try {
            $storage = $this->createStorage($paths['public'], $paths['storage']);
            $storage->write('image_01', 'photo-payload');
            $objectPath = $paths['storage'].DIRECTORY_SEPARATOR.'image_01.bin';
            $permissions = fileperms($objectPath);

            self::assertSame('photo-payload', $storage->read('image_01'));
            self::assertNotFalse($permissions);
            self::assertSame(0, $permissions & 0077);

            $storage->delete('image_01');

            self::assertFileDoesNotExist($objectPath);
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testRejectsPathTraversalBeforeWriting(): void
    {
        $paths = $this->createStorageRoot();

        try {
            $storage = $this->createStorage($paths['public'], $paths['storage']);

            $this->expectException(PrivateStorageOperationFailed::class);
            $storage->write('../outside', 'blocked');
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testRejectsSymlinkObjectWithoutReadingTarget(): void
    {
        $paths = $this->createStorageRoot();
        $outside = $paths['root'].DIRECTORY_SEPARATOR.'outside.bin';
        if (false === file_put_contents($outside, 'private-target')) {
            throw new \RuntimeException('Testdatei konnte nicht geschrieben werden.');
        }
        $link = $paths['storage'].DIRECTORY_SEPARATOR.'image_02.bin';

        if (!symlink($outside, $link)) {
            $this->removeTree($paths['root']);
            self::markTestSkipped('Symlinks are not available in this test environment.');
        }

        try {
            $storage = $this->createStorage($paths['public'], $paths['storage']);

            $this->expectException(PrivateStorageOperationFailed::class);
            $storage->read('image_02');
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testWriteRejectsSymlinkObjectWithoutChangingTarget(): void
    {
        $paths = $this->createStorageRoot();
        $outside = $paths['root'].DIRECTORY_SEPARATOR.'outside-write.bin';
        if (false === file_put_contents($outside, 'original-target')) {
            throw new \RuntimeException('Testdatei konnte nicht geschrieben werden.');
        }
        $link = $paths['storage'].DIRECTORY_SEPARATOR.'image_05.bin';

        if (!symlink($outside, $link)) {
            $this->removeTree($paths['root']);
            self::markTestSkipped('Symlinks are not available in this test environment.');
        }

        try {
            $storage = $this->createStorage($paths['public'], $paths['storage']);

            try {
                $storage->write('image_05', 'replacement');
                self::fail('Ein vorhandener Symlink muss vor dem Schreiben abgelehnt werden.');
            } catch (PrivateStorageOperationFailed) {
                self::assertTrue(is_link($link));
                self::assertSame('original-target', file_get_contents($outside));
            }
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testRejectsStorageRootInsidePublicWebDirectory(): void
    {
        $paths = $this->createStorageRoot();
        $publicStorage = $paths['public'].DIRECTORY_SEPARATOR.'private';
        if (!mkdir($publicStorage, 0700)) {
            throw new \RuntimeException('Testverzeichnis konnte nicht angelegt werden.');
        }

        try {
            $storage = $this->createStorage($paths['public'], $publicStorage);

            $this->expectException(PrivateStorageOperationFailed::class);
            $storage->write('image_03', 'blocked');
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testRejectsOverlyPermissiveStorageRoot(): void
    {
        $paths = $this->createStorageRoot();
        if (!chmod($paths['storage'], 0755)) {
            throw new \RuntimeException('Testrechte konnten nicht geändert werden.');
        }

        try {
            $storage = $this->createStorage($paths['public'], $paths['storage']);

            $this->expectException(PrivateStorageOperationFailed::class);
            $storage->write('image_04', 'blocked');
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testRejectsWriteWhenPrivateStorageCapabilityIsUnknown(): void
    {
        $paths = $this->createStorageRoot();
        $objectPath = $paths['storage'].DIRECTORY_SEPARATOR.'blocked.bin';

        try {
            $storage = new PrivateFileStorage(
                $paths['public'],
                $paths['storage'],
                $this->emptyCapabilityProbe(),
                new HostingCapabilityGuard(),
            );

            try {
                $storage->write('blocked', 'must-not-be-written');
                self::fail('Unbekannte private Speicherfähigkeit muss das Schreiben sperren.');
            } catch (PrivateStorageOperationFailed) {
                self::assertFileDoesNotExist($objectPath);
            }
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testRejectsReadWhenPrivateStorageCapabilityIsUnknown(): void
    {
        $paths = $this->createStorageRoot();

        try {
            $this->createStorage($paths['public'], $paths['storage'])->write('guarded', 'stored');
            $storage = new PrivateFileStorage(
                $paths['public'],
                $paths['storage'],
                $this->emptyCapabilityProbe(),
                new HostingCapabilityGuard(),
            );

            $this->expectException(PrivateStorageOperationFailed::class);
            $storage->read('guarded');
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testRejectsDeleteWhenPrivateStorageCapabilityIsUnknown(): void
    {
        $paths = $this->createStorageRoot();
        $objectPath = $paths['storage'].DIRECTORY_SEPARATOR.'guarded.bin';

        try {
            $this->createStorage($paths['public'], $paths['storage'])->write('guarded', 'stored');
            $storage = new PrivateFileStorage(
                $paths['public'],
                $paths['storage'],
                $this->emptyCapabilityProbe(),
                new HostingCapabilityGuard(),
            );

            try {
                $storage->delete('guarded');
                self::fail('Unbekannte private Speicherfähigkeit muss das Löschen sperren.');
            } catch (PrivateStorageOperationFailed) {
                self::assertFileExists($objectPath);
            }
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    private function emptyCapabilityProbe(): CapabilityProbeInterface
    {
        return new class implements CapabilityProbeInterface {
            /**
             * @return iterable<\App\Platform\Hosting\CapabilityEvidence>
             */
            public function probe(): iterable
            {
                return [];
            }
        };
    }

    private function createStorage(string $publicPath, string $storagePath): PrivateFileStorage
    {
        return new PrivateFileStorage(
            $publicPath,
            $storagePath,
            new PrivateStorageProbe($publicPath, $storagePath),
            new HostingCapabilityGuard(),
        );
    }

    /**
     * @return array{root: string, public: string, storage: string}
     */
    private function createStorageRoot(): array
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arcanorum-private-files-'.bin2hex(random_bytes(8));
        $public = $root.DIRECTORY_SEPARATOR.'public';
        $variable = $root.DIRECTORY_SEPARATOR.'var';
        $storage = $variable.DIRECTORY_SEPARATOR.'private';

        if (!mkdir($root, 0700)) {
            throw new \RuntimeException('Temporäres Speicherverzeichnis konnte nicht angelegt werden.');
        }

        if (!mkdir($public, 0755)) {
            throw new \RuntimeException('Temporäres Speicherverzeichnis konnte nicht angelegt werden.');
        }

        if (!mkdir($variable, 0700)) {
            throw new \RuntimeException('Temporäres Speicherverzeichnis konnte nicht angelegt werden.');
        }

        if (!mkdir($storage, 0700)) {
            throw new \RuntimeException('Temporäres Speicherverzeichnis konnte nicht angelegt werden.');
        }

        return ['root' => $root, 'public' => $public, 'storage' => $storage];
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);

            return;
        }

        if (is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry) {
                continue;
            }

            if ('..' === $entry) {
                continue;
            }

            $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
