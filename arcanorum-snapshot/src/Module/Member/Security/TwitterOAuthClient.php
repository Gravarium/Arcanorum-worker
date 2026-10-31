<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

final readonly class TwitterOAuthClient implements SocialOAuthClientInterface
{
    private const string AUTHORIZE_ENDPOINT = 'https://twitter.com/i/oauth2/authorize';
    private const string TOKEN_ENDPOINT = 'https://api.twitter.com/2/oauth2/token';
    private const string USERINFO_ENDPOINT = 'https://api.twitter.com/2/users/me?user.fields=name,username';

    public function __construct(
        private BoundedOAuthJsonClient $jsonClient,
        private string $clientId,
        private string $clientSecret,
        private string $publicBaseUrl,
    ) {
    }

    public function provider(): string
    {
        return 'twitter';
    }

    public function isConfigured(): bool
    {
        return 1 === preg_match('/\A[A-Za-z0-9._-]{1,255}\z/D', $this->clientId)
            && 1 === preg_match('/\A[^\x00-\x20\x7F]{16,512}\z/D', $this->clientSecret)
            && null !== SocialOAuthCallbackUrl::fromBaseUrl($this->publicBaseUrl, $this->provider());
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        if (!$this->isConfigured()
            || 1 !== preg_match('/\A[A-Za-z0-9_-]{43,128}\z/D', $state)
            || 1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $codeChallenge)
        ) {
            throw new \LogicException('Die Twitter-Anmeldung ist nicht sicher konfiguriert.');
        }

        return self::AUTHORIZE_ENDPOINT.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => SocialOAuthCallbackUrl::fromBaseUrl($this->publicBaseUrl, $this->provider()),
            'response_type' => 'code',
            'scope' => 'users.read',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function fetchProfile(string $code, string $codeVerifier): SocialOAuthProfile
    {
        $callbackUrl = SocialOAuthCallbackUrl::fromBaseUrl($this->publicBaseUrl, $this->provider());
        if (!$this->isConfigured()
            || null === $callbackUrl
            || '' === $code
            || strlen($code) > 4096
            || 1 !== preg_match('/\A[A-Za-z0-9._~+\/-]+={0,2}\z/D', $code)
            || strlen($codeVerifier) < 43
            || strlen($codeVerifier) > 128
            || 1 !== preg_match('/\A[A-Za-z0-9._~-]+\z/D', $codeVerifier)
        ) {
            throw new \InvalidArgumentException('Die Twitter-Rückgabe ist ungültig.');
        }

        $tokens = $this->jsonClient->requestObject('POST', self::TOKEN_ENDPOINT, [
            'auth_basic' => [$this->clientId, $this->clientSecret],
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $callbackUrl,
                'code_verifier' => $codeVerifier,
            ],
        ], 'Twitter hat den Anmeldecode abgewiesen.');
        $accessToken = $tokens['access_token'] ?? null;
        $tokenType = $tokens['token_type'] ?? null;
        if (!is_string($accessToken)
            || '' === $accessToken
            || strlen($accessToken) > 4096
            || 1 === preg_match('/[\x00-\x20\x7F]/', $accessToken)
            || !is_string($tokenType)
            || 0 !== strcasecmp($tokenType, 'Bearer')
        ) {
            throw new \RuntimeException('Twitter hat keine gültige Anmeldesitzung ausgestellt.');
        }

        $response = $this->jsonClient->requestObject('GET', self::USERINFO_ENDPOINT, [
            'headers' => [
                'Accept' => 'application/json',
                'Accept-Encoding' => 'identity',
                'Authorization' => 'Bearer '.$accessToken,
            ],
        ], 'Twitter konnte keine Profilidentität liefern.');
        $data = $this->jsonClient->requireObject($response, 'data');
        $subject = $data['id'] ?? null;
        $displayName = $data['name'] ?? $data['username'] ?? null;
        if (!is_string($subject)
            || '' === $subject
            || strlen($subject) > 255
            || 1 !== preg_match('//u', $subject)
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $subject)
            || !is_string($displayName)
            || 1 !== preg_match('//u', $displayName)
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $displayName)
        ) {
            throw new \RuntimeException('Twitter hat keine gültige Profilidentität geliefert.');
        }

        $displayName = trim($displayName);
        if ('' === $displayName) {
            throw new \RuntimeException('Twitter hat keinen gültigen Anzeigenamen geliefert.');
        }

        return new SocialOAuthProfile($subject, null, false, mb_substr($displayName, 0, 80, 'UTF-8'));
    }
}
