<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ContentSeries;
use App\Entity\PublishingStatus;
use App\Entity\SeriesGenre;
use App\Repository\ContentSeriesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContentSeriesRepositoryFeaturedTest extends KernelTestCase
{
    public function testGenreListingReturnsOnlyTheNewestPublishedSeriesUpToTheRequestedLimit(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $repository = $entityManager->getRepository(ContentSeries::class);
        self::assertInstanceOf(ContentSeriesRepository::class, $repository);
        $connection = $entityManager->getConnection();
        $suffix = bin2hex(random_bytes(6));
        $genre = new SeriesGenre('M52 repository '.$suffix);
        $published = [];

        $entityManager->persist($genre);
        for ($index = 0; $index < 12; ++$index) {
            $series = new ContentSeries(
                sprintf('M52 repository series %s %d', $suffix, $index),
                sprintf('m52-repository-%s-%d', $suffix, $index),
                'Published series description',
                PublishingStatus::Published,
                [$genre],
            );
            $published[$index] = $series;
            $entityManager->persist($series);
        }
        $draft = new ContentSeries(
            'M52 repository draft '.$suffix,
            'm52-repository-'.$suffix.'-draft',
            'Draft series description',
            PublishingStatus::Draft,
            [$genre],
        );
        $entityManager->persist($draft);
        $entityManager->flush();

        foreach ($published as $index => $series) {
            $connection->update(
                'content_series',
                ['published_at' => new \DateTimeImmutable(sprintf('2026-10-%02d 12:00:00 UTC', $index + 1))],
                ['id' => $series->getId()],
                ['published_at' => Types::DATETIME_IMMUTABLE],
            );
        }
        $connection->update(
            'content_series',
            ['published_at' => new \DateTimeImmutable('2027-01-01 12:00:00 UTC')],
            ['id' => $draft->getId()],
            ['published_at' => Types::DATETIME_IMMUTABLE],
        );

        try {
            $result = $repository->findPublishedByGenre($genre->getName(), 10, 0);
            $expectedIds = [];
            for ($index = 11; $index >= 2; --$index) {
                $expectedIds[] = $published[$index]->getId();
            }
            self::assertCount(10, $result);
            self::assertSame(
                $expectedIds,
                array_map(static fn (ContentSeries $series): string => $series->getId(), $result),
            );
        } finally {
            $connection->executeStatement('DELETE FROM content_series WHERE slug LIKE :prefix', ['prefix' => 'm52-repository-'.$suffix.'-%']);
            $connection->delete('series_genres', ['id' => $genre->getId()]);
        }
    }
}
