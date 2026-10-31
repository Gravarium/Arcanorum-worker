<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\ContentInlineImage;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\StaticPage;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030020000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContentInlineImageMigrationTest extends KernelTestCase
{
    public function testDatabaseEnforcesImageOwnerAndMetadataConstraints(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $suffix = bin2hex(random_bytes(8));
        $article = new NewsArticle('Inline image news '.$suffix, 'inline-news-'.$suffix, null, null, 'Body', PublishingStatus::Draft, false, false);
        $page = new StaticPage('Inline image page '.$suffix, 'inline-page-'.$suffix, null, 'Body', PublishingStatus::Draft);
        $entityManager->persist($article);
        $entityManager->persist($page);
        $entityManager->flush();

        /** @var list<array{id: string, news_article_id: string|null, static_page_id: string|null, mime_type: string, size: int, sha256: string}> $invalidRows */
        $invalidRows = [
            ['id' => str_repeat('a', 64), 'news_article_id' => null, 'static_page_id' => null, 'mime_type' => 'image/png', 'size' => 128, 'sha256' => str_repeat('b', 64)],
            ['id' => str_repeat('c', 64), 'news_article_id' => $article->getId(), 'static_page_id' => $page->getId(), 'mime_type' => 'image/png', 'size' => 128, 'sha256' => str_repeat('d', 64)],
            ['id' => str_repeat('e', 64), 'news_article_id' => $article->getId(), 'static_page_id' => null, 'mime_type' => 'image/svg+xml', 'size' => 128, 'sha256' => str_repeat('f', 64)],
            ['id' => str_repeat('1', 64), 'news_article_id' => null, 'static_page_id' => $page->getId(), 'mime_type' => 'image/png', 'size' => 4_194_305, 'sha256' => str_repeat('2', 64)],
            ['id' => str_repeat('3', 64), 'news_article_id' => null, 'static_page_id' => $page->getId(), 'mime_type' => 'image/webp', 'size' => 128, 'sha256' => 'not-a-digest'],
            ['id' => 'not-a-hash', 'news_article_id' => $article->getId(), 'static_page_id' => null, 'mime_type' => 'image/jpeg', 'size' => 128, 'sha256' => str_repeat('4', 64)],
        ];

        try {
            foreach ($invalidRows as $row) {
                $connection->beginTransaction();
                try {
                    $connection->executeStatement(
                        'INSERT INTO content_inline_images (id, news_article_id, static_page_id, mime_type, size, sha256, created_at) VALUES (:id, :news_article_id, :static_page_id, :mime_type, :size, :sha256, CURRENT_TIMESTAMP)',
                        $row,
                    );
                    self::fail('The database must reject unsafe inline image metadata.');
                } catch (DriverException $exception) {
                    self::assertSame('23514', $exception->getSQLState());
                } finally {
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                }
            }
        } finally {
            $entityManager->clear();
            $connection->delete('news_articles', ['id' => $article->getId()]);
            $connection->delete('static_pages', ['id' => $page->getId()]);
        }
    }

    public function testPageDeletionCascadesImageMetadataAndDowngradeRefusesDataLoss(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $suffix = bin2hex(random_bytes(8));
        $page = new StaticPage('Inline image page '.$suffix, 'inline-page-'.$suffix, null, 'Body', PublishingStatus::Draft);
        $entityManager->persist($page);
        $entityManager->flush();
        $image = new ContentInlineImage(str_repeat('5', 64), null, $page, 'image/png', 128, str_repeat('6', 64));
        $entityManager->persist($image);
        $entityManager->flush();

        try {
            $connection->beginTransaction();
            try {
                try {
                    new Version20261030020000($connection, new NullLogger())->down(new Schema());
                    self::fail('A downgrade must preserve uploaded images.');
                } catch (\RuntimeException $exception) {
                    self::assertSame(
                        'Inline-Bilder müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
                        $exception->getMessage(),
                    );
                }
            } finally {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }

            $entityManager->clear();
            $connection->delete('static_pages', ['id' => $page->getId()]);
            $remainingImages = filter_var($connection->fetchOne(
                'SELECT COUNT(*) FROM content_inline_images WHERE id = :id',
                ['id' => $image->getId()],
            ), FILTER_VALIDATE_INT);
            self::assertSame(0, $remainingImages);
        } finally {
            $entityManager->clear();
            $connection->delete('static_pages', ['id' => $page->getId()]);
        }
    }
}
