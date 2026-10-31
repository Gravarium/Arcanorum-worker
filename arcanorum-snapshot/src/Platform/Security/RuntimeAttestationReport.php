<?php

declare(strict_types=1);

namespace App\Platform\Security;

final readonly class RuntimeAttestationReport
{
    public const int MAX_FRESHNESS_SECONDS = 60;

    /** @var array<string, RuntimeAttestationStatus> */
    public array $controlStatuses;

    /**
     * @param array<array-key, mixed> $controlStatuses
     */
    public function __construct(
        public RuntimeAttestationStatus $status,
        array $controlStatuses,
        public string $reason,
        public ?\DateTimeImmutable $checkedAt = null,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
        $validatedControlStatuses = [];
        foreach ($controlStatuses as $controlId => $controlStatus) {
            if (!is_string($controlId) || !($controlStatus instanceof RuntimeAttestationStatus)) {
                throw new \InvalidArgumentException('Der Attestierungsbericht enthält einen ungültigen Kontrollstatus.');
            }

            $validatedControlStatuses[$controlId] = $controlStatus;
        }

        $this->controlStatuses = $validatedControlStatuses;
    }

    public function allows(string $controlId): bool
    {
        return RuntimeAttestationStatus::Verified === ($this->controlStatuses[$controlId] ?? RuntimeAttestationStatus::Unknown)
            && $this->hasFreshEvidenceAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    public function hasFreshEvidenceAt(\DateTimeImmutable $now): bool
    {
        if (null === $this->checkedAt || null === $this->expiresAt) {
            return false;
        }

        $oldestAllowed = $now->sub(new \DateInterval('PT'.self::MAX_FRESHNESS_SECONDS.'S'));
        $latestAllowedExpiry = $this->checkedAt->add(new \DateInterval('PT'.self::MAX_FRESHNESS_SECONDS.'S'));

        return $this->checkedAt >= $oldestAllowed
            && $this->checkedAt <= $now
            && $this->expiresAt > $now
            && $this->expiresAt <= $latestAllowedExpiry;
    }
}
