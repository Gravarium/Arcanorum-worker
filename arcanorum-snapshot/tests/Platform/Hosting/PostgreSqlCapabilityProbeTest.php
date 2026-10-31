<?php

declare(strict_types=1);

namespace App\Tests\Platform\Hosting;

use App\Platform\Hosting\CapabilityEvidence;
use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\PostgreSqlCapabilityProbe;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class PostgreSqlCapabilityProbeTest extends TestCase
{
    public function testPostgreSql18ConnectionIsVerified(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchOne')
            ->with('SHOW server_version_num')
            ->willReturn('180000');

        $evidence = $this->probe($connection);

        self::assertSame(HostingCapability::PostgreSqlServer18, $evidence->capability);
        self::assertSame(CapabilityStatus::Verified, $evidence->status);
    }

    public function testUnsupportedPostgreSqlVersionFailsClosed(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('170006');

        $evidence = $this->probe($connection);

        self::assertSame(CapabilityStatus::Failed, $evidence->status);
    }

    public function testMalformedServerVersionIsUnavailable(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('18.0');

        $evidence = $this->probe($connection);

        self::assertSame(CapabilityStatus::NotAvailable, $evidence->status);
    }

    public function testConnectionFailureDoesNotExposeDriverDetails(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(
            new \RuntimeException('postgresql://user:secret@database.internal'),
        );

        $evidence = $this->probe($connection);

        self::assertSame(CapabilityStatus::NotAvailable, $evidence->status);
        self::assertStringNotContainsString('secret', $evidence->details);
        self::assertStringNotContainsString('database.internal', $evidence->details);
    }

    private function probe(Connection $connection): CapabilityEvidence
    {
        foreach (new PostgreSqlCapabilityProbe($connection)->probe() as $evidence) {
            return $evidence;
        }

        throw new \LogicException('Der PostgreSQL-Prüfer muss einen Nachweis liefern.');
    }
}
