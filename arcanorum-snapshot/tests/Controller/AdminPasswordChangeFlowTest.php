<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminActivityEntry;
use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class AdminPasswordChangeFlowTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $testKey = base64_encode(str_repeat('P', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$testKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;

        parent::setUpBeforeClass();
    }

    public function testAdministratorCanChangeOwnPasswordAndMustAuthenticateAgain(): void
    {
        $client = $this->createAuthenticatedClient();
        $email = 'admin-password-'.bin2hex(random_bytes(6)).'@example.test';
        $currentPassword = 'CurrentAdmin!Password2026';
        $newPassword = 'ReplacementAdmin!Password2026';

        try {
            $this->persistUser($client, $email, $currentPassword, AdminRole::Administrator);
            $this->submitLogin($client, $email, $currentPassword);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/password');
            self::assertResponseIsSuccessful();
            $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('private', $cacheControl);
            self::assertStringContainsString('no-store', $cacheControl);
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorExists('form[action="/admin/password"] input[name="current_password"]');
            self::assertSelectorExists('a[href="/admin/password"]');

            $token = $this->csrfToken($client);
            $client->request('POST', '/admin/password', [
                '_token' => $token,
                'current_password' => $currentPassword,
                'new_password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);
            self::assertResponseRedirects('/admin/login');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $administrator = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(AdminUser::class, $administrator);
            $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
            self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
            self::assertTrue($hasher->isPasswordValid($administrator, $newPassword));
            self::assertFalse($hasher->isPasswordValid($administrator, $currentPassword));

            $client->request('GET', '/admin');
            self::assertResponseRedirects('/admin/login');

            $entries = $entityManager->getRepository(AdminActivityEntry::class)->findBy(['actor' => $administrator]);
            self::assertCount(1, $entries);
            self::assertSame('admin_password_change', $entries[0]->getRouteName());
            self::assertSame('Kontosicherheit', $entries[0]->getAreaLabel());
            self::assertSame('Eigenes Administratorpasswort geändert', $entries[0]->getActionLabel());
        } finally {
            static::ensureKernelShutdown();
            $this->removeUsers(static::createClient(), [$email]);
            static::ensureKernelShutdown();
        }
    }

    public function testPasswordChangeRejectsInvalidCsrfFieldsWeakPasswordsAndWrongCurrentPassword(): void
    {
        $client = $this->createAuthenticatedClient();
        $email = 'admin-password-invalid-'.bin2hex(random_bytes(6)).'@example.test';
        $currentPassword = 'ValidAdmin!Password2026';
        $newPassword = 'ReplacementAdmin!Password2026';

        try {
            $this->persistUser($client, $email, $currentPassword, AdminRole::Administrator);
            $this->submitLogin($client, $email, $currentPassword);
            $client->request('GET', '/admin/password');
            $token = $this->csrfToken($client);

            $validFields = [
                'current_password' => $currentPassword,
                'new_password' => $newPassword,
                'password_confirmation' => $newPassword,
            ];
            $client->request('POST', '/admin/password', ['_token' => 'invalid'] + $validFields);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/admin/password', [
                '_token' => $token,
                'current_password' => $currentPassword,
                'new_password' => 'TooShort!2026',
                'password_confirmation' => 'TooShort!2026',
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'mindestens 16 Zeichen');

            $client->request('POST', '/admin/password', [
                '_token' => $token,
                'current_password' => $currentPassword,
                'new_password' => $newPassword,
                'password_confirmation' => 'DifferentReplacement!Password2026',
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'stimmen nicht überein');

            $client->request('POST', '/admin/password', ['_token' => $token] + $validFields + ['unexpected' => 'value']);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'unbekannte Passwortfelder');

            $client->request('POST', '/admin/password', [
                '_token' => $token,
                'current_password' => ['unexpected'],
                'new_password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'gültige Passwörter');

            $client->request('POST', '/admin/password', [
                '_token' => $token,
                'current_password' => $currentPassword,
                'new_password' => str_repeat('A', 4097),
                'password_confirmation' => str_repeat('A', 4097),
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'zulässigen Länge');

            $client->request('POST', '/admin/password', [
                '_token' => $token,
                'current_password' => $currentPassword,
                'new_password' => $currentPassword,
                'password_confirmation' => $currentPassword,
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'vom aktuellen Passwort unterscheiden');

            $client->request('POST', '/admin/password', [
                '_token' => $token,
                'current_password' => 'IncorrectAdmin!Password2026',
                'new_password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'aktuelle Passwort ist falsch');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $administrator = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(AdminUser::class, $administrator);
            $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
            self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
            self::assertTrue($hasher->isPasswordValid($administrator, $currentPassword));
            self::assertFalse($hasher->isPasswordValid($administrator, $newPassword));
        } finally {
            static::ensureKernelShutdown();
            $this->removeUsers(static::createClient(), [$email]);
            static::ensureKernelShutdown();
        }
    }

    public function testPasswordChangeRateLimitRejectsFurtherCurrentPasswordAttempts(): void
    {
        $client = $this->createAuthenticatedClient();
        $email = 'admin-password-limit-'.bin2hex(random_bytes(6)).'@example.test';
        $currentPassword = 'RateLimitAdmin!Password2026';
        $newPassword = 'RateLimitReplacement!Password2026';

        try {
            $this->persistUser($client, $email, $currentPassword, AdminRole::Administrator);
            $this->submitLogin($client, $email, $currentPassword);
            $client->request('GET', '/admin/password');
            $token = $this->csrfToken($client);

            for ($attempt = 0; $attempt < 5; ++$attempt) {
                $client->request('POST', '/admin/password', [
                    '_token' => $token,
                    'current_password' => 'WrongPassword!'.bin2hex(random_bytes(5)),
                    'new_password' => $newPassword,
                    'password_confirmation' => $newPassword,
                ]);
                self::assertResponseStatusCodeSame(422);
            }

            $client->request('POST', '/admin/password', [
                '_token' => $token,
                'current_password' => $currentPassword,
                'new_password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);
            self::assertResponseStatusCodeSame(429);
        } finally {
            static::ensureKernelShutdown();
            $this->removeUsers(static::createClient(), [$email]);
            static::ensureKernelShutdown();
        }
    }

    public function testPasswordChangeFailsClosedWhenRateLimitStorageIsUnavailable(): void
    {
        $client = $this->createAuthenticatedClient();
        $email = 'admin-password-limiter-failure-'.bin2hex(random_bytes(6)).'@example.test';
        $currentPassword = 'LimiterFailureAdmin!Password2026';
        $newPassword = 'LimiterFailureReplacement!Password2026';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        try {
            $this->persistUser($client, $email, $currentPassword, AdminRole::Administrator);
            $this->submitLogin($client, $email, $currentPassword);
            $administrator = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(AdminUser::class, $administrator);
            $originalPasswordHash = $entityManager->getConnection()->fetchOne(
                'SELECT password FROM admin_users WHERE id = :id',
                ['id' => $administrator->getId()],
            );
            self::assertIsString($originalPasswordHash);

            $limiter = $this->createMock(RateLimiterFactoryInterface::class);
            $limiter->expects(self::once())
                ->method('create')
                ->with(self::callback(static fn (mixed $key): bool => is_string($key)
                    && 1 === preg_match('/\A[a-f0-9]{64}\z/D', $key)))
                ->willThrowException(new \RuntimeException('Private rate storage detail.'));
            $client->getContainer()->set('limiter.admin_password_change', $limiter);

            $client->request('GET', '/admin/password');
            self::assertResponseIsSuccessful();
            $token = $this->csrfToken($client);
            $client->request('POST', '/admin/password', [
                '_token' => $token,
                'current_password' => $currentPassword,
                'new_password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);

            self::assertResponseStatusCodeSame(503);
            self::assertCacheControlHasDirectives($client->getResponse()->headers->get('Cache-Control'), 'private', 'no-store');
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertStringNotContainsString('Private rate storage detail.', (string) $client->getResponse()->getContent());
            self::assertSame(0, self::integerValue($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM admin_activity_entries WHERE actor_id = '
                .'(SELECT id FROM admin_users WHERE email = :email) AND route_name = :route',
                ['email' => $email, 'route' => 'admin_password_change'],
            )));
            self::assertSame(
                $originalPasswordHash,
                $entityManager->getConnection()->fetchOne('SELECT password FROM admin_users WHERE id = :id', ['id' => $administrator->getId()]),
            );
        } finally {
            static::ensureKernelShutdown();
            $this->removeUsers(static::createClient(), [$email]);
            static::ensureKernelShutdown();
        }
    }

    public function testPasswordChangeKeepsPersistedAndInMemoryCredentialsUnchangedWhenFlushFails(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->getContainer()->set('logger', new NullLogger());
        $email = 'admin-password-db-failure-'.bin2hex(random_bytes(6)).'@example.test';
        $currentPassword = 'DatabaseFailureAdmin!Password2026';
        $newPassword = 'DatabaseFailureReplacement!Password2026';
        $triggerSuffix = bin2hex(random_bytes(8));
        $triggerName = 'test_fail_admin_password_activity_'.$triggerSuffix;
        $functionName = 'test_fail_admin_password_activity_fn_'.$triggerSuffix;
        $functionCreated = false;
        $triggerCreated = false;
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        try {
            $this->persistUser($client, $email, $currentPassword, AdminRole::Administrator);
            $this->submitLogin($client, $email, $currentPassword);
            $administrator = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(AdminUser::class, $administrator);
            $originalPasswordHash = $entityManager->getConnection()->fetchOne(
                'SELECT password FROM admin_users WHERE id = :id',
                ['id' => $administrator->getId()],
            );
            self::assertIsString($originalPasswordHash);

            // Make only the password-change audit insert fail at the database boundary. This
            // exercises the real EntityManager error path without replacing an initialized
            // Doctrine service inside Symfony's test container.
            $connection = $entityManager->getConnection();
            $connection->executeStatement(
                "CREATE FUNCTION {$functionName}() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF NEW.route_name = 'admin_password_change' THEN
                        RAISE EXCEPTION 'Private database detail.';
                    END IF;
                    RETURN NEW;
                END
                \$\$",
            );
            $functionCreated = true;
            $connection->executeStatement(
                "CREATE TRIGGER {$triggerName} BEFORE INSERT ON admin_activity_entries FOR EACH ROW EXECUTE FUNCTION {$functionName}()",
            );
            $triggerCreated = true;

            $client->request('GET', '/admin/password');
            self::assertResponseIsSuccessful();
            $token = $this->csrfToken($client);
            $client->request('POST', '/admin/password', [
                '_token' => $token,
                'current_password' => $currentPassword,
                'new_password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);

            self::assertResponseStatusCodeSame(503);
            self::assertCacheControlHasDirectives($client->getResponse()->headers->get('Cache-Control'), 'private', 'no-store');
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertStringNotContainsString('Private database detail.', (string) $client->getResponse()->getContent());
            self::assertSame(
                $originalPasswordHash,
                $entityManager->getConnection()->fetchOne('SELECT password FROM admin_users WHERE id = :id', ['id' => $administrator->getId()]),
            );
            $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
            self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
            self::assertTrue($hasher->isPasswordValid($administrator, $currentPassword));
            self::assertFalse($hasher->isPasswordValid($administrator, $newPassword));
            self::assertSame(0, self::integerValue($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM admin_activity_entries WHERE actor_id = :actor AND route_name = :route',
                ['actor' => $administrator->getId(), 'route' => 'admin_password_change'],
            )));
        } finally {
            static::ensureKernelShutdown();
            $cleanupClient = static::createClient();
            $cleanupEntityManager = $cleanupClient->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $cleanupEntityManager);
            if ($triggerCreated) {
                $cleanupEntityManager->getConnection()->executeStatement(
                    "DROP TRIGGER IF EXISTS {$triggerName} ON admin_activity_entries",
                );
            }
            if ($functionCreated) {
                $cleanupEntityManager->getConnection()->executeStatement("DROP FUNCTION IF EXISTS {$functionName}()");
            }
            $this->removeUsers($cleanupClient, [$email]);
            static::ensureKernelShutdown();
        }
    }

    public function testModeratorCannotReachAdministratorPasswordChange(): void
    {
        $client = $this->createAuthenticatedClient();
        $email = 'moderator-password-denied-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $client->request('GET', '/admin/password');
            self::assertResponseRedirects('/admin/login');

            $this->persistUser($client, $email, 'ModeratorAdmin!Password2026', AdminRole::VideoModerator);
            $this->submitLogin($client, $email, 'ModeratorAdmin!Password2026');
            $client->request('GET', '/admin/password');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/password', []);
            self::assertResponseStatusCodeSame(403);
        } finally {
            static::ensureKernelShutdown();
            $this->removeUsers(static::createClient(), [$email]);
            static::ensureKernelShutdown();
        }
    }

    private function createAuthenticatedClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        return $client;
    }

    private function persistUser(KernelBrowser $client, string $email, string $password, AdminRole $role): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $administrator = new AdminUser($email);
        $administrator->setRoles([$role->value]);
        $administrator->setPassword($hasher->hashPassword($administrator, $password));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($administrator);
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $administrator = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(AdminUser::class, $administrator);
        $ciphertext = $administrator->getMfaSecretCiphertext();
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

    private function csrfToken(KernelBrowser $client): string
    {
        $token = $client->getCrawler()->filter('form[action="/admin/password"] input[name="_token"]')->attr('value');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }

    private static function integerValue(mixed $value): int
    {
        self::assertTrue(is_int($value) || (is_string($value) && 1 === preg_match('/\A\d+\z/D', $value)));

        return (int) $value;
    }

    private static function assertCacheControlHasDirectives(?string $cacheControl, string ...$expected): void
    {
        self::assertNotNull($cacheControl);
        $directives = array_map(
            static fn (string $directive): string => strtolower(trim($directive)),
            explode(',', $cacheControl),
        );

        foreach ($expected as $directive) {
            self::assertContains(strtolower($directive), $directives);
        }
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
