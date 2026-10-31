<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CommentProvider;
use App\Entity\CommentSortOrder;
use App\Entity\SiteSettings;
use App\Entity\VideoPlayerAspectRatio;
use App\Entity\VideoPlayerColorScheme;
use App\Entity\VideoPlayerEngine;
use App\Entity\VideoPlayerMaxWidth;
use App\Entity\VideoPopularitySort;
use App\Entity\VideoPreloadMode;
use App\Entity\VideoThumbnailResolution;
use PHPUnit\Framework\TestCase;

final class SiteSettingsTest extends TestCase
{
    public function testDefaultsUseGermanAndExposeOnlyInstalledLocalesAndValidTimezone(): void
    {
        $settings = new SiteSettings();

        self::assertSame(SiteSettings::DEFAULT_SITE_NAME, $settings->getSiteName());
        self::assertSame('', $settings->getTagline());
        self::assertSame('de', $settings->getDefaultLocale());
        self::assertSame([
            'de' => 'Deutsch',
            'en' => 'Englisch',
            'ar' => 'Arabisch (RTL; Übersetzung teilweise)',
        ], SiteSettings::SUPPORTED_LOCALES);
        self::assertSame('Europe/Berlin', $settings->getTimezone());
        self::assertSame('', $settings->getSeoDescription());
        self::assertTrue($settings->isSearchIndexingEnabled());
        self::assertFalse($settings->isMaintenanceModeEnabled());
        self::assertTrue($settings->isCookieConsentEnabled());
        self::assertSame('', $settings->getGoogleAnalyticsMeasurementId());
        self::assertSame(CommentProvider::Native, $settings->getCommentProvider());
        self::assertSame('', $settings->getFacebookCommentsAppId());
        self::assertSame('', $settings->getDisqusCommentsShortname());
        self::assertFalse($settings->isPlayerAutoplayEnabled());
        self::assertSame(VideoPlayerEngine::Native, $settings->getVideoPlayerEngine());
        self::assertSame(VideoThumbnailResolution::Medium, $settings->getProviderThumbnailResolution());
        self::assertSame(VideoPlayerColorScheme::Midnight, $settings->getVideoPlayerColorScheme());
        self::assertFalse($settings->isVideoPlayerWatermarkEnabled());
        self::assertSame(VideoPreloadMode::Metadata, $settings->getVideoPreloadMode());
        self::assertTrue($settings->isPlayerAutoplayMuted());
        self::assertTrue($settings->isDefaultCommentsEnabled());
        self::assertTrue($settings->isPublicVideoSubmissionsEnabled());
        self::assertTrue($settings->isMemberRegistrationEnabled());
        self::assertFalse($settings->isGoogleSocialLoginEnabled());
        self::assertFalse($settings->isFacebookSocialLoginEnabled());
        self::assertFalse($settings->isTwitterSocialLoginEnabled());
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

    public function testArabicIsAnInstalledAndSelectableRightToLeftLocale(): void
    {
        $settings = new SiteSettings();

        self::assertSame([], $settings->updateFromInput([
            'site_name' => 'Arcanorum',
            'tagline' => '',
            'locale' => 'ar',
            'timezone' => 'UTC',
        ]));
        self::assertSame('ar', $settings->getDefaultLocale());
    }

    public function testSocialProviderSwitchesAreIndependentAndValidatedAtomically(): void
    {
        $settings = new SiteSettings();

        self::assertSame([], $settings->updateFromInput([
            'site_name' => SiteSettings::DEFAULT_SITE_NAME,
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'UTC',
            'social_google_enabled' => '1',
            'social_facebook_enabled' => '0',
            'social_twitter_enabled' => '1',
        ]));
        self::assertTrue($settings->isGoogleSocialLoginEnabled());
        self::assertFalse($settings->isFacebookSocialLoginEnabled());
        self::assertTrue($settings->isTwitterSocialLoginEnabled());

        $errors = $settings->updateFromInput([
            'site_name' => SiteSettings::DEFAULT_SITE_NAME,
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'UTC',
            'social_google_enabled' => '0',
            'social_facebook_enabled' => '1',
            'social_twitter_enabled' => 'enabled',
        ]);
        self::assertArrayHasKey('social_twitter_enabled', $errors);
        self::assertTrue($settings->isGoogleSocialLoginEnabled());
        self::assertFalse($settings->isFacebookSocialLoginEnabled());
        self::assertTrue($settings->isTwitterSocialLoginEnabled());
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

    public function testGoogleAnalyticsMeasurementIdAcceptsOnlyTheExactGa4FormatAtomically(): void
    {
        $settings = new SiteSettings();
        $baseInput = [
            'site_name' => 'Arcanorum',
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'UTC',
        ];

        self::assertSame([], $settings->updateFromInput($baseInput + [
            'google_analytics_measurement_id' => 'G-ABC1234567',
        ]));
        self::assertSame('G-ABC1234567', $settings->getGoogleAnalyticsMeasurementId());

        foreach (['G-abc1234567', 'G-ABC123456', 'G-ABC12345678', '<script>alert(1)</script>', []] as $invalid) {
            $errors = $settings->updateFromInput(array_replace($baseInput, [
                'google_analytics_measurement_id' => $invalid,
                'tagline' => 'must not be saved',
            ]));

            self::assertArrayHasKey('google_analytics_measurement_id', $errors);
            self::assertSame('G-ABC1234567', $settings->getGoogleAnalyticsMeasurementId());
            self::assertSame('', $settings->getTagline());
        }

        self::assertSame([], $settings->updateFromInput($baseInput + ['google_analytics_measurement_id' => '']));
        self::assertSame('', $settings->getGoogleAnalyticsMeasurementId());
    }

    public function testContentOptionsRequireMutedAutoplayAndRejectUnknownValuesAtomically(): void
    {
        $settings = new SiteSettings();
        self::assertTrue($settings->isGuestCommentsEnabled());
        self::assertSame([], $settings->updateContentOptions([
            'player_autoplay_enabled' => '1',
            'player_autoplay_muted' => '1',
            'default_comments_enabled' => '0',
            'guest_comments_enabled' => '0',
            'public_video_submissions_enabled' => '0',
        ]));
        self::assertTrue($settings->isPlayerAutoplayEnabled());
        self::assertTrue($settings->isPlayerAutoplayMuted());
        self::assertFalse($settings->isDefaultCommentsEnabled());
        self::assertFalse($settings->isGuestCommentsEnabled());
        self::assertFalse($settings->isPublicVideoSubmissionsEnabled());

        $errors = $settings->updateContentOptions([
            'player_autoplay_enabled' => '1',
            'player_autoplay_muted' => '0',
            'default_comments_enabled' => '1',
            'guest_comments_enabled' => '1',
            'public_video_submissions_enabled' => '1',
        ]);
        self::assertArrayHasKey('player_autoplay_muted', $errors);
        self::assertTrue($settings->isPlayerAutoplayEnabled());
        self::assertFalse($settings->isDefaultCommentsEnabled());
        self::assertFalse($settings->isGuestCommentsEnabled());
        self::assertFalse($settings->isPublicVideoSubmissionsEnabled());

        $errors = $settings->updateContentOptions([
            'player_autoplay_enabled' => '0',
            'player_autoplay_muted' => '1',
            'default_comments_enabled' => '1',
            'guest_comments_enabled' => '1',
            'public_video_submissions_enabled' => '1',
            'player_controls_enabled' => '0',
        ]);
        self::assertArrayHasKey('form', $errors);
        self::assertTrue($settings->isPlayerAutoplayEnabled());
        self::assertFalse($settings->isDefaultCommentsEnabled());
        self::assertFalse($settings->isGuestCommentsEnabled());
    }

    public function testGuestCommentSettingRejectsInvalidValuesWithoutPartialUpdates(): void
    {
        $settings = new SiteSettings();

        $errors = $settings->updateContentOptions(['guest_comments_enabled' => ['1']]);

        self::assertArrayHasKey('guest_comments_enabled', $errors);
        self::assertTrue($settings->isGuestCommentsEnabled());

        $errors = $settings->updateContentOptions(['guest_comments_enabled' => 'false']);

        self::assertArrayHasKey('guest_comments_enabled', $errors);
        self::assertTrue($settings->isGuestCommentsEnabled());
    }

    public function testCommentDisplayOptionsAreAllowlistedBoundedAndAtomic(): void
    {
        $settings = new SiteSettings();
        self::assertSame(CommentSortOrder::Added, $settings->getCommentDefaultSort());
        self::assertSame(0, $settings->getCommentRatingHideThreshold());
        self::assertSame(10, $settings->getCommentsPerPage());

        self::assertSame([], $settings->updateContentOptions([
            'comment_default_sort' => 'score',
            'comment_rating_hide_threshold' => '5',
            'comments_per_page' => '2',
        ]));
        self::assertSame(CommentSortOrder::Score, $settings->getCommentDefaultSort());
        self::assertSame(5, $settings->getCommentRatingHideThreshold());
        self::assertSame(2, $settings->getCommentsPerPage());

        self::assertSame([], $settings->updateContentOptions(['comments_per_page' => '100']));
        self::assertSame(100, $settings->getCommentsPerPage());

        self::assertSame([], $settings->updateContentOptions(['comment_rating_hide_threshold' => '1000']));
        self::assertSame(1000, $settings->getCommentRatingHideThreshold());

        $errors = $settings->updateContentOptions([
            'comment_default_sort' => ['score'],
            'comment_rating_hide_threshold' => '1001',
            'comments_per_page' => '101',
        ]);
        self::assertArrayHasKey('comment_default_sort', $errors);
        self::assertArrayHasKey('comment_rating_hide_threshold', $errors);
        self::assertArrayHasKey('comments_per_page', $errors);
        self::assertSame(CommentSortOrder::Score, $settings->getCommentDefaultSort());
        self::assertSame(1000, $settings->getCommentRatingHideThreshold());
        self::assertSame(100, $settings->getCommentsPerPage());

        $errors = $settings->updateContentOptions(['comment_rating_hide_threshold' => -1]);
        self::assertArrayHasKey('comment_rating_hide_threshold', $errors);
        self::assertSame(1000, $settings->getCommentRatingHideThreshold());
        self::assertArrayHasKey('comments_per_page', $settings->updateContentOptions(['comments_per_page' => '0']));
        self::assertSame(100, $settings->getCommentsPerPage());
    }

    public function testAutomaticVideoFeatureThresholdIsStrictlyBoundedAndAtomic(): void
    {
        $settings = new SiteSettings();
        self::assertSame(0, $settings->getAutoFeatureViewThreshold());

        self::assertSame([], $settings->updateContentOptions(['auto_feature_view_threshold' => '1']));
        self::assertSame(1, $settings->getAutoFeatureViewThreshold());
        self::assertSame([], $settings->updateContentOptions(['auto_feature_view_threshold' => '2147483647']));
        self::assertSame(2147483647, $settings->getAutoFeatureViewThreshold());

        foreach (['2147483648', '-1', '01', '+1', '', ['1'], 1.5, true, null] as $invalid) {
            $errors = $settings->updateContentOptions(['auto_feature_view_threshold' => $invalid]);
            self::assertArrayHasKey('auto_feature_view_threshold', $errors);
            self::assertSame(2147483647, $settings->getAutoFeatureViewThreshold());
        }

        $errors = $settings->updateContentOptions([
            'auto_feature_view_threshold' => '10',
            'video_player_engine' => 'unsupported',
        ]);
        self::assertArrayHasKey('video_player_engine', $errors);
        self::assertSame(2147483647, $settings->getAutoFeatureViewThreshold());
        self::assertArrayHasKey('form', $settings->updateContentOptions(['unrecognized_option' => '1']));
    }

    public function testExternalCommentProvidersRequireValidConfigurationAndConsentAtomically(): void
    {
        $settings = new SiteSettings();
        self::assertSame([], $settings->updateFromInput([
            'site_name' => 'Arcanorum',
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
            'comment_provider' => 'disqus',
            'disqus_comments_shortname' => 'arcanorum-comments',
        ]));
        self::assertSame(CommentProvider::Disqus, $settings->getCommentProvider());
        self::assertSame('arcanorum-comments', $settings->getDisqusCommentsShortname());

        $errors = $settings->updateFromInput([
            'site_name' => 'Arcanorum',
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
            'comment_provider' => 'facebook',
            'facebook_comments_app_id' => '123456789012345',
            'cookie_consent_enabled' => '0',
        ]);
        self::assertArrayHasKey('comment_provider', $errors);
        self::assertSame(CommentProvider::Disqus, $settings->getCommentProvider());
        self::assertSame('arcanorum-comments', $settings->getDisqusCommentsShortname());

        $errors = $settings->updateFromInput([
            'site_name' => 'Arcanorum',
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
            'comment_provider' => 'disqus',
            'disqus_comments_shortname' => 'https://attacker.example/script',
        ]);
        self::assertArrayHasKey('disqus_comments_shortname', $errors);
        self::assertSame('arcanorum-comments', $settings->getDisqusCommentsShortname());

        $errors = $settings->updateFromInput([
            'site_name' => 'Arcanorum',
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
            'comment_provider' => 'facebook',
            'facebook_comments_app_id' => '<script>alert(1)</script>',
        ]);
        self::assertArrayHasKey('facebook_comments_app_id', $errors);
        self::assertSame(CommentProvider::Disqus, $settings->getCommentProvider());
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

        $errors = $settings->updateContentOptions([
            'video_player_engine' => VideoPlayerEngine::Native->value,
            'player_autoplay_enabled' => null,
        ]);
        self::assertArrayHasKey('player_autoplay_enabled', $errors);
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

        $errors = $settings->updateContentOptions([
            'video_preload_mode' => VideoPreloadMode::Metadata->value,
            'default_comments_enabled' => null,
        ]);
        self::assertArrayHasKey('default_comments_enabled', $errors);
        self::assertSame(VideoPreloadMode::Auto, $settings->getVideoPreloadMode());
    }

    public function testProviderThumbnailResolutionIsAllowlistedAndUpdatedAtomically(): void
    {
        $settings = new SiteSettings();

        self::assertSame([], $settings->updateContentOptions([
            'provider_thumbnail_resolution' => VideoThumbnailResolution::ExtraLarge->value,
        ]));
        self::assertSame(VideoThumbnailResolution::ExtraLarge, $settings->getProviderThumbnailResolution());

        foreach (['remote', ['original'], null] as $invalid) {
            $errors = $settings->updateContentOptions(['provider_thumbnail_resolution' => $invalid]);
            self::assertArrayHasKey('provider_thumbnail_resolution', $errors);
            self::assertSame(VideoThumbnailResolution::ExtraLarge, $settings->getProviderThumbnailResolution());
        }

        self::assertSame([], $settings->updateContentOptions([
            'provider_thumbnail_resolution' => VideoThumbnailResolution::Original->value,
        ]));
        self::assertSame(VideoThumbnailResolution::Original, $settings->getProviderThumbnailResolution());
    }

    public function testPlayerDimensionsAndPublicEmbeddingSwitchAreAllowlistedAndAtomic(): void
    {
        $settings = new SiteSettings();

        self::assertSame(VideoPlayerAspectRatio::Widescreen, $settings->getVideoPlayerAspectRatio());
        self::assertSame(VideoPlayerMaxWidth::Fluid, $settings->getVideoPlayerMaxWidth());
        self::assertSame(VideoPlayerMaxWidth::Fluid, $settings->getVideoPlayerEmbedMaxWidth());
        self::assertSame(VideoPlayerMaxWidth::Fluid, $settings->getVideoPlayerHomeMaxWidth());
        self::assertFalse($settings->isPlayerFeaturedAutoplayEnabled());
        self::assertSame(VideoPlayerColorScheme::Midnight, $settings->getVideoPlayerColorScheme());
        self::assertFalse($settings->isVideoPlayerWatermarkEnabled());
        self::assertTrue($settings->isPublicVideoEmbeddingEnabled());
        self::assertSame(['width' => 360, 'height' => 640], VideoPlayerAspectRatio::Portrait->iframeDimensions());

        self::assertSame([], $settings->updateContentOptions([
            'video_player_aspect_ratio' => VideoPlayerAspectRatio::Portrait->value,
            'video_player_max_width' => VideoPlayerMaxWidth::Standard->value,
            'video_player_embed_max_width' => VideoPlayerMaxWidth::Wide->value,
            'video_player_home_max_width' => VideoPlayerMaxWidth::Compact->value,
            'player_featured_autoplay_enabled' => '1',
            'video_player_color_scheme' => VideoPlayerColorScheme::Ocean->value,
            'video_player_watermark_enabled' => '1',
            'public_video_embedding_enabled' => '0',
        ]));
        self::assertSame(VideoPlayerAspectRatio::Portrait, $settings->getVideoPlayerAspectRatio());
        self::assertSame(VideoPlayerMaxWidth::Standard, $settings->getVideoPlayerMaxWidth());
        self::assertSame(VideoPlayerMaxWidth::Wide, $settings->getVideoPlayerEmbedMaxWidth());
        self::assertSame(VideoPlayerMaxWidth::Compact, $settings->getVideoPlayerHomeMaxWidth());
        self::assertTrue($settings->isPlayerFeaturedAutoplayEnabled());
        self::assertSame(VideoPlayerColorScheme::Ocean, $settings->getVideoPlayerColorScheme());
        self::assertTrue($settings->isVideoPlayerWatermarkEnabled());
        self::assertSame(['width' => 450, 'height' => 800], $settings->getVideoPlayerAspectRatio()->iframeDimensions($settings->getVideoPlayerMaxWidth()));
        self::assertFalse($settings->isPublicVideoEmbeddingEnabled());

        $errors = $settings->updateContentOptions([
            'video_player_aspect_ratio' => '2:1',
            'video_player_max_width' => '777',
            'video_player_embed_max_width' => '777',
            'video_player_home_max_width' => '777',
            'player_featured_autoplay_enabled' => 'yes',
            'video_player_color_scheme' => 'url(https://invalid.example/)',
            'video_player_watermark_enabled' => 'yes',
            'public_video_embedding_enabled' => 'yes',
        ]);
        self::assertArrayHasKey('video_player_aspect_ratio', $errors);
        self::assertArrayHasKey('video_player_max_width', $errors);
        self::assertArrayHasKey('video_player_embed_max_width', $errors);
        self::assertArrayHasKey('video_player_home_max_width', $errors);
        self::assertArrayHasKey('player_featured_autoplay_enabled', $errors);
        self::assertArrayHasKey('video_player_color_scheme', $errors);
        self::assertArrayHasKey('video_player_watermark_enabled', $errors);
        self::assertArrayHasKey('public_video_embedding_enabled', $errors);
        self::assertSame(VideoPlayerAspectRatio::Portrait, $settings->getVideoPlayerAspectRatio());
        self::assertSame(VideoPlayerMaxWidth::Standard, $settings->getVideoPlayerMaxWidth());
        self::assertSame(VideoPlayerMaxWidth::Wide, $settings->getVideoPlayerEmbedMaxWidth());
        self::assertSame(VideoPlayerMaxWidth::Compact, $settings->getVideoPlayerHomeMaxWidth());
        self::assertTrue($settings->isPlayerFeaturedAutoplayEnabled());
        self::assertSame(VideoPlayerColorScheme::Ocean, $settings->getVideoPlayerColorScheme());
        self::assertTrue($settings->isVideoPlayerWatermarkEnabled());
        self::assertFalse($settings->isPublicVideoEmbeddingEnabled());
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

    public function testPublicDisplayOptionsApplyBoundedSettingsAtomically(): void
    {
        $settings = new SiteSettings();

        self::assertSame(1, $settings->getHomepageFeaturedLimit());
        self::assertSame(6, $settings->getHomepageVideoLimit());
        self::assertSame(24, $settings->getPublicVideoPageLimit());
        self::assertSame(VideoPopularitySort::TotalViews, $settings->getPopularVideoSort());
        self::assertFalse($settings->isPublicStatisticsEnabled());
        self::assertFalse($settings->isTagCloudEnabled());
        self::assertTrue($settings->isVideoShareWidgetEnabled());

        self::assertSame([], $settings->updatePublicDisplayOptions([
            'homepage_featured_limit' => '5',
            'homepage_video_limit' => '18',
            'homepage_news_limit' => '9',
            'public_video_page_limit' => '48',
            'popular_video_sort' => VideoPopularitySort::RecentViews->value,
            'popular_video_period_days' => '90',
            'currently_watching_limit' => '8',
            'public_statistics_enabled' => '1',
            'tag_cloud_enabled' => '1',
            'tag_cloud_limit' => '30',
            'tag_cloud_shuffle' => '1',
            'related_video_limit' => '9',
            'top_rated_video_limit' => '6',
            'video_share_widget_enabled' => '0',
        ]));

        self::assertSame(5, $settings->getHomepageFeaturedLimit());
        self::assertSame(18, $settings->getHomepageVideoLimit());
        self::assertSame(9, $settings->getHomepageNewsLimit());
        self::assertSame(48, $settings->getPublicVideoPageLimit());
        self::assertSame(VideoPopularitySort::RecentViews, $settings->getPopularVideoSort());
        self::assertSame(90, $settings->getPopularVideoPeriodDays());
        self::assertSame(8, $settings->getCurrentlyWatchingLimit());
        self::assertTrue($settings->isPublicStatisticsEnabled());
        self::assertTrue($settings->isTagCloudEnabled());
        self::assertSame(30, $settings->getTagCloudLimit());
        self::assertTrue($settings->isTagCloudShuffleEnabled());
        self::assertSame(9, $settings->getRelatedVideoLimit());
        self::assertSame(6, $settings->getTopRatedVideoLimit());
        self::assertFalse($settings->isVideoShareWidgetEnabled());
    }

    public function testPublicDisplayOptionsRejectUnknownAndUnsafeValuesWithoutPartialUpdates(): void
    {
        $settings = new SiteSettings();

        $errors = $settings->updatePublicDisplayOptions([
            'homepage_video_limit' => '24',
            'popular_video_sort' => 'random_sql',
        ]);
        self::assertArrayHasKey('popular_video_sort', $errors);
        self::assertSame(6, $settings->getHomepageVideoLimit());

        $errors = $settings->updatePublicDisplayOptions(['unexpected' => '1']);
        self::assertArrayHasKey('form', $errors);

        foreach ([['-1', 'homepage_featured_limit'], ['9', 'homepage_featured_limit'], ['25', 'homepage_video_limit'], ['13', 'currently_watching_limit'], [['6'], 'homepage_video_limit']] as [$value, $field]) {
            $errors = $settings->updatePublicDisplayOptions([$field => $value]);
            self::assertArrayHasKey($field, $errors);
            self::assertSame(6, $settings->getHomepageVideoLimit());
            self::assertSame(1, $settings->getHomepageFeaturedLimit());
        }

        self::assertSame([], $settings->updatePublicDisplayOptions(['tag_cloud_enabled' => '1', 'tag_cloud_limit' => '0']));
        self::assertFalse($settings->isTagCloudEnabled());
    }
}
