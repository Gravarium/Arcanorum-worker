<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SiteSettings;
use App\Repository\SiteSettingsRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class SiteSettingsProvider
{
    private const string REQUEST_ATTRIBUTE = '_arcanorum_site_settings';

    public function __construct(
        private SiteSettingsRepository $settings,
        private RequestStack $requestStack,
    ) {
    }

    public function current(): SiteSettings
    {
        $request = $this->requestStack->getCurrentRequest();
        $cached = $request?->attributes->get(self::REQUEST_ATTRIBUTE);
        if ($cached instanceof SiteSettings) {
            return $cached;
        }

        $settings = $this->settings->findCurrent() ?? new SiteSettings();
        $request?->attributes->set(self::REQUEST_ATTRIBUTE, $settings);

        return $settings;
    }
}
