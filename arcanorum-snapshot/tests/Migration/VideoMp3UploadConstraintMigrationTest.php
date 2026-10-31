<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030050500;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VideoMp3UploadConstraintMigrationTest extends KernelTestCase
{
    public function testVideoUploadConstraintAllowsMp3AndRejectsNonMediaMimeTypes(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $video = new Video(
            'MP3 constraint '.bin2hex(random_bytes(8)),
            'A validated MP3 file remains a private media upload.',
            null,
            VideoSourceType::File,
            '',
            PublishingStatus::Draft,
            false,
            false,
        );
        $video->attachUploadedMedia(bin2hex(random_bytes(32)), 'audio/mpeg', 2048);

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            $connection->beginTransaction();
            try {
                $connection->executeStatement(
                    'UPDATE videos SET uploaded_mime_type = :mime_type WHERE id = :id',
                    ['mime_type' => 'image/png', 'id' => $video->getId()],
                );
                self::fail('The database constraint must reject non-media upload MIME types.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            } finally {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
        } finally {
            $entityManager->clear();
            $connection->delete('videos', ['id' => $video->getId()]);
        }
    }

    public function testDowngradeRefusesToInvalidateExistingMp3Uploads(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $video = new Video(
            'MP3 rollback '.bin2hex(random_bytes(8)),
            'An MP3 upload must survive a migration rollback.',
            null,
            VideoSourceType::File,
            '',
            PublishingStatus::Draft,
            false,
            false,
        );
        $video->attachUploadedMedia(bin2hex(random_bytes(32)), 'audio/mpeg', 2048);

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            $connection->beginTransaction();
            try {
                new Version20261030050500($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve existing MP3 uploads.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'MP3-Uploads dürfen durch den Migrationsrückbau nicht ungültig werden.',
                    $exception->getMessage(),
                );
            } finally {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
        } finally {
            $entityManager->clear();
            $connection->delete('videos', ['id' => $video->getId()]);
        }
    }
}
