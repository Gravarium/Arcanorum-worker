<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final readonly class ContentTagNameLock
{
    private const string LOCK_PREFIX = 'arcanorum:content-tag:name:';

    /** @param list<string> $names */
    public function lockNames(Connection $connection, array $names): void
    {
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Schlagwortnamensperren benötigen eine aktive Datenbanktransaktion.');
        }

        $lockKeys = [];
        foreach ($names as $name) {
            $name = trim($name);
            if (1 !== preg_match('//u', $name)
                || '' === $name
                || mb_strlen($name, 'UTF-8') > 100
                || 1 === preg_match('/[\x00-\x1F\x7F]/u', $name)
            ) {
                throw new \InvalidArgumentException('Der zu sperrende Schlagwortname ist ungültig.');
            }

            $lockKeys[self::LOCK_PREFIX.mb_strtolower($name, 'UTF-8')] = true;
        }

        $lockKeys = array_keys($lockKeys);
        sort($lockKeys, SORT_STRING);

        foreach ($lockKeys as $lockKey) {
            $connection->fetchOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(:name_key, 0))',
                ['name_key' => $lockKey],
            );
        }
    }
}
