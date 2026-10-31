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
        $declarations = [
            '--site-primary' => $settings->getPrimaryColor(),
            '--site-accent' => $settings->getAccentColor(),
            '--site-heading' => $settings->getHeadingColor(),
            '--site-content-background' => $settings->getContentBackgroundColor(),
            '--site-content-text' => $settings->getContentTextColor(),
            '--site-body-background' => $settings->getBodyBackgroundColor(),
            '--site-header-background' => $settings->getHeaderBackgroundColor(),
            '--site-header-pane' => $settings->getHeaderPaneColor(),
            '--site-navigation-background' => $settings->getNavigationBackgroundColor(),
            '--site-navigation-link' => $settings->getNavigationLinkColor(),
            '--site-content-link' => $settings->getContentLinkColor(),
            '--site-video-link' => $settings->getVideoLinkColor(),
            '--site-title' => $settings->getSiteTitleColor(),
            '--site-footer-background' => $settings->getFooterBackgroundColor(),
            '--site-footer-link' => $settings->getFooterLinkColor(),
            '--site-content-width' => $settings->getContentWidth().'px',
        ];
        $css = $selector." {\n";

        foreach ($declarations as $property => $value) {
            $css .= sprintf('  %s: %s;'."\n", $property, $value);
        }

        return $css."}\n";
    }
}
