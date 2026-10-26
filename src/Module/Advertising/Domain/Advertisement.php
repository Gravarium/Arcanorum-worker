<?php

declare(strict_types=1);

namespace App\Module\Advertising\Domain;

final readonly class Advertisement
{
    /**
     * @param array<string, string> $creative
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $placementId,
        public AdFormat $format,
        public array $creative,
        public string $destinationUrl,
        public AdStatus $status,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            throw new \InvalidArgumentException('Advertisement id is invalid.');
        }

        if ('' === trim($label) || !mb_check_encoding($label, 'UTF-8') || mb_strlen($label) > 120) {
            throw new \InvalidArgumentException('Advertisement label must contain 1 to 120 characters.');
        }

        if ($updatedAt < $createdAt) {
            throw new \InvalidArgumentException('Advertisement update time cannot precede creation time.');
        }
    }

    /**
     * @param array<string, string> $creative
     */
    public function revise(string $label, array $creative, string $destinationUrl, \DateTimeImmutable $at): self
    {
        if (in_array($this->status, [AdStatus::Active, AdStatus::Archived], true)) {
            throw new \DomainException('Pause or deactivate an advertisement before editing it.');
        }

        return new self($this->id, $label, $this->placementId, $this->format, $creative, $destinationUrl, $this->status, $this->createdAt, $at);
    }

    public function transitionTo(AdStatus $next, \DateTimeImmutable $at): self
    {
        $allowed = match ($this->status) {
            AdStatus::Draft => [AdStatus::Active, AdStatus::Inactive, AdStatus::Archived],
            AdStatus::Active => [AdStatus::Paused, AdStatus::Inactive, AdStatus::Archived],
            AdStatus::Paused => [AdStatus::Active, AdStatus::Inactive, AdStatus::Archived],
            AdStatus::Inactive => [AdStatus::Active, AdStatus::Archived],
            AdStatus::Archived => [],
        };

        if (!in_array($next, $allowed, true)) {
            throw new \DomainException(sprintf('Transition from %s to %s is not allowed.', $this->status->value, $next->value));
        }

        return new self($this->id, $this->label, $this->placementId, $this->format, $this->creative, $this->destinationUrl, $next, $this->createdAt, $at);
    }
}
