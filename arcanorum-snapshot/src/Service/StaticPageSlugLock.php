<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final readonly class StaticPageSlugLock
{
    private const string LOCK_PREFIX = 'arcanorum:static-page-slug:';

    public function lock(Connection $connection, string $slug): void
    {
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Seiten-Permalinksperren benötigen eine aktive Datenbanktransaktion.');
        }

        if (strlen($slug) > 200 || 1 !== preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug)) {
            throw new \InvalidArgumentException('Der zu sperrende Seiten-Permalink ist ungültig.');
        }

        $connection->fetchOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(:slug_key, 0))',
            ['slug_key' => self::LOCK_PREFIX.$slug],
        );
    }
}
