<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\AdminMfaSecretCipher;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminLoginMfaConfigurationTest extends WebTestCase
{
    public function testLoginFormRemainsReachableButMfaCryptographyFailsClosedWithoutKey(): void
    {
        $previousServerValue = $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] ?? null;
        $previousEnvValue = $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] ?? null;
        $previousProcessValue = getenv('ADMIN_MFA_ENCRYPTION_KEY');

        putenv('ADMIN_MFA_ENCRYPTION_KEY');
        unset($_SERVER['ADMIN_MFA_ENCRYPTION_KEY'], $_ENV['ADMIN_MFA_ENCRYPTION_KEY']);

        try {
            $client = static::createClient();
            $client->request('GET', '/admin/login');

            self::assertResponseIsSuccessful();

            $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
            self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
            self::assertFalse($cipher->isConfigured());
            self::assertSelectorTextContains(
                'main',
                'Die Verwaltungsanmeldung ist vorübergehend nicht verfügbar. Die erforderliche Sicherheitskonfiguration ist nicht eingerichtet.',
            );
            self::assertSelectorNotExists('form[action="/admin/login"]');

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('ADMIN_MFA_ENCRYPTION_KEY muss ein Base64-kodierter 32-Byte-Schlüssel sein.');
            $cipher->encrypt(random_bytes(20));
        } finally {
            if (null === $previousServerValue) {
                unset($_SERVER['ADMIN_MFA_ENCRYPTION_KEY']);
            } else {
                $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $previousServerValue;
            }

            if (null === $previousEnvValue) {
                unset($_ENV['ADMIN_MFA_ENCRYPTION_KEY']);
            } else {
                $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $previousEnvValue;
            }

            if (false === $previousProcessValue) {
                putenv('ADMIN_MFA_ENCRYPTION_KEY');
            } else {
                putenv('ADMIN_MFA_ENCRYPTION_KEY='.$previousProcessValue);
            }

            static::ensureKernelShutdown();
        }
    }

    public function testLoginFormIsRenderedWhenMfaKeyIsConfigured(): void
    {
        $previousServerValue = $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] ?? null;
        $previousEnvValue = $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] ?? null;
        $previousProcessValue = getenv('ADMIN_MFA_ENCRYPTION_KEY');
        $configuredKey = base64_encode(str_repeat('K', 32));

        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$configuredKey);
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $configuredKey;
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $configuredKey;

        try {
            $client = static::createClient();
            $client->request('GET', '/admin/login');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="/admin/login"]');
            self::assertSelectorNotExists('p[role="status"]');

            $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
            self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
            self::assertTrue($cipher->isConfigured());
        } finally {
            if (null === $previousServerValue) {
                unset($_SERVER['ADMIN_MFA_ENCRYPTION_KEY']);
            } else {
                $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $previousServerValue;
            }

            if (null === $previousEnvValue) {
                unset($_ENV['ADMIN_MFA_ENCRYPTION_KEY']);
            } else {
                $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $previousEnvValue;
            }

            if (false === $previousProcessValue) {
                putenv('ADMIN_MFA_ENCRYPTION_KEY');
            } else {
                putenv('ADMIN_MFA_ENCRYPTION_KEY='.$previousProcessValue);
            }

            static::ensureKernelShutdown();
        }
    }
}
