<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Platform\Hosting\HostingCapabilityGuard;
use App\Platform\Hosting\HostingSecurityFeature;
use App\Platform\Hosting\HostingSecurityFeatureSelectionStoreInterface;
use App\Platform\Hosting\PrivateFileStorage;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Platform\Hosting\PrivateStorageProbe;
use App\Service\PrivateObjectStorageGateway;
use PHPUnit\Framework\TestCase;

final class PrivateObjectStorageGatewayTest extends TestCase
{
    public function testSelectedPrivateStorageDelegatesOperationsAndReadsSelectionOnce(): void
    {
        $paths = $this->createStorageRoot();
        $selectionStore = $this->createMock(HostingSecurityFeatureSelectionStoreInterface::class);
        $selectionStore->expects(self::once())
            ->method('selectedFeatures')
            ->willReturn([HostingSecurityFeature::PrivateStorage]);

        try {
            $gateway = new PrivateObjectStorageGateway($this->createStorage($paths['public'], $paths['storage']), $selectionStore);

            $gateway->write('selected', 'private-payload');

            self::assertSame('private-payload', $gateway->read('selected', strlen('private-payload')));

            $gateway->delete('selected');

            self::assertFileDoesNotExist($paths['storage'].DIRECTORY_SEPARATOR.'selected.bin');
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testUnselectedPrivateStorageBlocksWriteReadAndDelete(): void
    {
        $paths = $this->createStorageRoot();
        $storage = $this->createStorage($paths['public'], $paths['storage']);
        $storage->write('existing', 'keep-this-object');
        $selectionStore = $this->createMock(HostingSecurityFeatureSelectionStoreInterface::class);
        $selectionStore->expects(self::once())
            ->method('selectedFeatures')
            ->willReturn([]);

        try {
            $gateway = new PrivateObjectStorageGateway($storage, $selectionStore);

            $this->assertOperationIsBlocked(static fn () => $gateway->write('new-object', 'blocked'));
            $this->assertOperationIsBlocked(static fn () => $gateway->read('existing', 64));
            $this->assertOperationIsBlocked(static fn () => $gateway->delete('existing'));

            self::assertFileDoesNotExist($paths['storage'].DIRECTORY_SEPARATOR.'new-object.bin');
            self::assertFileExists($paths['storage'].DIRECTORY_SEPARATOR.'existing.bin');
            self::assertSame('keep-this-object', $storage->read('existing', strlen('keep-this-object')));
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testSelectionStoreFailureFailsClosedWithGenericStorageError(): void
    {
        $paths = $this->createStorageRoot();
        $selectionStore = $this->createMock(HostingSecurityFeatureSelectionStoreInterface::class);
        $selectionStore->expects(self::once())
            ->method('selectedFeatures')
            ->willThrowException(new \RuntimeException('sensitive database diagnostic'));

        try {
            $gateway = new PrivateObjectStorageGateway($this->createStorage($paths['public'], $paths['storage']), $selectionStore);

            try {
                $gateway->write('blocked', 'must-not-be-written');
                self::fail('Ein Fehler beim Lesen der Auswahl muss private Speicheraktionen sperren.');
            } catch (PrivateStorageOperationFailed $exception) {
                self::assertSame(
                    'Der private Dateispeicher konnte die angeforderte Aktion nicht sicher ausführen.',
                    $exception->getMessage(),
                );
                self::assertFileDoesNotExist($paths['storage'].DIRECTORY_SEPARATOR.'blocked.bin');
            }
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    public function testResetReloadsTheSelectionWithoutCheckingItForEachFileOperation(): void
    {
        $paths = $this->createStorageRoot();
        $selectionStore = $this->createMock(HostingSecurityFeatureSelectionStoreInterface::class);
        $selectionStore->expects(self::exactly(3))
            ->method('selectedFeatures')
            ->willReturnOnConsecutiveCalls(
                [],
                [HostingSecurityFeature::PrivateStorage],
                [],
            );

        try {
            $gateway = new PrivateObjectStorageGateway($this->createStorage($paths['public'], $paths['storage']), $selectionStore);
            $this->assertOperationIsBlocked(static fn () => $gateway->write('before-reset', 'blocked'));

            $gateway->reset();
            $gateway->write('after-reset', 'allowed-after-selection');
            self::assertSame(
                'allowed-after-selection',
                $gateway->read('after-reset', strlen('allowed-after-selection')),
            );

            $gateway->reset();
            $this->assertOperationIsBlocked(static fn () => $gateway->read('after-reset', 64));
            self::assertFileExists($paths['storage'].DIRECTORY_SEPARATOR.'after-reset.bin');
        } finally {
            $this->removeTree($paths['root']);
        }
    }

    private function assertOperationIsBlocked(callable $operation): void
    {
        try {
            $operation();
            self::fail('Ohne gespeicherte private Speicherauswahl muss die Aktion gesperrt sein.');
        } catch (PrivateStorageOperationFailed $exception) {
            self::assertSame(
                'Der private Dateispeicher konnte die angeforderte Aktion nicht sicher ausführen.',
                $exception->getMessage(),
            );
        }
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
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arcanorum-private-gateway-'.bin2hex(random_bytes(8));
        $public = $root.DIRECTORY_SEPARATOR.'public';
        $variable = $root.DIRECTORY_SEPARATOR.'var';
        $storage = $variable.DIRECTORY_SEPARATOR.'private';

        if (!mkdir($root, 0700) || !mkdir($public, 0755) || !mkdir($variable, 0700) || !mkdir($storage, 0700)) {
            throw new \RuntimeException('Temporäre Speicherverzeichnisse konnten nicht angelegt werden.');
        }

        return ['root' => $root, 'public' => $public, 'storage' => $storage];
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
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
            if ('.' !== $entry && '..' !== $entry) {
                $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
            }
        }

        @rmdir($path);
    }
}
