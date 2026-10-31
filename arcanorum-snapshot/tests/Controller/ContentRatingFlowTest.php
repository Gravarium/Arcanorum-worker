<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CommentContentType;
use App\Entity\ContentComment;
use App\Entity\MemberAccount;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class ContentRatingFlowTest extends WebTestCase
{
    public function testMemberCanLikeDislikeChangeAndWithdrawVideoRating(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $member = new MemberAccount('rating-'.$suffix.'@example.test', 'rating'.$suffix, 'Private rating member');
        $video = $this->publishedVideo($suffix);

        try {
            $entityManager->persist($member);
            $entityManager->persist($video);
            $entityManager->flush();

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.content-rating-counts');
            self::assertSelectorNotExists('.content-rating-form');
            self::assertStringNotContainsString($member->getEmail(), (string) $client->getResponse()->getContent());

            $client->loginUser($member, 'members');
            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            $token = $this->tokenFor($client->getCrawler()->filter('form[action="/bewertungen/videos/'.$video->getId().'"]'));

            $client->request('POST', '/bewertungen/videos/'.$video->getId(), ['_token' => $token, 'value' => 'like']);
            self::assertResponseRedirects('/videos/'.$video->getId().'#video-rating');
            $client->followRedirect();
            self::assertSelectorTextContains('.content-rating-counts', 'Gefällt mir: 1');
            self::assertSelectorTextContains('.content-rating-counts', 'Gefällt mir nicht: 0');
            self::assertSelectorExists('form[action="/bewertungen/videos/'.$video->getId().'"] button[aria-pressed="true"][value="like"]');

            $client->request('POST', '/bewertungen/videos/'.$video->getId(), ['_token' => $token, 'value' => 'dislike']);
            self::assertResponseRedirects('/videos/'.$video->getId().'#video-rating');
            $connection = $entityManager->getConnection();
            self::assertSame('dislike', $connection->fetchOne(
                'SELECT value FROM content_ratings WHERE member_id = :member_id AND video_id = :video_id',
                ['member_id' => $member->getId(), 'video_id' => $video->getId()],
            ));
            self::assertSame(1, self::integerValue($connection->fetchOne(
                'SELECT COUNT(*) FROM content_ratings WHERE member_id = :member_id AND video_id = :video_id',
                ['member_id' => $member->getId(), 'video_id' => $video->getId()],
            )));

            $client->request('POST', '/bewertungen/videos/'.$video->getId(), ['_token' => $token, 'value' => 'none']);
            self::assertResponseRedirects('/videos/'.$video->getId().'#video-rating');
            self::assertSame(0, self::integerValue($connection->fetchOne(
                'SELECT COUNT(*) FROM content_ratings WHERE member_id = :member_id AND video_id = :video_id',
                ['member_id' => $member->getId(), 'video_id' => $video->getId()],
            )));
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $connection->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $member->getId()]);
        }
    }

    public function testMemberCanRateVisibleNewsAndVideoCommentsWithoutExposingVoterIdentity(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $member = new MemberAccount('comment-voter-'.$suffix.'@example.test', 'voter'.$suffix, 'Private voter');
        $author = new MemberAccount('comment-author-'.$suffix.'@example.test', 'author'.$suffix, 'Private author');
        $video = $this->publishedVideo($suffix);
        $article = new NewsArticle(
            'Rating article '.$suffix,
            'rating-article-'.$suffix,
            'Community',
            null,
            'Published article used to verify ratings for approved news comments.',
            PublishingStatus::Published,
            false,
            true,
        );
        $videoComment = $this->approvedComment(CommentContentType::Video, $video->getId(), $video->getTitle(), $author);
        $newsComment = $this->approvedComment(CommentContentType::News, $article->getId(), $article->getTitle(), $author);
        $pendingComment = new ContentComment(CommentContentType::Video, $video->getId(), $video->getTitle(), 'Pending', 'This comment is not public yet.');

        try {
            foreach ([$member, $author, $video, $article, $videoComment, $newsComment, $pendingComment] as $entity) {
                $entityManager->persist($entity);
            }
            $entityManager->flush();
            $client->loginUser($member, 'members');

            $client->request('GET', '/news/'.$article->getSlug());
            self::assertResponseIsSuccessful();
            $newsContent = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($member->getEmail(), $newsContent);
            self::assertStringNotContainsString($author->getEmail(), $newsContent);
            self::assertStringNotContainsString($pendingComment->getBody(), $newsContent);
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            $newsForm = $client->getCrawler()->filter('form[action="/bewertungen/kommentare/'.$newsComment->getId().'"]');
            $client->request('POST', '/bewertungen/kommentare/'.$newsComment->getId(), [
                '_token' => $this->tokenFor($newsForm),
                'value' => 'like',
            ]);
            self::assertResponseRedirects('/news/'.$article->getSlug().'#comments');

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            $videoContent = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($member->getEmail(), $videoContent);
            self::assertStringNotContainsString($author->getEmail(), $videoContent);
            $videoForm = $client->getCrawler()->filter('form[action="/bewertungen/kommentare/'.$videoComment->getId().'"]');
            $videoToken = $this->tokenFor($videoForm);
            $client->request('POST', '/bewertungen/kommentare/'.$videoComment->getId(), [
                '_token' => $videoToken,
                'value' => 'dislike',
            ]);
            self::assertResponseRedirects('/videos/'.$video->getId().'#comments');

            $connection = $entityManager->getConnection();
            self::assertSame('like', $connection->fetchOne(
                'SELECT value FROM content_ratings WHERE member_id = :member_id AND comment_id = :comment_id',
                ['member_id' => $member->getId(), 'comment_id' => $newsComment->getId()],
            ));
            self::assertSame('dislike', $connection->fetchOne(
                'SELECT value FROM content_ratings WHERE member_id = :member_id AND comment_id = :comment_id',
                ['member_id' => $member->getId(), 'comment_id' => $videoComment->getId()],
            ));
            $client->request('POST', '/bewertungen/kommentare/'.$videoComment->getId(), [
                '_token' => $videoToken,
                'value' => 'like',
            ]);
            self::assertResponseRedirects('/videos/'.$video->getId().'#comments');
            self::assertSame('like', $connection->fetchOne(
                'SELECT value FROM content_ratings WHERE member_id = :member_id AND comment_id = :comment_id',
                ['member_id' => $member->getId(), 'comment_id' => $videoComment->getId()],
            ));
            self::assertSame(1, self::integerValue($connection->fetchOne(
                'SELECT COUNT(*) FROM content_ratings WHERE member_id = :member_id AND comment_id = :comment_id',
                ['member_id' => $member->getId(), 'comment_id' => $videoComment->getId()],
            )));
            $client->request('POST', '/bewertungen/kommentare/'.$videoComment->getId(), [
                '_token' => $videoToken,
                'value' => 'none',
            ]);
            self::assertResponseRedirects('/videos/'.$video->getId().'#comments');
            self::assertSame(0, self::integerValue($connection->fetchOne(
                'SELECT COUNT(*) FROM content_ratings WHERE member_id = :member_id AND comment_id = :comment_id',
                ['member_id' => $member->getId(), 'comment_id' => $videoComment->getId()],
            )));

            $client->request('POST', '/bewertungen/kommentare/'.$pendingComment->getId(), [
                '_token' => 'irrelevant',
                'value' => 'like',
            ]);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_comments WHERE id IN (:first, :second, :third)', [
                'first' => $videoComment->getId(),
                'second' => $newsComment->getId(),
                'third' => $pendingComment->getId(),
            ]);
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $connection->executeStatement('DELETE FROM news_articles WHERE id = :id', ['id' => $article->getId()]);
            $connection->executeStatement('DELETE FROM member_accounts WHERE id IN (:first, :second)', [
                'first' => $member->getId(),
                'second' => $author->getId(),
            ]);
        }
    }

    public function testRatingRequiresAuthenticationAndValidCsrfAndIsRateLimited(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $member = new MemberAccount('quota-'.$suffix.'@example.test', 'quota'.$suffix, 'Quota member');
        $video = $this->publishedVideo($suffix);

        try {
            $entityManager->persist($member);
            $entityManager->persist($video);
            $entityManager->flush();
            $path = '/bewertungen/videos/'.$video->getId();

            $client->request('POST', $path, ['value' => 'like']);
            self::assertResponseRedirects('/konto/anmelden');

            $client->loginUser($member, 'members');
            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            $form = $client->getCrawler()->filter('form[action="'.$path.'"]');
            $token = $this->tokenFor($form);

            $client->request('POST', $path, ['_token' => 'invalid', 'value' => 'like']);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $path, ['_token' => $token, 'value' => 'unknown']);
            self::assertResponseStatusCodeSame(400);

            for ($attempt = 1; $attempt <= 31; ++$attempt) {
                $client->request('POST', $path, ['_token' => $token, 'value' => 'like']);
                if (31 === $attempt) {
                    self::assertResponseStatusCodeSame(429);
                    self::assertNotEmpty($client->getResponse()->headers->get('Retry-After'));
                    continue;
                }
                self::assertResponseRedirects('/videos/'.$video->getId().'#video-rating');
            }

            self::assertSame(1, self::integerValue($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_ratings WHERE member_id = :member_id AND video_id = :video_id',
                ['member_id' => $member->getId(), 'video_id' => $video->getId()],
            )));
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $connection->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $member->getId()]);
        }
    }

    public function testRatingFailsClosedWhenAbuseQuotaStoreIsUnavailable(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $member = new MemberAccount('limiter-error-'.$suffix.'@example.test', 'limiter'.$suffix, 'Quota store member');
        $video = $this->publishedVideo($suffix);

        try {
            $entityManager->persist($member);
            $entityManager->persist($video);
            $entityManager->flush();
            $client->loginUser($member, 'members');
            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            $token = $this->tokenFor($client->getCrawler()->filter('form[action="/bewertungen/videos/'.$video->getId().'"]'));

            $limiter = $this->createMock(RateLimiterFactoryInterface::class);
            $limiter->expects(self::once())
                ->method('create')
                ->willThrowException(new \RuntimeException('Internal test-only rate store failure.'));
            $client->getContainer()->set('limiter.content_rating', $limiter);

            $client->request('POST', '/bewertungen/videos/'.$video->getId(), ['_token' => $token, 'value' => 'like']);
            self::assertResponseStatusCodeSame(503);
            self::assertSame(0, self::integerValue($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_ratings WHERE member_id = :member_id AND video_id = :video_id',
                ['member_id' => $member->getId(), 'video_id' => $video->getId()],
            )));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $connection->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $member->getId()]);
        }
    }

    private function publishedVideo(string $suffix): Video
    {
        return new Video(
            'Rating video '.$suffix,
            'Published video for rating flow.',
            'Community',
            VideoSourceType::Url,
            'https://video.example.test/rating-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
    }

    private function approvedComment(
        CommentContentType $contentType,
        string $contentId,
        string $title,
        MemberAccount $author,
    ): ContentComment {
        $comment = new ContentComment($contentType, $contentId, $title, 'Public author', 'An approved comment that can be rated.');
        $comment->attributeToMember($author);
        $comment->approve();

        return $comment;
    }

    private function tokenFor(\Symfony\Component\DomCrawler\Crawler $form): string
    {
        $token = $form->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        return $token;
    }

    private static function integerValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        self::assertIsString($value);
        self::assertMatchesRegularExpression('/\A\d+\z/', $value);

        return (int) $value;
    }
}
