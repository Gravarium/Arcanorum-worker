<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261106113000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NewsArticleViewCountMigrationTest extends KernelTestCase
{
    public function testViewCountConstraintAndRollbackPreserveCollectedData(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $suffix = bin2hex(random_bytes(6));
        $article = new NewsArticle('Migration '.$suffix, 'migration-'.$suffix, null, null, 'Body', PublishingStatus::Published, false, true);

        try {
            $entityManager->persist($article);
            $entityManager->flush();
            self::assertSame('0', $connection->fetchOne('SELECT view_count::text FROM news_articles WHERE id = :id', ['id' => $article->getId()]));

            $connection->beginTransaction();
            try {
                $connection->executeStatement('UPDATE news_articles SET view_count = -1 WHERE id = :id', ['id' => $article->getId()]);
                self::fail('Negative view counts must be rejected by the database constraint.');
            } catch (DriverException) {
                // The nested transaction rolls back the rejected statement without aborting the migration checks.
            } finally {
                $connection->rollBack();
            }
            self::assertSame('0', $connection->fetchOne('SELECT view_count::text FROM news_articles WHERE id = :id', ['id' => $article->getId()]));

            $connection->executeStatement('UPDATE news_articles SET view_count = 1 WHERE id = :id', ['id' => $article->getId()]);
            self::assertSame('1', $connection->fetchOne('SELECT view_count::text FROM news_articles WHERE id = :id', ['id' => $article->getId()]));

            try {
                new Version20261106113000($connection, new NullLogger())->down(new Schema());
                self::fail('Collected view counts must not be discarded by migration rollback.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Gespeicherte News-Aufrufzahlen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.', $exception->getMessage());
            }
        } finally {
            $entityManager->clear();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
