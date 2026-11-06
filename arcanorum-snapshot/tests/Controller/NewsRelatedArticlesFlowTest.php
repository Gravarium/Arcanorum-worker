<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentTag;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class NewsRelatedArticlesFlowTest extends WebTestCase
{
    public function testRecommendationsOnlyShowThreeDistinctPublishedPublicRelatedArticles(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $suffix = bin2hex(random_bytes(6));

        $firstTag = new ContentTag('news-related-first-'.$suffix);
        $secondTag = new ContentTag('news-related-second-'.$suffix);
        $unrelatedTag = new ContentTag('news-related-unrelated-'.$suffix);
        $category = 'News topic '.$suffix;

        $source = $this->article('News source '.$suffix, 'news-related-source-'.$suffix, $category);
        $source->replaceTags([$firstTag, $secondTag]);
        $multipleMatch = $this->article('News match both tags '.$suffix, 'news-related-both-'.$suffix, $category);
        $multipleMatch->replaceTags([$firstTag, $secondTag]);
        $firstTagMatch = $this->article('News match first tag '.$suffix, 'news-related-first-match-'.$suffix, null);
        $firstTagMatch->replaceTags([$firstTag]);
        $secondTagMatch = $this->article('News match second tag '.$suffix, 'news-related-second-match-'.$suffix, null);
        $secondTagMatch->replaceTags([$secondTag]);
        $categoryMatch = $this->article('News match category '.$suffix, 'news-related-category-'.$suffix, ' news topic '.$suffix.' ');
        $overflowMatch = $this->article('News overflow match '.$suffix, 'news-related-overflow-'.$suffix, $category);
        $unrelatedArticle = $this->article('News unrelated '.$suffix, 'news-related-unrelated-'.$suffix, 'Different topic '.$suffix);
        $unrelatedArticle->replaceTags([$unrelatedTag]);
        $membersOnlyMatch = $this->article('News private match '.$suffix, 'news-related-private-match-'.$suffix, null, membersOnly: true);
        $membersOnlyMatch->replaceTags([$firstTag]);
        $draftMatch = $this->article('News draft match '.$suffix, 'news-related-draft-'.$suffix, null, status: PublishingStatus::Draft);
        $draftMatch->replaceTags([$firstTag]);
        $futureMatch = $this->article('News future match '.$suffix, 'news-related-future-'.$suffix, null);
        $futureMatch->replaceTags([$firstTag]);
        $membersOnlySource = $this->article('News private source '.$suffix, 'news-related-private-source-'.$suffix, $category, membersOnly: true);
        $membersOnlySource->replaceTags([$firstTag]);
        $unclassifiedSource = $this->article('News unclassified source '.$suffix, 'news-related-unclassified-'.$suffix, null);
        $articles = [
            $source,
            $multipleMatch,
            $firstTagMatch,
            $secondTagMatch,
            $categoryMatch,
            $overflowMatch,
            $unrelatedArticle,
            $membersOnlyMatch,
            $draftMatch,
            $futureMatch,
            $membersOnlySource,
            $unclassifiedSource,
        ];
        $tags = [$firstTag, $secondTag, $unrelatedTag];

        try {
            foreach ($tags as $tag) {
                $entityManager->persist($tag);
            }
            foreach ($articles as $article) {
                $entityManager->persist($article);
            }
            $entityManager->flush();

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $publicationOrder = [
                ['article' => $multipleMatch, 'minutes_ago' => 1],
                ['article' => $firstTagMatch, 'minutes_ago' => 2],
                ['article' => $secondTagMatch, 'minutes_ago' => 3],
                ['article' => $categoryMatch, 'minutes_ago' => 4],
                ['article' => $overflowMatch, 'minutes_ago' => 5],
            ];
            foreach ($publicationOrder as $entry) {
                $connection->executeStatement(
                    'UPDATE news_articles SET published_at = :publishedAt WHERE id = :id',
                    [
                        'publishedAt' => $now->sub(new \DateInterval('PT'.$entry['minutes_ago'].'M')),
                        'id' => $entry['article']->getId(),
                    ],
                    ['publishedAt' => Types::DATETIME_IMMUTABLE],
                );
            }
            $connection->executeStatement(
                'UPDATE news_articles SET published_at = CURRENT_TIMESTAMP + INTERVAL \'1 day\' WHERE id = :id',
                ['id' => $futureMatch->getId()],
            );

            $client->request('GET', '/news/'.$source->getSlug());

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('#news-related-heading');
            $cards = $client->getCrawler()->filter('.news-related-card');
            self::assertCount(3, $cards);
            $titles = $cards->filter('h3')->each(static fn (Crawler $node): string => $node->text());
            self::assertSame([
                'News match both tags '.$suffix,
                'News match first tag '.$suffix,
                'News match second tag '.$suffix,
            ], $titles);
            self::assertCount(count(array_unique($titles)), $titles);
            self::assertSelectorNotExists('.news-related-card a[href="/news/'.$source->getSlug().'"]');
            $response = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('News overflow match '.$suffix, $response);
            self::assertStringNotContainsString('News unrelated '.$suffix, $response);
            self::assertStringNotContainsString('News private match '.$suffix, $response);
            self::assertStringNotContainsString('News draft match '.$suffix, $response);
            self::assertStringNotContainsString('News future match '.$suffix, $response);

            $client->request('GET', '/news/'.$membersOnlySource->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.members-only-notice');
            self::assertSelectorNotExists('#news-related-heading');

            $client->request('GET', '/news/'.$unclassifiedSource->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('#news-related-heading');
            self::assertSelectorNotExists('.news-related');
        } finally {
            $connection->executeStatement(
                'DELETE FROM news_articles WHERE id IN (:ids)',
                ['ids' => array_map(static fn (NewsArticle $article): string => $article->getId(), $articles)],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM content_tags WHERE id IN (:ids)',
                ['ids' => array_map(static fn (ContentTag $tag): string => $tag->getId(), $tags)],
                ['ids' => ArrayParameterType::STRING],
            );
        }
    }

    private function article(
        string $title,
        string $slug,
        ?string $category,
        PublishingStatus $status = PublishingStatus::Published,
        bool $membersOnly = false,
    ): NewsArticle {
        return new NewsArticle(
            $title,
            $slug,
            $category,
            'A related news summary.',
            'A public news article body.',
            $status,
            false,
            true,
            'Editorial team',
            $membersOnly,
        );
    }
}
