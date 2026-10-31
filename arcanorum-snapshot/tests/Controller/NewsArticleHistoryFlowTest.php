<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\MemberAccount;
use App\Entity\NewsArticle;
use App\Entity\NewsArticleRevision;
use App\Entity\PublishingStatus;
use App\Entity\StaticPage;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Repository\NewsArticleRepository;
use App\Repository\NewsArticleRevisionRepository;
use App\Repository\StaticPageRepository;
use App\Service\SiteSettingsProvider;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NewsArticleHistoryFlowTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $mfaKey = base64_encode(str_repeat('N', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$mfaKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;

        parent::setUpBeforeClass();
    }

    public function testNewsHistoryRequiresAnAdministrator(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/news/'.str_repeat('a', 32).'/history');

        self::assertResponseRedirects('/admin/login');
    }

    public function testAdministratorCreatesVersionedNewsWithEscapedAuthorAndPrivateHistory(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $thumbnailStorage = $client->getContainer()->get(PrivateObjectStorageInterface::class);
        self::assertInstanceOf(PrivateObjectStorageInterface::class, $thumbnailStorage);

        $suffix = bin2hex(random_bytes(8));
        $email = 'news-history-admin-'.$suffix.'@example.test';
        $password = 'NewsHistory-Admin-'.$suffix.'!2026';
        $admin = new AdminUser($email);
        $admin->setRoles([AdminRole::Administrator->value]);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $admin->setPassword($hasher->hashPassword($admin, $password));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($admin);
        $articleId = null;
        $pageSlug = null;
        $thumbnailObjectIds = [];

        try {
            $entityManager->persist($admin);
            $entityManager->flush();
            $this->submitLogin($client, $admin, $password);

            $client->request('GET', '/admin/news/new');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');
            self::assertNotSame('', $token);
            $client->request('POST', '/admin/news/new', [
                '_token' => $token,
                'title' => 'News revision '.$suffix,
                'author_name' => ' ',
                'category' => '',
                'tags' => '',
                'summary' => '',
                'body' => 'Must not be stored.',
                'status' => 'draft',
            ]);
            self::assertResponseStatusCodeSame(422);
            $freshManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $freshManager);
            self::assertFalse((bool) $freshManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM news_articles WHERE slug = :slug',
                ['slug' => 'news-revision-'.$suffix],
            ));

            $client->request('GET', '/admin/news/new');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-rich-text-editor]');
            self::assertSelectorExists('input[name="slug"]');
            self::assertSame('multipart/form-data', $client->getCrawler()->filter('form.admin-form')->attr('enctype'));
            $token = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
            self::assertIsString($png);
            $thumbnailPath = tempnam(sys_get_temp_dir(), 'arcanorum-news-thumbnail-');
            self::assertIsString($thumbnailPath);
            self::assertSame(strlen($png), file_put_contents($thumbnailPath, $png));
            try {
                $client->request('POST', '/admin/news/new', [
                    '_token' => $token,
                    'title' => 'News revision '.$suffix,
                    'slug' => 'editorial-'.$suffix,
                    'author_name' => 'Jordan O’Reilly & Co.',
                    'category' => '',
                    'tags' => 'History, Editorial',
                    'summary' => 'First version summary.',
                    'body' => '<p>First <strong>version</strong></p><script>alert(1)</script>',
                    'status' => 'draft',
                ], ['thumbnail' => new UploadedFile($thumbnailPath, 'news-thumbnail.png', 'image/png', null, true)]);
            } finally {
                if (is_file($thumbnailPath)) {
                    unlink($thumbnailPath);
                }
            }
            self::assertResponseRedirects('/admin/news');

            $articles = $client->getContainer()->get(NewsArticleRepository::class);
            self::assertInstanceOf(NewsArticleRepository::class, $articles);
            $article = $articles->findOneBySlug('editorial-'.$suffix);
            self::assertInstanceOf(NewsArticle::class, $article);
            $articleId = $article->getId();
            self::assertSame('Jordan O’Reilly & Co.', $article->getAuthorName());
            self::assertSame('<p>First <strong>version</strong></p>', $article->getBody());
            self::assertNotNull($article->getThumbnailObjectId());
            self::assertSame('image/png', $article->getThumbnailMimeType());
            self::assertSame(strlen($png), $article->getThumbnailSize());
            $thumbnailObjectIds[] = $article->getThumbnailObjectId();
            self::assertSame($png, $thumbnailStorage->read($article->getThumbnailObjectId()));

            $revisionRepository = $client->getContainer()->get(NewsArticleRevisionRepository::class);
            self::assertInstanceOf(NewsArticleRevisionRepository::class, $revisionRepository);
            self::assertSame(1, $revisionRepository->countForArticle($article));
            $firstRevision = $revisionRepository->findLatestForArticle($article);
            self::assertInstanceOf(NewsArticleRevision::class, $firstRevision);
            self::assertSame($article->getId(), $firstRevision->getArticle()->getId());
            self::assertSame($admin->getId(), $firstRevision->getEditor()?->getId());
            self::assertSame($article->getThumbnailObjectId(), $firstRevision->getThumbnailObjectId());

            $client->request('GET', '/admin/news/'.$article->getId().'/thumbnail');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'image/png');
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            self::assertSame($png, $client->getResponse()->getContent());
            $client->request('GET', '/news/'.$article->getId().'/thumbnail');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/admin/news/'.$article->getId().'/edit');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/news/'.$article->getId().'/edit', [
                '_token' => $token,
                'title' => 'Revised news '.$suffix,
                'slug' => 'revised-news-'.$suffix,
                'author_name' => 'News Desk',
                'category' => '',
                'tags' => 'History',
                'summary' => 'Second version summary.',
                'body' => '<p>Private phrase <em>formatted</em>.</p><script>alert(1)</script>',
                'status' => 'published',
                'members_only' => '1',
            ]);
            self::assertResponseRedirects('/admin/news');

            $articles = $client->getContainer()->get(NewsArticleRepository::class);
            $revisionRepository = $client->getContainer()->get(NewsArticleRevisionRepository::class);
            self::assertInstanceOf(NewsArticleRepository::class, $articles);
            self::assertInstanceOf(NewsArticleRevisionRepository::class, $revisionRepository);
            $article = $articles->find($articleId);
            self::assertInstanceOf(NewsArticle::class, $article);
            self::assertSame(2, $revisionRepository->countForArticle($article));
            self::assertSame($thumbnailObjectIds[0], $article->getThumbnailObjectId());
            $secondRevision = $revisionRepository->findLatestForArticle($article);
            self::assertInstanceOf(NewsArticleRevision::class, $secondRevision);
            self::assertSame($thumbnailObjectIds[0], $secondRevision->getThumbnailObjectId());
            self::assertSame('revised-news-'.$suffix, $article->getSlug());
            self::assertTrue($article->isMembersOnly());
            self::assertSame('<p>Private phrase <em>formatted</em>.</p>', $article->getBody());

            $client->request('GET', '/admin/news');
            self::assertResponseIsSuccessful();
            $bulkToken = (string) $client->getCrawler()->filter('form.admin-bulk-form input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/news/bulk', [
                '_token' => $bulkToken,
                'action' => 'feature',
                'ids' => [$article->getId()],
            ]);
            self::assertResponseRedirects('/admin/news');

            $entityManager->clear();
            $articles = $client->getContainer()->get(NewsArticleRepository::class);
            $revisionRepository = $client->getContainer()->get(NewsArticleRevisionRepository::class);
            self::assertInstanceOf(NewsArticleRepository::class, $articles);
            self::assertInstanceOf(NewsArticleRevisionRepository::class, $revisionRepository);
            $article = $articles->find($articleId);
            self::assertInstanceOf(NewsArticle::class, $article);
            self::assertTrue($article->isFeatured());
            self::assertSame(3, $revisionRepository->countForArticle($article));
            $revisions = $revisionRepository->findForArticle($article);
            self::assertCount(3, $revisions);
            self::assertSame([3, 2, 1], array_map(static fn (NewsArticleRevision $revision): int => $revision->getVersion(), $revisions));

            $client->request('GET', '/admin/news/'.$article->getId().'/history');
            self::assertResponseIsSuccessful();
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorTextContains('main', '<p>First <strong>version</strong></p>');
            self::assertStringNotContainsString('<script>alert(1)', $client->getResponse()->getContent());
            self::assertSelectorTextContains('main', $email);
            self::assertSelectorTextContains('main', 'Jordan O’Reilly & Co.');
            self::assertSelectorTextContains('main', 'News Desk');

            $client->request('GET', '/news/revised-news-'.$suffix);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Von News Desk');
            self::assertSelectorTextContains('main', 'Mitgliedern vorbehalten');
            self::assertStringNotContainsString('Private phrase', $client->getResponse()->getContent());
            self::assertStringNotContainsString($email, $client->getResponse()->getContent());

            $client->request('GET', '/news/revised-news-'.$suffix);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Second version summary.');
            self::assertSelectorTextContains('main', 'Mitgliedern vorbehalten');
            self::assertStringNotContainsString('Private phrase', $client->getResponse()->getContent());
            self::assertStringNotContainsString('/news/'.$article->getId().'/thumbnail', $client->getResponse()->getContent());
            self::assertSelectorNotExists('.comment-form');
            $client->request('GET', '/search?q=Private%20phrase');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('Revised news '.$suffix, $client->getResponse()->getContent());

            $client->request('GET', '/news/'.$article->getId().'/thumbnail');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/admin/news/'.$article->getId().'/edit');
            self::assertResponseIsSuccessful();
            $removeToken = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/news/'.$article->getId().'/edit', [
                '_token' => $removeToken,
                'title' => $article->getTitle(),
                'slug' => $article->getSlug(),
                'author_name' => $article->getAuthorName(),
                'category' => '',
                'tags' => $article->getTagNames(),
                'summary' => $article->getSummary() ?? '',
                'body' => $article->getBody(),
                'status' => 'published',
                'members_only' => '1',
                'remove_thumbnail' => '1',
            ]);
            self::assertResponseRedirects('/admin/news');
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->clear();
            $articles = $client->getContainer()->get(NewsArticleRepository::class);
            $revisionRepository = $client->getContainer()->get(NewsArticleRevisionRepository::class);
            self::assertInstanceOf(NewsArticleRepository::class, $articles);
            self::assertInstanceOf(NewsArticleRevisionRepository::class, $revisionRepository);
            $article = $articles->find($articleId);
            self::assertInstanceOf(NewsArticle::class, $article);
            self::assertNull($article->getThumbnailObjectId());
            $removedThumbnailRevision = $revisionRepository->findLatestForArticle($article);
            self::assertInstanceOf(NewsArticleRevision::class, $removedThumbnailRevision);
            self::assertNull($removedThumbnailRevision->getThumbnailObjectId());
            self::assertSame($thumbnailObjectIds[0], $revisionRepository->findForArticle($article, 10)[1]->getThumbnailObjectId());
            self::assertSame($png, $thumbnailStorage->read($thumbnailObjectIds[0]));

            $client->request('GET', '/admin/pages/new');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('input[name="meta_keywords"]');
            self::assertSelectorExists('textarea[name="meta_description"]');
            self::assertSelectorExists('input[name="show_in_menu"]');
            $pageToken = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');
            $pageSlug = 'editorial-page-'.$suffix;
            $client->request('POST', '/admin/pages/new', [
                '_token' => $pageToken,
                'title' => 'Editorial page '.$suffix,
                'slug' => $pageSlug,
                'summary' => 'Page teaser',
                'body' => '<p>Page <strong>formatting</strong></p><script>alert(9)</script>',
                'status' => 'published',
                'meta_keywords' => 'editorial, community',
                'meta_description' => 'Page SEO summary',
                'author_name' => 'Page Editor',
                'show_in_menu' => '1',
            ]);
            self::assertResponseRedirects('/admin/pages');
            $pages = $client->getContainer()->get(StaticPageRepository::class);
            self::assertInstanceOf(StaticPageRepository::class, $pages);
            $page = $pages->findOneBySlug($pageSlug);
            self::assertInstanceOf(StaticPage::class, $page);
            self::assertSame('<p>Page <strong>formatting</strong></p>', $page->getBody());
            self::assertSame('editorial, community', $page->getMetaKeywords());
            self::assertSame('Page SEO summary', $page->getMetaDescription());
            self::assertSame('Page Editor', $page->getAuthorName());
            self::assertTrue($page->isShownInMenu());
            $client->request('GET', '/pages/'.$pageSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.article-body p strong');
            self::assertSelectorTextContains('main', 'Von Page Editor');
            self::assertSelectorExists('meta[name="keywords"][content="editorial, community"]');
            self::assertStringNotContainsString('<script', $client->getResponse()->getContent());

            $client->request('GET', '/admin/news/'.$article->getId().'/edit');
            $client->request('POST', '/admin/news/'.$article->getId().'/edit', [
                '_token' => 'invalid-csrf-token',
                'title' => 'Unauthorized revision '.$suffix,
                'slug' => 'unauthorized-revision-'.$suffix,
                'author_name' => 'Attacker',
                'category' => '',
                'tags' => '',
                'summary' => '',
                'body' => 'Must not be saved.',
                'status' => 'published',
            ]);
            self::assertResponseStatusCodeSame(403);

            $freshManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $freshManager);
            $freshManager->clear();
            $articles = $client->getContainer()->get(NewsArticleRepository::class);
            $revisionRepository = $client->getContainer()->get(NewsArticleRevisionRepository::class);
            self::assertInstanceOf(NewsArticleRepository::class, $articles);
            self::assertInstanceOf(NewsArticleRevisionRepository::class, $revisionRepository);
            $reloadedArticle = $articles->find($articleId);
            self::assertInstanceOf(NewsArticle::class, $reloadedArticle);
            self::assertSame(4, $revisionRepository->countForArticle($reloadedArticle));
            self::assertSame('Revised news '.$suffix, $reloadedArticle->getTitle());

            $client->request('GET', '/admin/news');
            $deleteToken = (string) $client->getCrawler()->filter('form.admin-bulk-form input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/news/bulk', [
                '_token' => $deleteToken,
                'action' => 'delete',
                'confirm_delete' => '1',
                'ids' => [$articleId],
            ]);
            self::assertResponseRedirects('/admin/news');
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            $deletionJobs = $client->getContainer()->get(\App\Repository\PrivateObjectDeletionJobRepository::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            self::assertInstanceOf(\App\Repository\PrivateObjectDeletionJobRepository::class, $deletionJobs);
            self::assertNull($entityManager->find(NewsArticle::class, $articleId));
            self::assertSame(1, $deletionJobs->countPendingForObjects([$thumbnailObjectIds[0]]));
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $cleanupManager);
            if (null !== $articleId) {
                $cleanupManager->getConnection()->executeStatement(
                    'DELETE FROM news_articles WHERE id = :id',
                    ['id' => $articleId],
                );
            }
            foreach (array_unique($thumbnailObjectIds) as $thumbnailObjectId) {
                $cleanupManager->getConnection()->executeStatement(
                    'DELETE FROM private_object_deletion_jobs WHERE object_id = :object_id',
                    ['object_id' => $thumbnailObjectId],
                );
            }
            foreach (array_unique($thumbnailObjectIds) as $thumbnailObjectId) {
                $thumbnailStorage->delete($thumbnailObjectId);
            }
            if (null !== $pageSlug) {
                $cleanupManager->getConnection()->executeStatement(
                    'DELETE FROM static_pages WHERE slug = :slug',
                    ['slug' => $pageSlug],
                );
            }
            $cleanupManager->getConnection()->executeStatement(
                'DELETE FROM admin_users WHERE id = :id',
                ['id' => $admin->getId()],
            );
        }
    }

    public function testScheduledPublicationUsesSiteTimezoneAndStaysHiddenUntilDue(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $email = 'news-schedule-admin-'.$suffix.'@example.test';
        $password = 'NewsSchedule-Admin-'.$suffix.'!2026';
        $admin = new AdminUser($email);
        $admin->setRoles([AdminRole::Administrator->value]);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $admin->setPassword($hasher->hashPassword($admin, $password));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($admin);
        $articleId = null;
        $slug = 'scheduled-news-'.$suffix;

        try {
            $entityManager->persist($admin);
            $entityManager->flush();
            $this->submitLogin($client, $admin, $password);

            $siteSettings = $client->getContainer()->get(SiteSettingsProvider::class);
            self::assertInstanceOf(SiteSettingsProvider::class, $siteSettings);
            $timezone = new \DateTimeZone($siteSettings->current()->getTimezone());
            $publicationInput = '2030-04-01T12:30';
            $client->request('GET', '/admin/news/new');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');

            $client->request('POST', '/admin/news/new', [
                '_token' => $token,
                'title' => 'Scheduled news '.$suffix,
                'author_name' => 'News Desk',
                'category' => '',
                'tags' => '',
                'summary' => '',
                'body' => 'Unexpected fields must not be stored.',
                'status' => 'draft',
                'unexpected' => 'value',
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertFalse((bool) $entityManager->getConnection()->fetchOne(
                'SELECT EXISTS (SELECT 1 FROM news_articles WHERE slug = :slug)',
                ['slug' => $slug],
            ));

            $client->request('GET', '/admin/news/new');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/news/new', [
                '_token' => $token,
                'title' => 'Scheduled news '.$suffix,
                'author_name' => 'News Desk',
                'category' => '',
                'tags' => '',
                'summary' => '',
                'body' => 'This article must remain private until its scheduled time.',
                'status' => 'published',
                'publish_at' => '2030-02-30T12:30',
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('.flash-message.is-error', 'gültigen Veröffentlichungszeitpunkt');

            $client->request('GET', '/admin/news/new');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/news/new', [
                '_token' => $token,
                'title' => 'Scheduled news '.$suffix,
                'author_name' => 'News Desk',
                'category' => '',
                'tags' => '',
                'summary' => '',
                'body' => 'This article must remain private until its scheduled time.',
                'status' => 'published',
                'publish_at' => $publicationInput,
            ]);
            self::assertResponseRedirects('/admin/news');

            $articles = $client->getContainer()->get(NewsArticleRepository::class);
            self::assertInstanceOf(NewsArticleRepository::class, $articles);
            $article = $articles->findOneBySlug($slug);
            self::assertInstanceOf(NewsArticle::class, $article);
            $articleId = $article->getId();
            $scheduledAt = $article->getPublishedAt();
            self::assertInstanceOf(\DateTimeImmutable::class, $scheduledAt);
            $expectedUtc = new \DateTimeImmutable($publicationInput, $timezone)->setTimezone(new \DateTimeZone('UTC'));
            self::assertSame($expectedUtc->format('Y-m-d H:i'), $scheduledAt->format('Y-m-d H:i'));
            self::assertNull($articles->findPublishedBySlug($slug));
            self::assertNull($articles->findPublishedById($articleId));

            $client->request('GET', '/admin/news');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('table', 'Geplant für');
            self::assertSelectorTextContains('table', str_replace('T', ' ', $publicationInput));

            $client->request('GET', '/news/'.$slug);
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/news');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextNotContains('main', 'Scheduled news '.$suffix);
            $client->request('GET', '/news.rss');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('Scheduled news '.$suffix, (string) $client->getResponse()->getContent());
            $client->request('GET', '/sitemap.xml');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($slug, (string) $client->getResponse()->getContent());

            $duePublicationAt = new \DateTimeImmutable('-5 minutes', new \DateTimeZone('UTC'));
            $entityManager->getConnection()->executeStatement(
                'UPDATE news_articles SET published_at = :published_at WHERE id = :id',
                [
                    'published_at' => $duePublicationAt->format('Y-m-d H:i:s'),
                    'id' => $articleId,
                ],
            );
            $entityManager->clear();
            $articles = $client->getContainer()->get(NewsArticleRepository::class);
            self::assertInstanceOf(NewsArticleRepository::class, $articles);
            self::assertInstanceOf(NewsArticle::class, $articles->findPublishedBySlug($slug));
            self::assertInstanceOf(NewsArticle::class, $articles->findPublishedById($articleId));
            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Scheduled news '.$suffix);
            $client->request('GET', '/news.rss');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Scheduled news '.$suffix, (string) $client->getResponse()->getContent());
            $client->request('GET', '/sitemap.xml');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString($slug, (string) $client->getResponse()->getContent());
        } finally {
            if (null !== $articleId) {
                $entityManager->getConnection()->executeStatement('DELETE FROM news_articles WHERE id = :id', ['id' => $articleId]);
            }
            $entityManager->getConnection()->executeStatement('DELETE FROM admin_users WHERE id = :id', ['id' => $admin->getId()]);
        }
    }

    public function testVerifiedMemberCanReadMemberOnlyNewsThumbnailWithoutPublicCaching(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $storage = $client->getContainer()->get(PrivateObjectStorageInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(PrivateObjectStorageInterface::class, $storage);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        $slug = 'members-only-thumbnail-'.bin2hex(random_bytes(4));
        $article = new NewsArticle(
            'Members-only thumbnail '.bin2hex(random_bytes(4)),
            $slug,
            null,
            'Private teaser',
            'Private article body',
            PublishingStatus::Published,
            false,
            true,
            'Editorial Desk',
            true,
        );
        $article->setPublicationAt(new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')));
        $objectId = bin2hex(random_bytes(32));
        $storage->write($objectId, $png);
        $article->attachThumbnail($objectId, 'image/png', strlen($png));
        $entityManager->persist($article);
        $member = new MemberAccount('thumbnail-reader-'.bin2hex(random_bytes(4)).'@example.test', 'thumbreader'.bin2hex(random_bytes(3)), 'Thumbnail Reader');
        $entityManager->persist($member);
        $entityManager->flush();

        try {
            $client->request('GET', '/news/'.$article->getId().'/thumbnail');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Dieser Beitrag ist Mitgliedern vorbehalten.');
            $anonymousArticleResponse = $client->getResponse()->getContent();
            self::assertIsString($anonymousArticleResponse);
            self::assertStringNotContainsString('Private article body', $anonymousArticleResponse);

            $client->loginUser($member, 'members');
            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            $memberArticleResponse = $client->getResponse()->getContent();
            self::assertIsString($memberArticleResponse);
            self::assertStringContainsString('Private article body', $memberArticleResponse);
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            $client->request('GET', '/news/'.$article->getId().'/thumbnail');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'image/png');
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame($png, $client->getResponse()->getContent());
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM news_articles WHERE id = :id',
                ['id' => $article->getId()],
            );
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM member_accounts WHERE id = :id',
                ['id' => $member->getId()],
            );
            $storage->delete($objectId);
        }
    }

    private function submitLogin(KernelBrowser $client, AdminUser $admin, string $password): void
    {
        $encryptedSecret = $admin->getMfaSecretCiphertext();
        self::assertNotNull($encryptedSecret);
        $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
        self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
        $totp = $client->getContainer()->get(TotpVerifier::class);
        self::assertInstanceOf(TotpVerifier::class, $totp);

        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => $admin->getUserIdentifier(),
            '_password' => $password,
            '_mfa_code' => $totp->codeAtTime($cipher->decrypt($encryptedSecret), time()),
        ]);
        self::assertResponseRedirects('/admin');
    }
}
