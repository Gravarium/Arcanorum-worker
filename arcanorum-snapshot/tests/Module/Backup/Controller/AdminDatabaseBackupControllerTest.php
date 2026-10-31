<?php

declare(strict_types=1);

namespace App\Tests\Module\Backup\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminDatabaseBackupControllerTest extends WebTestCase
{
    private const string TEST_PASSWORD = 'BackupController!2026';

    public static function setUpBeforeClass(): void
    {
        $mfaKey = base64_encode(str_repeat('S', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$mfaKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;

        putenv('DATABASE_BACKUP_KEY=');
        $_ENV['DATABASE_BACKUP_KEY'] = '';
        $_SERVER['DATABASE_BACKUP_KEY'] = '';
        putenv('DATABASE_BACKUP_DIRECTORY=');
        $_ENV['DATABASE_BACKUP_DIRECTORY'] = '';
        $_SERVER['DATABASE_BACKUP_DIRECTORY'] = '';

        parent::setUpBeforeClass();
    }

    public function testAnonymousVisitorIsRedirectedToAdminLogin(): void
    {
        $client = $this->createConfiguredClient();

        $client->request('GET', '/admin/backups');

        self::assertResponseRedirects('/admin/login');
    }

    public function testModeratorCannotAccessDatabaseBackups(): void
    {
        $client = $this->createConfiguredClient();
        $user = $this->persistUser($client, AdminRole::CommentModerator->value);

        try {
            $this->submitLogin($client, $user);
            $client->request('GET', '/admin/backups');

            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->removeUser($client, $user);
        }
    }

    public function testAdministratorSeesPrivateDisabledStateAndMutationsRequireCsrf(): void
    {
        $client = $this->createConfiguredClient();
        $user = $this->persistUser($client, AdminRole::Administrator->value);

        try {
            $this->submitLogin($client, $user);
            $client->request('GET', '/admin/backups');

            self::assertResponseIsSuccessful();
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorExists('h1#seitenueberschrift');
            self::assertSelectorTextContains('.field-error', 'Sicherungen sind derzeit deaktiviert');
            self::assertSelectorNotExists('form[action="/admin/backups/create"]');
            self::assertStringNotContainsString('DATABASE_BACKUP_KEY', (string) $client->getResponse()->getContent());

            $id = str_repeat('0', 32);
            foreach ([
                ['POST', '/admin/backups/create'],
                ['POST', '/admin/backups/'.$id.'/verify'],
                ['POST', '/admin/backups/'.$id.'/download'],
                ['POST', '/admin/backups/'.$id.'/delete'],
            ] as [$method, $path]) {
                $client->request($method, $path, ['_token' => 'invalid-token']);
                self::assertResponseStatusCodeSame(403);
            }
        } finally {
            $this->removeUser($client, $user);
        }
    }

    public function testAdministratorCanCreateVerifyAndDeleteEncryptedBackup(): void
    {
        $temporaryRoot = sys_get_temp_dir().'/arcanorum-backup-controller-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryRoot, 0700));
        $privateDirectory = $temporaryRoot.'/private';
        $this->setBackupConfiguration(str_repeat('b', 64), $privateDirectory);

        $client = $this->createConfiguredClient();
        $user = $this->persistUser($client, AdminRole::Administrator->value);

        try {
            $this->submitLogin($client, $user);
            $client->request('GET', '/admin/backups');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="/admin/backups/create"]');

            $createToken = (string) $client->getCrawler()->filter('form[action="/admin/backups/create"] input[name="_token"]')->attr('value');
            self::assertNotSame('', $createToken);
            $client->request('POST', '/admin/backups/create', ['_token' => $createToken]);
            self::assertResponseRedirects('/admin/backups');

            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $verifyForm = $client->getCrawler()->filter('form[action*="/verify"]');
            self::assertCount(1, $verifyForm);
            $verifyAction = (string) $verifyForm->attr('action');
            $verifyToken = (string) $verifyForm->filter('input[name="_token"]')->attr('value');
            self::assertMatchesRegularExpression('#\A/admin/backups/[a-f0-9]{32}/verify\z#', $verifyAction);
            self::assertNotSame('', $verifyToken);

            $client->request('POST', $verifyAction, ['_token' => $verifyToken]);
            self::assertResponseRedirects('/admin/backups');
            $client->followRedirect();
            self::assertResponseIsSuccessful();

            $deleteForm = $client->getCrawler()->filter('form[action*="/delete"]');
            self::assertCount(1, $deleteForm);
            $deleteAction = (string) $deleteForm->attr('action');
            $deleteToken = (string) $deleteForm->filter('input[name="_token"]')->attr('value');
            self::assertNotSame('', $deleteToken);
            $client->request('POST', $deleteAction, ['_token' => $deleteToken]);
            self::assertResponseRedirects('/admin/backups');

            $client->request('POST', '/admin/backups/create', [
                '_token' => $createToken,
                'unexpected' => 'rejected',
            ]);
            self::assertResponseStatusCodeSame(403);

            new Filesystem()->remove($privateDirectory);
            self::assertTrue(symlink(dirname(__DIR__, 4).'/public', $privateDirectory));
            $client->request('POST', '/admin/backups/create', ['_token' => $createToken]);
            self::assertResponseStatusCodeSame(503);
            self::assertSame(
                'Die Sicherungsaktion konnte nicht sicher ausgeführt werden.',
                $client->getResponse()->getContent(),
            );
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('private'));
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            foreach (['admin_database_backup_create', 'admin_database_backup_verify', 'admin_database_backup_delete'] as $routeName) {
                $activityCount = $entityManager->getConnection()->fetchOne(
                    'SELECT COUNT(*) FROM admin_activity_entries WHERE actor_id = :actor AND route_name = :route',
                    ['actor' => $user->getId(), 'route' => $routeName],
                );
                self::assertTrue(in_array($activityCount, [1, '1'], true));
            }
        } finally {
            $this->removeUser($client, $user);
            $this->setBackupConfiguration('', '');
            new Filesystem()->remove($temporaryRoot);
        }
    }

