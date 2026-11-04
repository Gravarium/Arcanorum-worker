<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Repository\ContentCategoryRepository;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoCategoryAssignmentRepositoryTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $testKey = base64_encode(str_repeat('C', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$testKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;

        parent::setUpBeforeClass();
    }

    public function testSecondaryVideoCategoryFiltersAndFeaturedCategoryLookupUseAssignments(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $videoRepository = $entityManager->getRepository(Video::class);
        $categoryRepository = $entityManager->getRepository(ContentCategory::class);
        self::assertInstanceOf(VideoRepository::class, $videoRepository);
        self::assertInstanceOf(ContentCategoryRepository::class, $categoryRepository);

        $suffix = bin2hex(random_bytes(6));
        $primary = new ContentCategory('Primary '.$suffix, ContentCategoryType::Video);
        $secondary = new ContentCategory('Secondary '.$suffix, ContentCategoryType::Video);
        $secondary->setFeatured(true);
        $video = new Video(
            'Multiple category repository video '.$suffix,
            'A video assigned to two categories.',
            $primary->getName(),
            VideoSourceType::Url,
            'https://media.example.test/category-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $video->replaceCategories([$primary, $secondary]);
        $video->setPublicationAt(new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')));

        try {
            $entityManager->persist($primary);
            $entityManager->persist($secondary);
            $entityManager->persist($video);
            $entityManager->flush();

            self::assertSame([$video->getId()], array_map(
                static fn (Video $item): string => $item->getId(),
                $videoRepository->findPublishedForCategory($secondary->getName()),
            ));
            self::assertSame(1, $videoRepository->countForAdmin('', null, $secondary->getName()));
            self::assertSame(1, $categoryRepository->countUsage($secondary));
            self::assertContains($secondary->getId(), array_map(
                static fn (ContentCategory $category): string => $category->getId(),
                $categoryRepository->findFeaturedVideoCategories(),
            ));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM content_categories WHERE id IN (:primary, :secondary)',
                ['primary' => $primary->getId(), 'secondary' => $secondary->getId()],
            );
        }
    }
}
