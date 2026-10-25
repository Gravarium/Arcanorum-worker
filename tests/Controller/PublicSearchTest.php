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
}
