<?php

declare(strict_types=1);

namespace App\Tests\Platform\Hosting;

use App\Platform\Hosting\CapabilityEvidence;
use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingCapabilityReport;
use PHPUnit\Framework\TestCase;

final class HostingCapabilityReportTest extends TestCase
{
    public function testMissingCapabilityEvidenceIsRejected(): void
    {
        $checks = $this->unknownChecks();
        unset($checks[HostingCapability::PrivateStorage->value]);

        $this->expectException(\InvalidArgumentException::class);

        new HostingCapabilityReport($checks);
    }

    public function testEvidenceStoredUnderTheWrongCapabilityIsRejected(): void
    {
        $checks = $this->unknownChecks();
        $checks[HostingCapability::PrivateStorage->value] = new CapabilityEvidence(
            HostingCapability::RedisServer8,
            CapabilityStatus::Unknown,
            self::class,
            'Kein Prüfnachweis.',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $this->expectException(\InvalidArgumentException::class);

        new HostingCapabilityReport($checks);
    }

    public function testUnknownCapabilityEntryIsRejected(): void
    {
        $checks = $this->unknownChecks();
        $checks['UNKNOWN_CAPABILITY'] = $checks[HostingCapability::PrivateStorage->value];

        $this->expectException(\InvalidArgumentException::class);

        new HostingCapabilityReport($checks);
    }

    /**
     * @return array<string, CapabilityEvidence>
     */
    private function unknownChecks(): array
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $checks = [];

        foreach (HostingCapability::cases() as $capability) {
            $checks[$capability->value] = new CapabilityEvidence(
                $capability,
                CapabilityStatus::Unknown,
                self::class,
                'Für diese Fähigkeit liegt kein Prüfnachweis vor.',
                $checkedAt,
            );
        }

        return $checks;
    }
}
