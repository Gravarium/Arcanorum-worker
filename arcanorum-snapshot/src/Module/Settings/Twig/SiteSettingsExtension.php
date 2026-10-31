<?php

declare(strict_types=1);

namespace App\Module\Settings\Twig;

use App\Service\LocaleTextDirection;
use App\Service\SiteSettingsProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SiteSettingsExtension extends AbstractExtension
{
    public function __construct(private readonly SiteSettingsProvider $settings)
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_settings', $this->settings->current(...)),
            new TwigFunction('locale_text_direction', LocaleTextDirection::forLocale(...)),
        ];
    }
}
