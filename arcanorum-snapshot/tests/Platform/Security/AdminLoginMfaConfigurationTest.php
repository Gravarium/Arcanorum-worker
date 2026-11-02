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
        $previousSecretServer = $_SERVER['APP_SECRET'] ?? null;
        $previousSecretEnv = $_ENV['APP_SECRET'] ?? null;
        $previousSecretProcess = getenv('APP_SECRET');
        $placeholderApplicationSecret = 'development-placeholder-not-a-secret-change-before-deployment';

        putenv('ADMIN_MFA_ENCRYPTION_KEY');
        unset($_SERVER['ADMIN_MFA_ENCRYPTION_KEY'], $_ENV['ADMIN_MFA_ENCRYPTION_KEY']);
        putenv('APP_SECRET='.$placeholderApplicationSecret);
        $_SERVER['APP_SECRET'] = $placeholderApplicationSecret;
        $_ENV['APP_SECRET'] = $placeholderApplicationSecret;

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
            $this->expectExceptionMessage('Die Admin-MFA-Verschlüsselung ist nicht sicher konfiguriert.');
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

            if (null === $previousSecretServer) {
                unset($_SERVER['APP_SECRET']);
            } else {
                $_SERVER['APP_SECRET'] = $previousSecretServer;
            }

            if (null === $previousSecretEnv) {
                unset($_ENV['APP_SECRET']);
            } else {
                $_ENV['APP_SECRET'] = $previousSecretEnv;
            }

            putenv(false === $previousSecretProcess ? 'APP_SECRET' : 'APP_SECRET='.$previousSecretProcess);

            static::ensureKernelShutdown();
        }
    }

    public function testLoginFormUsesApplicationSecretWhenDedicatedMfaKeyIsOmitted(): void
    {
        $previousMfaServer = $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] ?? null;
        $previousMfaEnv = $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] ?? null;
        $previousMfaProcess = getenv('ADMIN_MFA_ENCRYPTION_KEY');
        $previousSecretServer = $_SERVER['APP_SECRET'] ?? null;
        $previousSecretEnv = $_ENV['APP_SECRET'] ?? null;
        $previousSecretProcess = getenv('APP_SECRET');
        $applicationSecret = base64_encode(random_bytes(32));

        putenv('ADMIN_MFA_ENCRYPTION_KEY');
        unset($_SERVER['ADMIN_MFA_ENCRYPTION_KEY'], $_ENV['ADMIN_MFA_ENCRYPTION_KEY']);
        putenv('APP_SECRET='.$applicationSecret);
        $_SERVER['APP_SECRET'] = $applicationSecret;
        $_ENV['APP_SECRET'] = $applicationSecret;

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
            if (null === $previousMfaServer) {
                unset($_SERVER['ADMIN_MFA_ENCRYPTION_KEY']);
            } else {
                $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $previousMfaServer;
            }
            if (null === $previousMfaEnv) {
                unset($_ENV['ADMIN_MFA_ENCRYPTION_KEY']);
            } else {
                $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $previousMfaEnv;
            }
            putenv(false === $previousMfaProcess ? 'ADMIN_MFA_ENCRYPTION_KEY' : 'ADMIN_MFA_ENCRYPTION_KEY='.$previousMfaProcess);

            if (null === $previousSecretServer) {
                unset($_SERVER['APP_SECRET']);
            } else {
                $_SERVER['APP_SECRET'] = $previousSecretServer;
            }
            if (null === $previousSecretEnv) {
                unset($_ENV['APP_SECRET']);
            } else {
                $_ENV['APP_SECRET'] = $previousSecretEnv;
            }
            putenv(false === $previousSecretProcess ? 'APP_SECRET' : 'APP_SECRET='.$previousSecretProcess);

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
