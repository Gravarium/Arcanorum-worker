<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\CommentFilterAction;
use App\Entity\CommentFilterTerm;
use App\Entity\ContentSeries;
use App\Entity\PublishingStatus;
use App\Entity\SeriesEpisode;
use App\Entity\SeriesGenre;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261018070000;
use DoctrineMigrations\Version20261018080000;
use DoctrineMigrations\Version20261018090000;
use DoctrineMigrations\Version20261018100000;
use DoctrineMigrations\Version20261018110000;
use DoctrineMigrations\Version20261025150000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HistoricalFeatureMigrationRollbackTest extends KernelTestCase
{
    public function testUploadMigrationRefusesToLosePrivateVideoFiles(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $video = new Video(
                'Uploaded video '.$suffix,
                'Private upload',
                null,
                VideoSourceType::File,
                '',
                PublishingStatus::Draft,
                false,
                false,
            );
            $video->attachUploadedMedia(str_repeat('a', 64), 'video/mp4', 128);
            $entityManager->persist($video);
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018070000($connection, new NullLogger()),
                'Privat gespeicherte Video-Uploads müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testVideoThumbnailMigrationRefusesToLosePrivateThumbnailReferences(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $video = new Video(
                'Video thumbnail '.$suffix,
                'Description',
                null,
                VideoSourceType::Url,
                'https://media.example.test/video',
                PublishingStatus::Draft,
                false,
                false,
            );
            $video->attachThumbnail(str_repeat('b', 64), 'image/png', 128);
            $entityManager->persist($video);
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018100000($connection, new NullLogger()),
                'Gespeicherte Video-Vorschaubilder müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testSeriesArtworkMigrationRefusesToLosePrivateCoverReferences(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $series = new ContentSeries('Series '.$suffix, 'series-'.$suffix, 'Description', PublishingStatus::Draft);
            $series->attachArtwork(str_repeat('c', 64), 'image/webp', 128);
            $entityManager->persist($series);
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018110000($connection, new NullLogger()),
                'Gespeicherte Seriencover müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testSeriesGenreMigrationRefusesToDiscardGenreAssignments(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $genre = new SeriesGenre('Genre '.$suffix);
            $series = new ContentSeries('Series '.$suffix, 'series-'.$suffix, 'Description', PublishingStatus::Draft, [$genre]);
            $entityManager->persist($genre);
            $entityManager->persist($series);
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018080000($connection, new NullLogger()),
                'Seriengenres, Episodenentwürfe und eigene Episodenmetadaten müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testSeriesEpisodeMigrationRefusesToPublishDraftEpisodesOnDowngrade(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $series = new ContentSeries('Series '.$suffix, 'series-'.$suffix, 'Description', PublishingStatus::Published);
            $video = new Video(
                'Episode '.$suffix,
                'Description',
                null,
                VideoSourceType::Url,
                'https://media.example.test/'.$suffix,
                PublishingStatus::Draft,
                false,
                false,
            );
            $episode = new SeriesEpisode($series, $video, 1, 1, status: PublishingStatus::Draft);
            $entityManager->persist($series);
            $entityManager->persist($video);
            $entityManager->persist($episode);
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018080000($connection, new NullLogger()),
                'Seriengenres, Episodenentwürfe und eigene Episodenmetadaten müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testSeriesEpisodeMigrationRefusesToDiscardOverrides(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $series = new ContentSeries('Series '.$suffix, 'series-'.$suffix, 'Description', PublishingStatus::Published);
            $video = new Video(
                'Episode '.$suffix,
                'Description',
                null,
                VideoSourceType::Url,
                'https://media.example.test/'.$suffix,
                PublishingStatus::Published,
                false,
                false,
            );
            $episode = new SeriesEpisode(
                $series,
                $video,
                1,
                1,
                'Custom episode title',
                'Custom episode description',
            );
            $entityManager->persist($series);
            $entityManager->persist($video);
            $entityManager->persist($episode);
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018080000($connection, new NullLogger()),
                'Seriengenres, Episodenentwürfe und eigene Episodenmetadaten müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testCommentFilterMigrationRefusesToDiscardRules(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $term = new CommentFilterTerm('blocked phrase', 'blocked phrase', CommentFilterAction::Reject);
            $entityManager->persist($term);
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261018090000($connection, new NullLogger()),
                'Kommentar-Filterregeln und dokumentierte Treffer müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testEmbedOriginMigrationRefusesToDiscardConfiguredPolicy(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(8));
            $video = new Video(
                'Embeddable video '.$suffix,
                'Description',
                null,
                VideoSourceType::Embed,
                'https://video.example.test/'.$suffix,
                PublishingStatus::Published,
                false,
                false,
            );
            $video->setEmbedAllowedOrigins(['https://partner.example.test']);
            $entityManager->persist($video);
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261025150000($connection, new NullLogger()),
                'Konfigurierte externe Einbettungsfreigaben müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
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
            self::fail('The downgrade must not discard or expose configured feature data.');
        } catch (\RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
