<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Module\Settings\Service\EmailDeliveryConfiguration;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminEmailSettingsTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $testKey = base64_encode(str_repeat('E', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$testKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;

        parent::setUpBeforeClass();
    }

    public function testAdministratorCanConfigureSenderAndSendTestOnlyToTheirOwnAddress(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'email-settings-'.bin2hex(random_bytes(6)).'@example.test';
        $this->persistUser($client, $email, 'EmailSettings!2026', [AdminRole::Administrator->value]);
        $this->submitLogin($client, $email, 'EmailSettings!2026');
        self::assertResponseRedirects('/admin');

        $manager = $client->getContainer()->get(SiteSettingsManager::class);
        self::assertInstanceOf(SiteSettingsManager::class, $manager);
        self::assertSame([], $manager->updateEmailSettings(['from_address' => '']));

        $provider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $provider);
        $configuration = new EmailDeliveryConfiguration(
            'failover(smtp://mailer-user:synthetic-password@mail.example.test:2525 smtps://backup-user:other-synthetic-password@backup.example.test:465)',
            'fallback@example.test',
            $provider,
        );
        $client->getContainer()->set(EmailDeliveryConfiguration::class, $configuration);

        $sentEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (RawMessage $message) use (&$sentEmail): bool {
                if (!$message instanceof Email) {
                    return false;
                }

                $sentEmail = $message;

                return true;
            }));
        $client->getContainer()->set(MailerInterface::class, $mailer);

        try {
            $client->request('GET', '/admin/settings/email');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorExists('form[action="/admin/settings/email"]');
            self::assertSelectorExists('form[action="/admin/settings/email/test"]');
            self::assertSelectorTextContains('body', 'fallback@example.test');
            $settingsToken = (string) $client->getCrawler()->filter('form[action="/admin/settings/email"] input[name="token"]')->attr('value');
            self::assertNotSame('', $settingsToken);
            self::assertStringNotContainsString('synthetic-password', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('other-synthetic-password', (string) $client->getResponse()->getContent());

            $client->request('POST', '/admin/settings/email', [
                'token' => 'invalid',
                'from_address' => 'changed@example.test',
            ]);
            self::assertResponseStatusCodeSame(403);
            $settings = $client->getContainer()->get(SiteSettingsProvider::class);
            self::assertInstanceOf(SiteSettingsProvider::class, $settings);
            self::assertSame('', $settings->current()->getEmailFromAddress());

            $client->request('POST', '/admin/settings/email', [
                'token' => $settingsToken,
                'from_address' => "changed@example.test\r\nBcc:attacker@example.test",
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'gültige Absenderadresse');

            $client->request('POST', '/admin/settings/email', [
                'token' => $settingsToken,
                'from_address' => 'notifications@example.test',
            ]);
            self::assertResponseRedirects('/admin/settings/email');

            $client->request('GET', '/admin/settings/email');
            self::assertResponseIsSuccessful();
            $testToken = (string) $client->getCrawler()->filter('form[action="/admin/settings/email/test"] input[name="token"]')->attr('value');
            self::assertNotSame('', $testToken);

            $client->request('POST', '/admin/settings/email/test', [
                'token' => $testToken,
                'to' => 'attacker@example.test',
            ]);
            self::assertResponseStatusCodeSame(422);

            $client->request('POST', '/admin/settings/email/test', ['token' => $testToken]);
            self::assertResponseRedirects('/admin/settings/email');
            self::assertInstanceOf(Email::class, $sentEmail);
            self::assertSame('notifications@example.test', $sentEmail->getFrom()[0]->getAddress());
            self::assertSame($email, $sentEmail->getTo()[0]->getAddress());
            self::assertStringNotContainsString('synthetic-password', $sentEmail->toString());
            self::assertStringNotContainsString('other-synthetic-password', $sentEmail->toString());

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $administrator = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(AdminUser::class, $administrator);
            self::assertSame('notifications@example.test', $entityManager->getConnection()->fetchOne('SELECT email_from_address FROM site_settings WHERE id = 1'));
            self::assertNotFalse($entityManager->getConnection()->fetchOne(
                "SELECT id FROM admin_activity_entries WHERE actor_id = :id AND route_name = 'admin_email_settings_test' LIMIT 1",
                ['id' => $administrator->getId()],
            ));
        } finally {
            $this->restoreDefaults($client, $email);
        }
    }

    public function testDeliveryFailureIsPrivateAndModeratorCannotAccessSettings(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'email-failure-'.bin2hex(random_bytes(6)).'@example.test';
        $this->persistUser($client, $email, 'EmailFailure!2026', [AdminRole::Administrator->value]);
        $this->submitLogin($client, $email, 'EmailFailure!2026');
        self::assertResponseRedirects('/admin');

        $provider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $provider);
        $client->getContainer()->set(
            EmailDeliveryConfiguration::class,
            new EmailDeliveryConfiguration('smtp://mail.example.test:2525', 'fallback@example.test', $provider),
        );
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException(new TransportException('simulated transport internals'));
        $client->getContainer()->set(MailerInterface::class, $mailer);

        try {
            $client->request('GET', '/admin/settings/email');
            $token = (string) $client->getCrawler()->filter('form[action="/admin/settings/email/test"] input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/email/test', ['token' => $token]);
            self::assertResponseStatusCodeSame(503);
            self::assertStringNotContainsString('simulated transport internals', (string) $client->getResponse()->getContent());
        } finally {
            $this->restoreDefaults($client, $email);
        }

        static::ensureKernelShutdown();
        $moderatorClient = static::createClient();
        $moderatorClient->disableReboot();
        $moderatorClient->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $moderatorEmail = 'email-moderator-'.bin2hex(random_bytes(6)).'@example.test';
        $this->persistUser($moderatorClient, $moderatorEmail, 'EmailModerator!2026', [AdminRole::CommentModerator->value]);
        $this->submitLogin($moderatorClient, $moderatorEmail, 'EmailModerator!2026');

        try {
            $moderatorClient->request('GET', '/admin/settings/email');
            self::assertResponseStatusCodeSame(403);
            $moderatorClient->request('POST', '/admin/settings/email/test', ['token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->restoreDefaults($moderatorClient, $moderatorEmail);
        }
    }

    /** @param list<string> $roles */
    private function persistUser(KernelBrowser $client, string $email, string $password, array $roles): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $admin = new AdminUser($email);
        $admin->setRoles($roles);
        $admin->setPassword($hasher->hashPassword($admin, $password));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($admin);
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $admin = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(AdminUser::class, $admin);
        $ciphertext = $admin->getMfaSecretCiphertext();
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
        $connection->executeStatement("UPDATE site_settings SET email_from_address = '' WHERE id = 1");
        $admin = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        if ($admin instanceof AdminUser) {
            $connection->executeStatement('DELETE FROM admin_activity_entries WHERE actor_id = :id', ['id' => $admin->getId()]);
            $entityManager->remove($admin);
            $entityManager->flush();
        }
    }
}
