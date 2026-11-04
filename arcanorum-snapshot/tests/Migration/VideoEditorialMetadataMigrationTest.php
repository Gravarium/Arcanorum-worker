<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261104140000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VideoEditorialMetadataMigrationTest extends KernelTestCase
{
    public function testDatabaseRejectsMalformedVideoPermalinks(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $video = $this->video('video-slug-constraint-'.bin2hex(random_bytes(4)));

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            try {
                $connection->executeStatement(
                    'UPDATE videos SET slug = :slug WHERE id = :id',
                    ['slug' => 'Invalid Slug', 'id' => $video->getId()],
                );
                self::fail('The database must reject a permalink outside the normalized format.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsMalformedVideoLanguageCodes(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $video = $this->video('video-language-constraint-'.bin2hex(random_bytes(4)));

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            try {
                $connection->executeStatement(
                    'UPDATE videos SET language_code = :language WHERE id = :id',
                    ['language' => 'bad language', 'id' => $video->getId()],
                );
                self::fail('The database must reject a malformed video language code.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardCustomizedVideoMetadata(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $video = $this->video('video-metadata-downgrade-'.bin2hex(random_bytes(4)));
        $video->updateEditorialMetadata('custom-video-slug', 'Editorial Desk', true, true, 'de');

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            try {
                new Version20261104140000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve customized editorial metadata.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Redaktionelle Video-Metadaten dürfen durch den Migrationsrückbau nicht verloren gehen.',
                    $exception->getMessage(),
                );
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
            'A video migration test.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            PublishingStatus::Draft,
            false,
            true,
        );
    }
}
