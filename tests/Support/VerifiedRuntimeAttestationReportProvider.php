<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Platform\Security\RuntimeAttestationReport;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\RuntimeAttestationStatus;

final class VerifiedRuntimeAttestationReportProvider implements RuntimeAttestationReportProviderInterface
{
    private bool $unknown = false;

    public function reportUnknown(): void
    {
        $this->unknown = true;
    }

    public function reportVerified(): void
    {
        $this->unknown = false;
    }

    public function verifyCurrent(): RuntimeAttestationReport
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $status = $this->unknown ? RuntimeAttestationStatus::Unknown : RuntimeAttestationStatus::Verified;
        $reason = $this->unknown
            ? 'Nur-Testfixture; Laufzeitattestierung unbekannt.'
            : 'Nur-Testfixture; kein Laufzeitnachweis.';

        return new RuntimeAttestationReport(
            $status,
            [
                'INV-AUTH-001' => $status,
                'INV-CSRF-001' => $status,
                'INV-SESSION-001' => $status,
                'INV-APP-INTEGRITY-001' => $status,
                'INV-WEBSERVER-CONFIG-001' => $status,
                'INV-INPUT-OUTPUT-001' => $status,
                'INV-TRUSTED-PROXY-001' => $status,
            ],
            $reason,
            $now,
            $now->add(new \DateInterval('PT60S')),
        );
    }
}
