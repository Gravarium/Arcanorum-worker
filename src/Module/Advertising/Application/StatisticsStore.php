<?php

declare(strict_types=1);

namespace App\Module\Advertising\Application;

use App\Module\Advertising\Domain\AdStatistics;

interface StatisticsStore
{
    public function recordImpression(string $advertisementId, string $placementId, \DateTimeImmutable $occurredAt): void;

    /**
     * Records one click when it is not a duplicate and stays within the aggregate rate limit.
     * The event digest is a one-use random token digest, never a person or network identifier.
     */
    public function recordClick(string $advertisementId, string $placementId, string $eventDigest, \DateTimeImmutable $occurredAt): bool;

    /** @return list<AdStatistics> */
    public function aggregate(\DateTimeImmutable $from, \DateTimeImmutable $through): array;
}
