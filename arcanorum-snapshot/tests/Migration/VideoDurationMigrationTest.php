<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261026230000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VideoDurationMigrationTest extends KernelTestCase
{
    public function testDatabaseAllowsValidDurationAndRejectsNegativeDuration(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $video = $this->video('duration-constraint-'.$this->uniqueSuffix());
        $video->setDurationSeconds(2147483647);

        try {
            $entityManager->persist($video);
            $entityManager->flush();
            self::assertSame(2147483647, filter_var($connection->fetchOne(
                'SELECT duration_seconds FROM videos WHERE id = :id',
                ['id' => $video->getId()],
            ), FILTER_VALIDATE_INT));

            try {
                $connection->executeStatement(
                    'UPDATE videos SET duration_seconds = -1 WHERE id = :id',
                    ['id' => $video->getId()],
                );
                self::fail('The database must reject negative durations.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDeleteStoredDurations(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $video = $this->video('duration-downgrade-'.$this->uniqueSuffix());
        $video->setDurationSeconds(120);

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            try {
                new Version20261026230000($connection, new NullLogger())->down(new Schema());
                self::fail('The migration downgrade must preserve stored durations.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Gespeicherte Videolaufzeiten dürfen durch den Migrationsrückbau nicht verloren gehen.', $exception->getMessage());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function video(string $title): Video
    {
        return new Video(
            $title,
            'A duration migration test video.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/'.$this->uniqueSuffix().'.mp4',
            PublishingStatus::Draft,
            false,
            false,
        );
    }

    private function uniqueSuffix(): string
    {
        return bin2hex(random_bytes(6));
    }
}
