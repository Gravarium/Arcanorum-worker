<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\RuntimeAttestationActionGuard;
use App\Platform\Security\RuntimeAttestationReport;
use App\Platform\Security\RuntimeAttestationStatus;
use App\Platform\Security\RuntimeAttestationUnavailable;
use PHPUnit\Framework\TestCase;

final class RuntimeAttestationActionGuardTest extends TestCase
{
    private const string AUTH_CONTROL = 'INV-AUTH-001';

    private const string CONFIG_CONTROL = 'INV-WEBSERVER-CONFIG-001';

    public function testActionIsAllowedWhenEveryRequiredControlIsVerified(): void
    {
        $report = $this->report([
            self::AUTH_CONTROL => RuntimeAttestationStatus::Verified,
            self::CONFIG_CONTROL => RuntimeAttestationStatus::Verified,
        ]);

        new RuntimeAttestationActionGuard()->requireVerified(
            $report,
            self::AUTH_CONTROL,
            self::CONFIG_CONTROL,
        );

        self::assertTrue($report->allows(self::AUTH_CONTROL));
    }

    public function testUnknownRequiredControlIsRejected(): void
    {
        $this->expectUnavailable(
            $this->report([self::AUTH_CONTROL => RuntimeAttestationStatus::Unknown]),
            RuntimeAttestationStatus::Unknown,
            self::AUTH_CONTROL,
        );
    }

    public function testMissingRequiredControlIsRejectedAsUnknown(): void
    {
        $this->expectUnavailable(
            $this->report([]),
            RuntimeAttestationStatus::Unknown,
            self::AUTH_CONTROL,
        );
    }

    public function testFailedRequiredControlTakesPrecedenceOverUnknown(): void
    {
        $this->expectUnavailable(
            $this->report([
                self::AUTH_CONTROL => RuntimeAttestationStatus::Unknown,
                self::CONFIG_CONTROL => RuntimeAttestationStatus::Failed,
            ]),
            RuntimeAttestationStatus::Failed,
            self::AUTH_CONTROL,
            self::CONFIG_CONTROL,
        );
    }

