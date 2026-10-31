<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\CommentContentType;
use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\ContentComment;
use App\Entity\ContentSeries;
use App\Entity\ContentTag;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\SeriesEpisode;
use App\Entity\StaticPage;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261006210000;
use DoctrineMigrations\Version20261016130200;
use DoctrineMigrations\Version20261017022700;
use DoctrineMigrations\Version20261018010000;
use DoctrineMigrations\Version20261018023000;
use DoctrineMigrations\Version20261018030000;
use DoctrineMigrations\Version20261018040000;
use DoctrineMigrations\Version20261018050000;
use DoctrineMigrations\Version20261018060000;
use DoctrineMigrations\Version20261030230000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HistoricalPrimaryDataMigrationRollbackTest extends KernelTestCase
{
    public function testAdminUserMigrationRefusesToDropPersistedCredentials(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $this->createAdminUser($entityManager);

            $this->assertRollbackBlocked(
                new Version20261006210000($connection, new NullLogger()),
                'Administratorkonten und ihre Zugangsdaten müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testAdminMfaMigrationRefusesToDropRecoveryCodes(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $admin = $this->createAdminUser($entityManager);
            $connection->insert('admin_user_recovery_codes', [
                'admin_user_id' => $admin->getId(),
                'code_hash' => hash('sha256', 'unused-recovery-code'),
            ]);

            $this->assertRollbackBlocked(
                new Version20261016130200($connection, new NullLogger()),
                'Konfigurierte Admin-MFA und Wiederherstellungscodes müssen vor dem Migrationsrückbau erhalten oder erneuert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testAdminMfaMigrationRefusesToDropConfiguredTotpState(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $admin = $this->createAdminUser($entityManager);
            $admin->setMfaSecretCiphertext('encrypted-totp-fixture');
            $admin->setMfaLastTotpCounter(42);
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261016130200($connection, new NullLogger()),
                'Konfigurierte Admin-MFA und Wiederherstellungscodes müssen vor dem Migrationsrückbau erhalten oder erneuert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testAdminMfaVersionMigrationRefusesToRestoreRevokedSessions(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $admin = $this->createAdminUser($entityManager);
            $connection->update('admin_users', ['mfa_auth_version' => 3], ['id' => $admin->getId()]);

            $this->assertRollbackBlocked(
                new Version20261017022700($connection, new NullLogger()),
                'Widerrufene Administratorsitzungen dürfen durch den Migrationsrückbau nicht wieder gültig werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testInitialContentMigrationRefusesToDropVideos(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $entityManager->persist($this->createVideo());
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018010000($connection, new NullLogger()),
                'News und Videos müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testInitialContentMigrationRefusesToDropNewsArticles(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $entityManager->persist($this->createNewsArticle());
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018010000($connection, new NullLogger()),
                'News und Videos müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testStaticPageMigrationRefusesToDropPages(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $entityManager->persist(new StaticPage(
                'Rollback page '.$suffix,
                'rollback-page-'.$suffix,
                null,
                'Persistent page body',
                PublishingStatus::Draft,
            ));
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018023000($connection, new NullLogger()),
                'Statische Inhaltsseiten müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testCategoryMigrationRefusesToDropManagedCategories(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $entityManager->persist(new ContentCategory('Rollback '.$suffix, ContentCategoryType::News));
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018030000($connection, new NullLogger()),
                'Verwaltete Inhaltskategorien müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testCommentMigrationRefusesToDropModeratedComments(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $entityManager->persist(new ContentComment(
                CommentContentType::News,
                bin2hex(random_bytes(16)),
                'Rollback article',
                'Reader',
                'A retained moderation record',
            ));
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018040000($connection, new NullLogger()),
                'Kommentare und ihre Moderationsdaten müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testTagMigrationRefusesToDropTagsAndContentAssignments(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $tag = new ContentTag('retained-'.$suffix);
            $video = $this->createVideo();
            $article = $this->createNewsArticle();
            $video->replaceTags([$tag]);
            $article->replaceTags([$tag]);
            $entityManager->persist($tag);
            $entityManager->persist($video);
            $entityManager->persist($article);
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018050000($connection, new NullLogger()),
                'Schlagwörter und ihre News-/Videozuordnungen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testSeriesMigrationRefusesToDropSeriesAndEpisodeAssignments(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $series = new ContentSeries('Rollback series '.$suffix, 'rollback-series-'.$suffix, 'Description', PublishingStatus::Draft);
            $video = $this->createVideo();
            $episode = new SeriesEpisode($series, $video, 1, 1);
            $entityManager->persist($series);
            $entityManager->persist($video);
            $entityManager->persist($episode);
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018060000($connection, new NullLogger()),
                'Redaktionelle Serien und Episodenzuordnungen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testProviderFailureMigrationRefusesToDiscardFailedCheckState(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $video = new Video(
                'Failed provider check '.$suffix,
                'The last known source status must remain visible.',
                null,
                VideoSourceType::Embed,
                'https://youtu.be/dQw4w9WgXcQ',
                PublishingStatus::Draft,
                false,
                false,
            );
            $video->markExternalSourceCheckFailed(new \DateTimeImmutable('2026-10-31T09:45:00+00:00'));
            $entityManager->persist($video);
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261030230000($connection, new NullLogger()),
                'Fehlgeschlagene Providerprüfungen müssen vor dem Migrationsrückbau erhalten oder erneut geprüft werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testProviderFailureMigrationFailsClosedWhenCountCannotBeRead(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT COUNT(*) FROM videos WHERE external_source_check_failed IS TRUE')
            ->willReturn('not-a-count');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Der Providerstatus konnte vor dem Migrationsrückbau nicht zuverlässig geprüft werden.');

        new Version20261030230000($connection, new NullLogger())->down(new Schema());
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
            self::fail('A migration rollback must not discard administrator access controls or retained content.');
        } catch (\RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function createAdminUser(EntityManagerInterface $entityManager): AdminUser
    {
        $admin = new AdminUser('rollback-admin-'.bin2hex(random_bytes(8)).'@example.test');
        $admin->setPassword('fixture-password-hash');
        $admin->setRoles([AdminRole::Administrator->value]);
        $entityManager->persist($admin);
        $entityManager->flush();

        return $admin;
    }

    private function createNewsArticle(): NewsArticle
    {
        $suffix = bin2hex(random_bytes(8));

        return new NewsArticle(
            'Rollback article '.$suffix,
            'rollback-article-'.$suffix,
            null,
            null,
            'Retained article body',
            PublishingStatus::Draft,
            false,
            false,
        );
    }

    private function createVideo(): Video
    {
        $suffix = bin2hex(random_bytes(8));

        return new Video(
            'Rollback video '.$suffix,
            'Retained video description',
            null,
            VideoSourceType::Url,
            'https://media.example.test/'.$suffix,
            PublishingStatus::Draft,
            false,
            false,
        );
    }
}
