<?php

declare(strict_types=1);

namespace App\Module\Advertising\Domain;

final readonly class AdStatistics
{
    public function __construct(
        public string $advertisementId,
        public string $placementId,
        public string $day,
        public int $impressions,
        public int $clicks,
    ) {
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/D', $advertisementId)
            || 1 !== preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?\z/D', $placementId)
            || 1 !== preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $day)
            || $impressions < 0
            || $clicks < 0
        ) {
            throw new \InvalidArgumentException('Advertising statistics row is invalid.');
        }
    }
}
