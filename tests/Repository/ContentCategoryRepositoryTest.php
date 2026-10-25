<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Repository\ContentCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentCategoryRepositoryTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $testKey = base64_encode(str_repeat('T', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$testKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;

        parent::setUpBeforeClass();
    }

    public function testCategoriesAreSeparatedByTypeAndRenamesUpdateAssignedContent(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $repository = $entityManager->getRepository(ContentCategory::class);
        self::assertInstanceOf(ContentCategoryRepository::class, $repository);

        $suffix = bin2hex(random_bytes(6));
        $name = 'Games '.$suffix;
        $renamed = 'Arcade '.$suffix;
        $newsSlug = 'category-news-'.$suffix;
        $news = new NewsArticle(
            'Category news '.$suffix,
            $newsSlug,
            $name,
            null,
            'Category test body',
            PublishingStatus::Draft,
            false,
            true,
        );
        $video = new Video(
            'Category video '.$suffix,
            'Category test description',
            $name,
            VideoSourceType::Url,
            'https://video.example.test/category.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );
        $newsCategory = new ContentCategory($name, ContentCategoryType::News);
        $videoCategory = new ContentCategory($name, ContentCategoryType::Video);

        try {
            $entityManager->persist($newsCategory);
            $entityManager->persist($videoCategory);
            $entityManager->persist($news);
            $entityManager->persist($video);
            $entityManager->flush();

            self::assertSame($newsCategory, $repository->findForTypeAndName(ContentCategoryType::News, '  GAMES '.$suffix.' '));
            self::assertSame($videoCategory, $repository->findForTypeAndName(ContentCategoryType::Video, $name));
            self::assertSame(1, $repository->countUsage($newsCategory));
            self::assertSame(1, $repository->countUsage($videoCategory));

            $entityManager->getConnection()->transactional(function () use ($repository, $newsCategory, $renamed, $entityManager): void {
                $repository->renameAssignedContent($newsCategory, $renamed);
                $newsCategory->rename($renamed);
                $entityManager->flush();
            });

            self::assertSame(1, $repository->countUsage($newsCategory));
            self::assertSame($renamed, $entityManager->getConnection()->fetchOne(
                'SELECT category FROM news_articles WHERE slug = :slug',
                ['slug' => $newsSlug],
            ));
            self::assertSame($name, $entityManager->getConnection()->fetchOne(
                'SELECT category FROM videos WHERE id = :id',
                ['id' => $video->getId()],
            ));
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $newsSlug]);
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $newsCategory->getId()]);
            $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $videoCategory->getId()]);
        }
    }

    public function testCategoryAdministrationRequiresAnAdministrator(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/categories');

        self::assertResponseRedirects('/admin/login');
    }
}
