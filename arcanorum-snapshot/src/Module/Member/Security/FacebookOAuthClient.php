<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

final readonly class FacebookOAuthClient implements SocialOAuthClientInterface
{
    private const string AUTHORIZE_ENDPOINT = 'https://www.facebook.com/v24.0/dialog/oauth';
    private const string TOKEN_ENDPOINT = 'https://graph.facebook.com/v24.0/oauth/access_token';
    private const string USERINFO_ENDPOINT = 'https://graph.facebook.com/v24.0/me';

    public function __construct(
        private BoundedOAuthJsonClient $jsonClient,
        private string $clientId,
        private string $clientSecret,
        private string $publicBaseUrl,
    ) {
    }

    public function provider(): string
    {
        return 'facebook';
    }

    public function isConfigured(): bool
    {
        return 1 === preg_match('/\A[0-9]{5,32}\z/D', $this->clientId)
            && 1 === preg_match('/\A[^\x00-\x20\x7F]{16,512}\z/D', $this->clientSecret)
            && null !== SocialOAuthCallbackUrl::fromBaseUrl($this->publicBaseUrl, $this->provider());
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        if (!$this->isConfigured()
            || 1 !== preg_match('/\A[A-Za-z0-9_-]{43,128}\z/D', $state)
            || 1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $codeChallenge)
        ) {
            throw new \LogicException('Die Facebook-Anmeldung ist nicht sicher konfiguriert.');
        }

        return self::AUTHORIZE_ENDPOINT.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => SocialOAuthCallbackUrl::fromBaseUrl($this->publicBaseUrl, $this->provider()),
            'response_type' => 'code',
            'scope' => 'email,public_profile',
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
            throw new \InvalidArgumentException('Die Facebook-Rückgabe ist ungültig.');
        }

        $tokens = $this->jsonClient->requestObject('POST', self::TOKEN_ENDPOINT, [
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $callbackUrl,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code_verifier' => $codeVerifier,
            ],
        ], 'Facebook hat den Anmeldecode abgewiesen.');
        $accessToken = $tokens['access_token'] ?? null;
        if (!is_string($accessToken)
            || '' === $accessToken
            || strlen($accessToken) > 4096
            || 1 === preg_match('/[\x00-\x20\x7F]/', $accessToken)
            || (isset($tokens['token_type']) && (!is_string($tokens['token_type']) || 0 !== strcasecmp($tokens['token_type'], 'Bearer')))
        ) {
            throw new \RuntimeException('Facebook hat keine gültige Anmeldesitzung ausgestellt.');
        }

        $profile = $this->jsonClient->requestObject('GET', self::USERINFO_ENDPOINT.'?fields=id,name,email', [
            'headers' => [
                'Accept' => 'application/json',
                'Accept-Encoding' => 'identity',
                'Authorization' => 'Bearer '.$accessToken,
            ],
        ], 'Facebook konnte keine Profilidentität liefern.');
        $subject = $profile['id'] ?? null;
        $email = $profile['email'] ?? null;
        $displayName = $profile['name'] ?? 'Facebook-Mitglied';
        if (!is_string($subject)
            || 1 !== preg_match('/\A[0-9]{1,255}\z/D', $subject)
            || !is_string($displayName)
            || 1 !== preg_match('//u', $displayName)
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $displayName)
            || (null !== $email && (!is_string($email) || false === filter_var($email, FILTER_VALIDATE_EMAIL)))
        ) {
            throw new \RuntimeException('Facebook hat keine gültige Profilidentität geliefert.');
        }

        $displayName = trim($displayName);
        if ('' === $displayName) {
            $displayName = 'Facebook-Mitglied';
        }

        return new SocialOAuthProfile(
            $subject,
            is_string($email) ? mb_strtolower($email, 'UTF-8') : null,
            false,
            mb_substr($displayName, 0, 80, 'UTF-8'),
        );
    }
}
