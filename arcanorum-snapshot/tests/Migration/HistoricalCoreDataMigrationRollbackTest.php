<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\MemberAccount;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261018120000;
use DoctrineMigrations\Version20261018130000;
use DoctrineMigrations\Version20261019195500;
use DoctrineMigrations\Version20261020010000;
use DoctrineMigrations\Version20261020080000;
use DoctrineMigrations\Version20261021010000;
use DoctrineMigrations\Version20261021233000;
use DoctrineMigrations\Version20261022090000;
use DoctrineMigrations\Version20261027130000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HistoricalCoreDataMigrationRollbackTest extends KernelTestCase
{
    public function testVideoReportMigrationRefusesToDiscardReports(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $video = $this->createVideo($entityManager);
            $connection->insert('video_reports', [
                'id' => bin2hex(random_bytes(16)),
                'video_id' => $video->getId(),
                'reason' => 'other',
                'details' => 'A report that still needs to be retained.',
                'status' => 'pending',
                'created_at' => '2026-10-31 09:00:00',
                'reviewed_at' => null,
            ]);

            $this->assertRollbackBlocked(
                new Version20261018120000($connection, new NullLogger()),
                'Gespeicherte Videomeldungen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testVideoSubmissionMigrationRefusesToDiscardSubmissions(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $video = $this->createVideo($entityManager);
            $connection->insert('video_submissions', [
                'id' => bin2hex(random_bytes(16)),
                'video_id' => $video->getId(),
                'status' => 'pending',
                'created_at' => '2026-10-31 09:00:00',
                'reviewed_at' => null,
            ]);

            $this->assertRollbackBlocked(
                new Version20261018130000($connection, new NullLogger()),
                'Gespeicherte Videoeinreichungen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testContactMessageMigrationRefusesToDiscardPersonalMessages(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $connection->insert('contact_messages', [
                'id' => bin2hex(random_bytes(16)),
                'name' => 'Example User',
                'email' => 'user@example.test',
                'subject' => 'Support request',
                'body' => 'A message that contains personal data.',
                'status' => 'pending',
                'created_at' => '2026-10-31 09:00:00',
                'resolved_at' => null,
            ]);

            $this->assertRollbackBlocked(
                new Version20261019195500($connection, new NullLogger()),
                'Gespeicherte Kontaktnachrichten mit personenbezogenen Angaben müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testMemberAccountMigrationRefusesToDiscardMemberData(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $entityManager->persist($this->createMember());
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261020010000($connection, new NullLogger()),
                'Mitgliedskonten und ihre personenbezogenen Daten müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testPlaylistMigrationRefusesToDiscardPrivatePlaylistsAndItems(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $member = $this->createMember();
            $video = $this->createVideo($entityManager);
            $entityManager->persist($member);
            $entityManager->flush();

            $playlistId = bin2hex(random_bytes(16));
            $now = '2026-10-31 09:00:00';
            $connection->executeStatement(
                'INSERT INTO member_playlists (id, owner_id, name, description, is_public, created_at, updated_at)
                 VALUES (:id, :owner_id, :name, NULL, FALSE, :created_at, :updated_at)',
                [
                    'id' => $playlistId,
                    'owner_id' => $member->getId(),
                    'name' => 'Private playlist',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            $connection->insert('playlist_items', [
                'id' => bin2hex(random_bytes(16)),
                'playlist_id' => $playlistId,
                'video_id' => $video->getId(),
                'position' => 0,
                'added_at' => $now,
            ]);

            $this->assertRollbackBlocked(
                new Version20261020080000($connection, new NullLogger()),
                'Private Wiedergabelisten und ihre Videozuordnungen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testAdminActivityMigrationRefusesToDiscardAuditEntries(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $connection->insert('admin_activity_entries', [
                'id' => bin2hex(random_bytes(16)),
                'actor_id' => null,
                'route_name' => 'admin_video_update',
                'method' => 'POST',
                'created_at' => '2026-10-31 09:00:00',
            ]);

            $this->assertRollbackBlocked(
                new Version20261021010000($connection, new NullLogger()),
                'Der Verwaltungsaktivitätsverlauf muss vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testVideoViewCountMigrationRefusesToDiscardStoredCounts(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $video = $this->createVideo($entityManager);
            $connection->update('videos', ['view_count' => 1], ['id' => $video->getId()]);

            $this->assertRollbackBlocked(
                new Version20261021233000($connection, new NullLogger()),
                'Gespeicherte Videoaufrufzahlen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testSearchStatisticsMigrationRefusesToDiscardAggregatedTerms(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $connection->insert('search_term_daily', [
                'day' => '2026-10-31',
                'term' => 'example query',
                'search_count' => 2,
                'last_at' => '2026-10-31 09:00:00',
            ]);

            $this->assertRollbackBlocked(
                new Version20261022090000($connection, new NullLogger()),
                'Gespeicherte aggregierte Suchbegriffe müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testPublicDisplayMigrationRefusesToDiscardDailyViewAggregates(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $video = $this->createVideo($entityManager);
            $connection->insert('video_daily_views', [
                'video_id' => $video->getId(),
                'view_date' => '2026-10-31',
                'view_count' => 4,
            ]);

            $this->assertRollbackBlocked(
                new Version20261027130000($connection, new NullLogger()),
                'The migration cannot remove collected daily view aggregates.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testPublicDisplayMigrationRefusesToDiscardLastViewedTimestamp(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $video = $this->createVideo($entityManager);
            $connection->update('videos', ['last_viewed_at' => '2026-10-31 09:00:00'], ['id' => $video->getId()]);

            $this->assertRollbackBlocked(
                new Version20261027130000($connection, new NullLogger()),
                'The migration cannot remove last-view timestamps.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testPublicDisplayMigrationRefusesToDiscardCustomizedSettings(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $connection->update('site_settings', ['homepage_video_limit' => 7], ['id' => 1]);

            $this->assertRollbackBlocked(
                new Version20261027130000($connection, new NullLogger()),
                'The migration cannot remove customized public display settings.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    /** @return array{EntityManagerInterface, Connection} */
    private function beginTransaction(): array
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        return [$entityManager, $connection];
    }

    private function rollbackTransaction(EntityManagerInterface $entityManager, Connection $connection): void
    {
        $entityManager->clear();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }

    private function assertRollbackBlocked(AbstractMigration $migration, string $message): void
    {
        try {
            $migration->down(new Schema());
            self::fail('The downgrade must not discard retained content, personal data, or audit records.');
        } catch (\RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function createVideo(EntityManagerInterface $entityManager): Video
    {
        $suffix = bin2hex(random_bytes(8));
        $video = new Video(
            'Rollback guard video '.$suffix,
            'Description',
            null,
            VideoSourceType::Url,
            'https://media.example.test/'.$suffix,
            PublishingStatus::Draft,
            false,
            false,
        );
        $entityManager->persist($video);
        $entityManager->flush();

        return $video;
    }

    private function createMember(): MemberAccount
    {
        $suffix = bin2hex(random_bytes(8));

        return new MemberAccount('member-'.$suffix.'@example.test', 'user-'.$suffix, 'Member '.$suffix);
    }
}
