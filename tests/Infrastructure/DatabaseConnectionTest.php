<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseConnectionTest extends KernelTestCase
{
    public function testRealPostgreSql18Connection(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $version = $connection->fetchOne('SHOW server_version');
        self::assertIsString($version);
        self::assertStringStartsWith('18', $version);
        self::assertSame(1, $connection->fetchOne('SELECT 1'));
    }
}
