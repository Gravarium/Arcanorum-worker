<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\SeriesGenre;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SeriesGenreFeaturedMigrationTest extends KernelTestCase
{
    public function testGenresDefaultToUnfeaturedAndDatabaseRejectsOutOfRangeSortOrders(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $genre = new SeriesGenre('M52 migration '.bin2hex(random_bytes(6)));
        $entityManager->persist($genre);
        $entityManager->flush();

        try {
            $row = $connection->fetchAssociative(
                'SELECT CASE WHEN is_featured THEN 1 ELSE 0 END AS is_featured, featured_sort_order FROM series_genres WHERE id = :id',
                ['id' => $genre->getId()],
            );
            self::assertIsArray($row);
            self::assertTrue(in_array($row['is_featured'], [0, '0'], true));
            self::assertTrue(in_array($row['featured_sort_order'], [0, '0'], true));

            foreach ([-1, 10_001] as $sortOrder) {
                $connection->beginTransaction();
                try {
                    $connection->executeStatement(
                        'UPDATE series_genres SET featured_sort_order = :sort_order WHERE id = :id',
                        ['sort_order' => $sortOrder, 'id' => $genre->getId()],
                    );
                    self::fail('The database must reject a sort order outside 0..10000.');
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
            $connection->delete('series_genres', ['id' => $genre->getId()]);
        }
    }

    public function testDowngradeRefusesToDiscardFeaturedGenreState(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $genre = new SeriesGenre('M52 rollback '.bin2hex(random_bytes(6)));
        $entityManager->persist($genre);
        $entityManager->flush();
        $connection->executeStatement('UPDATE series_genres SET is_featured = TRUE WHERE id = :id', ['id' => $genre->getId()]);

        try {
            try {
                new Version20261030120000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve featured series genres.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Cannot discard featured series genre choices during migration rollback.', $exception->getMessage());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }
}
