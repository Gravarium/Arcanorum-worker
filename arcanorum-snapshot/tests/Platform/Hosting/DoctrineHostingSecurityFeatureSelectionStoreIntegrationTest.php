<?php

declare(strict_types=1);

namespace App\Tests\Platform\Hosting;

use App\Platform\Hosting\DoctrineHostingSecurityFeatureSelectionStore;
use App\Platform\Hosting\HostingSecurityFeature;
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
}
