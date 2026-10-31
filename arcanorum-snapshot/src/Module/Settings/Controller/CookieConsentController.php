<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller;

use App\Service\CookieConsentService;
use App\Service\SiteSettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cookie-einstellungen', name: 'cookie_consent_')]
final class CookieConsentController extends AbstractController
{
    #[Route('', name: 'preferences', methods: ['GET'])]
    public function preferences(Request $request, CookieConsentService $consent, SiteSettingsProvider $settings): Response
    {
        return $this->privateResponse($this->render('settings/cookie_preferences.html.twig', [
            'settings' => $settings->current(),
            'choice' => $consent->currentChoice(),
            'token' => $consent->csrfToken($request),
            'return_to' => self::safeReturnTarget($request->query->get('return_to')) ?? '',
        ]));
    }

    #[Route('', name: 'submit', methods: ['POST'])]
    public function submit(Request $request, CookieConsentService $consent): Response
    {
        if (!$consent->isCsrfTokenValid($request, $request->request->get('token'))) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        if ([] !== array_diff(array_keys($request->request->all()), ['token', 'choice', 'return_to'])) {
            return $this->privateResponse(new Response('Ungültige Cookie-Auswahl.', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $choice = $request->request->get('choice');
        if (!is_string($choice) || !in_array($choice, ['all', 'necessary'], true)) {
            return $this->privateResponse(new Response('Ungültige Cookie-Auswahl.', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $submittedReturnTarget = $request->request->get('return_to');
        $returnTo = self::safeReturnTarget($submittedReturnTarget);
        if (null !== $submittedReturnTarget && '' !== $submittedReturnTarget && null === $returnTo) {
            return $this->privateResponse(new Response('Ungültiges Rücksprungziel.', Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        $response = $this->privateResponse(null === $returnTo
            ? $this->redirectToRoute('platform_home')
            : $this->redirect($returnTo));
        $response->headers->setCookie($consent->createConsentCookie($request, $choice));
        $response->headers->setCookie($consent->expireCsrfCookie($request));
        if ('necessary' === $choice) {
            foreach (array_keys($request->cookies->all()) as $cookieName) {
                if (1 !== preg_match('/\A_ga(?:_[A-Za-z0-9_]{1,64})?\z/D', $cookieName)) {
                    continue;
                }

                $response->headers->setCookie(Cookie::create($cookieName)
                    ->withValue('')
                    ->withExpires(new \DateTimeImmutable('@1'))
                    ->withPath('/')
                    ->withSecure($request->isSecure())
                    ->withHttpOnly(false)
                    ->withSameSite(Cookie::SAMESITE_LAX));
            }
        }

        return $response;
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private static function safeReturnTarget(mixed $value): ?string
    {
        if (!is_string($value)
            || '' === $value
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
        if (!self::isAllowedReturnPath($path)) {
            return null;
        }

        return $path;
    }

    private static function isAllowedReturnPath(string $path): bool
    {
        if (in_array($path, ['/', '/news', '/videos', '/videos/popular', '/mitglieder', '/video-submissions'], true)) {
            return true;
        }

        return array_any([
            '~\A/news/[a-z0-9]+(?:-[a-z0-9]+)*\z~D',
            '~\A/videos/[a-f0-9]{32}\z~D',
            '~\A/kanal/[a-z0-9][a-z0-9_-]{2,31}\z~D',
            '~\A/wiedergabelisten/[a-f0-9]{32}\z~D',
        ], fn ($pattern) => 1 === preg_match($pattern, $path));
    }
}
