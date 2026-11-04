<?php

declare(strict_types=1);

namespace App\Tests\Platform\Hosting;

use App\Platform\Hosting\DoctrineHostingSecurityFeatureSelectionStore;
use App\Platform\Hosting\HostingCapabilityGuard;
use App\Platform\Hosting\HostingSecurityFeature;
use App\Platform\Hosting\PrivateFileStorage;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Platform\Hosting\PrivateStorageProbe;
use App\Service\PrivateObjectStorageGateway;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineHostingSecurityFeatureSelectionStoreIntegrationTest extends KernelTestCase
{
    public function testSelectedFeaturesAreReadFromTheMigratedDatabaseRow(): void
    {
        self::bootKernel();
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                'UPDATE hosting_security_selection SET selected_feature_ids = CAST(:features AS json) WHERE id = :id',
                ['features' => '["private_redis_endpoint"]', 'id' => 1],
            );

            $selection = new DoctrineHostingSecurityFeatureSelectionStore($connection);

            self::assertSame([HostingSecurityFeature::PrivateRedisEndpoint], $selection->selectedFeatures());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testPrivateStorageGatewayAppliesSavedSelectionAtResetBoundaries(): void
    {
        self::bootKernel();
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $paths = $this->createStorageRoot();
        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                'UPDATE hosting_security_selection SET selected_feature_ids = CAST(:features AS json) WHERE id = :id',
                ['features' => '[]', 'id' => 1],
            );
            $gateway = new PrivateObjectStorageGateway(
                new PrivateFileStorage(
                    $paths['public'],
                    $paths['storage'],
                    new PrivateStorageProbe($paths['public'], $paths['storage']),
                    new HostingCapabilityGuard(),
                ),
                new DoctrineHostingSecurityFeatureSelectionStore($connection),
            );

            $this->assertOperationIsBlocked(static fn () => $gateway->write('choice', 'blocked'));

            $connection->executeStatement(
                'UPDATE hosting_security_selection SET selected_feature_ids = CAST(:features AS json) WHERE id = :id',
                ['features' => '["private_storage"]', 'id' => 1],
            );
            $gateway->reset();
            $gateway->write('choice', 'enabled-after-reset');
            self::assertSame('enabled-after-reset', $gateway->read('choice', strlen('enabled-after-reset')));

            $connection->executeStatement(
                'UPDATE hosting_security_selection SET selected_feature_ids = CAST(:features AS json) WHERE id = :id',
                ['features' => '[]', 'id' => 1],
            );
            $gateway->reset();
            $this->assertOperationIsBlocked(static fn () => $gateway->delete('choice'));
            self::assertFileExists($paths['storage'].DIRECTORY_SEPARATOR.'choice.bin');
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
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

    /**
     * @return array{root: string, public: string, storage: string}
     */
    private function createStorageRoot(): array
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arcanorum-private-gateway-integration-'.bin2hex(random_bytes(8));
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
