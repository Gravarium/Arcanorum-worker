<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SiteSettings;

final readonly class SiteThemeStylesheetFactory
{
    public function createPublicStylesheet(SiteSettings $settings): string
    {
        return $this->declarations(
            'html[data-public-theme="'.$settings->getPublicTheme()->value.'"]',
            $settings,
        );
    }

    public function createPreviewStylesheet(SiteSettings $settings): string
    {
        return $this->declarations(
            '.theme-preview[data-preview-theme="'.$settings->getPublicTheme()->value.'"]',
            $settings,
        );
    }

    private function declarations(string $selector, SiteSettings $settings): string
    {
        return sprintf(
            "%s { --site-primary: %s; --site-accent: %s; }\n",
            $selector,
            $settings->getPrimaryColor(),
            $settings->getAccentColor(),
        );
    }
}
