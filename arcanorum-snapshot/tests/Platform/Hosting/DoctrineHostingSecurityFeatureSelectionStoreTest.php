<?php

declare(strict_types=1);

namespace App\Tests\Platform\Hosting;

use App\Platform\Hosting\DoctrineHostingSecurityFeatureSelectionStore;
use App\Platform\Hosting\HostingSecurityFeature;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DoctrineHostingSecurityFeatureSelectionStoreTest extends TestCase
{
    public function testOnlyKnownUniqueFeatureIdsAreReturned(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(
                'SELECT selected_feature_ids FROM hosting_security_selection WHERE id = :id',
                ['id' => 1],
            )
            ->willReturn('["private_redis_endpoint"]');

        $selection = new DoctrineHostingSecurityFeatureSelectionStore($connection);

        self::assertSame([HostingSecurityFeature::PrivateRedisEndpoint], $selection->selectedFeatures());
    }

    public function testMalformedOrTamperedSelectionsAreIgnoredAsAWhole(): void
    {
        foreach ([
            '{invalid',
            '{"feature":"private_redis_endpoint"}',
            'null',
            'false',
            '4',
            '["private_redis_endpoint","unknown_feature"]',
            '["private_redis_endpoint","private_redis_endpoint"]',
            '["private_redis_endpoint",4]',
        ] as $storedValue) {
            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())
                ->method('fetchOne')
                ->willReturn($storedValue);

            self::assertSame([], new DoctrineHostingSecurityFeatureSelectionStore($connection)->selectedFeatures());
        }
    }

    public function testUnavailableSelectionTableUsesNoOptionalFeatures(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willThrowException(new \RuntimeException('Private database diagnostic'));

        self::assertSame([], new DoctrineHostingSecurityFeatureSelectionStore($connection)->selectedFeatures());
    }
}
