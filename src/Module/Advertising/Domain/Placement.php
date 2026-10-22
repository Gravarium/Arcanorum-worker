<?php

declare(strict_types=1);

namespace App\Module\Advertising\Domain;

final readonly class Placement
{
    /**
     * @param list<AdFormat> $formats
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $formats,
        public bool $archived = false,
    ) {
        if (1 !== preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?\z/D', $id)) {
            throw new \InvalidArgumentException('Placement id must be a lowercase URL-safe slug.');
        }

        if ('' === trim($label) || !mb_check_encoding($label, 'UTF-8') || mb_strlen($label) > 120) {
            throw new \InvalidArgumentException('Placement label must contain 1 to 120 characters.');
        }

        if ([] === $formats || count(array_unique(array_map(static fn (AdFormat $format): string => $format->value, $formats))) !== count($formats)) {
            throw new \InvalidArgumentException('A placement must support at least one unique advertising format.');
        }
    }

    public function supports(AdFormat $format): bool
    {
        return in_array($format, $this->formats, true);
    }

    /**
     * @param list<AdFormat> $formats
     */
    public function revise(string $label, array $formats): self
    {
        return new self($this->id, $label, $formats, $this->archived);
    }

    public function archive(): self
    {
        return new self($this->id, $this->label, $this->formats, true);
    }
}
