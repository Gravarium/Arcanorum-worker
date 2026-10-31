<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261026150000;
use DoctrineMigrations\Version20261026160000;
use DoctrineMigrations\Version20261026170000;
use DoctrineMigrations\Version20261026180000;
use DoctrineMigrations\Version20261026190000;
use DoctrineMigrations\Version20261026200000;
use DoctrineMigrations\Version20261029210000;
use DoctrineMigrations\Version20261030221700;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VideoPlayerSettingsMigrationTest extends KernelTestCase
{
    public function testAutomaticFeatureThresholdRejectsNegativeDatabaseValues(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            self::assertSame('0', $connection->fetchOne('SELECT auto_feature_view_threshold::text FROM site_settings WHERE id = 1'));
            $connection->executeStatement('UPDATE site_settings SET auto_feature_view_threshold = 2147483647 WHERE id = 1');

            try {
                $connection->executeStatement('UPDATE site_settings SET auto_feature_view_threshold = -1 WHERE id = 1');
                self::fail('The database must reject negative automatic feature thresholds.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardConfiguredAutomaticFeatureThreshold(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement('UPDATE site_settings SET auto_feature_view_threshold = 250 WHERE id = 1');

            try {
                new Version20261030221700($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve the configured automatic feature threshold.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Die konfigurierte Schwelle für automatische Video-Hervorhebung muss vor dem Migrationsrückbau erhalten oder exportiert werden.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardConfiguredProviderThumbnailResolution(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement("UPDATE site_settings SET provider_thumbnail_resolution = 'large' WHERE id = 1");

            try {
                new Version20261029210000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve the configured provider thumbnail resolution.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Die konfigurierte Auflösung für Provider-Vorschaubilder muss vor dem Migrationsrückbau erhalten oder exportiert werden.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsUnsupportedPlayerRatios(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            try {
                $connection->executeStatement("UPDATE site_settings SET video_player_aspect_ratio = '12:1' WHERE id = 1");
                self::fail('The database must reject unsupported player ratios.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardCustomizedPlayerSettings(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement("UPDATE site_settings SET video_player_aspect_ratio = '1:1' WHERE id = 1");

            try {
                new Version20261026150000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve customized video player settings.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Geänderte Player- und Einbettungseinstellungen dürfen durch den Migrationsrückbau nicht verloren gehen.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsUnsupportedResponsiveMaximumWidth(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            try {
                $connection->executeStatement("UPDATE site_settings SET video_player_max_width = '777' WHERE id = 1");
                self::fail('The database must reject a player width outside the allowlist.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardCustomizedResponsiveMaximumWidth(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement("UPDATE site_settings SET video_player_max_width = '800' WHERE id = 1");

            try {
                new Version20261026160000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve the customized responsive player width.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Die maximale Playerbreite darf durch den Migrationsrückbau nicht verloren gehen.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsUnsupportedEmbeddedPlayerMaximumWidth(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            try {
                $connection->executeStatement(
                    "UPDATE site_settings SET video_player_embed_max_width = '777' WHERE id = 1",
                );
                self::fail('The database must reject an embedded player width outside the allowlist.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardCustomizedEmbeddedPlayerMaximumWidth(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                "UPDATE site_settings SET video_player_embed_max_width = '800' WHERE id = 1",
            );

            try {
                new Version20261026190000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve the customized embedded player width.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Die angepasste Playerbreite für Einbettungen darf durch den Migrationsrückbau '
                    .'nicht verloren gehen.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsUnsupportedHomePlayerMaximumWidth(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            try {
                $connection->executeStatement("UPDATE site_settings SET video_player_home_max_width = '777' WHERE id = 1");
                self::fail('The database must reject a home player width outside the allowlist.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardCustomizedHomePlayerSettings(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement("UPDATE site_settings SET video_player_home_max_width = '800' WHERE id = 1");

            try {
                new Version20261026200000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve the customized home player settings.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Die Einstellungen für den Startseitenplayer dürfen durch den Migrationsrückbau nicht verloren gehen.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardFeaturedAutoplaySetting(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement('UPDATE site_settings SET player_featured_autoplay_enabled = TRUE WHERE id = 1');

            try {
                new Version20261026200000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve the featured autoplay setting.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Die Einstellungen für den Startseitenplayer dürfen durch den Migrationsrückbau nicht verloren gehen.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsUnsupportedPlayerColorSchemes(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            try {
                $connection->executeStatement("UPDATE site_settings SET video_player_color_scheme = 'unsafe-color' WHERE id = 1");
                self::fail('The database must reject a player color scheme outside the allowlist.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardPlayerColorSchemeOrWatermark(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement("UPDATE site_settings SET video_player_color_scheme = 'ocean', video_player_watermark_enabled = TRUE WHERE id = 1");

            try {
                new Version20261026170000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve the customized player color scheme and watermark setting.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Player-Farbschema und Wasserzeichen müssen vor dem Migrationsrückbau zurückgesetzt werden.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsIncompleteWatermarkImageMetadata(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            try {
                $connection->executeStatement("UPDATE site_settings SET video_player_watermark_object_id = 'invalid' WHERE id = 1");
                self::fail('The database must reject incomplete private watermark image metadata.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardStoredWatermarkImageMetadata(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                'UPDATE site_settings SET video_player_watermark_object_id = :object_id, video_player_watermark_mime_type = :mime_type, video_player_watermark_size = :size, video_player_watermark_sha256 = :sha256 WHERE id = 1',
                [
                    'object_id' => str_repeat('a', 64),
                    'mime_type' => 'image/png',
                    'size' => 64,
                    'sha256' => str_repeat('b', 64),
                ],
            );

            try {
                new Version20261026180000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve stored private watermark image metadata.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Gespeicherte Wasserzeichen müssen vor dem Migrationsrückbau entfernt werden.',
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
