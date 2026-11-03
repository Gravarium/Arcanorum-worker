<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\TotpVerifier;
use App\Repository\AdminUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InstallerAdministratorControllerTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $testKey = base64_encode(str_repeat('I', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$testKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;

        parent::setUpBeforeClass();
    }

    public function testFirstAdministratorIsSavedOnlyAfterTotpConfirmationAndRecoveryCodesAreShownOnce(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '192.0.2.'.random_int(1, 254));

        $adminUsers = $client->getContainer()->get(AdminUserRepository::class);
        self::assertInstanceOf(AdminUserRepository::class, $adminUsers);
        $managerRegistry = $client->getContainer()->get(ManagerRegistry::class);
        self::assertInstanceOf(ManagerRegistry::class, $managerRegistry);
        $entityManager = $managerRegistry->getManagerForClass(AdminUser::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $client->getContainer()->set(AdminUserRepository::class, $adminUsers);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM admin_users');
        $entityManager->clear();
        $adminUsers = $client->getContainer()->get(AdminUserRepository::class);
        self::assertInstanceOf(AdminUserRepository::class, $adminUsers);
        self::assertSame(0, $adminUsers->countAdministratorsForRoleManagement());

        $email = 'first-admin-'.bin2hex(random_bytes(6)).'@example.test';
        $password = bin2hex(random_bytes(12)).'Aa!';

        try {
            $client->request('GET', '/install/administrator');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('private', strtolower((string) $client->getResponse()->headers->get('Cache-Control')));
            self::assertStringContainsString('no-store', strtolower((string) $client->getResponse()->headers->get('Cache-Control')));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorExists('#installer-first-admin-credentials-form');
            $credentialsToken = $this->csrfToken($client);

            $client->request('POST', '/install/administrator', [
                'token' => 'invalid',
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
            ]);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/install/administrator', [
                'token' => $credentialsToken,
                'email' => $email,
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);
            self::assertResponseStatusCodeSame(422);
            $adminUsers = $client->getContainer()->get(AdminUserRepository::class);
            self::assertInstanceOf(AdminUserRepository::class, $adminUsers);
            self::assertSame(0, $adminUsers->countAdministratorsForRoleManagement());

            $client->request('POST', '/install/administrator', [
                'token' => $credentialsToken,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
            ]);
            self::assertResponseRedirects('/install/administrator/mfa', 303);
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('#installer-first-admin-mfa-form');

            $secretBase32 = trim($client->getCrawler()->filter('#installer-totp-secret')->text());
            self::assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $secretBase32);
            $secret = $this->decodeBase32($secretBase32);
            self::assertSame(20, strlen($secret));

            $totp = $client->getContainer()->get(TotpVerifier::class);
            self::assertInstanceOf(TotpVerifier::class, $totp);
            $invalidCode = $this->nonMatchingCode($totp, $secret);
            $mfaToken = $this->csrfToken($client);
            $client->request('POST', '/install/administrator/mfa', [
                'token' => $mfaToken,
                'code' => $invalidCode,
            ]);
            self::assertResponseStatusCodeSame(422);
            $adminUsers = $client->getContainer()->get(AdminUserRepository::class);
            self::assertInstanceOf(AdminUserRepository::class, $adminUsers);
            self::assertSame(0, $adminUsers->countAdministratorsForRoleManagement());

            $client->request('GET', '/install/administrator/mfa');
            self::assertResponseIsSuccessful();
            $mfaToken = $this->csrfToken($client);
            $client->request('POST', '/install/administrator/mfa', [
                'token' => $mfaToken,
                'code' => $totp->codeAtTime($secret, time()),
            ]);
            self::assertResponseRedirects('/install/administrator/recovery', 303);

            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('#installer-recovery-codes');
            self::assertSame(10, $client->getCrawler()->filter('#installer-recovery-codes li code')->count());
            self::assertStringNotContainsString($secretBase32, (string) $client->getResponse()->getContent());

            $codes = $client->getCrawler()->filter('#installer-recovery-codes li code')->each(
                static fn (Crawler $node): string => trim($node->text()),
            );
            self::assertCount(10, array_unique($codes));

            $client->request('GET', '/install/administrator/recovery');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/install/administrator');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/install/security-profile');
            self::assertResponseStatusCodeSame(404);

            $adminUsers = $client->getContainer()->get(AdminUserRepository::class);
            self::assertInstanceOf(AdminUserRepository::class, $adminUsers);
            $admin = $adminUsers->findOneByEmail($email);
            self::assertInstanceOf(AdminUser::class, $admin);
            self::assertContains(AdminRole::Administrator->value, $admin->getRoles());
            self::assertNotNull($admin->getMfaSecretCiphertext());

            $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
            $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
            self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
            self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
            self::assertSame($secret, $cipher->decrypt($admin->getMfaSecretCiphertext()));
            self::assertTrue($hasher->isPasswordValid($admin, $password));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testFirstAdministratorSetupAttemptsAreRateLimited(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '198.51.100.'.random_int(1, 254));

        $adminUsers = $client->getContainer()->get(AdminUserRepository::class);
        self::assertInstanceOf(AdminUserRepository::class, $adminUsers);
        $managerRegistry = $client->getContainer()->get(ManagerRegistry::class);
        self::assertInstanceOf(ManagerRegistry::class, $managerRegistry);
        $entityManager = $managerRegistry->getManagerForClass(AdminUser::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $client->getContainer()->set(AdminUserRepository::class, $adminUsers);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM admin_users');
        $entityManager->clear();
        $adminUsers = $client->getContainer()->get(AdminUserRepository::class);
        self::assertInstanceOf(AdminUserRepository::class, $adminUsers);
        self::assertSame(0, $adminUsers->countAdministratorsForRoleManagement());

        try {
            $client->request('GET', '/install/administrator');
            self::assertResponseIsSuccessful();
            $token = $this->csrfToken($client);
            $password = bin2hex(random_bytes(12)).'Aa!';

            for ($attempt = 0; $attempt < 10; ++$attempt) {
                $client->request('POST', '/install/administrator', [
                    'token' => $token,
                    'email' => 'not-an-email',
                    'password' => $password,
                    'password_confirmation' => $password,
                ]);
                self::assertResponseStatusCodeSame(422);
            }

            $client->request('POST', '/install/administrator', [
                'token' => $token,
                'email' => 'not-an-email',
                'password' => $password,
                'password_confirmation' => $password,
            ]);
            self::assertResponseStatusCodeSame(429);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    private function csrfToken(KernelBrowser $client): string
    {
        $token = $client->getCrawler()->filter('input[name="token"]')->attr('value');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }

    private function decodeBase32(string $encoded): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $decoded = '';

        foreach (str_split($encoded) as $character) {
            $value = strpos($alphabet, $character);
            self::assertNotFalse($value);
            $buffer = ($buffer << 5) | $value;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 255);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $decoded;
    }

    private function nonMatchingCode(TotpVerifier $totp, string $secret): string
    {
        $validCodes = [];
        $now = time();
        for ($offset = -1; $offset <= 1; ++$offset) {
            $validCodes[] = $totp->codeAtTime($secret, $now + 30 * $offset);
        }

        for ($candidate = 0; $candidate < 1_000_000; ++$candidate) {
            $code = sprintf('%06d', $candidate);
            if (!in_array($code, $validCodes, true)) {
                return $code;
            }
        }

        self::fail('Could not produce a non-matching TOTP value.');
    }
}
