<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentSeries;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicSearchTest extends WebTestCase
{
    public function testSearchShowsPublishedNewsAndVideosButNotDrafts(): void
    {
        $client = static::createClient();
        $client->setServerParameter(
            'REMOTE_ADDR',
            '2001:db8:'.bin2hex(random_bytes(2)).':'.bin2hex(random_bytes(2)).'::1',
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $term = 'needle-'.$suffix;
        $newsSlug = 'search-news-'.$suffix;
        $draftNewsSlug = 'search-draft-news-'.$suffix;
        $newsTitle = 'Search '.$term.' & <script>';
        $draftNewsTitle = 'Draft '.$term.' should stay private';
        $seriesSlug = 'search-series-'.$suffix;
        $draftSeriesSlug = 'search-draft-series-'.$suffix;
        $seriesTitle = 'Series '.$term;
        $draftSeriesTitle = 'Draft series '.$term;
        $videoTitle = 'Video '.$term;
        $draftVideoTitle = 'Draft video '.$term;
        $news = new NewsArticle($newsTitle, $newsSlug, null, null, 'Public body', PublishingStatus::Published, false, false);
        $draftNews = new NewsArticle($draftNewsTitle, $draftNewsSlug, null, null, 'Draft body', PublishingStatus::Draft, false, false);
        $series = new ContentSeries($seriesTitle, $seriesSlug, 'Searchable series description', PublishingStatus::Published);
        $draftSeries = new ContentSeries($draftSeriesTitle, $draftSeriesSlug, 'Draft series description', PublishingStatus::Draft);
        $video = new Video(
            $videoTitle,
            'Public video description',
            null,
            VideoSourceType::Url,
            'https://video.example.test/search-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
        $draftVideo = new Video(
            $draftVideoTitle,
            'Draft video description',
            null,
            VideoSourceType::Url,
            'https://video.example.test/search-draft-'.$suffix.'.mp4',
            PublishingStatus::Draft,
            false,
            false,
        );

        try {
            foreach ([$news, $draftNews, $series, $draftSeries, $video, $draftVideo] as $content) {
                $entityManager->persist($content);
            }
            $entityManager->flush();

            $client->request('GET', '/search?q='.rawurlencode($term));
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('data-suggestions-url="/search/suggestions"', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('data-message-count="%count% Suchvorschläge."', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('Search '.$term.' &amp; &lt;script&gt;', (string) $client->getResponse()->getContent());
            self::assertStringContainsString($videoTitle, (string) $client->getResponse()->getContent());
            self::assertStringContainsString($seriesTitle, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($draftSeriesTitle, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($draftNewsTitle, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($draftVideoTitle, (string) $client->getResponse()->getContent());

            $connection = $entityManager->getConnection();
            $statistics = $connection->fetchAssociative(
                'SELECT term, search_count FROM search_term_daily WHERE day = CURRENT_DATE AND term = :term',
                ['term' => $term],
            );
            self::assertIsArray($statistics);
            self::assertSame($term, $statistics['term']);
            self::assertTrue(1 === $statistics['search_count'] || '1' === $statistics['search_count']);

            $client->request('GET', '/search?q='.rawurlencode($term));
            self::assertResponseIsSuccessful();
            $client->request('GET', '/search?q='.rawurlencode($term).'&page=2');
            self::assertResponseIsSuccessful();
            $count = $connection->fetchOne(
                'SELECT search_count FROM search_term_daily WHERE day = CURRENT_DATE AND term = :term',
                ['term' => $term],
            );
            self::assertTrue(1 === $count || '1' === $count);

            $client->request('GET', '/search?q=%25');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($videoTitle, (string) $client->getResponse()->getContent());

            $client->request('GET', '/search?q='.rawurlencode("invalid\nterm"));
            self::assertResponseStatusCodeSame(400);
            $client->request('GET', '/search?q='.str_repeat('a', 401));
            self::assertResponseStatusCodeSame(400);
            $client->request('GET', '/search?q[]=invalid');
            self::assertResponseStatusCodeSame(400);
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM search_term_daily WHERE term IN (:term, :wildcard)', [
                'term' => $term,
                'wildcard' => '%',
            ]);
            $connection->executeStatement('DELETE FROM news_articles WHERE slug IN (:published, :draft)', [
                'published' => $newsSlug,
                'draft' => $draftNewsSlug,
            ]);
            $connection->executeStatement('DELETE FROM content_series WHERE slug IN (:published, :draft)', [
                'published' => $seriesSlug,
                'draft' => $draftSeriesSlug,
            ]);
            $connection->executeStatement('DELETE FROM videos WHERE id IN (:published, :draft)', [
                'published' => $video->getId(),
                'draft' => $draftVideo->getId(),
            ]);
        }
    }

    public function testLiveSuggestionsReturnOnlyPublishedTitlesFromEnabledContentAreas(): void
    {
        $client = static::createClient();
        $client->setServerParameter('REMOTE_ADDR', '2001:db8:'.bin2hex(random_bytes(2)).':'.bin2hex(random_bytes(2)).'::2');
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $term = 'suggest-'.$suffix;
        $video = new Video('Live '.$term.' <script>', 'A public result', null, VideoSourceType::Url, 'https://video.example.test/live-'.$suffix.'.mp4', PublishingStatus::Published, false, false);
        $draft = new Video('Draft '.$term, 'A private result', null, VideoSourceType::Url, 'https://video.example.test/draft-'.$suffix.'.mp4', PublishingStatus::Draft, false, false);
        $article = new NewsArticle('News '.$term, 'suggest-news-'.$suffix, null, null, 'Public summary', PublishingStatus::Published, false, false);
        $membersOnlyArticle = new NewsArticle(
            'Members-only '.$suffix,
            'suggest-members-'.$suffix,
            null,
            null,
            'Private body '.$term,
            PublishingStatus::Published,
            false,
            false,
            membersOnly: true,
        );
        $series = new ContentSeries('Series '.$term, 'suggest-series-'.$suffix, 'Public description', PublishingStatus::Published);

        try {
            foreach ([$video, $draft, $article, $membersOnlyArticle, $series] as $content) {
                $entityManager->persist($content);
            }
            $entityManager->flush();

            $client->request('GET', '/search/suggestions?q='.rawurlencode($term));
            self::assertResponseIsSuccessful();
            self::assertPrivateNoStore((string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
            self::assertStringNotContainsString('<script>', (string) $client->getResponse()->getContent());
            $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !isset($payload['suggestions']) || !is_array($payload['suggestions'])) {
                throw new \UnexpectedValueException('The suggestions response must contain a list.');
            }

            $suggestions = [];
            foreach ($payload['suggestions'] as $suggestion) {
                if (!is_array($suggestion)
                    || !is_string($suggestion['type'] ?? null)
                    || !is_string($suggestion['title'] ?? null)
                    || !is_string($suggestion['url'] ?? null)
                ) {
                    throw new \UnexpectedValueException('Every suggestion must contain typed fields.');
                }

                $suggestions[] = [
                    'type' => $suggestion['type'],
                    'title' => $suggestion['title'],
                    'url' => $suggestion['url'],
                ];
            }
            self::assertLessThanOrEqual(15, count($suggestions));

            $titles = array_column($suggestions, 'title');
            self::assertContains('Live '.$term.' <script>', $titles);
            self::assertContains('News '.$term, $titles);
            self::assertContains('Series '.$term, $titles);
            self::assertNotContains('Draft '.$term, $titles);
            self::assertNotContains('Members-only '.$suffix, $titles);
            $searchCount = $entityManager->getConnection()->fetchOne(
                'SELECT search_count FROM search_term_daily WHERE day = CURRENT_DATE AND term = :term',
                ['term' => $term],
            );
            self::assertFalse($searchCount, 'Suggestion queries must not add search statistics.');
            foreach ($suggestions as $suggestion) {
                self::assertContains($suggestion['type'], ['video', 'news', 'series']);
                self::assertStringStartsWith('/', $suggestion['url']);
                self::assertFalse(str_starts_with($suggestion['url'], '//'));
            }

            $client->request('GET', '/search/suggestions?q=a');
            self::assertResponseIsSuccessful();
            self::assertSame(['suggestions' => []], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
            $client->request('GET', '/search/suggestions?q[]=invalid');
            self::assertResponseStatusCodeSame(400);
            $client->request('GET', '/search/suggestions?q='.str_repeat('a', 401));
            self::assertResponseStatusCodeSame(400);
            $client->request('GET', '/search/suggestions?q='.rawurlencode("invalid\nterm"));
            self::assertResponseStatusCodeSame(400);
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM search_term_daily WHERE day = CURRENT_DATE AND term = :term', ['term' => $term]);
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => 'suggest-news-'.$suffix]);
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => 'suggest-members-'.$suffix]);
            $connection->executeStatement('DELETE FROM content_series WHERE slug = :slug', ['slug' => 'suggest-series-'.$suffix]);
            $connection->executeStatement('DELETE FROM videos WHERE id IN (:published, :draft)', [
                'published' => $video->getId(),
                'draft' => $draft->getId(),
            ]);
        }
    }

    public function testLiveSuggestionsApplyTheConfiguredRateLimit(): void
    {
        $client = static::createClient();
        $client->setServerParameter('REMOTE_ADDR', '2001:db8:'.bin2hex(random_bytes(2)).':'.bin2hex(random_bytes(2)).'::3');

        for ($attempt = 0; $attempt < 180; ++$attempt) {
            $client->request('GET', '/search/suggestions?q=limit-'.bin2hex(random_bytes(2)));
            self::assertResponseIsSuccessful();
        }

        $client->request('GET', '/search/suggestions?q=limit-exhausted');
        self::assertResponseStatusCodeSame(429);
        self::assertGreaterThanOrEqual(1, (int) $client->getResponse()->headers->get('Retry-After'));
        self::assertPrivateNoStore((string) $client->getResponse()->headers->get('Cache-Control'));
    }

    private static function assertPrivateNoStore(string $cacheControl): void
    {
        $directives = array_map(trim(...), explode(',', mb_strtolower($cacheControl)));
        self::assertContains('private', $directives);
        self::assertContains('no-store', $directives);
    }
}
