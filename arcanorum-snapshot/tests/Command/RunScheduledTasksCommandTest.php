<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RunScheduledTasksCommand;
use App\Module\Automation\Application\ScheduledTaskHandlerRegistry;
use App\Module\Automation\Application\ScheduledTaskRunner;
use App\Module\Automation\Application\ScheduledTaskRunStore;
use App\Module\Automation\Application\ScheduledTaskStore;
use App\Module\Automation\Application\SchedulerRequestAuthenticator;
use App\Module\Automation\Application\SchedulerRequestSignatureReaderInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RunScheduledTasksCommandTest extends TestCase
{
    private const string KEY = 'test-private-scheduler-key-0123456789';
    private const string IDENTITY = 'scheduler-east-1';
    private const string NONCE = '0123456789abcdef0123456789abcdef';

    public function testValidSignedRequestRunsThePrivateSchedulerWithoutExposingItsSignature(): void
    {
        $issuedAt = time();
        $signature = hash_hmac(
            'sha256',
            SchedulerRequestAuthenticator::signingMessage(self::IDENTITY, $issuedAt, self::NONCE),
            self::KEY,
        );
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $operation): mixed => $operation($connection));
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(0, 1);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('t', 't');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $tasks = $this->createMock(ScheduledTaskStore::class);
        $tasks->expects(self::once())
            ->method('findDue')
            ->with(self::isInstanceOf(\DateTimeImmutable::class), 10)
            ->willReturn([]);
        $runs = $this->createMock(ScheduledTaskRunStore::class);
        $runs->expects(self::once())->method('pruneHistory')->with(200)->willReturn(0);

        $reader = $this->createMock(SchedulerRequestSignatureReaderInterface::class);
        $reader->expects(self::once())->method('read')->willReturn($signature);
        $runner = new ScheduledTaskRunner(
            $connection,
            $entityManager,
            $tasks,
            $runs,
            new ScheduledTaskHandlerRegistry([]),
            new MockClock(),
        );
        $command = new RunScheduledTasksCommand(
            new SchedulerRequestAuthenticator($connection, self::KEY),
            $reader,
            $runner,
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--identity' => self::IDENTITY,
            '--issued-at' => (string) $issuedAt,
            '--nonce' => self::NONCE,
        ]));
        self::assertStringContainsString('0 fällige Aufgaben verarbeitet.', $tester->getDisplay());
        self::assertStringNotContainsString($signature, $tester->getDisplay());
        self::assertStringNotContainsString(self::KEY, $tester->getDisplay());
    }

    public function testMalformedSignatureFailsClosedBeforeDatabaseOrTaskAccess(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('executeStatement');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $tasks = $this->createMock(ScheduledTaskStore::class);
        $tasks->expects(self::never())->method('findDue');
        $runs = $this->createMock(ScheduledTaskRunStore::class);
        $runs->expects(self::never())->method('findExistingForDueTime');

        $runner = new ScheduledTaskRunner(
            $connection,
            $entityManager,
            $tasks,
            $runs,
            new ScheduledTaskHandlerRegistry([]),
            new MockClock(),
        );
        $command = new RunScheduledTasksCommand(
            new SchedulerRequestAuthenticator($connection, self::KEY),
            $this->signatureReader('not-a-signature'),
            $runner,
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--identity' => self::IDENTITY,
            '--issued-at' => (string) time(),
            '--nonce' => self::NONCE,
        ]));
        self::assertStringContainsString('Die private Scheduler-Anfrage wurde abgewiesen.', $tester->getDisplay());
        self::assertStringNotContainsString(self::KEY, $tester->getDisplay());
    }

    private function signatureReader(string $signature): SchedulerRequestSignatureReaderInterface
    {
        $reader = $this->createMock(SchedulerRequestSignatureReaderInterface::class);
        $reader->expects(self::once())->method('read')->willReturn($signature);

        return $reader;
    }
}
