<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

use App\Platform\Security\StrictJsonObjectDecoder;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class GoogleOAuthClient
{
    private const string AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const string TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const string USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const string CALLBACK_PATH = '/konto/sozial/google/callback';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $clientId,
        private string $clientSecret,
        private string $publicBaseUrl,
    ) {
    }

    public function isConfigured(): bool
    {
        return 1 === preg_match('/\A[A-Za-z0-9._-]{1,255}\z/D', $this->clientId)
            && 1 === preg_match('/\A[^\x00-\x20\x7F]{16,512}\z/D', $this->clientSecret)
            && null !== $this->callbackUrl();
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        if (!$this->isConfigured()
            || 1 !== preg_match('/\A[A-Za-z0-9_-]{43,128}\z/D', $state)
            || 1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $codeChallenge)
        ) {
            throw new \LogicException('Die Google-Anmeldung ist nicht sicher konfiguriert.');
        }

        return self::AUTHORIZE_ENDPOINT.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->callbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'access_type' => 'online',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function fetchVerifiedProfile(string $code, string $codeVerifier): GoogleOAuthProfile
    {
        $callbackUrl = $this->callbackUrl();
        if (!$this->isConfigured()
            || null === $callbackUrl
            || '' === $code
            || strlen($code) > 4096
            || 1 !== preg_match('/\A[A-Za-z0-9._~+\/-]+={0,2}\z/D', $code)
            || strlen($codeVerifier) < 43
            || strlen($codeVerifier) > 128
            || 1 !== preg_match('/\A[A-Za-z0-9._~-]+\z/D', $codeVerifier)
        ) {
            throw new \InvalidArgumentException('Die Google-Rückgabe ist ungültig.');
        }

        $tokenResponse = $this->httpClient->request('POST', self::TOKEN_ENDPOINT, [
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 8,
            'headers' => ['Accept' => 'application/json', 'Accept-Encoding' => 'identity'],
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $callbackUrl,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code_verifier' => $codeVerifier,
            ],
        ]);
        $tokens = $this->readJsonResponse($tokenResponse, 'Google hat den Anmeldecode abgewiesen.');
        $accessToken = $tokens['access_token'] ?? null;
        $tokenType = $tokens['token_type'] ?? null;
        if (!is_string($accessToken)
            || '' === $accessToken
            || strlen($accessToken) > 4096
            || 1 === preg_match('/[\x00-\x20\x7F]/', $accessToken)
            || !is_string($tokenType)
            || 0 !== strcasecmp($tokenType, 'Bearer')
        ) {
            throw new \RuntimeException('Google hat keine gültige Anmeldesitzung ausgestellt.');
        }

        $profileResponse = $this->httpClient->request('GET', self::USERINFO_ENDPOINT, [
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 8,
            'headers' => [
                'Accept' => 'application/json',
                'Accept-Encoding' => 'identity',
                'Authorization' => 'Bearer '.$accessToken,
            ],
        ]);
        $profile = $this->readJsonResponse($profileResponse, 'Google konnte die bestätigte Profilidentität nicht liefern.');
        $subject = $profile['sub'] ?? null;
        $email = $profile['email'] ?? null;
        $verified = $profile['email_verified'] ?? null;
        $displayName = $profile['name'] ?? '';
        if (!is_string($subject)
            || '' === $subject
            || strlen($subject) > 255
            || 1 !== preg_match('//u', $subject)
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $subject)
            || !is_string($email)
            || 1 !== preg_match('//u', $email)
            || mb_strlen($email, 'UTF-8') > 254
            || false === filter_var($email, FILTER_VALIDATE_EMAIL)
            || true !== $verified
            || !is_string($displayName)
        ) {
            throw new \RuntimeException('Google hat keine ausreichend bestätigte Profilidentität geliefert.');
        }

        $displayName = trim($displayName);
        if (1 !== preg_match('//u', $displayName)
            || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $displayName)
        ) {
            throw new \RuntimeException('Google hat keinen gültigen Anzeigenamen geliefert.');
        }
        if ('' === $displayName) {
            $displayName = 'Google-Mitglied';
        }
        if (mb_strlen($displayName, 'UTF-8') > 80) {
            $displayName = mb_substr($displayName, 0, 80, 'UTF-8');
        }

        return new GoogleOAuthProfile($subject, mb_strtolower($email, 'UTF-8'), $displayName);
    }

    /** @return array<string, mixed> */
    private function readJsonResponse(ResponseInterface $response, string $failureMessage): array
    {
        if (200 !== $response->getStatusCode()) {
            $response->cancel();
            throw new \RuntimeException($failureMessage);
        }

        $headers = $response->getHeaders(false);
        $contentTypes = $headers['content-type'] ?? [];
        $contentEncodings = $headers['content-encoding'] ?? [];
        $contentLengths = $headers['content-length'] ?? [];
        if (1 !== count($contentTypes)
            || 'application/json' !== strtolower(trim(explode(';', $contentTypes[0], 2)[0]))
            || (count($contentEncodings) > 1)
            || ([] !== $contentEncodings && !in_array(strtolower(trim($contentEncodings[0])), ['', 'identity'], true))
            || count($contentLengths) > 1
            || ([] !== $contentLengths && (1 !== preg_match('/\A[0-9]{1,6}\z/D', $contentLengths[0]) || (int) $contentLengths[0] > 16384))
        ) {
            $response->cancel();
            throw new \RuntimeException('Google hat eine unerwartete Antwort geliefert.');
        }

        $body = '';
        foreach ($this->httpClient->stream($response, 5) as $chunk) {
            if ($chunk->isTimeout()) {
                $response->cancel();
                throw new \RuntimeException('Google hat nicht rechtzeitig geantwortet.');
            }

            $body .= $chunk->getContent();
            if (strlen($body) > 16384) {
                $response->cancel();
                throw new \RuntimeException('Google hat eine zu große Antwort geliefert.');
            }
        }

        if (null === StrictJsonObjectDecoder::decode($body, 16)) {
            throw new \RuntimeException('Google hat ungültige oder mehrdeutige JSON-Daten geliefert.');
        }

        try {
            $decoded = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Google hat keine gültige JSON-Antwort geliefert.', 0, $exception);
        }
        if (!$decoded instanceof \stdClass) {
            throw new \RuntimeException('Google hat kein gültiges Profil geliefert.');
        }

        /** @var array<string, mixed> $properties */
        $properties = get_object_vars($decoded);

        return $properties;
    }

    private function callbackUrl(): ?string
    {
        $url = trim($this->publicBaseUrl);
        $parts = parse_url($url);
        if (!is_array($parts)
            || 'https' !== strtolower($parts['scheme'] ?? '')
            || !isset($parts['host'])
            || '' === $parts['host']
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (1 !== preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host)) {
            return null;
        }

        $port = isset($parts['port']) && 443 !== $parts['port'] ? ':'.$parts['port'] : '';

        return 'https://'.$host.$port.self::CALLBACK_PATH;
    }
}