    private function createConfiguredClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '198.51.'.random_int(0, 255).'.'.random_int(1, 254));
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        return $client;
    }

    private function persistUser(KernelBrowser $client, string $role): AdminUser
    {
        $email = 'backup-controller-'.bin2hex(random_bytes(8)).'@example.test';
        $user = new AdminUser($email);
        $user->setRoles([$role]);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user->setPassword($hasher->hashPassword($user, self::TEST_PASSWORD));

        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($user);

        return $user;
    }

    private function setBackupConfiguration(string $key, string $directory): void
    {
        putenv('DATABASE_BACKUP_KEY='.$key);
        $_ENV['DATABASE_BACKUP_KEY'] = $key;
        $_SERVER['DATABASE_BACKUP_KEY'] = $key;
        putenv('DATABASE_BACKUP_DIRECTORY='.$directory);
        $_ENV['DATABASE_BACKUP_DIRECTORY'] = $directory;
        $_SERVER['DATABASE_BACKUP_DIRECTORY'] = $directory;
    }

    private function submitLogin(KernelBrowser $client, AdminUser $user): void
    {
        $encryptedSecret = $user->getMfaSecretCiphertext();
        self::assertNotNull($encryptedSecret);
        $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
        self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
        $totp = $client->getContainer()->get(TotpVerifier::class);
        self::assertInstanceOf(TotpVerifier::class, $totp);

        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => $user->getUserIdentifier(),
            '_password' => self::TEST_PASSWORD,
            '_mfa_code' => $totp->codeAtTime($cipher->decrypt($encryptedSecret), time()),
        ]);
        self::assertResponseRedirects('/admin');
    }

    private function removeUser(KernelBrowser $client, AdminUser $user): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM admin_activity_entries WHERE actor_id = :actor',
            ['actor' => $user->getId()],
        );
        $managed = $entityManager->find(AdminUser::class, $user->getId());
        if ($managed instanceof AdminUser) {
            $entityManager->remove($managed);
            $entityManager->flush();
        }
    }
}
