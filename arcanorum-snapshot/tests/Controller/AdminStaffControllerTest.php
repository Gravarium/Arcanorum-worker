<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
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
