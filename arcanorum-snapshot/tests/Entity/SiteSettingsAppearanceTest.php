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
        ] as $field => $value) {
            $property = new \ReflectionProperty(SiteSettings::class, $field);
            $property->setValue($settings, $value);
        }

        self::assertSame(SiteTheme::Arcanorum, $settings->getPublicTheme());
        self::assertSame(SiteLayout::Grid, $settings->getPublicLayout());
        self::assertSame(SiteSettings::DEFAULT_PRIMARY_COLOR, $settings->getPrimaryColor());
        self::assertSame(SiteSettings::DEFAULT_ACCENT_COLOR, $settings->getAccentColor());
    }
}
