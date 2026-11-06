<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NewsFeaturedCardFlowTest extends WebTestCase
{
    public function testOnlyPublicFeaturedNewsReceivesTheWideArchiveCard(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $suffix = bin2hex(random_bytes(6));

        $featuredArticle = $this->article('Featured public '.$suffix, 'featured-public-'.$suffix, true);
        $normalArticle = $this->article('Normal public '.$suffix, 'normal-public-'.$suffix, false);
        $membersOnlyArticle = $this->article('Featured members-only '.$suffix, 'featured-members-'.$suffix, true, true);
        $articles = [$featuredArticle, $normalArticle, $membersOnlyArticle];

        try {
            foreach ($articles as $article) {
                $entityManager->persist($article);
            }
            $entityManager->flush();

            $client->request('GET', '/news?q='.$suffix);

            self::assertResponseIsSuccessful();
            $crawler = $client->getCrawler();
            $featuredCards = $crawler->filter('article.news-card-featured');
            self::assertCount(1, $featuredCards);
            self::assertSame('Featured public '.$suffix, $featuredCards->filter('h2 a')->text());

            $normalCard = $crawler->filterXPath('//article[.//h2/a[@href="/news/'.$normalArticle->getSlug().'"]]');
            $membersOnlyCard = $crawler->filterXPath('//article[.//h2/a[@href="/news/'.$membersOnlyArticle->getSlug().'"]]');
            self::assertCount(1, $normalCard);
            self::assertCount(1, $membersOnlyCard);
            self::assertStringNotContainsString('news-card-featured', (string) $normalCard->attr('class'));
            self::assertStringNotContainsString('news-card-featured', (string) $membersOnlyCard->attr('class'));
        } finally {
            $connection->executeStatement(
                'DELETE FROM news_articles WHERE id IN (:ids)',
                ['ids' => array_map(static fn (NewsArticle $article): string => $article->getId(), $articles)],
                ['ids' => ArrayParameterType::STRING],
            );
        }
    }

    private function article(string $title, string $slug, bool $featured, bool $membersOnly = false): NewsArticle
    {
        return new NewsArticle(
            $title,
            $slug,
            'News',
            'A short news summary.',
            'A public news article body.',
            PublishingStatus::Published,
            $featured,
            true,
            'Editorial team',
            $membersOnly,
        );
    }
}
