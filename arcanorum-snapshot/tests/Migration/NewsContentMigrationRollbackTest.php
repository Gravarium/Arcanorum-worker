<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\AdminUser;
use App\Entity\NewsArticle;
use App\Entity\NewsArticleRevision;
use App\Entity\PublishingStatus;
use App\Entity\StaticPage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261025160000;
use DoctrineMigrations\Version20261026233000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NewsContentMigrationRollbackTest extends KernelTestCase
{
    public function testDowngradeRefusesToMakeMembersOnlyNewsPublic(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $article = new NewsArticle(
                'Private news '.$suffix,
                'private-news-'.$suffix,
                null,
                null,
                'Body',
                PublishingStatus::Published,
                false,
                false,
                membersOnly: true,
            );
            $entityManager->persist($article);
            $entityManager->flush();

            $this->assertDowngradeIsBlocked($connection);
        } finally {
            $entityManager->clear();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardCurrentNewsThumbnailMetadata(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $article = new NewsArticle(
                'News with thumbnail '.$suffix,
                'news-thumbnail-'.$suffix,
                null,
                null,
                'Body',
                PublishingStatus::Draft,
                false,
                false,
            );
            $article->attachThumbnail(str_repeat('a', 64), 'image/png', 128);
            $entityManager->persist($article);
            $entityManager->flush();

            $this->assertDowngradeIsBlocked($connection);
        } finally {
            $entityManager->clear();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardHistoricalNewsAccessAndThumbnailMetadata(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $article = new NewsArticle(
                'Revised news '.$suffix,
                'revised-news-'.$suffix,
                null,
                null,
                'Body',
                PublishingStatus::Draft,
                false,
                false,
                membersOnly: true,
            );
            $article->attachThumbnail(str_repeat('b', 64), 'image/png', 128);
            $editor = new AdminUser('migration-test-'.$suffix.'@example.test');
            $editor->setPassword('test-hash');
            $entityManager->persist($article);
            $entityManager->persist($editor);
            $entityManager->flush();

            $revision = new NewsArticleRevision($article, 1, $editor);
            $entityManager->persist($revision);
            $entityManager->flush();
            $connection->executeStatement(
                'UPDATE news_articles SET members_only = FALSE, thumbnail_object_id = NULL, thumbnail_mime_type = NULL, thumbnail_size = NULL WHERE id = :id',
                ['id' => $article->getId()],
            );

            $this->assertDowngradeIsBlocked($connection);
            $this->assertHistoryDowngradeIsBlocked($connection);
        } finally {
            $entityManager->clear();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardStaticPageMetadata(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $page = new StaticPage(
                'Editorial page '.$suffix,
                'editorial-page-'.$suffix,
                null,
                'Body',
                PublishingStatus::Draft,
                'custom keywords',
                'Custom description',
                'Editorial team',
                true,
            );
            $entityManager->persist($page);
            $entityManager->flush();

            $this->assertDowngradeIsBlocked($connection);
        } finally {
            $entityManager->clear();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testAuthorMigrationDowngradeRefusesToDiscardCustomizedNewsAuthor(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $article = new NewsArticle(
                'Authored news '.$suffix,
                'authored-news-'.$suffix,
                null,
                null,
                'Body',
                PublishingStatus::Draft,
                false,
                false,
                authorName: 'Editorial author',
            );
            $entityManager->persist($article);
            $entityManager->flush();

            try {
                new Version20261025160000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve customized news author names.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Konfigurierte News-Autoren dürfen durch den Migrationsrückbau nicht verloren gehen.',
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

    private function assertDowngradeIsBlocked(Connection $connection): void
    {
        try {
            new Version20261026233000($connection, new NullLogger())->down(new Schema());
            self::fail('A downgrade must preserve member-only news and customized content metadata.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Mitgliederbeschränkte News, Vorschaubilder und Seitenmetadaten müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
                $exception->getMessage(),
            );
        }
    }

    private function assertHistoryDowngradeIsBlocked(Connection $connection): void
    {
        try {
            new Version20261025160000($connection, new NullLogger())->down(new Schema());
            self::fail('A downgrade must preserve recorded news revisions.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Der News-Änderungsverlauf muss vor dem Migrationsrückbau gesichert oder entfernt werden.',
                $exception->getMessage(),
            );
        }
    }
}
