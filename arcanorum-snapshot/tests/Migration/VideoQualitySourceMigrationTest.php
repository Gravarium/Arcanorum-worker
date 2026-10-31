<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261026210000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VideoQualitySourceMigrationTest extends KernelTestCase
{
    public function testDatabaseRejectsQualityOutsideTheAllowlist(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $video = $this->video('quality-check-'.$this->uniqueSuffix());

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            try {
                $this->insertSource($connection, $video->getId(), '144p', 'https://media.example.org/video.mp4');
                self::fail('The database must reject a quality outside the fixed allowlist.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseEnforcesUniqueQualityAndSourcePerVideo(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $video = $this->video('quality-unique-'.$this->uniqueSuffix());

        try {
            $entityManager->persist($video);
            $entityManager->flush();
            $this->insertSource($connection, $video->getId(), '720p', 'https://media.example.org/one.mp4');

            try {
                $this->insertSource($connection, $video->getId(), '720p', 'https://media.example.org/two.mp4');
                self::fail('A video cannot have two sources with the same quality.');
            } catch (DriverException $exception) {
                self::assertSame('23505', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDeleteExistingQualitySources(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $video = $this->video('quality-downgrade-'.$this->uniqueSuffix());

        try {
            $entityManager->persist($video);
            $entityManager->flush();
            $this->insertSource($connection, $video->getId(), '1080p', 'https://media.example.org/video-1080.mp4');

            try {
                new Version20261026210000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve configured video quality sources.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Videoqualitätsquellen dürfen durch den Migrationsrückbau nicht verloren gehen.', $exception->getMessage());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function insertSource(
        \Doctrine\DBAL\Connection $connection,
        string $videoId,
        string $resolution,
        string $sourceUrl,
    ): void {
        $connection->executeStatement(
            'INSERT INTO video_quality_sources (id, video_id, resolution, source_url, created_at, updated_at) '
            .'VALUES (:id, :video_id, :resolution, :source_url, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            [
                'id' => bin2hex(random_bytes(16)),
                'video_id' => $videoId,
                'resolution' => $resolution,
                'source_url' => $sourceUrl,
            ],
        );
    }

    private function video(string $title): Video
    {
        return new Video(
            $title,
            'A quality source migration test video.',
            null,
            VideoSourceType::Stream,
            'https://media.example.org/original.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
    }

    private function uniqueSuffix(): string
    {
        return bin2hex(random_bytes(6));
    }
}
