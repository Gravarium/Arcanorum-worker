<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;
use Doctrine\DBAL\Connection;

final readonly class VideoSourceUrlLock
{
    private const string LOCK_PREFIX = 'arcanorum:video-source-url:';

    /**
     * Acquire deterministic transaction-scoped locks so concurrent imports of
     * the same external source URL observe each other's committed rows.
     *
     * @param list<string> $sourceUrls
     */
    public function lock(Connection $connection, array $sourceUrls): void
    {
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Videoquellensperren benötigen eine aktive Datenbanktransaktion.');
        }

        $sourceUrls = array_values(array_unique($sourceUrls));
        foreach ($sourceUrls as $sourceUrl) {
            if (!Video::isSafeHttpsSource($sourceUrl)) {
                throw new \InvalidArgumentException('Die zu sperrende Videoquelle ist ungültig.');
            }
        }

        sort($sourceUrls, SORT_STRING);
        foreach ($sourceUrls as $sourceUrl) {
            $connection->fetchOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(:source_key, 0))',
                ['source_key' => self::LOCK_PREFIX.$sourceUrl],
            );
        }
    }
}
