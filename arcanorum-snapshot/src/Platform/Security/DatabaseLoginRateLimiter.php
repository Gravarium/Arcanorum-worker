<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * A cross-process login limiter backed by the application's required database.
 * A successful authentication releases only its own reserved hit; failed and
 * interrupted authentication attempts remain counted.
 */
final readonly class DatabaseLoginRateLimiter implements RequestRateLimiterInterface
{
    private const int LOCAL_LIMIT = 5;
    private const int GLOBAL_LIMIT = 25;
    private const int WINDOW_SECONDS = 900;
    private const int CLEANUP_AGE_SECONDS = 86400;
    private const string RESERVATION_ATTRIBUTE = '_arcanorum_login_rate_limit_reservation';

    public function __construct(
        private Connection $connection,
        private RateLimitKeyFactory $rateLimitKeys,
    ) {
    }

    public function consume(Request $request): RateLimit
    {
        $keyLimits = $this->keyLimitsFor($request);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowValue = $now->format('Y-m-d H:i:s');
        $resetBefore = $now->modify('-'.self::WINDOW_SECONDS.' seconds')->format('Y-m-d H:i:s');

        /** @var array<string, array{count: int, started_at: \DateTimeImmutable}> $windows */
        $windows = $this->connection->transactional(function (Connection $connection) use ($keyLimits, $now, $nowValue, $resetBefore): array {
            $windows = [];
            $orderedKeyLimits = $keyLimits;
            ksort($orderedKeyLimits, SORT_STRING);

            foreach ($orderedKeyLimits as $key => $limit) {
                $windows[$key] = $this->incrementWindow($connection, $key, $limit, $nowValue, $resetBefore);
            }

            $connection->executeStatement(
                'DELETE FROM authentication_login_rate_limits WHERE window_started_at < :cutoff',
                ['cutoff' => $now->modify('-'.self::CLEANUP_AGE_SECONDS.' seconds')->format('Y-m-d H:i:s')],
            );

            return $windows;
        });

        $rateLimit = $this->rateLimit($windows, $keyLimits, $now);
        if ($rateLimit->isAccepted()) {
            $request->attributes->set(self::RESERVATION_ATTRIBUTE, array_keys($keyLimits));
        }

        return $rateLimit;
    }

    public function reset(Request $request): void
    {
        $reservation = $request->attributes->get(self::RESERVATION_ATTRIBUTE);
        $request->attributes->remove(self::RESERVATION_ATTRIBUTE);
        if (!is_array($reservation) || 2 !== count($reservation) || !array_is_list($reservation)) {
            return;
        }

        $keyLimits = $this->keyLimitsFor($request);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $resetBefore = $now->modify('-'.self::WINDOW_SECONDS.' seconds')->format('Y-m-d H:i:s');
        $keys = array_keys($keyLimits);
        sort($keys, SORT_STRING);
        $reservedKeys = [];
        foreach ($reservation as $reservedKey) {
            if (!is_string($reservedKey)) {
                return;
            }

            $reservedKeys[] = $reservedKey;
        }
        sort($reservedKeys, SORT_STRING);
        if ($keys !== $reservedKeys) {
            return;
        }

        $this->connection->transactional(static function (Connection $connection) use ($keys, $resetBefore): void {
            foreach ($keys as $key) {
                $connection->executeStatement(
                    'UPDATE authentication_login_rate_limits '
                        .'SET request_count = request_count - 1 '
                        .'WHERE key_hash = :key AND window_started_at > :reset_before AND request_count > 0',
                    ['key' => $key, 'reset_before' => $resetBefore],
                );
            }

            $connection->executeStatement('DELETE FROM authentication_login_rate_limits WHERE request_count = 0');
        });
    }

    public function isStorageAvailable(): bool
    {
        try {
            $available = $this->connection->fetchOne(
                "SELECT to_regclass('authentication_login_rate_limits') IS NOT NULL "
                    ."AND has_table_privilege(current_user, 'authentication_login_rate_limits', 'SELECT') "
                    ."AND has_table_privilege(current_user, 'authentication_login_rate_limits', 'INSERT') "
                    ."AND has_table_privilege(current_user, 'authentication_login_rate_limits', 'UPDATE') "
                    ."AND has_table_privilege(current_user, 'authentication_login_rate_limits', 'DELETE')",
            );

            return true === $available || 1 === $available || '1' === $available || 't' === $available || 'true' === $available;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, int>
     */
    private function keyLimitsFor(Request $request): array
    {
        $route = $request->attributes->get('_route');
        $scope = 'admin_login' === $route
            ? 'admin-login'
            : (is_string($route) && str_starts_with($route, 'member_')
                ? 'member-login'
                : throw new \InvalidArgumentException('Der Anmelde-Limiter unterstützt nur Admin- und Mitglieder-Authentifizierungsrouten.'));
        $identifier = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');
        if (!is_string($identifier)) {
            $identifier = '';
        }
        $normalizedIdentifier = 1 === preg_match('//u', $identifier)
            ? mb_strtolower($identifier, 'UTF-8')
            : strtolower($identifier);

        return [
            $this->rateLimitKeys->forIdentityAndIp($normalizedIdentifier, $request, $scope.'-username-ip') => self::LOCAL_LIMIT,
            $this->rateLimitKeys->forIp($request, $scope.'-ip') => self::GLOBAL_LIMIT,
        ];
    }

    /** @return array{count: int, started_at: \DateTimeImmutable} */
    private function incrementWindow(Connection $connection, string $key, int $limit, string $now, string $resetBefore): array
    {
        $row = $connection->fetchAssociative(
            'INSERT INTO authentication_login_rate_limits (key_hash, window_started_at, request_count) '
                .'VALUES (:key, :now, 1) '
                .'ON CONFLICT (key_hash) DO UPDATE SET '
                .'window_started_at = CASE WHEN authentication_login_rate_limits.window_started_at <= :reset_before '
                .'THEN EXCLUDED.window_started_at ELSE authentication_login_rate_limits.window_started_at END, '
                .'request_count = CASE WHEN authentication_login_rate_limits.window_started_at <= :reset_before '
                .'THEN 1 ELSE LEAST(authentication_login_rate_limits.request_count + 1, :maximum_count) END '
                .'RETURNING request_count, window_started_at',
            ['key' => $key, 'now' => $now, 'reset_before' => $resetBefore, 'maximum_count' => $limit + 1],
        );

        if (!is_array($row) || !is_numeric($row['request_count'] ?? null) || !is_string($row['window_started_at'] ?? null)) {
            throw new \RuntimeException('Der gemeinsame Anmelde-Drosselungszähler ist nicht lesbar.');
        }

        $startedAt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $row['window_started_at'], new \DateTimeZone('UTC'));
        if (false === $startedAt) {
            throw new \RuntimeException('Der gemeinsame Anmelde-Drosselungszeitpunkt ist ungültig.');
        }

        return ['count' => (int) $row['request_count'], 'started_at' => $startedAt];
    }

    /**
     * @param array<string, array{count: int, started_at: \DateTimeImmutable}> $windows
     * @param array<string, int>                                               $keyLimits
     */
    private function rateLimit(array $windows, array $keyLimits, \DateTimeImmutable $now): RateLimit
    {
        $accepted = true;
        $remainingTokens = self::GLOBAL_LIMIT;
        $retryAfter = $now;

        foreach ($keyLimits as $key => $limit) {
            $count = $windows[$key]['count'];
            $remainingTokens = min($remainingTokens, max(0, $limit - $count));
            if ($count > $limit) {
                $accepted = false;
                $windowRetryAfter = $windows[$key]['started_at']->modify('+'.self::WINDOW_SECONDS.' seconds');
                if ($windowRetryAfter > $retryAfter) {
                    $retryAfter = $windowRetryAfter;
                }
            }
        }

        return new RateLimit($remainingTokens, $retryAfter, $accepted, self::LOCAL_LIMIT);
    }
}
