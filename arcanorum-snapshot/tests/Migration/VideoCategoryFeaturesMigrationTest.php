<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030110000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VideoCategoryFeaturesMigrationTest extends KernelTestCase
{
    public function testDatabaseRejectsFeaturedNewsAndPartialOrUnsafeArtworkMetadata(): void
    {
        $connection = $this->connection();
        self::assertConstraintViolation(
            $connection,
            'featured-news',
            'news',
            'UPDATE content_categories SET is_featured = TRUE WHERE id = :id',
        );
        self::assertConstraintViolation(
            $connection,
            'partial-artwork',
            'video',
            'UPDATE content_categories SET artwork_object_id = :object_id WHERE id = :id',
            ['object_id' => str_repeat('a', 64)],
        );
        self::assertConstraintViolation(
            $connection,
            'unsafe-artwork',
            'video',
            'UPDATE content_categories SET artwork_object_id = :object_id, artwork_mime_type = :mime, artwork_size = 12, artwork_sha256 = :sha WHERE id = :id',
            ['object_id' => str_repeat('a', 64), 'mime' => 'image/svg+xml', 'sha' => str_repeat('b', 64)],
        );
    }

    public function testDowngradeRefusesToDiscardFeaturedCategoryStateOrArtworkMetadata(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $id = self::insertCategory($connection, 'featured', 'video');
            $connection->executeStatement('UPDATE content_categories SET is_featured = TRUE WHERE id = :id', ['id' => $id]);

            try {
                new Version20261030110000($connection, new NullLogger())->down(new Schema());
                self::fail('Downgrade must preserve featured category data.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Cannot remove featured category data or artwork during migration rollback.', $exception->getMessage());
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

    /** @param array<string, int|string> $extraParameters */
    private static function assertConstraintViolation(
        Connection $connection,
        string $suffix,
        string $type,
        string $sql,
        array $extraParameters = [],
    ): void {
        $connection->beginTransaction();

        try {
            $id = self::insertCategory($connection, $suffix, $type);
            try {
                $connection->executeStatement($sql, ['id' => $id] + $extraParameters);
                self::fail('The database must reject invalid featured category or artwork metadata.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private static function insertCategory(Connection $connection, string $suffix, string $type): string
    {
        $id = bin2hex(random_bytes(16));
        $name = 'Featured migration '.$suffix.' '.$id;
        $connection->executeStatement(
            'INSERT INTO content_categories (id, name, normalized_name, content_type, slug, description, sort_order, created_at, updated_at) VALUES (:id, :name, :normalized_name, :type, :slug, \'\', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            [
                'id' => $id,
                'name' => $name,
                'normalized_name' => mb_strtolower($name),
                'type' => $type,
                'slug' => 'featured-migration-'.$suffix.'-'.$id,
            ],
        );

        return $id;
    }
}
