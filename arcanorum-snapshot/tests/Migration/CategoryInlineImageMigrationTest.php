<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\ContentInlineImage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030130000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CategoryInlineImageMigrationTest extends KernelTestCase
{
    public function testDatabaseEnforcesExactlyOneOwnerAndAcceptsCategoryImages(): void
    {
        [$entityManager, $connection] = $this->database();
        $suffix = bin2hex(random_bytes(6));
        $category = new ContentCategory('Inline category '.$suffix, ContentCategoryType::News);
        $entityManager->persist($category);
        $entityManager->flush();

        $categoryImageId = bin2hex(random_bytes(32));
        $connection->executeStatement(
            'INSERT INTO content_inline_images (id, news_article_id, static_page_id, category_id, mime_type, size, sha256, created_at) '
            .'VALUES (:id, NULL, NULL, :category_id, :mime_type, :size, :sha256, CURRENT_TIMESTAMP)',
            [
                'id' => $categoryImageId,
                'category_id' => $category->getId(),
                'mime_type' => 'image/png',
                'size' => 128,
                'sha256' => str_repeat('c', 64),
            ],
        );

        $invalidOwners = [
            [null, null, null],
            [str_repeat('a', 32), null, $category->getId()],
            [null, str_repeat('b', 32), $category->getId()],
        ];

        try {
            foreach ($invalidOwners as $index => [$newsId, $pageId, $categoryId]) {
                $connection->beginTransaction();
                try {
                    $connection->executeStatement(
                        'INSERT INTO content_inline_images (id, news_article_id, static_page_id, category_id, mime_type, size, sha256, created_at) '
                        .'VALUES (:id, :news_id, :page_id, :category_id, :mime_type, :size, :sha256, CURRENT_TIMESTAMP)',
                        [
                            'id' => str_repeat((string) ($index + 1), 64),
                            'news_id' => $newsId,
                            'page_id' => $pageId,
                            'category_id' => $categoryId,
                            'mime_type' => 'image/jpeg',
                            'size' => 128,
                            'sha256' => str_repeat('d', 64),
                        ],
                    );
                    self::fail('The database must reject missing or multiple inline-image owners.');
                } catch (DriverException $exception) {
                    self::assertSame('23514', $exception->getSQLState());
                } finally {
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                }
            }

            self::assertSame($category->getId(), $connection->fetchOne(
                'SELECT category_id FROM content_inline_images WHERE id = :id',
                ['id' => $categoryImageId],
            ));
        } finally {
            $connection->executeStatement('DELETE FROM content_inline_images WHERE id = :id', ['id' => $categoryImageId]);
            $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $category->getId()]);
        }
    }

    public function testCategoryDeletionCascadesAndDowngradeRefusesCategoryImageData(): void
    {
        [$entityManager, $connection] = $this->database();
        $category = new ContentCategory('Inline category cascade '.bin2hex(random_bytes(6)), ContentCategoryType::Video);
        $entityManager->persist($category);
        $entityManager->flush();
        $image = new ContentInlineImage(
            bin2hex(random_bytes(32)),
            null,
            null,
            'image/webp',
            256,
            str_repeat('e', 64),
            $category,
        );
        $entityManager->persist($image);
        $entityManager->flush();

        try {
            $connection->beginTransaction();
            try {
                try {
                    new Version20261030130000($connection, new NullLogger())->down(new Schema());
                    self::fail('A downgrade must preserve category image references.');
                } catch (\RuntimeException $exception) {
                    self::assertSame(
                        'Kategorie-Inline-Bilder müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
                        $exception->getMessage(),
                    );
                }

                $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $category->getId()]);
                self::assertSame(0, self::databaseInteger($connection->fetchOne(
                    'SELECT COUNT(*) FROM content_inline_images WHERE id = :id',
                    ['id' => $image->getId()],
                )));
                new Version20261030130000($connection, new NullLogger())->down(new Schema());
            } finally {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
        } finally {
            $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $category->getId()]);
        }
    }

    /** @return array{EntityManagerInterface, Connection} */
    private function database(): array
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return [$entityManager, $entityManager->getConnection()];
    }

    private static function databaseInteger(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        self::assertIsInt($integer);

        return $integer;
    }
}
