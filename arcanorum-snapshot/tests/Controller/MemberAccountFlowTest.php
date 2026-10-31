<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MemberAccount;
use App\Module\Member\Security\MemberSecurityEmailSenderInterface;
use App\Tests\Support\RecordingMemberSecurityEmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MemberAccountFlowTest extends WebTestCase
{
    public function testMemberCanRegisterAuthenticateAndPublishOnlyTheirChosenProfileFields(): void
    {
        $client = $this->createIsolatedClient();
        $client->disableReboot();
        $emailSender = new RecordingMemberSecurityEmailSender();
        $client->getContainer()->set(MemberSecurityEmailSenderInterface::class, $emailSender);
        $email = 'member-'.bin2hex(random_bytes(6)).'@example.test';
        $handle = 'channel-'.bin2hex(random_bytes(4));
        $secondEmail = 'member-throttle-'.bin2hex(random_bytes(6)).'@example.test';
        $secondHandle = 'other-'.bin2hex(random_bytes(4));
        $password = 'NebulaPortal!2026';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        try {
            $client->request('GET', '/konto/registrieren');
            self::assertResponseIsSuccessful();
            $registrationToken = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/registrieren', [
                '_token' => $registrationToken,
                'email' => $email,
                'handle' => $handle,
                'display_name' => 'Neues Mitglied',
                'password' => $password,
                'website' => '',
            ]);
            self::assertResponseRedirects('/konto/anmelden');
            self::assertCount(1, $emailSender->messages());
            self::assertSame($email, $emailSender->messages()[0]['email']);

            $client->request('GET', '/konto/registrieren');
            $secondRegistrationToken = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/registrieren', [
                '_token' => $secondRegistrationToken,
                'email' => $secondEmail,
                'handle' => $secondHandle,
                'display_name' => 'Weiteres Mitglied',
                'password' => $password,
                'website' => '',
            ]);
            self::assertResponseStatusCodeSame(429);
            self::assertNull($entityManager->getRepository(MemberAccount::class)->findOneBy(['email' => $secondEmail]));

            $client->request('GET', '/kanal/'.$handle);
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/konto/anmelden');
            self::assertResponseIsSuccessful();
            $loginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $email,
                '_password' => $password,
                '_csrf_token' => $loginToken,
            ]);
            self::assertResponseRedirects('/konto/anmelden');
            $client->followRedirect();
            self::assertSelectorExists('.login-error[role="alert"]');

            $verificationToken = $emailSender->messages()[0]['token'];
            $client->request('GET', '/konto/verifizieren/'.$verificationToken);
            self::assertResponseIsSuccessful();
            $this->assertNoStorePrivateResponse($client);
            self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
            $verificationCsrf = (string) $client->getCrawler()->filter('form[action="/konto/verifizieren/'.$verificationToken.'"] input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/verifizieren/'.$verificationToken, ['_token' => $verificationCsrf]);
            self::assertResponseRedirects('/konto/anmelden');
            $client->request('GET', '/konto/verifizieren/'.$verificationToken);
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/konto/anmelden');
            $loginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $email,
                '_password' => $password,
                '_csrf_token' => $loginToken,
            ]);
            self::assertResponseRedirects('/konto/profil');

            foreach (['/', '/news', '/videos', '/series', '/pages', '/search', '/contact'] as $publicPath) {
                $client->request('GET', $publicPath);
                self::assertResponseIsSuccessful();
                $this->assertNoStorePrivateResponse($client);
                self::assertSelectorExists('nav.public-navigation a[href="/konto/profil"]');
                self::assertSelectorExists('nav.public-navigation a[href="/konto/passwort"]');
                self::assertSelectorExists('nav.public-navigation a[href="/konto/wiedergabelisten"]');
                self::assertSelectorExists('nav.public-navigation a[href="/konto/einreichungen"]');
                self::assertSelectorExists('nav.public-navigation a[href="/konto/aktivitaeten"]');
                self::assertSelectorExists('nav.public-navigation form[action="/konto/abmelden"] input[name="_csrf_token"]');
            }

            $client->request('GET', '/konto/profil');
            self::assertResponseIsSuccessful();
            $cacheDirectives = array_map(
                static fn (string $directive): string => strtolower(trim($directive)),
                explode(',', (string) $client->getResponse()->headers->get('Cache-Control')),
            );
            self::assertContains('no-store', $cacheDirectives);
            self::assertContains('private', $cacheDirectives);
            self::assertStringNotContainsString($email, (string) $client->getResponse()->getContent());

            $member = $entityManager->getRepository(MemberAccount::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(MemberAccount::class, $member);
            self::assertSame(['ROLE_MEMBER'], $member->getRoles());
            self::assertNotSame($password, $member->getPassword());
            self::assertTrue(password_verify($password, $member->getPassword()));

            $client->request('POST', '/konto/profil', [
                '_token' => 'invalid',
                'display_name' => 'Should Not Save',
                'bio' => '',
                'visibility' => 'public',
            ]);
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/konto/profil');
            $profileToken = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/profil', [
                '_token' => $profileToken,
                'display_name' => '<script>alert(1)</script>',
                'bio' => '<img src=x onerror=alert(1)>',
                'visibility' => 'public',
            ]);
            self::assertResponseRedirects('/konto/profil');

            $client->request('GET', '/kanal/'.$handle);
            self::assertResponseIsSuccessful();
            $this->assertNoStorePrivateResponse($client);
            self::assertSelectorTextContains('h1', '<script>alert(1)</script>');
            self::assertSelectorTextContains('main', '<img src=x onerror=alert(1)>');
            self::assertSelectorNotExists('script');
            self::assertSelectorNotExists('img');
            self::assertStringNotContainsString($email, (string) $client->getResponse()->getContent());

            $newsCrawler = $client->request('GET', '/news');
            self::assertResponseIsSuccessful();
            $logoutToken = (string) $newsCrawler
                ->filter('nav.public-navigation form[action="/konto/abmelden"] input[name="_csrf_token"]')
                ->attr('value');
            $client->request('POST', '/konto/abmelden', ['_csrf_token' => $logoutToken]);
            self::assertResponseRedirects('/konto/anmelden');

            $client->request('GET', '/news');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('nav.public-navigation a[href="/konto/anmelden"]');
            self::assertSelectorExists('nav.public-navigation a[href="/konto/registrieren"]');
            $client->request('GET', '/konto/profil');
            self::assertResponseRedirects('/konto/anmelden');
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM member_accounts WHERE email IN (:email, :second_email)',
                ['email' => $email, 'second_email' => $secondEmail],
            );
        }
    }

    public function testSuspendingMemberRevokesExistingSessionAndPreventsAuthentication(): void
    {
        $client = $this->createIsolatedClient();
        $client->disableReboot();
        $email = 'member-suspended-'.bin2hex(random_bytes(6)).'@example.test';
        $handle = 'suspended-'.bin2hex(random_bytes(4));
        $password = 'SuspendedMember!2026';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        $member = new MemberAccount($email, $handle, 'Suspension test');
        $member->setPassword($passwordHasher->hashPassword($member, $password));
        $entityManager->persist($member);
        $entityManager->flush();
        $memberId = $member->getId();

        try {
            $client->request('GET', '/konto/anmelden');
            $loginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $email,
                '_password' => $password,
                '_csrf_token' => $loginToken,
            ]);
            self::assertResponseRedirects('/konto/profil');

            $client->request('GET', '/konto/profil');
            self::assertResponseIsSuccessful();

            $entityManager->getConnection()->executeStatement(
                'UPDATE member_accounts SET is_active = FALSE WHERE id = :id',
                ['id' => $memberId],
            );
            $entityManager->clear();

            $client->request('GET', '/konto/profil');
            self::assertResponseRedirects('/konto/anmelden');

            $client->request('GET', '/konto/anmelden');
            $loginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $email,
                '_password' => $password,
                '_csrf_token' => $loginToken,
            ]);
            self::assertResponseRedirects('/konto/anmelden');

            $client->followRedirect();
            self::assertSelectorExists('.login-error[role="alert"]');
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
        }
    }

    public function testMemberCannotAccessAdminPanelAndKeepsTheirPrivateProfileResponse(): void
    {
        $client = $this->createIsolatedClient();
        $client->disableReboot();
        $email = 'member-admin-'.bin2hex(random_bytes(6)).'@example.test';
        $handle = 'member-admin-'.bin2hex(random_bytes(4));
        $password = 'MemberPanel!2026';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        $member = new MemberAccount($email, $handle, 'Panel test');
        $member->setPassword($passwordHasher->hashPassword($member, $password));
        $entityManager->persist($member);
        $entityManager->flush();
        $memberId = $member->getId();

        try {
            $client->request('GET', '/konto/anmelden');
            $loginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $email,
                '_password' => $password,
                '_csrf_token' => $loginToken,
            ]);
            self::assertResponseRedirects('/konto/profil');

            $client->request('GET', '/admin');
            self::assertResponseRedirects('/admin/login');

            $client->request('GET', '/konto/profil');
            self::assertResponseIsSuccessful();
            $cacheDirectives = array_map(
                static fn (string $directive): string => strtolower(trim($directive)),
                explode(',', (string) $client->getResponse()->headers->get('Cache-Control')),
            );
            self::assertContains('no-store', $cacheDirectives);
            self::assertContains('private', $cacheDirectives);
            self::assertSelectorExists('form[action="/konto/profil"]');
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
        }
    }

    public function testRegistrationRejectsInvalidCsrfAndInvalidChannelNames(): void
    {
        $client = $this->createIsolatedClient();
        $client->disableReboot();
        $email = 'invalid-'.bin2hex(random_bytes(6)).'@example.test';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        try {
            $client->request('POST', '/konto/registrieren', [
                '_token' => 'invalid',
                'email' => $email,
                'handle' => 'good-handle',
                'display_name' => 'Valid Name',
                'password' => 'LongEnoughPassword!2026',
            ]);
            self::assertResponseStatusCodeSame(403);
            self::assertNull($entityManager->getRepository(MemberAccount::class)->findOneBy(['email' => $email]));

            $client->request('GET', '/konto/registrieren');
            $token = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/registrieren', [
                '_token' => $token,
                'email' => $email,
                'handle' => '../../admin',
                'display_name' => 'Valid Name',
                'password' => 'LongEnoughPassword!2026',
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertNull($entityManager->getRepository(MemberAccount::class)->findOneBy(['email' => $email]));

            $client->request('GET', '/konto/registrieren');
            $honeypotToken = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/registrieren', [
                '_token' => $honeypotToken,
                'email' => $email,
                'handle' => 'valid-channel',
                'display_name' => 'Valid Name',
                'password' => 'LongEnoughPassword!2026',
                'website' => 'https://spam.example',
            ]);
            self::assertResponseRedirects('/konto/anmelden');
            self::assertNull($entityManager->getRepository(MemberAccount::class)->findOneBy(['email' => $email]));
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM member_accounts WHERE email = :email',
                ['email' => $email],
            );
        }
    }

    public function testMemberProfileRequiresAuthentication(): void
    {
        $client = $this->createIsolatedClient();
        $client->request('GET', '/konto/profil');

        self::assertResponseRedirects('/konto/anmelden');
    }

    public function testMemberCanSetPasswordWithOneTimeInvitationCode(): void
    {
        $client = $this->createIsolatedClient();
        $client->disableReboot();
        $email = 'member-setup-'.bin2hex(random_bytes(6)).'@example.test';
        $handle = 'setup-'.bin2hex(random_bytes(4));
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $password = 'MemberSetup!2026';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $member = new MemberAccount($email, $handle, 'Einrichtungstest');
        $member->setActive(false);
        $member->setInvitation(
            hash('sha256', $code),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->modify('+48 hours'),
        );
        $memberId = $member->getId();
        $entityManager->persist($member);
        $entityManager->flush();

        try {
            $client->request('GET', '/konto/einrichten');
            self::assertResponseIsSuccessful();
            $cacheDirectives = array_map(
                static fn (string $directive): string => strtolower(trim($directive)),
                explode(',', (string) $client->getResponse()->headers->get('Cache-Control')),
            );
            self::assertContains(
                'no-store',
                $cacheDirectives,
                'The setup-code page must not be stored by caches.',
            );
            self::assertContains('private', $cacheDirectives);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            $csrf = (string) $client->getCrawler()->filter('form[action="/konto/einrichten"] input[name="_token"]')->attr('value');
            self::assertNotEmpty($csrf);

            $payload = [
                'invitation_code' => $code,
                'password' => $password,
                'password_confirmation' => $password,
            ];
            $client->request('POST', '/konto/einrichten', ['_token' => 'invalid-csrf'] + $payload);
            self::assertResponseStatusCodeSame(403);
            $row = $entityManager->getConnection()->fetchAssociative(
                'SELECT is_active, invitation_token_hash FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertIsArray($row);
            self::assertFalse((bool) $row['is_active']);
            self::assertSame(hash('sha256', $code), $row['invitation_token_hash']);

            $client->request('POST', '/konto/einrichten', ['_token' => $csrf] + $payload);
            self::assertResponseRedirects('/konto/anmelden');
            $entityManager->clear();
            $account = $entityManager->getRepository(MemberAccount::class)->find($memberId);
            self::assertInstanceOf(MemberAccount::class, $account);
            self::assertTrue($account->isActive());
            self::assertNull($account->getInvitationTokenHash());
            self::assertNull($account->getInvitationExpiresAt());
            self::assertTrue(password_verify($password, $account->getPassword()));
            self::assertNotSame($password, $account->getPassword());

            $client->request('GET', '/konto/einrichten');
            $reuseCsrf = (string) $client->getCrawler()->filter('form[action="/konto/einrichten"] input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/einrichten', ['_token' => $reuseCsrf] + $payload);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[role="alert"]', 'ungültig oder abgelaufen');

            $client->request('GET', '/konto/anmelden');
            $loginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $email,
                '_password' => $password,
                '_csrf_token' => $loginToken,
            ]);
            self::assertResponseRedirects('/konto/profil');
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
        }
    }

    public function testMemberCanChangePasswordWithCurrentPasswordAndSessionIsRevoked(): void
    {
        $client = $this->createIsolatedClient();
        $client->disableReboot();
        $email = 'member-password-'.bin2hex(random_bytes(6)).'@example.test';
        $handle = 'password-'.bin2hex(random_bytes(4));
        $oldPassword = 'OldMemberPassword!2026';
        $newPassword = 'NewMemberPassword!2026';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        $member = new MemberAccount($email, $handle, 'Passworttest');
        $member->setPassword($passwordHasher->hashPassword($member, $oldPassword));
        $memberId = $member->getId();
        $entityManager->persist($member);
        $entityManager->flush();
        $originalHash = $member->getPassword();

        try {
            $client->request('GET', '/konto/passwort');
            self::assertResponseRedirects('/konto/anmelden');

            $client->request('GET', '/konto/anmelden');
            $loginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $email,
                '_password' => $oldPassword,
                '_csrf_token' => $loginToken,
            ]);
            self::assertResponseRedirects('/konto/passwort');

            $client->request('GET', '/konto/passwort');
            self::assertResponseIsSuccessful();
            $cacheDirectives = array_map(
                static fn (string $directive): string => strtolower(trim($directive)),
                explode(',', (string) $client->getResponse()->headers->get('Cache-Control')),
            );
            self::assertContains('no-store', $cacheDirectives);
            self::assertContains('private', $cacheDirectives);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            $csrf = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            self::assertNotEmpty($csrf);

            $form = [
                'current_password' => $oldPassword,
                'new_password' => $newPassword,
                'password_confirmation' => $newPassword,
            ];
            $client->request('POST', '/konto/passwort', ['_token' => 'invalid'] + $form);
            self::assertResponseStatusCodeSame(403);
            self::assertSame($originalHash, $entityManager->getConnection()->fetchOne(
                'SELECT password FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            ));

            $client->request('POST', '/konto/passwort', ['_token' => $csrf] + [
                'current_password' => 'WrongCurrentPassword!2026',
                'new_password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[role="alert"]', 'aktuelle Passwort ist falsch');

            $client->request('POST', '/konto/passwort', ['_token' => $csrf] + [
                'current_password' => $oldPassword,
                'new_password' => $newPassword,
                'password_confirmation' => 'DifferentPassword!2026',
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[role="alert"]', 'Passwörter stimmen nicht überein');
            self::assertSame($originalHash, $entityManager->getConnection()->fetchOne(
                'SELECT password FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            ));

            $client->request('POST', '/konto/passwort', ['_token' => $csrf] + $form);
            self::assertResponseRedirects('/konto/anmelden');
            $entityManager->clear();
            $updatedMember = $entityManager->getRepository(MemberAccount::class)->find($memberId);
            self::assertInstanceOf(MemberAccount::class, $updatedMember);
            self::assertNotSame($newPassword, $updatedMember->getPassword());
            self::assertTrue(password_verify($newPassword, $updatedMember->getPassword()));
            self::assertFalse(password_verify($oldPassword, $updatedMember->getPassword()));

            $client->request('GET', '/konto/profil');
            self::assertResponseRedirects('/konto/anmelden');

            $client->request('GET', '/konto/anmelden');
            $newLoginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $email,
                '_password' => $oldPassword,
                '_csrf_token' => $newLoginToken,
            ]);
            self::assertResponseRedirects('/konto/anmelden');
            $client->followRedirect();
            self::assertSelectorExists('.login-error[role="alert"]');

            $client->request('GET', '/konto/anmelden');
            $newLoginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $email,
                '_password' => $newPassword,
                '_csrf_token' => $newLoginToken,
            ]);
            self::assertResponseRedirects('/konto/profil');
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
        }
    }

    public function testPasswordChangeRateLimitsIncorrectCurrentPasswordAttempts(): void
    {
        $client = $this->createIsolatedClient();
        $client->disableReboot();
        $email = 'member-password-limit-'.bin2hex(random_bytes(6)).'@example.test';
        $handle = 'password-limit-'.bin2hex(random_bytes(4));
        $password = 'ValidMemberPassword!2026';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        $member = new MemberAccount($email, $handle, 'Drosselungstest');
        $member->setPassword($passwordHasher->hashPassword($member, $password));
        $memberId = $member->getId();
        $originalHash = $member->getPassword();
        $entityManager->persist($member);
        $entityManager->flush();

        try {
            $client->request('GET', '/konto/anmelden');
            $loginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $email,
                '_password' => $password,
                '_csrf_token' => $loginToken,
            ]);
            self::assertResponseRedirects('/konto/profil');

            for ($attempt = 0; $attempt < 5; ++$attempt) {
                $client->request('GET', '/konto/passwort');
                $csrf = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
                $client->request('POST', '/konto/passwort', [
                    '_token' => $csrf,
                    'current_password' => 'IncorrectPassword!2026',
                    'new_password' => 'ACompletelyNewPassword!2026',
                    'password_confirmation' => 'ACompletelyNewPassword!2026',
                ]);
                self::assertResponseStatusCodeSame(422);
            }

            $client->request('GET', '/konto/passwort');
            $csrf = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/passwort', [
                '_token' => $csrf,
                'current_password' => 'IncorrectPassword!2026',
                'new_password' => 'ACompletelyNewPassword!2026',
                'password_confirmation' => 'ACompletelyNewPassword!2026',
            ]);
            self::assertResponseStatusCodeSame(429);
            self::assertSame($originalHash, $entityManager->getConnection()->fetchOne(
                'SELECT password FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            ));
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
        }
    }

    private function assertNoStorePrivateResponse(KernelBrowser $client): void
    {
        $directives = array_map(
            static fn (string $directive): string => strtolower(trim($directive)),
            explode(',', (string) $client->getResponse()->headers->get('Cache-Control')),
        );
        self::assertContains('no-store', $directives);
        self::assertContains('private', $directives);
    }

    private function createIsolatedClient(): KernelBrowser
    {
        $client = static::createClient();
        $remoteAddress = inet_ntop(random_bytes(16));
        if (false === $remoteAddress) {
            throw new \RuntimeException('Could not generate a test client address.');
        }

        $client->setServerParameter('REMOTE_ADDR', $remoteAddress);

        return $client;
    }
}
