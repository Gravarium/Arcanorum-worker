<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final readonly class CapabilityEvidence
{
    private const int MAX_VERIFIED_STATUS_AGE_SECONDS = 60;

    public function __construct(
        public HostingCapability $capability,
        public CapabilityStatus $status,
        public string $source,
        public string $details,
        public \DateTimeImmutable $checkedAt,
    ) {
        if ('' === trim($source) || '' === trim($details)) {
            throw new \InvalidArgumentException('Prüfnachweise benötigen Quelle und Beschreibung.');
        }
    }

    public function effectiveStatus(): CapabilityStatus
    {
        return $this->effectiveStatusAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /**
     * Resolves the evidence against a fixed instant for deterministic freshness checks.
     */
    public function effectiveStatusAt(\DateTimeImmutable $now): CapabilityStatus
    {
        if (CapabilityStatus::Verified !== $this->status) {
            return $this->status;
        }

        $expiresAt = $this->checkedAt->add(new \DateInterval('PT'.self::MAX_VERIFIED_STATUS_AGE_SECONDS.'S'));

        return $this->checkedAt > $now || $expiresAt < $now
            ? CapabilityStatus::Unknown
            : CapabilityStatus::Verified;
    }
}
