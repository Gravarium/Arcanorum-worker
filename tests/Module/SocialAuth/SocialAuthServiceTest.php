<?php

declare(strict_types=1);

namespace App\Tests\Module\SocialAuth;

use App\Module\SocialAuth\AuthenticatedAccount;
use App\Module\SocialAuth\AuthenticationResult;
use App\Module\SocialAuth\AuthenticationRejected;
use App\Module\SocialAuth\AuthorizationRequest;
use App\Module\SocialAuth\Flow;
use App\Module\SocialAuth\MapOAuthProviderClientRegistry;
use App\Module\SocialAuth\Provider;
use App\Module\SocialAuth\SocialAuthService;
use App\Module\SocialAuth\SocialAuthSettings;
use App\Tests\Module\SocialAuth\Support\FakeClock;
use App\Tests\Module\SocialAuth\Support\FakeIdentityRepository;
use App\Tests\Module\SocialAuth\Support\FakeOAuthProviderClient;
use App\Tests\Module\SocialAuth\Support\FakeOAuthStateStore;
use App\Tests\Module\SocialAuth\Support\FakeRateLimiter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SocialAuthServiceTest extends TestCase
{
    private const SESSION_BINDING = 'test-session-binding-32-characters';

    private FakeClock $clock;

    private FakeOAuthStateStore $states;

    private FakeIdentityRepository $identities;

    private FakeRateLimiter $rateLimiter;

    private FakeOAuthProviderClient $google;

    private FakeOAuthProviderClient $facebook;

    private FakeOAuthProviderClient $twitter;

    private SocialAuthService $service;

    /**
     * @param array<array-key, array<mixed>> $data
     */
    public function __construct(?string $name = null, array $data = [], string $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->clock = new FakeClock(new DateTimeImmutable('2026-10-25T23:30:00+00:00'));
        $this->states = new FakeOAuthStateStore();
        $this->identities = new FakeIdentityRepository();
        $this->rateLimiter = new FakeRateLimiter();
        $this->google = new FakeOAuthProviderClient('https://accounts.google.com/o/oauth2/v2/auth');
        $this->facebook = new FakeOAuthProviderClient('https://www.facebook.com/v19.0/dialog/oauth');
        $this->twitter = new FakeOAuthProviderClient('https://twitter.com/i/oauth2/authorize');
        $this->service = $this->createService($this->settings());
    }

    public function testEachProviderGetsSeparateEnabledStatusAndAStateBoundPkceRedirect(): void
    {
        $this->configureService($this->settings(['google', 'twitter']));

        $googleRequest = $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING);
        $twitterRequest = $this->service->begin('twitter', Flow::LOGIN->value, self::SESSION_BINDING);

        self::assertSame(Provider::GOOGLE, $googleRequest->provider);
        self::assertSame(Provider::TWITTER, $twitterRequest->provider);
        self::assertSame('accounts.google.com', parse_url($googleRequest->authorizationUrl, PHP_URL_HOST));
        self::assertSame('twitter.com', parse_url($twitterRequest->authorizationUrl, PHP_URL_HOST));

        foreach ([$googleRequest, $twitterRequest] as $request) {
            $query = $this->query($request->authorizationUrl);
            $state = $query['state'] ?? null;
            $challenge = $query['code_challenge'] ?? null;
            if (!is_string($state) || !is_string($challenge)) {
                self::fail('The authorization URL did not contain state and PKCE values.');
            }

            self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $state);
            self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $challenge);
            self::assertSame('S256', $query['code_challenge_method'] ?? null);
            self::assertSame('https://cms.example.test/auth/social/callback', $query['redirect_uri'] ?? null);
            self::assertArrayHasKey(hash('sha256', $state), $this->states->records);
        }

        $this->assertRejected(fn () => $this->service->begin('facebook', Flow::LOGIN->value, self::SESSION_BINDING));
        self::assertSame(0, $this->facebook->exchangeCallCount());
    }

    public function testUnknownProviderAndFlowFailClosed(): void
    {
        $this->assertRejected(fn () => $this->service->begin('unknown', Flow::LOGIN->value, self::SESSION_BINDING));
        $this->assertRejected(fn () => $this->service->begin('google', 'admin', self::SESSION_BINDING));

        self::assertSame(0, $this->states->recordCount());
    }

    public function testRegistrationCanBeDisabledBeforeAnyProviderProcessing(): void
    {
        $this->configureService($this->settings(registrationEnabled: false));

        $this->assertRejected(fn () => $this->service->begin('google', Flow::REGISTRATION->value, self::SESSION_BINDING));

        self::assertSame(0, $this->states->recordCount());
        self::assertSame(0, $this->google->exchangeCallCount());
    }

    public function testLoginDoesNotCreateAnAccountWhenRegistrationIsEnabled(): void
    {
        $request = $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING);

        $this->assertRejected(fn () => $this->complete($request));

        self::assertSame(0, $this->identities->registrationCallCount());
        self::assertSame(1, $this->google->exchangeCalls);
    }

    public function testRegistrationCreatesOnlyFromTheRegistrationFlowAndDropsUnverifiedEmail(): void
    {
        $this->google->normalizedProfile = '{"subject":"provider-subject-1","email":"unverified@example.test","email_verified":false}';
        $request = $this->service->begin('google', Flow::REGISTRATION->value, self::SESSION_BINDING);

        $result = $this->complete($request);

        self::assertSame('new-account', $result->accountId);
        self::assertSame(Flow::REGISTRATION, $result->flow);
        self::assertSame(1, $this->identities->registrationCalls);
        $registered = $this->identities->registered['google:provider-subject-1'] ?? null;
        if ($registered === null) {
            self::fail('The provider identity was not registered.');
        }

        self::assertNull($registered->verifiedEmail);
    }

    public function testVerifiedEmailIsTheOnlyEmailPassedToTheIdentityRepository(): void
    {
        $request = $this->service->begin('google', Flow::REGISTRATION->value, self::SESSION_BINDING);

        $this->complete($request);

        $registered = $this->identities->registered['google:provider-subject-1'] ?? null;
        if ($registered === null) {
            self::fail('The provider identity was not registered.');
        }

        self::assertSame('verified@example.test', $registered->verifiedEmail?->value);
    }

    public function testRegistrationRejectsAnIdentityAlreadyUsedByAnotherAccount(): void
    {
        $this->identities->seed(Provider::GOOGLE, 'provider-subject-1', 'existing-account');
        $request = $this->service->begin('google', Flow::REGISTRATION->value, self::SESSION_BINDING);

        $exception = $this->assertRejected(fn () => $this->complete($request));

        self::assertSame('Die Anmeldung konnte nicht abgeschlossen werden.', $exception->getMessage());
        self::assertSame('existing-account', $this->identities->owners['google:provider-subject-1'] ?? null);
        self::assertSame(1, $this->identities->registrationCalls);
    }

    public function testPkceVerifierIsStoredServerSideAndUsedAtCallback(): void
    {
        $request = $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING);
        $query = $this->query($request->authorizationUrl);
        $state = $query['state'] ?? null;
        $challenge = $query['code_challenge'] ?? null;
        if (!is_string($state) || !is_string($challenge)) {
            self::fail('The authorization URL did not contain state and PKCE values.');
        }

        $record = $this->states->records[hash('sha256', $state)] ?? null;
        if ($record === null) {
            self::fail('The state digest was not stored.');
        }

        self::assertSame(hash('sha256', self::SESSION_BINDING), $record->sessionBindingDigest);
        self::assertArrayNotHasKey($state, $this->states->records);
        $expectedChallenge = $this->base64UrlEncode(hash('sha256', $record->codeVerifier, true));

        $this->identities->seed(Provider::GOOGLE, 'provider-subject-1', 'account-1');
        $this->complete($request);

        self::assertSame($expectedChallenge, $challenge);
        self::assertSame($record->codeVerifier, $this->google->lastCodeVerifier);
        self::assertSame('https://cms.example.test/auth/social/callback', $this->google->lastCallbackUri);
        self::assertSame(16_384, $this->google->lastMaxResponseBytes);
    }

    public function testStateIsSingleUseAndBoundToTheInitiatingSession(): void
    {
        $request = $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING);
        $state = $this->state($request->authorizationUrl);

        $this->assertRejected(fn () => $this->service->complete('google', 'authorization-code', $state, 'different-session-binding-32-characters'));
        self::assertSame(0, $this->google->exchangeCallCount());
        self::assertSame(0, $this->states->recordCount());
        $this->assertRejected(fn () => $this->service->complete('google', 'authorization-code', $state, self::SESSION_BINDING));
    }

    public function testLinkCallbackRequiresTheOriginalSessionBinding(): void
    {
        $account = $this->authenticatedAccount('account-1');
        $request = $this->service->begin('google', Flow::LINK->value, self::SESSION_BINDING, $account);

        $this->assertRejected(fn () => $this->complete($request, $account, 'different-session-binding-32-characters'));

        self::assertSame(0, $this->google->exchangeCallCount());
        self::assertSame(0, $this->identities->linkCallCount());
    }

    public function testCallbackRejectsMissingMalformedExpiredAndMismatchedState(): void
    {
        $this->assertRejected(fn () => $this->service->complete('google', 'authorization-code', '', self::SESSION_BINDING));
        $this->assertRejected(fn () => $this->service->complete('google', 'authorization-code', str_repeat('x', 44), self::SESSION_BINDING));

        $expiredRequest = $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING);
        $expiredState = $this->state($expiredRequest->authorizationUrl);
        $this->clock->advance('+300 seconds');
        $this->assertRejected(fn () => $this->service->complete('google', 'authorization-code', $expiredState, self::SESSION_BINDING));
        self::assertSame(0, $this->google->exchangeCallCount());

        $this->clock->advance('-300 seconds');
        $mismatchedRequest = $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING);
        $mismatchedState = $this->state($mismatchedRequest->authorizationUrl);
        $this->assertRejected(fn () => $this->service->complete('facebook', 'authorization-code', $mismatchedState, self::SESSION_BINDING));
        self::assertSame(0, $this->google->exchangeCallCount());
    }

    public function testDisabledProviderIsRecheckedBeforeExchangingTheCode(): void
    {
        $request = $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING);
        $this->configureService($this->settings(['facebook', 'twitter']));

        $this->assertRejected(fn () => $this->complete($request));

        self::assertSame(0, $this->google->exchangeCallCount());
    }

    public function testRegistrationDisabledAfterStartIsRejectedBeforeProviderExchange(): void
    {
        $request = $this->service->begin('google', Flow::REGISTRATION->value, self::SESSION_BINDING);
        $this->configureService($this->settings(registrationEnabled: false));

        $this->assertRejected(fn () => $this->complete($request));

        self::assertSame(0, $this->google->exchangeCallCount());
        self::assertSame(0, $this->identities->registrationCallCount());
    }

    public function testUntrustedAuthorizationEndpointAndReservedParametersAreRejected(): void
    {
        $this->google->endpoint = 'https://attacker.example/authorize';
        $this->assertRejected(fn () => $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING));
        self::assertSame(0, $this->states->recordCount());

        $this->google->endpoint = 'https://accounts.google.com/o/oauth2/v2/auth';
        $this->google->parameters = ['state' => 'attacker-controlled'];
        $this->assertRejected(fn () => $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING));
        self::assertSame(0, $this->states->recordCount());
    }

    public function testMalformedAuthorizationParametersAreRejectedBeforeStateStorage(): void
    {
        $this->google->parameters = ['scope' => ['openid', 'email']];

        $this->assertRejected(fn () => $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING));

        self::assertSame(0, $this->states->recordCount());
    }

    public function testInvalidCallbackConfigurationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SocialAuthSettings(
            ['google'],
            true,
            [
                'google' => 'http://cms.example.test/auth/callback',
                'facebook' => 'https://cms.example.test/auth/facebook',
                'twitter' => 'https://cms.example.test/auth/twitter',
            ],
            [
                'google' => 'accounts.google.com',
                'facebook' => 'www.facebook.com',
                'twitter' => 'twitter.com',
            ],
        );
    }

    public function testLinkingRequiresRecentAuthenticationAndUsesTheStoredAccount(): void
    {
        $account = $this->authenticatedAccount('account-1');
        $this->assertRejected(fn () => $this->service->begin('google', Flow::LINK->value, self::SESSION_BINDING));
        $this->assertRejected(fn () => $this->service->begin('google', Flow::LINK->value, self::SESSION_BINDING, $this->authenticatedAccount('account-1', '-10 minutes')));

        $request = $this->service->begin('google', Flow::LINK->value, self::SESSION_BINDING, $account);
        $state = $this->state($request->authorizationUrl);

        $this->assertRejected(fn () => $this->service->complete('google', 'authorization-code', $state, self::SESSION_BINDING, $this->authenticatedAccount('account-2')));
        self::assertSame(0, $this->google->exchangeCallCount());
        self::assertSame(0, $this->identities->linkCallCount());
    }

    public function testLinkingNeverReassignsAnIdentityAlreadyOwnedByAnotherAccount(): void
    {
        $this->identities->seed(Provider::GOOGLE, 'provider-subject-1', 'existing-account');
        $request = $this->service->begin('google', Flow::LINK->value, self::SESSION_BINDING, $this->authenticatedAccount('target-account'));

        $this->assertRejected(fn () => $this->complete($request, $this->authenticatedAccount('target-account')));

        self::assertSame('existing-account', $this->identities->owners['google:provider-subject-1'] ?? null);
        self::assertSame(1, $this->identities->linkCalls);
    }

    public function testSuccessfulLinkIsBoundToFreshAccountContext(): void
    {
        $request = $this->service->begin('google', Flow::LINK->value, self::SESSION_BINDING, $this->authenticatedAccount('account-1'));

        $result = $this->complete($request, $this->authenticatedAccount('account-1'));

        self::assertSame(Flow::LINK, $result->flow);
        self::assertSame('account-1', $result->accountId);
        self::assertSame('account-1', $this->identities->owners['google:provider-subject-1'] ?? null);
    }

    public function testUnlinkRefusesTheLastAuthenticationMethodAndAllowsSafeRemoval(): void
    {
        $this->identities->seed(Provider::GOOGLE, 'provider-subject-1', 'account-1');
        $account = $this->authenticatedAccount('account-1');
        $this->assertRejected(fn () => $this->service->unlink('google', $account));
        self::assertSame('account-1', $this->identities->owners['google:provider-subject-1'] ?? null);

        $this->identities->additionalAuthenticationMethods['account-1'] = 1;
        $this->service->unlink('google', $account);
        self::assertArrayNotHasKey('google:provider-subject-1', $this->identities->owners);
    }

    public function testOversizedAndMalformedProviderResponsesFailGenerically(): void
    {
        $this->configureService($this->settings(maxResponseBytes: 256));
        $this->google->normalizedProfile = str_repeat('x', 257);
        $oversizedRequest = $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING);
        $oversizedException = $this->assertRejected(fn () => $this->complete($oversizedRequest));
        self::assertSame('Die Anmeldung konnte nicht abgeschlossen werden.', $oversizedException->getMessage());

        $this->google->normalizedProfile = '{"subject":[],"email_verified":"yes"}';
        $malformedRequest = $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING);
        $this->assertRejected(fn () => $this->complete($malformedRequest));
        self::assertSame(2, $this->google->exchangeCalls);
    }

    public function testProviderExceptionDetailsAreNotReturned(): void
    {
        $this->google->failure = new \RuntimeException('access_token=private-value');
        $request = $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING);

        $exception = $this->assertRejected(fn () => $this->complete($request));

        self::assertStringNotContainsString('access_token', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    public function testRateLimitsApplyToStartAndCallback(): void
    {
        $this->rateLimiter->allowed = false;
        $this->assertRejected(fn () => $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING));
        self::assertSame(0, $this->states->recordCount());
        $this->rateLimiter->allowed = true;

        $request = $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING);
        $this->rateLimiter->allowed = false;
        $this->assertRejected(fn () => $this->complete($request));
        self::assertSame(0, $this->google->exchangeCallCount());
        self::assertSame(3, count($this->rateLimiter->calls));
    }

    public function testRateLimiterReceivesOnlyADigestOfTheAccountIdentifier(): void
    {
        $account = $this->authenticatedAccount('account-1');

        $this->service->begin('google', Flow::LINK->value, self::SESSION_BINDING, $account);

        self::assertSame(hash('sha256', 'account-1'), $this->rateLimiter->calls[0][2] ?? null);
        self::assertNotSame('account-1', $this->rateLimiter->calls[0][2] ?? null);
    }

    public function testClientCannotOverrideRequiredOAuthParameters(): void
    {
        $this->google->parameters = ['redirect_uri' => 'https://attacker.example/callback'];

        $this->assertRejected(fn () => $this->service->begin('google', Flow::LOGIN->value, self::SESSION_BINDING));

        self::assertSame(0, $this->states->recordCount());
    }

    /**
     * @param list<string> $enabledProviders
     */
    private function settings(
        array $enabledProviders = ['google', 'facebook', 'twitter'],
        bool $registrationEnabled = true,
        int $maxResponseBytes = 16_384,
    ): SocialAuthSettings {
        return new SocialAuthSettings(
            $enabledProviders,
            $registrationEnabled,
            [
                'google' => 'https://cms.example.test/auth/social/callback',
                'facebook' => 'https://cms.example.test/auth/social/callback',
                'twitter' => 'https://cms.example.test/auth/social/callback',
            ],
            [
                'google' => 'accounts.google.com',
                'facebook' => 'www.facebook.com',
                'twitter' => 'twitter.com',
            ],
            maxProfileResponseBytes: $maxResponseBytes,
        );
    }

    private function configureService(SocialAuthSettings $settings): void
    {
        $this->service = $this->createService($settings);
    }

    private function createService(SocialAuthSettings $settings): SocialAuthService
    {
        return new SocialAuthService(
            $settings,
            new MapOAuthProviderClientRegistry([
                'google' => $this->google,
                'facebook' => $this->facebook,
                'twitter' => $this->twitter,
            ]),
            $this->states,
            $this->identities,
            $this->rateLimiter,
            $this->clock,
        );
    }

    private function complete(
        AuthorizationRequest $request,
        ?AuthenticatedAccount $account = null,
        string $sessionBinding = self::SESSION_BINDING,
    ): AuthenticationResult {
        return $this->service->complete(
            $request->provider->value,
            'authorization-code',
            $this->state($request->authorizationUrl),
            $sessionBinding,
            $account,
        );
    }

    private function state(string $authorizationUrl): string
    {
        $state = $this->query($authorizationUrl)['state'] ?? null;
        if (!is_string($state)) {
            self::fail('The authorization URL did not contain a state value.');
        }

        return $state;
    }

    /** @return array<int|string, array<mixed>|string> */
    private function query(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            self::fail('The authorization URL could not be parsed.');
        }

        $queryString = $parts['query'] ?? '';

        parse_str($queryString, $query);

        return $query;
    }

    private function authenticatedAccount(string $accountId, string $reauthenticationAge = '-60 seconds'): AuthenticatedAccount
    {
        return new AuthenticatedAccount($accountId, $this->clock->now()->modify($reauthenticationAge));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @param callable(): mixed $operation */
    private function assertRejected(callable $operation): AuthenticationRejected
    {
        try {
            $operation();
        } catch (AuthenticationRejected $exception) {
            self::assertSame('Die Anmeldung konnte nicht abgeschlossen werden.', $exception->getMessage());
            self::assertNull($exception->getPrevious());

            return $exception;
        } catch (Throwable $exception) {
            self::fail('An unexpected exception escaped: ' . $exception::class);
        }

        self::fail('The operation unexpectedly succeeded.');
    }
}
