<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CommentContentType;
use App\Entity\CommentFilterAction;
use App\Entity\CommentFilterTerm;
use App\Entity\CommentStatus;
use App\Entity\ContentComment;
use App\Entity\MemberAccount;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Repository\ContentCommentRepository;
use App\Service\CommentWordFilterNormalizer;
use App\Service\SiteSettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CommentFlowTest extends WebTestCase
{
    public function testEmojiHelpCatalogIsSearchableAndUsesOnlyLocalUnicodeCharacters(): void
    {
        $client = static::createClient();
        $client->request('GET', '/hilfe/emoji');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Emoji-Hilfe');
        self::assertSelectorExists('[data-emoji-catalog-search]');
        self::assertSelectorCount(8, '[data-emoji-catalog-category]');
        self::assertSelectorExists('[data-emoji-catalog-category="people"] [data-emoji-value="😀"]');
        self::assertStringNotContainsString('src="http', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('/comments/emoji-picker.js', (string) $client->getResponse()->getContent());
    }

    public function testEmojiCatalogEndpointExposesTheCompleteUnicodeCatalog(): void
    {
        $client = static::createClient();
        $client->request('GET', '/hilfe/emoji/katalog.json');

        self::assertResponseIsSuccessful();
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('public', (string) $client->getResponse()->headers->get('Cache-Control'));

        $catalog = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($catalog);
        self::assertIsArray($catalog['categories'] ?? null);
        self::assertCount(8, $catalog['categories']);

        $values = [];
        foreach ($catalog['categories'] as $category) {
            self::assertIsArray($category);
            self::assertIsArray($category['items'] ?? null);
            foreach ($category['items'] as $item) {
                self::assertIsArray($item);
                self::assertIsString($item['value'] ?? null);
                $values[] = $item['value'];
            }
        }

        self::assertCount(3963, $values);
        self::assertCount(3963, array_unique($values));
        self::assertContains('🫩', $values);
        self::assertContains("🏴\u{E0067}\u{E0062}\u{E0077}\u{E006C}\u{E0073}\u{E007F}", $values);
    }

    public function testPublicCommentWaitsForModerationAndIsNotShown(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $slug = 'comment-flow-'.$suffix;
        $article = new NewsArticle(
            'Comment flow '.$suffix,
            $slug,
            'Community',
            null,
            'Comment form test body',
            PublishingStatus::Published,
            false,
            true,
        );

        try {
            $entityManager->persist($article);
            $entityManager->flush();

            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('#news-comment-body-emoji-dialog[aria-labelledby="news-comment-body-emoji-dialog-title"]');
            self::assertSelectorExists('[data-emoji-picker][data-emoji-catalog-url="/hilfe/emoji/katalog.json"]');
            self::assertSelectorExists('form.comment-form a[href="/hilfe/emoji"]');
            $token = $client->getCrawler()->filter('form.comment-form input[name="_token"]')->attr('value');
            self::assertIsString($token);

            $client->request('POST', '/comments/news/'.$slug, [
                '_token' => $token,
                'author_name' => 'Test Visitor',
                'body' => 'A useful comment awaiting review. 😀 ❤️',
                'website' => '',
            ]);
            self::assertResponseRedirects('/news/'.$slug.'#comments');
            self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));

            $repository = $entityManager->getRepository(ContentComment::class);
            self::assertInstanceOf(ContentCommentRepository::class, $repository);
            $comment = $repository->findOneBy(['contentId' => $article->getId()]);
            self::assertInstanceOf(ContentComment::class, $comment);
            self::assertSame(CommentStatus::Pending, $comment->getStatus());
            self::assertSame(CommentContentType::News, $comment->getContentType());
            self::assertSame('A useful comment awaiting review. 😀 ❤️', $comment->getBody());

            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#comments', 'Dein Kommentar wurde zur Prüfung eingereicht.');
            self::assertStringNotContainsString(
                'A useful comment awaiting review. 😀 ❤️',
                $client->getCrawler()->filter('main')->text(),
            );
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_comments WHERE content_id = :id', ['id' => $article->getId()]);
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $slug]);
        }
    }

    public function testGuestCommentSettingBlocksAnonymousWritersButAllowsSignedInMembers(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settings = $client->getContainer()->get(SiteSettingsManager::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settings);

        $suffix = bin2hex(random_bytes(8));
        $slug = 'guest-comments-'.$suffix;
        $article = new NewsArticle(
            'Guest comments '.$suffix,
            $slug,
            'Community',
            null,
            'Guest comment policy test body',
            PublishingStatus::Published,
            false,
            true,
        );
        $video = new Video(
            'Guest comments video '.$suffix,
            'Video used to verify the guest comment policy.',
            'Community',
            VideoSourceType::Embed,
            'https://youtu.be/dQw4w9WgXcQ',
            PublishingStatus::Published,
            false,
            true,
        );
        $member = new MemberAccount('guest-comments-'.$suffix.'@example.test', 'guestcomments'.$suffix, 'Commenting member');
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $entityManager->persist($article);
            $entityManager->persist($video);
            $entityManager->persist($member);
            $entityManager->flush();
            self::assertSame([], $settings->updateContentOptions(['guest_comments_enabled' => '1']));

            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="/comments/news/'.$slug.'"]');
            $token = (string) $client->getCrawler()
                ->filter('form[action="/comments/news/'.$slug.'"] input[name="_token"]')
                ->attr('value');
            self::assertNotSame('', $token);

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="/comments/video/'.$video->getId().'"]');
            $videoToken = (string) $client->getCrawler()
                ->filter('form[action="/comments/video/'.$video->getId().'"] input[name="_token"]')
                ->attr('value');
            self::assertNotSame('', $videoToken);

            self::assertSame([], $settings->updateContentOptions(['guest_comments_enabled' => '0']));

            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('form[action="/comments/news/'.$slug.'"]');
            self::assertSelectorTextContains('#comments', 'Bitte melde dich an, um einen Kommentar zu schreiben.');

            $guestBody = 'Anonymous comment must be rejected.';
            $client->request('POST', '/comments/news/'.$slug, [
                '_token' => $token,
                'author_name' => 'Anonymous visitor',
                'body' => $guestBody,
                'website' => '',
            ]);
            self::assertResponseRedirects('/news/'.$slug.'#comments');
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
            self::assertFalse($connection->fetchOne('SELECT id FROM content_comments WHERE body = :body', ['body' => $guestBody]));

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('form[action="/comments/video/'.$video->getId().'"]');
            self::assertSelectorTextContains('#comments', 'Bitte melde dich an, um einen Kommentar zu schreiben.');

            $guestVideoBody = 'Anonymous video comment must be rejected.';
            $client->request('POST', '/comments/video/'.$video->getId(), [
                '_token' => $videoToken,
                'author_name' => 'Anonymous visitor',
                'body' => $guestVideoBody,
                'website' => '',
            ]);
            self::assertResponseRedirects('/videos/'.$video->getId().'#comments');
            self::assertFalse($connection->fetchOne('SELECT id FROM content_comments WHERE body = :body', ['body' => $guestVideoBody]));

            $client->loginUser($member, 'members');
            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="/comments/news/'.$slug.'"]');
            $memberToken = (string) $client->getCrawler()
                ->filter('form[action="/comments/news/'.$slug.'"] input[name="_token"]')
                ->attr('value');
            self::assertNotSame('', $memberToken);

            $memberBody = 'A signed-in member may still comment.';
            $client->request('POST', '/comments/news/'.$slug, [
                '_token' => $memberToken,
                'author_name' => 'Commenting member',
                'body' => $memberBody,
                'website' => '',
            ]);
            self::assertResponseRedirects('/news/'.$slug.'#comments');
            self::assertSame($member->getId(), $connection->fetchOne(
                'SELECT member_id FROM content_comments WHERE body = :body',
                ['body' => $memberBody],
            ));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testVideoCommentSubmissionUsesTheSameModerationQueue(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $video = new Video(
            'Video comment flow',
            'Video comment test description',
            'Community',
            VideoSourceType::Url,
            'https://video.example.test/comment-flow.mp4',
            PublishingStatus::Published,
            false,
            true,
        );

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('#video-comment-body-emoji-dialog[aria-labelledby="video-comment-body-emoji-dialog-title"]');
            self::assertSelectorExists('[data-emoji-picker][data-emoji-catalog-url="/hilfe/emoji/katalog.json"]');
            self::assertSelectorExists('form.comment-form a[href="/hilfe/emoji"]');
            $token = $client->getCrawler()
                ->filter(sprintf('form.comment-form[action="/comments/video/%s"] input[name="_token"]', $video->getId()))
                ->attr('value');
            self::assertIsString($token);

            $client->request('POST', '/comments/video/'.$video->getId(), [
                '_token' => $token,
                'author_name' => 'Video Visitor',
                'body' => 'A video comment awaiting review.',
                'website' => '',
            ]);
            self::assertResponseRedirects('/videos/'.$video->getId().'#comments');

            $currentEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $currentEntityManager);
            $repository = $currentEntityManager->getRepository(ContentComment::class);
            self::assertInstanceOf(ContentCommentRepository::class, $repository);
            $comment = $repository->findOneBy(['contentId' => $video->getId()]);
            self::assertInstanceOf(ContentComment::class, $comment);
            self::assertSame(CommentStatus::Pending, $comment->getStatus());
            self::assertSame(CommentContentType::Video, $comment->getContentType());
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_comments WHERE content_id = :id', ['id' => $video->getId()]);
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
        }
    }

    public function testPublicReportFlagsAnApprovedCommentForModeration(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $slug = 'reported-comment-'.$suffix;
        $article = new NewsArticle(
            'Report test '.$suffix,
            $slug,
            'Community',
            null,
            'Report form test body',
            PublishingStatus::Published,
            false,
            true,
        );
        $comment = new ContentComment(
            CommentContentType::News,
            $article->getId(),
            $article->getTitle(),
            'Public author',
            '<script>bad()</script> Approved comment to report.',
        );
        $comment->approve();

        try {
            $entityManager->persist($article);
            $entityManager->persist($comment);
            $entityManager->flush();

            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#comments', '<script>bad()</script> Approved comment to report.');
            self::assertSelectorNotExists('#comments script');
            $token = $client->getCrawler()
                ->filter(sprintf('form[action="/comments/%s/report"] input[name="_token"]', $comment->getId()))
                ->attr('value');
            self::assertIsString($token);

            $client->request('POST', '/comments/'.$comment->getId().'/report', ['_token' => 'invalid-token']);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/comments/'.$comment->getId().'/report', ['_token' => $token]);
            self::assertResponseRedirects('/news/'.$slug.'#comments');
            self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            $currentEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $currentEntityManager);
            $currentEntityManager->clear();
            $reportedComment = $currentEntityManager->getRepository(ContentComment::class)->find($comment->getId());
            self::assertInstanceOf(ContentComment::class, $reportedComment);
            self::assertSame(CommentStatus::Approved, $reportedComment->getStatus());
            self::assertSame(1, $reportedComment->getReportCount());

            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#comments', 'Der Kommentar wurde zur Prüfung gemeldet.');
            self::assertSelectorTextContains('#comments', '<script>bad()</script> Approved comment to report.');
            self::assertSelectorNotExists('#comments script');
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_comments WHERE id = :id', ['id' => $comment->getId()]);
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $slug]);
        }
    }

    public function testDisabledCommentsCannotBeSubmitted(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $slug = 'comments-disabled-'.$suffix;
        $article = new NewsArticle(
            'Comments disabled '.$suffix,
            $slug,
            'Community',
            null,
            'Disabled comment test body',
            PublishingStatus::Published,
            false,
            false,
        );

        try {
            $entityManager->persist($article);
            $entityManager->flush();

            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('form.comment-form');

            $client->request('POST', '/comments/news/'.$slug, [
                'author_name' => 'Blocked visitor',
                'body' => 'This should never be stored.',
                'website' => '',
            ]);
            self::assertResponseRedirects('/news/'.$slug.'#comments');
            $client->followRedirect();
            self::assertSelectorTextContains('#comments', 'Kommentare sind für diesen Inhalt deaktiviert.');

            $repository = $entityManager->getRepository(ContentComment::class);
            self::assertInstanceOf(ContentCommentRepository::class, $repository);
            self::assertNull($repository->findOneBy(['contentId' => $article->getId()]));
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_comments WHERE content_id = :id', ['id' => $article->getId()]);
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $slug]);
        }
    }

    public function testCommentSubmissionRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $slug = 'comment-csrf-'.$suffix;
        $article = new NewsArticle(
            'Comment CSRF '.$suffix,
            $slug,
            'Community',
            null,
            'CSRF test body',
            PublishingStatus::Published,
            false,
            true,
        );

        try {
            $entityManager->persist($article);
            $entityManager->flush();

            $client->request('POST', '/comments/news/'.$slug, [
                '_token' => 'invalid-token',
                'author_name' => 'Blocked visitor',
                'body' => 'This should never be stored.',
                'website' => '',
            ]);
            self::assertResponseStatusCodeSame(403);

            $repository = $entityManager->getRepository(ContentComment::class);
            self::assertInstanceOf(ContentCommentRepository::class, $repository);
            self::assertNull($repository->findOneBy(['contentId' => $article->getId()]));
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_comments WHERE content_id = :id', ['id' => $article->getId()]);
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $slug]);
        }
    }

    public function testWordFilteredCommentWaitsForModerationAndShowsTheMatchedTerm(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $termData = CommentWordFilterNormalizer::parseTerm('Spoiler Alert');
        $filterTerm = new CommentFilterTerm($termData['term'], $termData['normalized']);
        $suffix = bin2hex(random_bytes(8));
        $slug = 'word-filter-'.$suffix;
        $article = new NewsArticle(
            'Word filter '.$suffix,
            $slug,
            'Community',
            null,
            'Word filter test body',
            PublishingStatus::Published,
            false,
            true,
        );

        try {
            $entityManager->persist($filterTerm);
            $entityManager->persist($article);
            $entityManager->flush();

            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            $token = $client->getCrawler()->filter('form.comment-form input[name="_token"]')->attr('value');
            self::assertIsString($token);

            $body = "This contains SPOILER\t\nALERT in a comment.";
            $client->request('POST', '/comments/news/'.$slug, [
                '_token' => $token,
                'author_name' => 'Filtered Visitor',
                'body' => $body,
                'website' => '',
            ]);
            self::assertResponseRedirects('/news/'.$slug.'#comments');

            $repository = $entityManager->getRepository(ContentComment::class);
            self::assertInstanceOf(ContentCommentRepository::class, $repository);
            $comment = $repository->findOneBy(['contentId' => $article->getId()]);
            self::assertInstanceOf(ContentComment::class, $comment);
            self::assertSame(CommentStatus::Pending, $comment->getStatus());
            self::assertSame('Spoiler Alert', $comment->getFilteredByTerm());

            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($body, $client->getCrawler()->filter('main')->text());
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_comments WHERE content_id = :id', ['id' => $article->getId()]);
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $slug]);
            $connection->executeStatement('DELETE FROM comment_filter_terms WHERE id = :id', ['id' => $filterTerm->getId()]);
        }
    }

    public function testCensoredCommentStoresOnlyTheMaskedTextAndWaitsForModeration(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $termData = CommentWordFilterNormalizer::parseTerm('private phrase');
        $filterTerm = new CommentFilterTerm($termData['term'], $termData['normalized'], CommentFilterAction::Censor);
        $suffix = bin2hex(random_bytes(8));
        $slug = 'word-censor-'.$suffix;
        $article = new NewsArticle(
            'Word censor '.$suffix,
            $slug,
            'Community',
            null,
            'Word censor test body',
            PublishingStatus::Published,
            false,
            true,
        );

        try {
            $entityManager->persist($filterTerm);
            $entityManager->persist($article);
            $entityManager->flush();

            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            $token = $client->getCrawler()->filter('form.comment-form input[name="_token"]')->attr('value');
            self::assertIsString($token);

            $client->request('POST', '/comments/news/'.$slug, [
                '_token' => $token,
                'author_name' => 'Censored Visitor',
                'body' => "This PRIVATE\tPHRASE must be masked; private phrase too.",
                'website' => '',
            ]);
            self::assertResponseRedirects('/news/'.$slug.'#comments');

            $repository = $entityManager->getRepository(ContentComment::class);
            self::assertInstanceOf(ContentCommentRepository::class, $repository);
            $comment = $repository->findOneBy(['contentId' => $article->getId()]);
            self::assertInstanceOf(ContentComment::class, $comment);
            self::assertSame('This *** must be masked; *** too.', $comment->getBody());
            self::assertStringNotContainsString('PRIVATE', $comment->getBody());
            self::assertSame('private phrase', $comment->getFilteredByTerm());
            self::assertSame(CommentStatus::Pending, $comment->getStatus());
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_comments WHERE content_id = :id', ['id' => $article->getId()]);
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $slug]);
            $connection->executeStatement('DELETE FROM comment_filter_terms WHERE id = :id', ['id' => $filterTerm->getId()]);
        }
    }

    public function testRejectedCommentIsNotStoredAndDoesNotRevealTheMatchedTerm(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $termData = CommentWordFilterNormalizer::parseTerm('secret phrase');
        $filterTerm = new CommentFilterTerm($termData['term'], $termData['normalized'], CommentFilterAction::Reject);
        $suffix = bin2hex(random_bytes(8));
        $slug = 'word-reject-'.$suffix;
        $article = new NewsArticle(
            'Word reject '.$suffix,
            $slug,
            'Community',
            null,
            'Word reject test body',
            PublishingStatus::Published,
            false,
            true,
        );

        try {
            $entityManager->persist($filterTerm);
            $entityManager->persist($article);
            $entityManager->flush();

            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            $token = $client->getCrawler()->filter('form.comment-form input[name="_token"]')->attr('value');
            self::assertIsString($token);

            $client->request('POST', '/comments/news/'.$slug, [
                '_token' => $token,
                'author_name' => 'Rejected Visitor',
                'body' => 'This secret phrase must not be stored.',
                'website' => '',
            ]);
            self::assertResponseRedirects('/news/'.$slug.'#comments');
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));

            $repository = $entityManager->getRepository(ContentComment::class);
            self::assertInstanceOf(ContentCommentRepository::class, $repository);
            self::assertNull($repository->findOneBy(['contentId' => $article->getId()]));

            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $publicText = $client->getCrawler()->filter('main')->text();
            self::assertStringContainsString('Dein Kommentar konnte nicht eingereicht werden.', $publicText);
            self::assertStringNotContainsString('secret phrase', $publicText);
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_comments WHERE content_id = :id', ['id' => $article->getId()]);
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $slug]);
            $connection->executeStatement('DELETE FROM comment_filter_terms WHERE id = :id', ['id' => $filterTerm->getId()]);
        }
    }

    public function testRejectActionTakesPrecedenceWhenTheSameTermAlsoHasCensorRule(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $termData = CommentWordFilterNormalizer::parseTerm('same phrase');
        $censorTerm = new CommentFilterTerm($termData['term'], $termData['normalized'], CommentFilterAction::Censor);
        $rejectTerm = new CommentFilterTerm($termData['term'], $termData['normalized'], CommentFilterAction::Reject);
        $suffix = bin2hex(random_bytes(8));
        $slug = 'word-reject-precedence-'.$suffix;
        $article = new NewsArticle(
            'Word reject precedence '.$suffix,
            $slug,
            'Community',
            null,
            'Word reject precedence test body',
            PublishingStatus::Published,
            false,
            true,
        );

        try {
            $entityManager->persist($censorTerm);
            $entityManager->persist($rejectTerm);
            $entityManager->persist($article);
            $entityManager->flush();

            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            $token = $client->getCrawler()->filter('form.comment-form input[name="_token"]')->attr('value');
            self::assertIsString($token);
            $client->request('POST', '/comments/news/'.$slug, [
                '_token' => $token,
                'author_name' => 'Rejected Visitor',
                'body' => 'This same phrase must be rejected.',
                'website' => '',
            ]);

            self::assertResponseRedirects('/news/'.$slug.'#comments');
            $repository = $entityManager->getRepository(ContentComment::class);
            self::assertInstanceOf(ContentCommentRepository::class, $repository);
            self::assertNull($repository->findOneBy(['contentId' => $article->getId()]));
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_comments WHERE content_id = :id', ['id' => $article->getId()]);
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $slug]);
            $connection->executeStatement(
                'DELETE FROM comment_filter_terms WHERE normalized_term = :term',
                ['term' => $termData['normalized']],
            );
        }
    }

    public function testWordFilterAdministrationRequiresAnAdministrator(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/comments/word-filter');

        self::assertResponseRedirects('/admin/login');
    }

    public function testCommentModerationRequiresAnAdministrator(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/comments');

        self::assertResponseRedirects('/admin/login');
    }
}
