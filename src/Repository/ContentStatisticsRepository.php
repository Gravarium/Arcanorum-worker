<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

final readonly class ContentStatisticsRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{days: list<array{day: string, videos: int, news: int, comments: int, members: int}>, totals: array{videos: int, news: int, comments: int, members: int}}
     */
    public function createdPerDay(int $days): array
    {
        if (!in_array($days, [7, 30, 90], true)) {
            throw new \InvalidArgumentException('Der Statistikzeitraum ist ungültig.');
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $start = $today->modify(sprintf('-%d days', $days - 1));
        $end = $today->modify('+1 day');
        $series = [];
        foreach (['videos' => 'videos', 'news' => 'news_articles', 'comments' => 'content_comments', 'members' => 'member_accounts'] as $key => $table) {
            $series[$key] = [];
            $rows = $this->connection->fetchAllAssociative(
                sprintf('SELECT DATE(created_at) AS day, COUNT(*) AS total FROM %s WHERE created_at >= :start AND created_at < :end GROUP BY DATE(created_at)', $table),
                ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')],
            );
            foreach ($rows as $row) {
                $day = $row['day'] ?? null;
                $count = $row['total'] ?? null;
                if (!is_string($day) || !(is_int($count) || is_string($count) && ctype_digit($count))) {
                    throw new \RuntimeException('Die Statistikdaten konnten nicht gelesen werden.');
                }
                $series[$key][$day] = (int) $count;
            }
        }

        $totals = ['videos' => 0, 'news' => 0, 'comments' => 0, 'members' => 0];
        $entries = [];
        for ($offset = $days - 1; $offset >= 0; --$offset) {
            $day = $today->modify(sprintf('-%d days', $offset))->format('Y-m-d');
            $entry = [
                'day' => $day,
                'videos' => $series['videos'][$day] ?? 0,
                'news' => $series['news'][$day] ?? 0,
                'comments' => $series['comments'][$day] ?? 0,
                'members' => $series['members'][$day] ?? 0,
            ];
            foreach ($totals as $key => $total) {
                $totals[$key] = $total + $entry[$key];
            }
            $entries[] = $entry;
        }

        return ['days' => array_reverse($entries), 'totals' => $totals];
    }
}
