<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentTag;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Repository\ContentTagRepository;
use App\Repository\NewsArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentTagFlowTest extends WebTestCase
{
    public function testNewsAndVideoCanBeFoundThroughAssignedTags(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $tagName = 'Tag-'.$suffix;
        $tag = new ContentTag($tagName);
        $article = new NewsArticle(
            'Tagged News '.$suffix,
            'tagged-news-'.$suffix,
            null,
            null,
            'A tagged news article.',
            PublishingStatus::Published,
            false,
            false,
        );
        $article->replaceTags([$tag]);
        $secondArticle = new NewsArticle(
            'Second Tagged News '.$suffix,
            'second-tagged-news-'.$suffix,
            null,
            null,
            'A second tagged news article.',
            PublishingStatus::Published,
            false,
            false,
        );
        $secondArticle->replaceTags([$tag]);
        $video = new Video(
            'Tagged Video '.$suffix,
            'A tagged video.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/tag-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
        $video->replaceTags([$tag]);

        try {
            $entityManager->persist($tag);
            $entityManager->persist($article);
            $entityManager->persist($secondArticle);
            $entityManager->persist($video);
            $entityManager->flush();

            $articleRepository = $client->getContainer()->get(NewsArticleRepository::class);
            self::assertInstanceOf(NewsArticleRepository::class, $articleRepository);
            self::assertSame(2, $articleRepository->countForAdmin('', null, tag: $tagName));
            $adminPage = $articleRepository->findForAdmin('', null, limit: 1, tag: $tagName, sort: 'title', direction: 'asc');
            self::assertCount(1, $adminPage);

            $tagRepository = $client->getContainer()->get(ContentTagRepository::class);
            self::assertInstanceOf(ContentTagRepository::class, $tagRepository);
            self::assertSame(3, $tagRepository->countUsage($tag));

            $client->request('GET', '/news?tag='.rawurlencode($tagName));
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Tagged News '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringContainsString('Second Tagged News '.$suffix, (string) $client->getResponse()->getContent());

            $client->request('GET', '/videos?tag='.rawurlencode($tagName));
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Tagged Video '.$suffix, (string) $client->getResponse()->getContent());

            $client->request('GET', '/news/'.$article->getSlug());
            self::assertResponseIsSuccessful();
            self::assertStringContainsString($tagName, (string) $client->getResponse()->getContent());
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM news_articles WHERE slug IN (:first, :second)', [
                'first' => $article->getSlug(),
                'second' => $secondArticle->getSlug(),
            ]);
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $connection->executeStatement('DELETE FROM content_tags WHERE id = :id', ['id' => $tag->getId()]);
        }
    }
}
