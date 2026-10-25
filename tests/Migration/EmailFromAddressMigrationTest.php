<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261022214500;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmailFromAddressMigrationTest extends KernelTestCase
{
    public function testDowngradeRefusesToDiscardConfiguredSenderAddress(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                'UPDATE site_settings SET email_from_address = :address WHERE id = :id',
                ['address' => 'sender@example.test', 'id' => 1],
            );

            try {
                new Version20261022214500($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve the configured sender address.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Eine konfigurierte Absenderadresse darf durch den Migrationsrückbau nicht verloren gehen.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
