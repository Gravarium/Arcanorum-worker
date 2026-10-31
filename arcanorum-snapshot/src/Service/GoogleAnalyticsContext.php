<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class GoogleAnalyticsContext
{
    /** @var array<string, string> */
    private const array PUBLIC_PAGE_TYPES = [
        'platform_home' => 'home',
        'public_search' => 'search',
        'video_categories' => 'video_categories',
        'video_index' => 'video_list',
        'video_category' => 'video_category',
        'video_popular' => 'popular_videos',
        'video_show' => 'video',
        'news_index' => 'news_list',
        'news_category' => 'news_category',
        'news_show' => 'news_article',
        'series_index' => 'series_list',
        'series_show' => 'series',
        'series_episode_show' => 'episode',
        'page_index' => 'pages',
        'page_show' => 'page',
    ];

    public function __construct(
        private RequestStack $requestStack,
        private CookieConsentService $consent,
        private SiteSettingsProvider $settings,
    ) {
    }

    /** @return array{measurement_id: string, page_type: string}|null */
    public function current(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$this->isConfiguredFor($request) || !$this->consent->allowsOptionalServices()) {
            return null;
        }

        $route = $request->attributes->get('_route');
        $pageType = is_string($route) ? (self::PUBLIC_PAGE_TYPES[$route] ?? null) : null;
        if (null === $pageType) {
            return null;
        }

        return [
            'measurement_id' => $this->settings->current()->getGoogleAnalyticsMeasurementId(),
            'page_type' => $pageType,
        ];
    }

    public function isConfiguredFor(Request $request): bool
    {
        if ($this->requestStack->getCurrentRequest() !== $request
            || !$request->isMethod(Request::METHOD_GET)
            || 'html' !== $request->getRequestFormat()
        ) {
            return false;
        }

        $route = $request->attributes->get('_route');
        if (!is_string($route) || !isset(self::PUBLIC_PAGE_TYPES[$route])) {
            return false;
        }

        $settings = $this->settings->current();
        $measurementId = $settings->getGoogleAnalyticsMeasurementId();

        return $settings->isCookieConsentEnabled()
            && 1 === preg_match('/\AG-[A-Z0-9]{10}\z/D', $measurementId);
    }
}
