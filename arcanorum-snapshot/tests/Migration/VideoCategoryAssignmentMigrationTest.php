<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261104160000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VideoCategoryAssignmentMigrationTest extends KernelTestCase
{
    public function testDowngradeRefusesToDiscardSecondaryVideoCategories(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        $primary = new ContentCategory('Rollback primary '.bin2hex(random_bytes(5)), ContentCategoryType::Video);
        $secondary = new ContentCategory('Rollback secondary '.bin2hex(random_bytes(5)), ContentCategoryType::Video);
        $video = new Video(
            'Category rollback video',
            'A multi-category row for migration rollback verification.',
            $primary->getName(),
            VideoSourceType::Url,
            'https://media.example.test/rollback-category.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );
        $video->replaceCategories([$primary, $secondary]);

        try {
            $entityManager->persist($primary);
            $entityManager->persist($secondary);
            $entityManager->persist($video);
            $entityManager->flush();

            try {
                new Version20261104160000($connection, new NullLogger())->down(new Schema());
                self::fail('The downgrade must not discard secondary video category assignments.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Zusätzliche Video-Kategorien müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
