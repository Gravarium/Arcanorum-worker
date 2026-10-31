<?php

declare(strict_types=1);

namespace App\Tests\Module\Automation\Application;

use App\Module\Automation\Application\SchedulerRequestAuthenticator;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class SchedulerRequestAuthenticatorTest extends TestCase
{
    private const string KEY = 'test-private-scheduler-key-0123456789';
    private const string IDENTITY = 'scheduler-east-1';
    private const string NONCE = '0123456789abcdef0123456789abcdef';
    private const int ISSUED_AT = 1793188800;

    public function testValidHmacIsAcceptedAndOnlyNonceDigestIsStored(): void
    {
        $connection = $this->createMock(Connection::class);
        $calls = [];
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $operation): mixed => $operation($connection));
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters = [], array $types = []) use (&$calls): int {
                $calls[] = [$sql, $parameters, $types];

                return 1 === count($calls) ? 0 : 1;
            });

        $authenticator = new SchedulerRequestAuthenticator($connection, self::KEY);
        $authenticator->authenticate(
            self::IDENTITY,
            self::ISSUED_AT,
            self::NONCE,
            self::signature(),
            new \DateTimeImmutable('@'.self::ISSUED_AT),
        );

        self::assertSame('DELETE FROM scheduler_invocations WHERE expires_at <= :now', $calls[0][0]);
        self::assertStringContainsString('ON CONFLICT (nonce_digest) DO NOTHING', $calls[1][0]);
        self::assertSame(hash('sha256', self::NONCE), $calls[1][1]['digest']);
        self::assertSame(self::IDENTITY, $calls[1][1]['identity']);
        self::assertNotSame(self::NONCE, $calls[1][1]['digest']);
    }

    public function testAReplayedNonceIsRejected(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $operation): mixed => $operation($connection));
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(0, 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Die Scheduler-Anfrage wurde bereits verwendet.');
        new SchedulerRequestAuthenticator($connection, self::KEY)->authenticate(
            self::IDENTITY,
            self::ISSUED_AT,
            self::NONCE,
            self::signature(),
            new \DateTimeImmutable('@'.self::ISSUED_AT),
        );
    }

    public function testWrongSignatureAndExpiredRequestAreRejectedBeforeDatabaseAccess(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $authenticator = new SchedulerRequestAuthenticator($connection, self::KEY);
        try {
            $authenticator->authenticate(
                self::IDENTITY,
                self::ISSUED_AT,
                self::NONCE,
                str_repeat('0', 64),
                new \DateTimeImmutable('@'.self::ISSUED_AT),
            );
            self::fail('A wrong signature must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Die Scheduler-Anfrage konnte nicht authentifiziert werden.', $exception->getMessage());
        }

        try {
            $authenticator->authenticate(
                self::IDENTITY,
                self::ISSUED_AT,
                self::NONCE,
                self::signature(),
                new \DateTimeImmutable('@'.(self::ISSUED_AT + 121)),
            );
            self::fail('An expired requests must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Die Scheduler-Anfrage ist ungültig oder abgelaufen.', $exception->getMessage());
        }
    }

    public function testShortKeyAndMalformedNonceFailClosedBeforeDatabaseAccess(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $authenticator = new SchedulerRequestAuthenticator($connection, 'too-short');

        self::assertFalse($authenticator->isConfigured());
        try {
            $authenticator->authenticate(self::IDENTITY, self::ISSUED_AT, 'bad', self::signature(), new \DateTimeImmutable('@'.self::ISSUED_AT));
            self::fail('A short scheduler key must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Die Scheduler-Authentifizierung ist nicht verfügbar.', $exception->getMessage());
        }

        try {
            new SchedulerRequestAuthenticator($connection, self::KEY)->authenticate(
                self::IDENTITY,
                self::ISSUED_AT,
                'bad',
                self::signature(),
                new \DateTimeImmutable('@'.self::ISSUED_AT),
            );
            self::fail('A malformed nonce must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Die Scheduler-Anfrage ist ungültig oder abgelaufen.', $exception->getMessage());
        }
    }

    public function testSchedulerIdentityCannotContainAnIpAddress(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        try {
            new SchedulerRequestAuthenticator($connection, self::KEY)->authenticate(
                '192.0.2.17',
                self::ISSUED_AT,
                self::NONCE,
                self::signature(),
                new \DateTimeImmutable('@'.self::ISSUED_AT),
            );
            self::fail('An IP address must not be stored as a scheduler identity.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Die Scheduler-Anfrage ist ungültig oder abgelaufen.', $exception->getMessage());
        }
    }

    private static function signature(): string
    {
        return hash_hmac('sha256', SchedulerRequestAuthenticator::signingMessage(self::IDENTITY, self::ISSUED_AT, self::NONCE), self::KEY);
    }
}
