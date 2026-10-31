<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\MemberAccount;
use App\Entity\MemberAccountBan;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Repository\MemberAccountBanRepository;
use App\Repository\MemberAccountRepository;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MemberBanFlowTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $mfaKey = base64_encode(str_repeat('B', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$mfaKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;

        parent::setUpBeforeClass();
    }

    public function testAdminBanReasonIsPrivateSessionRevocationIsDurableAndUnbanRestoresOriginalState(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(6));
        $member = new MemberAccount('member-ban-'.$suffix.'@example.test', 'ban'.$suffix, 'Ban member');
        $memberId = $member->getId();
        $adminEmail = 'admin-ban-'.$suffix.'@example.test';
        $adminPassword = 'BanAdmin!2026-'.$suffix;
        $admin = $this->createAdministrator($client, $adminEmail, $adminPassword);

        try {
            $entityManager->persist($member);
            $entityManager->persist($admin);
            $entityManager->flush();

            $client->request('GET', '/admin/members/bans');
            self::assertResponseRedirects('/admin/login');

            $this->loginAsAdministrator($client, $admin, $adminPassword);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();

            $editUrl = '/admin/members/'.$memberId;
            $banUrl = $editUrl.'/ban';
            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));

            $deactivationToken = (string) $client->getCrawler()
                ->filter(sprintf('form[action="%s/status"] input[name="_token"]', $editUrl))
                ->attr('value');
            self::assertNotSame('', $deactivationToken);
            $client->request('POST', $editUrl.'/status', ['_token' => $deactivationToken, 'is_active' => '0']);
            self::assertResponseRedirects($editUrl);

            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            $reactivationToken = (string) $client->getCrawler()
                ->filter(sprintf('form[action="%s/status"] input[name="_token"]', $editUrl))
                ->attr('value');
            self::assertNotSame('', $reactivationToken);
            $client->request('POST', $editUrl.'/status', ['_token' => $reactivationToken, 'is_active' => '1']);
            self::assertResponseRedirects($editUrl);

            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            $staleSessionMember = clone $member;
            $banToken = (string) $client->getCrawler()
                ->filter(sprintf('form[action="%s"] input[name="_token"]', $banUrl))
                ->attr('value');
            self::assertNotSame('', $banToken);

            $client->request('POST', $banUrl, ['_token' => 'invalid-token', 'reason' => 'Repeated abuse']);
            self::assertResponseStatusCodeSame(403);
            self::assertSame(0, $this->countBans($entityManager->getConnection(), $memberId));

            $client->request('POST', $banUrl, ['_token' => $banToken, 'reason' => str_repeat('x', 501)]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(0, $this->countBans($entityManager->getConnection(), $memberId));

            $reason = 'Repeated harassment after moderation warning';
            $client->request('POST', $banUrl, ['_token' => $banToken, 'reason' => '  '.$reason.'  ']);
            self::assertResponseRedirects($editUrl);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            self::assertSame(1, $this->countBans($entityManager->getConnection(), $memberId));

            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT is_active, auth_version, invitation_token_hash FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertFalse((bool) $row['is_active']);
            self::assertSame(3, self::databaseInteger($row['auth_version']));
            self::assertNull($row['invitation_token_hash']);

            $banRepository = $client->getContainer()->get(MemberAccountBanRepository::class);
            self::assertInstanceOf(MemberAccountBanRepository::class, $banRepository);
            $memberRepository = $client->getContainer()->get(MemberAccountRepository::class);
            self::assertInstanceOf(MemberAccountRepository::class, $memberRepository);
            $entityManager->clear();
            $freshMember = $memberRepository->find($memberId);
            self::assertInstanceOf(MemberAccount::class, $freshMember);
            self::assertTrue($freshMember->isBanned());
            self::assertFalse($freshMember->isActive());
            $ban = $banRepository->findActiveForMember($freshMember);
            self::assertInstanceOf(MemberAccountBan::class, $ban);
            self::assertSame($reason, $ban->getReason());
            self::assertSame($admin->getId(), $ban->getBannedBy()?->getId());
            self::assertTrue($ban->wasActive());

            $banListUrl = '/admin/members/bans';
            $client->request('GET', $banListUrl.'?q='.rawurlencode($suffix));
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            self::assertSelectorTextContains('main', $member->getEmail());
            self::assertSelectorTextContains('main', $reason);

            $client->request('GET', '/admin/members?'.http_build_query(['q' => $suffix, 'status' => 'banned']));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('table', $member->getHandle());
            self::assertSelectorTextContains('table', $reason);

            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#member-status-description', 'Gesperrt');
            self::assertSelectorTextContains('main', $reason);
            self::assertSelectorNotExists(sprintf('form[action="%s/status"]', $editUrl));

            $client->request('POST', $editUrl.'/status', ['_token' => $reactivationToken, 'is_active' => '1']);
            self::assertResponseStatusCodeSame(409);
            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT is_active FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertFalse((bool) $row['is_active']);

            $client->request('GET', $banListUrl);
            self::assertResponseIsSuccessful();
            $banId = $ban->getId();
            $revokeUrl = '/admin/members/bans/'.$banId.'/revoke';
            $revokeToken = (string) $client->getCrawler()
                ->filter(sprintf('form[action="%s"] input[name="_token"]', $revokeUrl))
                ->attr('value');
            self::assertNotSame('', $revokeToken);
            $client->request('POST', $revokeUrl, ['_token' => 'invalid-token']);
            self::assertResponseStatusCodeSame(403);
            self::assertSame(1, $this->countBans($entityManager->getConnection(), $memberId));

            $client->request('POST', $revokeUrl, ['_token' => $revokeToken]);
            self::assertResponseRedirects('/admin/members/bans');
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));

            $entityManager->clear();
            $reloadedMember = $memberRepository->find($memberId);
            self::assertInstanceOf(MemberAccount::class, $reloadedMember);
            self::assertTrue($reloadedMember->isActive());
            self::assertFalse($reloadedMember->isBanned());
            self::assertFalse($staleSessionMember->isEqualTo($reloadedMember));
            self::assertSame(0, $this->countActiveBans($entityManager->getConnection(), $memberId));
            self::assertSame(1, $this->countBans($entityManager->getConnection(), $memberId));

            $client->request('GET', $banListUrl);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Keine aktiven Mitgliedersperren gefunden.');
        } finally {
            $cleanup = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $cleanup);
            $cleanup->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $memberId]);
            $cleanup->getConnection()->executeStatement('DELETE FROM admin_users WHERE id = :id', ['id' => $admin->getId()]);
        }
    }

    public function testBanningPendingSetupInvalidatesItsCodeAndUnbanDoesNotActivateTheAccount(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(6));
        $member = new MemberAccount('member-pending-ban-'.$suffix.'@example.test', 'pendingban'.$suffix, 'Pending setup member');
        $member->setActive(false);
        $setupToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $member->setInvitation(
            hash('sha256', $setupToken),
            new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        );
        $memberId = $member->getId();
        $adminEmail = 'admin-pending-ban-'.$suffix.'@example.test';
        $adminPassword = 'PendingBanAdmin!2026-'.$suffix;
        $admin = $this->createAdministrator($client, $adminEmail, $adminPassword);

        try {
            $entityManager->persist($member);
            $entityManager->persist($admin);
            $entityManager->flush();
            $this->loginAsAdministrator($client, $admin, $adminPassword);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();

            $editUrl = '/admin/members/'.$memberId;
            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()
                ->filter(sprintf('form[action="%s/ban"] input[name="_token"]', $editUrl))
                ->attr('value');
            $client->request('POST', $editUrl.'/ban', ['_token' => $token, 'reason' => 'Abuse prevention']);
            self::assertResponseRedirects($editUrl);

            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT is_active, invitation_token_hash FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertFalse((bool) $row['is_active']);
            self::assertNull($row['invitation_token_hash']);
            $memberRepository = $client->getContainer()->get(MemberAccountRepository::class);
            self::assertInstanceOf(MemberAccountRepository::class, $memberRepository);
            self::assertFalse($memberRepository->consumeInvitation(
                hash('sha256', $setupToken),
                'must-not-be-used',
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ));

            $client->request('GET', '/admin/members/bans');
            self::assertResponseIsSuccessful();
            $banFormCount = $client->getCrawler()
                ->filter('form[action*="/revoke"] input[name="_token"]')
                ->count();
            self::assertSame(1, $banFormCount);
            $revokeUrl = (string) $client->getCrawler()
                ->filter('form[action*="/revoke"]')
                ->attr('action');
            $revokeToken = (string) $client->getCrawler()
                ->filter('form[action*="/revoke"] input[name="_token"]')
                ->attr('value');
            $client->request('POST', $revokeUrl, ['_token' => $revokeToken]);
            self::assertResponseRedirects('/admin/members/bans');

            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT is_active, invitation_token_hash FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertFalse((bool) $row['is_active']);
            self::assertNull($row['invitation_token_hash']);
        } finally {
            $cleanup = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $cleanup);
            $cleanup->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $memberId]);
            $cleanup->getConnection()->executeStatement('DELETE FROM admin_users WHERE id = :id', ['id' => $admin->getId()]);
        }
    }

    private function createAdministrator(KernelBrowser $client, string $email, string $password): AdminUser
    {
        $admin = new AdminUser($email);
        $admin->setRoles([AdminRole::Administrator->value]);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $admin->setPassword($hasher->hashPassword($admin, $password));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($admin);

        return $admin;
    }

    private function loginAsAdministrator(KernelBrowser $client, AdminUser $admin, string $password): void
    {
        $encryptedSecret = $admin->getMfaSecretCiphertext();
        self::assertNotNull($encryptedSecret);
        $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
        self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
        $totp = $client->getContainer()->get(TotpVerifier::class);
        self::assertInstanceOf(TotpVerifier::class, $totp);

        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => $admin->getUserIdentifier(),
            '_password' => $password,
            '_mfa_code' => $totp->codeAtTime($cipher->decrypt($encryptedSecret), time()),
        ]);
    }

    private function countBans(Connection $connection, string $memberId): int
    {
        return self::databaseInteger($connection->fetchOne(
            'SELECT COUNT(*) FROM member_account_bans WHERE member_id = :member_id',
            ['member_id' => $memberId],
        ));
    }

    private function countActiveBans(Connection $connection, string $memberId): int
    {
        return self::databaseInteger($connection->fetchOne(
            'SELECT COUNT(*) FROM member_account_bans WHERE member_id = :member_id AND revoked_at IS NULL',
            ['member_id' => $memberId],
        ));
    }

    private static function databaseInteger(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new \UnexpectedValueException('Expected an integer value from the database.');
        }

        return $integer;
    }
}
