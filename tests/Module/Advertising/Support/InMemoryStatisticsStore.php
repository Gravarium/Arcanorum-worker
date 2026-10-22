<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Support;

use App\Module\Advertising\Application\StatisticsStore;
use App\Module\Advertising\Domain\AdStatistics;

final class InMemoryStatisticsStore implements StatisticsStore
{
    /** @var array<string, array{placement: string, day: string, impressions: int, clicks: int}> */
    private array $totals = [];

    /** @var array<string, true> */
    private array $seenEventDigests = [];

    /** @var array<string, int> */
    private array $minuteClicks = [];

    public function recordImpression(string $advertisementId, string $placementId, \DateTimeImmutable $occurredAt): void
    {
        $key = $advertisementId.'|'.$occurredAt->format('Y-m-d');
        $this->totals[$key] ??= ['placement' => $placementId, 'day' => $occurredAt->format('Y-m-d'), 'impressions' => 0, 'clicks' => 0];
        ++$this->totals[$key]['impressions'];
    }

    public function recordClick(string $advertisementId, string $placementId, string $eventDigest, \DateTimeImmutable $occurredAt): bool
    {
        if (isset($this->seenEventDigests[$eventDigest])) {
            return false;
        }

        $minuteKey = $advertisementId.'|'.$occurredAt->format('Y-m-d H:i');
        if (($this->minuteClicks[$minuteKey] ?? 0) >= 600) {
            return false;
        }

        $this->seenEventDigests[$eventDigest] = true;
        $this->minuteClicks[$minuteKey] = ($this->minuteClicks[$minuteKey] ?? 0) + 1;
        $key = $advertisementId.'|'.$occurredAt->format('Y-m-d');
        $this->totals[$key] ??= ['placement' => $placementId, 'day' => $occurredAt->format('Y-m-d'), 'impressions' => 0, 'clicks' => 0];
        ++$this->totals[$key]['clicks'];

        return true;
    }

    public function aggregate(\DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $rows = [];
        foreach ($this->totals as $key => $row) {
            if ($row['day'] >= $from->format('Y-m-d') && $row['day'] <= $through->format('Y-m-d')) {
                [$advertisementId] = explode('|', $key, 2);
                $rows[] = new AdStatistics($advertisementId, $row['placement'], $row['day'], $row['impressions'], $row['clicks']);
            }
        }

        return $rows;
    }
}
