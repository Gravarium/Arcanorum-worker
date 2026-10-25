<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SiteSettings;
use App\Entity\VideoPlayerEngine;
use App\Entity\VideoPreloadMode;
use PHPUnit\Framework\TestCase;

final class SiteSettingsTest extends TestCase
{
    public function testDefaultsUseGermanAndExposeOnlyInstalledLocalesAndValidTimezone(): void
    {
        $settings = new SiteSettings();

        self::assertSame(SiteSettings::DEFAULT_SITE_NAME, $settings->getSiteName());
        self::assertSame('', $settings->getTagline());
        self::assertSame('de', $settings->getDefaultLocale());
        self::assertSame(['de' => 'Deutsch', 'en' => 'Englisch'], SiteSettings::SUPPORTED_LOCALES);
        self::assertSame('Europe/Berlin', $settings->getTimezone());
        self::assertSame('', $settings->getSeoDescription());
        self::assertTrue($settings->isSearchIndexingEnabled());
        self::assertFalse($settings->isMaintenanceModeEnabled());
        self::assertTrue($settings->isCookieConsentEnabled());
        self::assertFalse($settings->isPlayerAutoplayEnabled());
        self::assertSame(VideoPlayerEngine::Native, $settings->getVideoPlayerEngine());
        self::assertSame(VideoPreloadMode::Metadata, $settings->getVideoPreloadMode());
        self::assertTrue($settings->isPlayerAutoplayMuted());
        self::assertTrue($settings->isDefaultCommentsEnabled());
        self::assertTrue($settings->isPublicVideoSubmissionsEnabled());
        self::assertTrue($settings->isMemberRegistrationEnabled());
        self::assertFalse($settings->isGoogleSocialLoginEnabled());
        self::assertSame('', $settings->getEmailFromAddress());
        self::assertTrue(SiteSettings::isSupportedTimezone('UTC'));
        self::assertFalse(SiteSettings::isSupportedTimezone('../UTC'));
    }

    public function testUpdatesPlainTextAndValidTimezoneAtomically(): void
    {
        $settings = new SiteSettings();

        $errors = $settings->updateFromInput([
            'site_name' => '  Harmonia   Video  ',
            'tagline' => '  News   und   Videos ',
            'locale' => 'de',
            'timezone' => 'America/Los_Angeles',
        ]);

        self::assertSame([], $errors);
        self::assertSame('Harmonia Video', $settings->getSiteName());
        self::assertSame('News und Videos', $settings->getTagline());
        self::assertSame('America/Los_Angeles', $settings->getTimezone());
    }

    public function testEnglishIsAnInstalledAndSelectableLocale(): void
    {
        $settings = new SiteSettings();

        self::assertSame([], $settings->updateFromInput([
            'site_name' => 'Arcanorum',
            'tagline' => '',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]));
        self::assertSame('en', $settings->getDefaultLocale());
    }

    public function testInvalidTextLocaleAndTimezoneLeaveEverySettingUnchanged(): void
    {
        $settings = new SiteSettings();

        $errors = $settings->updateFromInput([
            'site_name' => "Invalid\nName",
            'tagline' => str_repeat('x', 161),
            'locale' => 'fr',
            'timezone' => '../../etc/passwd',
        ]);

        self::assertArrayHasKey('site_name', $errors);
        self::assertArrayHasKey('tagline', $errors);
        self::assertArrayHasKey('locale', $errors);
        self::assertArrayHasKey('timezone', $errors);
        self::assertSame(SiteSettings::DEFAULT_SITE_NAME, $settings->getSiteName());
        self::assertSame('', $settings->getTagline());
        self::assertSame('de', $settings->getDefaultLocale());
        self::assertSame('Europe/Berlin', $settings->getTimezone());
    }

    public function testMarkupIsStoredAsTextAndMustBeEscapedByTheView(): void
    {
        $settings = new SiteSettings();

        self::assertSame([], $settings->updateFromInput([
            'site_name' => '<script>alert(1)</script>',
            'tagline' => 'Community <em>News</em>',
            'locale' => 'de',
            'timezone' => 'UTC',
        ]));
        self::assertSame('<script>alert(1)</script>', $settings->getSiteName());
        self::assertSame('Community <em>News</em>', $settings->getTagline());
    }

