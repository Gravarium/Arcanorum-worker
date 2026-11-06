<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CommentContentType;
use App\Entity\ContentComment;
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
            false,
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
        $approvedPublicComment = new ContentComment(CommentContentType::News, $publicArticle->getId(), $publicTitle, 'Reviewer', 'Approved public comment.');
        $approvedPublicComment->approve();
        $pendingPublicComment = new ContentComment(CommentContentType::News, $publicArticle->getId(), $publicTitle, 'Reviewer', 'Pending public comment.');
        $reportedPublicComment = new ContentComment(CommentContentType::News, $publicArticle->getId(), $publicTitle, 'Reviewer', 'Reported public comment.');
        $reportedPublicComment->approve();
        $reportedPublicComment->report();
        $reportedPublicComment->report();
        $reportedPublicComment->report();
        $approvedDisabledArticleComment = new ContentComment(CommentContentType::News, $normalArticle->getId(), $normalTitle, 'Reviewer', 'Comment on disabled comments.');
        $approvedDisabledArticleComment->approve();
        $approvedMemberComment = new ContentComment(CommentContentType::News, $membersArticle->getId(), $memberTitle, 'Reviewer', 'Private article comment.');
        $approvedMemberComment->approve();
        $comments = [
            $approvedPublicComment,
            $pendingPublicComment,
            $reportedPublicComment,
            $approvedDisabledArticleComment,
            $approvedMemberComment,
        ];

        try {
            $entityManager->persist($publicArticle);
            $entityManager->persist($normalArticle);
            $entityManager->persist($membersArticle);
            foreach ($comments as $comment) {
                $entityManager->persist($comment);
            }
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
            self::assertSelectorExists('#news-order option[value="comments"]');
            self::assertCount(1, $publicCard->filter('.news-featured-badge'));
            self::assertSame('Hervorgehoben', $publicCard->filter('.news-featured-badge')->text());
            self::assertCount(0, $normalCard->filter('.news-featured-badge'));
            self::assertCount(0, $membersCard->filter('.news-featured-badge'));
            self::assertCount(1, $publicCard->filter('.news-comment-count'));
            self::assertStringContainsString('1', $publicCard->filter('.news-comment-count')->text());
            self::assertCount(0, $normalCard->filter('.news-comment-count'));
            self::assertCount(0, $membersCard->filter('.news-comment-count'));
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
            self::assertStringContainsString('2', $client->getCrawler()->filter('.public-article .news-reading-time')->text());

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
                'DELETE FROM content_comments WHERE content_type = :type AND content_id IN (:ids)',
                ['type' => 'news', 'ids' => [$publicArticle->getId(), $normalArticle->getId(), $membersArticle->getId()]],
                ['ids' => ArrayParameterType::STRING],
            );
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
            self::assertSame(0, $client->getCrawler()->filter('.content-search input[name="page"]')->count());
            $previousUrl = (string) $client->getCrawler()->filter('.pagination a')->first()->attr('href');
            parse_str((string) parse_url($previousUrl, PHP_URL_QUERY), $previousQuery);
            self::assertSame('Archive sort search '.$suffix, $previousQuery['q'] ?? null);
            self::assertSame('Archive category '.$suffix, $previousQuery['category'] ?? null);
            self::assertSame('archive-sort-'.$suffix, $previousQuery['tag'] ?? null);
            self::assertSame($selectedOrder, $previousQuery['order'] ?? null);
            self::assertSame('1', $previousQuery['page'] ?? null);

            $client->submitForm('Filtern', ['order' => 'popular']);
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
        $suffix = bin2hex(random_bytes(6));
        $title = 'External comment count '.$suffix;
        $article = new NewsArticle(
            $title,
            'external-comment-count-'.$suffix,
            'News',
            'Public summary.',
            'Public body.',
            PublishingStatus::Published,
            false,
            true,
        );
        $comment = new ContentComment(CommentContentType::News, $article->getId(), $title, 'Reviewer', 'Approved comment.');
        $comment->approve();
        $previousSettings = $connection->fetchAssociative(
            'SELECT comment_provider, disqus_comments_shortname, cookie_consent_enabled::text AS cookie_consent_enabled, module_comments_enabled::text AS module_comments_enabled FROM site_settings WHERE id = 1',
        );
        self::assertIsArray($previousSettings);
        self::assertIsString($previousSettings['comment_provider']);
        self::assertIsString($previousSettings['disqus_comments_shortname']);
        self::assertContains($previousSettings['cookie_consent_enabled'], ['true', 'false']);
        self::assertContains($previousSettings['module_comments_enabled'], ['true', 'false']);

        try {
            $entityManager->persist($article);
            $entityManager->persist($comment);
            $entityManager->flush();
            $connection->executeStatement("UPDATE site_settings SET comment_provider = 'disqus', disqus_comments_shortname = 'test-news-archive', cookie_consent_enabled = TRUE WHERE id = 1");
            $client->request('GET', '/news?'.http_build_query(['q' => $suffix, 'order' => 'comments']));
            self::assertResponseIsSuccessful();
            self::assertSame('newest', $client->getCrawler()->filter('#news-order option[selected]')->attr('value'));
            self::assertSame(0, $client->getCrawler()->filter('#news-order option[value="comments"]')->count());
            self::assertCount(0, $client->getCrawler()->filter('.content-card .news-comment-count'));

            $connection->executeStatement("UPDATE site_settings SET comment_provider = 'native', module_comments_enabled = FALSE WHERE id = 1");
            $client->request('GET', '/news?q='.urlencode($suffix));
            self::assertResponseIsSuccessful();
            self::assertCount(0, $client->getCrawler()->filter('.content-card .news-comment-count'));
        } finally {
            $connection->executeStatement(
                'UPDATE site_settings SET comment_provider = :provider, disqus_comments_shortname = :shortname, cookie_consent_enabled = :cookie_consent, module_comments_enabled = :comments_enabled WHERE id = 1',
                [
                    'provider' => $previousSettings['comment_provider'],
                    'shortname' => $previousSettings['disqus_comments_shortname'],
                    'cookie_consent' => 'true' === $previousSettings['cookie_consent_enabled'],
                    'comments_enabled' => 'true' === $previousSettings['module_comments_enabled'],
                ],
            );
            $connection->executeStatement(
                'DELETE FROM content_comments WHERE content_type = :type AND content_id = :id',
                ['type' => 'news', 'id' => $article->getId()],
            );
            $connection->executeStatement('DELETE FROM news_articles WHERE id = :id', ['id' => $article->getId()]);
        }
    }
}
