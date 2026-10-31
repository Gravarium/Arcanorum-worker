<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030030000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GuestCommentsSettingsMigrationTest extends KernelTestCase
{
    public function testMigrationPreservesExistingGuestCommentBehaviorByDefault(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        self::assertSame(
            'true',
            $connection->fetchOne(
                "SELECT column_default FROM information_schema.columns WHERE table_name = 'site_settings' AND column_name = 'guest_comments_enabled'",
            ),
        );
        self::assertSame('true', $connection->fetchOne('SELECT guest_comments_enabled::text FROM site_settings WHERE id = 1'));
    }

    public function testDowngradeRefusesToDiscardCustomizedGuestCommentSetting(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement('UPDATE site_settings SET guest_comments_enabled = FALSE WHERE id = 1');

            try {
                new Version20261030030000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve a customized guest-comment setting.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Die Gästekommentareinstellung darf durch den Migrationsrückbau nicht verloren gehen.',
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