    public function testSeoMaintenanceAndCookieValuesAreValidatedAndAppliedAtomically(): void
    {
        $settings = new SiteSettings();
        self::assertSame([], $settings->updateFromInput([
            'site_name' => 'Arcanorum',
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'UTC',
            'seo_description' => 'News und Videos für alle',
            'search_indexing_enabled' => '0',
            'maintenance_mode_enabled' => '1',
            'maintenance_message' => 'Wir aktualisieren die Website.',
            'cookie_consent_enabled' => '0',
            'cookie_consent_message' => 'Notwendige Cookies bleiben aktiv.',
        ]));

        self::assertSame('News und Videos für alle', $settings->getSeoDescription());
        self::assertFalse($settings->isSearchIndexingEnabled());
        self::assertTrue($settings->isMaintenanceModeEnabled());
        self::assertSame('Wir aktualisieren die Website.', $settings->getMaintenanceMessage());
        self::assertFalse($settings->isCookieConsentEnabled());
        self::assertSame('Notwendige Cookies bleiben aktiv.', $settings->getCookieConsentMessage());

        $errors = $settings->updateFromInput([
            'site_name' => 'Arcanorum',
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'UTC',
            'seo_description' => str_repeat('x', 321),
            'search_indexing_enabled' => 'yes',
            'maintenance_mode_enabled' => [],
            'maintenance_message' => "Invalid\nmessage",
            'cookie_consent_enabled' => 'perhaps',
            'cookie_consent_message' => '',
        ]);

        self::assertArrayHasKey('seo_description', $errors);
        self::assertArrayHasKey('search_indexing_enabled', $errors);
        self::assertArrayHasKey('maintenance_mode_enabled', $errors);
        self::assertArrayHasKey('maintenance_message', $errors);
        self::assertArrayHasKey('cookie_consent_enabled', $errors);
        self::assertArrayHasKey('cookie_consent_message', $errors);
        self::assertSame('News und Videos für alle', $settings->getSeoDescription());
        self::assertFalse($settings->isSearchIndexingEnabled());
        self::assertTrue($settings->isMaintenanceModeEnabled());
        self::assertSame('Notwendige Cookies bleiben aktiv.', $settings->getCookieConsentMessage());
    }

    public function testContentOptionsRequireMutedAutoplayAndRejectUnknownValuesAtomically(): void
    {
        $settings = new SiteSettings();
        self::assertSame([], $settings->updateContentOptions([
            'player_autoplay_enabled' => '1',
            'player_autoplay_muted' => '1',
            'default_comments_enabled' => '0',
            'public_video_submissions_enabled' => '0',
        ]));
        self::assertTrue($settings->isPlayerAutoplayEnabled());
        self::assertTrue($settings->isPlayerAutoplayMuted());
        self::assertFalse($settings->isDefaultCommentsEnabled());
        self::assertFalse($settings->isPublicVideoSubmissionsEnabled());

        $errors = $settings->updateContentOptions([
            'player_autoplay_enabled' => '1',
            'player_autoplay_muted' => '0',
            'default_comments_enabled' => '1',
            'public_video_submissions_enabled' => '1',
        ]);
        self::assertArrayHasKey('player_autoplay_muted', $errors);
        self::assertTrue($settings->isPlayerAutoplayEnabled());
        self::assertFalse($settings->isDefaultCommentsEnabled());
        self::assertFalse($settings->isPublicVideoSubmissionsEnabled());

        $errors = $settings->updateContentOptions([
            'player_autoplay_enabled' => '0',
            'player_autoplay_muted' => '1',
            'default_comments_enabled' => '1',
            'public_video_submissions_enabled' => '1',
            'player_controls_enabled' => '0',
        ]);
        self::assertArrayHasKey('form', $errors);
        self::assertTrue($settings->isPlayerAutoplayEnabled());
        self::assertFalse($settings->isDefaultCommentsEnabled());
    }

