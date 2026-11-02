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
}
