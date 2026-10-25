<?php

declare(strict_types=1);

namespace App\Tests\Platform\Hosting;

use App\Platform\Hosting\CapabilityEvidence;
use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingCapabilityGuard;
use App\Platform\Hosting\HostingCapabilityReport;
use App\Platform\Hosting\HostingCapabilityUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostingCapabilityGuardTest extends TestCase
{
    public function testVerifiedEvidenceIsAllowed(): void
    {
        $capability = HostingCapability::PrivateStorage;
        $evidence = $this->evidence(
            $capability,
            CapabilityStatus::Verified,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        new HostingCapabilityGuard()->requireVerifiedEvidence($evidence, $capability);

        self::assertSame(CapabilityStatus::Verified, $evidence->status);
    }

    public function testVerifiedEvidenceUsesInclusiveSixtySecondFreshnessWindow(): void
    {
        $capability = HostingCapability::PrivateStorage;
        $checkedAt = new \DateTimeImmutable('2035-01-01T00:00:00.000000+00:00');
        $evidence = $this->evidence($capability, CapabilityStatus::Verified, $checkedAt);
        $expiresAt = $checkedAt->add(new \DateInterval('PT60S'));

        self::assertSame(
            CapabilityStatus::Unknown,
            $evidence->effectiveStatusAt($checkedAt->modify('-1 microsecond')),
        );
        self::assertSame(CapabilityStatus::Verified, $evidence->effectiveStatusAt($checkedAt));
        self::assertSame(CapabilityStatus::Verified, $evidence->effectiveStatusAt($expiresAt));
        self::assertSame(
            CapabilityStatus::Unknown,
            $evidence->effectiveStatusAt($expiresAt->modify('+1 microsecond')),
        );
    }

    public function testExpiredVerifiedEvidenceIsRejectedAsUnknown(): void
    {
        $capability = HostingCapability::PrivateStorage;
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $evidence = $this->evidence(
            $capability,
            CapabilityStatus::Verified,
            $checkedAt->sub(new \DateInterval('PT61S')),
        );

        $this->expectCapabilityUnavailable(
            fn () => new HostingCapabilityGuard()->requireVerifiedEvidence($evidence, $capability),
            $capability,
            CapabilityStatus::Unknown,
        );
    }

    public function testFutureDatedVerifiedEvidenceIsRejectedAsUnknown(): void
    {
        $capability = HostingCapability::PrivateStorage;
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $evidence = $this->evidence(
            $capability,
            CapabilityStatus::Verified,
            $checkedAt->add(new \DateInterval('PT1S')),
        );

        $this->expectCapabilityUnavailable(
            fn () => new HostingCapabilityGuard()->requireVerifiedEvidence($evidence, $capability),
            $capability,
            CapabilityStatus::Unknown,
        );
    }

    #[DataProvider('unverifiedStatuses')]
    public function testUnverifiedEvidenceIsRejectedWithoutExposingDetails(CapabilityStatus $status): void
    {
        $capability = HostingCapability::PrivateStorage;
        $details = 'Vertraulicher Pfad: /srv/private/production';
        $evidence = $this->evidence(
            $capability,
            $status,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $details,
        );

        try {
            new HostingCapabilityGuard()->requireVerifiedEvidence($evidence, $capability);
            self::fail('Nicht bestätigte Prüfnachweise müssen gesperrt werden.');
        } catch (HostingCapabilityUnavailable $exception) {
            self::assertSame($capability, $exception->capability);
            self::assertSame($status, $exception->status);
            self::assertStringNotContainsString($details, $exception->getMessage());
        }
    }

    public function testEvidenceForDifferentCapabilityIsRejectedAsUnknown(): void
    {
        $evidence = $this->evidence(
            HostingCapability::RedisServer8,
            CapabilityStatus::Verified,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'Redis ist erreichbar.',
        );

        $this->expectCapabilityUnavailable(
            fn () => new HostingCapabilityGuard()->requireVerifiedEvidence($evidence, HostingCapability::PrivateStorage),
            HostingCapability::PrivateStorage,
            CapabilityStatus::Unknown,
        );
    }

    public function testVerifiedCapabilityIsAllowed(): void
    {
        $capability = HostingCapability::PrivateStorage;
        $report = $this->reportFor($capability, CapabilityStatus::Verified, 'Prüfung erfolgreich.');

        new HostingCapabilityGuard()->requireVerified($report, $capability);

        self::assertTrue($report->supports($capability));
    }

    public function testStaleVerifiedCapabilityIsRejectedAsUnknown(): void
    {
        $capability = HostingCapability::PrivateStorage;
        $report = $this->reportFor(
            $capability,
            CapabilityStatus::Verified,
            'Prüfung erfolgreich.',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->sub(new \DateInterval('PT61S')),
        );

        self::assertSame(CapabilityStatus::Unknown, $report->statusFor($capability));
        self::assertFalse($report->supports($capability));

        $this->expectCapabilityUnavailable(
            fn () => new HostingCapabilityGuard()->requireVerified($report, $capability),
            $capability,
            CapabilityStatus::Unknown,
        );
    }

    public function testAllExposesExpiredVerifiedEvidenceAsUnknown(): void
    {
        $capability = HostingCapability::PrivateStorage;
        $report = $this->reportFor(
            $capability,
            CapabilityStatus::Verified,
            'Prüfung erfolgreich.',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->sub(new \DateInterval('PT61S')),
        );

        $matchingEvidence = array_first(array_filter(
            $report->all(),
            static fn (CapabilityEvidence $evidence): bool => $evidence->capability === $capability,
        ));

        self::assertInstanceOf(CapabilityEvidence::class, $matchingEvidence);
        self::assertSame(CapabilityStatus::Unknown, $matchingEvidence->status);
    }

    #[DataProvider('unverifiedStatuses')]
    public function testUnverifiedCapabilityIsRejectedWithoutExposingEvidence(
        CapabilityStatus $status,
    ): void {
        $capability = HostingCapability::PrivateStorage;
        $details = 'Vertraulicher Pfad: /srv/private/production';
        $report = $this->reportFor($capability, $status, $details);

        try {
            new HostingCapabilityGuard()->requireVerified($report, $capability);
            self::fail('Nicht bestätigte Fähigkeiten müssen gesperrt werden.');
        } catch (HostingCapabilityUnavailable $exception) {
            self::assertSame($capability, $exception->capability);
            self::assertSame($status, $exception->status);
            self::assertStringNotContainsString($details, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{CapabilityStatus}>
     */
    public static function unverifiedStatuses(): iterable
    {
        yield 'failed' => [CapabilityStatus::Failed];
        yield 'not available' => [CapabilityStatus::NotAvailable];
        yield 'unknown' => [CapabilityStatus::Unknown];
    }

    private function reportFor(
        HostingCapability $target,
        CapabilityStatus $status,
        string $details,
        ?\DateTimeImmutable $checkedAt = null,
    ): HostingCapabilityReport {
        $checkedAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $checks = [];

        foreach (HostingCapability::cases() as $capability) {
            $isTarget = $capability === $target;
            $checks[$capability->value] = $this->evidence(
                $capability,
                $isTarget ? $status : CapabilityStatus::Unknown,
                $checkedAt,
                $isTarget ? $details : 'Für diese Fähigkeit liegt kein Prüfnachweis vor.',
            );
        }

        return new HostingCapabilityReport($checks);
    }

    private function evidence(
        HostingCapability $capability,
        CapabilityStatus $status,
        \DateTimeImmutable $checkedAt,
        string $details = 'Prüfung erfolgreich.',
    ): CapabilityEvidence {
        return new CapabilityEvidence($capability, $status, self::class, $details, $checkedAt);
    }

    private function expectCapabilityUnavailable(
        callable $action,
        HostingCapability $capability,
        CapabilityStatus $status,
    ): void {
        try {
            $action();
            self::fail('Der nicht frische oder unpassende Nachweis muss gesperrt werden.');
        } catch (HostingCapabilityUnavailable $exception) {
            self::assertSame($capability, $exception->capability);
            self::assertSame($status, $exception->status);
        }
    }
}
