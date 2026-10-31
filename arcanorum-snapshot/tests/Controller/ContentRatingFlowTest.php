<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CommentContentType;
use App\Entity\CommentProvider;
use App\Entity\CommentSortOrder;
use App\Entity\ContentComment;
use App\Entity\ContentRatingValue;
use App\Entity\FeatureModule;
use App\Entity\MemberAccount;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Repository\ContentCommentRepository;
use App\Repository\ContentRatingRepository;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class ContentRatingFlowTest extends WebTestCase
{
    public function testCommentSortingAndMuteThresholdApplyToNewsAndVideos(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settings = $client->getContainer()->get(SiteSettingsManager::class);
        $siteSettings = $client->getContainer()->get(SiteSettingsProvider::class);
        $ratings = $client->getContainer()->get(ContentRatingRepository::class);
        $commentRepository = $client->getContainer()->get(ContentCommentRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settings);
        self::assertInstanceOf(SiteSettingsProvider::class, $siteSettings);
        self::assertInstanceOf(ContentRatingRepository::class, $ratings);
        self::assertInstanceOf(ContentCommentRepository::class, $commentRepository);

        $originalFeatureModules = [];
        foreach (FeatureModule::cases() as $featureModule) {
            $originalFeatureModules[$featureModule->inputName()] = $siteSettings->current()->isFeatureModuleEnabled($featureModule) ? '1' : '0';
        }
        $commentEnabledFeatureModules = [...$originalFeatureModules, FeatureModule::Comments->inputName() => '1'];

        $suffix = bin2hex(random_bytes(7));
        $author = new MemberAccount('display-author-'.$suffix.'@example.test', 'author'.$suffix, 'Display author');
        $raters = [
            new MemberAccount('display-rater1-'.$suffix.'@example.test', 'rater1'.$suffix, 'Rating member one'),
            new MemberAccount('display-rater2-'.$suffix.'@example.test', 'rater2'.$suffix, 'Rating member two'),
            new MemberAccount('display-rater3-'.$suffix.'@example.test', 'rater3'.$suffix, 'Rating member three'),
            new MemberAccount('display-rater4-'.$suffix.'@example.test', 'rater4'.$suffix, 'Rating member four'),
        ];
        $article = new NewsArticle(
            'Comment display '.$suffix,
            'comment-display-'.$suffix,
            'Community',
            null,
            'Published article for comment order and muting.',
            PublishingStatus::Published,
            false,
            true,
        );
        $video = $this->publishedVideo('comment-display-'.$suffix);
        $newsTop = $this->approvedComment(CommentContentType::News, $article->getId(), $article->getTitle(), $author);
        $newsLow = $this->approvedComment(CommentContentType::News, $article->getId(), $article->getTitle(), $author);
        $newsBalanced = $this->approvedComment(CommentContentType::News, $article->getId(), $article->getTitle(), $author);
        $videoTop = $this->approvedComment(CommentContentType::Video, $video->getId(), $video->getTitle(), $author);
        $videoLow = $this->approvedComment(CommentContentType::Video, $video->getId(), $video->getTitle(), $author);
        $videoBalanced = $this->approvedComment(CommentContentType::Video, $video->getId(), $video->getTitle(), $author);
        $comments = [$newsTop, $newsLow, $newsBalanced, $videoTop, $videoLow, $videoBalanced];
        $members = [$author, ...$raters];
        $connection = $entityManager->getConnection();

        try {
            self::assertSame([], $settings->updateFeatureModules($commentEnabledFeatureModules));
            self::assertTrue($siteSettings->current()->isFeatureModuleEnabled(FeatureModule::Comments));
            foreach ([...$members, $article, $video, ...$comments] as $entity) {
                $entityManager->persist($entity);
            }
            $entityManager->flush();
            $connection->executeStatement(
                'UPDATE content_comments SET created_at = :created_at WHERE id IN (:ids)',
                [
                    'created_at' => new \DateTimeImmutable('2026-10-01T12:00:00+00:00'),
                    'ids' => [$newsTop->getId(), $videoTop->getId()],
                ],
                [
                    'created_at' => \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE,
                    'ids' => \Doctrine\DBAL\ArrayParameterType::STRING,
                ],
            );
            $connection->executeStatement(
                'UPDATE content_comments SET created_at = :created_at WHERE id IN (:ids)',
                [
                    'created_at' => new \DateTimeImmutable('2026-10-03T12:00:00+00:00'),
                    'ids' => [$newsBalanced->getId(), $videoBalanced->getId()],
                ],
                [
                    'created_at' => \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE,
                    'ids' => \Doctrine\DBAL\ArrayParameterType::STRING,
                ],
            );
            $connection->executeStatement(
                'UPDATE content_comments SET created_at = :created_at WHERE id IN (:ids)',
                [
                    'created_at' => new \DateTimeImmutable('2026-10-02T12:00:00+00:00'),
                    'ids' => [$newsLow->getId(), $videoLow->getId()],
                ],
                [
                    'created_at' => \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE,
                    'ids' => \Doctrine\DBAL\ArrayParameterType::STRING,
                ],
            );

            foreach ($raters as $rater) {
                $ratings->setForComment($rater, $newsTop, ContentRatingValue::Like);
                $ratings->setForComment($rater, $videoTop, ContentRatingValue::Like);
            }
            $ratings->setForComment($raters[0], $newsLow, ContentRatingValue::Dislike);
            $ratings->setForComment($raters[1], $newsLow, ContentRatingValue::Dislike);
            $ratings->setForComment($raters[2], $newsLow, ContentRatingValue::Like);
            $ratings->setForComment($raters[0], $newsBalanced, ContentRatingValue::Like);
            $ratings->setForComment($raters[1], $newsBalanced, ContentRatingValue::Like);
            $ratings->setForComment($raters[2], $newsBalanced, ContentRatingValue::Dislike);
            $ratings->setForComment($raters[3], $newsBalanced, ContentRatingValue::Dislike);
            $ratings->setForComment($raters[0], $videoLow, ContentRatingValue::Dislike);
            $ratings->setForComment($raters[1], $videoLow, ContentRatingValue::Dislike);
            $ratings->setForComment($raters[2], $videoLow, ContentRatingValue::Like);
            $ratings->setForComment($raters[0], $videoBalanced, ContentRatingValue::Like);
            $ratings->setForComment($raters[1], $videoBalanced, ContentRatingValue::Like);
            $ratings->setForComment($raters[2], $videoBalanced, ContentRatingValue::Dislike);
            $ratings->setForComment($raters[3], $videoBalanced, ContentRatingValue::Dislike);

            self::assertSame([], $settings->updateContentOptions([
                'comment_default_sort' => CommentSortOrder::Added->value,
                'comment_rating_hide_threshold' => '2',
                'comments_per_page' => '2',
            ]));
            self::assertSame(CommentProvider::Native, $siteSettings->current()->getCommentProvider());
            self::assertSame(2, $siteSettings->current()->getCommentsPerPage());
            self::assertSame(3, $commentRepository->countApprovedForContent(CommentContentType::News, $article->getId()));
            self::assertSame(
                [$newsBalanced->getId(), $newsLow->getId()],
                array_map(
                    static fn (ContentComment $comment): string => $comment->getId(),
                    $commentRepository->findApprovedForContent(
                        CommentContentType::News,
                        $article->getId(),
                        CommentSortOrder::Added,
                        2,
                        0,
                    ),
                ),
            );
            $client->request('GET', '/news/'.$article->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.comment-section');
            self::assertSame(
                [$newsBalanced->getId(), $newsLow->getId()],
                $this->visibleCommentIds($client->getCrawler()),
            );
            self::assertSelectorTextContains('.comment-pagination', 'Seite 1 von 2');
            self::assertSelectorExists('.comment-pagination a[rel="next"][href="/news/'.$article->getSlug().'?comment_page=2#comments"]');
            self::assertSelectorNotExists('.comment-pagination a[rel="prev"]');
            self::assertSelectorExists('details.comment-card-muted:not([open])');
            self::assertSelectorExists('article#comment-'.$newsBalanced->getId());
            self::assertSelectorExists('details#comment-'.$newsLow->getId().' .comment-card-content .comment-body');
            self::assertSelectorNotExists('details#comment-'.$newsBalanced->getId());
            self::assertSelectorExists('form.comment-form[action="/comments/news/'.$article->getSlug().'"]');
            foreach ($raters as $rater) {
                self::assertStringNotContainsString($rater->getEmail(), (string) $client->getResponse()->getContent());
            }

            $client->request('GET', '/news/'.$article->getSlug().'?comment_page[]=2');
            self::assertResponseIsSuccessful();
            self::assertSame(
                [$newsBalanced->getId(), $newsLow->getId()],
                $this->visibleCommentIds($client->getCrawler()),
            );

            $client->request('GET', '/news/'.$article->getSlug().'?comment_page=99999');
            self::assertResponseIsSuccessful();
            self::assertSame([$newsTop->getId()], $this->visibleCommentIds($client->getCrawler()));
            self::assertSelectorTextContains('.comment-pagination', 'Seite 2 von 2');
            self::assertSelectorExists('.comment-pagination a[rel="prev"][href="/news/'.$article->getSlug().'#comments"]');
            self::assertSelectorNotExists('.comment-pagination a[rel="next"]');
            self::assertSelectorExists('form.comment-form[action="/comments/news/'.$article->getSlug().'?comment_page=2"]');

            $client->loginUser($raters[3], 'members');
            $client->request('GET', '/news/'.$article->getSlug().'?comment_page=2');
            self::assertResponseIsSuccessful();
            $reportForm = $client->getCrawler()->filter('form[action="/comments/'.$newsTop->getId().'/report?comment_page=2"]');
            $client->request('POST', '/comments/'.$newsTop->getId().'/report?comment_page=2', [
                '_token' => $this->tokenFor($reportForm),
            ]);
            self::assertResponseRedirects('/news/'.$article->getSlug().'?comment_page=2#comments');

            $client->request('GET', '/news/'.$article->getSlug().'?comment_page=2');
            self::assertResponseIsSuccessful();
            $ratingForm = $client->getCrawler()->filter('form[action="/bewertungen/kommentare/'.$newsTop->getId().'?comment_page=2"]');
            $client->request('POST', '/bewertungen/kommentare/'.$newsTop->getId().'?comment_page=2', [
                '_token' => $this->tokenFor($ratingForm),
                'value' => 'like',
            ]);
            self::assertResponseRedirects('/news/'.$article->getSlug().'?comment_page=2#comments');

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            self::assertSame(
                [$videoBalanced->getId(), $videoLow->getId()],
                $this->visibleCommentIds($client->getCrawler()),
            );
            self::assertSelectorTextContains('.comment-pagination', 'Seite 1 von 2');
            self::assertSelectorExists('details.comment-card-muted:not([open])');
            self::assertSelectorExists('article#comment-'.$videoBalanced->getId());
            self::assertSelectorNotExists('details#comment-'.$videoBalanced->getId());
            $client->request('GET', '/videos/'.$video->getId().'?comment_page=2');
            self::assertResponseIsSuccessful();
            self::assertSame([$videoTop->getId()], $this->visibleCommentIds($client->getCrawler()));
            self::assertSelectorExists('.comment-pagination a[rel="prev"][href="/videos/'.$video->getId().'#comments"]');

            self::assertSame([], $settings->updateContentOptions([
                'comment_default_sort' => CommentSortOrder::Score->value,
                'comment_rating_hide_threshold' => '2',
                'comments_per_page' => '2',
            ]));
            $client->request('GET', '/news/'.$article->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSame(
                [$newsTop->getId(), $newsBalanced->getId()],
                $this->visibleCommentIds($client->getCrawler()),
            );
            self::assertSelectorNotExists('details.comment-card-muted');
            $client->request('GET', '/news/'.$article->getSlug().'?comment_page=2');
            self::assertResponseIsSuccessful();
            self::assertSame([$newsLow->getId()], $this->visibleCommentIds($client->getCrawler()));
            self::assertSelectorTextContains('details.comment-card-muted summary', 'negativer Bewertungen eingeklappt');

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            self::assertSame(
                [$videoTop->getId(), $videoBalanced->getId()],
                $this->visibleCommentIds($client->getCrawler()),
            );
            self::assertSelectorNotExists('details.comment-card-muted');
            $client->request('GET', '/videos/'.$video->getId().'?comment_page=2');
            self::assertResponseIsSuccessful();
            self::assertSame([$videoLow->getId()], $this->visibleCommentIds($client->getCrawler()));
            self::assertSelectorExists('details.comment-card-muted:not([open])');

            self::assertSame([], $settings->updateContentOptions([
                'comment_default_sort' => CommentSortOrder::Added->value,
                'comment_rating_hide_threshold' => '0',
                'comments_per_page' => '2',
            ]));
            $client->request('GET', '/news/'.$article->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('details.comment-card-muted');

            $client->request('GET', '/videos/'.$video->getId().'?comment_page=2');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('details.comment-card-muted');
        } finally {
            try {
                $settings->updateContentOptions([
                    'comment_default_sort' => CommentSortOrder::Added->value,
                    'comment_rating_hide_threshold' => '0',
                    'comments_per_page' => '10',
                ]);
                $connection->executeStatement('DELETE FROM content_comments WHERE id IN (:ids)', [
                    'ids' => array_map(static fn (ContentComment $comment): string => $comment->getId(), $comments),
                ], ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]);
                $connection->executeStatement('DELETE FROM news_articles WHERE id = :id', ['id' => $article->getId()]);
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
                $connection->executeStatement('DELETE FROM member_accounts WHERE id IN (:ids)', [
                    'ids' => array_map(static fn (MemberAccount $member): string => $member->getId(), $members),
                ], ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]);
            } finally {
                self::assertSame([], $settings->updateFeatureModules($originalFeatureModules));
            }
        }
    }

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
        $settings = $client->getContainer()->get(SiteSettingsManager::class);
        $siteSettings = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settings);
        self::assertInstanceOf(SiteSettingsProvider::class, $siteSettings);

        $originalFeatureModules = [];
        foreach (FeatureModule::cases() as $featureModule) {
            $originalFeatureModules[$featureModule->inputName()] = $siteSettings->current()->isFeatureModuleEnabled($featureModule) ? '1' : '0';
        }
        $commentEnabledFeatureModules = [...$originalFeatureModules, FeatureModule::Comments->inputName() => '1'];

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
            self::assertSame([], $settings->updateFeatureModules($commentEnabledFeatureModules));
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
            try {
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
            } finally {
                self::assertSame([], $settings->updateFeatureModules($originalFeatureModules));
            }
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

    /** @return list<string> */
    private function visibleCommentIds(Crawler $crawler): array
    {
        $ids = [];
        foreach ($crawler->filter('.comment-list > .comment-card') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $id = $node->getAttribute('id');
            if (!str_starts_with($id, 'comment-')) {
                continue;
            }

            $ids[] = substr($id, strlen('comment-'));
        }

        return $ids;
    }

    private function tokenFor(Crawler $form): string
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
