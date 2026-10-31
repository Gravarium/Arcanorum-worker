<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\SiteSettings;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminDesignSettingsControllerTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $testKey = base64_encode(str_repeat('S', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$testKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;

        parent::setUpBeforeClass();
    }

    public function testAdministratorCanPreviewAndSaveAppearanceWithSafePublicStylesheet(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'design-admin-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $email, 'DesignAdmin!Password2026', AdminRole::Administrator);
            $this->submitLogin($client, $email, 'DesignAdmin!Password2026');
            self::assertResponseRedirects('/admin');
            $client->request('GET', '/admin/settings/design');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorExists('a[href="/admin/settings/design"]');
            self::assertSelectorExists('.theme-preview[data-preview-theme="arcanorum"]');
            $themeStoreLink = $client->getCrawler()->filter('a[href="https://www.phpsugar.com/phplegacy_templates.html"]');
            self::assertCount(1, $themeStoreLink);
            self::assertSame('_blank', $themeStoreLink->attr('target'));
            self::assertSame('noopener noreferrer', $themeStoreLink->attr('rel'));
            self::assertSame('no-referrer', $themeStoreLink->attr('referrerpolicy'));
            $token = (string) $client->getCrawler()->filter('form[action="/admin/settings/design"] input[name="_token"]')->attr('value');
            self::assertNotSame('', $token);

            $client->request('POST', '/admin/settings/design', [
                '_token' => 'invalid',
                'action' => 'save',
                'theme' => 'editorial',
                'layout' => 'magazine',
                'primary_color' => '#17345A',
                'accent_color' => '#28665E',
            ]);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/admin/settings/design', [
                '_token' => $token,
                'action' => 'save',
                'theme' => 'editorial',
                'layout' => 'magazine',
                'primary_color' => '#17345A',
                'accent_color' => '#28665E',
                'stylesheet' => 'https://unsafe.example/theme.css',
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'unbekannte Layout- oder Farbeinstellung');

            $client->request('POST', '/admin/settings/design', [
                '_token' => $token,
                'action' => 'save',
                'theme' => 'editorial',
                'layout' => 'magazine',
                'primary_color' => '#FFFFFF',
                'accent_color' => '#FFFFFF',
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'nicht genügend Kontrast');

            $previewInput = [
                '_token' => $token,
                'action' => 'preview',
                'theme' => 'editorial',
                'layout' => 'magazine',
                'primary_color' => '#17345A',
                'accent_color' => '#28665E',
                'heading_color' => '#1A2A3A',
                'content_background_color' => '#FFFDF8',
                'content_text_color' => '#182033',
                'body_background_color' => '#F3F5F9',
                'header_background_color' => '#152238',
                'header_pane_color' => '#F0F4FA',
                'navigation_background_color' => '#FFFFFF',
                'navigation_link_color' => '#24365E',
                'content_link_color' => '#17345A',
                'video_link_color' => '#182033',
                'site_title_color' => '#FFFFFF',
                'footer_background_color' => '#E8EDF5',
                'footer_link_color' => '#33445B',
                'content_width' => '1440',
            ];
            $client->request('POST', '/admin/settings/design', $previewInput);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('nicht gespeichert', (string) $client->getResponse()->getContent());
            self::assertSelectorExists('.theme-preview[data-preview-theme="editorial"][data-preview-layout="magazine"]');
            $previewStylesheet = (string) $client->getCrawler()->filter('link[rel="stylesheet"][href*="/preview/"]')->attr('href');
            self::assertStringContainsString('/admin/settings/design/preview/', $previewStylesheet);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $connection = $entityManager->getConnection();
            self::assertSame('arcanorum', $connection->fetchOne('SELECT public_theme FROM site_settings WHERE id = 1'));
            self::assertSame('grid', $connection->fetchOne('SELECT public_layout FROM site_settings WHERE id = 1'));

            $client->request('GET', $previewStylesheet);
            self::assertResponseIsSuccessful();
            self::assertSame('text/css; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('.theme-preview[data-preview-theme="editorial"]', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('--site-primary: #17345A', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('--site-content-width: 1440px', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('--site-header-background: #152238', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('unsafe.example', (string) $client->getResponse()->getContent());

            $client->request('POST', '/admin/settings/design', [
                ...$previewInput,
                'action' => 'save',
            ]);
            self::assertResponseRedirects('/admin/settings/design');
            self::assertSame('editorial', $connection->fetchOne('SELECT public_theme FROM site_settings WHERE id = 1'));
            self::assertSame('magazine', $connection->fetchOne('SELECT public_layout FROM site_settings WHERE id = 1'));
            self::assertSame('#17345A', $connection->fetchOne('SELECT theme_primary_color FROM site_settings WHERE id = 1'));
            self::assertSame('#28665E', $connection->fetchOne('SELECT theme_accent_color FROM site_settings WHERE id = 1'));
            self::assertSame('1440', $connection->fetchOne('SELECT CAST(theme_content_width AS VARCHAR) FROM site_settings WHERE id = 1'));
            self::assertSame('#152238', $connection->fetchOne('SELECT theme_header_background_color FROM site_settings WHERE id = 1'));
            self::assertNotFalse($connection->fetchOne(
                "SELECT id FROM admin_activity_entries WHERE route_name = 'admin_design_settings_update' LIMIT 1",
            ));

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('data-public-theme="editorial" data-public-layout="magazine"', (string) $client->getResponse()->getContent());

            $client->request('GET', '/site-theme.css');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('max-age=0, must-revalidate, public', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('html[data-public-theme="editorial"]', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('--site-primary: #17345A', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('--site-content-width: 1440px', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('--site-navigation-link: #24365E', (string) $client->getResponse()->getContent());
            $etag = (string) $client->getResponse()->headers->get('ETag');
            self::assertNotSame('', $etag);
            $client->request('GET', '/site-theme.css', server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(304);
        } finally {
            $this->restoreDefaults($client, $email);
        }
    }

    public function testModeratorCannotReadOrChangeDesignSettings(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'design-moderator-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $email, 'DesignModerator!Password2026', AdminRole::VideoModerator);
            $this->submitLogin($client, $email, 'DesignModerator!Password2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/settings/design');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/settings/design', [
                'action' => 'save',
                'theme' => 'arcade',
                'layout' => 'compact',
                'primary_color' => '#17345A',
                'accent_color' => '#28665E',
            ]);
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/admin/settings/design/preview/'.str_repeat('a', 64).'.css');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/settings/design/logo', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/settings/design/logo/remove', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            self::assertSame('arcanorum', $entityManager->getConnection()->fetchOne('SELECT public_theme FROM site_settings WHERE id = 1'));
        } finally {
            $this->restoreDefaults($client, $email);
        }
    }

    public function testAdministratorCanUploadAndRemoveSanitizedLogoWithCsrfAndPrivateStorage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'design-logo-admin-'.bin2hex(random_bytes(6)).'@example.test';
        $storageCapture = new AdminDesignLogoStorageCapture();
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::exactly(2))->method('write')
            ->willReturnCallback(static function (string $storedId, string $storedContents) use ($storageCapture): void {
                $storageCapture->store($storedId, $storedContents);
            });
        $storage->expects(self::exactly(2))->method('read')
            ->willReturnCallback(static function (string $requestedId, int $maximumBytes) use ($storageCapture): string {
                $contents = $storageCapture->contents($requestedId);
                self::assertSame(strlen($contents), $maximumBytes);

                return $contents;
            });
        $storage->expects(self::never())->method('delete');
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $storage);
        $uploadPaths = [];

        try {
            $this->persistUser($client, $email, 'DesignLogo!Password2026', AdminRole::Administrator);
            $this->submitLogin($client, $email, 'DesignLogo!Password2026');
            $client->request('GET', '/admin/settings/design');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()
                ->filter('form[action="/admin/settings/design/logo"] input[name="_token"]')
                ->attr('value');
            self::assertNotSame('', $token);

            $uploadPath = tempnam(sys_get_temp_dir(), 'arcanorum-admin-logo-');
            $uploadPaths[] = $uploadPath;
            self::assertIsString($uploadPath);
            $logoContents = $this->logoPng(0x1A70B1);
            self::assertSame(strlen($logoContents), file_put_contents($uploadPath, $logoContents));
            $upload = new UploadedFile($uploadPath, 'site-logo.png', 'image/png', UPLOAD_ERR_OK, true);

            $client->request('POST', '/admin/settings/design/logo', ['_token' => 'invalid'], ['logo' => $upload]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame([], $storageCapture->objectIds());

            $client->request(
                'POST',
                '/admin/settings/design/logo',
                ['_token' => $token, 'unexpected' => 'reject'],
                ['logo' => $upload],
            );
            self::assertResponseStatusCodeSame(422);
            self::assertSame([], $storageCapture->objectIds());

            $client->request('POST', '/admin/settings/design/logo', ['_token' => $token], ['logo' => $upload]);
            self::assertResponseRedirects('/admin/settings/design');
            self::assertCount(1, $storageCapture->objectIds());
            $firstObjectId = $storageCapture->objectIdAt(0);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $firstObjectId);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $connection = $entityManager->getConnection();
            self::assertSame($firstObjectId, $connection->fetchOne('SELECT site_logo_object_id FROM site_settings WHERE id = 1'));
            self::assertSame('image/png', $connection->fetchOne('SELECT site_logo_mime_type FROM site_settings WHERE id = 1'));
            $firstSha256 = $connection->fetchOne('SELECT CAST(site_logo_sha256 AS VARCHAR) FROM site_settings WHERE id = 1');
            self::assertIsString($firstSha256);

            $client->request('GET', '/site-logo/'.$firstSha256);
            self::assertResponseIsSuccessful();
            self::assertSame($storageCapture->contents($firstObjectId), $client->getResponse()->getContent());
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

            $replacementPath = tempnam(sys_get_temp_dir(), 'arcanorum-admin-logo-');
            $uploadPaths[] = $replacementPath;
            self::assertIsString($replacementPath);
            $replacementContents = $this->logoPng(0xD03040);
            self::assertSame(strlen($replacementContents), file_put_contents($replacementPath, $replacementContents));
            $replacement = new UploadedFile($replacementPath, 'site-logo-replacement.png', 'image/png', UPLOAD_ERR_OK, true);
            $client->request('POST', '/admin/settings/design/logo', ['_token' => $token], ['logo' => $replacement]);
            self::assertResponseRedirects('/admin/settings/design');
            self::assertCount(2, $storageCapture->objectIds());
            $currentObjectId = $storageCapture->objectIdAt(1);
            self::assertNotSame($firstObjectId, $currentObjectId);
            self::assertSame('1', $connection->fetchOne(
                'SELECT CAST(COUNT(*) AS VARCHAR) FROM private_object_deletion_jobs WHERE object_id = :object_id',
                ['object_id' => $firstObjectId],
            ));
            $currentSha256 = $connection->fetchOne('SELECT CAST(site_logo_sha256 AS VARCHAR) FROM site_settings WHERE id = 1');
            self::assertIsString($currentSha256);
            self::assertNotSame($firstSha256, $currentSha256);

            $client->request('GET', '/site-logo/'.$firstSha256);
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/site-logo/'.$currentSha256);
            self::assertResponseIsSuccessful();
            self::assertSame($storageCapture->contents($currentObjectId), $client->getResponse()->getContent());

            $client->request('POST', '/admin/settings/design/logo/remove', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/settings/design/logo/remove', ['_token' => $token]);
            self::assertResponseRedirects('/admin/settings/design');
            self::assertNull($connection->fetchOne('SELECT site_logo_object_id FROM site_settings WHERE id = 1'));
            self::assertSame('1', $connection->fetchOne(
                'SELECT CAST(COUNT(*) AS VARCHAR) FROM private_object_deletion_jobs WHERE object_id = :object_id',
                ['object_id' => $currentObjectId],
            ));

            $client->request('GET', '/site-logo/'.$currentSha256);
            self::assertResponseStatusCodeSame(404);
        } finally {
            foreach ($uploadPaths as $uploadPath) {
                if (is_string($uploadPath) && is_file($uploadPath)) {
                    unlink($uploadPath);
                }
            }
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($entityManager instanceof EntityManagerInterface) {
                foreach ($storageCapture->objectIds() as $objectId) {
                    $entityManager->getConnection()->executeStatement(
                        'UPDATE site_settings SET site_logo_object_id = NULL, site_logo_mime_type = NULL, site_logo_size = NULL, site_logo_sha256 = NULL WHERE site_logo_object_id = :object_id',
                        ['object_id' => $objectId],
                    );
                    $entityManager->getConnection()->delete('private_object_deletion_jobs', ['object_id' => $objectId]);
                }
            }
            $this->restoreDefaults($client, $email);
        }
    }

    private function logoPng(int $rgb): string
    {
        $image = imagecreatetruecolor(80, 40);
        self::assertInstanceOf(\GdImage::class, $image);
        $color = imagecolorallocate($image, ($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255);
        if (false === $color) {
            throw new \RuntimeException('Could not allocate the logo fixture color.');
        }
        imagefill($image, 0, 0, $color);

        ob_start();
        try {
            imagepng($image);
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($image);
        }

        self::assertIsString($contents);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $contents);

        return $contents;
    }

    private function persistUser(KernelBrowser $client, string $email, string $password, AdminRole $role): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = new AdminUser($email);
        $user->setRoles([$role->value]);
        $user->setPassword($hasher->hashPassword($user, $password));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($user);
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

    private function restoreDefaults(KernelBrowser $client, string $email): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $connection = $entityManager->getConnection();
        $connection->executeStatement(
            "UPDATE site_settings SET public_theme = 'arcanorum', public_layout = 'grid', theme_primary_color = :primary, theme_accent_color = :accent,
                theme_heading_color = :heading, theme_content_background_color = :content_background, theme_content_text_color = :content_text,
                theme_body_background_color = :body_background, theme_header_background_color = :header_background, theme_header_pane_color = :header_pane,
                theme_navigation_background_color = :navigation_background, theme_navigation_link_color = :navigation_link,
                theme_content_link_color = :content_link, theme_video_link_color = :video_link, theme_site_title_color = :site_title,
                theme_footer_background_color = :footer_background, theme_footer_link_color = :footer_link, theme_content_width = :content_width
             WHERE id = 1",
            [
                'primary' => SiteSettings::DEFAULT_PRIMARY_COLOR,
                'accent' => SiteSettings::DEFAULT_ACCENT_COLOR,
                'heading' => SiteSettings::DEFAULT_HEADING_COLOR,
                'content_background' => SiteSettings::DEFAULT_CONTENT_BACKGROUND_COLOR,
                'content_text' => SiteSettings::DEFAULT_CONTENT_TEXT_COLOR,
                'body_background' => SiteSettings::DEFAULT_BODY_BACKGROUND_COLOR,
                'header_background' => SiteSettings::DEFAULT_HEADER_BACKGROUND_COLOR,
                'header_pane' => SiteSettings::DEFAULT_HEADER_PANE_COLOR,
                'navigation_background' => SiteSettings::DEFAULT_NAVIGATION_BACKGROUND_COLOR,
                'navigation_link' => SiteSettings::DEFAULT_NAVIGATION_LINK_COLOR,
                'content_link' => SiteSettings::DEFAULT_CONTENT_LINK_COLOR,
                'video_link' => SiteSettings::DEFAULT_VIDEO_LINK_COLOR,
                'site_title' => SiteSettings::DEFAULT_SITE_TITLE_COLOR,
                'footer_background' => SiteSettings::DEFAULT_FOOTER_BACKGROUND_COLOR,
                'footer_link' => SiteSettings::DEFAULT_FOOTER_LINK_COLOR,
                'content_width' => SiteSettings::DEFAULT_CONTENT_WIDTH,
            ],
        );
        $id = $connection->fetchOne('SELECT id FROM admin_users WHERE email = :email', ['email' => $email]);
        if (is_string($id)) {
            $connection->delete('admin_activity_entries', ['actor_id' => $id]);
            $connection->delete('admin_users', ['id' => $id]);
        }
    }
}

final class AdminDesignLogoStorageCapture
{
    /** @var list<string> */
    private array $objectIds = [];

    /** @var array<string, string> */
    private array $contentsByObject = [];

    public function store(string $objectId, string $contents): void
    {
        $this->objectIds[] = $objectId;
        $this->contentsByObject[$objectId] = $contents;
    }

    /** @return list<string> */
    public function objectIds(): array
    {
        return $this->objectIds;
    }

    public function objectIdAt(int $index): string
    {
        return $this->objectIds[$index] ?? throw new \LogicException('A captured logo object is missing.');
    }

    public function contents(string $objectId): string
    {
        return $this->contentsByObject[$objectId] ?? throw new \LogicException('Captured logo contents are missing.');
    }
}
