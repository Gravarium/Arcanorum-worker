<?php

declare(strict_types=1);

namespace App\Tests\Platform\Hosting;

use App\Platform\Hosting\CapabilityEvidence;
use App\Platform\Hosting\CapabilityProbeInterface;
use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingProfileDetector;
use App\Platform\Hosting\RuntimeCapabilityProbe;
use PHPUnit\Framework\TestCase;

final class HostingProfileDetectorTest extends TestCase
{
    public function testUnprovenHostingBoundariesRemainUnknownAndUnavailable(): void
    {
        $report = new HostingProfileDetector([])->detect();

        self::assertSame(CapabilityStatus::Unknown, $report->statusFor(HostingCapability::PrivateStorage));
        self::assertFalse($report->supports(HostingCapability::PrivateStorage));
        self::assertSame(CapabilityStatus::Unknown, $report->statusFor(HostingCapability::DatabaseRoleSeparation));
        self::assertFalse($report->supports(HostingCapability::DatabaseRoleSeparation));
    }

    public function testRuntimeProbeVerifiesOnlyRequiredRuntimeFacts(): void
    {
        $report = new HostingProfileDetector([new RuntimeCapabilityProbe()])->detect();

        self::assertSame(CapabilityStatus::Verified, $report->statusFor(HostingCapability::PhpRuntime));
        self::assertTrue($report->supports(HostingCapability::PdoPgsqlExtension));
        self::assertSame(CapabilityStatus::Unknown, $report->statusFor(HostingCapability::ProcessIsolation));
        self::assertFalse($report->supports(HostingCapability::ProcessIsolation));
    }

    public function testDuplicateEvidenceFailsClosed(): void
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $verified = new CapabilityEvidence(
            HostingCapability::PrivateStorage,
            CapabilityStatus::Verified,
            'test-probe-a',
            'Private storage was checked.',
            $checkedAt,
        );
        $unavailable = new CapabilityEvidence(
            HostingCapability::PrivateStorage,
            CapabilityStatus::NotAvailable,
            'test-probe-b',
            'Private storage is not available.',
            $checkedAt,
        );

        $report = new HostingProfileDetector([
            $this->probeWith([$verified]),
            $this->probeWith([$unavailable]),
        ])->detect();

        self::assertSame(CapabilityStatus::Failed, $report->statusFor(HostingCapability::PrivateStorage));
        self::assertFalse($report->supports(HostingCapability::PrivateStorage));
    }

    /**
     * @param list<CapabilityEvidence> $evidence
     */
    private function probeWith(array $evidence): CapabilityProbeInterface
    {
        return new readonly class($evidence) implements CapabilityProbeInterface {
            /**
             * @param list<CapabilityEvidence> $evidence
             */
            public function __construct(private array $evidence)
            {
            }

            /**
             * @return iterable<CapabilityEvidence>
             */
            public function probe(): iterable
            {
                return $this->evidence;
            }
        };
    }
}
