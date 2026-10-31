<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final readonly class MemberEmailChallengeMigrationGuard
{
    public function __construct(private Connection $connection)
    {
    }

    public function assertSafeToRemove(): void
    {
        $this->connection->executeStatement('LOCK TABLE member_accounts IN SHARE ROW EXCLUSIVE MODE');
        $this->connection->executeStatement('LOCK TABLE member_email_challenges IN SHARE ROW EXCLUSIVE MODE');
        $this->connection->executeStatement('LOCK TABLE member_security_rate_limits IN SHARE ROW EXCLUSIVE MODE');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowValue = $now->format('Y-m-d H:i:s');
        $rateLimitCutoff = $now->modify('-1 hour')->format('Y-m-d H:i:s');
        $unverifiedAccounts = $this->count('SELECT COUNT(*) FROM member_accounts WHERE email_verified = FALSE');
        $liveChallenges = $this->count(
            'SELECT COUNT(*) FROM member_email_challenges WHERE consumed_at IS NULL AND expires_at > :now',
            ['now' => $nowValue],
        );
        $liveRateLimits = $this->count(
            'SELECT COUNT(*) FROM member_security_rate_limits WHERE window_started_at > :cutoff',
            ['cutoff' => $rateLimitCutoff],
        );

        if ($unverifiedAccounts > 0 || $liveChallenges > 0 || $liveRateLimits > 0) {
            throw new \RuntimeException('Der Rückbau würde unbestätigte Mitgliedskonten, gültige Sicherheitslinks oder aktive Drosselungsdaten verlieren.');
        }
    }

    /** @param array<string, string> $parameters */
    private function count(string $query, array $parameters = []): int
    {
        return new MigrationRollbackGuard($this->connection)->countRows($query, $parameters);
    }
}
