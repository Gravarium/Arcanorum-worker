<?php

declare(strict_types=1);

namespace App\Service;

final class VideoThumbnailImportBudget
{
    public const int MAX_BYTES = 16777216;
    public const float MAX_SECONDS = 20.0;

    private int $transferredBytes = 0;
    private readonly int $startedAt;

    public function __construct(
        private readonly int $maximumBytes = self::MAX_BYTES,
        private readonly float $maximumSeconds = self::MAX_SECONDS,
    ) {
        if ($maximumBytes < 1 || !is_finite($maximumSeconds) || $maximumSeconds <= 0.0) {
            throw new \InvalidArgumentException('Das gemeinsame Importbudget für Vorschaubilder ist ungültig.');
        }

        $this->startedAt = hrtime(true);
    }

    public function remainingBytes(): int
    {
        return max(0, $this->maximumBytes - $this->transferredBytes);
    }

    public function remainingSeconds(float $maximum = self::MAX_SECONDS): float
    {
        $elapsedSeconds = (hrtime(true) - $this->startedAt) / 1_000_000_000;

        return max(0.0, min($maximum, $this->maximumSeconds - $elapsedSeconds));
    }

    public function isExhausted(): bool
    {
        return 0 === $this->remainingBytes() || 0.0 === $this->remainingSeconds();
    }

    public function consumeBytes(int $bytes): void
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Die übertragene Vorschaubildgröße ist ungültig.');
        }

        $this->transferredBytes += $bytes;
        if ($this->transferredBytes > $this->maximumBytes) {
            throw new \InvalidArgumentException('Das gemeinsame Importbudget für Vorschaubilder wurde überschritten.');
        }
        if (0.0 === $this->remainingSeconds()) {
            throw new \InvalidArgumentException('Das gemeinsame Zeitbudget für Vorschaubilder wurde überschritten.');
        }
    }
}
