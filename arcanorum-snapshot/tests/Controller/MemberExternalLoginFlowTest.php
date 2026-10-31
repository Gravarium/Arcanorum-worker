<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MemberAccount;
use App\Entity\MemberExternalIdentity;
use App\Entity\SiteSettings;
use App\Module\Member\Security\BoundedOAuthJsonClient;
use App\Module\Member\Security\FacebookOAuthClient;
use App\Module\Member\Security\MemberEmailChallengePurpose;
use App\Module\Member\Security\MemberSecurityEmailSenderInterface;
use App\Module\Member\Security\TwitterOAuthClient;
use App\Repository\MemberExternalIdentityRepository;
use App\Tests\Support\RecordingMemberSecurityEmailSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MemberExternalLoginFlowTest extends WebTestCase
{
    public function testFacebookRegistrationRequiresEmailVerificationAndPersistsTheProviderIdentity(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUniqueRemoteAddress($client);
        $email = 'facebook-'.bin2hex(random_bytes(6)).'@example.test';
        $handle = 'facebook-'.bin2hex(random_bytes(6));
        $subject = (string) random_int(100000000000, 999999999999);
        $this->setFacebookClient($client, $subject, 'Facebook Example', $email);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $this->updateSettings($entityManager, ['social_facebook_enabled' => '1', 'member_registration_enabled' => '1']);
        $facebookClient = $client->getContainer()->get(FacebookOAuthClient::class);
        self::assertInstanceOf(FacebookOAuthClient::class, $facebookClient);
        self::assertTrue($facebookClient->isConfigured());
        self::assertTrue($this->settings($entityManager)->isFacebookSocialLoginEnabled());

        try {
            $client->request('GET', '/konto/sozial/facebook');
            self::assertResponseRedirects();
            $authorizationUrl = (string) $client->getResponse()->headers->get('Location');
            if (!str_starts_with($authorizationUrl, 'https://www.facebook.com/v24.0/dialog/oauth?')) {
                $client->followRedirect();
                self::fail('Facebook login did not start: '.$client->getCrawler()->filter('p[role="alert"]')->text(''));
            }
            self::assertStringStartsWith('https://www.facebook.com/v24.0/dialog/oauth?', $authorizationUrl);
            parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationQuery);
            $state = $authorizationQuery['state'] ?? null;
            self::assertIsString($state);
            self::assertSame('S256', $authorizationQuery['code_challenge_method'] ?? null);

            $client->request('GET', '/konto/sozial/facebook/callback', ['state' => $state, 'code' => 'authorization.code']);
            if ('/konto/sozial/registrieren' !== $client->getResponse()->headers->get('Location')) {
                $client->followRedirect();
                self::fail('Facebook callback did not reach registration: '.$client->getCrawler()->filter('p[role="alert"]')->text(''));
            }
            self::assertResponseRedirects('/konto/sozial/registrieren');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="/konto/sozial/registrieren"] input[name="_token"]');
            $client->submitForm('Registrierung abschließen', [
                'email' => $email,
                'handle' => $handle,
                'display_name' => 'Facebook Example',
            ]);

            self::assertResponseRedirects('/konto/anmelden');
            $member = $entityManager->getRepository(MemberAccount::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(MemberAccount::class, $member);
            self::assertFalse($member->isEmailVerified());
            self::assertFalse($member->hasUsablePasswordCredential());
            $identity = $this->identityRepository($entityManager)->findForMember($member, 'facebook');
            self::assertInstanceOf(MemberExternalIdentity::class, $identity);
            self::assertSame($subject, $identity->getSubject());
        } finally {
            $connection->executeStatement('DELETE FROM member_accounts WHERE email = :email', ['email' => $email]);
            $this->updateSettings($entityManager, ['social_facebook_enabled' => '0', 'member_registration_enabled' => '1']);
        }
    }

    public function testDisabledSocialRegistrationIsHiddenBeforeSessionOrCsrfProcessing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->updateSettings($entityManager, ['member_registration_enabled' => '0']);
        self::assertFalse($this->settings($entityManager)->isMemberRegistrationEnabled());

        try {
            $client->request('POST', '/konto/sozial/registrieren', ['_token' => 'invalid', 'email' => 'ignored@example.test']);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertNull($entityManager->getRepository(MemberAccount::class)->findOneBy(['email' => 'ignored@example.test']));
        } finally {
            $this->updateSettings($entityManager, ['member_registration_enabled' => '1']);
        }
    }

    /** @param array<string, mixed> $malformedFields */
    #[DataProvider('malformedSocialRegistrationInputs')]
    public function testSocialRegistrationRejectsMalformedPostFields(array $malformedFields): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUniqueRemoteAddress($client);
        $subject = 'twitter-array-field-'.bin2hex(random_bytes(8));
        $this->setTwitterClient($client, $subject);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $email = 'social-array-field-'.bin2hex(random_bytes(6)).'@example.test';
        $this->updateSettings($entityManager, ['social_twitter_enabled' => '1', 'member_registration_enabled' => '1']);

        try {
            $state = $this->beginExternalLogin($client, 'twitter');
            $client->request('GET', '/konto/sozial/twitter/callback', ['state' => $state, 'code' => 'authorization.code']);
            self::assertResponseRedirects('/konto/sozial/registrieren');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form[action="/konto/sozial/registrieren"] input[name="_token"]')->attr('value');
            $input = [
                '_token' => $token,
                'email' => $email,
                'handle' => 'array-field-member',
                'display_name' => 'Array Field Member',
                'website' => '',
            ];
            $input = array_replace($input, $malformedFields);

            $client->request('POST', '/konto/sozial/registrieren', $input);

            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
            self::assertNull($entityManager->getRepository(MemberAccount::class)->findOneBy(['email' => $email]));
        } finally {
            $connection->executeStatement('DELETE FROM member_accounts WHERE email = :email', ['email' => $email]);
            $this->updateSettings($entityManager, ['social_twitter_enabled' => '0', 'member_registration_enabled' => '1']);
        }
    }

    public function testTwitterLinkRequiresCsrfAndBindsCallbackToAuthenticatedMember(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUniqueRemoteAddress($client);
        $subject = 'twitter-subject-'.bin2hex(random_bytes(8));
        $this->setTwitterClient($client, $subject);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $email = 'twitter-link-'.bin2hex(random_bytes(6)).'@example.test';
        $this->updateSettings($entityManager, ['social_twitter_enabled' => '1']);
        $member = new MemberAccount($email, 'twitter-link-'.bin2hex(random_bytes(6)), 'Twitter Link Member');
        $entityManager->persist($member);
        $entityManager->flush();

        try {
            $client->loginUser($member, 'members');
            $client->request('GET', '/konto/profil');
            self::assertResponseIsSuccessful();
            $action = '/konto/sozial/twitter/verknuepfen';
            self::assertSelectorExists('form[action="'.$action.'"]');
            $client->request('POST', $action, ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertNull($this->identityRepository($entityManager)->findForMember($member, 'twitter'));

            $client->request('GET', '/konto/profil');
            $token = (string) $client->getCrawler()->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
            $client->request('POST', $action, ['_token' => $token]);
            self::assertResponseRedirects();
            $authorizationUrl = (string) $client->getResponse()->headers->get('Location');
            self::assertStringStartsWith('https://twitter.com/i/oauth2/authorize?', $authorizationUrl);
            parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationQuery);
            $state = $authorizationQuery['state'] ?? null;
            self::assertIsString($state);

            $client->request('GET', '/konto/sozial/twitter/callback', ['state' => $state, 'code' => 'authorization.code']);
            self::assertResponseRedirects('/konto/profil');
            $identity = $this->identityRepository($entityManager)->findForMember($member, 'twitter');
            self::assertInstanceOf(MemberExternalIdentity::class, $identity);
            self::assertSame($subject, $identity->getSubject());
            self::assertSame($email, $member->getEmail());
        } finally {
            $connection->executeStatement('DELETE FROM member_accounts WHERE email = :email', ['email' => $email]);
            $this->updateSettings($entityManager, ['social_twitter_enabled' => '0']);
        }
    }

    #[DataProvider('socialProviders')]
    public function testExistingSocialIdentityCanLogInWithRegistrationClosedAndCanBeUnlinkedWithCsrf(string $provider): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUniqueRemoteAddress($client);
        $subject = 'facebook' === $provider
            ? (string) random_int(100000000000, 999999999999)
            : 'twitter-existing-subject-'.bin2hex(random_bytes(8));
        if ('facebook' === $provider) {
            $this->setFacebookClient($client, $subject, 'Facebook Existing Member', 'external@example.test');
        } else {
            $this->setTwitterClient($client, [$subject]);
        }

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $connection = $entityManager->getConnection();
        $email = $provider.'-existing-'.bin2hex(random_bytes(6)).'@example.test';
        $member = new MemberAccount($email, $provider.'-existing-'.bin2hex(random_bytes(5)), 'Existing social member');
        $member->setPassword($passwordHasher->hashPassword($member, 'ExistingSocialMember!2026'));
        $identity = new MemberExternalIdentity($member, $provider, $subject);
        $entityManager->persist($member);
        $entityManager->persist($identity);
        $entityManager->flush();
        $this->updateSettings($entityManager, [
            'social_'.$provider.'_enabled' => '1',
            'member_registration_enabled' => '0',
        ]);

        try {
            $state = $this->beginExternalLogin($client, $provider);
            $client->request('GET', '/konto/sozial/'.$provider.'/callback', ['state' => $state, 'code' => 'authorization.code']);
            self::assertResponseRedirects('/konto/profil');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $unlinkPath = '/konto/sozial/'.$provider.'/trennen';
            self::assertSelectorExists('form[action="'.$unlinkPath.'"] input[name="_token"]');

            $client->request('POST', $unlinkPath, ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertSame($subject, $this->identityRepository($entityManager)->findForMember($member, $provider)?->getSubject());

            $client->request('GET', '/konto/profil');
            $token = (string) $client->getCrawler()->filter('form[action="'.$unlinkPath.'"] input[name="_token"]')->attr('value');
            $client->request('POST', $unlinkPath, ['_token' => $token]);
            self::assertResponseRedirects('/konto/profil');
            self::assertNull($this->identityRepository($entityManager)->findForMember($member, $provider));
        } finally {
            $connection->executeStatement('DELETE FROM member_accounts WHERE email = :email', ['email' => $email]);
            $this->updateSettings($entityManager, [
                'social_'.$provider.'_enabled' => '0',
                'member_registration_enabled' => '1',
            ]);
        }
    }

    public function testTwitterRegistrationRequiresEmailVerificationButLinkedLoginSurvivesRegistrationClosure(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUniqueRemoteAddress($client);
        $emailSender = new RecordingMemberSecurityEmailSender();
        $client->getContainer()->set(MemberSecurityEmailSenderInterface::class, $emailSender);
        $subject = 'twitter-registration-subject-'.bin2hex(random_bytes(8));
        $this->setTwitterClient($client, [$subject, $subject, $subject]);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $email = 'twitter-registration-'.bin2hex(random_bytes(6)).'@example.test';
        $handle = 'twitter-registration-'.bin2hex(random_bytes(4));
        $this->updateSettings($entityManager, ['social_twitter_enabled' => '1', 'member_registration_enabled' => '1']);

        try {
            $state = $this->beginExternalLogin($client, 'twitter');
            $client->request('GET', '/konto/sozial/twitter/callback', ['state' => $state, 'code' => 'authorization.code']);
            self::assertResponseRedirects('/konto/sozial/registrieren');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form[action="/konto/sozial/registrieren"] input[name="_token"]')->attr('value');
            $client->submitForm('Registrierung abschließen', [
                '_token' => $token,
                'email' => $email,
                'handle' => $handle,
                'display_name' => 'Twitter Registration Member',
                'website' => '',
            ]);

            self::assertResponseRedirects('/konto/anmelden');
            self::assertCount(1, $emailSender->messages());
            self::assertSame(MemberEmailChallengePurpose::EmailVerification, $emailSender->messages()[0]['purpose']);
            $member = $entityManager->getRepository(MemberAccount::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(MemberAccount::class, $member);
            self::assertFalse($member->isEmailVerified());
            self::assertFalse($member->hasUsablePasswordCredential());
            self::assertSame($subject, $this->identityRepository($entityManager)->findForMember($member, 'twitter')?->getSubject());

            $state = $this->beginExternalLogin($client, 'twitter');
            $client->request('GET', '/konto/sozial/twitter/callback', ['state' => $state, 'code' => 'authorization.code']);
            self::assertResponseRedirects('/konto/anmelden');
            $client->followRedirect();
            self::assertSelectorTextContains('p[role="alert"]', 'kann für dieses Mitgliedskonto nicht bestätigt werden');

            $verificationToken = $emailSender->messages()[0]['token'];
            $verificationPath = '/konto/verifizieren/'.$verificationToken;
            $client->request('GET', $verificationPath);
            self::assertResponseIsSuccessful();
            $csrf = (string) $client->getCrawler()->filter('form[action="'.$verificationPath.'"] input[name="_token"]')->attr('value');
            $client->request('POST', $verificationPath, ['_token' => $csrf]);
            self::assertResponseRedirects('/konto/anmelden');

            $this->updateSettings($entityManager, ['member_registration_enabled' => '0']);
            $client->request('GET', '/konto/registrieren');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            $client->request('GET', '/konto/sozial/registrieren');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            $state = $this->beginExternalLogin($client, 'twitter');
            $client->request('GET', '/konto/sozial/twitter/callback', ['state' => $state, 'code' => 'authorization.code']);
            self::assertResponseRedirects('/konto/profil');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('nav.public-navigation a[href="/konto/profil"]');
            $verifiedMember = $entityManager->getRepository(MemberAccount::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(MemberAccount::class, $verifiedMember);
            self::assertTrue($verifiedMember->isEmailVerified());
        } finally {
            $connection->executeStatement('DELETE FROM member_accounts WHERE email = :email', ['email' => $email]);
            $this->updateSettings($entityManager, ['social_twitter_enabled' => '0', 'member_registration_enabled' => '1']);
        }
    }

    #[DataProvider('socialProviders')]
    public function testProviderDenialAndStateReplayNeverReachTheTokenEndpoint(string $provider): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUniqueRemoteAddress($client);
        $providerRequests = 0;
        $http = new MockHttpClient(static function (mixed ...$request) use (&$providerRequests): MockResponse {
            ++$providerRequests;

            return self::jsonResponse('{}');
        });
        $boundedClient = new BoundedOAuthJsonClient($http);
        if ('facebook' === $provider) {
            $client->getContainer()->set(FacebookOAuthClient::class, new FacebookOAuthClient($boundedClient, '123456789012345', 'test-facebook-client-secret-2026', 'https://arcanorum.example'));
        } else {
            $client->getContainer()->set(TwitterOAuthClient::class, new TwitterOAuthClient($boundedClient, 'arcanorum-twitter-client', 'test-twitter-client-secret-2026', 'https://arcanorum.example'));
        }
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->updateSettings($entityManager, ['social_'.$provider.'_enabled' => '1']);

        try {
            $state = $this->beginExternalLogin($client, $provider);
            $client->request('GET', '/konto/sozial/'.$provider.'/callback', ['state' => $state, 'error' => 'access_denied']);
            self::assertResponseRedirects('/konto/anmelden');
            $this->assertNoStorePrivateResponse($client);
            self::assertSame(0, $providerRequests);

            $client->request('GET', '/konto/sozial/'.$provider.'/callback', ['state' => $state, 'code' => 'authorization.code']);
            self::assertResponseRedirects('/konto/anmelden');
            $this->assertNoStorePrivateResponse($client);
            self::assertSame(0, $providerRequests);
        } finally {
            $this->updateSettings($entityManager, ['social_'.$provider.'_enabled' => '0', 'member_registration_enabled' => '1']);
        }
    }

    #[DataProvider('socialProviders')]
    public function testExpiredProviderStateCannotReachTheTokenEndpoint(string $provider): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUniqueRemoteAddress($client);
        $providerRequests = 0;
        $http = new MockHttpClient(static function (mixed ...$request) use (&$providerRequests): MockResponse {
            ++$providerRequests;

            return self::jsonResponse('{}');
        });
        $boundedClient = new BoundedOAuthJsonClient($http);
        if ('facebook' === $provider) {
            $client->getContainer()->set(FacebookOAuthClient::class, new FacebookOAuthClient($boundedClient, '123456789012345', 'test-facebook-client-secret-2026', 'https://arcanorum.example'));
        } else {
            $client->getContainer()->set(TwitterOAuthClient::class, new TwitterOAuthClient($boundedClient, 'arcanorum-twitter-client', 'test-twitter-client-secret-2026', 'https://arcanorum.example'));
        }
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->updateSettings($entityManager, ['social_'.$provider.'_enabled' => '1']);

        try {
            $state = $this->beginExternalLogin($client, $provider);
            $session = $client->getSession();
            self::assertInstanceOf(SessionInterface::class, $session);
            $pendingKey = 'member_'.$provider.'_oauth_pending';
            $pending = $session->get($pendingKey);
            self::assertIsArray($pending);
            $pending['created_at'] = time() - 601;
            $session->set($pendingKey, $pending);
            $session->save();

            $client->request('GET', '/konto/sozial/'.$provider.'/callback', ['state' => $state, 'code' => 'authorization.code']);
            self::assertResponseRedirects('/konto/anmelden');
            $this->assertNoStorePrivateResponse($client);
            self::assertSame(0, $providerRequests);
        } finally {
            $this->updateSettings($entityManager, ['social_'.$provider.'_enabled' => '0', 'member_registration_enabled' => '1']);
        }
    }

    #[DataProvider('socialProviders')]
    public function testSocialLoginStartAndCallbackAreRateLimited(string $provider): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUniqueRemoteAddress($client);
        if ('facebook' === $provider) {
            $this->setFacebookClient($client, '123456789012345', 'Rate limited member', 'rate-limit@example.test');
        } else {
            $this->setTwitterClient($client, ['unused-rate-limit-subject']);
        }
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->updateSettings($entityManager, ['social_'.$provider.'_enabled' => '1']);

        try {
            for ($attempt = 0; $attempt < 15; ++$attempt) {
                $client->request('GET', '/konto/sozial/'.$provider);
                self::assertResponseRedirects();
            }
            $client->request('GET', '/konto/sozial/'.$provider);
            self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
            $this->assertNoStorePrivateResponse($client);

            for ($attempt = 0; $attempt < 20; ++$attempt) {
                $client->request('GET', '/konto/sozial/'.$provider.'/callback');
                self::assertResponseRedirects('/konto/anmelden');
            }
            $client->request('GET', '/konto/sozial/'.$provider.'/callback');
            self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
            $this->assertNoStorePrivateResponse($client);
        } finally {
            $this->updateSettings($entityManager, ['social_'.$provider.'_enabled' => '0', 'member_registration_enabled' => '1']);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function socialProviders(): iterable
    {
        yield 'Facebook' => ['facebook'];
        yield 'Twitter' => ['twitter'];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedSocialRegistrationInputs(): iterable
    {
        yield 'CSRF token' => [['_token' => ['malformed']]];
        yield 'email' => [['email' => ['malformed']]];
        yield 'handle' => [['handle' => ['malformed']]];
        yield 'display name' => [['display_name' => ['malformed']]];
        yield 'honeypot' => [['website' => ['malformed']]];
        yield 'unknown field' => [['unexpected_field' => 'malformed']];
    }

    private function beginExternalLogin(KernelBrowser $client, string $provider): string
    {
        $client->request('GET', '/konto/sozial/'.$provider);
        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        $expectedPrefix = 'facebook' === $provider
            ? 'https://www.facebook.com/v24.0/dialog/oauth?'
            : 'https://twitter.com/i/oauth2/authorize?';
        self::assertStringStartsWith($expectedPrefix, $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);

        return $query['state'];
    }

    private function setUniqueRemoteAddress(KernelBrowser $client): void
    {
        $address = inet_ntop(random_bytes(16));
        if (false === $address) {
            throw new \RuntimeException('Could not generate a test client address.');
        }

        $client->setServerParameter('REMOTE_ADDR', $address);
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

    private function setFacebookClient(KernelBrowser $client, string $subject, string $name, string $email): void
    {
        $client->getContainer()->set(FacebookOAuthClient::class, new FacebookOAuthClient(
            new BoundedOAuthJsonClient(new MockHttpClient([
                self::jsonResponse('{"access_token":"test-facebook-token","token_type":"Bearer"}'),
                self::jsonResponse(json_encode(['id' => $subject, 'name' => $name, 'email' => $email], JSON_THROW_ON_ERROR)),
            ])),
            '123456789012345',
            'test-facebook-client-secret-2026',
            'https://arcanorum.example',
        ));
    }

    /** @param non-empty-string|non-empty-list<non-empty-string> $subjects */
    private function setTwitterClient(KernelBrowser $client, string|array $subjects): void
    {
        $subjects = is_string($subjects) ? [$subjects] : $subjects;
        $responses = [];
        foreach ($subjects as $subject) {
            $responses[] = self::jsonResponse('{"access_token":"test-twitter-token","token_type":"Bearer"}');
            $responses[] = self::jsonResponse(json_encode(['data' => ['id' => $subject, 'name' => 'Twitter Example', 'username' => 'member']], JSON_THROW_ON_ERROR));
        }
        $client->getContainer()->set(TwitterOAuthClient::class, new TwitterOAuthClient(
            new BoundedOAuthJsonClient(new MockHttpClient($responses)),
            'arcanorum-twitter-client',
            'test-twitter-client-secret-2026',
            'https://arcanorum.example',
        ));
    }

    private static function jsonResponse(string $body): MockResponse
    {
        return new MockResponse($body, [
            'http_code' => 200,
            'response_headers' => ['content-type: application/json'],
        ]);
    }

    private function identityRepository(EntityManagerInterface $entityManager): MemberExternalIdentityRepository
    {
        $repository = $entityManager->getRepository(MemberExternalIdentity::class);
        self::assertInstanceOf(MemberExternalIdentityRepository::class, $repository);

        return $repository;
    }

    /** @param array<string, string> $changes */
    private function updateSettings(EntityManagerInterface $entityManager, array $changes): void
    {
        $settings = $this->settings($entityManager);
        $errors = $settings->updateFromInput([
            'site_name' => $settings->getSiteName(),
            'tagline' => $settings->getTagline(),
            'locale' => $settings->getDefaultLocale(),
            'timezone' => $settings->getTimezone(),
            ...$changes,
        ]);
        self::assertSame([], $errors);
        $entityManager->flush();
    }

    private function settings(EntityManagerInterface $entityManager): SiteSettings
    {
        $settings = $entityManager->getRepository(SiteSettings::class)->find(SiteSettings::SINGLETON_ID);
        self::assertInstanceOf(SiteSettings::class, $settings);

        return $settings;
    }
}
