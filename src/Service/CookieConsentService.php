<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class CookieConsentService
{
    private const string CONSENT_COOKIE = 'arcanorum_cookie_consent';
    private const string CSRF_COOKIE = 'arcanorum_consent_csrf';
    private const int CONSENT_TTL = 31536000;
    private const int CSRF_TTL = 3600;

    public function __construct(
        private RequestStack $requestStack,
        private SiteSettingsProvider $settings,
        #[Autowire(param: 'kernel.secret')]
        private string $signingKey,
    ) {
    }

    public function needsChoice(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || 'cookie_consent_preferences' === $request->attributes->get('_route')) {
            return false;
        }

        return $this->settings->current()->isCookieConsentEnabled()
            && null === self::readConsent($request, $this->signingKey);
    }

    public function currentChoice(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        return null === $request ? null : self::readConsent($request, $this->signingKey);
    }

    public function csrfToken(Request $request): string
    {
        $request->attributes->set('_arcanorum_consent_csrf_active', true);
        $current = self::readCsrfToken($request, $this->signingKey);
        if (null !== $current) {
            return $current['nonce'];
        }

        $nonce = bin2hex(random_bytes(32));
        $expiresAt = time() + self::CSRF_TTL;
        $payload = 'v1.'.$nonce.'.'.$expiresAt;
        $cookieValue = $payload.'.'.self::signature('csrf', $payload, $this->signingKey);
        $request->attributes->set('_arcanorum_consent_csrf_cookie', $cookieValue);
        $request->attributes->set('_arcanorum_consent_csrf_expires_at', $expiresAt);

        return $nonce;
    }

    public function isCsrfTokenValid(Request $request, mixed $submitted): bool
    {
        if (!is_string($submitted) || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $submitted)) {
            return false;
        }

        $origin = $request->headers->get('Origin');
        if (null !== $origin && !hash_equals($request->getSchemeAndHttpHost(), $origin)) {
            return false;
        }

        if ('cross-site' === strtolower((string) $request->headers->get('Sec-Fetch-Site', ''))) {
            return false;
        }

        $cookieToken = self::readCsrfToken($request, $this->signingKey);

        return null !== $cookieToken && hash_equals($cookieToken['nonce'], $submitted);
    }

    public function createConsentCookie(Request $request, string $choice): Cookie
    {
        if (!in_array($choice, ['all', 'necessary'], true)) {
            throw new \InvalidArgumentException('Die Cookie-Einwilligung enthält eine unbekannte Auswahl.');
        }

        $expiresAt = time() + self::CONSENT_TTL;
        $payload = 'v1.'.$choice.'.'.$expiresAt;
        $value = $payload.'.'.self::signature('consent', $payload, $this->signingKey);

        return Cookie::create(self::CONSENT_COOKIE)
            ->withValue($value)
            ->withExpires(new \DateTimeImmutable('@'.$expiresAt))
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    public function expireCsrfCookie(Request $request): Cookie
    {
        return Cookie::create(self::CSRF_COOKIE)
            ->withValue('')
            ->withExpires(new \DateTimeImmutable('@1'))
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    public function csrfCookieForResponse(Request $request): ?Cookie
    {
        $value = $request->attributes->get('_arcanorum_consent_csrf_cookie');
        $expiresAt = $request->attributes->get('_arcanorum_consent_csrf_expires_at');
        if (!is_string($value) || !is_int($expiresAt)) {
            return null;
        }

        return Cookie::create(self::CSRF_COOKIE)
            ->withValue($value)
            ->withExpires(new \DateTimeImmutable('@'.$expiresAt))
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    public function csrfResponseNeedsPrivateCache(Request $request): bool
    {
        return true === $request->attributes->get('_arcanorum_consent_csrf_active');
    }

    /** @return array{nonce: string}|null */
    private static function readCsrfToken(Request $request, string $signingKey): ?array
    {
        $value = $request->cookies->get(self::CSRF_COOKIE);
        if (!is_string($value) || 1 !== preg_match('/\Av1\.([a-f0-9]{64})\.([0-9]{10})\.([a-f0-9]{64})\z/D', $value, $matches)) {
            return null;
        }

        $payload = 'v1.'.$matches[1].'.'.$matches[2];
        $expiresAt = (int) $matches[2];
        if ($expiresAt < time() || $expiresAt > time() + self::CSRF_TTL + 5
            || !hash_equals(self::signature('csrf', $payload, $signingKey), $matches[3])
        ) {
            return null;
        }

        return ['nonce' => $matches[1]];
    }

    private static function readConsent(Request $request, string $signingKey): ?string
    {
        $value = $request->cookies->get(self::CONSENT_COOKIE);
        if (!is_string($value) || 1 !== preg_match('/\Av1\.(all|necessary)\.([0-9]{10})\.([a-f0-9]{64})\z/D', $value, $matches)) {
            return null;
        }

        $payload = 'v1.'.$matches[1].'.'.$matches[2];
        $expiresAt = (int) $matches[2];
        if ($expiresAt < time() || $expiresAt > time() + self::CONSENT_TTL + 5
            || !hash_equals(self::signature('consent', $payload, $signingKey), $matches[3])
        ) {
            return null;
        }

        return $matches[1];
    }

    private static function signature(string $purpose, string $payload, string $signingKey): string
    {
        return hash_hmac('sha256', $purpose.'|'.$payload, $signingKey);
    }
}
