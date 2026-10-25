<?php

declare(strict_types=1);

namespace App\Platform\Security;

final readonly class RuntimeAttestationActionGuard
{
    public function requireVerified(RuntimeAttestationReport $report, string ...$requiredControlIds): void
    {
        if ([] === $requiredControlIds) {
            throw new \InvalidArgumentException('Die Aktionssperre benötigt mindestens eine Kontrollkennung.');
        }

        $requiredControlIds = array_values($requiredControlIds);
        $seen = [];
        $status = RuntimeAttestationStatus::Verified;
        $hasFreshEvidence = $report->hasFreshEvidenceAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        foreach ($requiredControlIds as $controlId) {
            if (1 !== preg_match('/\A[A-Z][A-Z0-9-]{0,63}\z/D', $controlId) || isset($seen[$controlId])) {
                throw new \InvalidArgumentException('Die Aktionssperre enthält eine ungültige Kontrollliste.');
            }

            $seen[$controlId] = true;
            $controlStatus = $report->controlStatuses[$controlId] ?? RuntimeAttestationStatus::Unknown;

            if (!$hasFreshEvidence) {
                $status = RuntimeAttestationStatus::Unknown;

                continue;
            }

            if (RuntimeAttestationStatus::Failed === $controlStatus) {
                throw new RuntimeAttestationUnavailable(RuntimeAttestationStatus::Failed, $requiredControlIds);
            }

            if (RuntimeAttestationStatus::Unknown === $controlStatus) {
                $status = RuntimeAttestationStatus::Unknown;

                continue;
            }

            if (RuntimeAttestationStatus::NotAvailable === $controlStatus && RuntimeAttestationStatus::Verified === $status) {
                $status = RuntimeAttestationStatus::NotAvailable;
            }
        }

        if (RuntimeAttestationStatus::Verified !== $status) {
            throw new RuntimeAttestationUnavailable($status, $requiredControlIds);
        }
    }
}
