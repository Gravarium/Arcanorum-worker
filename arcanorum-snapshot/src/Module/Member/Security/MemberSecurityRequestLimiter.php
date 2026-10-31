<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

use App\Platform\Security\RateLimitKeyFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final readonly class MemberSecurityRequestLimiter
{
    public function __construct(
        private Connection $connection,
        private RateLimitKeyFactory $rateLimitKeys,
    ) {
    }

    public function allow(Request $request, string $identifier): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowValue = $now->format('Y-m-d H:i:s');

        return $this->connection->transactional(function (Connection $connection) use ($request, $identifier, $now, $nowValue): bool {
            $ipHash = $this->rateLimitKeys->forIp($request, 'member-security-request-ip');
            $emailHash = $this->rateLimitKeys->forIdentity($identifier, 'member-security-request-identifier');
            $ipCount = $this->incrementWindow($connection, $ipHash, $now, $nowValue, 900);
            $emailCount = $this->incrementWindow($connection, $emailHash, $now, $nowValue, 3600);
            $connection->executeStatement(
                'DELETE FROM member_security_rate_limits WHERE window_started_at < :cutoff',
                ['cutoff' => $now->modify('-1 day')->format('Y-m-d H:i:s')],
            );

            return $ipCount <= 10 && $emailCount <= 3;
        });
    }

    private function incrementWindow(Connection $connection, string $keyHash, \DateTimeImmutable $now, string $nowValue, int $windowSeconds): int
    {
        $resetBefore = $now->modify('-'.$windowSeconds.' seconds')->format('Y-m-d H:i:s');
        $count = $connection->fetchOne(
            'INSERT INTO member_security_rate_limits (key_hash, window_started_at, request_count) '
                .'VALUES (:key, :now, 1) '
                .'ON CONFLICT (key_hash) DO UPDATE SET '
                .'window_started_at = CASE WHEN member_security_rate_limits.window_started_at <= :reset_before THEN EXCLUDED.window_started_at ELSE member_security_rate_limits.window_started_at END, '
                .'request_count = CASE WHEN member_security_rate_limits.window_started_at <= :reset_before THEN 1 ELSE LEAST(member_security_rate_limits.request_count + 1, 1000000) END '
                .'RETURNING request_count',
            ['key' => $keyHash, 'now' => $nowValue, 'reset_before' => $resetBefore],
        );

        if (!is_numeric($count) || (int) $count < 1) {
            throw new \RuntimeException('Der Mitglieds-Drosselungszähler ist nicht lesbar.');
        }

        return (int) $count;
    }
}
