<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Command\CreateAdminUserCommand;
use App\Command\ProvisionAdminMfaCommand;
use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\CommentContentType;
use App\Entity\CommentFilterAction;
use App\Entity\CommentFilterTerm;
use App\Entity\CommentStatus;
use App\Entity\ContactMessage;
use App\Entity\ContactMessageStatus;
use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\ContentComment;
use App\Entity\ContentSeries;
use App\Entity\MemberAccount;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\SeriesEpisode;
use App\Entity\SeriesGenre;
use App\Entity\StaticPage;
use App\Entity\Video;
use App\Entity\VideoReport;
use App\Entity\VideoReportReason;
use App\Entity\VideoReportStatus;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubmission;
use App\Entity\VideoSubmissionStatus;
use App\Module\Member\Security\MemberEmailChallengePurpose;
use App\Module\Member\Security\MemberSecurityEmailSenderInterface;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Security\AdminMfaLoginVerifier;
use App\Platform\Security\AdminMfaProvisioningConflict;
use App\Platform\Security\AdminMfaProvisioningResult;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\ContentSecurityPolicyReportOnlySubscriber;
use App\Platform\Security\InteractiveConsoleTerminalInterface;
use App\Platform\Security\RuntimeAttestationActionGuard;
use App\Platform\Security\RuntimeAttestationIncidentDeliveryUnavailable;
use App\Platform\Security\RuntimeAttestationIncidentReporterInterface;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\RuntimeAttestationStatus;
use App\Platform\Security\TotpVerifier;
use App\Platform\Security\UnconfiguredRuntimeAttestationReportProvider;
use App\Repository\AdminUserRepository;
use App\Repository\CommentFilterTermRepository;
use App\Repository\ContactMessageRepository;
use App\Repository\ContentCommentRepository;
use App\Repository\ContentSeriesRepository;
use App\Repository\MemberAccountRepository;
use App\Repository\NewsArticleRepository;
use App\Repository\SeriesEpisodeRepository;
use App\Repository\StaticPageRepository;
use App\Repository\VideoReportRepository;
use App\Repository\VideoRepository;
use App\Repository\VideoSubmissionRepository;
use App\Service\CommentWordFilterNormalizer;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\PhpRuntimeInspectorInterface;
use App\Service\VideoProviderImportService;
use App\Service\VideoSocialPreviewGenerator;
use App\Service\VideoThumbnailImportService;
use App\Service\VideoThumbnailUploadService;
use App\Tests\Support\RecordingMemberSecurityEmailSender;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;

final class AdminDashboardTest extends WebTestCase
{
    private const string CSP_REPORT_ONLY_POLICY = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; media-src 'self'";

