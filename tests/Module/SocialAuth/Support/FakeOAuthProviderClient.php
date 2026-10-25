<?php

declare(strict_types=1);

namespace App\Tests\Module\SocialAuth\Support;

use App\Module\SocialAuth\OAuthProviderClient;

final class FakeOAuthProviderClient implements OAuthProviderClient
{
    /** @var array<mixed, mixed> */
    public array $parameters = ['client_id' => 'test-client', 'scope' => 'openid email'];

    public string $normalizedProfile = '{"subject":"provider-subject-1","email":"verified@example.test","email_verified":true}';

    public int $exchangeCalls = 0;

    public string $lastCode = '';

    public string $lastCodeVerifier = '';

    public string $lastCallbackUri = '';

    public int $lastMaxResponseBytes = 0;

    public ?\Throwable $failure = null;

    public function __construct(public string $endpoint)
    {
    }

    public function authorizationEndpoint(): string
    {
        return $this->endpoint;
    }

    public function authorizationParameters(): array
    {
        return $this->parameters;
    }

    public function exchangeCallCount(): int
    {
        return $this->exchangeCalls;
    }

    public function exchangeAuthorizationCode(string $code, string $codeVerifier, string $callbackUri, int $maxResponseBytes): string
    {
        ++$this->exchangeCalls;
        $this->lastCode = $code;
        $this->lastCodeVerifier = $codeVerifier;
        $this->lastCallbackUri = $callbackUri;
        $this->lastMaxResponseBytes = $maxResponseBytes;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->normalizedProfile;
    }
}
