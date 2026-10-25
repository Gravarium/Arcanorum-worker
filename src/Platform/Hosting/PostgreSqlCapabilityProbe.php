<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

use Doctrine\DBAL\Connection;

final readonly class PostgreSqlCapabilityProbe implements CapabilityProbeInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return iterable<CapabilityEvidence>
     */
    public function probe(): iterable
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $serverVersion = $this->connection->fetchOne('SHOW server_version_num');
        } catch (\Throwable) {
            yield new CapabilityEvidence(
                HostingCapability::PostgreSqlServer18,
                CapabilityStatus::NotAvailable,
                self::class,
                'Die PostgreSQL-Verbindung oder Versionsprüfung ist nicht verfügbar.',
                $checkedAt,
            );

            return;
        }

        if ((!is_int($serverVersion) && !is_string($serverVersion))
            || 1 !== preg_match('/\A[0-9]+\z/', (string) $serverVersion)
        ) {
            yield new CapabilityEvidence(
                HostingCapability::PostgreSqlServer18,
                CapabilityStatus::NotAvailable,
                self::class,
                'Die PostgreSQL-Serverversion konnte nicht zuverlässig gelesen werden.',
                $checkedAt,
            );

            return;
        }

        $majorVersion = intdiv((int) $serverVersion, 10000);
        $isSupported = 18 === $majorVersion;

        yield new CapabilityEvidence(
            HostingCapability::PostgreSqlServer18,
            $isSupported ? CapabilityStatus::Verified : CapabilityStatus::Failed,
            self::class,
            $isSupported
                ? 'Die aktive PostgreSQL-Verbindung meldet die geforderte Hauptversion 18.'
                : 'Die aktive PostgreSQL-Verbindung erfüllt die Projektvorgabe PostgreSQL 18 nicht.',
            $checkedAt,
        );
    }
}