    public static function setUpBeforeClass(): void
    {
        $testKey = base64_encode(str_repeat('T', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$testKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;

        parent::setUpBeforeClass();
    }

    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');

        $client->request('GET', '/admin/activity');
        self::assertResponseRedirects('/admin/login');

        $client->request('GET', '/admin/system');
        self::assertResponseRedirects('/admin/login');
    }

    public function testVideoModeratorIsLimitedToAssignedVideoQueues(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $email = 'video-moderator-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $email, 'VideoModerator!2026', [AdminRole::VideoModerator->value]);
            $this->submitLogin($client, $email, 'VideoModerator!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin');
            self::assertResponseRedirects('/admin/video-submissions');

            $crawler = $client->request('GET', '/admin/video-submissions');
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Videoeinreichungen', $html);
            self::assertStringContainsString('/admin/video-reports', $html);
            self::assertStringNotContainsString('Hosting und System', $html);
            self::assertStringNotContainsString('Videos verwalten', $html);

            $client->request('GET', '/admin/comments');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $client->request('GET', '/admin/system');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $logoutToken = $crawler->filter('input[name="_csrf_token"]')->attr('value');
            self::assertIsString($logoutToken);
            $client->request('POST', '/admin/logout', ['_csrf_token' => $logoutToken]);
            self::assertResponseRedirects('/admin/login');
            $client->request('GET', '/admin/video-submissions');
            self::assertResponseRedirects('/admin/login');
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testCommentModeratorCanModerateCommentsButNotVideoOrWordFilter(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $email = 'comment-moderator-'.bin2hex(random_bytes(6)).'@example.test';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $commentId = null;

        try {
            $this->persistUser($client, $email, 'CommentModerator!2026', [AdminRole::CommentModerator->value]);
            $comment = new ContentComment(
                CommentContentType::Video,
                bin2hex(random_bytes(16)),
                'Moderator test video',
                'Moderator test author',
                'A harmless test comment.',
            );
            $commentId = $comment->getId();
            $entityManager->persist($comment);
            $entityManager->flush();

            $this->submitLogin($client, $email, 'CommentModerator!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin');
            self::assertResponseRedirects('/admin/comments');

            $crawler = $client->request('GET', '/admin/comments');
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Kommentare prüfen', $html);
            self::assertStringNotContainsString('Wortfilter', $html);
            self::assertStringNotContainsString('Hosting und System', $html);

            $form = $crawler->filter(sprintf('form[action="/admin/comments/%s/moderate"]', $commentId));
            self::assertCount(1, $form);
            $token = $form->filter('input[name="_token"]')->attr('value');
            self::assertIsString($token);

            $moderatePath = sprintf('/admin/comments/%s/moderate', $commentId);
            $client->request('POST', $moderatePath, ['action' => 'approve']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $persistedComment = $entityManager->getRepository(ContentComment::class)->find($commentId);
            self::assertInstanceOf(ContentComment::class, $persistedComment);
            self::assertSame(CommentStatus::Pending, $persistedComment->getStatus());

            $client->request('POST', $moderatePath, ['_token' => $token, 'action' => 'approve']);
            self::assertResponseRedirects('/admin/comments');
            $client->followRedirect();
            self::assertSelectorTextContains('.flash-message.is-success', 'Kommentar wurde freigegeben.');
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $persistedComment = $entityManager->getRepository(ContentComment::class)->find($commentId);
            self::assertInstanceOf(ContentComment::class, $persistedComment);
            self::assertSame(CommentStatus::Approved, $persistedComment->getStatus());

            $actor = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(AdminUser::class, $actor);
            self::assertSame(1, filter_var($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM admin_activity_entries WHERE actor_id = :actor_id AND route_name = :route_name',
                ['actor_id' => $actor->getId(), 'route_name' => 'admin_comment_moderate'],
            ), FILTER_VALIDATE_INT));

            $client->request('GET', '/admin/comments/word-filter');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $client->request('GET', '/admin/video-submissions');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $client->request('GET', '/admin');
            self::assertResponseRedirects('/admin/comments');
        } finally {
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            if (null !== $commentId) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM content_comments WHERE id = :id',
                    ['id' => $commentId],
                );
            }
            $actor = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
            if ($actor instanceof AdminUser) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM admin_activity_entries WHERE actor_id = :actor_id',
                    ['actor_id' => $actor->getId()],
                );
            }
            $this->removeUser($client, $email);
        }
    }

    public function testGeneralModeratorCanUseBothModerationQueuesButNotAdminDashboard(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $email = 'general-moderator-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $email, 'GeneralModerator!2026', [AdminRole::Moderator->value]);
            $this->submitLogin($client, $email, 'GeneralModerator!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin');
            self::assertResponseRedirects('/admin/video-submissions');

            $client->request('GET', '/admin/video-submissions');
            self::assertResponseIsSuccessful();
            $client->request('GET', '/admin/comments');
            self::assertResponseIsSuccessful();
            $client->request('GET', '/admin');
            self::assertResponseRedirects('/admin/video-submissions');
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testSystemOverviewShowsOnlyCuratedRuntimeValuesToAdmin(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $runtime = self::createStub(PhpRuntimeInspectorInterface::class);
        $runtime->method('version')->willReturn('8.5.4');
        $runtime->method('sapi')->willReturn('fpm-fcgi');
        $runtime->method('iniValue')->willReturnCallback(
            static fn (string $name): string => 'allow_url_include' === $name ? '1' : '0',
        );
        $runtime->method('extensionLoaded')->willReturn(false);
        $client->getContainer()->set(PhpRuntimeInspectorInterface::class, $runtime);
        $email = 'system-overview-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $email, 'SystemOverview!2026', ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, 'SystemOverview!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#dashboard-notices-heading', 'Systemhinweise');
            self::assertSelectorTextContains('section[data-dashboard-latest-videos]', 'Neueste Videos');
            self::assertSelectorTextContains('section[data-dashboard-latest-videos]', 'Noch keine Videos. Füge die erste Videoquelle hinzu.');
            self::assertSelectorTextContains('section[data-dashboard-latest-articles]', 'Noch keine Artikel vorhanden.');
            self::assertSelectorTextContains('section[data-dashboard-latest-members]', 'Noch keine Mitglieder vorhanden.');
            self::assertSelectorTextContains('section[data-dashboard-latest-comments]', 'Noch keine Kommentare vorhanden.');
            self::assertSelectorNotExists('[data-dashboard-video-review]');
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));

            $client->request('GET', '/admin/system');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Hosting und System');
            self::assertSelectorTextContains('#system-php-heading', 'PHP-Konfiguration');
            self::assertSelectorTextContains('#system-notices-heading', 'Systemhinweise');
            self::assertSelectorTextContains('#system-events .status-pill.is-critical', 'Kritisch');
            self::assertSelectorTextContains('#system-events', 'PHP-URL-Includes sind aktiviert');
            self::assertSelectorExists('#system-events time[datetime]');
            self::assertSelectorTextContains('body', 'Maximale Eingabefelder');
            self::assertSelectorTextContains('body', 'PHP-Einstellungen');
            self::assertSelectorTextContains('body', 'PHP-Erweiterungen');
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSame(self::CSP_REPORT_ONLY_POLICY, $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            $html = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('DATABASE_URL', $html);
            self::assertStringNotContainsString('APP_SECRET', $html);
            self::assertStringNotContainsString('phpinfo()', $html);
            self::assertStringNotContainsString('session.save_path', $html);
            self::assertStringNotContainsString('sendmail_path', $html);
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testDashboardShowsLatestArticlesMembersAndCommentsWithoutPrivateOrUnsafeData(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $runtime = self::createStub(PhpRuntimeInspectorInterface::class);
        $runtime->method('version')->willReturn('8.5.4');
        $runtime->method('sapi')->willReturn('fpm-fcgi');
        $runtime->method('iniValue')->willReturn('0');
        $runtime->method('extensionLoaded')->willReturn(true);
        $client->getContainer()->set(PhpRuntimeInspectorInterface::class, $runtime);

        $email = 'dashboard-widgets-'.bin2hex(random_bytes(5)).'@example.test';
        $password = 'DashboardWidgets!2026';
        $dashboardFixtureReferences = [];

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $connection = $entityManager->getConnection();
            $suffix = bin2hex(random_bytes(4));
            $members = [];
            $articles = [];
            $comments = [];
            $memberEmails = [];

            for ($index = 1; $index <= 6; ++$index) {
                $memberEmail = sprintf('dashmember%s%d@example.test', $suffix, $index);
                $memberEmails[] = $memberEmail;
                $members[] = new MemberAccount(
                    $memberEmail,
                    'dashmember'.$suffix.$index,
                    'Dashboard member '.$index.' '.$suffix,
                );
                $articleTitle = 1 === $index
                    ? '<script>alert("dashboard-article-'.$suffix.'")</script>'
                    : 'Dashboard article '.$index.' '.$suffix;
                $articles[] = new NewsArticle(
                    $articleTitle,
                    'dashboard-'.$suffix.'-'.$index,
                    null,
                    null,
                    'dashboard-private-article-body-'.$suffix.'-'.$index,
                    PublishingStatus::Draft,
                    false,
                    true,
                );
                $commentBody = 1 === $index
                    ? '<script>alert("dashboard-comment-'.$suffix.'")</script>'.str_repeat('x', 200).'comment-tail-secret-'.$suffix
                    : 'Dashboard comment '.$index.' '.$suffix;
                $comment = new ContentComment(
                    CommentContentType::Video,
                    bin2hex(random_bytes(16)),
                    'Dashboard video '.$index.' '.$suffix,
                    'Dashboard commenter '.$index.' '.$suffix,
                    $commentBody,
                );
                if (1 === $index) {
                    $comment->approve();
                }
                $comments[] = $comment;
            }

            foreach ($members as $member) {
                $dashboardFixtureReferences[] = [MemberAccount::class, $member->getId()];
                $entityManager->persist($member);
            }
            foreach ($articles as $article) {
                $dashboardFixtureReferences[] = [NewsArticle::class, $article->getId()];
                $entityManager->persist($article);
            }
            foreach ($comments as $comment) {
                $dashboardFixtureReferences[] = [ContentComment::class, $comment->getId()];
                $entityManager->persist($comment);
            }
            $entityManager->flush();

            foreach ([
                'member_accounts' => $members,
                'news_articles' => $articles,
                'content_comments' => $comments,
            ] as $table => $entities) {
                foreach ($entities as $index => $entity) {
                    if (0 === $index) {
                        $createdAt = '2099-12-31 23:59:59';
                    } elseif ('content_comments' === $table && 5 === $index) {
                        $createdAt = '1999-12-31 23:59:59';
                    } elseif ('content_comments' === $table) {
                        $createdAt = '2000-01-01 00:00:00';
                    } else {
                        $createdAt = sprintf('2000-01-%02d 00:00:00', $index + 1);
                    }
                    $connection->executeStatement(
                        sprintf('UPDATE %s SET created_at = CAST(:created_at AS TIMESTAMP WITHOUT TIME ZONE) WHERE id = :id', $table),
                        ['created_at' => $createdAt, 'id' => $entity->getId()],
                    );
                }
            }
            $entityManager->clear();

            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            $cacheControl = $client->getResponse()->headers->get('Cache-Control');
            self::assertIsString($cacheControl);
            self::assertStringContainsString('private', $cacheControl);
            self::assertStringContainsString('no-store', $cacheControl);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');

            $latestArticles = $client->getCrawler()->filter('[data-dashboard-latest-articles] li');
            self::assertCount(5, $latestArticles);
            self::assertSame('<script>alert("dashboard-article-'.$suffix.'")</script>', trim($latestArticles->first()->filter('a')->text()));
            self::assertSame('/admin/news/'.$articles[0]->getId().'/edit', $latestArticles->first()->filter('a')->attr('href'));
            self::assertSelectorTextContains('section[data-dashboard-latest-articles]', 'Entwurf');
            self::assertSelectorTextContains('section[data-dashboard-latest-articles] time', 'UTC');
            self::assertStringEndsWith('+00:00', (string) $latestArticles->first()->filter('time')->attr('datetime'));

            $latestMembers = $client->getCrawler()->filter('[data-dashboard-latest-members] li');
            self::assertCount(5, $latestMembers);
            self::assertSame('dashmember'.$suffix.'1 · Dashboard member 1 '.$suffix, trim($latestMembers->first()->filter('a')->text()));
            self::assertSame('/admin/members/'.$members[0]->getId(), $latestMembers->first()->filter('a')->attr('href'));
            self::assertSelectorTextContains('section[data-dashboard-latest-members]', 'Aktiv');
            self::assertSelectorTextContains('section[data-dashboard-latest-members] time', 'UTC');
            self::assertStringEndsWith('+00:00', (string) $latestMembers->first()->filter('time')->attr('datetime'));

            $latestComments = $client->getCrawler()->filter('[data-dashboard-latest-comments] li');
            self::assertCount(5, $latestComments);
            self::assertSelectorTextContains('section[data-dashboard-latest-comments] li:first-child', 'Dashboard commenter 1 '.$suffix);
            self::assertSelectorTextContains('section[data-dashboard-latest-comments] li:first-child', 'Dashboard video 1 '.$suffix);
            self::assertSelectorTextContains('section[data-dashboard-latest-comments] li:first-child', 'Freigegeben');
            self::assertSelectorTextContains('section[data-dashboard-latest-comments] time', 'UTC');
            self::assertStringEndsWith('+00:00', (string) $latestComments->first()->filter('time')->attr('datetime'));
            self::assertSelectorExists('section[data-dashboard-latest-comments] a[href="/admin/comments"]');
            $tiedComments = array_slice($comments, 1, 4);
            usort($tiedComments, static fn (ContentComment $left, ContentComment $right): int => $right->getId() <=> $left->getId());
            $expectedCommenters = ['Dashboard commenter 1 '.$suffix];
            foreach ($tiedComments as $comment) {
                $expectedCommenters[] = $comment->getAuthorName();
            }
            $actualCommenters = $latestComments->each(
                static fn (Crawler $commentRow): string => trim($commentRow->filter('strong')->text()),
            );
            self::assertSame($expectedCommenters, $actualCommenters);

            $dashboardHtml = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('&lt;script&gt;', $dashboardHtml);
            self::assertStringNotContainsString('<script>alert("dashboard-article-'.$suffix.'")</script>', $dashboardHtml);
            self::assertStringNotContainsString('<script>alert("dashboard-comment-'.$suffix.'")</script>', $dashboardHtml);
            self::assertStringNotContainsString('comment-tail-secret-'.$suffix, $dashboardHtml);
            self::assertStringNotContainsString('dashboard-private-article-body-'.$suffix, $dashboardHtml);
            foreach ($memberEmails as $memberEmail) {
                self::assertStringNotContainsString($memberEmail, $dashboardHtml);
            }
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                foreach ($dashboardFixtureReferences as [$entityClass, $entityId]) {
                    $fixture = $cleanupManager->getRepository($entityClass)->find($entityId);
                    if (null !== $fixture) {
                        $cleanupManager->remove($fixture);
                    }
                }
                $cleanupManager->flush();
            }

            $this->removeUser($client, $email);
        }
    }

    public function testDashboardShowsBoundedPendingVideoReviewQueuesWithoutPrivateDetails(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $runtime = self::createStub(PhpRuntimeInspectorInterface::class);
        $runtime->method('version')->willReturn('8.5.4');
        $runtime->method('sapi')->willReturn('fpm-fcgi');
        $runtime->method('iniValue')->willReturn('0');
        $runtime->method('extensionLoaded')->willReturn(true);
        $client->getContainer()->set(PhpRuntimeInspectorInterface::class, $runtime);

        $email = 'dashboard-video-review-'.bin2hex(random_bytes(5)).'@example.test';
        $password = 'DashboardVideoReview!2026';
        $dashboardFixtureReferences = [];

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $connection = $entityManager->getConnection();
            $suffix = bin2hex(random_bytes(4));
            $submitterEmail = 'dashboard-review-submitter-'.$suffix.'@example.test';
            $submitter = new MemberAccount($submitterEmail, 'review'.$suffix, 'Review Submitter '.$suffix);
            $entityManager->persist($submitter);

            $pendingReports = [];
            $pendingSubmissions = [];
            $closedReport = null;
            $closedSubmission = null;

            for ($index = 0; $index <= 6; ++$index) {
                $videoTitle = 0 === $index
                    ? '<script>alert("dashboard-review-'.$suffix.'")</script>'
                    : 'Dashboard review video '.$index.' '.$suffix;
                $video = new Video(
                    $videoTitle,
                    'Private dashboard review source description '.$suffix.' '.$index,
                    null,
                    VideoSourceType::Url,
                    'https://review.example.test/'.$suffix.'/'.$index.'.mp4',
                    PublishingStatus::Draft,
                    false,
                    true,
                );
                $submission = new VideoSubmission($video, $submitter);
                $report = new VideoReport(
                    $video,
                    VideoReportReason::BrokenMedia,
                    'dashboard-review-private-report-'.$suffix.'-'.$index,
                );

                if (6 === $index) {
                    $submission->resolve(VideoSubmissionStatus::Rejected);
                    $report->resolve(VideoReportStatus::Dismissed);
                    $closedSubmission = $submission;
                    $closedReport = $report;
                } else {
                    $pendingSubmissions[] = $submission;
                    $pendingReports[] = $report;
                }

                $entityManager->persist($video);
                $entityManager->persist($submission);
                $entityManager->persist($report);
                $dashboardFixtureReferences[] = [VideoReport::class, $report->getId()];
                $dashboardFixtureReferences[] = [VideoSubmission::class, $submission->getId()];
                $dashboardFixtureReferences[] = [Video::class, $video->getId()];
            }
            $dashboardFixtureReferences[] = [MemberAccount::class, $submitter->getId()];
            self::assertInstanceOf(VideoSubmission::class, $closedSubmission);
            self::assertInstanceOf(VideoReport::class, $closedReport);

            $entityManager->flush();
            foreach ($pendingReports as $index => $report) {
                $createdAt = 5 === $index ? '1999-12-31 23:59:59' : '2000-01-01 00:00:00';
                $connection->executeStatement(
                    'UPDATE video_reports SET created_at = CAST(:created_at AS TIMESTAMP WITHOUT TIME ZONE) WHERE id = :id',
                    ['created_at' => $createdAt, 'id' => $report->getId()],
                );
            }
            foreach ($pendingSubmissions as $index => $submission) {
                $createdAt = 5 === $index ? '1999-12-31 23:59:59' : '2000-01-01 00:00:00';
                $connection->executeStatement(
                    'UPDATE video_submissions SET created_at = CAST(:created_at AS TIMESTAMP WITHOUT TIME ZONE) WHERE id = :id',
                    ['created_at' => $createdAt, 'id' => $submission->getId()],
                );
            }
            $connection->executeStatement(
                'UPDATE video_reports SET created_at = CAST(:created_at AS TIMESTAMP WITHOUT TIME ZONE) WHERE id = :id',
                ['created_at' => '2099-12-31 23:59:59', 'id' => $closedReport->getId()],
            );
            $connection->executeStatement(
                'UPDATE video_submissions SET created_at = CAST(:created_at AS TIMESTAMP WITHOUT TIME ZONE) WHERE id = :id',
                ['created_at' => '2099-12-31 23:59:59', 'id' => $closedSubmission->getId()],
            );
            $entityManager->clear();

            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            $cacheControl = $client->getResponse()->headers->get('Cache-Control');
            self::assertIsString($cacheControl);
            self::assertStringContainsString('private', $cacheControl);
            self::assertStringContainsString('no-store', $cacheControl);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertSelectorTextContains('[data-dashboard-video-review]', 'Videos zur Prüfung');

            $latestReports = $client->getCrawler()->filter('[data-dashboard-pending-video-reports] li');
            self::assertCount(5, $latestReports);
            self::assertSelectorExists('[data-dashboard-pending-video-reports] a[href="/admin/video-reports"]');
            self::assertSelectorExists('[data-dashboard-pending-video-reports] time[datetime]');
            self::assertStringEndsWith('+00:00', (string) $latestReports->first()->filter('time')->attr('datetime'));
            $expectedReports = array_slice($pendingReports, 0, 5);
            usort($expectedReports, static fn (VideoReport $left, VideoReport $right): int => $right->getId() <=> $left->getId());
            $expectedReportTitles = array_map(
                static fn (VideoReport $report): string => $report->getVideo()->getTitle(),
                $expectedReports,
            );
            $actualReportTitles = $latestReports->each(
                static fn (Crawler $row): string => trim($row->filter('a')->text()),
            );
            self::assertSame($expectedReportTitles, $actualReportTitles);

            $latestSubmissions = $client->getCrawler()->filter('[data-dashboard-pending-video-submissions] li');
            self::assertCount(5, $latestSubmissions);
            self::assertSelectorExists('[data-dashboard-pending-video-submissions] a[href="/admin/video-submissions"]');
            self::assertSelectorExists('[data-dashboard-pending-video-submissions] time[datetime]');
            self::assertStringEndsWith('+00:00', (string) $latestSubmissions->first()->filter('time')->attr('datetime'));
            $expectedSubmissions = array_slice($pendingSubmissions, 0, 5);
            usort($expectedSubmissions, static fn (VideoSubmission $left, VideoSubmission $right): int => $right->getId() <=> $left->getId());
            $expectedSubmissionTitles = array_map(
                static fn (VideoSubmission $submission): string => $submission->getVideo()->getTitle(),
                $expectedSubmissions,
            );
            $actualSubmissionTitles = $latestSubmissions->each(
                static fn (Crawler $row): string => trim($row->filter('a')->text()),
            );
            self::assertSame($expectedSubmissionTitles, $actualSubmissionTitles);

            $dashboardHtml = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('&lt;script&gt;', $dashboardHtml);
            self::assertStringNotContainsString('<script>alert("dashboard-review-'.$suffix.'")</script>', $dashboardHtml);
            self::assertStringNotContainsString('dashboard-review-private-report-'.$suffix, $dashboardHtml);
            self::assertStringNotContainsString('Private dashboard review source description '.$suffix, $dashboardHtml);
            self::assertStringNotContainsString('https://review.example.test/', $dashboardHtml);
            self::assertStringNotContainsString($submitterEmail, $dashboardHtml);
            self::assertNotContains('Dashboard review video 6 '.$suffix, $actualReportTitles);
            self::assertNotContains('Dashboard review video 6 '.$suffix, $actualSubmissionTitles);
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                foreach ($dashboardFixtureReferences as [$entityClass, $entityId]) {
                    $fixture = $cleanupManager->getRepository($entityClass)->find($entityId);
                    if (null !== $fixture) {
                        $cleanupManager->remove($fixture);
                    }
                }
                $cleanupManager->flush();
            }

            $this->removeUser($client, $email);
        }
    }

    public function testSearchStatisticsRequiresAdminAndCsrfAndEscapesTerms(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'search-statistics-'.bin2hex(random_bytes(6)).'@example.test';
        $term = 'search-'.bin2hex(random_bytes(6)).' <script>';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        try {
            $client->request('GET', '/admin/search-statistics');
            self::assertResponseRedirects('/admin/login');

            $connection->executeStatement(
                'INSERT INTO search_term_daily (day, term, search_count, last_at) VALUES (CURRENT_DATE, :term, 2, CURRENT_TIMESTAMP)',
                ['term' => $term],
            );
            $this->persistUser($client, $email, 'SearchStatistics!2026', ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, 'SearchStatistics!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/search-statistics?q='.rawurlencode($term));
            self::assertResponseIsSuccessful();
            self::assertStringContainsString(htmlspecialchars($term, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('<script>', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSame(self::CSP_REPORT_ONLY_POLICY, $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));

            $client->request('POST', '/admin/search-statistics/clear', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertNotFalse($connection->fetchOne('SELECT term FROM search_term_daily WHERE term = :term', ['term' => $term]));

            $client->request('GET', '/admin/search-statistics');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()
                ->filter('form[action="/admin/search-statistics/clear"] input[name="_token"]')
                ->attr('value');
            self::assertNotEmpty($token);
            $client->request('POST', '/admin/search-statistics/clear', ['_token' => $token]);
            self::assertResponseRedirects('/admin/search-statistics');
            self::assertFalse($connection->fetchOne('SELECT term FROM search_term_daily WHERE term = :term', ['term' => $term]));
        } finally {
            $connection->executeStatement('DELETE FROM search_term_daily WHERE term = :term', ['term' => $term]);
            $this->removeUser($client, $email);
        }
    }

    public function testContentStatisticsRequiresAdminAndRestrictsDateRange(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $suffix = bin2hex(random_bytes(6));
        $email = 'content-statistics-'.$suffix.'@example.test';
        $member = new MemberAccount('member-statistics-'.$suffix.'@example.test', 'stats-'.$suffix, 'Statistics Member');
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        try {
            $client->request('GET', '/admin/statistics');
            self::assertResponseRedirects('/admin/login');

            $this->persistUser($client, $email, 'ContentStatistics!2026', ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, 'ContentStatistics!2026');
            self::assertResponseRedirects('/admin');

            $entityManager->persist($member);
            $entityManager->flush();
            $client->request('GET', '/admin/statistics?days=7');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Inhaltsstatistik');
            self::assertCount(7, $client->getCrawler()->filter('tbody tr'));
            self::assertGreaterThanOrEqual(1, (int) $client->getCrawler()->filter('tbody tr:first-child td:last-child')->text());
            self::assertStringNotContainsString($member->getEmail(), (string) $client->getResponse()->getContent());
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSame(self::CSP_REPORT_ONLY_POLICY, $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));

            $client->request('GET', '/admin/statistics?days=90');
            self::assertResponseIsSuccessful();
            self::assertCount(90, $client->getCrawler()->filter('tbody tr'));
            $client->request('GET', '/admin/statistics?days=500');
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $member->getId()]);
            $this->removeUser($client, $email);
        }
    }

    public function testAnonymousCannotReachProtectedMediaAdministration(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/videos/'.str_repeat('a', 32).'/thumbnail');
        self::assertResponseRedirects('/admin/login');
        $client->request('GET', '/admin/series/'.str_repeat('b', 32).'/artwork');
        self::assertResponseRedirects('/admin/login');
        $client->request('GET', '/admin/video-submissions');
        self::assertResponseRedirects('/admin/login');
        $client->request('GET', '/admin/videos/import/provider');
        self::assertResponseRedirects('/admin/login');
    }

    public function testEncodedAdminPrefixCannotBypassRoleAccessControl(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $client->request('GET', '/%61dmin');

        self::assertResponseRedirects('/admin/login');

        $client->request('GET', '/%61dmin/login');

        self::assertResponseIsSuccessful();
    }

    public function testAdminLoginResponseHasBaselineSecurityHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();

        $headers = $client->getResponse()->headers;
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        self::assertSame('camera=(), geolocation=(), microphone=()', $headers->get('Permissions-Policy'));
        self::assertTrue($headers->getCacheControlDirective('no-store'));
        $this->assertReportOnlyCsp($client);
    }

    public function testHomeResponseHasReportOnlyCsp(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $this->assertReportOnlyCsp($client);
    }

    public function testAdminSessionCookieUsesHttpOnlyAndSameSiteOnHttps(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTPS', 'on');
        $client->request('GET', 'https://localhost/admin/login');

        self::assertResponseIsSuccessful();
        self::assertTrue($client->getRequest()->isSecure(), 'The kernel request must use HTTPS.');

        $sessionCookie = $client->getCookieJar()->get('MOCKSESSID');
        self::assertInstanceOf(BrowserKitCookie::class, $sessionCookie);
        self::assertTrue($sessionCookie->isHttpOnly(), 'The session cookie must be HttpOnly.');
        self::assertSame(Cookie::SAMESITE_LAX, $sessionCookie->getSameSite());
    }

    public function testProductionHostSessionCookieConfigurationHasRequiredSecurityAttributes(): void
    {
        $configuration = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/prod/framework.yaml');
        self::assertIsArray($configuration);
        $framework = $configuration['framework'] ?? null;
        self::assertIsArray($framework);
        $session = $framework['session'] ?? null;
        self::assertIsArray($session);

        self::assertSame('__Host-arcanorum_session', $session['name'] ?? null);
        self::assertSame(true, $session['cookie_secure'] ?? null);
        self::assertSame(true, $session['cookie_httponly'] ?? null);
        self::assertSame('lax', $session['cookie_samesite'] ?? null);
        self::assertSame('/', $session['cookie_path'] ?? null);
        self::assertSame('', $session['cookie_domain'] ?? null);
    }

    public function testUnknownRuntimeAttestationBlocksAdminRouteWithSecurityHeaders(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new UnconfiguredRuntimeAttestationReportProvider(),
        );
        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(503);

        $headers = $client->getResponse()->headers;
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('60', $headers->get('Retry-After'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        self::assertSame('camera=(), geolocation=(), microphone=()', $headers->get('Permissions-Policy'));
        $this->assertReportOnlyCsp($client);
    }

    public function testUnknownRuntimeAttestationBlocksAdminBootstrapBeforeInteractivePrompt(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new UnconfiguredRuntimeAttestationReportProvider(),
        );
        $incidentReporter = $this->createMock(RuntimeAttestationIncidentReporterInterface::class);
        $incidentReporter
            ->expects($this->once())
            ->method('report')
            ->with(
                'app:admin:create',
                RuntimeAttestationStatus::Unknown,
                self::callback(static fn (array $ids): bool => in_array('INV-AUTH-001', $ids, true)),
            )
            ->willThrowException(new RuntimeAttestationIncidentDeliveryUnavailable());
        $client->getContainer()->set(RuntimeAttestationIncidentReporterInterface::class, $incidentReporter);

        $tester = new CommandTester($this->createAdminUserCommand($client));
        $tester->setInputs(['must-not-be-requested@example.test', 'Test-only-long-passphrase-456!', 'Test-only-long-passphrase-456!']);

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => true]));
        self::assertStringContainsString('Sicherheitsnachweise', $tester->getDisplay());
        self::assertStringNotContainsString('E-Mail-Adresse', $tester->getDisplay());
        self::assertStringNotContainsString('Unabhängige Laufzeitattestierung', $tester->getDisplay());
    }

    public function testRuntimeAttestationIsRecheckedBeforeAdminBootstrapIsPersisted(): void
    {
        $client = static::createClient();
        $email = 'blocked-admin-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';
        $provider = $this->createMock(RuntimeAttestationReportProviderInterface::class);
        $provider
            ->expects($this->exactly(2))
            ->method('verifyCurrent')
            ->willReturnOnConsecutiveCalls(
                new VerifiedRuntimeAttestationReportProvider()->verifyCurrent(),
                new UnconfiguredRuntimeAttestationReportProvider()->verifyCurrent(),
            );
        $client->getContainer()->set(RuntimeAttestationReportProviderInterface::class, $provider);
        $incidentReporter = $this->createMock(RuntimeAttestationIncidentReporterInterface::class);
        $incidentReporter
            ->expects($this->once())
            ->method('report')
            ->with(
                'app:admin:create',
                RuntimeAttestationStatus::Unknown,
                self::callback(static fn (array $ids): bool => in_array('INV-INPUT-OUTPUT-001', $ids, true)),
            );
        $client->getContainer()->set(RuntimeAttestationIncidentReporterInterface::class, $incidentReporter);

        $tester = new CommandTester($this->createAdminUserCommand($client));
        $tester->setInputs([$email, $password, $password]);

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => true]));
        self::assertStringContainsString('Sicherheitsnachweise', $tester->getDisplay());
        self::assertStringNotContainsString('Unabhängige Laufzeitattestierung', $tester->getDisplay());

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $repository = $entityManager->getRepository(AdminUser::class);
        self::assertInstanceOf(AdminUserRepository::class, $repository);
        self::assertNull($repository->findOneByEmail($email));
    }

    public function testFailClosedLoginResponseRetainsBaselineSecurityHeaders(): void
    {
        $previousProcessRedisUrl = getenv('REDIS_URL');
        $hadEnvRedisUrl = array_key_exists('REDIS_URL', $_ENV);
        $previousEnvRedisUrl = $_ENV['REDIS_URL'] ?? null;
        $hadServerRedisUrl = array_key_exists('REDIS_URL', $_SERVER);
        $previousServerRedisUrl = $_SERVER['REDIS_URL'] ?? null;

        try {
            $unavailableRedisUrl = 'redis://127.0.0.1:1';
            putenv('REDIS_URL='.$unavailableRedisUrl);
            $_ENV['REDIS_URL'] = $unavailableRedisUrl;
            $_SERVER['REDIS_URL'] = $unavailableRedisUrl;

            $client = static::createClient();
            $client->request('POST', '/admin/login');

            self::assertResponseStatusCodeSame(503);

            $headers = $client->getResponse()->headers;
            self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
            self::assertSame('DENY', $headers->get('X-Frame-Options'));
            self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
            self::assertSame('camera=(), geolocation=(), microphone=()', $headers->get('Permissions-Policy'));
        } finally {
            putenv(false === $previousProcessRedisUrl ? 'REDIS_URL' : 'REDIS_URL='.$previousProcessRedisUrl);

            if ($hadEnvRedisUrl) {
                $_ENV['REDIS_URL'] = $previousEnvRedisUrl;
            } else {
                unset($_ENV['REDIS_URL']);
            }

            if ($hadServerRedisUrl) {
                $_SERVER['REDIS_URL'] = $previousServerRedisUrl;
            } else {
                unset($_SERVER['REDIS_URL']);
            }
        }
    }

    public function testLoginRequiresCsrfToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/admin/login', [
            '_username' => 'nobody@example.test',
            '_password' => 'not-a-production-password',
        ]);

        self::assertResponseRedirects('/admin/login');
        $client->followRedirect();

        self::assertSelectorExists('[role="alert"]');
    }

    public function testUntrustedForwardedHeadersDoNotChangeClientIpSchemeOrHost(): void
    {
        static::createClient();

        $request = Request::create(
            'http://app.example.test/admin/login',
            Request::METHOD_GET,
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '192.0.2.42',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST' => 'spoofed.example.test',
            ],
        );

        self::assertSame('192.0.2.42', $request->getClientIp());
        self::assertSame('http', $request->getScheme());
        self::assertSame('app.example.test', $request->getHost());
    }

    public function testLoginThrottleCannotBeBypassedWithSpoofedForwardedIps(): void
    {
        $client = static::createClient();
        $email = 'throttle-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $client->setServerParameter('REMOTE_ADDR', '192.0.2.'.random_int(1, 254));

            for ($attempt = 1; $attempt <= 5; ++$attempt) {
                $client->setServerParameter('HTTP_X_FORWARDED_FOR', '203.0.113.'.$attempt);
                $this->submitLogin($client, $email, 'Wrong-test-passphrase-789!');
                self::assertResponseRedirects('/admin/login');
            }

            $client->setServerParameter('HTTP_X_FORWARDED_FOR', '198.51.100.44');
            $this->submitLogin($client, $email, $password);

            self::assertResponseRedirects('/admin/login');
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testLoginThrottleAlsoLimitsAttemptsAcrossUsernamesByClientIp(): void
    {
        $client = static::createClient();
        $email = 'ip-throttle-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';
        $remoteAddress = sprintf('172.%d.%d.%d', random_int(16, 31), random_int(0, 255), random_int(1, 254));

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $client->setServerParameter('REMOTE_ADDR', $remoteAddress);

            for ($attempt = 1; $attempt <= 25; ++$attempt) {
                $this->submitLogin($client, sprintf('missing-%02d@example.test', $attempt), 'Wrong-test-passphrase-789!');
                self::assertResponseRedirects('/admin/login');
            }

            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin/login');

            $client->request('GET', '/admin');
            self::assertResponseRedirects('/admin/login');
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testFailedLoginUsesSameMessageForUnknownAndExistingAccounts(): void
    {
        $client = static::createClient();
        $email = 'enumeration-'.bin2hex(random_bytes(4)).'@example.test';
        $unknownEmail = 'missing-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-321!';
        $incorrectPassword = 'Incorrect-passphrase-123!';

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);

            $client->request('GET', '/admin/login');
            $client->submitForm('Anmelden', [
                '_username' => $unknownEmail,
                '_password' => $incorrectPassword,
            ]);
            self::assertResponseRedirects('/admin/login');
            $client->followRedirect();
            $unknownAccountMessage = $client->getCrawler()->filter('[role="alert"]')->text();

            $client->request('GET', '/admin/login');
            $client->submitForm('Anmelden', [
                '_username' => $email,
                '_password' => $incorrectPassword,
            ]);
            self::assertResponseRedirects('/admin/login');
            $client->followRedirect();
            $existingAccountMessage = $client->getCrawler()->filter('[role="alert"]')->text();

            self::assertSame('Die Anmeldung ist fehlgeschlagen. Prüfe deine Angaben und versuche es erneut.', $unknownAccountMessage);
            self::assertSame($unknownAccountMessage, $existingAccountMessage);
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testSuccessfulLoginReplacesSessionCookieIdentifier(): void
    {
        $client = static::createClient();
        $email = 'session-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-987!';

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);

            $client->request('GET', '/admin/login');
            $sessionCookieBefore = $client->getCookieJar()->get('MOCKSESSID');
            self::assertNotNull($sessionCookieBefore);
            $sessionIdBefore = $sessionCookieBefore->getValue();
            $mfaCode = $this->loginCode($client, $email);
            self::assertNotNull($mfaCode);

            $client->submitForm('Anmelden', [
                '_username' => $email,
                '_password' => $password,
                '_mfa_code' => $mfaCode,
            ]);

            self::assertResponseRedirects('/admin');
            $sessionCookieAfter = $client->getCookieJar()->get('MOCKSESSID');
            self::assertNotNull($sessionCookieAfter);
            self::assertNotSame($sessionIdBefore, $sessionCookieAfter->getValue());
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testAdministratorCanBeCreatedAndCanLogInAndOut(): void
    {
        $client = static::createClient();
        $email = 'admin-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';
        $verifiedReportProvider = null;
        $dashboardFixtureReferences = [];

        try {
            $this->createAdministratorInteractively($client, $email, $password);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $connection = $entityManager->getConnection();
            $repository = $entityManager->getRepository(AdminUser::class);
            self::assertInstanceOf(AdminUserRepository::class, $repository);
            $user = $repository->findOneByEmail($email);
            self::assertNotNull($user);
            self::assertNotSame($password, $user->getPassword());
            self::assertContains('ROLE_ADMIN', $user->getRoles());

            $client->request('GET', '/admin/login');
            $sessionCookieBeforeLogin = $client->getCookieJar()->get('MOCKSESSID');
            self::assertNotNull($sessionCookieBeforeLogin);

            $client->submitForm('Anmelden', [
                '_username' => $email,
                '_password' => $password,
                '_mfa_code' => $this->loginCode($client, $email),
            ]);

            $sessionCookieAfterLogin = $client->getCookieJar()->get('MOCKSESSID');
            self::assertNotNull($sessionCookieAfterLogin);
            self::assertNotSame($sessionCookieBeforeLogin->getValue(), $sessionCookieAfterLogin->getValue());

            self::assertResponseRedirects('/admin');

            $suffix = bin2hex(random_bytes(4));
            $activeMember = new MemberAccount('dashboard-active-'.$suffix.'@example.test', 'dashactive'.$suffix, 'Dashboard Active');
            $inactiveMember = new MemberAccount('dashboard-inactive-'.$suffix.'@example.test', 'dashinactive'.$suffix, 'Dashboard Inactive');
            $inactiveMember->setActive(false);
            $dashboardVideoTitle = '<script>alert("dashboard-title-'.$suffix.'")</script>';
            $pendingVideo = new Video(
                $dashboardVideoTitle,
                'Noch nicht freigegeben',
                null,
                VideoSourceType::Url,
                'https://videos.example.test/dashboard.mp4',
                PublishingStatus::Draft,
                false,
                true,
            );
            $olderDashboardVideos = [];
            for ($index = 1; $index <= 6; ++$index) {
                $olderDashboardVideos[] = new Video(
                    'Dashboard älter '.$index.' '.$suffix,
                    'Dashboard display fixture.',
                    null,
                    VideoSourceType::Url,
                    'https://videos.example.test/dashboard-old-'.$suffix.'-'.$index.'.mp4',
                    PublishingStatus::Draft,
                    false,
                    true,
                );
            }
            $pendingSubmission = new VideoSubmission($pendingVideo, $activeMember);
            $pendingReport = new VideoReport($pendingVideo, VideoReportReason::BrokenMedia, 'Quelle nicht erreichbar');
            $pendingContactMessage = new ContactMessage(
                'Dashboard Test',
                'dashboard-contact@example.test',
                'Dashboard-Anfrage',
                'Offene Nachricht für die Dashboard-Prüfung.',
            );
            $dashboardPage = new StaticPage(
                'Dashboard-Seite '.$suffix,
                'dashboard-seite-'.$suffix,
                'Eine interne Testzusammenfassung.',
                '<p>Interner Testinhalt</p>',
                PublishingStatus::Draft,
            );
            $dashboardComments = [
                new ContentComment(
                    CommentContentType::Video,
                    bin2hex(random_bytes(16)),
                    'Dashboard metric video '.$suffix,
                    'Dashboard pending commenter '.$suffix,
                    'Pending dashboard metric comment.',
                ),
                new ContentComment(
                    CommentContentType::Video,
                    bin2hex(random_bytes(16)),
                    'Dashboard metric video '.$suffix,
                    'Dashboard approved commenter '.$suffix,
                    'Approved dashboard metric comment.',
                ),
            ];
            $dashboardComments[1]->approve();
            $dashboardFixtureReferences = [
                [VideoSubmission::class, $pendingSubmission->getId()],
                [VideoReport::class, $pendingReport->getId()],
                [ContactMessage::class, $pendingContactMessage->getId()],
                [Video::class, $pendingVideo->getId()],
                [MemberAccount::class, $activeMember->getId()],
                [MemberAccount::class, $inactiveMember->getId()],
                [StaticPage::class, $dashboardPage->getId()],
                [ContentComment::class, $dashboardComments[0]->getId()],
                [ContentComment::class, $dashboardComments[1]->getId()],
            ];
            foreach ($olderDashboardVideos as $dashboardVideo) {
                $dashboardFixtureReferences[] = [Video::class, $dashboardVideo->getId()];
            }

            foreach ([
                $activeMember,
                $inactiveMember,
                $pendingVideo,
                $pendingSubmission,
                $pendingReport,
                $pendingContactMessage,
                $dashboardPage,
                ...$dashboardComments,
            ] as $entity) {
                $entityManager->persist($entity);
            }
            foreach ($olderDashboardVideos as $dashboardVideo) {
                $entityManager->persist($dashboardVideo);
            }
            $entityManager->flush();
            foreach ($olderDashboardVideos as $index => $dashboardVideo) {
                $connection->executeStatement(
                    'UPDATE videos SET created_at = CAST(:created_at AS TIMESTAMP WITHOUT TIME ZONE) WHERE id = :id',
                    [
                        'created_at' => sprintf('2000-01-%02d 00:00:00', $index + 1),
                        'id' => $dashboardVideo->getId(),
                    ],
                );
            }
            $connection->executeStatement(
                'UPDATE videos SET created_at = CAST(:created_at AS TIMESTAMP WITHOUT TIME ZONE) WHERE id = :id',
                ['created_at' => '2099-12-31 23:59:59', 'id' => $pendingVideo->getId()],
            );
            $entityManager->clear();

            $client->followRedirect();

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'CMS-Verwaltung');
            self::assertSelectorTextContains('#dashboard-latest-videos-heading', 'Neueste Videos');
            $latestVideos = $client->getCrawler()->filter('[data-dashboard-latest-videos] li');
            self::assertCount(5, $latestVideos);
            self::assertSame($dashboardVideoTitle, trim($latestVideos->first()->filter('a')->text()));
            self::assertSame('/admin/videos/'.$pendingVideo->getId().'/edit', $latestVideos->first()->filter('a')->attr('href'));
            self::assertSelectorTextContains('section[data-dashboard-latest-videos]', 'Entwurf');
            self::assertSelectorExists('section[data-dashboard-latest-videos] time[datetime]');
            self::assertSelectorTextContains('section[data-dashboard-latest-videos] time', 'UTC');
            self::assertStringEndsWith('+00:00', (string) $latestVideos->first()->filter('time')->attr('datetime'));
            $dashboardHtml = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($dashboardVideoTitle, $dashboardHtml);
            self::assertStringContainsString('&lt;script&gt;', $dashboardHtml);
            self::assertStringNotContainsString('https://videos.example.test/', $dashboardHtml);

            $dashboardContainer = $client->getContainer();
            $newsArticles = $dashboardContainer->get(NewsArticleRepository::class);
            $videos = $dashboardContainer->get(VideoRepository::class);
            $series = $dashboardContainer->get(ContentSeriesRepository::class);
            $comments = $dashboardContainer->get(ContentCommentRepository::class);
            $staticPages = $dashboardContainer->get(StaticPageRepository::class);
            $members = $dashboardContainer->get(MemberAccountRepository::class);
            $submissions = $dashboardContainer->get(VideoSubmissionRepository::class);
            $videoReports = $dashboardContainer->get(VideoReportRepository::class);
            $contactMessages = $dashboardContainer->get(ContactMessageRepository::class);
            self::assertInstanceOf(NewsArticleRepository::class, $newsArticles);
            self::assertInstanceOf(VideoRepository::class, $videos);
            self::assertInstanceOf(ContentSeriesRepository::class, $series);
            self::assertInstanceOf(ContentCommentRepository::class, $comments);
            self::assertInstanceOf(StaticPageRepository::class, $staticPages);
            self::assertInstanceOf(MemberAccountRepository::class, $members);
            self::assertInstanceOf(VideoSubmissionRepository::class, $submissions);
            self::assertInstanceOf(VideoReportRepository::class, $videoReports);
            self::assertInstanceOf(ContactMessageRepository::class, $contactMessages);
            $databaseCommentCountValue = $connection->fetchOne('SELECT COUNT(*) FROM content_comments');
            $databaseStaticPageCountValue = $connection->fetchOne('SELECT COUNT(*) FROM static_pages');
            if (!is_numeric($databaseCommentCountValue) || !is_numeric($databaseStaticPageCountValue)) {
                throw new \UnexpectedValueException('Dashboard aggregate query did not return numeric counts.');
            }
            $databaseCommentCount = (int) $databaseCommentCountValue;
            $databaseStaticPageCount = (int) $databaseStaticPageCountValue;
            self::assertSame($databaseCommentCount, $comments->countForAdmin());
            self::assertSame($databaseStaticPageCount, $staticPages->countForAdmin());
            $expectedMetrics = [
                'news' => [
                    'total' => $newsArticles->countForAdmin('', null),
                    'published' => $newsArticles->countForAdmin('', PublishingStatus::Published),
                    'draft' => $newsArticles->countForAdmin('', PublishingStatus::Draft),
                ],
                'videos' => [
                    'total' => $videos->countForAdmin('', null),
                    'published' => $videos->countForAdmin('', PublishingStatus::Published),
                    'draft' => $videos->countForAdmin('', PublishingStatus::Draft),
                ],
                'series' => [
                    'total' => $series->countForAdmin(),
                    'published' => $series->countForAdmin(PublishingStatus::Published),
                    'draft' => $series->countForAdmin(PublishingStatus::Draft),
                ],
                'comments' => [
                    'total' => $databaseCommentCount,
                    'pending' => $comments->countForStatus(CommentStatus::Pending),
                    'reported' => $comments->countForStatus(CommentStatus::Reported),
                ],
                'pages' => [
                    'total' => $databaseStaticPageCount,
                ],
                'members' => [
                    'total' => $members->countForAdmin('', null),
                    'active' => $members->countForAdmin('', true),
                    'inactive' => $members->countForAdmin('', false),
                ],
                'submissions' => [
                    'pending' => $submissions->countForStatus(VideoSubmissionStatus::Pending),
                ],
                'video_reports' => [
                    'pending' => $videoReports->countForStatus(VideoReportStatus::Pending),
                ],
                'contact_messages' => [
                    'pending' => $contactMessages->countForStatus(ContactMessageStatus::Pending),
                ],
            ];
            foreach ($expectedMetrics as $metricName => $counts) {
                $metricCard = $client->getCrawler()->filter(sprintf('[data-dashboard-metric="%s"]', $metricName));
                self::assertCount(1, $metricCard);

                foreach ($counts as $countName => $count) {
                    self::assertSame(
                        (string) $count,
                        trim($metricCard->filter(sprintf('[data-count="%s"]', $countName))->text()),
                    );
                }
            }
            self::assertSelectorExists('[data-dashboard-metric="pages"][href="/admin/pages"]');
            self::assertSelectorExists('[data-dashboard-metric="comments"][href="/admin/comments"]');
            self::assertStringNotContainsString('Interner Testinhalt', (string) $client->getResponse()->getContent());
            self::assertSelectorNotExists('section[aria-labelledby="fortress-heading"]');
            self::assertSelectorTextContains('section[aria-labelledby="host-capability-heading"]', 'Festgestellte Systemfähigkeiten');
            self::assertSelectorTextContains('section[aria-labelledby="host-capability-heading"] .admin-table tbody', 'PHP-Laufzeit');
            self::assertSelectorTextContains('section[aria-labelledby="host-capability-heading"] .status-pill.is-unknown', 'Unbekannt');
            self::assertSelectorTextContains('section[aria-labelledby="dashboard-notices-heading"]', 'Systemhinweise');
            self::assertSelectorTextContains('section[aria-labelledby="dashboard-notices-heading"]', 'Unbekannt');
            self::assertSelectorExists('section[aria-labelledby="dashboard-notices-heading"] a[href="/admin/system"]');
            self::assertSelectorTextContains('section[aria-labelledby="dashboard-open-heading"]', 'Offene Vorgänge');
            self::assertSelectorExists('section[aria-labelledby="dashboard-open-heading"] a[href="/admin/video-submissions"]');
            self::assertSelectorExists('section[aria-labelledby="dashboard-open-heading"] a[href="/admin/video-reports"]');
            self::assertSelectorExists('section[aria-labelledby="dashboard-open-heading"] a[href="/admin/comments"]');
            self::assertSelectorExists('section[aria-labelledby="dashboard-open-heading"] a[href="/admin/contact-messages"]');
            self::assertSelectorTextContains('section[aria-labelledby="dashboard-open-heading"]', (string) $expectedMetrics['submissions']['pending'].' Videoeinreichungen prüfen');
            self::assertSelectorTextContains('section[aria-labelledby="webserver-integrity-heading"]', 'Webserver-Integrität');
            self::assertSelectorTextContains('section[aria-labelledby="webserver-integrity-heading"]', 'Anforderungen, aber keinen Laufzeitnachweis');
            self::assertSelectorTextContains('section[aria-labelledby="webserver-integrity-heading"]', 'B→C-Erkennung');
            self::assertSelectorTextContains('section[aria-labelledby="webserver-integrity-heading"]', 'Nicht eingerichtet');
            self::assertSelectorExists('aside.admin-sidebar nav[aria-label="Hauptnavigation"] a[aria-current="page"]');
            self::assertSelectorTextContains('nav[aria-label="Hauptnavigation"]', 'Videos');
            self::assertSelectorTextContains('nav[aria-label="Hauptnavigation"]', 'News');
            self::assertSelectorTextContains('nav[aria-label="Hauptnavigation"]', 'Kommentare');
            self::assertSelectorTextContains('nav[aria-label="Hauptnavigation"]', 'Einstellungen');
            self::assertCount(12, $client->getCrawler()->filter('details.navigation-group'));
            self::assertSelectorTextContains('nav[aria-label="Hauptnavigation"] > details.navigation-group:first-child > summary', 'Fortress');
            self::assertSelectorExists('nav[aria-label="Hauptnavigation"] [aria-disabled="true"]');

            $client->disableReboot();
            $verifiedReportProvider = $client->getContainer()->get(RuntimeAttestationReportProviderInterface::class);
            self::assertInstanceOf(VerifiedRuntimeAttestationReportProvider::class, $verifiedReportProvider);

            $client->request('POST', '/admin/logout', ['_csrf_token' => 'invalid-test-token']);
            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();

            $verifiedReportProvider->reportUnknown();
            self::assertSame(RuntimeAttestationStatus::Unknown, $verifiedReportProvider->verifyCurrent()->status);
            $client->submitForm('Abmelden');
            self::assertResponseRedirects('/admin/login');

            $verifiedReportProvider->reportVerified();
            $client->request('GET', '/admin');
            self::assertResponseRedirects('/admin/login');
        } finally {
            if ($verifiedReportProvider instanceof VerifiedRuntimeAttestationReportProvider) {
                $verifiedReportProvider->reportVerified();
            }

            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                foreach ($dashboardFixtureReferences as [$entityClass, $entityId]) {
                    $fixture = $cleanupManager->getRepository($entityClass)->find($entityId);
                    if (null !== $fixture) {
                        $cleanupManager->remove($fixture);
                    }
                }
                $cleanupManager->flush();
            }

            $this->removeUser($client, $email);
        }
    }

    public function testUnprovisionedAdministratorCannotLogInWithPasswordAlone(): void
    {
        $client = static::createClient();
        $email = 'unprovisioned-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN'], false);
            $client->request('GET', '/admin/login');
            $client->submitForm('Anmelden', [
                '_username' => $email,
                '_password' => $password,
                '_mfa_code' => '',
            ]);

            self::assertResponseRedirects('/admin/login');
            $client->followRedirect();
            self::assertSelectorExists('[role="alert"]');

            $client->request('GET', '/admin');
            self::assertResponseRedirects('/admin/login');
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testIncorrectTotpCodeDoesNotAuthenticate(): void
    {
        $client = static::createClient();
        $email = 'wrong-totp-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';
        $client->setServerParameter('REMOTE_ADDR', sprintf('198.17.%d.%d', random_int(0, 255), random_int(1, 254)));

        try {
            $setup = $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            self::assertInstanceOf(AdminMfaProvisioningResult::class, $setup);

            $this->submitLogin($client, $email, $password, $this->incorrectTotpCode($client, $email));
            self::assertResponseRedirects('/admin/login');
            $client->followRedirect();
            self::assertSelectorExists('[role="alert"]');

            $client->request('GET', '/admin');
            self::assertResponseRedirects('/admin/login');
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testTotpCodeIsRejectedAfterItHasBeenUsed(): void
    {
        $client = static::createClient();
        $email = 'totp-replay-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';
        $client->setServerParameter('REMOTE_ADDR', sprintf('198.18.%d.%d', random_int(0, 255), random_int(1, 254)));

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $code = $this->loginCode($client, $email);
            $this->submitLogin($client, $email, $password, $code);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();
            $client->submitForm('Abmelden');
            self::assertResponseRedirects('/admin/login');

            $this->submitLogin($client, $email, $password, $code);
            self::assertResponseRedirects('/admin/login');
            $client->followRedirect();
            self::assertSelectorExists('[role="alert"]');
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testTotpLoginRejectsCodeForSecretReplacedAfterUserLoad(): void
    {
        $client = static::createClient();
        $email = 'rotated-totp-'.bin2hex(random_bytes(4)).'@example.test';

        try {
            $this->persistUser($client, $email, 'Test-only-long-passphrase-456!', ['ROLE_ADMIN']);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $users = $entityManager->getRepository(AdminUser::class);
            self::assertInstanceOf(AdminUserRepository::class, $users);
            $user = $users->findOneByEmail($email);
            self::assertInstanceOf(AdminUser::class, $user);
            $oldCiphertext = $user->getMfaSecretCiphertext();
            self::assertNotNull($oldCiphertext);

            $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
            self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
            $oldSecret = $cipher->decrypt($oldCiphertext);
            $replacementCiphertext = $cipher->encrypt(random_bytes(20));
            self::assertNotSame($oldCiphertext, $replacementCiphertext);

            $connection = $entityManager->getConnection();
            self::assertSame(
                1,
                $connection->executeStatement(
                    'UPDATE admin_users SET mfa_secret_ciphertext = :ciphertext WHERE id = :id',
                    ['ciphertext' => $replacementCiphertext, 'id' => $user->getId()],
                ),
            );

            $loginVerifier = $client->getContainer()->get(AdminMfaLoginVerifier::class);
            self::assertInstanceOf(AdminMfaLoginVerifier::class, $loginVerifier);
            $totpVerifier = $client->getContainer()->get(TotpVerifier::class);
            self::assertInstanceOf(TotpVerifier::class, $totpVerifier);
            $codeForPreviouslyLoadedSecret = $totpVerifier->codeAtTime($oldSecret, time());

            self::assertFalse($loginVerifier->verifyAndConsume($user, $codeForPreviouslyLoadedSecret));
            self::assertTrue(
                in_array(
                    $connection->fetchOne(
                        'SELECT COUNT(*) FROM admin_users WHERE id = :id AND mfa_last_totp_counter IS NOT NULL',
                        ['id' => $user->getId()],
                    ),
                    [0, '0'],
                    true,
                ),
            );
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testRecoveryCodesArePersistedOnlyAsKeyedHashes(): void
    {
        $client = static::createClient();
        $email = 'recovery-storage-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';

        try {
            $setup = $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            self::assertInstanceOf(AdminMfaProvisioningResult::class, $setup);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $repository = $entityManager->getRepository(AdminUser::class);
            self::assertInstanceOf(AdminUserRepository::class, $repository);
            $user = $repository->findOneByEmail($email);
            self::assertInstanceOf(AdminUser::class, $user);
            $hashes = $entityManager->getConnection()->fetchFirstColumn(
                'SELECT code_hash FROM admin_user_recovery_codes WHERE admin_user_id = :id',
                ['id' => $user->getId()],
            );

            self::assertCount(10, $hashes);
            $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
            self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
            foreach ($hashes as $hash) {
                self::assertIsString($hash);
                self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $hash);
                self::assertNotContains($hash, $setup->recoveryCodes);
            }
            foreach ($setup->recoveryCodes as $recoveryCode) {
                self::assertContains($cipher->hashRecoveryCode($recoveryCode), $hashes);
            }
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testRecoveryCodeIsRejectedAfterSecretReplacementOnLoadedUser(): void
    {
        $client = static::createClient();
        $email = 'rotated-recovery-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';

        try {
            $setup = $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            self::assertInstanceOf(AdminMfaProvisioningResult::class, $setup);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $users = $entityManager->getRepository(AdminUser::class);
            self::assertInstanceOf(AdminUserRepository::class, $users);
            $user = $users->findOneByEmail($email);
            self::assertInstanceOf(AdminUser::class, $user);
            $oldCiphertext = $user->getMfaSecretCiphertext();
            self::assertNotNull($oldCiphertext);

            $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
            self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
            $replacementCiphertext = $cipher->encrypt(random_bytes(20));
            self::assertNotSame($oldCiphertext, $replacementCiphertext);

            $connection = $entityManager->getConnection();
            self::assertSame(
                1,
                $connection->executeStatement(
                    'UPDATE admin_users SET mfa_secret_ciphertext = :ciphertext, mfa_auth_version = mfa_auth_version + 1 WHERE id = :id',
                    ['ciphertext' => $replacementCiphertext, 'id' => $user->getId()],
                ),
            );

            $loginVerifier = $client->getContainer()->get(AdminMfaLoginVerifier::class);
            self::assertInstanceOf(AdminMfaLoginVerifier::class, $loginVerifier);
            $recoveryCode = $setup->recoveryCodes[0];
            self::assertFalse($loginVerifier->verifyAndConsume($user, $recoveryCode));
            self::assertTrue(
                in_array(
                    $connection->fetchOne(
                        'SELECT COUNT(*) FROM admin_user_recovery_codes WHERE admin_user_id = :id AND code_hash = :hash',
                        ['id' => $user->getId(), 'hash' => $cipher->hashRecoveryCode($recoveryCode)],
                    ),
                    [1, '1'],
                    true,
                ),
            );
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testRecoveryCodeCanOnlyBeUsedOnce(): void
    {
        $client = static::createClient();
        $email = 'recovery-replay-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';
        $client->setServerParameter('REMOTE_ADDR', sprintf('198.19.%d.%d', random_int(0, 255), random_int(1, 254)));

        try {
            $setup = $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            self::assertInstanceOf(AdminMfaProvisioningResult::class, $setup);
            $recoveryCode = $setup->recoveryCodes[0];

            $this->submitLogin($client, $email, $password, $recoveryCode);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();
            $client->submitForm('Abmelden');
            self::assertResponseRedirects('/admin/login');

            $this->submitLogin($client, $email, $password, $recoveryCode);
            self::assertResponseRedirects('/admin/login');
            $client->followRedirect();
            self::assertSelectorExists('[role="alert"]');
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testExistingAdministratorCanBeReprovisionedOnlyByTheAttestedInteractiveCommand(): void
    {
        $client = static::createClient();
        $email = 'reprovision-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';
        $client->setServerParameter('REMOTE_ADDR', sprintf('198.20.%d.%d', random_int(0, 255), random_int(1, 254)));

        try {
            $initialSetup = $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            self::assertInstanceOf(AdminMfaProvisioningResult::class, $initialSetup);

            $tester = new CommandTester($this->provisionAdminMfaCommand($client));
            $tester->setInputs([$email, $email]);

            self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => true]));
            self::assertStringContainsString('Authenticator-URI', $tester->getDisplay());
            self::assertStringContainsString('Wiederherstellungscodes', $tester->getDisplay());
            self::assertStringNotContainsString($password, $tester->getDisplay());

            $this->submitLogin($client, $email, $password, $initialSetup->recoveryCodes[0]);
            self::assertResponseRedirects('/admin/login');

            $this->submitLogin($client, $email, $password, $this->loginCode($client, $email));
            self::assertResponseRedirects('/admin');
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testRecreatedAdminWithSameCredentialsCannotReuseOldSession(): void
    {
        $client = static::createClient();
        $email = 'recreated-session-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-678!';

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password, $this->loginCode($client, $email));
            self::assertResponseRedirects('/admin');
            $client->followRedirect();
            self::assertResponseIsSuccessful();

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();

            $users = $entityManager->getRepository(AdminUser::class);
            self::assertInstanceOf(AdminUserRepository::class, $users);
            $deletedUser = $users->findOneByEmail($email);
            self::assertInstanceOf(AdminUser::class, $deletedUser);
            $oldId = $deletedUser->getId();
            $passwordHash = $deletedUser->getPassword();
            $roles = $deletedUser->getRoles();
            $mfaSecretCiphertext = $deletedUser->getMfaSecretCiphertext();
            self::assertNotNull($passwordHash);
            self::assertNotNull($mfaSecretCiphertext);

            $entityManager->remove($deletedUser);
            $entityManager->flush();

            $replacementUser = new AdminUser($email);
            $replacementUser->setPassword($passwordHash);
            $replacementUser->setRoles($roles);
            $replacementUser->setMfaSecretCiphertext($mfaSecretCiphertext);
            $entityManager->persist($replacementUser);
            $entityManager->flush();

            self::assertNotSame($oldId, $replacementUser->getId());
            self::assertSame($email, $replacementUser->getUserIdentifier());
            self::assertSame($passwordHash, $replacementUser->getPassword());
            self::assertSame($roles, $replacementUser->getRoles());
            self::assertSame($mfaSecretCiphertext, $replacementUser->getMfaSecretCiphertext());
            self::assertFalse($deletedUser->isEqualTo($replacementUser));

            $client->request('GET', '/admin');

            self::assertResponseRedirects('/admin/login');
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testMfaReprovisioningRevokesExistingAdminSession(): void
    {
        $client = static::createClient();
        $email = 'mfa-session-revoke-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password, $this->loginCode($client, $email));
            self::assertResponseRedirects('/admin');
            $client->followRedirect();
            self::assertResponseIsSuccessful();

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();

            $users = $entityManager->getRepository(AdminUser::class);
            self::assertInstanceOf(AdminUserRepository::class, $users);
            $user = $users->findOneByEmail($email);
            self::assertInstanceOf(AdminUser::class, $user);

            $mfaProvisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
            self::assertInstanceOf(AdminMfaProvisioningService::class, $mfaProvisioner);
            $mfaProvisioner->provision($user);

            $client->request('GET', '/admin');

            self::assertResponseRedirects('/admin/login');
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testMfaReprovisioningRefreshesStaleAuthenticationVersionBeforeRotation(): void
    {
        $client = static::createClient();
        $email = 'mfa-version-refresh-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-567!';

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $users = $entityManager->getRepository(AdminUser::class);
            self::assertInstanceOf(AdminUserRepository::class, $users);
            $user = $users->findOneByEmail($email);
            self::assertInstanceOf(AdminUser::class, $user);

            $connection = $entityManager->getConnection();
            self::assertSame(
                1,
                $connection->executeStatement(
                    'UPDATE admin_users SET mfa_auth_version = 23 WHERE id = :id',
                    ['id' => $user->getId()],
                ),
            );

            $mfaProvisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
            self::assertInstanceOf(AdminMfaProvisioningService::class, $mfaProvisioner);
            $mfaProvisioner->provision($user);

            self::assertTrue(
                in_array(
                    $connection->fetchOne(
                        'SELECT mfa_auth_version FROM admin_users WHERE id = :id',
                        ['id' => $user->getId()],
                    ),
                    [24, '24'],
                    true,
                ),
            );
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testMfaReprovisioningRejectsConcurrentSecretRotationWithoutReplacingRecoveryCodes(): void
    {
        $client = static::createClient();
        $email = 'mfa-concurrent-rotation-'.bin2hex(random_bytes(4)).'@example.test';

        try {
            $this->persistUser($client, $email, 'Test-only-long-passphrase-456!', ['ROLE_ADMIN']);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();

            $users = $entityManager->getRepository(AdminUser::class);
            self::assertInstanceOf(AdminUserRepository::class, $users);
            $staleUser = $users->findOneByEmail($email);
            self::assertInstanceOf(AdminUser::class, $staleUser);
            self::assertNotNull($staleUser->getMfaSecretCiphertext());

            $connection = $entityManager->getConnection();
            $recoveryHashBefore = $connection->fetchOne(
                'SELECT code_hash FROM admin_user_recovery_codes WHERE admin_user_id = :id ORDER BY code_hash LIMIT 1',
                ['id' => $staleUser->getId()],
            );
            self::assertIsString($recoveryHashBefore);

            $concurrentCiphertext = 'concurrent-mfa-rotation-'.bin2hex(random_bytes(8));
            self::assertSame(
                1,
                $connection->executeStatement(
                    'UPDATE admin_users SET mfa_secret_ciphertext = :ciphertext, mfa_auth_version = mfa_auth_version + 1 WHERE id = :id',
                    ['ciphertext' => $concurrentCiphertext, 'id' => $staleUser->getId()],
                ),
            );

            $mfaProvisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
            self::assertInstanceOf(AdminMfaProvisioningService::class, $mfaProvisioner);

            try {
                $mfaProvisioner->provision($staleUser);
                self::fail('Eine veraltete MFA-Provisionierung muss nach einer Secret-Rotation abgebrochen werden.');
            } catch (AdminMfaProvisioningConflict) {
                // Erwarteter Konflikt: das parallel gespeicherte MFA-Geheimnis bleibt maßgeblich.
            }

            self::assertSame(
                $concurrentCiphertext,
                $connection->fetchOne('SELECT mfa_secret_ciphertext FROM admin_users WHERE id = :id', ['id' => $staleUser->getId()]),
            );
            self::assertSame(
                $recoveryHashBefore,
                $connection->fetchOne(
                    'SELECT code_hash FROM admin_user_recovery_codes WHERE admin_user_id = :id ORDER BY code_hash LIMIT 1',
                    ['id' => $staleUser->getId()],
                ),
            );
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testAuthenticatedUserWithoutAdminRoleCannotOpenAdministration(): void
    {
        $client = static::createClient();
        $email = 'editor-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-789!';

        try {
            $this->persistUser($client, $email, $password, ['ROLE_USER']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();

            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testAdminBootstrapRejectsPipedInputEvenWhenSymfonyMarksItInteractive(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $email = 'no-tty-bootstrap-'.bin2hex(random_bytes(4)).'@example.test';
        $tester = new CommandTester($this->createAdminUserCommand($client, false));
        $tester->setInputs([$email, 'Test-only-long-passphrase-456!', 'Test-only-long-passphrase-456!']);

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => true]));
        self::assertStringContainsString('interaktiven Terminal', $tester->getDisplay());
        self::assertStringNotContainsString('E-Mail-Adresse', $tester->getDisplay());

        $users = $client->getContainer()->get(AdminUserRepository::class);
        self::assertInstanceOf(AdminUserRepository::class, $users);
        self::assertNull($users->findOneByEmail($email));
    }

    public function testMfaReprovisioningRejectsPipedInputWithoutReplacingExistingSecret(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'no-tty-reprovision-'.bin2hex(random_bytes(4)).'@example.test';

        try {
            $this->persistUser($client, $email, 'Test-only-long-passphrase-456!', ['ROLE_ADMIN']);
            $users = $client->getContainer()->get(AdminUserRepository::class);
            self::assertInstanceOf(AdminUserRepository::class, $users);
            $user = $users->findOneByEmail($email);
            self::assertInstanceOf(AdminUser::class, $user);
            $originalCiphertext = $user->getMfaSecretCiphertext();
            self::assertNotNull($originalCiphertext);

            $tester = new CommandTester($this->provisionAdminMfaCommand($client, false));
            $tester->setInputs([$email, $email]);

            self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => true]));
            self::assertStringContainsString('interaktiven Terminal', $tester->getDisplay());
            self::assertStringNotContainsString('E-Mail-Adresse des Administratorkontos', $tester->getDisplay());
            self::assertSame($originalCiphertext, $users->findOneByEmail($email)?->getMfaSecretCiphertext());
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testVideoBulkMoveRequiresCsrfAndExistingVideoCategory(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(6));
        $adminEmail = 'bulk-move-'.$suffix.'@example.test';
        $videoCategory = new ContentCategory('Videos '.$suffix, ContentCategoryType::Video);
        $newsCategory = new ContentCategory('News '.$suffix, ContentCategoryType::News);
        $video = new Video(
            'Move test '.$suffix,
            'Test video',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );

        try {
            $entityManager->persist($videoCategory);
            $entityManager->persist($newsCategory);
            $entityManager->persist($video);
            $entityManager->flush();

            $client->request('GET', '/admin/videos');
            self::assertResponseRedirects('/admin/login');

            $this->persistUser($client, $adminEmail, 'BulkMoveAdmin!2026', ['ROLE_ADMIN']);
            $this->submitLogin($client, $adminEmail, 'BulkMoveAdmin!2026');
            self::assertResponseRedirects('/admin');
            $client->request('GET', '/admin/videos');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form.admin-bulk-form option[value="'.$videoCategory->getId().'"]');
            $csrf = (string) $client->getCrawler()->filter('form.admin-bulk-form input[name="_token"]')->attr('value');
            $payload = [
                'action' => 'move',
                'ids' => [$video->getId()],
                'category_id' => $videoCategory->getId(),
            ];

            $client->request('POST', '/admin/videos/bulk', ['_token' => 'invalid'] + $payload);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $client->request('POST', '/admin/videos/bulk', ['_token' => $csrf, 'category_id' => $newsCategory->getId()] + $payload);
            self::assertResponseRedirects('/admin/videos');

            $connection = $entityManager->getConnection();
            self::assertNull($connection->fetchOne('SELECT category FROM videos WHERE id = :id', ['id' => $video->getId()]));

            $client->request('POST', '/admin/videos/bulk', ['_token' => $csrf] + $payload);
            self::assertResponseRedirects('/admin/videos');
            self::assertSame(
                $videoCategory->getName(),
                $connection->fetchOne('SELECT category FROM videos WHERE id = :id', ['id' => $video->getId()]),
            );

            $client->request('POST', '/admin/videos/bulk', ['_token' => $csrf, 'category_id' => ''] + $payload);
            self::assertResponseRedirects('/admin/videos');
            self::assertNull($connection->fetchOne('SELECT category FROM videos WHERE id = :id', ['id' => $video->getId()]));
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                $cleanupManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
                $cleanupManager->getConnection()->executeStatement(
                    'DELETE FROM content_categories WHERE id IN (:video_id, :news_id)',
                    ['video_id' => $videoCategory->getId(), 'news_id' => $newsCategory->getId()],
                );
            }
            $this->removeUser($client, $adminEmail);
        }
    }

    public function testVideoBulkSourceCheckUsesFixedProvidersAndStoresTheOutcome(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set('logger', new NullLogger());
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(6));
        $adminEmail = 'bulk-source-check-'.$suffix.'@example.test';
        $availableVideo = new Video(
            'Available source '.$suffix,
            'A provider source used by the bulk check regression.',
            null,
            VideoSourceType::Embed,
            'https://youtu.be/dQw4w9WgXcQ',
            PublishingStatus::Draft,
            false,
            true,
        );
        $failedVideo = new Video(
            'Failed source '.$suffix,
            'A provider error must be stored without leaking provider details.',
            null,
            VideoSourceType::Embed,
            'https://youtu.be/M7lc1UVf-VE',
            PublishingStatus::Draft,
            false,
            true,
        );
        $unsupportedEmbed = new Video(
            'Unsupported embed '.$suffix,
            'Unrecognized embed hosts must not cause outbound requests.',
            null,
            VideoSourceType::Embed,
            'https://untrusted.example/embed/video',
            PublishingStatus::Draft,
            false,
            true,
        );
        $directUrl = new Video(
            'Direct URL '.$suffix,
            'Direct media URLs must not be fetched by source checks.',
            null,
            VideoSourceType::Url,
            'https://untrusted.example/video.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );
        $databaseFailureVideo = new Video(
            'Database failure '.$suffix,
            'A database write error must roll back the whole source-check batch.',
            null,
            VideoSourceType::Embed,
            'https://youtu.be/9bZkp7q19f0',
            PublishingStatus::Draft,
            false,
            true,
        );
        $connection = $entityManager->getConnection();
        $failureConstraintInstalled = false;

        try {
            foreach ([$availableVideo, $failedVideo, $unsupportedEmbed, $directUrl, $databaseFailureVideo] as $video) {
                $entityManager->persist($video);
            }
            $entityManager->flush();

            $providerRequestCount = 0;
            $providerImport = new VideoProviderImportService(
                new MockHttpClient(static function (string $method, string $url, array $options) use (&$providerRequestCount): MockResponse {
                    ++$providerRequestCount;
                    self::assertSame('GET', $method);
                    self::assertSame(0, $options['max_redirects'] ?? null);
                    $parts = parse_url($url);
                    self::assertIsArray($parts);
                    self::assertSame('www.googleapis.com', $parts['host'] ?? null);
                    self::assertSame('/youtube/v3/videos', $parts['path'] ?? null);
                    $query = $options['query'] ?? null;
                    self::assertIsArray($query);
                    $videoId = $query['id'] ?? null;
                    self::assertIsString($videoId);

                    if ('dQw4w9WgXcQ' === $videoId) {
                        return new MockResponse(json_encode([
                            'items' => [[
                                'id' => $videoId,
                                'status' => ['privacyStatus' => 'public', 'embeddable' => true],
                            ]],
                        ], JSON_THROW_ON_ERROR), ['http_code' => Response::HTTP_OK]);
                    }

                    return new MockResponse('{"error":"provider unavailable"}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]);
                }),
                new ExternalVideoEmbedResolver(),
                'test-provider-api-key',
            );
            $client->getContainer()->set(VideoProviderImportService::class, $providerImport);

            $this->persistUser($client, $adminEmail, 'BulkSourceCheckAdmin!2026', ['ROLE_ADMIN']);
            $this->submitLogin($client, $adminEmail, 'BulkSourceCheckAdmin!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/videos');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form.admin-bulk-form option[value="check_source_status"]');
            self::assertSelectorTextContains('thead', 'Externer Quellenstatus');
            $csrf = (string) $client->getCrawler()->filter('form.admin-bulk-form input[name="_token"]')->attr('value');

            $validSelection = [
                'action' => 'check_source_status',
                'ids' => [$availableVideo->getId(), $failedVideo->getId(), $unsupportedEmbed->getId(), $directUrl->getId()],
            ];
            $client->request('POST', '/admin/videos/bulk', ['_token' => 'invalid'] + $validSelection);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertSame(0, $providerRequestCount);

            $tooManyIds = array_map(static fn (int $index): string => str_pad(dechex($index + 1), 32, 'a'), range(0, 5));
            $client->request('POST', '/admin/videos/bulk', [
                '_token' => $csrf,
                'action' => 'check_source_status',
                'ids' => $tooManyIds,
            ]);
            self::assertResponseRedirects('/admin/videos');
            self::assertSame(0, $providerRequestCount);

            $client->request('POST', '/admin/videos/bulk', ['_token' => $csrf] + $validSelection);
            self::assertResponseRedirects('/admin/videos');
            self::assertSame(2, $providerRequestCount);

            self::assertSame('available', $connection->fetchOne('SELECT external_source_status FROM videos WHERE id = :id', ['id' => $availableVideo->getId()]));
            self::assertNull($connection->fetchOne('SELECT external_source_status FROM videos WHERE id = :id', ['id' => $failedVideo->getId()]));
            self::assertTrue(in_array($connection->fetchOne('SELECT external_source_check_failed FROM videos WHERE id = :id', ['id' => $failedVideo->getId()]), [true, 1, '1', 't'], true));
            self::assertSame('unsupported', $connection->fetchOne('SELECT external_source_status FROM videos WHERE id = :id', ['id' => $unsupportedEmbed->getId()]));
            self::assertNull($connection->fetchOne('SELECT external_source_status FROM videos WHERE id = :id', ['id' => $directUrl->getId()]));
            foreach ([$availableVideo, $failedVideo, $unsupportedEmbed, $directUrl] as $video) {
                self::assertSame(PublishingStatus::Draft->value, $connection->fetchOne('SELECT status FROM videos WHERE id = :id', ['id' => $video->getId()]));
            }

            $client->request('GET', '/admin/videos');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('tbody', 'Quelle verfügbar');
            self::assertSelectorTextContains('tbody', 'Prüfung fehlgeschlagen');
            self::assertStringNotContainsString('provider unavailable', (string) $client->getResponse()->getContent());

            $availableTimestampBeforeFailedTransaction = $connection->fetchOne(
                'SELECT external_source_checked_at FROM videos WHERE id = :id',
                ['id' => $availableVideo->getId()],
            );
            self::assertIsString($availableTimestampBeforeFailedTransaction);
            $connection->executeStatement(sprintf(
                "ALTER TABLE videos ADD CONSTRAINT chk_test_video_source_check_rollback CHECK (id <> '%s' OR external_source_check_failed = FALSE)",
                $databaseFailureVideo->getId(),
            ));
            $failureConstraintInstalled = true;

            $client->request('POST', '/admin/videos/bulk', [
                '_token' => $csrf,
                'action' => 'check_source_status',
                'ids' => [$availableVideo->getId(), $databaseFailureVideo->getId()],
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);
            self::assertSame(4, $providerRequestCount);
            $connection->executeStatement('ALTER TABLE videos DROP CONSTRAINT chk_test_video_source_check_rollback');
            $failureConstraintInstalled = false;

            self::assertSame($availableTimestampBeforeFailedTransaction, $connection->fetchOne(
                'SELECT external_source_checked_at FROM videos WHERE id = :id',
                ['id' => $availableVideo->getId()],
            ));
            self::assertNull($connection->fetchOne(
                'SELECT external_source_checked_at FROM videos WHERE id = :id',
                ['id' => $databaseFailureVideo->getId()],
            ));
            self::assertFalse(in_array($connection->fetchOne(
                'SELECT external_source_check_failed FROM videos WHERE id = :id',
                ['id' => $databaseFailureVideo->getId()],
            ), [true, 1, '1', 't'], true));
        } finally {
            if ($failureConstraintInstalled) {
                $connection->executeStatement('ALTER TABLE videos DROP CONSTRAINT IF EXISTS chk_test_video_source_check_rollback');
            }
            foreach ([$availableVideo, $failedVideo, $unsupportedEmbed, $directUrl, $databaseFailureVideo] as $video) {
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            }
            $registry = $client->getContainer()->get(ManagerRegistry::class);
            self::assertInstanceOf(ManagerRegistry::class, $registry);
            $registry->resetManager();
            $this->removeUser($client, $adminEmail);
        }
    }

    public function testVideoBulkStateAndDeletionAreValidatedAtomically(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(6));
        $adminEmail = 'bulk-state-'.$suffix.'@example.test';
        $video = new Video(
            'Bulk state '.$suffix,
            'Test video',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );

        try {
            $entityManager->persist($video);
            $entityManager->flush();
            $connection = $entityManager->getConnection();
            $this->persistUser($client, $adminEmail, 'BulkStateAdmin!2026', ['ROLE_ADMIN']);
            $this->submitLogin($client, $adminEmail, 'BulkStateAdmin!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/videos');
            self::assertResponseIsSuccessful();
            $csrf = (string) $client->getCrawler()->filter('form.admin-bulk-form input[name="_token"]')->attr('value');
            self::assertNotEmpty($csrf);

            $client->request('POST', '/admin/videos/bulk', ['_token' => $csrf, 'action' => 'feature', 'ids' => [$video->getId()]]);
            self::assertResponseRedirects('/admin/videos');
            $featured = $connection->fetchOne('SELECT CASE WHEN featured THEN 1 ELSE 0 END FROM videos WHERE id = :id', ['id' => $video->getId()]);
            self::assertTrue(1 === $featured || '1' === $featured);

            $client->request('POST', '/admin/videos/bulk', ['_token' => $csrf, 'action' => 'publish', 'ids' => [$video->getId()]]);
            self::assertResponseRedirects('/admin/videos');
            self::assertSame(PublishingStatus::Published->value, $connection->fetchOne('SELECT status FROM videos WHERE id = :id', ['id' => $video->getId()]));

            $client->request('POST', '/admin/videos/bulk', ['_token' => $csrf, 'action' => 'draft', 'ids' => [$video->getId(), str_repeat('a', 32)]]);
            self::assertResponseRedirects('/admin/videos');
            self::assertSame(PublishingStatus::Published->value, $connection->fetchOne('SELECT status FROM videos WHERE id = :id', ['id' => $video->getId()]));

            $client->request('POST', '/admin/videos/bulk', ['_token' => $csrf, 'action' => 'draft', 'ids' => [$video->getId()]]);
            self::assertResponseRedirects('/admin/videos');
            self::assertSame(PublishingStatus::Draft->value, $connection->fetchOne('SELECT status FROM videos WHERE id = :id', ['id' => $video->getId()]));

            $client->request('POST', '/admin/videos/bulk', ['_token' => $csrf, 'action' => 'unfeature', 'ids' => [$video->getId()]]);
            self::assertResponseRedirects('/admin/videos');
            $featured = $connection->fetchOne('SELECT CASE WHEN featured THEN 1 ELSE 0 END FROM videos WHERE id = :id', ['id' => $video->getId()]);
            self::assertTrue(0 === $featured || '0' === $featured);

            $client->request('POST', '/admin/videos/bulk', ['_token' => $csrf, 'action' => 'delete', 'ids' => [$video->getId()]]);
            self::assertResponseRedirects('/admin/videos');
            self::assertNotFalse($entityManager->getConnection()->fetchOne('SELECT id FROM videos WHERE id = :id', ['id' => $video->getId()]));

            $client->request('POST', '/admin/videos/bulk', ['_token' => $csrf, 'action' => 'delete', 'ids' => [$video->getId()], 'confirm_delete' => '1']);
            self::assertResponseRedirects('/admin/videos');
            self::assertFalse($entityManager->getConnection()->fetchOne('SELECT id FROM videos WHERE id = :id', ['id' => $video->getId()]));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $this->removeUser($client, $adminEmail);
        }
    }

    public function testSeriesGenresCanBeFilteredRenamedAndDeletedAcrossSeries(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(6));
        $genre = 'Genre '.$suffix;
        $renamed = 'Renamed '.$suffix;
        $genreEntity = new SeriesGenre($genre);
        $published = new ContentSeries('Genre public '.$suffix, 'genre-public-'.$suffix, '', PublishingStatus::Published, [$genreEntity]);
        $draft = new ContentSeries('Genre draft '.$suffix, 'genre-draft-'.$suffix, '', PublishingStatus::Draft, [$genreEntity]);
        $adminEmail = 'genre-admin-'.$suffix.'@example.test';

        try {
            $entityManager->persist($genreEntity);
            $entityManager->persist($published);
            $entityManager->persist($draft);
            $entityManager->flush();
            $connection = $entityManager->getConnection();

            $client->request('GET', '/series?genre='.rawurlencode($genre));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', $published->getTitle());
            self::assertStringNotContainsString($draft->getTitle(), (string) $client->getResponse()->getContent());
            $client->request('GET', '/series?genre[]=invalid');
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

            $client->request('GET', '/admin/series/genres');
            self::assertResponseRedirects('/admin/login');
            $client->request('POST', '/admin/series/genres/create', ['name' => 'Unauthorized '.$suffix, '_token' => 'invalid']);
            self::assertResponseRedirects('/admin/login');
            $this->persistUser($client, $adminEmail, 'GenreAdmin!2026', ['ROLE_ADMIN']);
            $this->submitLogin($client, $adminEmail, 'GenreAdmin!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/series/genres');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Seriengenres');
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            $createToken = (string) $client->getCrawler()->filter('form[action="/admin/series/genres/create"] input[name="_token"]')->attr('value');
            self::assertNotEmpty($createToken);
            $standalone = 'Standalone '.$suffix;
            $client->request('POST', '/admin/series/genres/create', ['name' => $standalone, '_token' => 'invalid']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $client->request('POST', '/admin/series/genres/create', ['name' => $standalone, '_token' => $createToken]);
            self::assertResponseRedirects('/admin/series/genres');
            self::assertContains($connection->fetchOne('SELECT COUNT(*) FROM series_genres WHERE name = :name', ['name' => $standalone]), [1, '1']);
            $client->request('POST', '/admin/series/genres/create', ['name' => mb_strtolower($standalone), '_token' => $createToken]);
            self::assertResponseRedirects('/admin/series/genres');
            self::assertContains($connection->fetchOne('SELECT COUNT(*) FROM series_genres WHERE normalized_name = :name', ['name' => mb_strtolower($standalone)]), [1, '1']);
            $client->request('GET', '/admin/series/genres');
            self::assertResponseIsSuccessful();
            $standaloneRow = $client->getCrawler()->filterXPath('//tr[th[text()="'.$standalone.'"]]');
            self::assertCount(1, $standaloneRow);
            self::assertSelectorTextContains('main', $standalone);
            $standaloneDeleteToken = (string) $standaloneRow->filter('form[action="/admin/series/genres/delete"] input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/series/genres/delete', ['genre' => $standalone, '_token' => $standaloneDeleteToken, 'confirm_delete' => '1']);
            self::assertResponseRedirects('/admin/series/genres');
            self::assertContains($connection->fetchOne('SELECT COUNT(*) FROM series_genres WHERE name = :name', ['name' => $standalone]), [0, '0']);

            $client->request('GET', '/admin/series/genres');
            self::assertResponseIsSuccessful();
            $row = $client->getCrawler()->filterXPath('//tr[th[text()="'.$genre.'"]]');
            self::assertCount(1, $row);
            $renameToken = (string) $row->filter('form[action="/admin/series/genres/rename"] input[name="_token"]')->attr('value');
            self::assertNotEmpty($renameToken);

            $client->request('POST', '/admin/series/genres/rename', ['genre' => $genre, 'new_name' => $renamed, '_token' => 'invalid']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $client->request('POST', '/admin/series/genres/rename', ['genre' => $genre, 'new_name' => $renamed, '_token' => $renameToken]);
            self::assertResponseRedirects('/admin/series/genres');
            self::assertSame($renamed, $connection->fetchOne('SELECT name FROM series_genres WHERE id = :id', ['id' => $genreEntity->getId()]));
            self::assertContains($connection->fetchOne('SELECT COUNT(*) FROM content_series_genres WHERE genre_id = :id', ['id' => $genreEntity->getId()]), [2, '2']);

            $client->request('GET', '/series?genre='.rawurlencode($renamed));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', $published->getTitle());
            $client->request('GET', '/series?genre='.rawurlencode($genre));
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($published->getTitle(), (string) $client->getResponse()->getContent());

            $client->request('GET', '/admin/series/genres');
            self::assertResponseIsSuccessful();
            $row = $client->getCrawler()->filterXPath('//tr[th[text()="'.$renamed.'"]]');
            self::assertCount(1, $row);
            $deleteToken = (string) $row->filter('form[action="/admin/series/genres/delete"] input[name="_token"]')->attr('value');
            self::assertNotEmpty($deleteToken);
            $client->request('POST', '/admin/series/genres/delete', ['genre' => $renamed, '_token' => $deleteToken]);
            self::assertResponseRedirects('/admin/series/genres');
            self::assertContains($connection->fetchOne('SELECT COUNT(*) FROM content_series_genres WHERE genre_id = :id', ['id' => $genreEntity->getId()]), [2, '2']);
            $client->request('POST', '/admin/series/genres/delete', ['genre' => $renamed, '_token' => $deleteToken, 'confirm_delete' => '1']);
            self::assertResponseRedirects('/admin/series/genres');
            self::assertContains($connection->fetchOne('SELECT COUNT(*) FROM content_series_genres WHERE genre_id = :id', ['id' => $genreEntity->getId()]), [0, '0']);
            self::assertContains($connection->fetchOne('SELECT COUNT(*) FROM series_genres WHERE id = :id', ['id' => $genreEntity->getId()]), [0, '0']);
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM content_series WHERE id IN (:published, :draft)', [
                'published' => $published->getId(),
                'draft' => $draft->getId(),
            ]);
            $entityManager->getConnection()->executeStatement('DELETE FROM series_genres WHERE id = :id', ['id' => $genreEntity->getId()]);
            $this->removeUser($client, $adminEmail);
        }
    }

    public function testAdminCanFeatureAndOrderSeriesGenresWithCsrfAndStrictBounds(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(6));
        $genre = new SeriesGenre('Featured genre '.$suffix);
        $adminEmail = 'featured-genre-admin-'.$suffix.'@example.test';
        $adminPassword = 'FeaturedGenreAdmin!2026';
        $entityManager->persist($genre);
        $entityManager->flush();
        $connection = $entityManager->getConnection();

        try {
            $client->request('POST', '/admin/series/genres/feature', [
                'genre_id' => $genre->getId(),
                'featured' => '1',
                'sort_order' => '12',
                '_token' => 'invalid',
            ]);
            self::assertResponseRedirects('/admin/login');

            $this->persistUser($client, $adminEmail, $adminPassword, ['ROLE_ADMIN']);
            $this->submitLogin($client, $adminEmail, $adminPassword);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();

            $genreOffset = $connection->fetchOne(
                'SELECT COUNT(*) FROM series_genres WHERE name < :name OR (name = :name AND id < :id)',
                ['name' => $genre->getName(), 'id' => $genre->getId()],
            );
            self::assertIsInt($genreOffset);
            $genrePage = intdiv($genreOffset, 50) + 1;
            $client->request('GET', '/admin/series/genres?page='.$genrePage);
            self::assertResponseIsSuccessful();
            $row = $client->getCrawler()->filterXPath('//tr[th[text()="'.$genre->getName().'"]]');
            self::assertCount(1, $row);
            self::assertSame('Startseite', trim($client->getCrawler()->filter('table.admin-table thead th')->eq(2)->text()));
            $token = (string) $row->filter('form[action="/admin/series/genres/feature"] input[name="_token"]')->attr('value');
            self::assertNotEmpty($token);

            $client->request('POST', '/admin/series/genres/feature', [
                'genre_id' => $genre->getId(),
                'featured' => '1',
                'sort_order' => '12',
                '_token' => 'invalid',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $state = $connection->fetchAssociative(
                'SELECT CASE WHEN is_featured THEN 1 ELSE 0 END AS is_featured, featured_sort_order FROM series_genres WHERE id = :id',
                ['id' => $genre->getId()],
            );
            self::assertIsArray($state);
            self::assertTrue(in_array($state['is_featured'], [0, '0'], true));
            self::assertTrue(in_array($state['featured_sort_order'], [0, '0'], true));

            $client->request('POST', '/admin/series/genres/feature', [
                'genre_id' => $genre->getId(),
                'featured' => '1',
                'sort_order' => '10001',
                '_token' => $token,
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

            $client->request('POST', '/admin/series/genres/feature', [
                'genre_id' => $genre->getId(),
                'featured' => '1',
                'sort_order' => '12',
                '_token' => $token,
            ]);
            self::assertResponseRedirects('/admin/series/genres');
            $state = $connection->fetchAssociative(
                'SELECT CASE WHEN is_featured THEN 1 ELSE 0 END AS is_featured, featured_sort_order FROM series_genres WHERE id = :id',
                ['id' => $genre->getId()],
            );
            self::assertIsArray($state);
            self::assertTrue(in_array($state['is_featured'], [1, '1'], true));
            self::assertTrue(in_array($state['featured_sort_order'], [12, '12'], true));

            $client->request('POST', '/admin/series/genres/feature', [
                'genre_id' => $genre->getId(),
                'sort_order' => '12',
                '_token' => $token,
            ]);
            self::assertResponseRedirects('/admin/series/genres');
            $state = $connection->fetchAssociative(
                'SELECT CASE WHEN is_featured THEN 1 ELSE 0 END AS is_featured, featured_sort_order FROM series_genres WHERE id = :id',
                ['id' => $genre->getId()],
            );
            self::assertIsArray($state);
            self::assertTrue(in_array($state['is_featured'], [0, '0'], true));
            self::assertTrue(in_array($state['featured_sort_order'], [12, '12'], true));
        } finally {
            $connection->delete('series_genres', ['id' => $genre->getId()]);
            $this->removeUser($client, $adminEmail);
        }
    }

    public function testAdminCanFetchProviderMetadataThroughCsrfProtectedSameSiteEndpoint(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $suffix = bin2hex(random_bytes(8));
        $email = 'provider-metadata-'.$suffix.'@example.test';
        $password = 'ProviderMetadata-'.$suffix.'-Q4!';
        $providerImport = new VideoProviderImportService(
            new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('GET', $method);
                self::assertSame(0, $options['max_redirects'] ?? null);
                $parts = parse_url($url);
                self::assertIsArray($parts);
                self::assertSame('www.googleapis.com', $parts['host'] ?? null);
                self::assertSame('/youtube/v3/videos', $parts['path'] ?? null);

                return new MockResponse(json_encode([
                    'items' => [[
                        'id' => 'dQw4w9WgXcQ',
                        'snippet' => [
                            'title' => 'Provider metadata title',
                            'description' => 'Provider metadata description',
                            'thumbnails' => [
                                'medium' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg', 'width' => 320],
                            ],
                        ],
                        'contentDetails' => ['duration' => 'PT90S'],
                        'status' => ['privacyStatus' => 'public', 'embeddable' => true],
                    ]],
                ], JSON_THROW_ON_ERROR), ['http_code' => Response::HTTP_OK]);
            }),
            new ExternalVideoEmbedResolver(),
            'test-provider-api-key',
        );
        $client->getContainer()->set(VideoProviderImportService::class, $providerImport);

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/videos/new');
            self::assertResponseIsSuccessful();
            $metadataForm = $client->getCrawler()->filter('form[data-video-source-metadata]');
            $csrfToken = $metadataForm->attr('data-metadata-csrf-token');
            self::assertIsString($csrfToken);
            self::assertNotEmpty($csrfToken);

            $client->request('POST', '/admin/videos/metadata', [
                '_token' => 'invalid-csrf-token',
                'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('POST', '/admin/videos/metadata', [
                '_token' => $csrfToken,
                'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&feature=share',
            ]);
            self::assertResponseIsSuccessful();
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            self::assertSame([
                'provider' => 'youtube',
                'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'title' => 'Provider metadata title',
                'description' => 'Provider metadata description',
                'duration_seconds' => 90,
                'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg',
            ], json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR));
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testAdminCanImportVimeoVideosAsDraftsAndSkipDuplicates(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $token = bin2hex(random_bytes(8));
        $email = 'provider-import-'.$token.'@example.test';
        $password = 'ProviderImport-'.$token.'-A9!';
        $videoTitle = 'Vimeo Provider Video '.$token;
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        $vimeoResponse = json_encode([
            'data' => [[
                'uri' => '/videos/345678901',
                'name' => $videoTitle,
                'description' => 'Imported as a private draft.',
                'link' => 'https://vimeo.com/345678901?h=privacy789',
                'pictures' => ['sizes' => [
                    ['width' => 640, 'link' => 'https://i.vimeocdn.com/video/345678901_640x360.jpg'],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $providerResponses = [
            new MockResponse($vimeoResponse, ['http_code' => Response::HTTP_OK]),
            new MockResponse($vimeoResponse, ['http_code' => Response::HTTP_OK]),
            new MockResponse('{"error":"temporarily unavailable"}', ['http_code' => Response::HTTP_BAD_GATEWAY]),
        ];
        for ($responseIndex = 0; $responseIndex < 7; ++$responseIndex) {
            $providerResponses[] = new MockResponse('{"data":[]}', ['http_code' => Response::HTTP_OK]);
        }
        $providerImport = new VideoProviderImportService(
            new MockHttpClient($providerResponses),
            new ExternalVideoEmbedResolver(),
            'test-provider-api-key',
            'vimeo-test-access-token_123',
        );
        $client->getContainer()->set(VideoProviderImportService::class, $providerImport);
        $thumbnailPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        self::assertIsString($thumbnailPng);
        $storedThumbnailObjectId = null;
        $privateStorage = $this->createMock(PrivateObjectStorageInterface::class);
        $privateStorage->expects(self::once())->method('write')->willReturnCallback(
            static function (string $objectId, string $contents) use (&$storedThumbnailObjectId, $thumbnailPng): void {
                self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $objectId);
                self::assertSame($thumbnailPng, $contents);
                $storedThumbnailObjectId = $objectId;
            },
        );
        $privateStorage->expects(self::once())->method('read')->willReturnCallback(
            static function (string $objectId, int $maximumBytes) use (&$storedThumbnailObjectId, $thumbnailPng): string {
                self::assertSame($storedThumbnailObjectId, $objectId);
                self::assertSame(strlen($thumbnailPng), $maximumBytes);

                return $thumbnailPng;
            },
        );
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $privateStorage);
        $thumbnailImports = new VideoThumbnailImportService(
            new MockHttpClient([new MockResponse($thumbnailPng, ['http_code' => Response::HTTP_OK])]),
            new VideoThumbnailUploadService($privateStorage),
        );
        $client->getContainer()->set(VideoThumbnailImportService::class, $thumbnailImports);

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/videos/import/provider');
            self::assertResponseIsSuccessful();
            $this->assertReportOnlyCsp($client);
            self::assertSelectorTextContains('h1', 'Videoanbieter-Import');
            self::assertSelectorExists('select[name="provider"] option[value="vimeo"]');
            $csrfToken = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            self::assertIsString($csrfToken);

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => 'invalid-csrf-token',
                'provider' => 'vimeo',
                'mode' => 'search',
                'query' => 'nature',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => $csrfToken,
                'provider' => 'unknown-provider',
                'mode' => 'search',
                'query' => 'nature',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertStringContainsString('unterstützten Videoanbieter', (string) $client->getResponse()->getContent());

            $invalidSubmissions = [
                [
                    '_token' => ['invalid-csrf-token'],
                    'provider' => 'vimeo',
                    'mode' => 'search',
                    'query' => 'nature',
                ],
                [
                    '_token' => $csrfToken,
                    'provider' => 'vimeo',
                    'mode' => 'search',
                    'query' => 'nature',
                    'channel_id' => '',
                    'category' => '',
                    'unexpected' => 'ignored-field',
                ],
                [
                    '_token' => $csrfToken,
                    'provider' => 'vimeo',
                    'mode' => 'search',
                    'query' => 'nature',
                    'channel_id' => '',
                    'category' => ['unexpected-array-value'],
                ],
                [
                    '_token' => $csrfToken,
                    'provider' => 'vimeo',
                    'mode' => 'search',
                    'query' => ['unexpected-array-value'],
                    'channel_id' => '',
                    'category' => '',
                ],
            ];
            foreach ($invalidSubmissions as $index => $submission) {
                $client->request('POST', '/admin/videos/import/provider', $submission);
                self::assertResponseStatusCodeSame(0 === $index ? Response::HTTP_FORBIDDEN : Response::HTTP_UNPROCESSABLE_ENTITY);
                if (0 !== $index) {
                    self::assertStringContainsString('ungültige oder unbekannte Importfelder', (string) $client->getResponse()->getContent());
                }
            }
            self::assertFalse($connection->fetchOne('SELECT id FROM videos WHERE title = :title', ['title' => $videoTitle]));

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => $csrfToken,
                'provider' => 'vimeo',
                'mode' => 'search',
                'query' => 'nature',
                'channel_id' => '',
                'category' => '',
            ]);
            self::assertResponseRedirects('/admin/videos');

            $video = $connection->fetchAssociative(
                'SELECT status, source_type, comments_enabled, source_url FROM videos WHERE title = :title',
                ['title' => $videoTitle],
            );
            self::assertIsArray($video);
            self::assertSame('draft', $video['status']);
            self::assertSame('embed', $video['source_type']);
            self::assertFalse((bool) $video['comments_enabled']);
            self::assertSame('https://vimeo.com/345678901?h=privacy789', $video['source_url']);
            $thumbnailRecord = $connection->fetchAssociative(
                'SELECT thumbnail_object_id, thumbnail_mime_type, thumbnail_size FROM videos WHERE source_url = :source_url',
                ['source_url' => 'https://vimeo.com/345678901?h=privacy789'],
            );
            self::assertIsArray($thumbnailRecord);
            self::assertSame($storedThumbnailObjectId, $thumbnailRecord['thumbnail_object_id']);
            self::assertSame('image/png', $thumbnailRecord['thumbnail_mime_type']);
            self::assertIsNumeric($thumbnailRecord['thumbnail_size']);
            self::assertSame(strlen($thumbnailPng), (int) $thumbnailRecord['thumbnail_size']);
            $videoId = $connection->fetchOne(
                'SELECT id FROM videos WHERE source_url = :source_url',
                ['source_url' => 'https://vimeo.com/345678901?h=privacy789'],
            );
            self::assertIsString($videoId);
            $client->request('GET', '/admin/videos/'.$videoId.'/thumbnail');
            self::assertResponseIsSuccessful();
            self::assertSame($thumbnailPng, $client->getResponse()->getContent());
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => $csrfToken,
                'provider' => 'vimeo',
                'mode' => 'search',
                'query' => 'nature',
                'channel_id' => '',
                'category' => '',
            ]);
            self::assertResponseRedirects('/admin/videos');
            self::assertEquals(1, $connection->fetchOne(
                'SELECT COUNT(*) FROM videos WHERE source_url = :source_url',
                ['source_url' => 'https://vimeo.com/345678901?h=privacy789'],
            ));

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => $csrfToken,
                'provider' => 'vimeo',
                'mode' => 'search',
                'query' => 'nature',
                'channel_id' => '',
                'category' => '',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
            self::assertStringContainsString('Vimeo konnte die Anfrage nicht sicher beantworten', $client->getResponse()->getContent());
            self::assertEquals(1, $connection->fetchOne(
                'SELECT COUNT(*) FROM videos WHERE source_url = :source_url',
                ['source_url' => 'https://vimeo.com/345678901?h=privacy789'],
            ));

            for ($requestIndex = 0; $requestIndex < 7; ++$requestIndex) {
                $client->request('POST', '/admin/videos/import/provider', [
                    '_token' => $csrfToken,
                    'provider' => 'vimeo',
                    'mode' => 'search',
                    'query' => 'no-match-'.$requestIndex,
                    'channel_id' => '',
                    'category' => '',
                ]);
                self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => $csrfToken,
                'provider' => 'vimeo',
                'mode' => 'search',
                'query' => 'one-too-many',
                'channel_id' => '',
                'category' => '',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
            self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
        } finally {
            $connection->executeStatement('DELETE FROM videos WHERE title = :title', ['title' => $videoTitle]);
            $this->removeUser($client, $email);
        }
    }

    public function testProviderThumbnailStorageFailureCleansEarlierFilesBeforeImport(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set('logger', new NullLogger());
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $suffix = bin2hex(random_bytes(8));
        $email = 'provider-thumbnail-failure-'.$suffix.'@example.test';
        $password = 'ThumbnailFailure-'.$suffix.'-A9!';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $titlePrefix = 'Vimeo Thumbnail Failure '.$suffix;
        $providerResponse = json_encode([
            'data' => [
                [
                    'uri' => '/videos/345678901',
                    'name' => $titlePrefix.' first',
                    'description' => '',
                    'link' => 'https://vimeo.com/345678901',
                    'pictures' => ['sizes' => [
                        ['width' => 640, 'link' => 'https://i.vimeocdn.com/video/345678901_640x360.jpg'],
                    ]],
                ],
                [
                    'uri' => '/videos/346678901',
                    'name' => $titlePrefix.' second',
                    'description' => '',
                    'link' => 'https://vimeo.com/346678901',
                    'pictures' => ['sizes' => [
                        ['width' => 640, 'link' => 'https://i.vimeocdn.com/video/346678901_640x360.jpg'],
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $providerImport = new VideoProviderImportService(
            new MockHttpClient([new MockResponse($providerResponse, ['http_code' => Response::HTTP_OK])]),
            new ExternalVideoEmbedResolver(),
            'test-provider-api-key',
            'vimeo-test-access-token_123',
        );
        $client->getContainer()->set(VideoProviderImportService::class, $providerImport);

        $thumbnailPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        self::assertIsString($thumbnailPng);
        $writeCount = 0;
        $storedObjectIds = [];
        $privateStorage = $this->createMock(PrivateObjectStorageInterface::class);
        $privateStorage->expects(self::exactly(2))->method('write')->willReturnCallback(
            static function (string $objectId, string $contents) use (&$writeCount, &$storedObjectIds, $thumbnailPng): void {
                ++$writeCount;
                if (2 === $writeCount) {
                    throw new \RuntimeException('Private storage is unavailable.');
                }
                self::assertSame($thumbnailPng, $contents);
                $storedObjectIds[] = $objectId;
            },
        );
        $privateStorage->expects(self::exactly(2))->method('delete')->willReturnCallback(
            static function (string $objectId): void {
                self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $objectId);
            },
        );
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $privateStorage);
        $client->getContainer()->set(VideoThumbnailImportService::class, new VideoThumbnailImportService(
            new MockHttpClient([
                new MockResponse($thumbnailPng, ['http_code' => Response::HTTP_OK]),
                new MockResponse($thumbnailPng, ['http_code' => Response::HTTP_OK]),
            ]),
            new VideoThumbnailUploadService($privateStorage),
        ));

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/videos/import/provider');
            self::assertResponseIsSuccessful();
            $csrfToken = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            self::assertIsString($csrfToken);
            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => $csrfToken,
                'provider' => 'vimeo',
                'mode' => 'search',
                'query' => 'nature',
                'channel_id' => '',
                'category' => '',
            ]);

            self::assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);
            self::assertCount(1, $storedObjectIds);
            $importedCount = $connection->fetchOne(
                'SELECT COUNT(*) FROM videos WHERE title LIKE :title',
                ['title' => $titlePrefix.'%'],
            );
            self::assertIsNumeric($importedCount);
            self::assertSame(0, (int) $importedCount);
        } finally {
            $connection->executeStatement('DELETE FROM videos WHERE title LIKE :title', ['title' => $titlePrefix.'%']);
            $this->removeUser($client, $email);
        }
    }

    public function testAdminCanImportDailymotionSearchResultsAsDrafts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $suffix = bin2hex(random_bytes(8));
        $email = 'dailymotion-import-'.$suffix.'@example.test';
        $password = 'DailymotionImport-'.$suffix.'-A9!';
        $title = 'Dailymotion Draft '.$suffix;
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        $providerResponse = json_encode([
            'list' => [[
                'id' => 'x84sh87',
                'title' => $title,
                'description' => 'Imported from the fixed Dailymotion search endpoint.',
                'url' => 'https://www.dailymotion.com/video/x84sh87',
                'allow_embed' => true,
            ]],
        ], JSON_THROW_ON_ERROR);
        $providerImport = new VideoProviderImportService(
            new MockHttpClient([new MockResponse($providerResponse, ['http_code' => Response::HTTP_OK])]),
            new ExternalVideoEmbedResolver('x8lr5'),
            '',
        );
        $client->getContainer()->set(VideoProviderImportService::class, $providerImport);

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/videos/import/provider');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('select[name="provider"] option[value="dailymotion"]');
            $csrfToken = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            self::assertIsString($csrfToken);

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => $csrfToken,
                'provider' => 'dailymotion',
                'mode' => 'search',
                'query' => 'nature',
                'channel_id' => '',
                'category' => '',
            ]);
            self::assertResponseRedirects('/admin/videos');

            $video = $connection->fetchAssociative(
                'SELECT status, source_type, comments_enabled, source_url FROM videos WHERE title = :title',
                ['title' => $title],
            );
            self::assertIsArray($video);
            self::assertSame('draft', $video['status']);
            self::assertSame('embed', $video['source_type']);
            self::assertFalse((bool) $video['comments_enabled']);
            self::assertSame('https://www.dailymotion.com/video/x84sh87', $video['source_url']);
        } finally {
            $connection->executeStatement('DELETE FROM videos WHERE title = :title', ['title' => $title]);
            $connection->executeStatement('DELETE FROM admin_users WHERE email = :email', ['email' => $email]);
        }
    }

    public function testAdminCanImportDailymotionPlaylistResultsAsDrafts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $suffix = bin2hex(random_bytes(8));
        $email = 'dailymotion-playlist-'.$suffix.'@example.test';
        $password = 'DailymotionPlaylist-'.$suffix.'-A9!';
        $title = 'Dailymotion Playlist Draft '.$suffix;
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        $providerImport = new VideoProviderImportService(
            new MockHttpClient([
                new MockResponse(json_encode([
                    'access_token' => 'dailymotion-playlist-admin-token',
                    'token_type' => 'Bearer',
                    'expires_in' => 1200,
                    'scope' => 'playlist.read',
                ], JSON_THROW_ON_ERROR), ['http_code' => Response::HTTP_OK]),
                new MockResponse(json_encode([
                    'data' => [[
                        'video_id' => 'x84sh87',
                        'video_url' => 'https://www.dailymotion.com/video/x84sh87',
                        'title' => $title,
                        'description' => 'Imported from a public Dailymotion playlist.',
                        'visibility' => 'public',
                        'embedding' => ['enable_embed' => true],
                    ]],
                ], JSON_THROW_ON_ERROR), ['http_code' => Response::HTTP_OK]),
            ]),
            new ExternalVideoEmbedResolver('x8lr5'),
            '',
            '',
            'dailymotion-test-client-id',
            'dailymotion-test-client-secret',
        );
        $client->getContainer()->set(VideoProviderImportService::class, $providerImport);

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/videos/import/provider');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('select[name="mode"] option[value="playlist"]');
            self::assertSelectorExists('input[name="playlist_id"]');
            $csrfToken = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            self::assertIsString($csrfToken);

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => $csrfToken,
                'provider' => 'dailymotion',
                'mode' => 'playlist',
                'playlist_id' => 'https://attacker.example/list',
                'query' => '',
                'channel_id' => '',
                'category' => '',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => $csrfToken,
                'provider' => 'dailymotion',
                'mode' => 'playlist',
                'playlist_id' => 'playlist-123',
                'query' => '',
                'channel_id' => '',
                'category' => '',
            ]);
            self::assertResponseRedirects('/admin/videos');

            $video = $connection->fetchAssociative(
                'SELECT status, source_type, comments_enabled, source_url FROM videos WHERE title = :title',
                ['title' => $title],
            );
            self::assertIsArray($video);
            self::assertSame('draft', $video['status']);
            self::assertSame('embed', $video['source_type']);
            self::assertFalse((bool) $video['comments_enabled']);
            self::assertSame('https://www.dailymotion.com/video/x84sh87', $video['source_url']);
        } finally {
            $connection->executeStatement('DELETE FROM videos WHERE title = :title', ['title' => $title]);
            $connection->executeStatement('DELETE FROM admin_users WHERE email = :email', ['email' => $email]);
        }
    }

    public function testAdminCanImportDailymotionFavoritesAsDrafts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $suffix = bin2hex(random_bytes(8));
        $email = 'dailymotion-favorites-'.$suffix.'@example.test';
        $password = 'DailymotionFavorites-'.$suffix.'-A9!';
        $title = 'Dailymotion Favorites Draft '.$suffix;
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        $providerImport = new VideoProviderImportService(
            new MockHttpClient([new MockResponse(json_encode([
                'list' => [[
                    'id' => 'x84sh87',
                    'title' => $title,
                    'description' => 'Imported from a public Dailymotion favorites list.',
                    'url' => 'https://www.dailymotion.com/video/x84sh87',
                    'allow_embed' => true,
                ]],
            ], JSON_THROW_ON_ERROR), ['http_code' => Response::HTTP_OK])]),
            new ExternalVideoEmbedResolver('x8lr5'),
            '',
        );
        $client->getContainer()->set(VideoProviderImportService::class, $providerImport);

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/videos/import/provider');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('select[name="mode"] option[value="favorites"]');
            self::assertSelectorExists('input[name="query"]');
            $csrfToken = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            self::assertIsString($csrfToken);

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => $csrfToken,
                'provider' => 'dailymotion',
                'mode' => 'favorites',
                'channel_id' => 'x1favorites',
                'category' => '',
            ]);
            self::assertResponseRedirects('/admin/videos');

            $video = $connection->fetchAssociative(
                'SELECT status, source_type, comments_enabled, source_url FROM videos WHERE title = :title',
                ['title' => $title],
            );
            self::assertIsArray($video);
            self::assertSame('draft', $video['status']);
            self::assertSame('embed', $video['source_type']);
            self::assertFalse((bool) $video['comments_enabled']);
            self::assertSame('https://www.dailymotion.com/video/x84sh87', $video['source_url']);
        } finally {
            $connection->executeStatement('DELETE FROM videos WHERE title = :title', ['title' => $title]);
            $connection->executeStatement('DELETE FROM admin_users WHERE email = :email', ['email' => $email]);
        }
    }

    public function testAdminCanImportVimeoChannelVideosAsDrafts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $token = bin2hex(random_bytes(8));
        $email = 'provider-channel-'.$token.'@example.test';
        $password = 'ProviderChannel-'.$token.'-A9!';
        $channelId = '123456789';
        $videoTitle = 'Vimeo Channel Video '.$token;
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        $providerImport = new VideoProviderImportService(
            new MockHttpClient(static function (string $method, string $url, array $options) use ($channelId, $videoTitle): MockResponse {
                self::assertSame('GET', $method);
                $urlParts = parse_url($url);
                self::assertIsArray($urlParts);
                self::assertSame('api.vimeo.com', $urlParts['host'] ?? null);
                self::assertSame('/users/'.$channelId.'/videos', $urlParts['path'] ?? null);
                $query = $options['query'] ?? null;
                self::assertIsArray($query);
                self::assertSame(25, $query['per_page'] ?? null);
                self::assertSame('embeddable', $query['filter'] ?? null);
                self::assertTrue($query['filter_embeddable'] ?? false);
                self::assertArrayNotHasKey('query', $query);

                return new MockResponse(json_encode([
                    'data' => [[
                        'uri' => '/videos/456789123',
                        'name' => $videoTitle,
                        'description' => 'Imported from the selected Vimeo channel.',
                        'link' => 'https://vimeo.com/456789123',
                    ]],
                ], JSON_THROW_ON_ERROR), ['http_code' => Response::HTTP_OK]);
            }),
            new ExternalVideoEmbedResolver(),
            'test-provider-api-key',
            'vimeo-test-access-token_123',
        );
        $client->getContainer()->set(VideoProviderImportService::class, $providerImport);

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/videos/import/provider');
            self::assertResponseIsSuccessful();
            $csrfToken = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            self::assertIsString($csrfToken);

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => $csrfToken,
                'provider' => 'vimeo',
                'mode' => 'channel',
                'query' => '',
                'channel_id' => $channelId,
                'category' => '',
            ]);
            self::assertResponseRedirects('/admin/videos');

            $video = $connection->fetchAssociative(
                'SELECT status, source_type, source_url FROM videos WHERE title = :title',
                ['title' => $videoTitle],
            );
            self::assertIsArray($video);
            self::assertSame('draft', $video['status']);
            self::assertSame('embed', $video['source_type']);
            self::assertSame('https://vimeo.com/456789123', $video['source_url']);
        } finally {
            $connection->executeStatement('DELETE FROM videos WHERE title = :title', ['title' => $videoTitle]);
            $this->removeUser($client, $email);
        }
    }

    public function testUnavailableProviderRemainsSelectableAndFailsClosedWithoutAnApiRequest(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $email = 'provider-unconfigured-'.bin2hex(random_bytes(6)).'@example.test';
        $password = 'ProviderUnconfigured-'.bin2hex(random_bytes(6)).'-A9!';
        $client->getContainer()->set(
            VideoProviderImportService::class,
            new VideoProviderImportService(
                new MockHttpClient(static function (): MockResponse {
                    throw new \RuntimeException('No request is allowed without Vimeo credentials.');
                }),
                new ExternalVideoEmbedResolver(),
                'test-provider-api-key',
            ),
        );

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/videos/import/provider');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('select[name="provider"] option[value="vimeo"]', 'nicht eingerichtet');
            self::assertSelectorNotExists('button[type="submit"][disabled]');
            $csrfToken = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            self::assertIsString($csrfToken);

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => $csrfToken,
                'provider' => 'vimeo',
                'mode' => 'search',
                'query' => 'nature',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
            self::assertStringContainsString('VIMEO_ACCESS_TOKEN', (string) $client->getResponse()->getContent());
        } finally {
            $this->removeUser($client, $email);
        }
    }

    public function testFirstNewsAndVideoIterationPersistsContentAndRejectsUnsafeSources(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(VideoStreamSourceResolver::class, new VideoStreamSourceResolver(['media.example.org']));
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $mediaStorage = $client->getContainer()->get(PrivateObjectStorageInterface::class);
        self::assertInstanceOf(PrivateObjectStorageInterface::class, $mediaStorage);

        $token = bin2hex(random_bytes(8));
        $thumbnailPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        self::assertIsString($thumbnailPng);
        $thumbnailObjectIds = [];
        $deletedThumbnailObjectIds = [];
        $thumbnailRequestCount = 0;
        $thumbnailStorage = $this->createMock(PrivateObjectStorageInterface::class);
        $thumbnailStorage->expects($this->exactly(2))
            ->method('write')
            ->willReturnCallback(static function (string $objectId, string $contents) use ($thumbnailPng, &$thumbnailObjectIds): void {
                self::assertMatchesRegularExpression('/\\A[a-f0-9]{64}\\z/', $objectId);
                self::assertSame($thumbnailPng, $contents);
                $thumbnailObjectIds[] = $objectId;
            });
        $thumbnailStorage->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(static function (string $objectId) use (&$thumbnailObjectIds, &$deletedThumbnailObjectIds): void {
                $expectedObjectIds = [
                    VideoSocialPreviewGenerator::cacheObjectIdForSourceObjectId($thumbnailObjectIds[1] ?? ''),
                    $thumbnailObjectIds[1] ?? '',
                ];
                self::assertSame($expectedObjectIds[count($deletedThumbnailObjectIds)] ?? null, $objectId);
                $deletedThumbnailObjectIds[] = $objectId;
            });
        $thumbnailClient = new MockHttpClient(static function (string $method, string $url, array $options) use ($thumbnailPng, $token, &$thumbnailRequestCount): MockResponse {
            ++$thumbnailRequestCount;
            self::assertSame('GET', $method);
            self::assertStringStartsWith('https://images.example.test/', $url);
            self::assertSame(0, $options['max_redirects']);

            return str_contains($url, '/second-'.$token.'.png')
                ? new MockResponse('', ['http_code' => 302, 'response_headers' => ['location: https://images.example.test/redirected.png']])
                : new MockResponse($thumbnailPng, ['http_code' => 200]);
        });
        $client->getContainer()->set(
            VideoThumbnailImportService::class,
            new VideoThumbnailImportService($thumbnailClient, new VideoThumbnailUploadService($thumbnailStorage)),
        );
        $categoryName = 'Gaming '.$token;
        $categoryManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $categoryManager);
        $newsCategory = new ContentCategory($categoryName, ContentCategoryType::News);
        $videoCategory = new ContentCategory($categoryName, ContentCategoryType::Video);
        $categoryManager->persist($newsCategory);
        $categoryManager->persist($videoCategory);
        $categoryManager->flush();
        $newsTitle = 'News Test '.$token;
        $newsSlug = 'news-test-'.$token;
        $editedNewsTitle = 'News Edit '.$token;
        $editedNewsSlug = 'news-edit-'.$token;
        $videoTitle = 'Video Test '.$token;
        $embeddedVideoTitle = 'Embedded Video '.$token;
        $invalidEmbedAdminTitle = 'Invalid Embed '.$token;
        $rejectedEmbedCsvTitle = 'Rejected Embed CSV '.$token;
        $uploadedVideoTitle = 'Uploaded Video '.$token;
        $email = 'content-admin-'.$token.'@example.test';
        $password = 'Admin-Content-'.$token.'-A9!';
        $csvVideoTitle = 'CSV Video '.$token;
        $providerVideoTitle = 'Provider Video '.$token;
        $streamVideoTitle = 'Stream Video '.$token;
        $liveStreamVideoTitle = 'Live Stream Video '.$token;
        $unapprovedStreamTitle = 'Unapproved Stream '.$token;
        $atomicCsvTitle = 'Atomic CSV Video '.$token;
        $csvStreamVideoTitle = 'CSV Stream Video '.$token;
        $csvLiveStreamVideoTitle = 'CSV Live Stream Video '.$token;
        $unapprovedStreamCsvTitle = 'Unapproved CSV Stream '.$token;
        $legacyCsvSourceUrl = 'https://video.example.test/legacy-'.$token.'.mp4';
        $streamUrl = 'https://media.example.org/videos/admin-stream-'.$token.'.mp4?token=public-test';
        $liveStreamUrl = 'https://media.example.org/live/admin-'.$token.'.m3u8';
        $csvTagName = 'csv-tag-'.$token;
        $temporaryFiles = [];
        $uploadedObjectId = null;

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/news');
            self::assertResponseIsSuccessful();
            self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertSelectorTextContains('h1', 'News und Artikel');
            $client->request('GET', '/admin/news/new');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[name="custom_fields_present"]');
            $customNewsValue = '<script>news-custom-field</script>';
            $invalidNewsToken = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/news/new', [
                '_token' => $invalidNewsToken,
                'title' => $newsTitle,
                'author_name' => 'Test Redaktion',
                'category' => $categoryName,
                'tags' => '',
                'summary' => 'Kurzer Vorspann',
                'body' => 'Ein Testbeitrag für die redaktionelle Verwaltung.',
                'status' => 'published',
                'publish_at' => '',
                'custom_fields_present' => '1',
                'custom_fields' => [
                    ['key' => 'Studio', 'value' => 'First'],
                    ['key' => ' studio ', 'value' => 'Duplicate'],
                ],
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertFalse((bool) $categoryManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM news_articles WHERE slug = :slug',
                ['slug' => $newsSlug],
            ));

            $client->request('GET', '/admin/news/new');
            self::assertResponseIsSuccessful();
            $newsToken = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/news/new', [
                '_token' => $newsToken,
                'title' => $newsTitle,
                'author_name' => 'Test Redaktion',
                'category' => $categoryName,
                'tags' => '',
                'summary' => 'Kurzer Vorspann',
                'body' => 'Ein Testbeitrag für die redaktionelle Verwaltung.',
                'status' => 'published',
                'featured' => '1',
                'comments_enabled' => '1',
                'publish_at' => '',
                'custom_fields_present' => '1',
                'custom_fields' => [['key' => 'Studio', 'value' => $customNewsValue]],
            ]);
            self::assertResponseRedirects('/admin/news');
            $client->followRedirect();
            self::assertSelectorTextContains('table', $newsTitle);
            self::assertSelectorTextContains('table', 'Veröffentlicht');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $articleId = $entityManager->getConnection()->fetchOne(
                'SELECT id FROM news_articles WHERE slug = :slug',
                ['slug' => $newsSlug],
            );
            self::assertIsString($articleId);
            $newsCustomFieldsJson = $entityManager->getConnection()->fetchOne(
                'SELECT custom_fields::text FROM news_articles WHERE id = :id',
                ['id' => $articleId],
            );
            if (!is_string($newsCustomFieldsJson)) {
                throw new \LogicException('Die News-Metafelder wurden nicht als JSON gespeichert.');
            }
            $newsCustomFields = json_decode($newsCustomFieldsJson, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([['key' => 'Studio', 'value' => $customNewsValue]], $newsCustomFields);
            $client->request('GET', '/news/'.$newsSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.article-custom-fields', $customNewsValue);
            $newsResponseContent = $client->getResponse()->getContent();
            if (!is_string($newsResponseContent)) {
                throw new \LogicException('Die News-Seite lieferte keinen lesbaren Antworttext.');
            }
            self::assertStringNotContainsString($customNewsValue, $newsResponseContent);

            $client->request('GET', '/admin/news/'.$articleId.'/edit');
            self::assertResponseIsSuccessful();
            $client->submitForm('Änderungen speichern', [
                'title' => $editedNewsTitle,
                'category' => $categoryName,
                'summary' => 'Aktualisierter Vorspann',
                'body' => 'Der Beitrag wurde bearbeitet.',
                'status' => 'draft',
                'comments_enabled' => '1',
            ]);
            self::assertResponseRedirects('/admin/news');
            $client->followRedirect();
            self::assertSelectorTextContains('table', $editedNewsTitle);
            self::assertStringNotContainsString($newsTitle, $client->getCrawler()->filter('table')->text());

            $client->request('GET', '/admin/news');
            $newsBulkToken = $client->getCrawler()->filter('form.admin-bulk-form input[name="_token"]')->attr('value');
            self::assertIsString($newsBulkToken);
            $client->request('POST', '/admin/news/bulk', [
                '_token' => $newsBulkToken,
                'action' => 'publish',
                'ids' => [$articleId],
            ]);
            self::assertResponseRedirects('/admin/news');
            $client->followRedirect();
            self::assertSelectorTextContains('.flash-message.is-success', '1 Einträge wurden veröffentlicht.');

            $client->request('GET', '/admin/videos');
            self::assertResponseIsSuccessful();
            self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            $client->request('GET', '/admin/videos/new');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[name="custom_fields_present"]');
            $videoToken = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');
            $customVideoValue = '<script>video-custom-field</script>';
            $client->request('POST', '/admin/videos/new', [
                '_token' => $videoToken,
                'title' => $videoTitle,
                'description' => 'Testvideo mit einer HTTPS-Quelle.',
                'category' => $categoryName,
                'tags' => '',
                'source_type' => 'url',
                'source_url' => 'https://youtu.be/dQw4w9WgXcQ',
                'status' => 'published',
                'comments_enabled' => '1',
                'duration_seconds' => '',
                'embed_origins' => '',
                'custom_fields_present' => '1',
                'custom_fields' => [['key' => 'License', 'value' => $customVideoValue]],
            ]);
            self::assertResponseRedirects('/admin/videos');
            $client->followRedirect();
            self::assertSelectorTextContains('table', $videoTitle);
            self::assertSelectorTextContains('table', 'youtu.be');

            $client->request('GET', '/admin/videos/new');
            self::assertResponseIsSuccessful();
            $client->submitForm('Video speichern', [
                'title' => 'Doppeltes Testvideo '.$token,
                'description' => 'Diese URL ist bereits als Quelle gespeichert.',
                'category' => $categoryName,
                'source_type' => 'url',
                'source_url' => 'https://youtu.be/dQw4w9WgXcQ',
                'status' => 'draft',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('.flash-message.is-error', 'Diese Videoquelle ist bereits vorhanden.');
            self::assertSelectorNotExists('section[aria-labelledby="video-thumbnail-heading"]');
            self::assertEquals(1, $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM videos WHERE source_url = :source_url',
                ['source_url' => 'https://youtu.be/dQw4w9WgXcQ'],
            ));

            $videoId = $entityManager->getConnection()->fetchOne(
                'SELECT id FROM videos WHERE title = :title',
                ['title' => $videoTitle],
            );
            self::assertIsString($videoId);
            $videoCustomFieldsJson = $entityManager->getConnection()->fetchOne(
                'SELECT custom_fields::text FROM videos WHERE id = :id',
                ['id' => $videoId],
            );
            if (!is_string($videoCustomFieldsJson)) {
                throw new \LogicException('Die Video-Metafelder wurden nicht als JSON gespeichert.');
            }
            $videoCustomFields = json_decode($videoCustomFieldsJson, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([['key' => 'License', 'value' => $customVideoValue]], $videoCustomFields);
            $client->request('GET', '/videos/'.$videoId);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.article-custom-fields', $customVideoValue);
            $videoResponseContent = $client->getResponse()->getContent();
            if (!is_string($videoResponseContent)) {
                throw new \LogicException('Die Videoseite lieferte keinen lesbaren Antworttext.');
            }
            self::assertStringNotContainsString($customVideoValue, $videoResponseContent);
            $client->request('GET', '/admin/videos/'.$videoId.'/edit');
            self::assertResponseIsSuccessful();
            $client->submitForm('Änderungen speichern', [
                'title' => $videoTitle,
                'description' => 'Testvideo mit einer HTTPS-Quelle.',
                'category' => $categoryName,
                'source_type' => 'url',
                'source_url' => 'https://youtu.be/dQw4w9WgXcQ',
                'status' => 'draft',
                'comments_enabled' => '1',
            ]);
            self::assertResponseRedirects('/admin/videos');

            $client->request('GET', '/admin/videos/new');
            self::assertResponseIsSuccessful();
            $client->submitForm('Video speichern', [
                'title' => $embeddedVideoTitle,
                'description' => 'Allowlisted provider embed.',
                'category' => $categoryName,
                'source_type' => 'embed',
                'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'status' => 'draft',
                'comments_enabled' => '1',
            ]);
            self::assertResponseRedirects('/admin/videos');
            $embeddedVideo = $entityManager->getConnection()->fetchAssociative(
                'SELECT source_type, source_url FROM videos WHERE title = :title',
                ['title' => $embeddedVideoTitle],
            );
            self::assertIsArray($embeddedVideo);
            self::assertSame('embed', $embeddedVideo['source_type']);
            self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $embeddedVideo['source_url']);

            $client->request('GET', '/admin/videos/new');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('form.admin-form', 'MP4-, M4V-, WebM-, Ogg- und MP3-Dateien');
            self::assertSelectorTextContains('form.admin-form', 'keine Zugangsdaten oder geheimen Tokens');
            $client->request('POST', '/admin/videos/new', [
                '_token' => 'ungueltiger-token',
                'title' => $streamVideoTitle,
                'description' => 'A direct media stream.',
                'source_type' => 'stream',
                'source_url' => $streamUrl,
                'status' => 'published',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertFalse((bool) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM videos WHERE title = :title',
                ['title' => $streamVideoTitle],
            ));

            $client->request('GET', '/admin/videos/new');
            $client->submitForm('Video speichern', [
                'title' => $streamVideoTitle,
                'description' => 'A direct media stream.',
                'source_type' => 'stream',
                'source_url' => $streamUrl,
                'status' => 'published',
                'comments_enabled' => '1',
            ]);
            self::assertResponseRedirects('/admin/videos');
            $storedStream = $entityManager->getConnection()->fetchAssociative(
                'SELECT id, source_type, source_url, status FROM videos WHERE title = :title',
                ['title' => $streamVideoTitle],
            );
            self::assertIsArray($storedStream);
            self::assertSame('stream', $storedStream['source_type']);
            self::assertSame($streamUrl, $storedStream['source_url']);
            self::assertSame('published', $storedStream['status']);
            self::assertIsString($storedStream['id']);
            $client->request('GET', '/videos/'.$storedStream['id']);
            self::assertResponseIsSuccessful();
            self::assertSame($streamUrl, $client->getCrawler()->filter('video[data-testid="video-stream"] source')->attr('src'));
            self::assertStringContainsString("media-src 'self' https://media.example.org", $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));

            $client->request('GET', '/admin/videos/new');
            $client->submitForm('Video speichern', [
                'title' => $liveStreamVideoTitle,
                'description' => 'An approved HLS live stream.',
                'source_type' => 'stream',
                'source_url' => $liveStreamUrl,
                'status' => 'published',
            ]);
            self::assertResponseRedirects('/admin/videos');
            $storedLiveStream = $entityManager->getConnection()->fetchAssociative(
                'SELECT source_type, source_url FROM videos WHERE title = :title',
                ['title' => $liveStreamVideoTitle],
            );
            self::assertIsArray($storedLiveStream);
            self::assertSame('stream', $storedLiveStream['source_type']);
            self::assertSame($liveStreamUrl, $storedLiveStream['source_url']);

            $client->request('GET', '/admin/videos/new');
            $client->submitForm('Video speichern', [
                'title' => $unapprovedStreamTitle,
                'description' => 'A stream from a host that is not approved.',
                'source_type' => 'stream',
                'source_url' => 'https://unapproved.example.org/videos/private.mp4',
                'status' => 'draft',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('[role="alert"]', 'HTTPS-Mediendatei oder HLS-Wiedergabeliste auf einem ausdrücklich freigegebenen Host');
            self::assertFalse((bool) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM videos WHERE title = :title',
                ['title' => $unapprovedStreamTitle],
            ));

            $providerImport = new VideoProviderImportService(
                new MockHttpClient([new MockResponse(json_encode([
                    'items' => [[
                        'id' => ['videoId' => 'AbCdEf12345'],
                        'snippet' => [
                            'title' => $providerVideoTitle,
                            'description' => 'Imported from a mocked provider response.',
                        ],
                    ]],
                ], JSON_THROW_ON_ERROR), ['http_code' => Response::HTTP_OK])]),
                new ExternalVideoEmbedResolver(),
                'test-provider-api-key',
            );
            $client->disableReboot();
            $client->getContainer()->set(VideoProviderImportService::class, $providerImport);
            $client->request('GET', '/admin/videos/import/provider');
            self::assertResponseIsSuccessful();
            $this->assertReportOnlyCsp($client);
            self::assertSelectorTextContains('h1', 'Videoanbieter-Import');
            self::assertSelectorExists('input[name="channel_id"]');
            $providerCsrfToken = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            self::assertIsString($providerCsrfToken);

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => 'ungueltiger-token',
                'provider' => 'youtube',
                'mode' => 'search',
                'query' => 'gaming',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('POST', '/admin/videos/import/provider', [
                '_token' => $providerCsrfToken,
                'provider' => 'youtube',
                'mode' => 'search',
                'query' => 'gaming',
                'channel_id' => '',
                'category' => '',
            ]);
            self::assertResponseRedirects('/admin/videos');

            $providerVideo = $entityManager->getConnection()->fetchAssociative(
                'SELECT status, source_type, comments_enabled, source_url FROM videos WHERE title = :title',
                ['title' => $providerVideoTitle],
            );
            self::assertIsArray($providerVideo);
            self::assertSame('draft', $providerVideo['status']);
            self::assertSame('embed', $providerVideo['source_type']);
            self::assertFalse((bool) $providerVideo['comments_enabled']);
            self::assertSame('https://www.youtube.com/watch?v=AbCdEf12345', $providerVideo['source_url']);

            $temporaryCsvPath = tempnam(sys_get_temp_dir(), 'arcanorum-video-import-');
            if (false === $temporaryCsvPath) {
                throw new \RuntimeException('Could not create the CSV test file.');
            }
            $validCsvPath = $temporaryCsvPath.'.csv';
            if (!rename($temporaryCsvPath, $validCsvPath)) {
                unlink($temporaryCsvPath);
                throw new \RuntimeException('Could not add the CSV extension to the test file.');
            }
            $temporaryFiles[] = $validCsvPath;
            $validCsv = "title,description,source_type,source_url,category,tags\n"
                .sprintf(
                    "%s,Imported from CSV,url,https://video.example.test/%s.mp4,,%s\n",
                    $csvVideoTitle,
                    $token,
                    $csvTagName,
                )
                .sprintf(
                    "%s,Imported stream,stream,https://media.example.org/imported/%s.m4v,,\n",
                    $csvStreamVideoTitle,
                    $token,
                )
                .sprintf(
                    "%s,Imported HLS live stream,stream,https://media.example.org/imported/%s.m3u8,,\n",
                    $csvLiveStreamVideoTitle,
                    $token,
                );
            if (false === file_put_contents($validCsvPath, $validCsv)) {
                throw new \RuntimeException('Could not write the CSV test file.');
            }

            $client->request('GET', '/admin/videos/import');
            self::assertResponseIsSuccessful();
            self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            $client->submitForm('CSV prüfen und Entwürfe importieren', [
                'csv_file' => new UploadedFile($validCsvPath, 'video-import.csv', 'text/csv', null, true),
            ]);
            self::assertResponseRedirects('/admin/videos');
            $client->followRedirect();
            self::assertSelectorTextContains('table', $csvVideoTitle);
            self::assertSelectorTextContains('table', $csvStreamVideoTitle);
            self::assertSelectorTextContains('table', $csvLiveStreamVideoTitle);

            $importedVideo = $entityManager->getConnection()->fetchAssociative(
                'SELECT status, comments_enabled, source_url FROM videos WHERE title = :title',
                ['title' => $csvVideoTitle],
            );
            self::assertIsArray($importedVideo);
            self::assertSame('draft', $importedVideo['status']);
            self::assertFalse((bool) $importedVideo['comments_enabled']);
            self::assertSame('https://video.example.test/'.$token.'.mp4', $importedVideo['source_url']);

            $importedStream = $entityManager->getConnection()->fetchAssociative(
                'SELECT source_type, source_url FROM videos WHERE title = :title',
                ['title' => $csvStreamVideoTitle],
            );
            self::assertIsArray($importedStream);
            self::assertSame('stream', $importedStream['source_type']);
            self::assertSame('https://media.example.org/imported/'.$token.'.m4v', $importedStream['source_url']);

            $importedLiveStream = $entityManager->getConnection()->fetchAssociative(
                'SELECT source_type, source_url FROM videos WHERE title = :title',
                ['title' => $csvLiveStreamVideoTitle],
            );
            self::assertIsArray($importedLiveStream);
            self::assertSame('stream', $importedLiveStream['source_type']);
            self::assertSame('https://media.example.org/imported/'.$token.'.m3u8', $importedLiveStream['source_url']);

            $legacyCsvTemporaryPath = tempnam(sys_get_temp_dir(), 'arcanorum-legacy-csv-');
            if (false === $legacyCsvTemporaryPath) {
                throw new \RuntimeException('Could not create the Legacy CSV test file.');
            }
            $legacyCsvPath = $legacyCsvTemporaryPath.'.csv';
            if (!rename($legacyCsvTemporaryPath, $legacyCsvPath)) {
                unlink($legacyCsvTemporaryPath);
                throw new \RuntimeException('Could not add the CSV extension to the Legacy test file.');
            }
            $temporaryFiles[] = $legacyCsvPath;
            $legacyCsv = sprintf(
                "%s,,Imported from the legacy CSV format,\"legacy-csv-%s,news-csv-%s\",https://images.example.test/%s.png,120\n",
                $legacyCsvSourceUrl,
                $token,
                $token,
                $token,
            );
            if (false === file_put_contents($legacyCsvPath, $legacyCsv)) {
                throw new \RuntimeException('Could not write the Legacy CSV test file.');
            }

            $client->request('GET', '/admin/videos/import');
            self::assertResponseIsSuccessful();
            $client->submitForm('CSV prüfen und Entwürfe importieren', [
                'csv_file' => new UploadedFile($legacyCsvPath, 'legacy-video-import.csv', 'text/csv', null, true),
            ]);
            self::assertResponseRedirects('/admin/videos');

            $legacyVideo = $entityManager->getConnection()->fetchAssociative(
                'SELECT title, status, duration_seconds, thumbnail_object_id, thumbnail_mime_type FROM videos WHERE source_url = :source_url',
                ['source_url' => $legacyCsvSourceUrl],
            );
            self::assertIsArray($legacyVideo);
            self::assertSame('Video von video.example.test', $legacyVideo['title']);
            self::assertSame('draft', $legacyVideo['status']);
            self::assertSame(120, filter_var($legacyVideo['duration_seconds'], FILTER_VALIDATE_INT));
            self::assertSame($thumbnailObjectIds[0] ?? null, $legacyVideo['thumbnail_object_id']);
            self::assertSame('image/png', $legacyVideo['thumbnail_mime_type']);

            $partialImportSourceUrls = [
                'https://video.example.test/partial-first-'.$token.'.mp4',
                'https://video.example.test/partial-second-'.$token.'.mp4',
            ];
            $rollbackCsvTemporaryPath = tempnam(sys_get_temp_dir(), 'arcanorum-thumbnail-rollback-');
            if (false === $rollbackCsvTemporaryPath) {
                throw new \RuntimeException('Could not create the thumbnail rollback CSV test file.');
            }
            $rollbackCsvPath = $rollbackCsvTemporaryPath.'.csv';
            if (!rename($rollbackCsvTemporaryPath, $rollbackCsvPath)) {
                unlink($rollbackCsvTemporaryPath);
                throw new \RuntimeException('Could not add the CSV extension to the thumbnail rollback test file.');
            }
            $temporaryFiles[] = $rollbackCsvPath;
            $rollbackCsv = sprintf(
                "%s,First temporary image,Description,tag,https://images.example.test/first-%s.png,10\n%s,Redirected image,Description,tag,https://images.example.test/second-%s.png,20\n",
                $partialImportSourceUrls[0],
                $token,
                $partialImportSourceUrls[1],
                $token,
            );
            if (false === file_put_contents($rollbackCsvPath, $rollbackCsv)) {
                throw new \RuntimeException('Could not write the thumbnail rollback CSV test file.');
            }

            $client->request('GET', '/admin/videos/import');
            self::assertResponseIsSuccessful();
            $client->submitForm('CSV prüfen und Entwürfe importieren', [
                'csv_file' => new UploadedFile($rollbackCsvPath, 'thumbnail-rollback.csv', 'text/csv', null, true),
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('[role="alert"]', 'Vorschaubild konnte nicht sicher übernommen werden');
            foreach ($partialImportSourceUrls as $sourceUrl) {
                self::assertFalse((bool) $entityManager->getConnection()->fetchOne(
                    'SELECT COUNT(*) FROM videos WHERE source_url = :source_url',
                    ['source_url' => $sourceUrl],
                ));
            }
            self::assertSame(3, $thumbnailRequestCount, 'Only the three explicit thumbnail addresses may be fetched.');

            $videoBytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64);
            $videoFilePath = tempnam(sys_get_temp_dir(), 'arcanorum-video-upload-');
            if (false === $videoFilePath) {
                throw new \RuntimeException('Could not create the video upload test file.');
            }
            $temporaryFiles[] = $videoFilePath;
            if (false === file_put_contents($videoFilePath, $videoBytes)) {
                throw new \RuntimeException('Could not write the video upload test file.');
            }

            $client->request('GET', '/admin/videos/new');
            self::assertResponseIsSuccessful();
            $client->submitForm('Video speichern', [
                'title' => $uploadedVideoTitle,
                'description' => 'Uploaded into private storage.',
                'category' => '',
                'source_type' => 'file',
                'source_url' => '',
                'status' => 'published',
                'comments_enabled' => false,
                'video_file' => new UploadedFile($videoFilePath, 'claimed-video.mp4', 'application/octet-stream', null, true),
            ]);
            self::assertResponseRedirects('/admin/videos');

            $uploadedVideo = $entityManager->getConnection()->fetchAssociative(
                'SELECT id, status, source_type, source_url, comments_enabled, uploaded_object_id, uploaded_mime_type, uploaded_size FROM videos WHERE title = :title',
                ['title' => $uploadedVideoTitle],
            );
            self::assertIsArray($uploadedVideo);
            self::assertIsString($uploadedVideo['id']);
            self::assertSame('published', $uploadedVideo['status']);
            self::assertSame('file', $uploadedVideo['source_type']);
            self::assertSame('', $uploadedVideo['source_url']);
            self::assertFalse((bool) $uploadedVideo['comments_enabled']);
            self::assertSame('video/mp4', $uploadedVideo['uploaded_mime_type']);
            self::assertSame(strlen($videoBytes), filter_var($uploadedVideo['uploaded_size'], FILTER_VALIDATE_INT));
            $uploadedVideoId = $uploadedVideo['id'];
            self::assertIsString($uploadedVideo['uploaded_object_id']);
            $uploadedObjectId = $uploadedVideo['uploaded_object_id'];

            $client->request('GET', '/videos/'.$uploadedVideoId);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('/videos/'.$uploadedVideoId.'/media', (string) $client->getResponse()->getContent());

            $client->request('GET', '/videos/'.$uploadedVideoId.'/media');
            self::assertResponseIsSuccessful();
            self::assertSame('video/mp4', $client->getResponse()->headers->get('Content-Type'));
            self::assertSame('same-origin', $client->getResponse()->headers->get('Cross-Origin-Resource-Policy'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
            self::assertSame($videoBytes, $client->getResponse()->getContent());

            $client->request('GET', '/videos/'.$uploadedVideoId.'/media', [], [], ['HTTP_RANGE' => 'bytes=2-5']);
            self::assertResponseStatusCodeSame(Response::HTTP_PARTIAL_CONTENT);
            self::assertSame('bytes 2-5/'.strlen($videoBytes), $client->getResponse()->headers->get('Content-Range'));
            self::assertSame(substr($videoBytes, 2, 4), $client->getResponse()->getContent());

            $validMediaRanges = [
                ['bytes=2-', 2, strlen($videoBytes) - 1],
                ['bytes=2-999999', 2, strlen($videoBytes) - 1],
                ['bytes=-4', strlen($videoBytes) - 4, strlen($videoBytes) - 1],
            ];
            foreach ($validMediaRanges as [$rangeHeader, $start, $end]) {
                $client->request('GET', '/videos/'.$uploadedVideoId.'/media', [], [], ['HTTP_RANGE' => $rangeHeader]);
                self::assertResponseStatusCodeSame(Response::HTTP_PARTIAL_CONTENT, $rangeHeader);
                self::assertResponseHeaderSame(
                    'Content-Range',
                    sprintf('bytes %d-%d/%d', $start, $end, strlen($videoBytes)),
                );
                self::assertResponseHeaderSame('Content-Length', (string) ($end - $start + 1));
                self::assertSame(substr($videoBytes, $start, $end - $start + 1), $client->getResponse()->getContent());
            }

            $invalidMediaRanges = [
                'bytes=999999-',
                'items=0-1',
                'bytes=3-2',
                'bytes=-0',
                'bytes=0-1,4-5',
                'bytes=-',
                'bytes='.str_repeat('9', 101),
            ];
            foreach ($invalidMediaRanges as $rangeHeader) {
                $client->request('GET', '/videos/'.$uploadedVideoId.'/media', [], [], ['HTTP_RANGE' => $rangeHeader]);
                self::assertResponseStatusCodeSame(Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, $rangeHeader);
                self::assertResponseHeaderSame('Accept-Ranges', 'bytes');
                self::assertResponseHeaderSame('Content-Range', 'bytes */'.strlen($videoBytes));
                self::assertStringContainsString(
                    'no-store',
                    (string) $client->getResponse()->headers->get('Cache-Control'),
                );
                self::assertSame('', $client->getResponse()->getContent());
            }

            $mediaStorage->delete($uploadedObjectId);
            $client->request('GET', '/videos/'.$uploadedVideoId.'/media');
            self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
            self::assertResponseHeaderSame('Retry-After', '60');
            self::assertStringContainsString(
                'no-store',
                (string) $client->getResponse()->headers->get('Cache-Control'),
            );
            self::assertSame('', $client->getResponse()->getContent());
            $mediaStorage->write($uploadedObjectId, $videoBytes);

            foreach ([str_repeat('x', strlen($videoBytes)), substr($videoBytes, 1)] as $damagedMediaBytes) {
                $mediaStorage->delete($uploadedObjectId);
                $mediaStorage->write($uploadedObjectId, $damagedMediaBytes);
                $client->request('GET', '/videos/'.$uploadedVideoId.'/media');
                self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
                self::assertResponseHeaderSame('Retry-After', '60');
                $damagedMediaCacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
                self::assertStringContainsString('no-store', $damagedMediaCacheControl);
                self::assertSame('', $client->getResponse()->getContent());
            }
            $mediaStorage->delete($uploadedObjectId);
            $mediaStorage->write($uploadedObjectId, $videoBytes);

            $temporaryInvalidCsvPath = tempnam(sys_get_temp_dir(), 'arcanorum-video-import-');
            if (false === $temporaryInvalidCsvPath) {
                throw new \RuntimeException('Could not create the invalid CSV test file.');
            }
            $invalidCsvPath = $temporaryInvalidCsvPath.'.csv';
            if (!rename($temporaryInvalidCsvPath, $invalidCsvPath)) {
                unlink($temporaryInvalidCsvPath);
                throw new \RuntimeException('Could not add the CSV extension to the invalid test file.');
            }
            $temporaryFiles[] = $invalidCsvPath;
            $invalidCsv = "title,description,source_type,source_url,category,tags\n"
                .sprintf(
                    "%s,Valid first row,url,https://video.example.test/atomic-%s.mp4,,\n",
                    $atomicCsvTitle,
                    $token,
                )
                .sprintf(
                    "Rejected CSV Video %s,Invalid second row,unknown,https://video.example.test/rejected-%s.mp4,,\n",
                    $token,
                    $token,
                )
                .sprintf(
                    "%s,Untrusted embed provider,embed,https://attacker.example.test/123456789,,\n",
                    $rejectedEmbedCsvTitle,
                )
                .sprintf(
                    "%s,Unapproved stream,stream,https://unapproved.example.org/private/%s.mp4,,\n",
                    $unapprovedStreamCsvTitle,
                    $token,
                );
            if (false === file_put_contents($invalidCsvPath, $invalidCsv)) {
                throw new \RuntimeException('Could not write the invalid CSV test file.');
            }

            $client->request('GET', '/admin/videos/import');
            self::assertResponseIsSuccessful();
            $client->submitForm('CSV prüfen und Entwürfe importieren', [
                'csv_file' => new UploadedFile($invalidCsvPath, 'invalid-video-import.csv', 'text/csv', null, true),
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('[role="alert"]', 'CSV-Quellart muss url, stream oder embed sein');
            self::assertSelectorTextContains('[role="alert"]', 'unterstützten Providers');
            self::assertSelectorTextContains('[role="alert"]', 'freigegebenen Host');
            self::assertFalse((bool) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM videos WHERE title = :title',
                ['title' => $atomicCsvTitle],
            ));
            self::assertFalse((bool) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM videos WHERE title = :title',
                ['title' => $rejectedEmbedCsvTitle],
            ));
            self::assertFalse((bool) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM videos WHERE title = :title',
                ['title' => $unapprovedStreamCsvTitle],
            ));

            $client->request('GET', '/admin/videos/new');
            $client->submitForm('Video speichern', [
                'title' => 'Ungültige Quelle '.$token,
                'description' => '',
                'category' => '',
                'source_type' => 'url',
                'source_url' => 'https://127.0.0.1/private',
                'status' => 'draft',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('[role="alert"]', 'HTTPS-Adresse');

            $client->request('GET', '/admin/videos/new');
            self::assertResponseIsSuccessful();
            $client->submitForm('Video speichern', [
                'title' => $invalidEmbedAdminTitle,
                'description' => '',
                'category' => '',
                'source_type' => 'embed',
                'source_url' => 'https://attacker.example.test/embed/dQw4w9WgXcQ',
                'status' => 'draft',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('[role="alert"]', 'Video-URL von YouTube, Vimeo, Dailymotion, Facebook, Google Drive, Rutube, VK oder Youku');
            self::assertFalse((bool) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM videos WHERE title = :title',
                ['title' => $invalidEmbedAdminTitle],
            ));
        } finally {
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($entityManager instanceof EntityManagerInterface) {
                $connection = $entityManager->getConnection();
                $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $newsSlug]);
                $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $editedNewsSlug]);
                foreach ([$videoTitle, $embeddedVideoTitle, $providerVideoTitle, $streamVideoTitle, $liveStreamVideoTitle, $unapprovedStreamTitle, $invalidEmbedAdminTitle, $csvVideoTitle, $csvStreamVideoTitle, $csvLiveStreamVideoTitle, $atomicCsvTitle, 'Rejected CSV Video '.$token, $rejectedEmbedCsvTitle, $unapprovedStreamCsvTitle, $uploadedVideoTitle] as $title) {
                    $connection->executeStatement('DELETE FROM videos WHERE title = :title', ['title' => $title]);
                }
                $connection->executeStatement('DELETE FROM videos WHERE source_url = :source_url', ['source_url' => $legacyCsvSourceUrl]);
                $connection->executeStatement('DELETE FROM content_categories WHERE id IN (:news, :video)', [
                    'news' => $newsCategory->getId(),
                    'video' => $videoCategory->getId(),
                ]);
                $connection->executeStatement('DELETE FROM content_tags WHERE normalized_name = :name', [
                    'name' => mb_strtolower($csvTagName),
                ]);
                foreach (['legacy-csv-'.$token, 'news-csv-'.$token] as $tagName) {
                    $connection->executeStatement('DELETE FROM content_tags WHERE normalized_name = :name', [
                        'name' => mb_strtolower($tagName),
                    ]);
                }
            }
            if (is_string($uploadedObjectId)) {
                $mediaStorage->delete($uploadedObjectId);
            }
            foreach ($temporaryFiles as $temporaryFile) {
                if (is_file($temporaryFile)) {
                    unlink($temporaryFile);
                }
            }

            $this->removeUser($client, $email);
        }
    }

    public function testPublicVideoMediaRejectsNonCanonicalPrivateObjectId(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $video = new Video(
            'Corrupted storage reference '.bin2hex(random_bytes(6)),
            '',
            null,
            VideoSourceType::File,
            '',
            PublishingStatus::Published,
            false,
            true,
        );
        $video->attachUploadedMedia(bin2hex(random_bytes(32)), 'video/mp4', 1);
        $videoId = $video->getId();

        try {
            $entityManager->persist($video);
            $entityManager->flush();
            $entityManager->getConnection()->executeStatement(
                'UPDATE videos SET uploaded_object_id = :object_id WHERE id = :id',
                ['object_id' => '../outside-private-storage', 'id' => $videoId],
            );
            $entityManager->clear();

            $client->request('GET', '/videos/'.$videoId.'/media');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                $cleanupManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $videoId]);
            }
        }
    }

    private function interactiveConsoleTerminal(bool $attached): InteractiveConsoleTerminalInterface
    {
        return new readonly class($attached) implements InteractiveConsoleTerminalInterface {
            public function __construct(private bool $attached)
            {
            }

            public function isAttachedToTerminal(InputInterface $input, OutputInterface $output): bool
            {
                return $this->attached;
            }
        };
    }

    private function assertReportOnlyCsp(KernelBrowser $client): void
    {
        $headers = $client->getResponse()->headers;

        self::assertSame(
            [self::CSP_REPORT_ONLY_POLICY],
            $headers->all(ContentSecurityPolicyReportOnlySubscriber::HEADER),
        );
        self::assertFalse($headers->has('Content-Security-Policy'));
    }

    private function createAdminUserCommand(KernelBrowser $client, bool $terminalAttached = true): CreateAdminUserCommand
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $repository = $entityManager->getRepository(AdminUser::class);
        self::assertInstanceOf(AdminUserRepository::class, $repository);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $mfaProvisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $mfaProvisioner);
        $provider = $client->getContainer()->get(RuntimeAttestationReportProviderInterface::class);
        self::assertInstanceOf(RuntimeAttestationReportProviderInterface::class, $provider);
        $guard = $client->getContainer()->get(RuntimeAttestationActionGuard::class);
        self::assertInstanceOf(RuntimeAttestationActionGuard::class, $guard);
        $incidentReporter = $client->getContainer()->get(RuntimeAttestationIncidentReporterInterface::class);
        self::assertInstanceOf(RuntimeAttestationIncidentReporterInterface::class, $incidentReporter);

        return new CreateAdminUserCommand($repository, $passwordHasher, $mfaProvisioner, $provider, $guard, $incidentReporter, $this->interactiveConsoleTerminal($terminalAttached));
    }

    private function provisionAdminMfaCommand(KernelBrowser $client, bool $terminalAttached = true): ProvisionAdminMfaCommand
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $users = $entityManager->getRepository(AdminUser::class);
        self::assertInstanceOf(AdminUserRepository::class, $users);
        $mfaProvisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $mfaProvisioner);
        $provider = $client->getContainer()->get(RuntimeAttestationReportProviderInterface::class);
        self::assertInstanceOf(RuntimeAttestationReportProviderInterface::class, $provider);
        $guard = $client->getContainer()->get(RuntimeAttestationActionGuard::class);
        self::assertInstanceOf(RuntimeAttestationActionGuard::class, $guard);
        $incidentReporter = $client->getContainer()->get(RuntimeAttestationIncidentReporterInterface::class);
        self::assertInstanceOf(RuntimeAttestationIncidentReporterInterface::class, $incidentReporter);

        return new ProvisionAdminMfaCommand($users, $mfaProvisioner, $provider, $guard, $incidentReporter, $this->interactiveConsoleTerminal($terminalAttached));
    }

    private function createAdministratorInteractively(KernelBrowser $client, string $email, string $password): void
    {
        $tester = new CommandTester($this->createAdminUserCommand($client));
        $tester->setInputs([$email, $password, $password]);

        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => true]));
        self::assertStringNotContainsString($password, $tester->getDisplay());
        self::assertStringContainsString('TOTP-Geheimnis', $tester->getDisplay());
        self::assertStringContainsString('Wiederherstellungscodes', $tester->getDisplay());
    }

    public function testAdminCanSaveSeriesGenresAndEpisodeMetadata(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $token = bin2hex(random_bytes(8));
        $email = 'series-admin-'.$token.'@example.test';
        $password = 'Series-Admin-'.$token.'-K8!';
        $seriesTitle = 'Series Admin '.$token;
        $seriesSlug = 'series-admin-'.$token;
        $firstGenre = 'Drama '.$token;
        $secondGenre = 'Sci-Fi '.$token;
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $video = null;
        $seriesId = null;

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);

            $video = new Video(
                'Series episode source '.$token,
                'Source video description.',
                null,
                VideoSourceType::Url,
                'https://video.example.test/series-admin-'.$token.'.mp4',
                PublishingStatus::Published,
                false,
                false,
            );
            $entityManager->persist($video);
            $entityManager->flush();

            $client->request('GET', '/admin/series/new');
            self::assertResponseIsSuccessful();
            $client->submitForm('Serie anlegen', [
                'title' => $seriesTitle,
                'slug' => $seriesSlug,
                'description' => 'Series description.',
                'genres' => $firstGenre.', '.$secondGenre."\n".mb_strtolower($firstGenre),
                'status' => 'published',
            ]);
            self::assertResponseRedirects('/admin/series');

            $seriesId = $entityManager->getConnection()->fetchOne(
                'SELECT id FROM content_series WHERE slug = :slug',
                ['slug' => $seriesSlug],
            );
            self::assertIsString($seriesId);

            $client->request('GET', '/admin/episodes/new?series='.$seriesId);
            self::assertResponseIsSuccessful();
            $client->submitForm('Folge zuordnen', [
                'series_id' => $seriesId,
                'video_id' => $video->getId(),
                'season_number' => '1',
                'episode_number' => '1',
                'title_override' => 'Pilot episode',
                'description_override' => 'Episode-specific description.',
                'status' => 'draft',
            ]);
            self::assertResponseRedirects('/admin/episodes?series='.$seriesId);

            $seriesRepository = $entityManager->getRepository(ContentSeries::class);
            self::assertInstanceOf(ContentSeriesRepository::class, $seriesRepository);
            $series = $seriesRepository->find($seriesId);
            self::assertInstanceOf(ContentSeries::class, $series);
            self::assertSame([$firstGenre, $secondGenre], $series->getGenres());

            $episodeRepository = $entityManager->getRepository(SeriesEpisode::class);
            self::assertInstanceOf(SeriesEpisodeRepository::class, $episodeRepository);
            $episode = $episodeRepository->findForVideo($video);
            self::assertInstanceOf(SeriesEpisode::class, $episode);
            self::assertSame('Pilot episode', $episode->getTitleOverride());
            self::assertSame('Episode-specific description.', $episode->getDescriptionOverride());
            self::assertSame(PublishingStatus::Draft, $episode->getStatus());
        } finally {
            if ($video instanceof Video) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM videos WHERE id = :id',
                    ['id' => $video->getId()],
                );
            }
            if (is_string($seriesId)) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM content_series WHERE id = :id',
                    ['id' => $seriesId],
                );
            }
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM series_genres WHERE normalized_name IN (:first, :second)',
                ['first' => mb_strtolower($firstGenre), 'second' => mb_strtolower($secondGenre)],
            );
            $this->removeUser($client, $email);
        }
    }

    public function testAdminCanUploadPreviewAndRemoveVideoThumbnail(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $token = bin2hex(random_bytes(8));
        $email = 'thumbnail-admin-'.$token.'@example.test';
        $password = 'Thumbnail-Admin-'.$token.'-K8!';
        $video = new Video(
            'Thumbnail admin '.$token,
            'Thumbnail upload integration test.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/thumbnail-'.$token.'.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        $storage = $client->getContainer()->get(PrivateObjectStorageInterface::class);
        self::assertInstanceOf(PrivateObjectStorageInterface::class, $storage);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'arcanorum-thumbnail-');
        self::assertIsString($temporaryPath);
        self::assertSame(strlen($png), file_put_contents($temporaryPath, $png));
        $entityManager->persist($video);
        $entityManager->flush();

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

            $client->request('POST', '/admin/videos/'.$video->getId().'/thumbnail', [
                '_token' => 'invalid-token',
                'action' => 'upload',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('GET', '/admin/videos/'.$video->getId().'/edit');
            self::assertResponseIsSuccessful();
            $csrfToken = $client->getCrawler()
                ->filter('form[action="/admin/videos/'.$video->getId().'/thumbnail"] input[name="_token"]')
                ->eq(0)
                ->attr('value');
            self::assertIsString($csrfToken);
            $client->request(
                'POST',
                '/admin/videos/'.$video->getId().'/thumbnail',
                ['_token' => $csrfToken, 'action' => 'upload'],
                ['thumbnail_file' => new UploadedFile($temporaryPath, 'thumbnail.png', 'image/png', null, true)],
            );
            self::assertResponseRedirects('/admin/videos/'.$video->getId().'/edit');
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();
            $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
            self::assertInstanceOf(Video::class, $storedVideo);
            self::assertNotNull($storedVideo->getThumbnailObjectId());

            $client->request('GET', '/admin/videos/'.$video->getId().'/thumbnail');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'image/png');
            self::assertSame($png, $client->getResponse()->getContent());

            $client->request('GET', '/admin/videos/'.$video->getId().'/edit');
            $csrfToken = $client->getCrawler()
                ->filter('form[action="/admin/videos/'.$video->getId().'/thumbnail"] input[name="_token"]')
                ->eq(1)
                ->attr('value');
            self::assertIsString($csrfToken);
            $client->request('POST', '/admin/videos/'.$video->getId().'/thumbnail', [
                '_token' => $csrfToken,
                'action' => 'remove',
            ]);
            self::assertResponseRedirects('/admin/videos/'.$video->getId().'/edit');
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();
            $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
            self::assertInstanceOf(Video::class, $storedVideo);
            self::assertNull($storedVideo->getThumbnailObjectId());
            $client->request('GET', '/admin/videos/'.$video->getId().'/thumbnail');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                $thumbnailObjectId = $cleanupManager->getConnection()->fetchOne(
                    'SELECT thumbnail_object_id FROM videos WHERE id = :id',
                    ['id' => $video->getId()],
                );
                if (is_string($thumbnailObjectId)) {
                    $storage->delete($thumbnailObjectId);
                }
                $cleanupManager->getConnection()->executeStatement(
                    'DELETE FROM videos WHERE id = :id',
                    ['id' => $video->getId()],
                );
            }
            $this->removeUser($client, $email);
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function testAdminCanUploadUpdateAndRemovePrivateVideoSubtitleTracks(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set('logger', new NullLogger());
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $token = bin2hex(random_bytes(8));
        $email = 'subtitle-admin-'.$token.'@example.test';
        $password = 'Subtitle-Admin-'.$token.'-K8!';
        $video = new Video(
            'Subtitle admin '.$token,
            'Subtitle upload integration test.',
            null,
            VideoSourceType::File,
            '',
            PublishingStatus::Published,
            false,
            true,
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $storage = $client->getContainer()->get(PrivateObjectStorageInterface::class);
        self::assertInstanceOf(PrivateObjectStorageInterface::class, $storage);
        $videoObjectId = bin2hex(random_bytes(32));
        $videoBytes = "\x00\x00\x00\x18ftypmp42".str_repeat("\x00", 32);
        $storage->write($videoObjectId, $videoBytes);
        $video->attachUploadedMedia($videoObjectId, 'video/mp4', strlen($videoBytes));
        $entityManager->persist($video);
        $entityManager->flush();

        $firstSubtitlePath = tempnam(sys_get_temp_dir(), 'arcanorum-subtitle-');
        $updatedSubtitlePath = tempnam(sys_get_temp_dir(), 'arcanorum-subtitle-');
        self::assertIsString($firstSubtitlePath);
        self::assertIsString($updatedSubtitlePath);
        $firstSubtitle = "WEBVTT\n\n00:00:00.000 --> 00:00:01.500\nHello world.\n";
        $updatedSubtitle = "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nUpdated caption.\n";
        self::assertSame(strlen($firstSubtitle), file_put_contents($firstSubtitlePath, $firstSubtitle));
        self::assertSame(strlen($updatedSubtitle), file_put_contents($updatedSubtitlePath, $updatedSubtitle));

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $videoId = $video->getId();
            $uploadUrl = '/admin/videos/'.$videoId.'/subtitles';
            $editUrl = '/admin/videos/'.$videoId.'/edit';
            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            $csrfToken = $client->getCrawler()
                ->filter('form[action="'.$uploadUrl.'"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($csrfToken);

            $client->request('POST', $uploadUrl, [
                '_token' => 'invalid-token',
                'language_code' => 'EN-us',
                'label' => 'English',
            ], [
                'subtitle_file' => new UploadedFile($firstSubtitlePath, 'english.vtt', 'text/vtt', null, true),
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('POST', $uploadUrl, [
                '_token' => $csrfToken,
                'language_code' => 'en-US',
                'label' => 'English',
                'unexpected' => 'must be rejected',
            ], [
                'subtitle_file' => new UploadedFile($firstSubtitlePath, 'english.vtt', 'text/vtt', null, true),
            ]);
            self::assertResponseRedirects($editUrl);
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();
            self::assertSame(0, self::subtitleIntegerValue($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM video_subtitle_tracks WHERE video_id = :id',
                ['id' => $videoId],
            )));

            $client->request('POST', $uploadUrl, [
                '_token' => $csrfToken,
                'language_code' => 'EN-us',
                'label' => 'English',
            ], [
                'subtitle_file' => new UploadedFile($firstSubtitlePath, 'english.vtt', 'text/vtt', null, true),
            ]);
            self::assertResponseRedirects($editUrl);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $track = $entityManager->getConnection()->fetchAssociative(
                'SELECT id, language_code, label, storage_object_id, size, sha256 FROM video_subtitle_tracks WHERE video_id = :id',
                ['id' => $videoId],
            );
            $track = self::subtitleRow($track);
            self::assertSame('en-US', self::subtitleStringValue($track, 'language_code'));
            self::assertSame('English', self::subtitleStringValue($track, 'label'));
            self::assertSame(strlen($firstSubtitle), self::subtitleIntegerValue($track['size'] ?? null));
            self::assertSame(hash('sha256', $firstSubtitle), self::subtitleStringValue($track, 'sha256'));
            $trackId = self::subtitleStringValue($track, 'id');
            $oldObjectId = self::subtitleStringValue($track, 'storage_object_id');

            $client->request('GET', '/videos/'.$videoId.'/subtitles/'.$trackId);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'text/vtt; charset=UTF-8');
            self::assertSame($firstSubtitle, $client->getResponse()->getContent());
            self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
            $connection = $entityManager->getConnection();
            $connection->beginTransaction();
            try {
                $connection->executeStatement(
                    'ALTER TABLE video_subtitle_tracks DROP CONSTRAINT video_subtitle_tracks_storage_object_id_check',
                );
                $connection->executeStatement(
                    'UPDATE video_subtitle_tracks SET storage_object_id = :object_id WHERE id = :id',
                    ['object_id' => 'A'.substr($oldObjectId, 1), 'id' => $trackId],
                );
                $entityManager->clear();
                $client->request('GET', '/videos/'.$videoId.'/subtitles/'.$trackId);
                self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
                self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            } finally {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $entityManager->clear();
            }
            $storage->write($oldObjectId, $updatedSubtitle);
            $client->request('GET', '/videos/'.$videoId.'/subtitles/'.$trackId);
            self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
            $storage->write($oldObjectId, $firstSubtitle);
            $storage->delete($oldObjectId);
            $client->request('GET', '/videos/'.$videoId.'/subtitles/'.$trackId);
            self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
            $storage->write($oldObjectId, $firstSubtitle);

            $client->request('GET', '/videos/'.$videoId);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('srclang="en-US"', $client->getResponse()->getContent());
            self::assertStringContainsString('/videos/'.$videoId.'/subtitles/'.$trackId, $client->getResponse()->getContent());
            $client->request('GET', '/videos/'.$videoId.'/subtitles/'.bin2hex(random_bytes(16)));
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

            $client->request('GET', $editUrl);
            $csrfToken = $client->getCrawler()
                ->filter('form[action="'.$uploadUrl.'"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($csrfToken);
            $client->request('POST', $uploadUrl, [
                '_token' => $csrfToken,
                'language_code' => 'en-US',
                'label' => 'English updated',
            ], [
                'subtitle_file' => new UploadedFile($updatedSubtitlePath, 'english-updated.vtt', 'text/vtt', null, true),
            ]);
            self::assertResponseRedirects($editUrl);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $updatedTrack = $entityManager->getConnection()->fetchAssociative(
                'SELECT id, label, storage_object_id FROM video_subtitle_tracks WHERE video_id = :id',
                ['id' => $videoId],
            );
            self::assertIsArray($updatedTrack);
            $updatedTrack = self::subtitleRow($updatedTrack);
            self::assertSame($trackId, self::subtitleStringValue($updatedTrack, 'id'));
            self::assertSame('English updated', self::subtitleStringValue($updatedTrack, 'label'));
            self::assertNotSame($oldObjectId, self::subtitleStringValue($updatedTrack, 'storage_object_id'));

            $client->request('GET', $editUrl);
            $deleteUrl = '/admin/videos/'.$videoId.'/subtitles/'.$trackId.'/delete';
            $deleteToken = $client->getCrawler()
                ->filter('form[action="'.$deleteUrl.'"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($deleteToken);
            $client->request('POST', $deleteUrl, ['_token' => $deleteToken, 'action' => 'delete']);
            self::assertResponseRedirects($editUrl);
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();
            self::assertSame(0, self::subtitleIntegerValue($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM video_subtitle_tracks WHERE video_id = :id',
                ['id' => $videoId],
            )));
            $client->request('GET', '/videos/'.$videoId.'/subtitles/'.$trackId);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

            $client->request('GET', $editUrl);
            $csrfToken = $client->getCrawler()
                ->filter('form[action="'.$uploadUrl.'"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($csrfToken);
            $client->request('POST', $uploadUrl, [
                '_token' => $csrfToken,
                'language_code' => 'de',
                'label' => 'Deutsch',
            ], [
                'subtitle_file' => new UploadedFile($updatedSubtitlePath, 'german.vtt', 'text/vtt', null, true),
            ]);
            self::assertResponseRedirects($editUrl);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $remainingSubtitle = $entityManager->getConnection()->fetchAssociative(
                'SELECT id, storage_object_id FROM video_subtitle_tracks WHERE video_id = :id',
                ['id' => $videoId],
            );
            $remainingSubtitle = self::subtitleRow($remainingSubtitle);
            $remainingTrackId = self::subtitleStringValue($remainingSubtitle, 'id');
            $remainingObjectId = self::subtitleStringValue($remainingSubtitle, 'storage_object_id');

            $client->request('GET', '/admin/videos');
            self::assertResponseIsSuccessful();
            $bulkToken = $client->getCrawler()
                ->filter('form[action="/admin/videos/bulk"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($bulkToken);
            $client->request('POST', '/admin/videos/bulk', [
                '_token' => $bulkToken,
                'ids' => [$videoId],
                'action' => 'delete',
                'confirm_delete' => '1',
            ]);
            self::assertResponseRedirects('/admin/videos');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();
            self::assertSame(0, self::subtitleIntegerValue($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM video_subtitle_tracks WHERE video_id = :id',
                ['id' => $videoId],
            )));
            self::assertSame(0, self::subtitleIntegerValue($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM videos WHERE id = :id',
                ['id' => $videoId],
            )));
            $client->request('GET', '/videos/'.$videoId.'/subtitles/'.$remainingTrackId);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            try {
                $storage->read($remainingObjectId, 67_108_864);
                self::fail('A subtitle file remained in private storage after its video was deleted.');
            } catch (\App\Platform\Hosting\PrivateStorageOperationFailed) {
                // Missing private objects are rejected by the storage adapter.
            }
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                foreach ($cleanupManager->getConnection()->fetchFirstColumn(
                    'SELECT storage_object_id FROM video_subtitle_tracks WHERE video_id = :id',
                    ['id' => $video->getId()],
                ) as $objectId) {
                    if (is_string($objectId)) {
                        $storage->delete($objectId);
                    }
                }
                $cleanupManager->getConnection()->executeStatement(
                    'DELETE FROM videos WHERE id = :id',
                    ['id' => $video->getId()],
                );
            }
            $storage->delete($videoObjectId);
            $this->removeUser($client, $email);
            if (is_file($firstSubtitlePath)) {
                unlink($firstSubtitlePath);
            }
            if (is_file($updatedSubtitlePath)) {
                unlink($updatedSubtitlePath);
            }
        }
    }

    /**
     * @param array<string, mixed>|false $row
     *
     * @return array<string, mixed>
     */
    private static function subtitleRow(array|false $row): array
    {
        if (!is_array($row)) {
            throw new \UnexpectedValueException('A subtitle database row was not found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private static function subtitleStringValue(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException('A subtitle database column did not contain a string.');
        }

        return $value;
    }

    private static function subtitleIntegerValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('A subtitle database value did not contain an integer.');
    }

    public function testAdminCanUploadPreviewAndRemoveSeriesArtwork(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $token = bin2hex(random_bytes(8));
        $email = 'series-artwork-admin-'.$token.'@example.test';
        $password = 'Series-Artwork-Admin-'.$token.'-K8!';
        $series = new ContentSeries(
            'Series artwork '.$token,
            'series-artwork-'.$token,
            'Series artwork upload integration test.',
            PublishingStatus::Draft,
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        $storage = $client->getContainer()->get(PrivateObjectStorageInterface::class);
        self::assertInstanceOf(PrivateObjectStorageInterface::class, $storage);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'arcanorum-series-artwork-');
        self::assertIsString($temporaryPath);
        self::assertSame(strlen($png), file_put_contents($temporaryPath, $png));
        $entityManager->persist($series);
        $entityManager->flush();

        try {
            $artworkPath = '/admin/series/'.$series->getId().'/artwork';
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

            $client->request('POST', $artworkPath, [
                '_token' => 'invalid-token',
                'action' => 'upload',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('GET', '/admin/series/'.$series->getId().'/edit');
            self::assertResponseIsSuccessful();
            $csrfToken = $client->getCrawler()
                ->filter('form[action="'.$artworkPath.'"] input[name="_token"]')
                ->eq(0)
                ->attr('value');
            self::assertIsString($csrfToken);
            $client->request(
                'POST',
                $artworkPath,
                ['_token' => $csrfToken, 'action' => 'upload'],
                ['artwork_file' => new UploadedFile($temporaryPath, 'series-artwork.png', 'image/png', null, true)],
            );
            self::assertResponseRedirects('/admin/series/'.$series->getId().'/edit');
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();
            $storedSeries = $entityManager->getRepository(ContentSeries::class)->find($series->getId());
            self::assertInstanceOf(ContentSeries::class, $storedSeries);
            self::assertNotNull($storedSeries->getArtworkObjectId());

            $client->request('GET', '/series/'.$series->getSlug().'/artwork');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

            $client->request('GET', $artworkPath);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'image/png');
            $previewCacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('private', $previewCacheControl);
            self::assertStringContainsString('no-store', $previewCacheControl);
            self::assertSame($png, $client->getResponse()->getContent());

            $client->request('GET', '/admin/series/'.$series->getId().'/edit');
            $csrfToken = $client->getCrawler()
                ->filter('form[action="'.$artworkPath.'"] input[name="_token"]')
                ->eq(1)
                ->attr('value');
            self::assertIsString($csrfToken);
            $client->request('POST', $artworkPath, [
                '_token' => $csrfToken,
                'action' => 'remove',
            ]);
            self::assertResponseRedirects('/admin/series/'.$series->getId().'/edit');
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();
            $storedSeries = $entityManager->getRepository(ContentSeries::class)->find($series->getId());
            self::assertInstanceOf(ContentSeries::class, $storedSeries);
            self::assertNull($storedSeries->getArtworkObjectId());

            $client->request('GET', $artworkPath);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                $artworkObjectId = $cleanupManager->getConnection()->fetchOne(
                    'SELECT artwork_object_id FROM content_series WHERE id = :id',
                    ['id' => $series->getId()],
                );
                if (is_string($artworkObjectId)) {
                    $storage->delete($artworkObjectId);
                }
                $cleanupManager->getConnection()->executeStatement(
                    'DELETE FROM content_series WHERE id = :id',
                    ['id' => $series->getId()],
                );
            }
            $this->removeUser($client, $email);
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function testAdminCanCreateCategoryAndDuplicateNamesAreRejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $suffix = bin2hex(random_bytes(6));
        $categoryName = 'Category admin '.$suffix;
        $parentCategoryName = 'Parent category '.$suffix;
        $categorySlug = 'category-admin-'.$suffix;
        $categoryFields = [
            'name' => $categoryName,
            'type' => 'news',
            'slug' => $categorySlug,
            'description' => 'News about local culture.',
            'meta_title' => 'Local culture news',
            'meta_description' => 'Recent news about culture in the region.',
            'meta_keywords' => 'culture, local news',
            'sort_order' => '17',
            'parent_id' => '',
        ];
        $email = 'category-admin-'.bin2hex(random_bytes(6)).'@example.test';
        $password = 'Category-Admin-'.bin2hex(random_bytes(6)).'-A9!';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $parentCategory = null;
        $usedArticle = null;

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $parentCategory = new ContentCategory($parentCategoryName, ContentCategoryType::News);
            $parentCategory->updateTaxonomy('parent-category-'.$suffix, '', null, null, null, 0);
            $entityManager->persist($parentCategory);
            $entityManager->flush();
            $categoryFields['parent_id'] = $parentCategory->getId();

            $client->request('GET', '/admin/categories/new?type=news');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            self::assertNotSame('', $token);
            self::assertSelectorExists('select[name="parent_id"] option[value="'.$parentCategory->getId().'"]');

            $client->request('POST', '/admin/categories/new', [
                '_token' => 'invalid',
            ] + $categoryFields);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertSame(0, $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_categories WHERE name = :name',
                ['name' => $categoryName],
            ));

            $client->request('POST', '/admin/categories/new', [
                '_token' => $token,
            ] + $categoryFields);
            self::assertResponseRedirects('/admin/categories?type=news');
            $client->followRedirect();
            self::assertSelectorTextContains('.flash-message.is-success', 'Kategorie wurde angelegt.');
            self::assertSame(1, $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_categories WHERE name = :name AND content_type = :type',
                ['name' => $categoryName, 'type' => 'news'],
            ));
            $createdCategory = $entityManager->getRepository(ContentCategory::class)->findOneBy(['name' => $categoryName]);
            self::assertInstanceOf(ContentCategory::class, $createdCategory);
            self::assertSame($categorySlug, $createdCategory->getSlug());
            self::assertSame('News about local culture.', $createdCategory->getDescription());
            self::assertSame('Local culture news', $createdCategory->getMetaTitle());
            self::assertSame('Recent news about culture in the region.', $createdCategory->getMetaDescription());
            self::assertSame('culture, local news', $createdCategory->getMetaKeywords());
            self::assertSame(17, $createdCategory->getSortOrder());
            $createdParent = $createdCategory->getParent();
            self::assertNotNull($createdParent);
            self::assertSame($parentCategory->getId(), $createdParent->getId());

            $client->request('GET', '/admin/categories/'.$createdCategory->getId().'/edit');
            self::assertResponseIsSuccessful();
            $editToken = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            $invalidParentFields = $categoryFields;
            $invalidParentFields['parent_id'] = $createdCategory->getId();
            $client->request('POST', '/admin/categories/'.$createdCategory->getId().'/edit', [
                '_token' => $editToken,
            ] + $invalidParentFields);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('[role="alert"]', 'Die übergeordnete Kategorie ist ungültig.');
            $createdParentAfterInvalidUpdate = $createdCategory->getParent();
            self::assertNotNull($createdParentAfterInvalidUpdate);
            self::assertSame($parentCategory->getId(), $createdParentAfterInvalidUpdate->getId());

            $client->request('GET', '/admin/categories/new?type=news');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/categories/new', [
                '_token' => $token,
            ] + $categoryFields);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('[role="alert"]', 'Diese Kategorie gibt es in diesem Inhaltsbereich bereits.');
            self::assertSame(1, $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_categories WHERE name = :name AND content_type = :type',
                ['name' => $categoryName, 'type' => 'news'],
            ));

            $duplicateSlugFields = $categoryFields;
            $duplicateSlugFields['name'] = 'Another category '.$suffix;
            $client->request('GET', '/admin/categories/new?type=news');
            self::assertResponseIsSuccessful();
            $slugToken = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/categories/new?type=news', [
                '_token' => $slugToken,
            ] + $duplicateSlugFields);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('[role="alert"]', 'Dieser Kategorien-Slug wird in diesem Inhaltsbereich bereits verwendet.');
            self::assertSame(1, $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_categories WHERE content_type = :type AND slug = :slug',
                ['type' => 'news', 'slug' => $categorySlug],
            ));

            $client->request('POST', '/admin/categories/'.$parentCategory->getId().'/delete', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $usedArticle = new NewsArticle(
                'Used category article '.$suffix,
                'category-delete-usage-'.$suffix,
                $categoryName,
                null,
                'This article prevents its category from being deleted.',
                PublishingStatus::Draft,
                false,
                true,
            );
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->persist($usedArticle);
            $entityManager->flush();

            $parentDeleteToken = $this->categoryDeleteToken($client, $parentCategory->getId());
            $client->request('POST', '/admin/categories/'.$parentCategory->getId().'/delete', ['_token' => $parentDeleteToken]);
            self::assertResponseRedirects('/admin/categories?type=news');
            $client->followRedirect();
            self::assertSelectorTextContains('.flash-message.is-error', 'Die Kategorie wird noch verwendet oder enthält Unterkategorien und kann nicht gelöscht werden.');
            self::assertSame(1, $this->integer($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_categories WHERE id = :id',
                ['id' => $parentCategory->getId()],
            )));

            $usedCategoryDeleteToken = $this->categoryDeleteToken($client, $createdCategory->getId());
            $client->request('POST', '/admin/categories/'.$createdCategory->getId().'/delete', ['_token' => $usedCategoryDeleteToken]);
            self::assertResponseRedirects('/admin/categories?type=news');
            $client->followRedirect();
            self::assertSelectorTextContains('.flash-message.is-error', 'Die Kategorie wird noch verwendet oder enthält Unterkategorien und kann nicht gelöscht werden.');
            self::assertSame(1, $this->integer($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_categories WHERE id = :id',
                ['id' => $createdCategory->getId()],
            )));

            $entityManager->getConnection()->delete('news_articles', ['id' => $usedArticle->getId()]);
            $client->request('GET', '/admin/categories?type=news');
            self::assertResponseIsSuccessful();
            $categoryDeleteForm = $client->getCrawler()->filter('form[action="/admin/categories/'.$createdCategory->getId().'/delete"]');
            self::assertSame(1, $categoryDeleteForm->count());
            $categoryDeleteToken = (string) $categoryDeleteForm->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/categories/'.$createdCategory->getId().'/delete', ['_token' => $categoryDeleteToken]);
            self::assertResponseRedirects('/admin/categories?type=news');
            self::assertSame(0, $this->integer($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_categories WHERE id = :id',
                ['id' => $createdCategory->getId()],
            )));

            $client->request('GET', '/admin/categories?type=news');
            self::assertResponseIsSuccessful();
            $parentDeleteForm = $client->getCrawler()->filter('form[action="/admin/categories/'.$parentCategory->getId().'/delete"]');
            self::assertSame(1, $parentDeleteForm->count());
            $parentDeleteToken = (string) $parentDeleteForm->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/categories/'.$parentCategory->getId().'/delete', ['_token' => $parentDeleteToken]);
            self::assertResponseRedirects('/admin/categories?type=news');
            self::assertSame(0, $this->integer($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_categories WHERE id = :id',
                ['id' => $parentCategory->getId()],
            )));
        } finally {
            static::ensureKernelShutdown();
            $cleanupClient = static::createClient();
            $cleanupEntityManager = $cleanupClient->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $cleanupEntityManager);
            $connection = $cleanupEntityManager->getConnection();
            if ($usedArticle instanceof NewsArticle) {
                $connection->delete('news_articles', ['id' => $usedArticle->getId()]);
            }
            $connection->executeStatement(
                'DELETE FROM content_categories WHERE name = :name',
                ['name' => $categoryName],
            );
            $connection->executeStatement(
                'DELETE FROM content_categories WHERE name = :name',
                ['name' => $parentCategoryName],
            );
            $connection->executeStatement(
                'DELETE FROM admin_activity_entries WHERE actor_id = (SELECT id FROM admin_users WHERE email = :email)',
                ['email' => $email],
            );
            $this->removeUser($cleanupClient, $email);
            static::ensureKernelShutdown();
        }
    }

    public function testAdminCanUploadAndRemoveVideoCategoryArtworkWithCsrfAndPublicValidation(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $token = bin2hex(random_bytes(8));
        $email = 'video-category-artwork-'.$token.'@example.test';
        $password = 'Video-Category-Artwork-'.$token.'-K8!';
        $category = new ContentCategory('Artwork category '.$token, ContentCategoryType::Video);
        $category->updateTaxonomy('artwork-category-'.$token, '', null, null, null, 0);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $storage = $client->getContainer()->get(PrivateObjectStorageInterface::class);
        self::assertInstanceOf(PrivateObjectStorageInterface::class, $storage);
        $png = self::categoryArtworkPng();
        $temporaryPath = tempnam(sys_get_temp_dir(), 'arcanorum-video-category-artwork-');
        self::assertIsString($temporaryPath);
        self::assertSame(strlen($png), file_put_contents($temporaryPath, $png));
        $entityManager->persist($category);
        $entityManager->flush();
        $artworkPath = '/admin/categories/'.$category->getId().'/artwork';
        $editPath = '/admin/categories/'.$category->getId().'/edit';

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();
            self::assertResponseIsSuccessful();

            $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            $categorySaveToken = $client->getCrawler()->filter('input[name="_token"]')->eq(0)->attr('value');
            self::assertIsString($categorySaveToken);
            $client->request('POST', $editPath, [
                '_token' => $categorySaveToken,
                'name' => $category->getName(),
                'slug' => $category->getSlug(),
                'description' => '',
                'meta_title' => '',
                'meta_description' => '',
                'meta_keywords' => '',
                'sort_order' => '0',
                'parent_id' => '',
                'featured' => '1',
            ]);
            self::assertResponseRedirects('/admin/categories?type=video');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();
            $featuredCategory = $entityManager->getRepository(ContentCategory::class)->find($category->getId());
            self::assertInstanceOf(ContentCategory::class, $featuredCategory);
            self::assertTrue($featuredCategory->isFeatured());

            $client->request('POST', $artworkPath, [
                '_token' => 'invalid-token',
                'action' => 'upload',
            ], [
                'artwork' => new UploadedFile($temporaryPath, 'category.png', 'image/png', null, true),
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            $csrfToken = $client->getCrawler()
                ->filter('form[action="'.$artworkPath.'"] input[name="_token"]')
                ->eq(0)
                ->attr('value');
            self::assertIsString($csrfToken);

            $client->request('POST', $artworkPath, [
                '_token' => $csrfToken,
                'action' => 'upload',
            ], [
                'artwork' => new UploadedFile($temporaryPath, 'category.png', 'image/png', null, true),
            ]);
            self::assertResponseRedirects($editPath);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();
            $storedCategory = $entityManager->getRepository(ContentCategory::class)->find($category->getId());
            self::assertInstanceOf(ContentCategory::class, $storedCategory);
            $storedArtworkObjectId = $storedCategory->getArtworkObjectId();
            self::assertIsString($storedArtworkObjectId);
            self::assertSame('image/png', $storedCategory->getArtworkMimeType());
            $storedArtworkSha256 = $storedCategory->getArtworkSha256();
            self::assertIsString($storedArtworkSha256);
            self::assertSame(hash('sha256', $storage->read($storedArtworkObjectId, 1048576)), $storedArtworkSha256);
            $firstArtworkObjectId = $storedArtworkObjectId;

            $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            $replacementToken = $client->getCrawler()
                ->filter('form[action="'.$artworkPath.'"] input[name="_token"]')
                ->eq(0)
                ->attr('value');
            self::assertIsString($replacementToken);
            $client->request('POST', $artworkPath, [
                '_token' => $replacementToken,
                'action' => 'upload',
            ], [
                'artwork' => new UploadedFile($temporaryPath, 'category-replacement.png', 'image/png', null, true),
            ]);
            self::assertResponseRedirects($editPath);
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();
            $storedCategory = $entityManager->getRepository(ContentCategory::class)->find($category->getId());
            self::assertInstanceOf(ContentCategory::class, $storedCategory);
            $replacementArtworkObjectId = $storedCategory->getArtworkObjectId();
            self::assertIsString($replacementArtworkObjectId);
            self::assertNotSame($firstArtworkObjectId, $replacementArtworkObjectId);
            try {
                $storage->read($firstArtworkObjectId, 1048576);
                self::fail('Replacing category artwork must remove the superseded private object.');
            } catch (\App\Platform\Hosting\PrivateStorageOperationFailed) {
                self::assertNotNull($storedCategory->getArtworkObjectId());
            }

            $client->request('GET', '/videos/category/'.$category->getSlug().'/artwork');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'image/png');
            self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
            self::assertResponseHeaderSame('Cross-Origin-Resource-Policy', 'same-origin');
            $publicImage = $client->getResponse()->getContent();
            self::assertIsString($publicImage);
            $imageInfo = getimagesizefromstring($publicImage);
            self::assertIsArray($imageInfo);
            self::assertLessThanOrEqual(320, $imageInfo[0]);
            self::assertLessThanOrEqual(180, $imageInfo[1]);

            $entityManager->getConnection()->executeStatement(
                'UPDATE content_categories SET artwork_sha256 = :sha256 WHERE id = :id',
                ['sha256' => str_repeat('0', 64), 'id' => $category->getId()],
            );
            $client->request('GET', '/videos/category/'.$category->getSlug().'/artwork');
            self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->getConnection()->executeStatement(
                'UPDATE content_categories SET artwork_sha256 = :sha256 WHERE id = :id',
                ['sha256' => hash('sha256', $storage->read($replacementArtworkObjectId, 1048576)), 'id' => $category->getId()],
            );

            $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            $removeToken = $client->getCrawler()
                ->filter('form[action="'.$artworkPath.'"] input[name="_token"]')
                ->eq(1)
                ->attr('value');
            self::assertIsString($removeToken);
            $client->request('POST', $artworkPath, [
                '_token' => $removeToken,
                'action' => 'remove',
            ]);
            self::assertResponseRedirects($editPath);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();
            $storedCategory = $entityManager->getRepository(ContentCategory::class)->find($category->getId());
            self::assertInstanceOf(ContentCategory::class, $storedCategory);
            self::assertNull($storedCategory->getArtworkObjectId());
            self::assertNull($storedCategory->getArtworkSha256());
            $client->request('GET', '/videos/category/'.$category->getSlug().'/artwork');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                $objectId = $cleanupManager->getConnection()->fetchOne(
                    'SELECT artwork_object_id FROM content_categories WHERE id = :id',
                    ['id' => $category->getId()],
                );
                if (is_string($objectId)) {
                    $storage->delete($objectId);
                }
                $cleanupManager->getConnection()->executeStatement(
                    'DELETE FROM content_categories WHERE id = :id',
                    ['id' => $category->getId()],
                );
                $cleanupManager->getConnection()->executeStatement(
                    'DELETE FROM admin_activity_entries WHERE actor_id = (SELECT id FROM admin_users WHERE email = :email)',
                    ['email' => $email],
                );
            }
            $this->removeUser($client, $email);
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function testAdminCanManageCommentFilterTermsAndSeeMatchedComments(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $token = bin2hex(random_bytes(8));
        $email = 'word-filter-admin-'.$token.'@example.test';
        $password = 'Word-Filter-Admin-'.$token.'-K8!';
        $termData = CommentWordFilterNormalizer::parseTerm('Spoiler '.$token);
        $suffix = bin2hex(random_bytes(8));
        $slug = 'word-filter-admin-'.$suffix;
        $article = new NewsArticle(
            'Word filter admin '.$suffix,
            $slug,
            'Community',
            null,
            'Admin word filter integration test.',
            PublishingStatus::Published,
            false,
            true,
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);

            $client->request('POST', '/admin/comments/word-filter', [
                '_token' => 'invalid-token',
                'term' => $termData['term'],
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('GET', '/admin/comments/word-filter');
            self::assertResponseIsSuccessful();
            $invalidActionToken = $client->getCrawler()
                ->filter('form[action="/admin/comments/word-filter"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($invalidActionToken);
            $client->request('POST', '/admin/comments/word-filter', [
                '_token' => $invalidActionToken,
                'term' => $termData['term'],
                'action' => 'invalid',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('.flash-message.is-error', 'gültige Filteraktion');

            $client->request('GET', '/admin/comments/word-filter');
            self::assertResponseIsSuccessful();
            $client->submitForm('Wortfilter hinzufügen', ['term' => $termData['term'], 'action' => 'review']);
            self::assertResponseRedirects('/admin/comments/word-filter');
            $client->followRedirect();
            self::assertSelectorTextContains('.flash-message.is-success', 'Filterwort wurde hinzugefügt.');

            $termRepository = $entityManager->getRepository(CommentFilterTerm::class);
            self::assertInstanceOf(CommentFilterTermRepository::class, $termRepository);
            $term = $termRepository->findByNormalizedTermAndAction(
                $termData['normalized'],
                CommentFilterAction::Review,
            );
            self::assertInstanceOf(CommentFilterTerm::class, $term);
            self::assertSame(CommentFilterAction::Review, $term->getAction());

            $client->request('GET', '/admin/comments/word-filter');
            self::assertResponseIsSuccessful();
            $client->submitForm('Wortfilter hinzufügen', [
                'term' => mb_strtolower($termData['term'], 'UTF-8'),
                'action' => 'review',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('.flash-message.is-error', 'bereits mit dieser Aktion vorhanden');

            $client->request('GET', '/admin/comments/word-filter');
            self::assertResponseIsSuccessful();
            $client->submitForm('Wortfilter hinzufügen', ['term' => $termData['term'], 'action' => 'censor']);
            self::assertResponseRedirects('/admin/comments/word-filter');
            $client->followRedirect();
            $censorTerm = $termRepository->findByNormalizedTermAndAction(
                $termData['normalized'],
                CommentFilterAction::Censor,
            );
            self::assertInstanceOf(CommentFilterTerm::class, $censorTerm);
            self::assertSelectorTextContains('table', 'Treffer durch *** ersetzen');

            $entityManager->persist($article);
            $entityManager->flush();
            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            $commentToken = $client->getCrawler()->filter('form.comment-form input[name="_token"]')->attr('value');
            self::assertIsString($commentToken);

            $client->request('POST', '/comments/news/'.$slug, [
                '_token' => $commentToken,
                'author_name' => 'Filtered admin test visitor',
                'body' => 'This includes '.mb_strtoupper($termData['term'], 'UTF-8').' in the body.',
                'website' => '',
            ]);
            self::assertResponseRedirects('/news/'.$slug.'#comments');

            $commentRepository = $entityManager->getRepository(ContentComment::class);
            self::assertInstanceOf(ContentCommentRepository::class, $commentRepository);
            $comment = $commentRepository->findOneBy(['contentId' => $article->getId()]);
            self::assertInstanceOf(ContentComment::class, $comment);
            self::assertSame($termData['term'], $comment->getFilteredByTerm());
            self::assertSame(CommentStatus::Pending, $comment->getStatus());

            $client->request('GET', '/admin/comments?status=pending');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('table', 'Wortfilter: '.$termData['term']);

            $client->request('GET', '/admin/comments/word-filter');
            self::assertResponseIsSuccessful();
            $deleteToken = $client->getCrawler()
                ->filter(sprintf('form[action="/admin/comments/word-filter/%s/delete"] input[name="_token"]', $term->getId()))
                ->attr('value');
            self::assertIsString($deleteToken);
            $client->request('POST', '/admin/comments/word-filter/'.$term->getId().'/delete', ['_token' => $deleteToken]);
            self::assertResponseRedirects('/admin/comments/word-filter');
            $client->followRedirect();
            self::assertSelectorTextContains('.flash-message.is-success', 'Filterwort wurde entfernt.');
            self::assertNull($termRepository->findByNormalizedTermAndAction(
                $termData['normalized'],
                CommentFilterAction::Review,
            ));
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_comments WHERE content_id = :id', ['id' => $article->getId()]);
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $slug]);
            $connection->executeStatement(
                'DELETE FROM comment_filter_terms WHERE normalized_term = :term',
                ['term' => $termData['normalized']],
            );
            $this->removeUser($client, $email);
        }
    }

    public function testVideoModeratorCanApproveVideoSubmission(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'video-submission-admin-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';
        $suffix = bin2hex(random_bytes(6));
        $objectId = str_repeat('c', 64);
        $videoBytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64);
        $storage = $client->getContainer()->get(PrivateObjectStorageInterface::class);
        self::assertInstanceOf(PrivateObjectStorageInterface::class, $storage);
        $storage->write($objectId, $videoBytes);

        $video = new Video(
            'Visitor video '.$suffix,
            'A private submission waiting for review.',
            null,
            VideoSourceType::File,
            '',
            PublishingStatus::Draft,
            false,
            false,
        );
        $video->attachUploadedMedia($objectId, 'video/mp4', strlen($videoBytes));
        $submission = new VideoSubmission($video);

        try {
            $this->persistUser($client, $email, $password, [AdminRole::VideoModerator->value]);
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->persist($video);
            $entityManager->persist($submission);
            $entityManager->flush();

            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');
            $client->request('GET', '/admin');
            self::assertResponseRedirects('/admin/video-submissions');
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $client->request('GET', '/admin/video-submissions');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('table', $video->getTitle());
            self::assertSelectorExists('video[data-testid="video-submission-preview"]');

            $token = $client->getCrawler()
                ->filter('form.video-submission-actions input[name="_token"]')
                ->first()
                ->attr('value');
            self::assertIsString($token);
            $client->request('GET', '/admin/video-submissions/'.$submission->getId().'/preview');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'video/mp4');
            self::assertResponseHeaderSame('Cross-Origin-Resource-Policy', 'same-origin');
            self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
            $previewCacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('private', $previewCacheControl);
            self::assertStringContainsString('no-store', $previewCacheControl);
            self::assertSame($videoBytes, $client->getResponse()->getContent());

            $previewPath = '/admin/video-submissions/'.$submission->getId().'/preview';
            $client->request('GET', $previewPath, [], [], ['HTTP_RANGE' => 'bytes=2-5']);
            self::assertResponseStatusCodeSame(Response::HTTP_PARTIAL_CONTENT);
            self::assertResponseHeaderSame('Content-Range', 'bytes 2-5/'.strlen($videoBytes));
            self::assertResponseHeaderSame('Content-Length', '4');
            self::assertSame(substr($videoBytes, 2, 4), $client->getResponse()->getContent());

            $client->request('GET', $previewPath, [], [], ['HTTP_RANGE' => 'bytes=-4']);
            self::assertResponseStatusCodeSame(Response::HTTP_PARTIAL_CONTENT);
            self::assertResponseHeaderSame(
                'Content-Range',
                sprintf('bytes %d-%d/%d', strlen($videoBytes) - 4, strlen($videoBytes) - 1, strlen($videoBytes)),
            );
            self::assertSame(substr($videoBytes, -4), $client->getResponse()->getContent());

            foreach (['bytes=2-', 'bytes=2-999999'] as $openEndedRange) {
                $client->request('GET', $previewPath, [], [], ['HTTP_RANGE' => $openEndedRange]);
                self::assertResponseStatusCodeSame(Response::HTTP_PARTIAL_CONTENT);
                self::assertResponseHeaderSame(
                    'Content-Range',
                    'bytes 2-'.(strlen($videoBytes) - 1).'/'.strlen($videoBytes),
                );
                self::assertSame(substr($videoBytes, 2), $client->getResponse()->getContent());
            }

            $invalidRanges = [
                'bytes=999999-',
                'items=0-1',
                'bytes=3-2',
                'bytes=-0',
                'bytes=0-1,4-5',
                'bytes=-',
                'bytes='.str_repeat('9', 101),
            ];
            foreach ($invalidRanges as $invalidRange) {
                $client->request('GET', $previewPath, [], [], ['HTTP_RANGE' => $invalidRange]);
                self::assertResponseStatusCodeSame(Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, $invalidRange);
                self::assertResponseHeaderSame('Accept-Ranges', 'bytes');
                self::assertResponseHeaderSame('Content-Range', 'bytes */'.strlen($videoBytes));
                $invalidRangeCacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
                self::assertStringContainsString('private', $invalidRangeCacheControl);
                self::assertStringContainsString('no-store', $invalidRangeCacheControl);
                self::assertSame('', $client->getResponse()->getContent());
            }

            $storage->delete($objectId);
            $client->request('GET', $previewPath);
            self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
            self::assertResponseHeaderSame('Retry-After', '60');
            $missingMediaCacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('private', $missingMediaCacheControl);
            self::assertStringContainsString('no-store', $missingMediaCacheControl);
            self::assertSame('', $client->getResponse()->getContent());
            $storage->write($objectId, $videoBytes);

            foreach ([str_repeat('x', strlen($videoBytes)), substr($videoBytes, 1)] as $tamperedBytes) {
                $storage->delete($objectId);
                $storage->write($objectId, $tamperedBytes);
                $client->request('GET', $previewPath);
                self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
                $invalidMediaCacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
                self::assertStringContainsString('private', $invalidMediaCacheControl);
                self::assertStringContainsString('no-store', $invalidMediaCacheControl);
                self::assertSame('', $client->getResponse()->getContent());
            }
            $storage->delete($objectId);
            $storage->write($objectId, $videoBytes);

            $client->request('POST', '/admin/video-submissions/'.$submission->getId().'/review', [
                '_token' => $token,
                'action' => 'approve',
            ]);
            self::assertResponseRedirects('/admin/video-submissions');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
            $storedSubmission = $entityManager->getRepository(VideoSubmission::class)->find($submission->getId());
            self::assertInstanceOf(Video::class, $storedVideo);
            self::assertInstanceOf(VideoSubmission::class, $storedSubmission);
            self::assertSame(PublishingStatus::Published, $storedVideo->getStatus());
            self::assertSame(VideoSubmissionStatus::Approved, $storedSubmission->getStatus());
            self::assertNotNull($storedSubmission->getReviewedAt());

            $client->request('GET', '/admin/video-submissions/'.bin2hex(random_bytes(16)).'/preview');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            $client->request('GET', $previewPath);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                $storedSubmission = $cleanupManager->getRepository(VideoSubmission::class)->find($submission->getId());
                if ($storedSubmission instanceof VideoSubmission) {
                    $cleanupManager->remove($storedSubmission);
                }
                $storedVideo = $cleanupManager->getRepository(Video::class)->find($video->getId());
                if ($storedVideo instanceof Video) {
                    $cleanupManager->remove($storedVideo);
                }
                $cleanupManager->flush();
            }
            $storage->delete($objectId);
            $this->removeUser($client, $email);
        }
    }

    public function testAdministratorCanHideAReportedVideo(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'video-report-admin-'.bin2hex(random_bytes(4)).'@example.test';
        $password = 'Test-only-long-passphrase-456!';
        $suffix = bin2hex(random_bytes(6));
        $video = new Video(
            'Reported video '.$suffix,
            'Report moderation test',
            null,
            VideoSourceType::Url,
            'https://video.example.test/report-'.$suffix,
            PublishingStatus::Published,
            false,
            true,
        );
        $report = new VideoReport($video, VideoReportReason::Inappropriate, 'Regression report details');

        try {
            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->persist($video);
            $entityManager->persist($report);
            $entityManager->flush();

            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();
            $client->request('GET', '/admin/video-reports');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('table', $video->getTitle());

            $token = $client->getCrawler()
                ->filter('form.video-report-actions input[name="_token"]')
                ->first()
                ->attr('value');
            self::assertIsString($token);
            $client->request('POST', '/admin/video-reports/'.$report->getId().'/moderate', [
                '_token' => $token,
                'action' => 'hide',
            ]);
            self::assertResponseRedirects('/admin/video-reports');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
            $storedReport = $entityManager->getRepository(VideoReport::class)->find($report->getId());
            self::assertInstanceOf(Video::class, $storedVideo);
            self::assertInstanceOf(VideoReport::class, $storedReport);
            self::assertSame(PublishingStatus::Draft, $storedVideo->getStatus());
            self::assertSame(VideoReportStatus::VideoHidden, $storedReport->getStatus());
            self::assertNotNull($storedReport->getReviewedAt());
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                $storedReport = $cleanupManager->getRepository(VideoReport::class)->find($report->getId());
                if ($storedReport instanceof VideoReport) {
                    $cleanupManager->remove($storedReport);
                }
                $storedVideo = $cleanupManager->getRepository(Video::class)->find($video->getId());
                if ($storedVideo instanceof Video) {
                    $cleanupManager->remove($storedVideo);
                }
                $cleanupManager->flush();
            }
            $this->removeUser($client, $email);
        }
    }

    public function testContactFlowValidatesInputProtectsInboxAndEscapesMessages(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $token = bin2hex(random_bytes(8));
        $senderEmail = 'contact-'.$token.'@example.test';
        $adminEmail = 'contact-admin-'.$token.'@example.test';
        $adminPassword = 'Contact-Admin-'.$token.'-P8!';
        $messageBody = 'Bitte <script>alert(1)</script> prüfen.';

        try {
            $client->request('GET', '/contact');
            self::assertResponseIsSuccessful();
            self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('private', $cacheControl);
            self::assertStringContainsString('no-store', $cacheControl);

            $honeypotToken = $client->getCrawler()
                ->filter('form[action="/contact"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($honeypotToken);
            $client->submitForm('Nachricht senden', [
                '_token' => $honeypotToken,
                'website' => 'https://spam.example.test',
                'name' => 'Bot',
                'email' => $senderEmail,
                'subject' => 'Werbung',
                'body' => 'Diese Nachricht muss verworfen werden.',
            ]);
            self::assertResponseRedirects('/contact');
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            self::assertNull($entityManager->getRepository(ContactMessage::class)->findOneBy(['email' => $senderEmail]));

            $client->request('POST', '/contact', [
                '_token' => 'invalid-token',
                'name' => 'Besucher',
                'email' => $senderEmail,
                'subject' => 'Frage',
                'body' => 'Eine Nachricht',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('GET', '/contact');
            $tokenValue = $client->getCrawler()
                ->filter('form[action="/contact"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($tokenValue);
            $client->submitForm('Nachricht senden', [
                '_token' => $tokenValue,
                'name' => 'Besucher Test',
                'email' => 'ungueltig',
                'subject' => 'Frage',
                'body' => 'Eine Nachricht mit ausreichendem Inhalt.',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('[role="alert"]', 'gültige E-Mail-Adresse');

            $client->request('GET', '/contact');
            $tokenValue = $client->getCrawler()
                ->filter('form[action="/contact"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($tokenValue);
            $client->submitForm('Nachricht senden', [
                '_token' => $tokenValue,
                'name' => 'Besucher Test',
                'email' => $senderEmail,
                'subject' => 'Hinweis zur Seite',
                'body' => $messageBody,
            ]);
            self::assertResponseRedirects('/contact');
            $client->followRedirect();
            self::assertSelectorTextContains('[role="status"]', 'Nachricht wurde zur Bearbeitung gespeichert');

            $client->request('GET', '/contact');
            $tokenValue = $client->getCrawler()
                ->filter('form[action="/contact"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($tokenValue);
            $client->submitForm('Nachricht senden', [
                '_token' => $tokenValue,
                'name' => 'Besucher Test',
                'email' => $senderEmail,
                'subject' => 'Noch eine Frage',
                'body' => 'Eine weitere Nachricht.',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

            $client->request('GET', '/admin/contact-messages');
            self::assertResponseRedirects('/admin/login');
            $this->persistUser($client, $adminEmail, $adminPassword, ['ROLE_ADMIN']);
            $this->submitLogin($client, $adminEmail, $adminPassword);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();
            self::assertResponseIsSuccessful();

            $client->request('GET', '/admin/contact-messages');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Kontaktpostfach');
            self::assertSelectorTextContains('article', 'Hinweis zur Seite');
            self::assertStringContainsString('&lt;script&gt;', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('<script>alert(1)</script>', (string) $client->getResponse()->getContent());

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $contactMessage = $entityManager->getRepository(ContactMessage::class)->findOneBy(['email' => $senderEmail]);
            self::assertInstanceOf(ContactMessage::class, $contactMessage);
            self::assertSame(ContactMessageStatus::Pending, $contactMessage->getStatus());

            $closePath = '/admin/contact-messages/'.$contactMessage->getId().'/close';
            $csrfToken = $client->getCrawler()
                ->filter('form[action="'.$closePath.'"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($csrfToken);
            $client->request('POST', $closePath, ['_token' => $csrfToken]);
            self::assertResponseRedirects('/admin/contact-messages');
            $client->followRedirect();

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();
            $contactMessage = $entityManager->getRepository(ContactMessage::class)->findOneBy(['email' => $senderEmail]);
            self::assertInstanceOf(ContactMessage::class, $contactMessage);
            self::assertSame(ContactMessageStatus::Resolved, $contactMessage->getStatus());

            $deletePath = '/admin/contact-messages/'.$contactMessage->getId().'/delete';
            $csrfToken = $client->getCrawler()
                ->filter('form[action="'.$deletePath.'"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($csrfToken);
            $client->request('POST', $deletePath, ['_token' => $csrfToken]);
            self::assertResponseRedirects('/admin/contact-messages');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            self::assertNull($entityManager->getRepository(ContactMessage::class)->findOneBy(['email' => $senderEmail]));
        } finally {
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($entityManager instanceof EntityManagerInterface) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM contact_messages WHERE email = :email',
                    ['email' => $senderEmail],
                );
            }
            $this->removeUser($client, $adminEmail);
        }
    }

    public function testAdminCanSearchPaginateAndSafelyExportMemberDirectory(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $client->request('GET', '/admin/members');
        self::assertResponseRedirects('/admin/login');
        $client->request('GET', '/admin/members/export');
        self::assertResponseRedirects('/admin/login');
        $client->request('GET', '/admin/members/export-contacts');
        self::assertResponseRedirects('/admin/login');

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(6));
        $emailPrefix = 'admin-directory-'.$suffix.'-';
        $adminEmail = 'admin-directory-'.$suffix.'@example.test';
        $adminPassword = 'DirectoryAdmin!2026';
        $specialHandle = 'special-'.$suffix;
        $specialEmail = $emailPrefix.'0@example.test';

        try {
            $this->persistUser($client, $adminEmail, $adminPassword, ['ROLE_ADMIN']);

            for ($index = 0; $index < 27; ++$index) {
                $email = $emailPrefix.$index.'@example.test';
                $handle = 0 === $index ? $specialHandle : 'directory-'.$suffix.'-'.$index;
                $displayName = 0 === $index ? '<script>alert(1)</script>' : 'Mitglied '.$index;
                $member = new MemberAccount($email, $handle, $displayName);
                $entityManager->persist($member);
            }
            $entityManager->flush();

            $this->submitLogin($client, $adminEmail, $adminPassword);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();

            $client->request('GET', '/admin/members?'.http_build_query(['q' => 'directory-'.$suffix]));
            self::assertResponseIsSuccessful();
            self::assertSame(
                [self::CSP_REPORT_ONLY_POLICY],
                $client->getResponse()->headers->all(ContentSecurityPolicyReportOnlySubscriber::HEADER),
            );
            self::assertStringContainsString(
                'no-store',
                strtolower((string) $client->getResponse()->headers->get('Cache-Control')),
            );
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorTextContains('.panel-heading', '27 Treffer');
            self::assertCount(25, $client->getCrawler()->filter('tbody tr'));

            $client->request('GET', '/admin/members?'.http_build_query([
                'q' => 'directory-'.$suffix,
                'page' => '2',
            ]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.panel-heading', 'Seite 2 von 2');
            self::assertCount(2, $client->getCrawler()->filter('tbody tr'));

            $client->request('GET', '/admin/members?'.http_build_query([
                'q' => mb_strtoupper($specialHandle, 'UTF-8'),
            ]));
            self::assertResponseIsSuccessful();
            self::assertCount(1, $client->getCrawler()->filter('tbody tr'));
            $responseContent = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($specialEmail, $responseContent);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $responseContent);
            self::assertStringNotContainsString('<script>alert(1)</script>', $responseContent);

            $client->request('GET', '/admin/members/export?'.http_build_query([
                'q' => 'directory-'.$suffix,
            ]));
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));
            self::assertStringContainsString(
                'no-store',
                strtolower((string) $client->getResponse()->headers->get('Cache-Control')),
            );
            self::assertSame(
                [self::CSP_REPORT_ONLY_POLICY],
                $client->getResponse()->headers->all(ContentSecurityPolicyReportOnlySubscriber::HEADER),
            );
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
            self::assertSame(
                'attachment; filename="arcanorum-mitglieder.csv"',
                $client->getResponse()->headers->get('Content-Disposition'),
            );

            $client->request('GET', '/admin/members/export-contacts?'.http_build_query([
                'q' => 'directory-'.$suffix,
                'status' => 'active',
            ]));
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));
            self::assertSame(
                'attachment; filename="arcanorum-mitglieder-kontakte.csv"',
                $client->getResponse()->headers->get('Content-Disposition'),
            );
            self::assertStringContainsString(
                'no-store',
                strtolower((string) $client->getResponse()->headers->get('Cache-Control')),
            );
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        } finally {
            $this->removeUser($client, $adminEmail);
        }
    }

    public function testAdminCanChangeMemberEmailAndHandleWithCsrfAndDuplicateChecks(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $emailSender = new RecordingMemberSecurityEmailSender();
        $client->getContainer()->set(MemberSecurityEmailSenderInterface::class, $emailSender);
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(6));
        $memberEmail = 'member-identity-'.$suffix.'@example.test';
        $memberHandle = 'identity-'.$suffix;
        $conflictEmail = 'member-conflict-'.$suffix.'@example.test';
        $conflictHandle = 'conflict-'.$suffix;
        $adminEmail = 'admin-identity-'.$suffix.'@example.test';
        $adminPassword = 'DirectoryAdmin!2026';

        $member = new MemberAccount($memberEmail, $memberHandle, 'Identity member');
        $member->updateProfile('Identity member', '', true);
        $memberId = $member->getId();
        $conflict = new MemberAccount($conflictEmail, $conflictHandle, 'Conflicting member');
        $conflictId = $conflict->getId();
        $editUrl = '/admin/members/'.$memberId;
        $identityUrl = $editUrl.'/identity';

        try {
            $entityManager->persist($member);
            $entityManager->persist($conflict);
            $entityManager->flush();

            $client->request('POST', $identityUrl, [
                '_token' => 'invalid',
                'email' => 'changed@example.test',
                'handle' => 'changed-channel',
            ]);
            self::assertResponseRedirects('/admin/login');

            $this->persistUser($client, $adminEmail, $adminPassword, ['ROLE_ADMIN']);
            $this->submitLogin($client, $adminEmail, $adminPassword);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();

            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            self::assertSame(
                [self::CSP_REPORT_ONLY_POLICY],
                $client->getResponse()->headers->all(ContentSecurityPolicyReportOnlySubscriber::HEADER),
            );
            $identityForm = $client->getCrawler()->filter('form[action="'.$identityUrl.'"]');
            self::assertCount(1, $identityForm);
            $token = $identityForm->filter('input[name="_token"]')->attr('value');
            self::assertIsString($token);

            $client->request('POST', $identityUrl, [
                '_token' => 'invalid-token',
                'email' => 'changed@example.test',
                'handle' => 'changed-channel',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $verificationManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $verificationManager);
            $row = $verificationManager->getConnection()->fetchAssociative(
                'SELECT email, handle FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertSame($memberEmail, $row['email']);
            self::assertSame($memberHandle, $row['handle']);

            $client->request('GET', $editUrl);
            $token = $client->getCrawler()->filter('form[action="'.$identityUrl.'"] input[name="_token"]')->attr('value');
            self::assertIsString($token);
            $client->request('POST', $identityUrl, [
                '_token' => $token,
                'email' => $conflictEmail,
                'handle' => 'unique-'.$suffix,
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('[role="alert"]', 'bereits vergeben');

            $client->request('GET', $editUrl);
            $token = $client->getCrawler()->filter('form[action="'.$identityUrl.'"] input[name="_token"]')->attr('value');
            self::assertIsString($token);
            $client->request('POST', $identityUrl, [
                '_token' => $token,
                'email' => 'unique-'.$suffix.'@example.test',
                'handle' => $conflictHandle,
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('[role="alert"]', 'bereits vergeben');
            $verificationManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $verificationManager);
            $row = $verificationManager->getConnection()->fetchAssociative(
                'SELECT email, handle FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertSame($memberEmail, $row['email']);
            self::assertSame($memberHandle, $row['handle']);

            $client->request('GET', $editUrl);
            $token = $client->getCrawler()->filter('form[action="'.$identityUrl.'"] input[name="_token"]')->attr('value');
            self::assertIsString($token);
            $client->request('POST', $identityUrl, [
                '_token' => $token,
                'email' => "\xFF@example.test",
                'handle' => 'valid-'.$suffix,
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('[role="alert"]', 'UTF-8');

            $client->request('GET', $editUrl);
            $token = $client->getCrawler()->filter('form[action="'.$identityUrl.'"] input[name="_token"]')->attr('value');
            self::assertIsString($token);
            $client->request('POST', $identityUrl, [
                '_token' => $token,
                'email' => '  UPDATED-'.$suffix.'@Example.Test  ',
                'handle' => '  Updated-'.$suffix.'  ',
            ]);
            self::assertResponseRedirects($editUrl);

            $verificationManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $verificationManager);
            $row = $verificationManager->getConnection()->fetchAssociative(
                'SELECT email, handle FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertSame('updated-'.$suffix.'@example.test', $row['email']);
            self::assertSame('updated-'.$suffix, $row['handle']);

            $client->request('GET', '/kanal/updated-'.$suffix);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertCount(1, $emailSender->messages());
            self::assertSame(MemberEmailChallengePurpose::EmailVerification, $emailSender->messages()[0]['purpose']);
            $verificationToken = $emailSender->messages()[0]['token'];
            $verificationPath = '/konto/verifizieren/'.$verificationToken;
            $client->request('GET', $verificationPath);
            self::assertResponseIsSuccessful();
            $verificationCsrf = (string) $client->getCrawler()->filter('form[action="'.$verificationPath.'"] input[name="_token"]')->attr('value');
            $client->request('POST', $verificationPath, ['_token' => $verificationCsrf]);
            self::assertResponseRedirects('/konto/anmelden');

            $client->request('GET', '/kanal/updated-'.$suffix);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Identity member');
            $client->request('GET', '/kanal/'.$memberHandle);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                $cleanupManager->getConnection()->executeStatement(
                    'DELETE FROM member_accounts WHERE id IN (:member_id, :conflict_id)',
                    ['member_id' => $memberId, 'conflict_id' => $conflictId],
                );
            }
            $this->removeUser($client, $adminEmail);
        }
    }

    public function testAdminCanEditMemberProfileAndRejectsInvalidCsrf(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(6));
        $memberEmail = 'member-edit-'.$suffix.'@example.test';
        $memberHandle = 'editmember'.$suffix;
        $originalName = 'Original '.$suffix;
        $member = new MemberAccount($memberEmail, $memberHandle, $originalName);
        $memberId = $member->getId();
        $adminEmail = 'admin-edit-member-'.$suffix.'@example.test';
        $adminPassword = 'DirectoryAdmin!2026';
        $editUrl = '/admin/members/'.$memberId;

        try {
            $entityManager->persist($member);
            $entityManager->flush();

            $client->request('GET', $editUrl);
            self::assertResponseRedirects('/admin/login');

            $this->persistUser($client, $adminEmail, $adminPassword, ['ROLE_ADMIN']);
            $this->submitLogin($client, $adminEmail, $adminPassword);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();

            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString(
                'no-store',
                strtolower((string) $client->getResponse()->headers->get('Cache-Control')),
            );
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSame(
                [self::CSP_REPORT_ONLY_POLICY],
                $client->getResponse()->headers->all(ContentSecurityPolicyReportOnlySubscriber::HEADER),
            );
            self::assertSelectorExists('form.admin-form textarea[name="bio"]');
            $token = $client->getCrawler()->filter('form.admin-form:not([action]) input[name="_token"]')->attr('value');
            self::assertNotEmpty($token);

            $client->request('POST', $editUrl, [
                '_token' => 'invalid-token',
                'display_name' => 'Tampered profile',
                'bio' => 'Tampered bio',
                'public_profile' => '1',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $verificationManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $verificationManager);
            $row = $verificationManager->getConnection()->fetchAssociative(
                'SELECT display_name, bio, public_profile FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertSame($originalName, $row['display_name']);
            self::assertNull($row['bio']);
            self::assertFalse((bool) $row['public_profile']);

            $client->request('POST', $editUrl, [
                '_token' => $token,
                'display_name' => 'Updated '.$suffix,
                'bio' => 'Edited by admin',
                'public_profile' => '1',
            ]);
            self::assertResponseRedirects($editUrl);
            $verificationManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $verificationManager);
            $row = $verificationManager->getConnection()->fetchAssociative(
                'SELECT display_name, bio, public_profile FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertSame('Updated '.$suffix, $row['display_name']);
            self::assertSame('Edited by admin', $row['bio']);
            self::assertTrue((bool) $row['public_profile']);
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                $cleanupManager->getConnection()->executeStatement(
                    'DELETE FROM member_accounts WHERE id = :id',
                    ['id' => $memberId],
                );
            }
            $this->removeUser($client, $adminEmail);
        }
    }

    public function testAdminCanSuspendAndReactivateMemberWithCsrf(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(6));
        $member = new MemberAccount('member-status-'.$suffix.'@example.test', 'status'.$suffix, 'Status member');
        $memberId = $member->getId();
        $adminEmail = 'admin-member-status-'.$suffix.'@example.test';
        $adminPassword = 'DirectoryAdmin!2026';
        $editUrl = '/admin/members/'.$memberId;
        $statusUrl = $editUrl.'/status';

        try {
            $entityManager->persist($member);
            $entityManager->flush();

            $client->request('GET', $editUrl);
            self::assertResponseRedirects('/admin/login');

            $this->persistUser($client, $adminEmail, $adminPassword, ['ROLE_ADMIN']);
            $this->submitLogin($client, $adminEmail, $adminPassword);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();

            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#member-status-description', 'Aktiv');
            $token = (string) $client->getCrawler()
                ->filter(sprintf('form[action="%s"] input[name="_token"]', $statusUrl))
                ->attr('value');
            self::assertNotEmpty($token);

            $client->request('POST', $statusUrl, ['_token' => 'invalid-token', 'is_active' => '0']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT is_active FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertTrue((bool) $row['is_active']);

            $client->request('POST', $statusUrl, ['_token' => $token, 'is_active' => '0']);
            self::assertResponseRedirects($editUrl);
            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT is_active FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertFalse((bool) $row['is_active']);

            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#member-status-description', 'Gesperrt');
            $activationToken = (string) $client->getCrawler()
                ->filter(sprintf('form[action="%s"] input[name="_token"]', $statusUrl))
                ->attr('value');
            self::assertNotEmpty($activationToken);

            $client->request('POST', $statusUrl, ['_token' => $activationToken, 'is_active' => '1']);
            self::assertResponseRedirects($editUrl);
            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT is_active FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertTrue((bool) $row['is_active']);
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                $cleanupManager->getConnection()->executeStatement(
                    'DELETE FROM member_accounts WHERE id = :id',
                    ['id' => $memberId],
                );
            }
            $this->removeUser($client, $adminEmail);
        }
    }

    public function testAdminCanResetMemberPasswordWithOneTimeCodeAndCsrf(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(6));
        $member = new MemberAccount('member-reset-'.$suffix.'@example.test', 'reset'.$suffix, 'Reset member');
        $member->setPassword('existing-password-hash');
        $memberId = $member->getId();
        $adminEmail = 'admin-member-reset-'.$suffix.'@example.test';
        $adminPassword = 'ResetAdmin!2026';
        $editUrl = '/admin/members/'.$memberId;
        $resetUrl = $editUrl.'/password-reset';
        $setupPassword = 'MemberReset!2026';

        try {
            $entityManager->persist($member);
            $entityManager->flush();

            $this->persistUser($client, $adminEmail, $adminPassword, ['ROLE_ADMIN']);
            $this->submitLogin($client, $adminEmail, $adminPassword);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();

            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()
                ->filter(sprintf('form[action="%s"] input[name="_token"]', $resetUrl))
                ->attr('value');
            self::assertNotEmpty($token);

            $client->request('POST', $resetUrl, ['_token' => 'invalid-token']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT is_active, password, invitation_token_hash FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertTrue((bool) $row['is_active']);
            self::assertSame('existing-password-hash', $row['password']);
            self::assertNull($row['invitation_token_hash']);

            $client->request('POST', $resetUrl, ['_token' => $token]);
            self::assertResponseRedirects($editUrl);
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('Es wird keine E-Mail versendet.', (string) $client->getResponse()->getContent());

            $code = trim($client->getCrawler()->filter('.member-invitation-code')->text());
            self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $code);
            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT is_active, password, invitation_token_hash, invitation_expires_at FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertFalse((bool) $row['is_active']);
            self::assertSame('existing-password-hash', $row['password']);
            self::assertSame(hash('sha256', $code), $row['invitation_token_hash']);
            self::assertNotNull($row['invitation_expires_at']);

            $client->request('GET', '/konto/einrichten');
            self::assertResponseIsSuccessful();
            $setupToken = (string) $client->getCrawler()
                ->filter('form[action="/konto/einrichten"] input[name="_token"]')
                ->attr('value');
            self::assertNotEmpty($setupToken);
            $setupPayload = [
                '_token' => $setupToken,
                'invitation_code' => $code,
                'password' => $setupPassword,
                'password_confirmation' => $setupPassword,
            ];
            $client->request('POST', '/konto/einrichten', $setupPayload);
            self::assertResponseRedirects('/konto/anmelden');

            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT is_active, password, invitation_token_hash FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertTrue((bool) $row['is_active']);
            self::assertNotSame('existing-password-hash', $row['password']);
            self::assertNull($row['invitation_token_hash']);

            $client->request('POST', '/konto/einrichten', $setupPayload);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            $this->removeUser($client, $adminEmail);
        }
    }

    public function testAdminCanFilterMembersByStatusAndExportSelection(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(6));
        $activeHandle = 'active'.$suffix;
        $suspendedHandle = 'paused'.$suffix;
        $activeMember = new MemberAccount('active-'.$suffix.'@example.test', $activeHandle, 'Active filter member');
        $suspendedMember = new MemberAccount('paused-'.$suffix.'@example.test', $suspendedHandle, 'Suspended filter member');
        $suspendedMember->setActive(false);
        $activeId = $activeMember->getId();
        $suspendedId = $suspendedMember->getId();
        $adminEmail = 'admin-status-filter-'.$suffix.'@example.test';
        $adminPassword = 'DirectoryAdmin!2026';

        try {
            $entityManager->persist($activeMember);
            $entityManager->persist($suspendedMember);
            $entityManager->flush();

            $this->persistUser($client, $adminEmail, $adminPassword, ['ROLE_ADMIN']);
            $this->submitLogin($client, $adminEmail, $adminPassword);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();

            $client->request('GET', '/admin/members?q='.$suffix.'&status=suspended');
            self::assertResponseIsSuccessful();
            $content = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($suspendedHandle, $content);
            self::assertStringNotContainsString($activeHandle, $content);
            self::assertSelectorExists('select[name="status"] option[value="suspended"][selected]');
            self::assertSelectorExists('nav[aria-label="Hauptnavigation"] a[href="/admin/members?status=suspended"][aria-current="page"]');

            $exportLink = (string) $client->getCrawler()
                ->filter('a[href^="/admin/members/export"]')
                ->attr('href');
            self::assertStringContainsString('status=suspended', $exportLink);

            $contactExportLink = (string) $client->getCrawler()
                ->filter('a[href^="/admin/members/export-contacts"]')
                ->attr('href');
            self::assertStringContainsString('status=suspended', $contactExportLink);
            self::assertStringContainsString('q='.$suffix, $contactExportLink);

            $client->request('GET', '/admin/members/export?q='.$suffix.'&status=suspended');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString(
                'attachment; filename="arcanorum-mitglieder.csv"',
                (string) $client->getResponse()->headers->get('Content-Disposition'),
            );

            $client->request('GET', '/admin/members?q='.$suffix.'&status=active');
            self::assertResponseIsSuccessful();
            $content = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($activeHandle, $content);
            self::assertStringNotContainsString($suspendedHandle, $content);
            self::assertSelectorExists('select[name="status"] option[value="active"][selected]');

            $client->request('GET', '/admin/members?q='.$suffix.'&status=invalid');
            self::assertResponseIsSuccessful();
            $content = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($activeHandle, $content);
            self::assertStringContainsString($suspendedHandle, $content);
            self::assertSelectorExists('select[name="status"] option[value="all"][selected]');
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                $cleanupManager->getConnection()->executeStatement(
                    'DELETE FROM member_accounts WHERE id IN (:active_id, :suspended_id)',
                    ['active_id' => $activeId, 'suspended_id' => $suspendedId],
                );
            }
            $this->removeUser($client, $adminEmail);
        }
    }

    public function testAdminCanCreateMemberAndIssueOneTimeSetupCode(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(6));
        $email = 'invited-'.$suffix.'@example.test';
        $handle = 'invite'.$suffix;
        $adminEmail = 'admin-invitation-'.$suffix.'@example.test';
        $adminPassword = 'InvitationAdmin!2026';
        $changedEmail = 'changed-invitation-'.$suffix.'@example.test';
        /** @var string|null $accountId */
        $accountId = null;

        try {
            $client->request('GET', '/admin/members/new');
            self::assertResponseRedirects('/admin/login');

            $this->persistUser($client, $adminEmail, $adminPassword, ['ROLE_ADMIN']);
            $this->submitLogin($client, $adminEmail, $adminPassword);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();

            $client->request('GET', '/admin/members/new');
            self::assertResponseIsSuccessful();
            $csrf = (string) $client->getCrawler()->filter('form[action="/admin/members/new"] input[name="_token"]')->attr('value');
            self::assertNotEmpty($csrf);

            $payload = [
                'email' => $email,
                'handle' => $handle,
                'display_name' => 'Eingeladenes Mitglied',
            ];
            $client->request('POST', '/admin/members/new', ['_token' => 'invalid-csrf'] + $payload);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $memberCount = $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM member_accounts WHERE email = :email',
                ['email' => $email],
            );
            self::assertIsNumeric($memberCount);
            self::assertSame(0, (int) $memberCount);

            $client->request('POST', '/admin/members/new', ['_token' => $csrf] + $payload);
            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT id, is_active, password, invitation_token_hash, invitation_expires_at FROM member_accounts WHERE email = :email',
                ['email' => $email],
            );
            self::assertIsArray($row);
            self::assertIsString($row['id']);
            $accountId = $row['id'];
            self::assertNotSame('', $accountId);
            self::assertFalse((bool) $row['is_active']);
            self::assertSame('!invalid-password', $row['password']);
            self::assertResponseRedirects('/admin/members/'.$accountId);
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $cacheDirectives = array_map(
                static fn (string $directive): string => strtolower(trim($directive)),
                explode(',', (string) $client->getResponse()->headers->get('Cache-Control')),
            );
            self::assertContains(
                'no-store',
                $cacheDirectives,
                'The setup-code page must not be stored by caches.',
            );
            self::assertContains('private', $cacheDirectives);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');

            $firstCode = trim($client->getCrawler()->filter('.member-invitation-code')->text());
            self::assertMatchesRegularExpression('/\\A[A-Za-z0-9_-]{43}\\z/D', $firstCode);
            self::assertSame(hash('sha256', $firstCode), $row['invitation_token_hash']);
            self::assertNotSame($firstCode, $row['invitation_token_hash']);
            self::assertNotNull($row['invitation_expires_at']);
            self::assertStringContainsString('automatischer E-Mail-Versand ist nicht eingerichtet', (string) $client->getResponse()->getContent());

            $activityQuery = http_build_query(['q' => $adminEmail]);
            $client->request('GET', '/admin/activity?'.$activityQuery);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Security-Policy-Report-Only', self::CSP_REPORT_ONLY_POLICY);
            $activityHtml = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($adminEmail, $activityHtml);
            self::assertStringContainsString('Mitgliedskonto angelegt', $activityHtml);
            self::assertStringNotContainsString($email, $activityHtml);
            $cacheDirectives = array_map(
                static fn (string $directive): string => strtolower(trim($directive)),
                explode(',', (string) $client->getResponse()->headers->get('Cache-Control')),
            );
            self::assertContains('no-store', $cacheDirectives);
            self::assertContains('private', $cacheDirectives);

            $client->request('POST', '/admin/activity/clear', ['_token' => 'invalid-csrf']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('GET', '/admin/activity?'.$activityQuery);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Mitgliedskonto angelegt', (string) $client->getResponse()->getContent());
            $clearToken = (string) $client->getCrawler()
                ->filter('form[action="/admin/activity/clear"] input[name="_token"]')
                ->attr('value');
            self::assertNotEmpty($clearToken);

            $client->request('POST', '/admin/activity/clear', ['_token' => $clearToken]);
            self::assertResponseRedirects('/admin/activity');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.admin-page', 'Der Aktivitätsverlauf ist leer.');
            self::assertStringNotContainsString('Mitgliedskonto angelegt', (string) $client->getResponse()->getContent());

            $client->request('GET', '/admin/members/'.$accountId);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.member-invitation-code');

            $inviteToken = (string) $client->getCrawler()
                ->filter(sprintf('form[action="/admin/members/%s/invitation"] input[name="_token"]', $accountId))
                ->attr('value');
            self::assertNotEmpty($inviteToken);
            $client->request('POST', '/admin/members/'.$accountId.'/invitation', ['_token' => $inviteToken]);
            self::assertResponseRedirects('/admin/members/'.$accountId);
            $client->followRedirect();
            $secondCode = trim($client->getCrawler()->filter('.member-invitation-code')->text());
            self::assertNotSame($firstCode, $secondCode);
            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT invitation_token_hash FROM member_accounts WHERE id = :id',
                ['id' => $accountId],
            );
            self::assertIsArray($row);
            self::assertSame(hash('sha256', $secondCode), $row['invitation_token_hash']);

            $client->request('GET', '/admin/members/'.$accountId);
            $identityToken = (string) $client->getCrawler()
                ->filter(sprintf('form[action="/admin/members/%s/identity"] input[name="_token"]', $accountId))
                ->attr('value');
            $client->request('POST', '/admin/members/'.$accountId.'/identity', [
                '_token' => $identityToken,
                'email' => $changedEmail,
                'handle' => $handle,
            ]);
            self::assertResponseRedirects('/admin/members/'.$accountId);
            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT invitation_token_hash, invitation_expires_at FROM member_accounts WHERE id = :id',
                ['id' => $accountId],
            );
            self::assertIsArray($row);
            self::assertNull($row['invitation_token_hash']);
            self::assertNull($row['invitation_expires_at']);
        } finally {
            if (null !== $accountId) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM member_accounts WHERE id = :id',
                    ['id' => $accountId],
                );
            }
            $admin = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $adminEmail]);
            if ($admin instanceof AdminUser) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM admin_activity_entries WHERE actor_id = :actor_id',
                    ['actor_id' => $admin->getId()],
                );
            }
            $this->removeUser($client, $adminEmail);
        }
    }

    public function testAdminCanFilterVideoCatalogBySourceTypeAndKeepSafeFiltersAcrossPages(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $marker = 'source-filter-'.bin2hex(random_bytes(6));
        $email = $marker.'@example.test';
        $password = 'SourceFilter!'.bin2hex(random_bytes(6)).'-A9!';
        $videos = [];

        try {
            for ($index = 0; $index < 26; ++$index) {
                $videos[] = new Video(
                    sprintf('%s stream %02d', $marker, $index),
                    'Source filter regression fixture.',
                    null,
                    VideoSourceType::Stream,
                    sprintf('https://media.example.test/%s-stream-%02d.m3u8', $marker, $index),
                    0 === $index ? PublishingStatus::Draft : PublishingStatus::Published,
                    false,
                    true,
                );
            }
            $videos[] = new Video(
                $marker.' embed',
                'Source filter regression fixture.',
                null,
                VideoSourceType::Embed,
                'https://www.youtube.com/watch?v=dQw4w9WgXcQ&feature='.$marker,
                PublishingStatus::Published,
                false,
                true,
            );
            $videos[] = new Video(
                $marker.' url',
                'Source filter regression fixture.',
                null,
                VideoSourceType::Url,
                'https://media.example.test/'.$marker.'.mp4',
                PublishingStatus::Published,
                false,
                true,
            );
            foreach ($videos as $video) {
                $entityManager->persist($video);
            }
            $entityManager->flush();

            $this->persistUser($client, $email, $password, ['ROLE_ADMIN']);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('nav[aria-label="Hauptnavigation"]', 'Streams');
            self::assertSelectorExists('nav[aria-label="Hauptnavigation"] a[href="/admin/videos?source_type=stream"]');
            self::assertSelectorExists('nav[aria-label="Hauptnavigation"] a[href="/admin/videos?source_type=embed"]');

            $client->request('GET', '/admin/videos', [
                'q' => $marker,
                'source_type' => VideoSourceType::Stream->value,
                'sort' => 'title',
                'direction' => 'asc',
            ]);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('section[aria-labelledby="video-list-heading"] header', '26 Treffer');
            self::assertSelectorExists('select[name="source_type"] option[value="stream"][selected]');
            self::assertSelectorTextContains('table', $marker.' stream 00');
            self::assertStringNotContainsString($marker.' embed', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($marker.' url', (string) $client->getResponse()->getContent());
            self::assertSelectorExists('nav[aria-label="Hauptnavigation"] a[href="/admin/videos?source_type=stream"][aria-current="page"]');

            $nextPage = $client->getCrawler()->filter('nav[aria-label="Verwaltungsseiten"] a')->last()->attr('href');
            self::assertIsString($nextPage);
            self::assertStringContainsString('source_type=stream', $nextPage);
            $client->request('GET', $nextPage);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('nav[aria-label="Verwaltungsseiten"]', 'Seite 2 von 2');
            self::assertSelectorTextContains('table', $marker.' stream 25');
            self::assertSelectorNotExists('select[name="source_type"] option[value="embed"][selected]');

            $client->request('GET', '/admin/videos', [
                'q' => $marker,
                'source_type' => VideoSourceType::Stream->value,
                'status' => PublishingStatus::Published->value,
            ]);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('section[aria-labelledby="video-list-heading"] header', '25 Treffer');
            self::assertSelectorTextContains('table', $marker.' stream 01');
            self::assertStringNotContainsString($marker.' stream 00', (string) $client->getResponse()->getContent());

            $client->request('GET', '/admin/videos', [
                'q' => $marker,
                'source_type' => VideoSourceType::Embed->value,
            ]);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('table', $marker.' embed');
            self::assertStringNotContainsString($marker.' stream', (string) $client->getResponse()->getContent());
            self::assertSelectorExists('nav[aria-label="Hauptnavigation"] a[href="/admin/videos?source_type=embed"][aria-current="page"]');

            $client->request('GET', '/admin/videos', ['q' => $marker, 'source_type' => 'unsupported']);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('section[aria-labelledby="video-list-heading"] header', '28 Treffer');

            $client->request('GET', '/admin/videos', ['q' => $marker, 'source_type' => ['stream']]);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('section[aria-labelledby="video-list-heading"] header', '28 Treffer');
        } finally {
            foreach ($videos as $video) {
                $connection->delete('videos', ['id' => $video->getId()]);
            }
            $this->removeUser($client, $email);
        }
    }

    private static function categoryArtworkPng(): string
    {
        $image = imagecreatetruecolor(640, 360);
        self::assertInstanceOf(\GdImage::class, $image);
        $color = imagecolorallocate($image, 48, 112, 160);
        self::assertNotFalse($color);
        imagefill($image, 0, 0, $color);

        ob_start();
        try {
            self::assertTrue(imagepng($image));
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($image);
        }
        self::assertIsString($contents);

        return $contents;
    }

    private function categoryDeleteToken(KernelBrowser $client, string $categoryId): string
    {
        $client->request('GET', '/admin/categories?type=news');
        self::assertResponseIsSuccessful();
        $csrfTokens = $client->getContainer()->get(CsrfTokenManagerInterface::class);
        $requestStack = $client->getContainer()->get(RequestStack::class);
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $csrfTokens);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $session = $client->getRequest()->getSession();
        $request = Request::create('/admin/categories?type=news');
        $request->setSession($session);
        $requestStack->push($request);
        try {
            return $csrfTokens->getToken('admin_category_delete_'.$categoryId)->getValue();
        } finally {
            $requestStack->pop();
            $session->save();
        }
    }

    private function integer(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        self::assertIsInt($integer);

        return $integer;
    }

    /** @param list<string> $roles */
    private function persistUser(KernelBrowser $client, string $email, string $password, array $roles, bool $provisionMfa = true): ?AdminMfaProvisioningResult
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        $user = new AdminUser($email);
        $user->setRoles($roles);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        if ($provisionMfa) {
            $mfaProvisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
            self::assertInstanceOf(AdminMfaProvisioningService::class, $mfaProvisioner);

            return $mfaProvisioner->provision($user);
        }

        $entityManager->persist($user);
        $entityManager->flush();

        return null;
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password, ?string $mfaCode = null): void
    {
        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => $email,
            '_password' => $password,
            '_mfa_code' => $mfaCode ?? $this->loginCode($client, $email) ?? '000000',
        ]);
    }

    private function incorrectTotpCode(KernelBrowser $client, string $email): string
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $repository = $entityManager->getRepository(AdminUser::class);
        self::assertInstanceOf(AdminUserRepository::class, $repository);
        $user = $repository->findOneByEmail($email);
        self::assertInstanceOf(AdminUser::class, $user);
        $encryptedSecret = $user->getMfaSecretCiphertext();
        self::assertNotNull($encryptedSecret);

        $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
        self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
        $totp = $client->getContainer()->get(TotpVerifier::class);
        self::assertInstanceOf(TotpVerifier::class, $totp);
        $secret = $cipher->decrypt($encryptedSecret);
        $now = time();

        for ($candidate = 0; $candidate < 1_000_000; ++$candidate) {
            $code = sprintf('%06d', $candidate);
            if (null === $totp->matchingCounter($secret, $code, $now - 30)
                && null === $totp->matchingCounter($secret, $code, $now)
                && null === $totp->matchingCounter($secret, $code, $now + 30)
            ) {
                return $code;
            }
        }

        throw new \LogicException('Could not generate a non-matching TOTP test code.');
    }

    private function loginCode(KernelBrowser $client, string $email): ?string
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $repository = $entityManager->getRepository(AdminUser::class);
        self::assertInstanceOf(AdminUserRepository::class, $repository);
        $user = $repository->findOneByEmail($email);
        if (!$user instanceof AdminUser || null === $user->getMfaSecretCiphertext()) {
            return null;
        }

        $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
        self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
        $totp = $client->getContainer()->get(TotpVerifier::class);
        self::assertInstanceOf(TotpVerifier::class, $totp);

        return $totp->codeAtTime($cipher->decrypt($user->getMfaSecretCiphertext()), time());
    }

    private function removeUser(KernelBrowser $client, string $email): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $user = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        if (!$user instanceof AdminUser) {
            return;
        }

        $entityManager->remove($user);
        $entityManager->flush();
    }
}
