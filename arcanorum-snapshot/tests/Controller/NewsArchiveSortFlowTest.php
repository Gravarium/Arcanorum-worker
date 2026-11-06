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
        $previousProvider = (string) $connection->fetchOne('SELECT comment_provider FROM site_settings WHERE id = 1');

        try {
            $connection->executeStatement("UPDATE site_settings SET comment_provider = 'disqus' WHERE id = 1");
            $client->request('GET', '/news?order=comments');
            self::assertResponseIsSuccessful();
            self::assertSame('newest', $client->getCrawler()->filter('#news-order option[selected]')->attr('value'));
            self::assertSame(0, $client->getCrawler()->filter('#news-order option[value="comments"]')->count());
        } finally {
            $connection->executeStatement('UPDATE site_settings SET comment_provider = :provider WHERE id = 1', ['provider' => $previousProvider]);
        }
    }
}
