<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CheckInfrastructureCommand;
use App\Infrastructure\InfrastructureProbe;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckInfrastructureCommandTest extends TestCase
{
    public function testFailuresRemainClosedWithoutExposingExceptionDetails(): void
    {
        $marker = 'private-diagnostic-marker';
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willThrowException(new \RuntimeException($marker));
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::once())->method('getItem')->willThrowException(new \RuntimeException($marker));
        $cache->expects(self::once())->method('deleteItem')->willReturn(true);
        $tester = new CommandTester(new CheckInfrastructureCommand(new InfrastructureProbe($connection, $cache)));

        self::assertSame(Command::FAILURE, $tester->execute(['--format' => 'json'], ['verbosity' => OutputInterface::VERBOSITY_DEBUG]));
        self::assertSame(
            ['database' => false, 'cache' => false, 'ready' => false],
            json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString($marker, $tester->getDisplay());
    }

    public function testUnknownFormatIsRejectedBeforeUsingServices(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::never())->method('getItem');
        $tester = new CommandTester(new CheckInfrastructureCommand(new InfrastructureProbe($connection, $cache)));

        self::assertSame(Command::INVALID, $tester->execute(['--format' => 'unsupported']));
    }

    public function testWrongDatabaseMajorStillChecksTheCache(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn('170000');
        $tester = new CommandTester(new CheckInfrastructureCommand(new InfrastructureProbe($connection, new ArrayAdapter())));

        self::assertSame(Command::FAILURE, $tester->execute(['--format' => 'json']));
        self::assertSame(
            ['database' => false, 'cache' => true, 'ready' => false],
            json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testCacheWriteFailureRejectsReadinessAndRemovesTheProbe(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('fetchOne')->willReturn('180001', 1);
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::once())->method('getItem')->willReturn(new ArrayAdapter()->getItem('test_item'));
        $cache->expects(self::once())->method('save')->willReturn(false);
        $cache->expects(self::once())->method('deleteItem')->willReturn(true);
        $tester = new CommandTester(new CheckInfrastructureCommand(new InfrastructureProbe($connection, $cache)));

        self::assertSame(Command::FAILURE, $tester->execute(['--format' => 'json']));
        self::assertSame(
            ['database' => true, 'cache' => false, 'ready' => false],
            json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
