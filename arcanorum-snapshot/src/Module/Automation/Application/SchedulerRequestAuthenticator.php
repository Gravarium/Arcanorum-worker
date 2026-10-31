<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class SchedulerRequestAuthenticator
{
    public function __construct(
        private Connection $connection,
        private string $schedulerKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return strlen($this->schedulerKey) >= 32
            && strlen($this->schedulerKey) <= 256
            && 1 === preg_match('/\A[\x21-\x7E]+\z/D', $this->schedulerKey);
    }

    public function authenticate(
        string $identity,
        int $issuedAt,
        string $nonce,
        string $signature,
        \DateTimeImmutable $now,
    ): void {
        if (!$this->isConfigured()) {
            throw new \InvalidArgumentException('Die Scheduler-Authentifizierung ist nicht verfügbar.');
        }
        if (1 !== preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{1,63}\z/D', $identity)
            || false !== filter_var($identity, FILTER_VALIDATE_IP)
            || abs($now->getTimestamp() - $issuedAt) > 120
            || 1 !== preg_match('/\A[a-f0-9]{32}\z/D', $nonce)
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $signature)
        ) {
            throw new \InvalidArgumentException('Die Scheduler-Anfrage ist ungültig oder abgelaufen.');
        }

        $message = self::signingMessage($identity, $issuedAt, $nonce);
        $expected = hash_hmac('sha256', $message, $this->schedulerKey);
        if (!hash_equals($expected, $signature)) {
            throw new \InvalidArgumentException('Die Scheduler-Anfrage konnte nicht authentifiziert werden.');
        }

        $this->connection->transactional(function (Connection $connection) use ($identity, $nonce, $now): void {
            $connection->executeStatement(
                'DELETE FROM scheduler_invocations WHERE expires_at <= :now',
                ['now' => $now],
                ['now' => Types::DATETIME_IMMUTABLE],
            );
            $inserted = $connection->executeStatement(
                'INSERT INTO scheduler_invocations (nonce_digest, scheduler_identity, created_at, expires_at) VALUES (:digest, :identity, :created, :expires) ON CONFLICT (nonce_digest) DO NOTHING',
                [
                    'digest' => hash('sha256', $nonce),
                    'identity' => $identity,
                    'created' => $now,
                    'expires' => $now->modify('+10 minutes'),
                ],
                [
                    'created' => Types::DATETIME_IMMUTABLE,
                    'expires' => Types::DATETIME_IMMUTABLE,
                ],
            );
            if (1 !== $inserted) {
                throw new \InvalidArgumentException('Die Scheduler-Anfrage wurde bereits verwendet.');
            }
        });
    }

    public static function signingMessage(string $identity, int $issuedAt, string $nonce): string
    {
        return "arcanorum-scheduler-v1\n".$identity."\n".$issuedAt."\n".$nonce;
    }
}
