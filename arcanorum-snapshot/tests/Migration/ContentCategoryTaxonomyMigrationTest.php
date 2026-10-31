<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\ContentCategory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030100000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContentCategoryTaxonomyMigrationTest extends KernelTestCase
{
    public function testDatabaseRejectsCategorySortOrderOutsideItsRange(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $id = self::insertCategory($connection, 'sort');
            try {
                $connection->executeStatement(
                    'UPDATE content_categories SET sort_order = 10001 WHERE id = :id',
                    ['id' => $id],
                );
                self::fail('The database must reject a sort order above 10000.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsInvalidSlugs(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $id = self::insertCategory($connection, 'invalid-slug');
            try {
                $connection->executeStatement(
                    'UPDATE content_categories SET slug = :value WHERE id = :id',
                    ['value' => 'bad/slug', 'id' => $id],
                );
                self::fail('The database must reject an invalid category slug.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testCategorySlugsAreUniqueWithinContentTypeButMayBeSharedAcrossTypes(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $newsId = self::insertCategory($connection, 'slug-news', 'news');
            $videoId = self::insertCategory($connection, 'slug-video', 'video');
            $slug = 'shared-category-slug-'.bin2hex(random_bytes(6));
            $connection->executeStatement(
                'UPDATE content_categories SET slug = :slug WHERE id IN (:news, :video)',
                ['slug' => $slug, 'news' => $newsId, 'video' => $videoId],
            );

            $duplicateNewsId = self::insertCategory($connection, 'slug-news-duplicate', 'news');
            try {
                $connection->executeStatement(
                    'UPDATE content_categories SET slug = :slug WHERE id = :id',
                    ['slug' => $slug, 'id' => $duplicateNewsId],
                );
                self::fail('The database must reject duplicate category slugs within one content type.');
            } catch (DriverException $exception) {
                self::assertSame('23505', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsOversizedDescriptions(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $id = self::insertCategory($connection, 'long-description');
            try {
                $connection->executeStatement(
                    'UPDATE content_categories SET description = :value WHERE id = :id',
                    ['value' => str_repeat('x', 2001), 'id' => $id],
                );
                self::fail('The database must reject category descriptions over 2000 characters.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsAnOrphanedParentCategory(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $id = self::insertCategory($connection, 'parent');
            try {
                $connection->executeStatement(
                    'UPDATE content_categories SET parent_id = :parent WHERE id = :id',
                    ['parent' => str_repeat('f', 32), 'id' => $id],
                );
                self::fail('The database must reject a category parent that does not exist.');
            } catch (DriverException $exception) {
                self::assertSame('23503', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsParentCategoriesFromAnotherContentType(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $parentId = self::insertCategory($connection, 'cross-parent', 'video');
            $childId = self::insertCategory($connection, 'cross-child', 'news');
            try {
                $connection->executeStatement(
                    'UPDATE content_categories SET parent_id = :parent WHERE id = :id',
                    ['parent' => $parentId, 'id' => $childId],
                );
                self::fail('The database must reject a parent from another content type.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsSelfParentCategories(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $id = self::insertCategory($connection, 'self-cycle');
            try {
                $connection->executeStatement(
                    'UPDATE content_categories SET parent_id = :parent WHERE id = :id',
                    ['parent' => $id, 'id' => $id],
                );
                self::fail('The database must reject a category as its own parent.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsIndirectCategoryCycles(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $parentId = self::insertCategory($connection, 'cycle-parent');
            $childId = self::insertCategory($connection, 'cycle-child');
            $connection->executeStatement(
                'UPDATE content_categories SET parent_id = :parent WHERE id = :id',
                ['parent' => $parentId, 'id' => $childId],
            );

            try {
                $connection->executeStatement(
                    'UPDATE content_categories SET parent_id = :parent WHERE id = :id',
                    ['parent' => $childId, 'id' => $parentId],
                );
                self::fail('The database must reject an indirect category cycle.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsCategoryTreesDeeperThanEightLevels(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $parentId = self::insertCategory($connection, 'depth-root');
            for ($level = 1; $level < ContentCategory::MAX_DEPTH + 1; ++$level) {
                $childId = self::insertCategory($connection, 'depth-'.$level);
                $connection->executeStatement(
                    'UPDATE content_categories SET parent_id = :parent WHERE id = :id',
                    ['parent' => $parentId, 'id' => $childId],
                );
                $parentId = $childId;
            }

            $tooDeepId = self::insertCategory($connection, 'depth-too-deep');
            try {
                $connection->executeStatement(
                    'UPDATE content_categories SET parent_id = :parent WHERE id = :id',
                    ['parent' => $parentId, 'id' => $tooDeepId],
                );
                self::fail('The database must reject a ninth category level.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardCategoryMetadata(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $id = self::insertCategory($connection, 'downgrade');
            $connection->executeStatement(
                'UPDATE content_categories SET description = :description WHERE id = :id',
                ['description' => 'Category text that must be preserved.', 'id' => $id],
            );

            try {
                new Version20261030100000($connection, new NullLogger())->down(new Schema());
                self::fail('Downgrade must not discard customized category metadata.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Kategorien-Metadaten und Hierarchien dürfen durch den Migrationsrückbau nicht verloren gehen.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function connection(): Connection
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }

    private static function insertCategory(Connection $connection, string $suffix, string $type = 'news'): string
    {
        $id = bin2hex(random_bytes(16));
        $name = 'Taxonomy test '.$suffix.' '.$id;
        $connection->executeStatement(
            'INSERT INTO content_categories (id, name, normalized_name, content_type, slug, description, sort_order, created_at, updated_at) VALUES (:id, :name, :normalized_name, :type, :slug, \'\', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            [
                'id' => $id,
                'name' => $name,
                'normalized_name' => mb_strtolower($name),
                'type' => $type,
                'slug' => 'taxonomy-test-'.$suffix.'-'.$id,
            ],
        );

        return $id;
    }
}
