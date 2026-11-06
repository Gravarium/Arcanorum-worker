<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentTag;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NewsArchiveSortFlowTest extends WebTestCase
{
    public function testArchiveAndArticleShowEditorialMetadataWithoutLeakingMembersOnlyReadingTime(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $suffix = bin2hex(random_bytes(6));
        $publicTitle = 'Editorial metadata public '.$suffix;
        $normalTitle = 'Editorial metadata normal '.$suffix;
        $memberTitle = 'Editorial metadata members '.$suffix;
        $memberBodyMarker = 'members-only-secret-canary-'.$suffix;
        $publicArticle = new NewsArticle(
            $publicTitle,
            'editorial-metadata-public-'.$suffix,
            'Editorial '.$suffix,
            'A public summary.',
            str_repeat('Leseprobe ', 201),
            PublishingStatus::Published,
            true,
            true,
            'Redaktion',
        );
        $normalArticle = new NewsArticle(
            $normalTitle,
            'editorial-metadata-normal-'.$suffix,
            'Editorial '.$suffix,
            'A regular public summary.',
            'A regular public article.',
            PublishingStatus::Published,
            false,
            true,
            'Redaktion',
        );
        $membersArticle = new NewsArticle(
            $memberTitle,
            'editorial-metadata-members-'.$suffix,
            'Editorial '.$suffix,
            'A members-only summary.',
            str_repeat($memberBodyMarker.' ', 401),
            PublishingStatus::Published,
            false,
            true,
            'Redaktion',
            true,
        );

        try {
            $entityManager->persist($publicArticle);
            $entityManager->persist($normalArticle);
            $entityManager->persist($membersArticle);
            $entityManager->flush();

            $client->request('GET', '/news?q='.urlencode($suffix));
            self::assertResponseIsSuccessful();
            $archive = $client->getCrawler();
            $publicCard = $archive->filterXPath(sprintf('//article[contains(@class, "content-card") and contains(., "%s")]', $publicTitle));
            $normalCard = $archive->filterXPath(sprintf('//article[contains(@class, "content-card") and contains(., "%s")]', $normalTitle));
            $membersCard = $archive->filterXPath(sprintf('//article[contains(@class, "content-card") and contains(., "%s")]', $memberTitle));
            self::assertCount(1, $publicCard);
            self::assertCount(1, $normalCard);
            self::assertCount(1, $membersCard);
            self::assertCount(1, $publicCard->filter('.news-featured-badge'));
            self::assertSame('Hervorgehoben', $publicCard->filter('.news-featured-badge')->text());
            self::assertCount(0, $normalCard->filter('.news-featured-badge'));
            self::assertCount(0, $membersCard->filter('.news-featured-badge'));
            self::assertCount(1, $publicCard->filter('.news-reading-time'));
            self::assertStringContainsString('2', $publicCard->filter('.news-reading-time')->text());
            self::assertCount(1, $normalCard->filter('.news-reading-time'));
            self::assertStringContainsString('1', $normalCard->filter('.news-reading-time')->text());
            self::assertStringContainsString('Redaktion', $normalCard->filter('.byline')->text());
            self::assertCount(1, $normalCard->filter('time[datetime]'));
            self::assertCount(0, $membersCard->filter('.news-reading-time'));

            $client->request('GET', '/news/'.$publicArticle->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.public-article .news-featured-badge');
            self::assertSelectorExists('.public-article .news-reading-time');
            self::assertStringContainsString('2', (string) $client->getCrawler()->filter('.public-article .news-reading-time')->text());

            $client->request('GET', '/news/'.$normalArticle->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.public-article .news-featured-badge');
            self::assertSelectorExists('.public-article .news-reading-time');

            $client->request('GET', '/news/'.$membersArticle->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.members-only-notice');
            self::assertSelectorNotExists('.public-article .news-reading-time');
            self::assertStringNotContainsString($memberBodyMarker, (string) $client->getResponse()->getContent());
        } finally {
            $connection->executeStatement(
                'DELETE FROM news_articles WHERE id IN (:ids)',
                ['ids' => [$publicArticle->getId(), $normalArticle->getId(), $membersArticle->getId()]],
                ['ids' => ArrayParameterType::STRING],
            );
        }
    }

    public function testArchiveSortSelectionPreservesSearchFiltersAndPagination(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(6));
        $tag = new ContentTag('archive-sort-'.$suffix);
        $articles = [];

        for ($index = 0; $index < 25; ++$index) {
            $article = new NewsArticle(
                'Archive sort search '.$suffix.' '.$index,
                'archive-sort-'.$suffix.'-'.$index,
                'Archive category '.$suffix,
                'Archive summary '.$suffix,
                'Archive test body.',
                PublishingStatus::Published,
                false,
                true,
            );
            $article->replaceTags([$tag]);
            $articles[] = $article;
        }

        try {
            $entityManager->persist($tag);
            foreach ($articles as $article) {
                $entityManager->persist($article);
            }
            $entityManager->flush();
            $entityManager->getConnection()->executeStatement(
                'UPDATE news_articles SET view_count = CASE WHEN slug = :popular_slug THEN 100 ELSE 0 END WHERE slug LIKE :slug_prefix',
                ['popular_slug' => $articles[0]->getSlug(), 'slug_prefix' => 'archive-sort-'.$suffix.'-%'],
            );

            $client->request('GET', '/news?'.http_build_query([
                'q' => 'Archive sort search '.$suffix,
                'category' => 'Archive category '.$suffix,
                'tag' => 'archive-sort-'.$suffix,
                'order' => 'popular',
            ]));
            self::assertResponseIsSuccessful();
            self::assertSame('popular', $client->getCrawler()->filter('#news-order option[selected]')->attr('value'));
            self::assertStringEndsWith(
                '/news/'.$articles[0]->getSlug(),
                (string) $client->getCrawler()->filter('.content-grid .content-card h2 a')->first()->attr('href'),
            );
            $nextUrl = (string) $client->getCrawler()->filter('.pagination a')->last()->attr('href');
            parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $nextQuery);
            self::assertSame('Archive sort search '.$suffix, $nextQuery['q'] ?? null);
            self::assertSame('Archive category '.$suffix, $nextQuery['category'] ?? null);
            self::assertSame('archive-sort-'.$suffix, $nextQuery['tag'] ?? null);
            self::assertSame('popular', $nextQuery['order'] ?? null);
            self::assertSame('2', $nextQuery['page'] ?? null);

            $client->request('GET', '/news?'.http_build_query([
                'q' => 'Archive sort search '.$suffix,
                'category' => 'Archive category '.$suffix,
                'tag' => 'archive-sort-'.$suffix,
                'order' => 'comments',
                'page' => '2',
            ]));
            self::assertResponseIsSuccessful();
            $selectedOrder = 0 === $client->getCrawler()->filter('#news-order option[value="comments"]')->count() ? 'newest' : 'comments';
            self::assertSame($selectedOrder, $client->getCrawler()->filter('#news-order option[selected]')->attr('value'));
            self::assertSame('2', $client->getCrawler()->filter('input[name="page"]')->attr('value'));
            $previousUrl = (string) $client->getCrawler()->filter('.pagination a')->first()->attr('href');
            parse_str((string) parse_url($previousUrl, PHP_URL_QUERY), $previousQuery);
            self::assertSame('Archive sort search '.$suffix, $previousQuery['q'] ?? null);
            self::assertSame('Archive category '.$suffix, $previousQuery['category'] ?? null);
            self::assertSame('archive-sort-'.$suffix, $previousQuery['tag'] ?? null);
            self::assertSame($selectedOrder, $previousQuery['order'] ?? null);
            self::assertSame('1', $previousQuery['page'] ?? null);
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM news_articles WHERE id IN (:ids)',
                ['ids' => array_map(static fn (NewsArticle $article): string => $article->getId(), $articles)],
                ['ids' => ArrayParameterType::STRING],
            );
            $entityManager->getConnection()->executeStatement('DELETE FROM content_tags WHERE id = :id', ['id' => $tag->getId()]);
        }
    }

    public function testUnknownAndArraySortValuesFallBackToNewest(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        foreach (['/news?order=unknown', '/news?order%5B%5D=popular'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSame('newest', $client->getCrawler()->filter('#news-order option[selected]')->attr('value'));
        }
    }

    public function testExternalCommentProviderHidesCommentSortAndFallsBackToNewest(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $previousSettings = $connection->fetchAssociative(
            'SELECT comment_provider, disqus_comments_shortname, cookie_consent_enabled::text AS cookie_consent_enabled FROM site_settings WHERE id = 1',
        );
        self::assertIsArray($previousSettings);
        self::assertIsString($previousSettings['comment_provider']);
        self::assertIsString($previousSettings['disqus_comments_shortname']);
        self::assertContains($previousSettings['cookie_consent_enabled'], ['true', 'false']);

        try {
            $connection->executeStatement("UPDATE site_settings SET comment_provider = 'disqus', disqus_comments_shortname = 'test-news-archive', cookie_consent_enabled = TRUE WHERE id = 1");
            $client->request('GET', '/news?order=comments');
            self::assertResponseIsSuccessful();
            self::assertSame('newest', $client->getCrawler()->filter('#news-order option[selected]')->attr('value'));
            self::assertSame(0, $client->getCrawler()->filter('#news-order option[value="comments"]')->count());
        } finally {
            $connection->executeStatement(
                'UPDATE site_settings SET comment_provider = :provider, disqus_comments_shortname = :shortname, cookie_consent_enabled = :cookie_consent WHERE id = 1',
                [
                    'provider' => $previousSettings['comment_provider'],
                    'shortname' => $previousSettings['disqus_comments_shortname'],
                    'cookie_consent' => 'true' === $previousSettings['cookie_consent_enabled'],
                ],
            );
        }
    }
}
