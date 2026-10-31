<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\ContentTag;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Admin\Service\AdminModeratorAccessManager;
use App\Module\Admin\Service\AdminRoleChangeResult;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Repository\AdminUserRepository;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminStaffControllerTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $testKey = base64_encode(str_repeat('S', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$testKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;

        parent::setUpBeforeClass();
    }

    public function testAdministratorCanChangeModerationScopeAndRevokeExistingSession(): void
    {
        $moderatorClient = static::createClient();
        $moderatorClient->disableReboot();
        $moderatorClient->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $adminEmail = 'staff-admin-'.bin2hex(random_bytes(6)).'@example.test';
        $moderatorEmail = 'staff-target-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($moderatorClient, $adminEmail, 'StaffAdmin!Password2026', AdminRole::Administrator);
            $this->persistUser($moderatorClient, $moderatorEmail, 'StaffTarget!Password2026', AdminRole::CommentModerator);
            $this->submitLogin($moderatorClient, $moderatorEmail, 'StaffTarget!Password2026');
            self::assertResponseRedirects('/admin');

            static::ensureKernelShutdown();
            $adminClient = static::createClient();
            $adminClient->disableReboot();
            $adminClient->getContainer()->set(
                RuntimeAttestationReportProviderInterface::class,
                new VerifiedRuntimeAttestationReportProvider(),
            );
            $this->submitLogin($adminClient, $adminEmail, 'StaffAdmin!Password2026');
            self::assertResponseRedirects('/admin');

            $adminClient->request('GET', '/admin/staff');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('no-store', (string) $adminClient->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $adminClient->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorExists('a[href="/admin/staff"]');
            self::assertSelectorTextContains('body', $moderatorEmail);
            self::assertSelectorTextContains('body', 'Kommentarmoderation');

            $adminClient->request('GET', '/admin/staff?page%5B%5D=invalid');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Seite 1 von 1');

            $users = $adminClient->getContainer()->get(AdminUserRepository::class);
            self::assertInstanceOf(AdminUserRepository::class, $users);
            $target = $users->findOneByEmail($moderatorEmail);
            self::assertInstanceOf(AdminUser::class, $target);
            $roleForm = 'form[id="staff-role-'.$target->getId().'"] input[name="_token"]';
            $token = (string) $adminClient->getCrawler()->filter($roleForm)->attr('value');
            self::assertNotSame('', $token);
            $updatePath = '/admin/staff/'.$target->getId().'/role?page=1';

            $adminClient->request('POST', $updatePath, ['_token' => 'invalid', 'role' => AdminRole::VideoModerator->value]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame([AdminRole::CommentModerator->value, 'ROLE_USER'], $users->findOneByEmail($moderatorEmail)?->getRoles());

            $adminClient->request('POST', $updatePath, ['_token' => $token, 'role' => 'ROLE_UNKNOWN']);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'wird nicht unterstützt');

            $adminClient->request('POST', $updatePath, ['_token' => $token, 'role' => AdminRole::VideoModerator->value]);
            self::assertResponseRedirects('/admin/staff?page=1');
            $updatedModerator = $users->findOneByEmail($moderatorEmail);
            self::assertInstanceOf(AdminUser::class, $updatedModerator);
            self::assertSame([AdminRole::VideoModerator->value, 'ROLE_USER'], $updatedModerator->getRoles());

            $activity = $adminClient->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $activity);
            self::assertSame(
                'admin_staff_role_update',
                $activity->getConnection()->fetchOne(
                    'SELECT route_name FROM admin_activity_entries WHERE actor_id = :id ORDER BY created_at DESC LIMIT 1',
                    ['id' => $activity->getRepository(AdminUser::class)->findOneBy(['email' => $adminEmail])?->getId()],
                ),
            );

            $moderatorClient->request('GET', '/admin/comments');
            self::assertSame(302, $moderatorClient->getResponse()->getStatusCode());
            self::assertSame('/admin/login', $moderatorClient->getResponse()->headers->get('Location'));

            $adminClient->request('GET', '/admin/staff');
            $token = (string) $adminClient->getCrawler()->filter('form[id="staff-role-'.$updatedModerator->getId().'"] input[name="_token"]')->attr('value');
            $adminClient->request('POST', '/admin/staff/'.$updatedModerator->getId().'/role', ['_token' => $token, 'role' => '']);
            self::assertResponseRedirects('/admin/staff?page=1');
            $unassignedModerator = $users->findOneByEmail($moderatorEmail);
            self::assertInstanceOf(AdminUser::class, $unassignedModerator);
            self::assertSame(['ROLE_USER'], $unassignedModerator->getRoles());
            $moderatorClient->request('GET', '/admin');
            self::assertSame(302, $moderatorClient->getResponse()->getStatusCode());
            self::assertSame('/admin/login', $moderatorClient->getResponse()->headers->get('Location'));
        } finally {
            static::ensureKernelShutdown();
            $cleanupClient = static::createClient();
            $this->removeUsers($cleanupClient, [$adminEmail, $moderatorEmail]);
            static::ensureKernelShutdown();
        }
    }

    public function testRoleAssignmentRequiresMfaAndCannotChangeTheCurrentAdministrator(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $adminEmail = 'staff-guard-admin-'.bin2hex(random_bytes(6)).'@example.test';
        $unprovisionedEmail = 'staff-no-mfa-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $adminEmail, 'StaffGuard!Password2026', AdminRole::Administrator);
            $this->persistUser($client, $unprovisionedEmail, 'StaffNoMfa!Password2026', null, false);
            $this->submitLogin($client, $adminEmail, 'StaffGuard!Password2026');
            self::assertResponseRedirects('/admin');
            $client->request('GET', '/admin/staff');
            self::assertResponseIsSuccessful();

            $users = $client->getContainer()->get(AdminUserRepository::class);
            self::assertInstanceOf(AdminUserRepository::class, $users);
            $admin = $users->findOneByEmail($adminEmail);
            $unprovisioned = $users->findOneByEmail($unprovisionedEmail);
            self::assertInstanceOf(AdminUser::class, $admin);
            self::assertInstanceOf(AdminUser::class, $unprovisioned);
            $targetToken = (string) $client->getCrawler()->filter('form[id="staff-role-'.$unprovisioned->getId().'"] input[name="_token"]')->attr('value');
            $ownToken = (string) $client->getCrawler()->filter('form[id="staff-role-'.$admin->getId().'"] input[name="_token"]')->attr('value');

            $client->request('POST', '/admin/staff/'.$unprovisioned->getId().'/role', [
                '_token' => $targetToken,
                'role' => AdminRole::CommentModerator->value,
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'zuerst mit MFA eingerichtet');
            self::assertSame(['ROLE_USER'], $users->findOneByEmail($unprovisionedEmail)?->getRoles());

            $client->request('POST', '/admin/staff/'.$admin->getId().'/role', [
                '_token' => $ownToken,
                'role' => '',
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'eigene Rolle');
            self::assertSame([AdminRole::Administrator->value, 'ROLE_USER'], $users->findOneByEmail($adminEmail)?->getRoles());
        } finally {
            static::ensureKernelShutdown();
            $cleanupClient = static::createClient();
            $this->removeUsers($cleanupClient, [$adminEmail, $unprovisionedEmail]);
            static::ensureKernelShutdown();
        }
    }

    public function testAdministratorCanAssignTheSeparateContentEditorRole(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $adminEmail = 'editor-role-admin-'.bin2hex(random_bytes(6)).'@example.test';
        $editorEmail = 'editor-role-target-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $adminEmail, 'EditorRoleAdmin!Password2026', AdminRole::Administrator);
            $this->persistUser($client, $editorEmail, 'EditorRoleTarget!Password2026', null);
            $this->submitLogin($client, $adminEmail, 'EditorRoleAdmin!Password2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/staff');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('select option[value="'.AdminRole::ContentEditor->value.'"]');

            $users = $client->getContainer()->get(AdminUserRepository::class);
            self::assertInstanceOf(AdminUserRepository::class, $users);
            $editor = $users->findOneByEmail($editorEmail);
            self::assertInstanceOf(AdminUser::class, $editor);
            $token = (string) $client->getCrawler()
                ->filter('form[id="staff-role-'.$editor->getId().'"] input[name="_token"]')
                ->attr('value');

            $client->request('POST', '/admin/staff/'.$editor->getId().'/role', [
                '_token' => $token,
                'role' => AdminRole::ContentEditor->value,
            ]);
            self::assertResponseRedirects('/admin/staff?page=1');
            self::assertSame(
                [AdminRole::ContentEditor->value, 'ROLE_USER'],
                $users->findOneByEmail($editorEmail)?->getRoles(),
            );
        } finally {
            static::ensureKernelShutdown();
            $cleanupClient = static::createClient();
            $this->removeUsers($cleanupClient, [$adminEmail, $editorEmail]);
            static::ensureKernelShutdown();
        }
    }

    public function testContentEditorCanPublishNewsButCannotCrossEditorialBoundaryOrDeleteArticles(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $suffix = bin2hex(random_bytes(6));
        $email = 'news-editor-'.$suffix.'@example.test';
        $password = 'NewsEditor-'.$suffix.'-Password!';
        $newsCategoryName = 'Editor News '.$suffix;
        $videoCategoryName = 'Editor Video '.$suffix;
        $articleTitle = 'Editor article '.$suffix;
        $articleSlug = 'editor-article-'.$suffix;
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $newsCategory = new ContentCategory($newsCategoryName, ContentCategoryType::News);
        $videoCategory = new ContentCategory($videoCategoryName, ContentCategoryType::Video);
        $videoOnlyTag = new ContentTag('Video-only '.$suffix);
        $video = new Video(
            'Editor boundary video '.$suffix,
            'A tag that must not be exposed to news editors.',
            $videoCategoryName,
            VideoSourceType::Url,
            'https://video.example.test/editor-boundary-'.$suffix.'.mp4',
            PublishingStatus::Draft,
            false,
            false,
        );
        $video->replaceTags([$videoOnlyTag]);
        $entityManager->persist($newsCategory);
        $entityManager->persist($videoCategory);
        $entityManager->persist($videoOnlyTag);
        $entityManager->persist($video);
        $entityManager->flush();

        try {
            $this->persistUser($client, $email, $password, AdminRole::ContentEditor);
            $this->submitLogin($client, $email, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin');
            self::assertResponseRedirects('/admin/news');
            $client->request('GET', '/admin/news');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'News und Artikel');
            self::assertStringNotContainsString(
                $videoOnlyTag->getName(),
                $client->getCrawler()->filter('select[name="tag"]')->text(),
            );
            self::assertSelectorExists('a[href="/admin/news"]');
            self::assertSelectorNotExists('a[href="/admin/videos"]');
            self::assertSelectorNotExists('a[href="/admin/staff"]');

            foreach (['/admin/videos', '/admin/pages', '/admin/comments', '/admin/tags', '/admin/staff'] as $forbiddenPath) {
                $client->request('GET', $forbiddenPath);
                self::assertResponseStatusCodeSame(403, $forbiddenPath);
            }

            $client->request('GET', '/admin/categories');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('table', $newsCategoryName);
            self::assertStringNotContainsString($videoCategoryName, $client->getCrawler()->filter('table')->text());
            $client->request('GET', '/admin/categories?type=video');
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/admin/categories/new?type=video');
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/admin/categories/'.$videoCategory->getId().'/edit');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/categories/'.$videoCategory->getId().'/artwork', [
                '_token' => 'invalid-token',
                'action' => 'remove',
            ]);
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/admin/categories/new?type=news');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('select[name="type"] option[value="news"]');
            self::assertSelectorNotExists('select[name="type"] option[value="video"]');
            $categoryToken = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/categories/new?type=news', [
                '_token' => $categoryToken,
                'name' => 'Rejected video '.$suffix,
                'type' => 'video',
            ]);
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/admin/news/new');
            self::assertResponseIsSuccessful();
            $newsToken = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/news/new', [
                '_token' => $newsToken,
                'title' => $articleTitle,
                'slug' => $articleSlug,
                'author_name' => 'Redaktionstest',
                'category' => $newsCategoryName,
                'tags' => '',
                'summary' => 'Ein redaktioneller Testbeitrag.',
                'body' => 'Der Beitrag wurde von einem News-Redakteur erstellt.',
                'status' => 'published',
                'publish_at' => '',
                'featured' => '1',
                'comments_enabled' => '1',
                'custom_fields_present' => '1',
                'custom_fields' => [],
            ]);
            self::assertResponseRedirects('/admin/news');
            $publishedArticleCount = $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM news_articles WHERE slug = :slug AND status = :status',
                ['slug' => $articleSlug, 'status' => 'published'],
            );
            self::assertIsInt($publishedArticleCount);
            self::assertSame(1, $publishedArticleCount);

            $client->request('GET', '/admin/news');
            self::assertSelectorNotExists('select[name="action"] option[value="delete"]');
            $bulkToken = (string) $client->getCrawler()->filter('form.admin-bulk-form input[name="_token"]')->attr('value');
            $articleId = $entityManager->getConnection()->fetchOne(
                'SELECT id FROM news_articles WHERE slug = :slug',
                ['slug' => $articleSlug],
            );
            self::assertIsString($articleId);

            $client->request('GET', '/admin/news/'.$articleId.'/edit');
            self::assertResponseIsSuccessful();
            $inlineImageToken = (string) $client->getCrawler()
                ->filter('.rich-text-widget')
                ->attr('data-rich-text-image-upload-token');
            self::assertNotSame('', $inlineImageToken);
            $client->request('POST', '/admin/content-inline-images/news/'.$articleId, ['_token' => $inlineImageToken]);
            self::assertResponseStatusCodeSame(422);
            $client->request('POST', '/admin/content-inline-images/page/'.str_repeat('a', 32), ['_token' => 'unused']);
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/admin/news/'.$articleId.'/thumbnail');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/admin/news/'.$articleId.'/edit');
            $client->submitForm('Änderungen speichern', [
                'title' => $articleTitle.' bearbeitet',
                'category' => $newsCategoryName,
                'summary' => 'Redaktionell überarbeiteter Vorspann.',
                'body' => 'Der News-Redakteur hat den Beitrag bearbeitet.',
                'status' => 'published',
            ]);
            self::assertResponseRedirects('/admin/news');
            self::assertSame($articleTitle.' bearbeitet', $entityManager->getConnection()->fetchOne(
                'SELECT title FROM news_articles WHERE id = :id',
                ['id' => $articleId],
            ));

            $client->request('POST', '/admin/news/bulk', [
                '_token' => $bulkToken,
                'action' => 'delete',
                'ids' => [$articleId],
                'confirm_delete' => '1',
            ]);
            self::assertResponseStatusCodeSame(403);
            $remainingArticleCount = $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM news_articles WHERE id = :id',
                ['id' => $articleId],
            );
            self::assertIsInt($remainingArticleCount);
            self::assertSame(1, $remainingArticleCount);
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $articleSlug]);
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $connection->delete('content_tags', ['id' => $videoOnlyTag->getId()]);
            $connection->delete('content_categories', ['id' => $newsCategory->getId()]);
            $connection->delete('content_categories', ['id' => $videoCategory->getId()]);
            static::ensureKernelShutdown();
            $cleanupClient = static::createClient();
            $this->removeUsers($cleanupClient, [$email]);
            static::ensureKernelShutdown();
        }
    }

    public function testStaleAdministratorIdentityCannotAssignRolesAfterItsDatabaseRoleChanges(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $adminEmail = 'staff-stale-admin-'.bin2hex(random_bytes(6)).'@example.test';
        $targetEmail = 'staff-stale-target-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $adminEmail, 'StaffStaleAdmin!Password2026', AdminRole::Administrator);
            $this->persistUser($client, $targetEmail, 'StaffStaleTarget!Password2026', AdminRole::CommentModerator);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $users = $entityManager->getRepository(AdminUser::class);
            $actor = $users->findOneBy(['email' => $adminEmail]);
            $target = $users->findOneBy(['email' => $targetEmail]);
            self::assertInstanceOf(AdminUser::class, $actor);
            self::assertInstanceOf(AdminUser::class, $target);

            $entityManager->getConnection()->executeStatement(
                'UPDATE admin_users SET roles = :roles WHERE id = :id',
                [
                    'roles' => json_encode([AdminRole::VideoModerator->value, 'ROLE_USER'], JSON_THROW_ON_ERROR),
                    'id' => $actor->getId(),
                ],
            );
            self::assertSame([AdminRole::Administrator->value, 'ROLE_USER'], $actor->getRoles(), 'The ORM identity is deliberately stale before reassignment.');

            $manager = $client->getContainer()->get(AdminModeratorAccessManager::class);
            self::assertInstanceOf(AdminModeratorAccessManager::class, $manager);
            self::assertSame(
                AdminRoleChangeResult::ActorNoLongerAdministrator,
                $manager->assign($actor, $target->getId(), AdminRole::VideoModerator, csrfVerified: true),
            );

            self::assertSame([AdminRole::CommentModerator->value, 'ROLE_USER'], $target->getRoles());
        } finally {
            static::ensureKernelShutdown();
            $cleanupClient = static::createClient();
            $this->removeUsers($cleanupClient, [$adminEmail, $targetEmail]);
            static::ensureKernelShutdown();
        }
    }

    public function testModeratorCannotConfigureStaffRoles(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $moderatorEmail = 'staff-denied-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $moderatorEmail, 'StaffDenied!Password2026', AdminRole::VideoModerator);
            $this->submitLogin($client, $moderatorEmail, 'StaffDenied!Password2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/staff');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/staff/'.str_repeat('a', 32).'/role', ['role' => AdminRole::Administrator->value]);
            self::assertResponseStatusCodeSame(403);
        } finally {
            static::ensureKernelShutdown();
            $cleanupClient = static::createClient();
            $this->removeUsers($cleanupClient, [$moderatorEmail]);
            static::ensureKernelShutdown();
        }
    }

    private function persistUser(KernelBrowser $client, string $email, string $password, ?AdminRole $role, bool $provisionMfa = true): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = new AdminUser($email);
        $user->setRoles(null === $role ? [] : [$role->value]);
        $user->setPassword($hasher->hashPassword($user, $password));

        if ($provisionMfa) {
            $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
            self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
            $provisioner->provision($user);
        } else {
            $entityManager->persist($user);
            $entityManager->flush();
        }
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $user = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(AdminUser::class, $user);
        $ciphertext = $user->getMfaSecretCiphertext();
        self::assertNotNull($ciphertext);
        $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
        self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
        $totp = $client->getContainer()->get(TotpVerifier::class);
        self::assertInstanceOf(TotpVerifier::class, $totp);
        $code = $totp->codeAtTime($cipher->decrypt($ciphertext), time());

        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => $email,
            '_password' => $password,
            '_mfa_code' => $code,
        ]);
    }

    /** @param list<string> $emails */
    private function removeUsers(KernelBrowser $client, array $emails): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        foreach ($emails as $email) {
            $id = $connection->fetchOne('SELECT id FROM admin_users WHERE email = :email', ['email' => $email]);
            if (!is_string($id)) {
                continue;
            }

            $connection->delete('admin_activity_entries', ['actor_id' => $id]);
            $connection->delete('admin_user_recovery_codes', ['admin_user_id' => $id]);
            $connection->delete('admin_users', ['id' => $id]);
        }
    }
}
