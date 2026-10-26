<?php

declare(strict_types=1);

namespace App\Module\Advertising\Infrastructure;

use App\Module\Advertising\Application\StatisticsStore;
use App\Module\Advertising\Domain\AdStatistics;
use Doctrine\DBAL\Connection;

final readonly class DoctrineStatisticsStore implements StatisticsStore
{
    private const int MAXIMUM_CLICKS_PER_AD_PER_MINUTE = 600;

    public function __construct(private Connection $connection)
    {
    }

    public function recordImpression(string $advertisementId, string $placementId, \DateTimeImmutable $occurredAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cms_advertising_daily_stats (day, advertisement_id, placement_id, impressions, clicks) '
            .'VALUES (:day, :advertisement_id, :placement_id, 1, 0) '
            .'ON CONFLICT (day, advertisement_id) DO UPDATE SET impressions = cms_advertising_daily_stats.impressions + 1',
            ['day' => $occurredAt->format('Y-m-d'), 'advertisement_id' => $advertisementId, 'placement_id' => $placementId],
        );
    }

    public function recordClick(string $advertisementId, string $placementId, string $eventDigest, \DateTimeImmutable $occurredAt): bool
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $eventDigest)) {
            throw new \InvalidArgumentException('Click event digest is invalid.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($advertisementId, $placementId, $eventDigest, $occurredAt): bool {
            $connection->executeStatement('DELETE FROM cms_advertising_click_dedupe WHERE expires_at < :now', ['now' => $occurredAt->format('Y-m-d H:i:sP')]);
            $inserted = $connection->executeStatement(
                'INSERT INTO cms_advertising_click_dedupe (event_digest, expires_at) VALUES (:digest, :expires_at) ON CONFLICT (event_digest) DO NOTHING',
                ['digest' => $eventDigest, 'expires_at' => $occurredAt->modify('+1 day')->format('Y-m-d H:i:sP')],
            );
            if (0 === $inserted) {
                return false;
            }

            $minute = $occurredAt->setTime((int) $occurredAt->format('H'), (int) $occurredAt->format('i'), 0)->format('Y-m-d H:i:sP');
            $rateAccepted = $connection->executeStatement(
                'INSERT INTO cms_advertising_click_window (advertisement_id, minute_at, click_count) VALUES (:advertisement_id, :minute_at, 1) '
                .'ON CONFLICT (advertisement_id, minute_at) DO UPDATE SET click_count = cms_advertising_click_window.click_count + 1 '
                .'WHERE cms_advertising_click_window.click_count < :maximum',
                ['advertisement_id' => $advertisementId, 'minute_at' => $minute, 'maximum' => self::MAXIMUM_CLICKS_PER_AD_PER_MINUTE],
            );
            if (0 === $rateAccepted) {
                $connection->executeStatement('DELETE FROM cms_advertising_click_window WHERE minute_at < :cutoff', ['cutoff' => $occurredAt->modify('-5 minutes')->format('Y-m-d H:i:sP')]);

                return false;
            }

            $connection->executeStatement(
                'INSERT INTO cms_advertising_daily_stats (day, advertisement_id, placement_id, impressions, clicks) '
                .'VALUES (:day, :advertisement_id, :placement_id, 0, 1) '
                .'ON CONFLICT (day, advertisement_id) DO UPDATE SET clicks = cms_advertising_daily_stats.clicks + 1',
                ['day' => $occurredAt->format('Y-m-d'), 'advertisement_id' => $advertisementId, 'placement_id' => $placementId],
            );
            $connection->executeStatement('DELETE FROM cms_advertising_click_window WHERE minute_at < :cutoff', ['cutoff' => $occurredAt->modify('-5 minutes')->format('Y-m-d H:i:sP')]);

            return true;
        });
    }

    public function aggregate(\DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT advertisement_id, placement_id, day, SUM(impressions) AS impressions, SUM(clicks) AS clicks '
            .'FROM cms_advertising_daily_stats WHERE day BETWEEN :from AND :through '
            .'GROUP BY advertisement_id, placement_id, day ORDER BY day DESC, advertisement_id',
            ['from' => $from->format('Y-m-d'), 'through' => $through->format('Y-m-d')],
        );

        return array_map(static fn (array $row): AdStatistics => new AdStatistics(
            self::stringColumn($row, 'advertisement_id'),
            self::stringColumn($row, 'placement_id'),
            self::stringColumn($row, 'day'),
            self::countColumn($row, 'impressions'),
            self::countColumn($row, 'clicks'),
        ), $rows);
    }

    /** @param array<string, mixed> $row */
    private static function stringColumn(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Aggregated advertising statistics are invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function countColumn(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && 1 === preg_match('/\A[0-9]+\z/D', $value)) {
            return (int) $value;
        }

        throw new \InvalidArgumentException('Aggregated advertising statistics are invalid.');
    }
}
