<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SiteLayout;
use App\Entity\SiteSettings;
use App\Entity\SiteTheme;
use PHPUnit\Framework\TestCase;

final class SiteSettingsAppearanceTest extends TestCase
{
    public function testAppearanceDefaultsAndNormalizesSupportedValues(): void
    {
        $settings = new SiteSettings();

        self::assertSame(SiteTheme::Arcanorum, $settings->getPublicTheme());
        self::assertSame(SiteLayout::Grid, $settings->getPublicLayout());
        self::assertSame(SiteSettings::DEFAULT_PRIMARY_COLOR, $settings->getPrimaryColor());
        self::assertSame(SiteSettings::DEFAULT_ACCENT_COLOR, $settings->getAccentColor());
        self::assertSame(SiteSettings::DEFAULT_CONTENT_BACKGROUND_COLOR, $settings->getContentBackgroundColor());
        self::assertSame(SiteSettings::DEFAULT_CONTENT_TEXT_COLOR, $settings->getContentTextColor());
        self::assertSame(SiteSettings::DEFAULT_CONTENT_WIDTH, $settings->getContentWidth());

        self::assertSame([], $settings->updateAppearance([
            'theme' => SiteTheme::Editorial->value,
            'layout' => SiteLayout::Magazine->value,
            'primary_color' => '#17345A',
            'accent_color' => '#28665E',
        ]));

        self::assertSame(SiteTheme::Editorial, $settings->getPublicTheme());
        self::assertSame(SiteLayout::Magazine, $settings->getPublicLayout());
        self::assertSame('#17345A', $settings->getPrimaryColor());
        self::assertSame('#28665E', $settings->getAccentColor());
    }

    public function testPublicThemeColorsAndWidthAreValidatedAndPersisted(): void
    {
        $settings = new SiteSettings();
        self::assertSame([], $settings->updateAppearance([
            'theme' => SiteTheme::Editorial->value,
            'layout' => SiteLayout::Magazine->value,
            'primary_color' => '#17345A',
            'accent_color' => '#28665E',
            'heading_color' => '#1A2A3A',
            'content_background_color' => '#FFFDF8',
            'content_text_color' => '#182033',
            'body_background_color' => '#F3F5F9',
            'header_background_color' => '#152238',
            'header_pane_color' => '#F0F4FA',
            'navigation_background_color' => '#FFFFFF',
            'navigation_link_color' => '#24365E',
            'content_link_color' => '#17345A',
            'video_link_color' => '#182033',
            'site_title_color' => '#FFFFFF',
            'footer_background_color' => '#E8EDF5',
            'footer_link_color' => '#33445B',
            'content_width' => '1440',
        ]));

        self::assertSame('#1A2A3A', $settings->getHeadingColor());
        self::assertSame('#FFFDF8', $settings->getContentBackgroundColor());
        self::assertSame('#F0F4FA', $settings->getHeaderPaneColor());
        self::assertSame('#152238', $settings->getHeaderBackgroundColor());
        self::assertSame('#FFFFFF', $settings->getSiteTitleColor());
        self::assertSame('#E8EDF5', $settings->getFooterBackgroundColor());
        self::assertSame('#33445B', $settings->getFooterLinkColor());
        self::assertSame(1440, $settings->getContentWidth());
    }

    public function testInaccessibleColorPairsAndUnsafeContentWidthsAreRejectedAtomically(): void
    {
        $settings = new SiteSettings();
        $errors = $settings->updateAppearance([
            'theme' => SiteTheme::Arcade->value,
            'layout' => SiteLayout::Compact->value,
            'primary_color' => '#17345A',
            'accent_color' => '#28665E',
            'content_background_color' => '#111111',
            'content_text_color' => '#182033',
            'content_link_color' => 'url(https://example.test/track.css)',
            'content_width' => '1921',
        ]);

        self::assertArrayHasKey('content_text_color', $errors);
        self::assertArrayHasKey('content_link_color', $errors);
        self::assertArrayHasKey('content_width', $errors);
        self::assertSame(SiteTheme::Arcanorum, $settings->getPublicTheme());
        self::assertSame(SiteSettings::DEFAULT_CONTENT_BACKGROUND_COLOR, $settings->getContentBackgroundColor());
        self::assertSame(SiteSettings::DEFAULT_CONTENT_WIDTH, $settings->getContentWidth());
    }

