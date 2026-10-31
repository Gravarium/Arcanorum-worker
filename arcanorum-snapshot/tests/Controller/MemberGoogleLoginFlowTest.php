<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MemberAccount;
use App\Entity\MemberExternalIdentity;
use App\Module\Member\Security\GoogleOAuthClient;
use App\Repository\MemberExternalIdentityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MemberGoogleLoginFlowTest extends WebTestCase
{
    public function testVerifiedGoogleAccountCanRegisterAndAuthenticateWithoutAutoLinkingByEmail(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->useIsolatedRateLimitAddress($client);
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $email = 'google-'.bin2hex(random_bytes(6)).'@example.test';
        $handle = 'google-'.bin2hex(random_bytes(6));
        $localEmail = 'local-'.bin2hex(random_bytes(6)).'@example.test';
        $googleSubject = 'google-subject-'.bin2hex(random_bytes(8));
        $this->enableGoogleLogin($entityManager, true);
        $this->setGoogleClient($client, [
            ['email' => $email, 'subject' => $googleSubject],
            ['email' => $localEmail, 'subject' => 'unlinked-google-subject-'.bin2hex(random_bytes(8))],
            ['email' => $email, 'subject' => $googleSubject],
        ]);

        try {
            $state = $this->beginPublicLogin($client);
            $client->request('GET', '/konto/sozial/google/callback', [
                'state' => $state,
                'code' => 'authorization.code',
            ]);

            self::assertResponseRedirects('/konto/profil');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $this->assertNoStorePrivateResponse($client);

            $member = $entityManager->getRepository(MemberAccount::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(MemberAccount::class, $member);
            self::assertSame('Example Google Member', $member->getDisplayName());
            self::assertTrue($member->isActive());
            self::assertTrue($member->isEmailVerified());
            self::assertFalse($member->hasUsablePasswordCredential());
            self::assertInstanceOf(
                MemberExternalIdentity::class,
                $this->identityRepository($entityManager)->findForMember($member, 'google'),
            );

            $localMember = new MemberAccount($localEmail, $handle, 'Local Account');
            $entityManager->persist($localMember);
            $entityManager->flush();
            $client->request('GET', '/konto/profil');
            $logoutToken = (string) $client->getCrawler()->filter('form[action="/konto/abmelden"] input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/abmelden', ['_csrf_token' => $logoutToken]);
            self::assertResponseRedirects('/konto/anmelden');

            $state = $this->beginPublicLogin($client);
            $client->request('GET', '/konto/sozial/google/callback', [
                'state' => $state,
                'code' => 'authorization.code',
            ]);
            self::assertResponseRedirects('/konto/anmelden');
            $client->followRedirect();
            self::assertSelectorTextContains('p[role="alert"]', 'verknüpfe Google danach im Profil');
            self::assertNull($this->identityRepository($entityManager)->findForMember($localMember, 'google'));

            $state = $this->beginPublicLogin($client);
            $client->request('GET', '/konto/sozial/google/callback', [
                'state' => $state,
                'code' => 'authorization.code',
            ]);
            self::assertResponseRedirects('/konto/profil');
            $client->followRedirect();
            self::assertSelectorExists('form[action="/konto/sozial/google/trennen"]');
        } finally {
            $connection->executeStatement('DELETE FROM member_accounts WHERE email IN (:email, :local_email)', [
                'email' => $email,
                'local_email' => $localEmail,
            ]);
            $this->restoreGoogleLogin($entityManager);
        }
    }

    public function testGoogleRegistrationHonorsTheSiteRegistrationSwitch(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->useIsolatedRateLimitAddress($client);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $email = 'closed-'.bin2hex(random_bytes(6)).'@example.test';
        $this->enableGoogleLogin($entityManager, false);
        $this->setGoogleClient($client, [['email' => $email, 'subject' => 'closed-google-subject-'.bin2hex(random_bytes(8))]]);

        try {
            $state = $this->beginPublicLogin($client);
            $client->request('GET', '/konto/sozial/google/callback', [
                'state' => $state,
                'code' => 'authorization.code',
            ]);

            self::assertResponseRedirects('/konto/anmelden');
            $client->followRedirect();
            self::assertSelectorTextContains('p[role="alert"]', 'Neue Mitgliedskonten sind derzeit nicht verfügbar');
            self::assertNull($entityManager->getRepository(MemberAccount::class)->findOneBy(['email' => $email]));
        } finally {
            $connection->executeStatement('DELETE FROM member_accounts WHERE email = :email', ['email' => $email]);
            $this->restoreGoogleLogin($entityManager);
        }
    }

    public function testMemberCanExplicitlyLinkGoogleAfterCsrfAndSessionBoundCallback(): void
    {
        $email = 'explicit-link-'.bin2hex(random_bytes(6)).'@example.test';
        $otherEmail = 'other-link-'.bin2hex(random_bytes(6)).'@example.test';
        $externalEmail = 'different-provider-email-'.bin2hex(random_bytes(6)).'@example.test';
        $subject = 'explicit-google-subject-'.bin2hex(random_bytes(8));
        $client = static::createClient();
        $client->disableReboot();
        $this->useIsolatedRateLimitAddress($client);
        $this->setGoogleClient($client, [
            ['email' => $externalEmail, 'subject' => $subject],
            ['email' => $externalEmail, 'subject' => $subject],
        ]);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $connection = $entityManager->getConnection();
        $member = new MemberAccount($email, 'explicit-link-'.bin2hex(random_bytes(6)), 'Explicit Link Member');
        $otherMember = new MemberAccount($otherEmail, 'other-link-'.bin2hex(random_bytes(6)), 'Other Link Member');
        $member->setPassword($passwordHasher->hashPassword($member, 'ExplicitGoogleLink!2026'));
        $entityManager->persist($member);
        $entityManager->persist($otherMember);
        $entityManager->flush();
        $this->enableGoogleLogin($entityManager, true);

        try {
            $client->loginUser($member, 'members');
            $client->request('GET', '/konto/profil');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="/konto/sozial/google/verknuepfen"]');
            $token = (string) $client->getCrawler()->filter('form[action="/konto/sozial/google/verknuepfen"] input[name="_token"]')->attr('value');

            $client->request('POST', '/konto/sozial/google/verknuepfen', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
            self::assertNull($this->identityRepository($entityManager)->findForMember($member, 'google'));

            $client->request('GET', '/konto/profil');
            $token = (string) $client->getCrawler()->filter('form[action="/konto/sozial/google/verknuepfen"] input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/sozial/google/verknuepfen', ['_token' => $token]);
            self::assertResponseRedirects();
            $authorizationUrl = (string) $client->getResponse()->headers->get('Location');
            self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $authorizationUrl);
            parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationQuery);
            $state = $authorizationQuery['state'] ?? null;
            self::assertIsString($state);

            $client->loginUser($otherMember, 'members');
            $client->request('GET', '/konto/sozial/google/callback', [
                'state' => $state,
                'code' => 'authorization.code',
            ]);
            self::assertResponseRedirects('/konto/anmelden');
            self::assertNull($this->identityRepository($entityManager)->findForMember($member, 'google'));
            self::assertNull($this->identityRepository($entityManager)->findForMember($otherMember, 'google'));

            $client->loginUser($member, 'members');
            $client->request('GET', '/konto/profil');
            $token = (string) $client->getCrawler()->filter('form[action="/konto/sozial/google/verknuepfen"] input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/sozial/google/verknuepfen', ['_token' => $token]);
            self::assertResponseRedirects();
            $authorizationUrl = (string) $client->getResponse()->headers->get('Location');
            parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationQuery);
            $state = $authorizationQuery['state'] ?? null;
            self::assertIsString($state);

            $client->request('GET', '/konto/sozial/google/callback', [
                'state' => $state,
                'code' => 'authorization.code',
            ]);
            self::assertResponseRedirects('/konto/profil');
            $client->followRedirect();
            self::assertSelectorExists('form[action="/konto/sozial/google/trennen"]');
            self::assertSame($email, $member->getEmail());
            $identity = $this->identityRepository($entityManager)->findForMember($member, 'google');
            self::assertInstanceOf(MemberExternalIdentity::class, $identity);
            self::assertSame($subject, $identity->getSubject());

            $client->request('GET', '/konto/sozial/google/callback', [
                'state' => $state,
                'code' => 'authorization.code',
            ]);
            self::assertResponseRedirects('/konto/anmelden');
            $identityCount = $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM member_external_identities WHERE member_id = :member_id AND provider = :provider',
                ['member_id' => $member->getId(), 'provider' => 'google'],
            );
            self::assertTrue(in_array($identityCount, [1, '1'], true));
        } finally {
            $connection->executeStatement('DELETE FROM member_accounts WHERE email IN (:email, :other_email)', [
                'email' => $email,
                'other_email' => $otherEmail,
            ]);
            $this->restoreGoogleLogin($entityManager);
        }
    }

    public function testLinkedIdentityRequiresCsrfAndARecoveryCredentialBeforeUnlinking(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->useIsolatedRateLimitAddress($client);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $connection = $entityManager->getConnection();
        $email = 'link-'.bin2hex(random_bytes(6)).'@example.test';
        $member = new MemberAccount($email, 'link-'.bin2hex(random_bytes(6)), 'Linked Member');
        $member->setPassword($passwordHasher->hashPassword($member, 'GoogleLinkedMember!2026'));
        $identity = new MemberExternalIdentity($member, 'google', 'linked-google-subject-'.bin2hex(random_bytes(8)));
        $entityManager->persist($member);
        $entityManager->persist($identity);
        $entityManager->flush();
        $this->enableGoogleLogin($entityManager, true);

        try {
            $client->loginUser($member, 'members');
            $client->request('GET', '/konto/profil');
            self::assertResponseIsSuccessful();
            $client->request('POST', '/konto/sozial/google/trennen', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
            self::assertSame($member->getId(), $identity->getMember()->getId());

            $client->request('GET', '/konto/profil');
            $unlinkToken = (string) $client->getCrawler()->filter('form[action="/konto/sozial/google/trennen"] input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/sozial/google/trennen', ['_token' => $unlinkToken]);
            self::assertResponseRedirects('/konto/profil');
            self::assertNull($this->identityRepository($entityManager)->findForMember($member, 'google'));

            $passwordlessMember = new MemberAccount('google-only-'.bin2hex(random_bytes(6)).'@example.test', 'google-only-'.bin2hex(random_bytes(6)), 'Google Only');
            $passwordlessIdentity = new MemberExternalIdentity($passwordlessMember, 'google', 'passwordless-subject-'.bin2hex(random_bytes(8)));
            $entityManager->persist($passwordlessMember);
            $entityManager->persist($passwordlessIdentity);
            $entityManager->flush();
            $client->loginUser($passwordlessMember, 'members');
            $client->request('GET', '/konto/profil');
            $unlinkToken = (string) $client->getCrawler()->filter('form[action="/konto/sozial/google/trennen"] input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/sozial/google/trennen', ['_token' => $unlinkToken]);
            self::assertResponseRedirects('/konto/profil');
            $client->followRedirect();
            self::assertSelectorTextContains('p[role="alert"]', 'Lege zuerst ein lokales Passwort fest');
            self::assertNotNull($this->identityRepository($entityManager)->findForMember($passwordlessMember, 'google'));
        } finally {
            $connection->executeStatement('DELETE FROM member_accounts WHERE email = :email OR email LIKE :google_only', [
                'email' => $email,
                'google_only' => 'google-only-%@example.test',
            ]);
            $this->restoreGoogleLogin($entityManager);
        }
    }

    private function beginPublicLogin(KernelBrowser $client): string
    {
        $client->request('GET', '/konto/sozial/google');
        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        $this->assertNoStorePrivateResponse($client);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);

        return $query['state'];
    }

    /** @param list<array{email: string, subject: string}> $profiles */
    private function setGoogleClient(KernelBrowser $client, array $profiles): void
    {
        $responses = [];
        foreach ($profiles as $profile) {
            $responses[] = self::jsonResponse('{"access_token":"test-access-token","token_type":"Bearer"}');
            $responses[] = self::jsonResponse(json_encode([
                'sub' => $profile['subject'],
                'email' => $profile['email'],
                'email_verified' => true,
                'name' => 'Example Google Member',
            ], JSON_THROW_ON_ERROR));
        }
        $client->getContainer()->set(GoogleOAuthClient::class, new GoogleOAuthClient(
            new MockHttpClient($responses),
            'arcanorum-test-client.apps.googleusercontent.com',
            'test-google-oauth-secret-123456',
            'https://arcanorum.example',
        ));
    }

    private function enableGoogleLogin(EntityManagerInterface $entityManager, bool $registrationEnabled): void
    {
        $entityManager->getConnection()->executeStatement(
            'UPDATE site_settings SET social_google_enabled = TRUE, member_registration_enabled = :registration_enabled WHERE id = 1',
            ['registration_enabled' => $registrationEnabled ? 'true' : 'false'],
        );
    }

    private function restoreGoogleLogin(EntityManagerInterface $entityManager): void
    {
        $entityManager->getConnection()->executeStatement('UPDATE site_settings SET social_google_enabled = FALSE, member_registration_enabled = TRUE WHERE id = 1');
    }

    private static function jsonResponse(string $body): MockResponse
    {
        return new MockResponse($body, [
            'http_code' => 200,
            'response_headers' => ['content-type: application/json'],
        ]);
    }

    private function assertNoStorePrivateResponse(KernelBrowser $client): void
    {
        $directives = array_map(
            static fn (string $directive): string => strtolower(trim($directive)),
            explode(',', (string) $client->getResponse()->headers->get('Cache-Control')),
        );

        self::assertContains('no-store', $directives);
        self::assertContains('private', $directives);
        self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
    }

    private function useIsolatedRateLimitAddress(KernelBrowser $client): void
    {
        $address = inet_ntop(random_bytes(16));
        if (false === $address) {
            throw new \RuntimeException('Could not generate a test client address.');
        }

        $client->setServerParameter('REMOTE_ADDR', $address);
    }

    private function identityRepository(EntityManagerInterface $entityManager): MemberExternalIdentityRepository
    {
        $repository = $entityManager->getRepository(MemberExternalIdentity::class);
        self::assertInstanceOf(MemberExternalIdentityRepository::class, $repository);

        return $repository;
    }
}
