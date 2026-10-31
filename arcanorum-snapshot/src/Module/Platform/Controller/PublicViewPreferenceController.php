<?php

declare(strict_types=1);

namespace App\Module\Platform\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicViewPreferenceController extends AbstractController
{
    private const string COOKIE_NAME = 'arcanorum_public_view';
    private const int COOKIE_TTL_SECONDS = 15_552_000;

    #[Route('/public-view-preference', name: 'public_view_preference', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!self::isSameOriginPost($request)) {
            return self::privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        if ([] !== array_diff(array_keys($request->request->all()), ['mode', 'return_to'])) {
            return self::privateResponse(new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $mode = $request->request->get('mode');
        if (!is_string($mode) || !in_array($mode, ['site', 'compact', 'desktop'], true)) {
            return self::privateResponse(new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $returnTarget = self::safeReturnTarget($request->request->get('return_to'));
        if (null === $returnTarget) {
            return self::privateResponse(new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $response = self::privateResponse(new RedirectResponse($returnTarget, Response::HTTP_SEE_OTHER));
        $response->headers->setCookie('site' === $mode
            ? self::expiredPreferenceCookie($request)
            : self::preferenceCookie($request, $mode));

        return $response;
    }

    private static function preferenceCookie(Request $request, string $mode): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue($mode)
            ->withExpires(new \DateTimeImmutable('@'.(time() + self::COOKIE_TTL_SECONDS)))
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    private static function expiredPreferenceCookie(Request $request): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue('')
            ->withExpires(new \DateTimeImmutable('@1'))
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    private static function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private static function isSameOriginPost(Request $request): bool
    {
        // This browser-local preference uses strict same-origin and Fetch Metadata checks as its CSRF boundary.
        $fetchSite = strtolower((string) $request->headers->get('Sec-Fetch-Site', ''));
        if ('' !== $fetchSite && 'same-origin' !== $fetchSite) {
            return false;
        }

        $expectedOrigin = self::normalizedOrigin($request->getSchemeAndHttpHost(), false);
        if (null === $expectedOrigin) {
            return false;
        }

        $origin = $request->headers->get('Origin');
        if (is_string($origin) && '' !== $origin) {
            $submittedOrigin = self::normalizedOrigin($origin, false);

            return null !== $submittedOrigin && hash_equals($expectedOrigin, $submittedOrigin);
        }

        if ('same-origin' === $fetchSite) {
            return true;
        }

        $referer = $request->headers->get('Referer');
        if (!is_string($referer) || '' === $referer) {
            return false;
        }

        $refererOrigin = self::normalizedOrigin($referer, true);

        return null !== $refererOrigin && hash_equals($expectedOrigin, $refererOrigin);
    }

    private static function normalizedOrigin(string $uri, bool $allowPath): ?string
    {
        try {
            $parts = parse_url($uri);
        } catch (\ValueError) {
            return null;
        }
        if (!is_array($parts)
            || !is_string($parts['scheme'] ?? null)
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (!$allowPath && (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])))
        ) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        if ('' === $host || 1 === preg_match('/[\x00-\x20\x7f\\\\]/', $host)) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if ((80 === $port && 'http' === $scheme) || (443 === $port && 'https' === $scheme)) {
            $port = null;
        }

        return $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');
    }

    private static function safeReturnTarget(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return '/';
        }
        if (!is_string($value)
            || strlen($value) > 2048
            || 1 === preg_match('/[\x00-\x20\x7f\\\\]/', $value)
            || 1 === preg_match('/%(?:2f|5c)/i', $value)
        ) {
            return null;
        }

        try {
            $parts = parse_url($value);
        } catch (\ValueError) {
            return null;
        }
        if (!is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !is_string($parts['path'] ?? null)
            || !str_starts_with($parts['path'], '/')
            || str_starts_with($parts['path'], '//')
        ) {
            return null;
        }

        $path = $parts['path'];
        $allowed = in_array($path, [
            '/',
            '/news',
            '/videos',
            '/videos/popular',
            '/mitglieder',
            '/video-submissions',
            '/pages',
            '/series',
            '/search',
            '/contact',
        ], true);
        $allowed = $allowed || array_any([
            '~\A/news/[a-z0-9]+(?:-[a-z0-9]+)*\z~D',
            '~\A/videos/[a-f0-9]{32}\z~D',
            '~\A/kanal/[a-z0-9][a-z0-9_-]{2,31}\z~D',
            '~\A/wiedergabelisten/[a-f0-9]{32}\z~D',
            '~\A/pages/[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}\z~D',
            '~\A/series/[a-z0-9]+(?:-[a-z0-9]+)*\z~D',
            '~\A/series/[a-z0-9]+(?:-[a-z0-9]+)*/season/[1-9][0-9]{0,2}/episode/[1-9][0-9]{0,2}\z~D',
        ], static fn (string $pattern): bool => 1 === preg_match($pattern, $path));

        return $allowed ? $path : null;
    }
}
