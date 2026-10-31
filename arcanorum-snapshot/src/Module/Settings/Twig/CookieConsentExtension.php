<?php

declare(strict_types=1);

namespace App\Module\Settings\Twig;

use App\Service\CookieConsentService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CookieConsentExtension extends AbstractExtension
{
    public function __construct(
        private readonly CookieConsentService $consent,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cookie_consent_needs_choice', $this->consent->needsChoice(...)),
            new TwigFunction('cookie_consent_allows_optional', $this->consent->allowsOptionalServices(...)),
            new TwigFunction('cookie_consent_csrf_token', $this->csrfToken(...)),
            new TwigFunction('cookie_consent_return_to', $this->returnTarget(...)),
        ];
    }

    public function returnTarget(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return '';
        }

        $route = $request->attributes->get('_route');
        $routeParameters = $request->attributes->get('_route_params', []);
        if (!is_string($route) || !is_array($routeParameters)) {
            return '';
        }

        $seasonNumber = self::safePosition($routeParameters['seasonNumber'] ?? null);
        $episodeNumber = self::safePosition($routeParameters['episodeNumber'] ?? null);
        $parameters = match ($route) {
            'platform_home', 'news_index', 'video_index', 'video_popular', 'public_member_index', 'public_video_submission_new',
            'page_index', 'series_index', 'public_search', 'contact_new',
            'member_register', 'member_setup', 'member_login', 'member_password', 'member_profile',
            'member_activity_index', 'member_follow_index', 'member_playlist_index', 'member_video_library_index',
            'member_submission_history', 'member_email_request', 'member_password_reset_request' => [],
            'news_show', 'page_show', 'series_show' => self::isSafeSlug($routeParameters['slug'] ?? null) ? ['slug' => $routeParameters['slug']] : null,
            'series_episode_show' => self::isSafeSlug($routeParameters['slug'] ?? null)
                && null !== $seasonNumber
                && null !== $episodeNumber
                    ? [
                        'slug' => $routeParameters['slug'],
                        'seasonNumber' => $seasonNumber,
                        'episodeNumber' => $episodeNumber,
                    ]
                    : null,
            'video_show', 'public_playlist_show' => self::isSafeIdentifier($routeParameters['id'] ?? null) ? ['id' => $routeParameters['id']] : null,
            'public_member_profile' => self::isSafeMemberHandle($routeParameters['handle'] ?? null) ? ['handle' => $routeParameters['handle']] : null,
            default => null,
        };
        if (null === $parameters) {
            return '';
        }

        try {
            return $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
        } catch (\InvalidArgumentException) {
            return '';
        }
    }

    public function csrfToken(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            throw new \LogicException('Für Cookie-Einwilligungstoken muss ein HTTP-Request aktiv sein.');
        }

        return $this->consent->csrfToken($request);
    }

    private static function isSafeSlug(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $value);
    }

    private static function isSafeIdentifier(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\A[a-f0-9]{32}\z/D', $value);
    }

    private static function isSafeMemberHandle(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\A[a-z0-9][a-z0-9_-]{2,31}\z/D', $value);
    }

    private static function safePosition(mixed $value): ?string
    {
        if (!(is_string($value) || is_int($value)) || 1 !== preg_match('/\A[1-9][0-9]{0,2}\z/D', (string) $value)) {
            return null;
        }

        return (string) $value;
    }
}
