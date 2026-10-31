<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\AdminUser;
use App\Entity\NewsArticle;
use App\Entity\NewsArticleRevision;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030010000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CustomFieldsMigrationTest extends KernelTestCase
{
    public function testDatabaseRejectsNonArrayCustomFieldsForContentAndHistory(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $suffix = bin2hex(random_bytes(8));
        $video = new Video('Custom video '.$suffix, '', null, VideoSourceType::Url, 'https://example.test/'.$suffix, PublishingStatus::Draft, false, false);
        $article = new NewsArticle('Custom news '.$suffix, 'custom-news-'.$suffix, null, null, '', PublishingStatus::Draft, false, false);
        $editor = new AdminUser('custom-fields-'.$suffix.'@example.test');
        $editor->setPassword('migration-test-hash');
        $revision = new NewsArticleRevision($article, 1, $editor);

        try {
            $entityManager->persist($video);
            $entityManager->persist($article);
            $entityManager->persist($editor);
            $entityManager->persist($revision);
            $entityManager->flush();

            foreach ([
                ['videos', $video->getId()],
                ['news_articles', $article->getId()],
                ['news_article_revisions', $revision->getId()],
            ] as [$table, $id]) {
                $connection->beginTransaction();
                try {
                    $connection->executeStatement(
                        sprintf('UPDATE %s SET custom_fields = :fields WHERE id = :id', $table),
                        ['fields' => '{}', 'id' => $id],
                    );
                    self::fail(sprintf('The %s table must reject an object instead of an array.', $table));
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
            $connection->delete('videos', ['id' => $video->getId()]);
            $connection->delete('admin_users', ['id' => $editor->getId()]);
        }
    }

    public function testDowngradeRefusesToDiscardCustomFields(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $suffix = bin2hex(random_bytes(8));
        $video = new Video('Custom video '.$suffix, '', null, VideoSourceType::Url, 'https://example.test/'.$suffix, PublishingStatus::Draft, false, false);
        $connection->beginTransaction();

        try {
            $entityManager->persist($video);
            $entityManager->flush();
            $connection->executeStatement(
                'UPDATE videos SET custom_fields = :fields WHERE id = :id',
                ['fields' => json_encode([['key' => 'License', 'value' => 'CC-BY']], JSON_THROW_ON_ERROR), 'id' => $video->getId()],
            );

            try {
                new Version20261030010000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve configured custom fields.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Benutzerdefinierte Metafelder dürfen durch den Migrationsrückbau nicht verloren gehen.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }
}