    public function testInvalidAppearanceInputIsRejectedAtomically(): void
    {
        $settings = new SiteSettings();
        self::assertSame([], $settings->updateAppearance([
            'theme' => SiteTheme::Arcade->value,
            'layout' => SiteLayout::Compact->value,
            'primary_color' => '#17345A',
            'accent_color' => '#28665E',
        ]));

        $errors = $settings->updateAppearance([
            'theme' => 'external-css',
            'layout' => 'javascript',
            'primary_color' => 'url(https://example.test/track.css)',
            'accent_color' => '#FFFFFF',
        ]);

        self::assertArrayHasKey('theme', $errors);
        self::assertArrayHasKey('layout', $errors);
        self::assertArrayHasKey('primary_color', $errors);
        self::assertArrayHasKey('accent_color', $errors);
        self::assertSame(SiteTheme::Arcade, $settings->getPublicTheme());
        self::assertSame(SiteLayout::Compact, $settings->getPublicLayout());
        self::assertSame('#17345A', $settings->getPrimaryColor());
        self::assertSame('#28665E', $settings->getAccentColor());

        self::assertArrayHasKey('form', $settings->updateAppearance([
            'theme' => SiteTheme::Arcanorum->value,
            'layout' => SiteLayout::Grid->value,
            'primary_color' => '#17345A',
            'accent_color' => '#28665E',
            'stylesheet' => 'https://example.test/theme.css',
        ]));
        self::assertSame(SiteTheme::Arcade, $settings->getPublicTheme());
    }

    public function testCorruptStoredAppearanceFallsBackToSafeBuiltInValues(): void
    {
        $settings = new SiteSettings();
        foreach ([
            'publicTheme' => 'unknown',
            'publicLayout' => '../layout',
            'primaryColor' => 'url(javascript:alert(1))',
            'accentColor' => '#EEEEEE',
            'contentBackgroundColor' => 'url(javascript:alert(1))',
            'contentWidth' => 2500,
        ] as $field => $value) {
            $property = new \ReflectionProperty(SiteSettings::class, $field);
            $property->setValue($settings, $value);
        }

        self::assertSame(SiteTheme::Arcanorum, $settings->getPublicTheme());
        self::assertSame(SiteLayout::Grid, $settings->getPublicLayout());
        self::assertSame(SiteSettings::DEFAULT_PRIMARY_COLOR, $settings->getPrimaryColor());
        self::assertSame(SiteSettings::DEFAULT_ACCENT_COLOR, $settings->getAccentColor());
        self::assertSame(SiteSettings::DEFAULT_CONTENT_BACKGROUND_COLOR, $settings->getContentBackgroundColor());
        self::assertSame(SiteSettings::DEFAULT_CONTENT_WIDTH, $settings->getContentWidth());
    }

    public function testSiteLogoMetadataIsBoundedAndCanBeRemovedAtomically(): void
    {
        $settings = new SiteSettings();
        $objectId = str_repeat('a', 64);
        $digest = str_repeat('b', 64);
        $settings->attachSiteLogo($objectId, 'image/png', 512, $digest);

        self::assertSame($objectId, $settings->getSiteLogoObjectId());
        self::assertSame('image/png', $settings->getSiteLogoMimeType());
        self::assertSame(512, $settings->getSiteLogoSize());
        self::assertSame($digest, $settings->getSiteLogoSha256());
        self::assertSame($objectId, $settings->removeSiteLogo());
        self::assertNull($settings->getSiteLogoObjectId());
        self::assertNull($settings->getSiteLogoMimeType());
        self::assertNull($settings->getSiteLogoSize());
        self::assertNull($settings->getSiteLogoSha256());

        try {
            $settings->attachSiteLogo('../public/logo.png', 'image/svg+xml', 0, 'invalid');
            self::fail('Unsafe or incomplete logo metadata must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertNull($settings->getSiteLogoObjectId());
            self::assertNull($settings->getSiteLogoMimeType());
            self::assertNull($settings->getSiteLogoSize());
            self::assertNull($settings->getSiteLogoSha256());
        }
    }
}
