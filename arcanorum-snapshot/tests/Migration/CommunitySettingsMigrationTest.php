<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261107130000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommunitySettingsMigrationTest extends KernelTestCase
{
    public function testDefaultsRangeConstraintAndRollbackGuard(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $settings = $connection->fetchAssociative(
                'SELECT module_social_enabled::text AS module_social_enabled, social_following_limit::text AS social_following_limit, '
                .'social_activity_video_submission_enabled::text AS social_activity_video_submission_enabled, '
                .'social_activity_playlist_enabled::text AS social_activity_playlist_enabled, '
                .'social_activity_video_comment_enabled::text AS social_activity_video_comment_enabled, '
                .'social_activity_news_comment_enabled::text AS social_activity_news_comment_enabled '
                .'FROM site_settings WHERE id = 1',
            );
            self::assertIsArray($settings);
            self::assertSame('true', $settings['module_social_enabled']);
            self::assertSame('500', $settings['social_following_limit']);
            self::assertSame('true', $settings['social_activity_video_submission_enabled']);
            self::assertSame('true', $settings['social_activity_playlist_enabled']);
            self::assertSame('true', $settings['social_activity_video_comment_enabled']);
            self::assertSame('true', $settings['social_activity_news_comment_enabled']);

            $connection->beginTransaction();
            try {
                $connection->executeStatement('UPDATE site_settings SET social_following_limit = 5001 WHERE id = 1');
                self::fail('The social follow limit must remain inside the database range.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            } finally {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }

            $connection->executeStatement('UPDATE site_settings SET social_activity_playlist_enabled = FALSE WHERE id = 1');
            try {
                new Version20261107130000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve customized community settings.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Konfigurierte Social- und Community-Einstellungen dürfen durch den Migrationsrückbau nicht verloren gehen.',
                    $exception->getMessage(),
                );
            }
        } finally {
            $entityManager->clear();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
