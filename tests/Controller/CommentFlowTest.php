<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CommentContentType;
use App\Entity\CommentFilterTerm;
use App\Entity\CommentStatus;
use App\Entity\ContentComment;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Repository\ContentCommentRepository;
use App\Service\CommentWordFilterNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CommentFlowTest extends WebTestCase
{
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
            $token = $client->getCrawler()->filter('form.comment-form input[name="_token"]')->attr('value');
            self::assertIsString($token);

            $client->request('POST', '/comments/news/'.$slug, [
                '_token' => $token,
                'author_name' => 'Test Visitor',
                'body' => 'A useful comment awaiting review.',
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

            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#comments', 'Dein Kommentar wurde zur Prüfung eingereicht.');
            self::assertStringNotContainsString(
                'A useful comment awaiting review.',
                $client->getCrawler()->filter('main')->text(),
            );
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_comments WHERE content_id = :id', ['id' => $article->getId()]);
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $slug]);
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