    public function testMalformedRuntimeControlStatusIsRejectedWhenReportIsCreated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new \ReflectionClass(RuntimeAttestationReport::class)->newInstanceArgs([
            RuntimeAttestationStatus::Verified,
            [self::AUTH_CONTROL => 'verified'],
            'malformed_control_status',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('+60 seconds', new \DateTimeZone('UTC')),
        ]);
    }

    public function testNotAvailableRequiredControlIsRejected(): void
    {
        $this->expectUnavailable(
            $this->report([self::AUTH_CONTROL => RuntimeAttestationStatus::NotAvailable]),
            RuntimeAttestationStatus::NotAvailable,
            self::AUTH_CONTROL,
        );
    }

    public function testNotAvailableUnrelatedControlDoesNotBlockIndependentlyVerifiedAction(): void
    {
        $report = $this->report([
            self::AUTH_CONTROL => RuntimeAttestationStatus::Verified,
            self::CONFIG_CONTROL => RuntimeAttestationStatus::NotAvailable,
        ]);

        new RuntimeAttestationActionGuard()->requireVerified($report, self::AUTH_CONTROL);

        self::assertTrue($report->allows(self::AUTH_CONTROL));
    }

    public function testFailureOfUnrelatedControlDoesNotBlockIndependentlyVerifiedAction(): void
    {
        $report = $this->report([
            self::AUTH_CONTROL => RuntimeAttestationStatus::Verified,
            self::CONFIG_CONTROL => RuntimeAttestationStatus::Failed,
        ]);

        new RuntimeAttestationActionGuard()->requireVerified($report, self::AUTH_CONTROL);

        self::assertTrue($report->allows(self::AUTH_CONTROL));
    }

    public function testExceptionDoesNotExposeAttestationReason(): void
    {
        $sensitiveReason = 'Interner Monitorpfad: /srv/attestor/private';
        $report = new RuntimeAttestationReport(
            RuntimeAttestationStatus::Failed,
            [self::AUTH_CONTROL => RuntimeAttestationStatus::Failed],
            $sensitiveReason,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        try {
            new RuntimeAttestationActionGuard()->requireVerified($report, self::AUTH_CONTROL);
            self::fail('Eine fehlgeschlagene Pflichtkontrolle muss die Aktion sperren.');
        } catch (RuntimeAttestationUnavailable $exception) {
            self::assertStringNotContainsString($sensitiveReason, $exception->getMessage());
            self::assertSame([self::AUTH_CONTROL], $exception->requiredControlIds);
        }
    }

    public function testStaleVerifiedReportCannotReleaseAnAction(): void
    {
        $report = new RuntimeAttestationReport(
            RuntimeAttestationStatus::Verified,
            [self::AUTH_CONTROL => RuntimeAttestationStatus::Verified],
            'stale_report',
            new \DateTimeImmutable('-61 seconds', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('+1 minute', new \DateTimeZone('UTC')),
        );

        $this->expectUnavailable($report, RuntimeAttestationStatus::Unknown, self::AUTH_CONTROL);
        self::assertFalse($report->allows(self::AUTH_CONTROL));
    }

    public function testExpiredFailedAttestationIsReportedAsUnknownAndCannotReleaseAnAction(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $report = new RuntimeAttestationReport(
            RuntimeAttestationStatus::Failed,
            [self::AUTH_CONTROL => RuntimeAttestationStatus::Failed],
            'expired_failure',
            $now->sub(new \DateInterval('PT61S')),
            $now->sub(new \DateInterval('PT1S')),
        );

        $this->expectUnavailable($report, RuntimeAttestationStatus::Unknown, self::AUTH_CONTROL);
        self::assertFalse($report->allows(self::AUTH_CONTROL));
    }

    public function testFutureDatedVerifiedReportCannotReleaseAnAction(): void
    {
        $report = new RuntimeAttestationReport(
            RuntimeAttestationStatus::Verified,
            [self::AUTH_CONTROL => RuntimeAttestationStatus::Verified],
            'future_report',
            new \DateTimeImmutable('+1 minute', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('+2 minutes', new \DateTimeZone('UTC')),
        );

        $this->expectUnavailable($report, RuntimeAttestationStatus::Unknown, self::AUTH_CONTROL);
        self::assertFalse($report->allows(self::AUTH_CONTROL));
    }

    public function testExpiredSignedAttestationCannotReleaseAnActionAfterRecentVerification(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $report = new RuntimeAttestationReport(
            RuntimeAttestationStatus::Verified,
            [self::AUTH_CONTROL => RuntimeAttestationStatus::Verified],
            'expired_attestation',
            $now->sub(new \DateInterval('PT10S')),
            $now->sub(new \DateInterval('PT1S')),
        );

        $this->expectUnavailable($report, RuntimeAttestationStatus::Unknown, self::AUTH_CONTROL);
        self::assertFalse($report->allows(self::AUTH_CONTROL));
    }

    public function testAttestationCannotOutliveSixtySecondsFromVerificationTime(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $report = new RuntimeAttestationReport(
            RuntimeAttestationStatus::Verified,
            [self::AUTH_CONTROL => RuntimeAttestationStatus::Verified],
            'attestation_lifetime_too_long',
            $now,
            $now->add(new \DateInterval('PT61S')),
        );

        self::assertFalse($report->hasFreshEvidenceAt($now));
        $this->expectUnavailable($report, RuntimeAttestationStatus::Unknown, self::AUTH_CONTROL);
    }

    public function testVerifiedReportWithoutCheckTimeCannotReleaseAnAction(): void
    {
        $report = new RuntimeAttestationReport(
            RuntimeAttestationStatus::Verified,
            [self::AUTH_CONTROL => RuntimeAttestationStatus::Verified],
            'missing_check_time',
            null,
            new \DateTimeImmutable('+1 minute', new \DateTimeZone('UTC')),
        );

        $this->expectUnavailable($report, RuntimeAttestationStatus::Unknown, self::AUTH_CONTROL);
        self::assertFalse($report->allows(self::AUTH_CONTROL));
    }

    public function testFreshnessRequiresRecentCheckAndAtMostSixtySecondLifetime(): void
    {
        $now = new \DateTimeImmutable('2026-10-11T00:01:00+00:00');
        $expiresAfterNow = $now->add(new \DateInterval('PT1S'));
        $maximumLifetime = new RuntimeAttestationReport(
            RuntimeAttestationStatus::Verified,
            [self::AUTH_CONTROL => RuntimeAttestationStatus::Verified],
            'maximum_lifetime',
            $now->sub(new \DateInterval('PT59S')),
            $expiresAfterNow,
        );
        $overlongLifetime = new RuntimeAttestationReport(
            RuntimeAttestationStatus::Verified,
            [self::AUTH_CONTROL => RuntimeAttestationStatus::Verified],
            'overlong_lifetime',
            $now->sub(new \DateInterval('PT60S')),
            $expiresAfterNow,
        );
        $stale = new RuntimeAttestationReport(
            RuntimeAttestationStatus::Verified,
            [self::AUTH_CONTROL => RuntimeAttestationStatus::Verified],
            'stale',
            $now->sub(new \DateInterval('PT61S')),
            $expiresAfterNow,
        );
        $future = new RuntimeAttestationReport(
            RuntimeAttestationStatus::Verified,
            [self::AUTH_CONTROL => RuntimeAttestationStatus::Verified],
            'future',
            $now->add(new \DateInterval('PT1S')),
            $expiresAfterNow,
        );
        $expiredAtBoundary = new RuntimeAttestationReport(
            RuntimeAttestationStatus::Verified,
            [self::AUTH_CONTROL => RuntimeAttestationStatus::Verified],
            'expired_at_boundary',
            $now->sub(new \DateInterval('PT1S')),
            $now,
        );

        self::assertTrue($maximumLifetime->hasFreshEvidenceAt($now));
        self::assertFalse($overlongLifetime->hasFreshEvidenceAt($now));
        self::assertFalse($stale->hasFreshEvidenceAt($now));
        self::assertFalse($future->hasFreshEvidenceAt($now));
        self::assertFalse($expiredAtBoundary->hasFreshEvidenceAt($now));
    }

    public function testEmptyOrDuplicateControlListIsRejected(): void
    {
        $guard = new RuntimeAttestationActionGuard();
        $report = $this->report([self::AUTH_CONTROL => RuntimeAttestationStatus::Verified]);

        try {
            $guard->requireVerified($report);
            self::fail('Eine leere Kontrollliste darf keine Aktion freigeben.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $guard->requireVerified($report, self::AUTH_CONTROL, self::AUTH_CONTROL);
    }

    /**
     * @param array<string, RuntimeAttestationStatus> $controlStatuses
     */
    private function report(array $controlStatuses): RuntimeAttestationReport
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new RuntimeAttestationReport(
            RuntimeAttestationStatus::Unknown,
            $controlStatuses,
            'test_reason',
            $checkedAt,
            $checkedAt->add(new \DateInterval('PT60S')),
        );
    }

    private function expectUnavailable(
        RuntimeAttestationReport $report,
        RuntimeAttestationStatus $expectedStatus,
        string ...$requiredControlIds,
    ): void {
        try {
            new RuntimeAttestationActionGuard()->requireVerified($report, ...$requiredControlIds);
            self::fail('Eine nicht bestätigte Pflichtkontrolle muss die Aktion sperren.');
        } catch (RuntimeAttestationUnavailable $exception) {
            self::assertSame($expectedStatus, $exception->status);
            self::assertSame($requiredControlIds, $exception->requiredControlIds);
        }
    }
}
