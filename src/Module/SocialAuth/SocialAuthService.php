<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

final readonly class SocialAuthService
{
    private const SENSITIVE_AUTHORIZATION_PARAMETER_NAME_FRAGMENTS = [
        'secret',
        'token',
        'password',
        'credential',
        'verifier',
        'assertion',
        'private_key',
        'api_key',
    ];

    public function __construct(
        private SocialAuthSettings $settings,
        private OAuthProviderClientRegistry $clients,
        private OAuthStateStore $states,
        private SocialIdentityRepository $identities,
        private AuthenticationRateLimiter $rateLimiter,
        private Clock $clock,
    ) {
    }

    public function begin(string $providerId, string $flowId, string $sessionBinding, ?AuthenticatedAccount $account = null): AuthorizationRequest
    {
        $provider = Provider::tryFrom($providerId);
        $flow = Flow::tryFrom($flowId);
        if (null === $provider || null === $flow || !$this->settings->isProviderEnabled($provider)) {
            throw new AuthenticationRejected();
        }

        $sessionBindingDigest = $this->sessionBindingDigest($sessionBinding);

        if (Flow::REGISTRATION === $flow && !$this->settings->isRegistrationEnabled()) {
            throw new AuthenticationRejected();
        }

        if (Flow::LINK === $flow) {
            if (null === $account) {
                throw new AuthenticationRejected();
            }

            $this->assertRecentlyReauthenticated($account);
        } elseif (null !== $account) {
            throw new AuthenticationRejected();
        }

        $this->consumeRateLimit($provider, 'begin_'.$flow->value, $account?->accountId);

        $now = $this->clock->now();
        $expiresAt = $now->modify('+'.$this->settings->stateLifetimeSeconds().' seconds');

        $state = $this->base64UrlEncode(random_bytes(32));
        $verifier = $this->base64UrlEncode(random_bytes(32));
        $challenge = $this->base64UrlEncode(hash('sha256', $verifier, true));
        $callbackUri = $this->settings->callbackUri($provider);

        try {
            $client = $this->clients->clientFor($provider);
            $endpoint = $client->authorizationEndpoint();
            $extraParameters = $client->authorizationParameters();
        } catch (\Throwable) {
            throw new AuthenticationRejected();
        }

        $authorizationUrl = $this->buildAuthorizationUrl(
            $provider,
            $endpoint,
            $extraParameters,
            $callbackUri,
            $state,
            $challenge,
        );

        $transaction = new OAuthState(
            $provider,
            $flow,
            $verifier,
            $callbackUri,
            $account?->accountId,
            $sessionBindingDigest,
            $expiresAt,
        );
        try {
            $this->states->store(hash('sha256', $state), $transaction);
        } catch (\Throwable) {
            throw new AuthenticationRejected();
        }

        return new AuthorizationRequest($provider, $authorizationUrl, $expiresAt);
    }

    public function complete(
        string $providerId,
        string $authorizationCode,
        string $state,
        string $sessionBinding,
        ?AuthenticatedAccount $account = null,
    ): AuthenticationResult {
        if (1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $state)) {
            throw new AuthenticationRejected();
        }

        try {
            $transaction = $this->states->consume(hash('sha256', $state));
        } catch (\Throwable) {
            throw new AuthenticationRejected();
        }

        if (null === $transaction) {
            throw new AuthenticationRejected();
        }

        $sessionBindingDigest = $this->sessionBindingDigest($sessionBinding);
        $provider = Provider::tryFrom($providerId);
        $now = $this->clock->now();
        if (
            null === $provider
            || $provider !== $transaction->provider
            || !$this->settings->isProviderEnabled($provider)
            || $now >= $transaction->expiresAt
            || $transaction->callbackUri !== $this->settings->callbackUri($transaction->provider)
            || !hash_equals($transaction->sessionBindingDigest, $sessionBindingDigest)
            || 1 !== preg_match('/\A[^\x00-\x20\x7F]{1,2048}\z/D', $authorizationCode)
        ) {
            throw new AuthenticationRejected();
        }

        if (Flow::LINK === $transaction->flow) {
            if (null === $account || $transaction->accountId !== $account->accountId) {
                throw new AuthenticationRejected();
            }

            $this->assertRecentlyReauthenticated($account);
        } elseif (null !== $account) {
            throw new AuthenticationRejected();
        }

        if (Flow::REGISTRATION === $transaction->flow && !$this->settings->isRegistrationEnabled()) {
            throw new AuthenticationRejected();
        }

        $this->consumeRateLimit($provider, 'complete_'.$transaction->flow->value, $transaction->accountId);

        try {
            $client = $this->clients->clientFor($provider);
            $normalizedProfile = $client->exchangeAuthorizationCode(
                $authorizationCode,
                $transaction->codeVerifier,
                $transaction->callbackUri,
                $this->settings->maxProfileResponseBytes(),
            );
        } catch (\Throwable) {
            throw new AuthenticationRejected();
        }

        $identity = new ProviderIdentityParser($this->settings->maxProfileResponseBytes())->parse($provider, $normalizedProfile);

        return match ($transaction->flow) {
            Flow::LOGIN => $this->login($identity),
            Flow::REGISTRATION => $this->register($identity),
            Flow::LINK => $this->link($identity, $transaction->accountId),
        };
    }

    public function unlink(string $providerId, AuthenticatedAccount $account): void
    {
        $provider = Provider::tryFrom($providerId);
        if (null === $provider) {
            throw new AuthenticationRejected();
        }

        $this->assertRecentlyReauthenticated($account);
        $this->consumeRateLimit($provider, 'unlink', $account->accountId);

        try {
            $unlinked = $this->identities->unlinkIfAnotherMethodRemains($account->accountId, $provider);
        } catch (\Throwable) {
            throw new AuthenticationRejected();
        }

        if (!$unlinked) {
            throw new AuthenticationRejected();
        }
    }

    private function login(ProviderIdentity $identity): AuthenticationResult
    {
        try {
            $accountId = $this->identities->accountIdFor($identity->provider, $identity->subject);
        } catch (\Throwable) {
            throw new AuthenticationRejected();
        }

        if (null === $accountId || '' === $accountId) {
            throw new AuthenticationRejected();
        }

        return new AuthenticationResult($accountId, $identity->provider, Flow::LOGIN);
    }

    private function register(ProviderIdentity $identity): AuthenticationResult
    {
        if (!$this->settings->isRegistrationEnabled()) {
            throw new AuthenticationRejected();
        }

        try {
            $accountId = $this->identities->registerAccount($identity);
        } catch (\Throwable) {
            throw new AuthenticationRejected();
        }

        if (null === $accountId || '' === $accountId) {
            throw new AuthenticationRejected();
        }

        return new AuthenticationResult($accountId, $identity->provider, Flow::REGISTRATION);
    }

    private function link(ProviderIdentity $identity, ?string $accountId): AuthenticationResult
    {
        if (null === $accountId || '' === $accountId) {
            throw new AuthenticationRejected();
        }

        try {
            $linked = $this->identities->linkToAccount($accountId, $identity);
        } catch (\Throwable) {
            throw new AuthenticationRejected();
        }

        if (!$linked) {
            throw new AuthenticationRejected();
        }

        return new AuthenticationResult($accountId, $identity->provider, Flow::LINK);
    }

    /**
     * @param array<mixed, mixed> $extraParameters
     */
    private function buildAuthorizationUrl(
        Provider $provider,
        string $endpoint,
        array $extraParameters,
        string $callbackUri,
        string $state,
        string $challenge,
    ): string {
        $parts = parse_url($endpoint);
        if (!is_array($parts)) {
            throw new AuthenticationRejected();
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? '';
        if (
            !is_string($scheme)
            || 'https' !== strtolower($scheme)
            || !is_string($host)
            || strtolower($host) !== $this->settings->authorizationHost($provider)
            || 1 === preg_match('/[\x00-\x20\x7F]/', $path)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['port'])
        ) {
            throw new AuthenticationRejected();
        }

        $requiredParameters = [
            'response_type' => 'code',
            'redirect_uri' => $callbackUri,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        $requiredParameterNames = array_fill_keys(array_map(strtolower(...), array_keys($requiredParameters)), true);
        $seenParameters = [];

        if (count($extraParameters) > 32) {
            throw new AuthenticationRejected();
        }

        foreach ($extraParameters as $key => $value) {
            $normalizedKey = is_string($key) ? strtolower($key) : '';
            if (
                !is_string($key)
                || !is_string($value)
                || 1 !== preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D', $key)
                || isset($requiredParameterNames[$normalizedKey])
                || $this->containsSensitiveAuthorizationParameterName($normalizedKey)
                || isset($seenParameters[$normalizedKey])
                || '' === $value
                || strlen($value) > 2048
                || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)
            ) {
                throw new AuthenticationRejected();
            }

            $seenParameters[$normalizedKey] = true;
        }

        $parameters = array_merge($extraParameters, $requiredParameters);
        $baseUrl = 'https://'.strtolower($host).$path;
        $url = $baseUrl.'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        if (strlen($url) > 8192) {
            throw new AuthenticationRejected();
        }

        return $url;
    }

    private function containsSensitiveAuthorizationParameterName(string $parameterName): bool
    {
        foreach (self::SENSITIVE_AUTHORIZATION_PARAMETER_NAME_FRAGMENTS as $fragment) {
            if (str_contains($parameterName, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function assertRecentlyReauthenticated(AuthenticatedAccount $account): void
    {
        $now = $this->clock->now();
        $oldestAllowed = $now->modify('-'.$this->settings->reauthenticationWindowSeconds().' seconds');
        if ($account->lastReauthenticatedAt > $now || $account->lastReauthenticatedAt < $oldestAllowed) {
            throw new AuthenticationRejected();
        }
    }

    private function consumeRateLimit(Provider $provider, string $operation, ?string $accountId): void
    {
        try {
            $accountKey = null === $accountId ? null : hash('sha256', $accountId);
            $allowed = $this->rateLimiter->consume($provider, $operation, $accountKey);
        } catch (\Throwable) {
            throw new AuthenticationRejected();
        }

        if (!$allowed) {
            throw new AuthenticationRejected();
        }
    }

    private function sessionBindingDigest(string $sessionBinding): string
    {
        if (
            strlen($sessionBinding) < 32
            || strlen($sessionBinding) > 512
            || 1 === preg_match('/[\x00-\x20\x7F]/', $sessionBinding)
        ) {
            throw new AuthenticationRejected();
        }

        return hash('sha256', $sessionBinding);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