    public function testVideoPlayerSelectionUsesOnlySupportedEnginesAndRejectsInvalidInputAtomically(): void
    {
        $settings = new SiteSettings();

        self::assertSame([], $settings->updateContentOptions(['video_player_engine' => VideoPlayerEngine::VideoJs->value]));
        self::assertSame(VideoPlayerEngine::VideoJs, $settings->getVideoPlayerEngine());

        $errors = $settings->updateContentOptions(['video_player_engine' => 'jwplayer8']);
        self::assertArrayHasKey('video_player_engine', $errors);
        self::assertSame(VideoPlayerEngine::VideoJs, $settings->getVideoPlayerEngine());

        $errors = $settings->updateContentOptions(['video_player_engine' => ['videojs']]);
        self::assertArrayHasKey('video_player_engine', $errors);
        self::assertSame(VideoPlayerEngine::VideoJs, $settings->getVideoPlayerEngine());
    }

    public function testVideoPreloadSelectionUsesOnlySupportedModesAndRejectsInvalidInputAtomically(): void
    {
        $settings = new SiteSettings();

        self::assertSame([], $settings->updateContentOptions(['video_preload_mode' => VideoPreloadMode::Auto->value]));
        self::assertSame(VideoPreloadMode::Auto, $settings->getVideoPreloadMode());

        $errors = $settings->updateContentOptions(['video_preload_mode' => 'eager']);
        self::assertArrayHasKey('video_preload_mode', $errors);
        self::assertSame(VideoPreloadMode::Auto, $settings->getVideoPreloadMode());

        $errors = $settings->updateContentOptions(['video_preload_mode' => ['auto']]);
        self::assertArrayHasKey('video_preload_mode', $errors);
        self::assertSame(VideoPreloadMode::Auto, $settings->getVideoPreloadMode());
    }

    public function testEmailSettingsNormalizeAddressAndRejectInvalidOrUnknownInputAtomically(): void
    {
        $settings = new SiteSettings();
        self::assertSame([], $settings->updateEmailSettings(['from_address' => '  mail@example.test  ']));
        self::assertSame('mail@example.test', $settings->getEmailFromAddress());

        $errors = $settings->updateEmailSettings(['from_address' => "mail@example.test\r\nBcc:attacker@example.test"]);
        self::assertArrayHasKey('from_address', $errors);
        self::assertSame('mail@example.test', $settings->getEmailFromAddress());

        $errors = $settings->updateEmailSettings([
            'from_address' => 'other@example.test',
            'mailer_dsn' => 'smtp://mail.example.test',
        ]);
        self::assertArrayHasKey('form', $errors);
        self::assertSame('mail@example.test', $settings->getEmailFromAddress());

        self::assertSame([], $settings->updateEmailSettings(['from_address' => '']));
        self::assertSame('', $settings->getEmailFromAddress());
    }

    public function testMemberRegistrationSettingValidatesAndUpdatesAtomically(): void
    {
        $settings = new SiteSettings();
        self::assertTrue($settings->isMemberRegistrationEnabled());
        self::assertSame([], $settings->updateFromInput([
            'site_name' => SiteSettings::DEFAULT_SITE_NAME,
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
            'member_registration_enabled' => '0',
            'social_google_enabled' => '1',
        ]));
        self::assertFalse($settings->isMemberRegistrationEnabled());
        self::assertTrue($settings->isGoogleSocialLoginEnabled());

        $errors = $settings->updateFromInput([
            'site_name' => SiteSettings::DEFAULT_SITE_NAME,
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
            'member_registration_enabled' => 'yes',
        ]);
        self::assertArrayHasKey('member_registration_enabled', $errors);
        self::assertFalse($settings->isMemberRegistrationEnabled());

        $errors = $settings->updateFromInput([
            'site_name' => SiteSettings::DEFAULT_SITE_NAME,
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
            'social_google_enabled' => 'yes',
        ]);
        self::assertArrayHasKey('social_google_enabled', $errors);
        self::assertTrue($settings->isGoogleSocialLoginEnabled());
    }
}
