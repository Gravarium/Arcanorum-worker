<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\SiteSettings;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            self::assertSame('arcanorum', $entityManager->getConnection()->fetchOne('SELECT public_theme FROM site_settings WHERE id = 1'));
        } finally {
            $this->restoreDefaults($client, $email);
        }
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
            "UPDATE site_settings SET public_theme = 'arcanorum', public_layout = 'grid', theme_primary_color = :primary, theme_accent_color = :accent WHERE id = 1",
            ['primary' => SiteSettings::DEFAULT_PRIMARY_COLOR, 'accent' => SiteSettings::DEFAULT_ACCENT_COLOR],
        );
        $id = $connection->fetchOne('SELECT id FROM admin_users WHERE email = :email', ['email' => $email]);
        if (is_string($id)) {
            $connection->delete('admin_activity_entries', ['actor_id' => $id]);
            $connection->delete('admin_users', ['id' => $id]);
        }
    }
}
