<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ContentCategoryType;
use Doctrine\DBAL\Connection;

final readonly class ContentCategoryIdentityLock
{
    private const string LOCK_PREFIX = 'arcanorum:content-category:';

    public function lock(Connection $connection, ContentCategoryType $contentType, string $name, string $slug): void
    {
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Kategorie-Identitätssperren benötigen eine aktive Datenbanktransaktion.');
        }

        $name = trim($name);
        if (1 !== preg_match('//u', $name)
            || '' === $name
            || mb_strlen($name, 'UTF-8') > 100
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $name)
        ) {
            throw new \InvalidArgumentException('Der zu sperrende Kategoriename ist ungültig.');
        }

        if (1 !== preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug)
            || mb_strlen($slug, 'UTF-8') > 120
        ) {
            throw new \InvalidArgumentException('Der zu sperrende Kategorien-Slug ist ungültig.');
        }

        $lockKeys = [
            self::LOCK_PREFIX.'name:'.$contentType->value.':'.mb_strtolower($name, 'UTF-8'),
            self::LOCK_PREFIX.'slug:'.$contentType->value.':'.$slug,
        ];
        sort($lockKeys, SORT_STRING);

        foreach ($lockKeys as $lockKey) {
            $connection->fetchOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(:identity_key, 0))',
                ['identity_key' => $lockKey],
            );
        }
    }
}
