<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SiteSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SiteSettingsRepository::class)]
#[ORM\Table(name: 'site_settings')]
final class SiteSettings
{
    public const int SINGLETON_ID = 1;
    public const string DEFAULT_SITE_NAME = 'Arcanorum';
    public const string DEFAULT_TAGLINE = '';
    public const string DEFAULT_LOCALE = 'de';
    /** @var array<string, string> Supported locale code to source-language label. */
    public const array SUPPORTED_LOCALES = [
        'de' => 'Deutsch',
        'en' => 'Englisch',
        'ar' => 'Arabisch (RTL; Übersetzung teilweise)',
    ];
    public const string DEFAULT_TIMEZONE = 'Europe/Berlin';
    public const CommentProvider DEFAULT_COMMENT_PROVIDER = CommentProvider::Native;
    public const string DEFAULT_MAINTENANCE_MESSAGE = 'Die Website wird gewartet. Bitte versuchen Sie es später erneut.';
    public const string DEFAULT_COOKIE_CONSENT_MESSAGE = 'Wir verwenden notwendige Cookies für Anmeldung, Sicherheit und die Ansichtspräferenz in diesem Browser. Optionale Analyse- und Werbedienste werden nur nach Ihrer Zustimmung geladen.';
    public const string DEFAULT_PRIMARY_COLOR = '#24365E';
    public const string DEFAULT_ACCENT_COLOR = '#2F6E68';
    public const string DEFAULT_HEADING_COLOR = '#182033';
    public const string DEFAULT_CONTENT_BACKGROUND_COLOR = '#FFFFFF';
    public const string DEFAULT_CONTENT_TEXT_COLOR = '#182033';
    public const string DEFAULT_BODY_BACKGROUND_COLOR = '#F3F5F9';
    public const string DEFAULT_HEADER_BACKGROUND_COLOR = '#FFFFFF';
    public const string DEFAULT_HEADER_PANE_COLOR = '#596783';
    public const string DEFAULT_NAVIGATION_BACKGROUND_COLOR = '#FFFFFF';
    public const string DEFAULT_NAVIGATION_LINK_COLOR = '#24365E';
    public const string DEFAULT_CONTENT_LINK_COLOR = '#24365E';
    public const string DEFAULT_VIDEO_LINK_COLOR = '#182033';
    public const string DEFAULT_SITE_TITLE_COLOR = '#172236';
    public const string DEFAULT_FOOTER_BACKGROUND_COLOR = '#F3F5F9';
    public const string DEFAULT_FOOTER_LINK_COLOR = '#596783';
    public const int DEFAULT_CONTENT_WIDTH = 1248;
    private const int MIN_CONTENT_WIDTH = 640;
    private const int MAX_CONTENT_WIDTH = 1920;
    public const int MAX_AUTO_FEATURE_VIEW_THRESHOLD = 2147483647;

    /** @var array<string, true>|null */
    private static ?array $availableTimezones = null;

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(name: 'site_name', length: 80)]
    private string $siteName;

    #[ORM\Column(name: 'site_logo_object_id', length: 64, nullable: true)]
    private ?string $siteLogoObjectId = null;

    #[ORM\Column(name: 'site_logo_mime_type', length: 16, nullable: true)]
    private ?string $siteLogoMimeType = null;

    #[ORM\Column(name: 'site_logo_size', type: Types::INTEGER, nullable: true)]
    private ?int $siteLogoSize = null;

    #[ORM\Column(name: 'site_logo_sha256', length: 64, nullable: true)]
    private ?string $siteLogoSha256 = null;

    #[ORM\Column(length: 160)]
    private string $tagline;

    #[ORM\Column(name: 'default_locale', length: 8)]
    private string $defaultLocale;

    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\Column(name: 'seo_description', length: 320)]
    private string $seoDescription;

    #[ORM\Column(name: 'search_indexing_enabled', type: Types::BOOLEAN)]
    private bool $searchIndexingEnabled;

    #[ORM\Column(name: 'maintenance_mode_enabled', type: Types::BOOLEAN)]
    private bool $maintenanceModeEnabled;

    #[ORM\Column(name: 'maintenance_message', length: 320)]
    private string $maintenanceMessage;

    #[ORM\Column(name: 'cookie_consent_enabled', type: Types::BOOLEAN)]
    private bool $cookieConsentEnabled;

    #[ORM\Column(name: 'cookie_consent_message', length: 320)]
    private string $cookieConsentMessage;

    #[ORM\Column(name: 'google_analytics_measurement_id', length: 12, options: ['default' => ''])]
    private string $googleAnalyticsMeasurementId = '';

    #[ORM\Column(name: 'module_news_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $newsModuleEnabled;

    #[ORM\Column(name: 'module_series_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $seriesModuleEnabled;

    #[ORM\Column(name: 'module_comments_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $commentsModuleEnabled;

    #[ORM\Column(name: 'module_contact_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $contactModuleEnabled;

    #[ORM\Column(name: 'module_pages_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $pagesModuleEnabled;

    #[ORM\Column(name: 'module_search_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $searchModuleEnabled;

    #[ORM\Column(name: 'player_autoplay_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $playerAutoplayEnabled;

    #[ORM\Column(name: 'video_player_engine', length: 16, enumType: VideoPlayerEngine::class, options: ['default' => 'native'])]
    private VideoPlayerEngine $videoPlayerEngine = VideoPlayerEngine::Native;

    #[ORM\Column(name: 'provider_thumbnail_resolution', length: 16, enumType: VideoThumbnailResolution::class, options: ['default' => 'medium'])]
    private VideoThumbnailResolution $providerThumbnailResolution = VideoThumbnailResolution::Medium;

    #[ORM\Column(name: 'video_preload_mode', length: 16, enumType: VideoPreloadMode::class, options: ['default' => 'metadata'])]
    private VideoPreloadMode $videoPreloadMode = VideoPreloadMode::Metadata;

    #[ORM\Column(name: 'video_player_aspect_ratio', length: 4, enumType: VideoPlayerAspectRatio::class, options: ['default' => '16:9'])]
    private VideoPlayerAspectRatio $videoPlayerAspectRatio = VideoPlayerAspectRatio::Widescreen;

    #[ORM\Column(name: 'video_player_max_width', length: 8, enumType: VideoPlayerMaxWidth::class, options: ['default' => 'fluid'])]
    private VideoPlayerMaxWidth $videoPlayerMaxWidth = VideoPlayerMaxWidth::Fluid;

    #[ORM\Column(name: 'video_player_embed_max_width', length: 8, enumType: VideoPlayerMaxWidth::class, options: ['default' => 'fluid'])]
    private VideoPlayerMaxWidth $videoPlayerEmbedMaxWidth = VideoPlayerMaxWidth::Fluid;

    #[ORM\Column(name: 'video_player_home_max_width', length: 8, enumType: VideoPlayerMaxWidth::class, options: ['default' => 'fluid'])]
    private VideoPlayerMaxWidth $videoPlayerHomeMaxWidth = VideoPlayerMaxWidth::Fluid;

    #[ORM\Column(name: 'player_featured_autoplay_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $playerFeaturedAutoplayEnabled = false;

    #[ORM\Column(name: 'auto_feature_view_threshold', type: Types::INTEGER, options: ['default' => 0])]
    private int $autoFeatureViewThreshold = 0;

    #[ORM\Column(name: 'video_player_color_scheme', length: 16, enumType: VideoPlayerColorScheme::class, options: ['default' => 'midnight'])]
    private VideoPlayerColorScheme $videoPlayerColorScheme = VideoPlayerColorScheme::Midnight;

    #[ORM\Column(name: 'video_player_watermark_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $videoPlayerWatermarkEnabled = false;

    #[ORM\Column(name: 'video_player_watermark_object_id', length: 64, nullable: true)]
    private ?string $videoPlayerWatermarkObjectId = null;

    #[ORM\Column(name: 'video_player_watermark_mime_type', length: 16, nullable: true)]
    private ?string $videoPlayerWatermarkMimeType = null;

    #[ORM\Column(name: 'video_player_watermark_size', type: Types::INTEGER, nullable: true)]
    private ?int $videoPlayerWatermarkSize = null;

    #[ORM\Column(name: 'video_player_watermark_sha256', length: 64, nullable: true)]
    private ?string $videoPlayerWatermarkSha256 = null;

    #[ORM\Column(name: 'public_video_embedding_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $publicVideoEmbeddingEnabled = true;

    #[ORM\Column(name: 'player_autoplay_muted', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $playerAutoplayMuted;

    #[ORM\Column(name: 'default_comments_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $defaultCommentsEnabled;

    #[ORM\Column(name: 'guest_comments_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $guestCommentsEnabled;

    #[ORM\Column(name: 'comment_default_sort', length: 16, enumType: CommentSortOrder::class, options: ['default' => 'added'])]
    private CommentSortOrder $commentDefaultSort = CommentSortOrder::Added;

    #[ORM\Column(name: 'comment_rating_hide_threshold', type: Types::SMALLINT, options: ['default' => 0])]
    private int $commentRatingHideThreshold = 0;

    #[ORM\Column(name: 'comments_per_page', type: Types::SMALLINT, options: ['default' => 10])]
    private int $commentsPerPage = 10;

    #[ORM\Column(name: 'comment_provider', length: 16, enumType: CommentProvider::class, options: ['default' => 'native'])]
    private CommentProvider $commentProvider = CommentProvider::Native;

    #[ORM\Column(name: 'facebook_comments_app_id', length: 32, options: ['default' => ''])]
    private string $facebookCommentsAppId = '';

    #[ORM\Column(name: 'disqus_comments_shortname', length: 63, options: ['default' => ''])]
    private string $disqusCommentsShortname = '';

    #[ORM\Column(name: 'public_video_submissions_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $publicVideoSubmissionsEnabled;

    #[ORM\Column(name: 'homepage_featured_limit', type: Types::SMALLINT, options: ['default' => 1])]
    private int $homepageFeaturedLimit = 1;

    #[ORM\Column(name: 'homepage_video_limit', type: Types::SMALLINT, options: ['default' => 6])]
    private int $homepageVideoLimit = 6;

    #[ORM\Column(name: 'homepage_news_limit', type: Types::SMALLINT, options: ['default' => 6])]
    private int $homepageNewsLimit = 6;

    #[ORM\Column(name: 'public_video_page_limit', type: Types::SMALLINT, options: ['default' => 24])]
    private int $publicVideoPageLimit = 24;

    #[ORM\Column(name: 'popular_video_sort', length: 16, enumType: VideoPopularitySort::class, options: ['default' => 'views'])]
    private VideoPopularitySort $popularVideoSort = VideoPopularitySort::TotalViews;

    #[ORM\Column(name: 'popular_video_period_days', type: Types::SMALLINT, options: ['default' => 30])]
    private int $popularVideoPeriodDays = 30;

    #[ORM\Column(name: 'currently_watching_limit', type: Types::SMALLINT, options: ['default' => 0])]
    private int $currentlyWatchingLimit = 0;

    #[ORM\Column(name: 'public_statistics_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $publicStatisticsEnabled = false;

    #[ORM\Column(name: 'tag_cloud_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $tagCloudEnabled = false;

    #[ORM\Column(name: 'tag_cloud_limit', type: Types::SMALLINT, options: ['default' => 20])]
    private int $tagCloudLimit = 20;

    #[ORM\Column(name: 'tag_cloud_shuffle', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $tagCloudShuffle = false;

    #[ORM\Column(name: 'related_video_limit', type: Types::SMALLINT, options: ['default' => 6])]
    private int $relatedVideoLimit = 6;

    #[ORM\Column(name: 'top_rated_video_limit', type: Types::SMALLINT, options: ['default' => 0])]
    private int $topRatedVideoLimit = 0;

    #[ORM\Column(name: 'video_share_widget_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $videoShareWidgetEnabled = true;

    #[ORM\Column(name: 'member_registration_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $memberRegistrationEnabled = true;

    #[ORM\Column(name: 'social_google_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $googleSocialLoginEnabled = false;

    #[ORM\Column(name: 'social_facebook_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $facebookSocialLoginEnabled = false;

    #[ORM\Column(name: 'social_twitter_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $twitterSocialLoginEnabled = false;

    #[ORM\Column(name: 'email_from_address', length: 254, options: ['default' => ''])]
    private string $emailFromAddress = '';

    #[ORM\Column(name: 'public_theme', length: 32, options: ['default' => 'arcanorum'])]
    private string $publicTheme = 'arcanorum';

    #[ORM\Column(name: 'public_layout', length: 24, options: ['default' => 'grid'])]
    private string $publicLayout = 'grid';

    #[ORM\Column(name: 'theme_primary_color', length: 7, options: ['default' => self::DEFAULT_PRIMARY_COLOR])]
    private string $primaryColor = self::DEFAULT_PRIMARY_COLOR;

    #[ORM\Column(name: 'theme_accent_color', length: 7, options: ['default' => self::DEFAULT_ACCENT_COLOR])]
    private string $accentColor = self::DEFAULT_ACCENT_COLOR;

    #[ORM\Column(name: 'theme_heading_color', length: 7, options: ['default' => self::DEFAULT_HEADING_COLOR])]
    private string $headingColor = self::DEFAULT_HEADING_COLOR;

    #[ORM\Column(name: 'theme_content_background_color', length: 7, options: ['default' => self::DEFAULT_CONTENT_BACKGROUND_COLOR])]
    private string $contentBackgroundColor = self::DEFAULT_CONTENT_BACKGROUND_COLOR;

    #[ORM\Column(name: 'theme_content_text_color', length: 7, options: ['default' => self::DEFAULT_CONTENT_TEXT_COLOR])]
    private string $contentTextColor = self::DEFAULT_CONTENT_TEXT_COLOR;

    #[ORM\Column(name: 'theme_body_background_color', length: 7, options: ['default' => self::DEFAULT_BODY_BACKGROUND_COLOR])]
    private string $bodyBackgroundColor = self::DEFAULT_BODY_BACKGROUND_COLOR;

    #[ORM\Column(name: 'theme_header_background_color', length: 7, options: ['default' => self::DEFAULT_HEADER_BACKGROUND_COLOR])]
    private string $headerBackgroundColor = self::DEFAULT_HEADER_BACKGROUND_COLOR;

    #[ORM\Column(name: 'theme_header_pane_color', length: 7, options: ['default' => self::DEFAULT_HEADER_PANE_COLOR])]
    private string $headerPaneColor = self::DEFAULT_HEADER_PANE_COLOR;

    #[ORM\Column(name: 'theme_navigation_background_color', length: 7, options: ['default' => self::DEFAULT_NAVIGATION_BACKGROUND_COLOR])]
    private string $navigationBackgroundColor = self::DEFAULT_NAVIGATION_BACKGROUND_COLOR;

    #[ORM\Column(name: 'theme_navigation_link_color', length: 7, options: ['default' => self::DEFAULT_NAVIGATION_LINK_COLOR])]
    private string $navigationLinkColor = self::DEFAULT_NAVIGATION_LINK_COLOR;

    #[ORM\Column(name: 'theme_content_link_color', length: 7, options: ['default' => self::DEFAULT_CONTENT_LINK_COLOR])]
    private string $contentLinkColor = self::DEFAULT_CONTENT_LINK_COLOR;

    #[ORM\Column(name: 'theme_video_link_color', length: 7, options: ['default' => self::DEFAULT_VIDEO_LINK_COLOR])]
    private string $videoLinkColor = self::DEFAULT_VIDEO_LINK_COLOR;

    #[ORM\Column(name: 'theme_site_title_color', length: 7, options: ['default' => self::DEFAULT_SITE_TITLE_COLOR])]
    private string $siteTitleColor = self::DEFAULT_SITE_TITLE_COLOR;

    #[ORM\Column(name: 'theme_footer_background_color', length: 7, options: ['default' => self::DEFAULT_FOOTER_BACKGROUND_COLOR])]
    private string $footerBackgroundColor = self::DEFAULT_FOOTER_BACKGROUND_COLOR;

    #[ORM\Column(name: 'theme_footer_link_color', length: 7, options: ['default' => self::DEFAULT_FOOTER_LINK_COLOR])]
    private string $footerLinkColor = self::DEFAULT_FOOTER_LINK_COLOR;

    #[ORM\Column(name: 'theme_content_width', type: Types::INTEGER, options: ['default' => self::DEFAULT_CONTENT_WIDTH])]
    private int $contentWidth = self::DEFAULT_CONTENT_WIDTH;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->siteName = self::DEFAULT_SITE_NAME;
        $this->tagline = self::DEFAULT_TAGLINE;
        $this->defaultLocale = self::DEFAULT_LOCALE;
        $this->timezone = self::DEFAULT_TIMEZONE;
        $this->seoDescription = '';
        $this->searchIndexingEnabled = true;
        $this->maintenanceModeEnabled = false;
        $this->maintenanceMessage = self::DEFAULT_MAINTENANCE_MESSAGE;
        $this->cookieConsentEnabled = true;
        $this->cookieConsentMessage = self::DEFAULT_COOKIE_CONSENT_MESSAGE;
        $this->newsModuleEnabled = true;
        $this->seriesModuleEnabled = true;
        $this->commentsModuleEnabled = true;
        $this->contactModuleEnabled = true;
        $this->pagesModuleEnabled = true;
        $this->searchModuleEnabled = true;
        $this->playerAutoplayEnabled = false;
        $this->playerAutoplayMuted = true;
        $this->defaultCommentsEnabled = true;
        $this->guestCommentsEnabled = true;
        $this->commentDefaultSort = CommentSortOrder::Added;
        $this->commentRatingHideThreshold = 0;
        $this->commentsPerPage = 10;
        $this->publicVideoSubmissionsEnabled = true;
        $this->memberRegistrationEnabled = true;
        $this->googleSocialLoginEnabled = false;
        $this->facebookSocialLoginEnabled = false;
        $this->twitterSocialLoginEnabled = false;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $input
     * @return array<string, string> field name to safe user-facing error
     */
    public function updateContentOptions(array $input): array
    {
        $knownFields = [
            'video_player_engine' => true,
            'provider_thumbnail_resolution' => true,
            'video_preload_mode' => true,
            'video_player_aspect_ratio' => true,
            'video_player_max_width' => true,
            'video_player_embed_max_width' => true,
            'video_player_home_max_width' => true,
            'player_featured_autoplay_enabled' => true,
            'auto_feature_view_threshold' => true,
            'video_player_color_scheme' => true,
            'video_player_watermark_enabled' => true,
            'public_video_embedding_enabled' => true,
            'player_autoplay_enabled' => true,
            'player_autoplay_muted' => true,
            'default_comments_enabled' => true,
            'guest_comments_enabled' => true,
            'comment_default_sort' => true,
            'comment_rating_hide_threshold' => true,
            'comments_per_page' => true,
            'public_video_submissions_enabled' => true,
        ];
        foreach ($input as $field => $_value) {
            if (!isset($knownFields[$field])) {
                return ['form' => 'Die Anfrage enthält eine unbekannte Inhaltseinstellung.'];
            }
        }

        $errors = [];
        $videoPlayerEngineValue = $input['video_player_engine'] ?? $this->videoPlayerEngine->value;
        $videoPlayerEngine = is_string($videoPlayerEngineValue)
            ? VideoPlayerEngine::tryFrom($videoPlayerEngineValue)
            : null;
        if (null === $videoPlayerEngine) {
            $errors['video_player_engine'] = 'Bitte wähle einen unterstützten Video-Player.';
        }
        $thumbnailResolutionValue = array_key_exists('provider_thumbnail_resolution', $input)
            ? $input['provider_thumbnail_resolution']
            : $this->providerThumbnailResolution->value;
        $thumbnailResolution = is_string($thumbnailResolutionValue)
            ? VideoThumbnailResolution::tryFrom($thumbnailResolutionValue)
            : null;
        if (null === $thumbnailResolution) {
            $errors['provider_thumbnail_resolution'] = 'Bitte wähle eine unterstützte Vorschaubild-Auflösung.';
        }
        $videoPreloadModeValue = $input['video_preload_mode'] ?? $this->videoPreloadMode->value;
        $videoPreloadMode = is_string($videoPreloadModeValue)
            ? VideoPreloadMode::tryFrom($videoPreloadModeValue)
            : null;
        if (null === $videoPreloadMode) {
            $errors['video_preload_mode'] = 'Bitte wähle eine unterstützte Einstellung für das Vorladen.';
        }
        $videoPlayerAspectRatioValue = $input['video_player_aspect_ratio'] ?? $this->videoPlayerAspectRatio->value;
        $videoPlayerAspectRatio = is_string($videoPlayerAspectRatioValue)
            ? VideoPlayerAspectRatio::tryFrom($videoPlayerAspectRatioValue)
            : null;
        if (null === $videoPlayerAspectRatio) {
            $errors['video_player_aspect_ratio'] = 'Bitte wähle ein unterstütztes Seitenverhältnis.';
        }
        $videoPlayerMaxWidthValue = $input['video_player_max_width'] ?? $this->videoPlayerMaxWidth->value;
        $videoPlayerMaxWidth = is_string($videoPlayerMaxWidthValue)
            ? VideoPlayerMaxWidth::tryFrom($videoPlayerMaxWidthValue)
            : null;
        if (null === $videoPlayerMaxWidth) {
            $errors['video_player_max_width'] = 'Bitte wähle eine unterstützte maximale Playerbreite.';
        }
        $videoPlayerEmbedMaxWidthValue = $input['video_player_embed_max_width'] ?? $this->videoPlayerEmbedMaxWidth->value;
        $videoPlayerEmbedMaxWidth = is_string($videoPlayerEmbedMaxWidthValue)
            ? VideoPlayerMaxWidth::tryFrom($videoPlayerEmbedMaxWidthValue)
            : null;
        if (null === $videoPlayerEmbedMaxWidth) {
            $errors['video_player_embed_max_width'] = 'Bitte wähle eine unterstützte maximale Breite für Einbettungen.';
        }
        $videoPlayerHomeMaxWidthValue = $input['video_player_home_max_width'] ?? $this->videoPlayerHomeMaxWidth->value;
        $videoPlayerHomeMaxWidth = is_string($videoPlayerHomeMaxWidthValue)
            ? VideoPlayerMaxWidth::tryFrom($videoPlayerHomeMaxWidthValue)
            : null;
        if (null === $videoPlayerHomeMaxWidth) {
            $errors['video_player_home_max_width'] = 'Bitte wähle eine unterstützte maximale Breite für die Startseitenwiedergabe.';
        }
        $featuredAutoplayValue = array_key_exists('player_featured_autoplay_enabled', $input)
            ? $input['player_featured_autoplay_enabled']
            : $this->playerFeaturedAutoplayEnabled;
        $featuredAutoplayEnabled = self::normalizeBoolean(
            $featuredAutoplayValue,
            $this->playerFeaturedAutoplayEnabled,
            'player_featured_autoplay_enabled',
            $errors,
        );
        $autoFeatureViewThresholdValue = array_key_exists('auto_feature_view_threshold', $input)
            ? $input['auto_feature_view_threshold']
            : $this->autoFeatureViewThreshold;
        if (is_int($autoFeatureViewThresholdValue)) {
            $autoFeatureViewThreshold = $autoFeatureViewThresholdValue;
        } elseif (is_string($autoFeatureViewThresholdValue)
            && 1 === preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $autoFeatureViewThresholdValue)
        ) {
            $autoFeatureViewThreshold = (int) $autoFeatureViewThresholdValue;
        } else {
            $autoFeatureViewThreshold = null;
        }
        if (null === $autoFeatureViewThreshold
            || $autoFeatureViewThreshold < 0
            || $autoFeatureViewThreshold > self::MAX_AUTO_FEATURE_VIEW_THRESHOLD
        ) {
            $errors['auto_feature_view_threshold'] = 'Die Schwelle für die automatische Hervorhebung muss eine ganze Zahl von 0 bis 2147483647 sein.';
        }
        $videoPlayerColorSchemeValue = $input['video_player_color_scheme'] ?? $this->videoPlayerColorScheme->value;
        $videoPlayerColorScheme = is_string($videoPlayerColorSchemeValue)
            ? VideoPlayerColorScheme::tryFrom($videoPlayerColorSchemeValue)
            : null;
        if (null === $videoPlayerColorScheme) {
            $errors['video_player_color_scheme'] = 'Bitte wähle ein unterstütztes Player-Farbschema.';
        }
        $videoPlayerWatermarkValue = array_key_exists('video_player_watermark_enabled', $input)
            ? $input['video_player_watermark_enabled']
            : $this->videoPlayerWatermarkEnabled;
        $videoPlayerWatermarkEnabled = self::normalizeBoolean(
            $videoPlayerWatermarkValue,
            $this->videoPlayerWatermarkEnabled,
            'video_player_watermark_enabled',
            $errors,
        );
        $publicVideoEmbeddingEnabledValue = array_key_exists('public_video_embedding_enabled', $input)
            ? $input['public_video_embedding_enabled']
            : $this->publicVideoEmbeddingEnabled;
        $publicVideoEmbeddingEnabled = self::normalizeBoolean(
            $publicVideoEmbeddingEnabledValue,
            $this->publicVideoEmbeddingEnabled,
            'public_video_embedding_enabled',
            $errors,
        );
        $autoplayEnabledValue = array_key_exists('player_autoplay_enabled', $input)
            ? $input['player_autoplay_enabled']
            : $this->playerAutoplayEnabled;
        $autoplayEnabled = self::normalizeBoolean(
            $autoplayEnabledValue,
            $this->playerAutoplayEnabled,
            'player_autoplay_enabled',
            $errors,
        );
        $autoplayMutedValue = array_key_exists('player_autoplay_muted', $input)
            ? $input['player_autoplay_muted']
            : $this->playerAutoplayMuted;
        $autoplayMuted = self::normalizeBoolean(
            $autoplayMutedValue,
            $this->playerAutoplayMuted,
            'player_autoplay_muted',
            $errors,
        );
        $defaultCommentsEnabledValue = array_key_exists('default_comments_enabled', $input)
            ? $input['default_comments_enabled']
            : $this->defaultCommentsEnabled;
        $defaultCommentsEnabled = self::normalizeBoolean(
            $defaultCommentsEnabledValue,
            $this->defaultCommentsEnabled,
            'default_comments_enabled',
            $errors,
        );
        $guestCommentsEnabledValue = array_key_exists('guest_comments_enabled', $input)
            ? $input['guest_comments_enabled']
            : $this->guestCommentsEnabled;
        $guestCommentsEnabled = self::normalizeBoolean(
            $guestCommentsEnabledValue,
            $this->guestCommentsEnabled,
            'guest_comments_enabled',
            $errors,
        );
        $commentDefaultSortValue = array_key_exists('comment_default_sort', $input)
            ? $input['comment_default_sort']
            : $this->commentDefaultSort->value;
        $commentDefaultSort = is_string($commentDefaultSortValue)
            ? CommentSortOrder::tryFrom($commentDefaultSortValue)
            : null;
        if (null === $commentDefaultSort) {
            $errors['comment_default_sort'] = 'Bitte wähle eine unterstützte Kommentarsortierung.';
        }
        $commentRatingHideThresholdValue = array_key_exists('comment_rating_hide_threshold', $input)
            ? $input['comment_rating_hide_threshold']
            : $this->commentRatingHideThreshold;
        if (is_int($commentRatingHideThresholdValue)) {
            $commentRatingHideThreshold = $commentRatingHideThresholdValue;
        } elseif (is_string($commentRatingHideThresholdValue)
            && 1 === preg_match('/\A(?:0|[1-9][0-9]{0,3})\z/D', $commentRatingHideThresholdValue)
        ) {
            $commentRatingHideThreshold = (int) $commentRatingHideThresholdValue;
        } else {
            $commentRatingHideThreshold = null;
        }
        if (null === $commentRatingHideThreshold || $commentRatingHideThreshold < 0 || $commentRatingHideThreshold > 1000) {
            $errors['comment_rating_hide_threshold'] = 'Die Schwelle muss eine ganze Zahl von 0 bis 1000 sein.';
        }
        $commentsPerPageValue = array_key_exists('comments_per_page', $input)
            ? $input['comments_per_page']
            : $this->commentsPerPage;
        if (is_int($commentsPerPageValue)) {
            $commentsPerPage = $commentsPerPageValue;
        } elseif (is_string($commentsPerPageValue)
            && 1 === preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', $commentsPerPageValue)
        ) {
            $commentsPerPage = (int) $commentsPerPageValue;
        } else {
            $commentsPerPage = null;
        }
        if (null === $commentsPerPage || $commentsPerPage < 1 || $commentsPerPage > 100) {
            $errors['comments_per_page'] = 'Die Zahl der Kommentare pro Seite muss zwischen 1 und 100 liegen.';
        }
        $videoSubmissionsEnabledValue = array_key_exists('public_video_submissions_enabled', $input)
            ? $input['public_video_submissions_enabled']
            : $this->publicVideoSubmissionsEnabled;
        $videoSubmissionsEnabled = self::normalizeBoolean(
            $videoSubmissionsEnabledValue,
            $this->publicVideoSubmissionsEnabled,
            'public_video_submissions_enabled',
            $errors,
        );

        if ($autoplayEnabled && !$autoplayMuted) {
            $errors['player_autoplay_muted'] = 'Automatische Wiedergabe ist nur mit stummgeschaltetem Player zulässig.';
        }
        if ([] !== $errors) {
            return $errors;
        }

        $this->videoPlayerEngine = $videoPlayerEngine ?? $this->videoPlayerEngine;
        $this->providerThumbnailResolution = $thumbnailResolution ?? $this->providerThumbnailResolution;
        $this->videoPreloadMode = $videoPreloadMode ?? $this->videoPreloadMode;
        $this->videoPlayerAspectRatio = $videoPlayerAspectRatio ?? $this->videoPlayerAspectRatio;
        $this->videoPlayerMaxWidth = $videoPlayerMaxWidth ?? $this->videoPlayerMaxWidth;
        $this->videoPlayerEmbedMaxWidth = $videoPlayerEmbedMaxWidth ?? $this->videoPlayerEmbedMaxWidth;
        $this->videoPlayerHomeMaxWidth = $videoPlayerHomeMaxWidth ?? $this->videoPlayerHomeMaxWidth;
        $this->playerFeaturedAutoplayEnabled = $featuredAutoplayEnabled;
        $this->autoFeatureViewThreshold = $autoFeatureViewThreshold ?? $this->autoFeatureViewThreshold;
        $this->videoPlayerColorScheme = $videoPlayerColorScheme ?? $this->videoPlayerColorScheme;
        $this->videoPlayerWatermarkEnabled = $videoPlayerWatermarkEnabled;
        $this->publicVideoEmbeddingEnabled = $publicVideoEmbeddingEnabled;
        $this->playerAutoplayEnabled = $autoplayEnabled;
        $this->playerAutoplayMuted = $autoplayMuted;
        $this->defaultCommentsEnabled = $defaultCommentsEnabled;
        $this->guestCommentsEnabled = $guestCommentsEnabled;
        $this->commentDefaultSort = $commentDefaultSort ?? $this->commentDefaultSort;
        $this->commentRatingHideThreshold = $commentRatingHideThreshold ?? $this->commentRatingHideThreshold;
        $this->commentsPerPage = $commentsPerPage ?? $this->commentsPerPage;
        $this->publicVideoSubmissionsEnabled = $videoSubmissionsEnabled;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [];
    }

    /** @param array<string, mixed> $input
     * @return array<string, string> field name to safe user-facing error
     */
    public function updatePublicDisplayOptions(array $input): array
    {
        $knownFields = [
            'homepage_featured_limit' => true,
            'homepage_video_limit' => true,
            'homepage_news_limit' => true,
            'public_video_page_limit' => true,
            'popular_video_sort' => true,
            'popular_video_period_days' => true,
            'currently_watching_limit' => true,
            'public_statistics_enabled' => true,
            'tag_cloud_enabled' => true,
            'tag_cloud_limit' => true,
            'tag_cloud_shuffle' => true,
            'related_video_limit' => true,
            'top_rated_video_limit' => true,
            'video_share_widget_enabled' => true,
        ];
        foreach ($input as $field => $_value) {
            if (!isset($knownFields[$field])) {
                return ['form' => 'Die Anfrage enthält eine unbekannte Anzeigeeinstellung.'];
            }
        }

        $errors = [];
        $homepageFeaturedLimit = self::boundedInteger($input['homepage_featured_limit'] ?? $this->homepageFeaturedLimit, 1, 8, 'homepage_featured_limit', $errors);
        $homepageVideoLimit = self::boundedInteger($input['homepage_video_limit'] ?? $this->homepageVideoLimit, 0, 24, 'homepage_video_limit', $errors);
        $homepageNewsLimit = self::boundedInteger($input['homepage_news_limit'] ?? $this->homepageNewsLimit, 0, 12, 'homepage_news_limit', $errors);
        $publicVideoPageLimit = self::allowedInteger($input['public_video_page_limit'] ?? $this->publicVideoPageLimit, [12, 24, 36, 48], 'public_video_page_limit', $errors);
        $popularVideoSortValue = $input['popular_video_sort'] ?? $this->popularVideoSort->value;
        $popularVideoSort = is_string($popularVideoSortValue) ? VideoPopularitySort::tryFrom($popularVideoSortValue) : null;
        if (null === $popularVideoSort) {
            $errors['popular_video_sort'] = 'Bitte wähle eine unterstützte Sortierung für beliebte Videos.';
        }
        $popularVideoPeriodDays = self::allowedInteger($input['popular_video_period_days'] ?? $this->popularVideoPeriodDays, [7, 30, 90], 'popular_video_period_days', $errors);
        $currentlyWatchingLimit = self::boundedInteger($input['currently_watching_limit'] ?? $this->currentlyWatchingLimit, 0, 12, 'currently_watching_limit', $errors);
        $publicStatisticsEnabled = self::normalizeBoolean($input['public_statistics_enabled'] ?? $this->publicStatisticsEnabled, $this->publicStatisticsEnabled, 'public_statistics_enabled', $errors);
        $tagCloudEnabled = self::normalizeBoolean($input['tag_cloud_enabled'] ?? $this->tagCloudEnabled, $this->tagCloudEnabled, 'tag_cloud_enabled', $errors);
        $tagCloudLimit = self::boundedInteger($input['tag_cloud_limit'] ?? $this->tagCloudLimit, 0, 50, 'tag_cloud_limit', $errors);
        $tagCloudShuffle = self::normalizeBoolean($input['tag_cloud_shuffle'] ?? $this->tagCloudShuffle, $this->tagCloudShuffle, 'tag_cloud_shuffle', $errors);
        $relatedVideoLimit = self::boundedInteger($input['related_video_limit'] ?? $this->relatedVideoLimit, 0, 12, 'related_video_limit', $errors);
        $topRatedVideoLimit = self::boundedInteger($input['top_rated_video_limit'] ?? $this->topRatedVideoLimit, 0, 12, 'top_rated_video_limit', $errors);
        $videoShareWidgetEnabled = self::normalizeBoolean($input['video_share_widget_enabled'] ?? $this->videoShareWidgetEnabled, $this->videoShareWidgetEnabled, 'video_share_widget_enabled', $errors);
        if ([] !== $errors) {
            return $errors;
        }

        $this->homepageFeaturedLimit = $homepageFeaturedLimit;
        $this->homepageVideoLimit = $homepageVideoLimit;
        $this->homepageNewsLimit = $homepageNewsLimit;
        $this->publicVideoPageLimit = $publicVideoPageLimit;
        $this->popularVideoSort = $popularVideoSort ?? $this->popularVideoSort;
        $this->popularVideoPeriodDays = $popularVideoPeriodDays;
        $this->currentlyWatchingLimit = $currentlyWatchingLimit;
        $this->publicStatisticsEnabled = $publicStatisticsEnabled;
        $this->tagCloudEnabled = $tagCloudEnabled;
        $this->tagCloudLimit = $tagCloudLimit;
        $this->tagCloudShuffle = $tagCloudShuffle;
        $this->relatedVideoLimit = $relatedVideoLimit;
        $this->topRatedVideoLimit = $topRatedVideoLimit;
        $this->videoShareWidgetEnabled = $videoShareWidgetEnabled;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [];
    }

    /** @param array<string, mixed> $input
     * @return array<string, string> field name to safe user-facing error
     */
    public function updateFeatureModules(array $input): array
    {
        $errors = [];
        $enabled = [];
        $knownFields = array_fill_keys(array_map(
            static fn (FeatureModule $module): string => $module->inputName(),
            FeatureModule::cases(),
        ), true);

        foreach ($input as $field => $_value) {
            if (!isset($knownFields[$field])) {
                $errors['form'] = 'Die Anfrage enthält eine unbekannte Moduleinstellung.';

                return $errors;
            }
        }

        foreach (FeatureModule::cases() as $module) {
            $field = $module->inputName();
            if (!array_key_exists($field, $input)) {
                $errors[$field] = 'Bitte wählen Sie einen gültigen Ein/Aus-Wert.';

                continue;
            }

            $fallback = $this->isFeatureModuleEnabled($module);
            $enabled[$module->value] = self::normalizeBoolean($input[$field], $fallback, $field, $errors);
        }

        if ([] !== $errors) {
            return $errors;
        }

        foreach (FeatureModule::cases() as $module) {
            $moduleEnabled = $enabled[$module->value] ?? null;
            if (!is_bool($moduleEnabled)) {
                throw new \LogicException('Die geprüfte Moduleinstellung ist unvollständig.');
            }

            $this->setFeatureModuleEnabled($module, $moduleEnabled);
        }
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [];
    }

    public function isFeatureModuleEnabled(FeatureModule $module): bool
    {
        return match ($module) {
            FeatureModule::News => $this->newsModuleEnabled,
            FeatureModule::Series => $this->seriesModuleEnabled,
            FeatureModule::Comments => $this->commentsModuleEnabled,
            FeatureModule::Contact => $this->contactModuleEnabled,
            FeatureModule::Pages => $this->pagesModuleEnabled,
            FeatureModule::Search => $this->searchModuleEnabled,
        };
    }

    private function setFeatureModuleEnabled(FeatureModule $module, bool $enabled): void
    {
        match ($module) {
            FeatureModule::News => $this->newsModuleEnabled = $enabled,
            FeatureModule::Series => $this->seriesModuleEnabled = $enabled,
            FeatureModule::Comments => $this->commentsModuleEnabled = $enabled,
            FeatureModule::Contact => $this->contactModuleEnabled = $enabled,
            FeatureModule::Pages => $this->pagesModuleEnabled = $enabled,
            FeatureModule::Search => $this->searchModuleEnabled = $enabled,
        };
    }

    /** @param array<string, mixed> $input
     * @return array<string, string> field name to safe user-facing error
     */
    public function updateFromInput(array $input): array
    {
        $errors = [];
        $siteName = self::normalizePlainText($input['site_name'] ?? null, 80, false, 'site_name', $errors);
        $tagline = self::normalizePlainText($input['tagline'] ?? null, 160, true, 'tagline', $errors);
        $locale = $input['locale'] ?? null;
        if (!is_string($locale) || !array_key_exists($locale, self::SUPPORTED_LOCALES)) {
            $errors['locale'] = 'Bitte wählen Sie eine installierte Sprache aus.';
            $locale = self::DEFAULT_LOCALE;
        }

        $timezone = $input['timezone'] ?? null;
        if (!is_string($timezone) || !self::isSupportedTimezone($timezone)) {
            $errors['timezone'] = 'Bitte geben Sie eine gültige IANA-Zeitzone ein, zum Beispiel Europe/Berlin oder UTC.';
            $timezone = self::DEFAULT_TIMEZONE;
        }

        $seoDescription = self::normalizePlainText(
            $input['seo_description'] ?? $this->seoDescription,
            320,
            true,
            'seo_description',
            $errors,
        );
        $maintenanceMessage = self::normalizePlainText(
            $input['maintenance_message'] ?? $this->maintenanceMessage,
            320,
            false,
            'maintenance_message',
            $errors,
        );
        $cookieConsentMessage = self::normalizePlainText(
            $input['cookie_consent_message'] ?? $this->cookieConsentMessage,
            320,
            false,
            'cookie_consent_message',
            $errors,
        );
        $googleAnalyticsMeasurementId = $input['google_analytics_measurement_id'] ?? $this->googleAnalyticsMeasurementId;
        if (!is_string($googleAnalyticsMeasurementId)
            || strlen($googleAnalyticsMeasurementId) > 12
            || ('' !== $googleAnalyticsMeasurementId && 1 !== preg_match('/\AG-[A-Z0-9]{10}\z/D', $googleAnalyticsMeasurementId))
        ) {
            $errors['google_analytics_measurement_id'] = 'Bitte gib eine gültige GA4-Mess-ID im Format G-XXXXXXXXXX ein oder lasse das Feld leer.';
            $googleAnalyticsMeasurementId = $this->googleAnalyticsMeasurementId;
        }
        $searchIndexingEnabled = self::normalizeBoolean(
            $input['search_indexing_enabled'] ?? $this->searchIndexingEnabled,
            $this->searchIndexingEnabled,
            'search_indexing_enabled',
            $errors,
        );
        $maintenanceModeEnabled = self::normalizeBoolean(
            $input['maintenance_mode_enabled'] ?? $this->maintenanceModeEnabled,
            $this->maintenanceModeEnabled,
            'maintenance_mode_enabled',
            $errors,
        );
        $cookieConsentEnabled = self::normalizeBoolean(
            $input['cookie_consent_enabled'] ?? $this->cookieConsentEnabled,
            $this->cookieConsentEnabled,
            'cookie_consent_enabled',
            $errors,
        );
        $memberRegistrationEnabled = self::normalizeBoolean(
            $input['member_registration_enabled'] ?? $this->memberRegistrationEnabled,
            $this->memberRegistrationEnabled,
            'member_registration_enabled',
            $errors,
        );
        $googleSocialLoginEnabled = self::normalizeBoolean(
            $input['social_google_enabled'] ?? $this->googleSocialLoginEnabled,
            $this->googleSocialLoginEnabled,
            'social_google_enabled',
            $errors,
        );
        $facebookSocialLoginEnabled = self::normalizeBoolean(
            $input['social_facebook_enabled'] ?? $this->facebookSocialLoginEnabled,
            $this->facebookSocialLoginEnabled,
            'social_facebook_enabled',
            $errors,
        );
        $twitterSocialLoginEnabled = self::normalizeBoolean(
            $input['social_twitter_enabled'] ?? $this->twitterSocialLoginEnabled,
            $this->twitterSocialLoginEnabled,
            'social_twitter_enabled',
            $errors,
        );

        $commentProviderValue = $input['comment_provider'] ?? $this->commentProvider->value;
        $commentProvider = is_string($commentProviderValue) ? CommentProvider::tryFrom($commentProviderValue) : null;
        if (null === $commentProvider) {
            $errors['comment_provider'] = 'Bitte wähle einen unterstützten Kommentar-Anbieter.';
        }

        $facebookCommentsAppIdValue = $input['facebook_comments_app_id'] ?? $this->facebookCommentsAppId;
        if (!is_string($facebookCommentsAppIdValue)
            || strlen($facebookCommentsAppIdValue) > 32
            || ('' !== trim($facebookCommentsAppIdValue) && 1 !== preg_match('/\A[0-9]{5,32}\z/D', trim($facebookCommentsAppIdValue)))
        ) {
            $errors['facebook_comments_app_id'] = 'Bitte gib eine gültige numerische Facebook-App-ID ein oder lasse das Feld leer.';
            $facebookCommentsAppId = '';
        } else {
            $facebookCommentsAppId = trim($facebookCommentsAppIdValue);
        }

        $disqusCommentsShortnameValue = $input['disqus_comments_shortname'] ?? $this->disqusCommentsShortname;
        if (!is_string($disqusCommentsShortnameValue)
            || strlen($disqusCommentsShortnameValue) > 63
            || ('' !== trim($disqusCommentsShortnameValue)
                && 1 !== preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', trim($disqusCommentsShortnameValue)))
        ) {
            $errors['disqus_comments_shortname'] = 'Bitte gib einen gültigen Disqus-Shortname ein oder lasse das Feld leer.';
            $disqusCommentsShortname = '';
        } else {
            $disqusCommentsShortname = trim($disqusCommentsShortnameValue);
        }

        if (CommentProvider::Facebook === $commentProvider && '' === $facebookCommentsAppId) {
            $errors['facebook_comments_app_id'] = 'Für Facebook-Kommentare ist eine gültige App-ID erforderlich.';
        }
        if (CommentProvider::Disqus === $commentProvider && '' === $disqusCommentsShortname) {
            $errors['disqus_comments_shortname'] = 'Für Disqus-Kommentare ist ein gültiger Shortname erforderlich.';
        }
        if (null !== $commentProvider && CommentProvider::Native !== $commentProvider && !$cookieConsentEnabled) {
            $errors['comment_provider'] = 'Externe Kommentar-Anbieter benötigen die aktivierte Einwilligungseinstellung.';
        }

        if ([] !== $errors) {
            return $errors;
        }

        $this->siteName = $siteName;
        $this->tagline = $tagline;
        $this->defaultLocale = $locale;
        $this->timezone = $timezone;
        $this->seoDescription = $seoDescription;
        $this->searchIndexingEnabled = $searchIndexingEnabled;
        $this->maintenanceModeEnabled = $maintenanceModeEnabled;
        $this->maintenanceMessage = $maintenanceMessage;
        $this->cookieConsentEnabled = $cookieConsentEnabled;
        $this->cookieConsentMessage = $cookieConsentMessage;
        $this->googleAnalyticsMeasurementId = $googleAnalyticsMeasurementId;
        $this->memberRegistrationEnabled = $memberRegistrationEnabled;
        $this->googleSocialLoginEnabled = $googleSocialLoginEnabled;
        $this->facebookSocialLoginEnabled = $facebookSocialLoginEnabled;
        $this->twitterSocialLoginEnabled = $twitterSocialLoginEnabled;
        $this->commentProvider = $commentProvider ?? $this->commentProvider;
        $this->facebookCommentsAppId = $facebookCommentsAppId;
        $this->disqusCommentsShortname = $disqusCommentsShortname;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [];
    }

    public static function isSupportedTimezone(string $timezone): bool
    {
        self::$availableTimezones ??= array_fill_keys(
            [...\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), 'UTC'],
            true,
        );

        return isset(self::$availableTimezones[$timezone]);
    }

    /** @param array<string, string> $errors */
    private static function normalizePlainText(
        mixed $value,
        int $maxLength,
        bool $allowEmpty,
        string $field,
        array &$errors,
    ): string {
        if (!is_string($value) || 1 !== preg_match('//u', $value)) {
            $errors[$field] = 'Bitte geben Sie gültigen Text ein.';

            return '';
        }

        if (1 === preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            $errors[$field] = 'Steuerzeichen sind nicht zulässig.';

            return '';
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));
        if (!is_string($normalized)) {
            $errors[$field] = 'Bitte geben Sie gültigen Text ein.';

            return '';
        }

        $length = mb_strlen($normalized, 'UTF-8');
        if ((!$allowEmpty && 0 === $length) || $length > $maxLength) {
            $errors[$field] = $allowEmpty
                ? sprintf('Bitte geben Sie höchstens %d Zeichen ein.', $maxLength)
                : sprintf('Bitte geben Sie zwischen 1 und %d Zeichen ein.', $maxLength);

            return '';
        }

        return $normalized;
    }

    /** @param array<string, string> $errors */
    private static function normalizeBoolean(mixed $value, bool $fallback, string $field, array &$errors): bool
    {
        if (true === $value || 1 === $value || '1' === $value) {
            return true;
        }

        if (false === $value || 0 === $value || '0' === $value) {
            return false;
        }

        $errors[$field] = 'Bitte wählen Sie einen gültigen Ein/Aus-Wert.';

        return $fallback;
    }

    /** @param array<string, string> $errors */
    private static function boundedInteger(mixed $value, int $minimum, int $maximum, string $field, array &$errors): int
    {
        $parsed = self::parseNonNegativeInteger($value);
        if (null === $parsed || $parsed < $minimum || $parsed > $maximum) {
            $errors[$field] = sprintf('Bitte wähle einen Wert zwischen %d und %d.', $minimum, $maximum);

            return $minimum;
        }

        return $parsed;
    }

    /** @param list<int> $allowedValues
     * @param array<string, string> $errors
     */
    private static function allowedInteger(mixed $value, array $allowedValues, string $field, array &$errors): int
    {
        $parsed = self::parseNonNegativeInteger($value);
        if (null === $parsed || !in_array($parsed, $allowedValues, true)) {
            $errors[$field] = 'Bitte wähle einen der angebotenen Werte.';

            return $allowedValues[0];
        }

        return $parsed;
    }

    private static function parseNonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || 1 !== preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/D', $value)) {
            return null;
        }

        return (int) $value;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSiteName(): string
    {
        return $this->siteName;
    }

    public function getSiteLogoObjectId(): ?string
    {
        return $this->siteLogoObjectId;
    }

    public function getSiteLogoMimeType(): ?string
    {
        return $this->siteLogoMimeType;
    }

    public function getSiteLogoSize(): ?int
    {
        return $this->siteLogoSize;
    }

    public function getSiteLogoSha256(): ?string
    {
        return $this->siteLogoSha256;
    }

    public function attachSiteLogo(string $objectId, string $mimeType, int $size, string $sha256): void
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)
            || 'image/png' !== $mimeType
            || $size < 1
            || $size > 524288
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $sha256)
        ) {
            throw new \InvalidArgumentException('Die Logo-Dateimetadaten sind ungültig.');
        }

        $this->siteLogoObjectId = $objectId;
        $this->siteLogoMimeType = $mimeType;
        $this->siteLogoSize = $size;
        $this->siteLogoSha256 = $sha256;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function removeSiteLogo(): ?string
    {
        $previousObjectId = $this->siteLogoObjectId;
        $this->siteLogoObjectId = null;
        $this->siteLogoMimeType = null;
        $this->siteLogoSize = null;
        $this->siteLogoSha256 = null;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $previousObjectId;
    }

    public function getTagline(): string
    {
        return $this->tagline;
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getSeoDescription(): string
    {
        return $this->seoDescription;
    }

    public function isSearchIndexingEnabled(): bool
    {
        return $this->searchIndexingEnabled;
    }

    public function isMaintenanceModeEnabled(): bool
    {
        return $this->maintenanceModeEnabled;
    }

    public function getMaintenanceMessage(): string
    {
        return $this->maintenanceMessage;
    }

    public function isCookieConsentEnabled(): bool
    {
        return $this->cookieConsentEnabled;
    }

    public function getCookieConsentMessage(): string
    {
        return $this->cookieConsentMessage;
    }

    public function getGoogleAnalyticsMeasurementId(): string
    {
        return $this->googleAnalyticsMeasurementId;
    }

    public function isPlayerAutoplayEnabled(): bool
    {
        return $this->playerAutoplayEnabled && $this->playerAutoplayMuted;
    }

    public function getVideoPlayerEngine(): VideoPlayerEngine
    {
        return $this->videoPlayerEngine;
    }

    public function getProviderThumbnailResolution(): VideoThumbnailResolution
    {
        return $this->providerThumbnailResolution;
    }

    public function getVideoPreloadMode(): VideoPreloadMode
    {
        return $this->videoPreloadMode;
    }

    public function getVideoPlayerAspectRatio(): VideoPlayerAspectRatio
    {
        return $this->videoPlayerAspectRatio;
    }

    public function getVideoPlayerMaxWidth(): VideoPlayerMaxWidth
    {
        return $this->videoPlayerMaxWidth;
    }

    public function getVideoPlayerEmbedMaxWidth(): VideoPlayerMaxWidth
    {
        return $this->videoPlayerEmbedMaxWidth;
    }

    public function getVideoPlayerHomeMaxWidth(): VideoPlayerMaxWidth
    {
        return $this->videoPlayerHomeMaxWidth;
    }

    public function isPlayerFeaturedAutoplayEnabled(): bool
    {
        return $this->playerFeaturedAutoplayEnabled;
    }

    public function getAutoFeatureViewThreshold(): int
    {
        return $this->autoFeatureViewThreshold;
    }

    public function getVideoPlayerColorScheme(): VideoPlayerColorScheme
    {
        return $this->videoPlayerColorScheme;
    }

    public function isVideoPlayerWatermarkEnabled(): bool
    {
        return $this->videoPlayerWatermarkEnabled;
    }

    public function getVideoPlayerWatermarkObjectId(): ?string
    {
        return $this->videoPlayerWatermarkObjectId;
    }

    public function getVideoPlayerWatermarkMimeType(): ?string
    {
        return $this->videoPlayerWatermarkMimeType;
    }

    public function getVideoPlayerWatermarkSize(): ?int
    {
        return $this->videoPlayerWatermarkSize;
    }

    public function getVideoPlayerWatermarkSha256(): ?string
    {
        return $this->videoPlayerWatermarkSha256;
    }

    public function attachVideoPlayerWatermark(string $objectId, string $mimeType, int $size, string $sha256): void
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)
            || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $size < 1
            || $size > 2097152
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $sha256)
        ) {
            throw new \InvalidArgumentException('Die Wasserzeichen-Dateimetadaten sind ungültig.');
        }

        $this->videoPlayerWatermarkObjectId = $objectId;
        $this->videoPlayerWatermarkMimeType = $mimeType;
        $this->videoPlayerWatermarkSize = $size;
        $this->videoPlayerWatermarkSha256 = $sha256;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function removeVideoPlayerWatermark(): ?string
    {
        $previousObjectId = $this->videoPlayerWatermarkObjectId;
        $this->videoPlayerWatermarkObjectId = null;
        $this->videoPlayerWatermarkMimeType = null;
        $this->videoPlayerWatermarkSize = null;
        $this->videoPlayerWatermarkSha256 = null;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $previousObjectId;
    }

    public function isPublicVideoEmbeddingEnabled(): bool
    {
        return $this->publicVideoEmbeddingEnabled;
    }

    public function isPlayerAutoplayMuted(): bool
    {
        return $this->playerAutoplayMuted;
    }

    public function isDefaultCommentsEnabled(): bool
    {
        return $this->defaultCommentsEnabled;
    }

    public function isGuestCommentsEnabled(): bool
    {
        return $this->guestCommentsEnabled;
    }

    public function getCommentDefaultSort(): CommentSortOrder
    {
        return $this->commentDefaultSort;
    }

    public function getCommentRatingHideThreshold(): int
    {
        return $this->commentRatingHideThreshold;
    }

    public function getCommentsPerPage(): int
    {
        return $this->commentsPerPage;
    }

    public function getCommentProvider(): CommentProvider
    {
        return $this->commentProvider;
    }

    public function getFacebookCommentsAppId(): string
    {
        return $this->facebookCommentsAppId;
    }

    public function getDisqusCommentsShortname(): string
    {
        return $this->disqusCommentsShortname;
    }

    public function isPublicVideoSubmissionsEnabled(): bool
    {
        return $this->publicVideoSubmissionsEnabled;
    }

    public function getHomepageFeaturedLimit(): int
    {
        return $this->homepageFeaturedLimit;
    }

    public function getHomepageVideoLimit(): int
    {
        return $this->homepageVideoLimit;
    }

    public function getHomepageNewsLimit(): int
    {
        return $this->homepageNewsLimit;
    }

    public function getPublicVideoPageLimit(): int
    {
        return $this->publicVideoPageLimit;
    }

    public function getPopularVideoSort(): VideoPopularitySort
    {
        return $this->popularVideoSort;
    }

    public function getPopularVideoPeriodDays(): int
    {
        return $this->popularVideoPeriodDays;
    }

    public function getCurrentlyWatchingLimit(): int
    {
        return $this->currentlyWatchingLimit;
    }

    public function isPublicStatisticsEnabled(): bool
    {
        return $this->publicStatisticsEnabled;
    }

    public function isTagCloudEnabled(): bool
    {
        return $this->tagCloudEnabled && $this->tagCloudLimit > 0;
    }

    public function getTagCloudLimit(): int
    {
        return $this->tagCloudLimit;
    }

    public function isTagCloudShuffleEnabled(): bool
    {
        return $this->tagCloudShuffle;
    }

    public function getRelatedVideoLimit(): int
    {
        return $this->relatedVideoLimit;
    }

    public function getTopRatedVideoLimit(): int
    {
        return $this->topRatedVideoLimit;
    }

    public function isVideoShareWidgetEnabled(): bool
    {
        return $this->videoShareWidgetEnabled;
    }

    public function isMemberRegistrationEnabled(): bool
    {
        return $this->memberRegistrationEnabled;
    }

    public function isGoogleSocialLoginEnabled(): bool
    {
        return $this->googleSocialLoginEnabled;
    }

    public function isFacebookSocialLoginEnabled(): bool
    {
        return $this->facebookSocialLoginEnabled;
    }

    public function isTwitterSocialLoginEnabled(): bool
    {
        return $this->twitterSocialLoginEnabled;
    }

    /** @param array<string, mixed> $input
     * @return array<string, string> field name to safe user-facing error
     */
    public function updateEmailSettings(array $input): array
    {
        foreach ($input as $field => $_value) {
            if ('from_address' !== $field) {
                return ['form' => 'Die Anfrage enthält eine unbekannte E-Mail-Einstellung.'];
            }
        }

        $value = $input['from_address'] ?? null;
        if (!is_string($value)
            || strlen($value) > 512
            || 1 !== preg_match('//u', $value)
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            return ['from_address' => 'Bitte geben Sie eine gültige Absenderadresse ein.'];
        }

        $address = trim($value);
        if (strlen($address) > 254
            || ('' !== $address && false === filter_var($address, FILTER_VALIDATE_EMAIL))
        ) {
            return ['from_address' => 'Bitte geben Sie eine gültige E-Mail-Adresse mit höchstens 254 Zeichen ein.'];
        }

        $this->emailFromAddress = $address;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [];
    }

    /** @param array<string, mixed> $input
     * @return array<string, string> field name to safe user-facing error
     */
    public function updateAppearance(array $input): array
    {
        $knownFields = [
            'theme' => true,
            'layout' => true,
            'primary_color' => true,
            'accent_color' => true,
            'heading_color' => true,
            'content_background_color' => true,
            'content_text_color' => true,
            'body_background_color' => true,
            'header_background_color' => true,
            'header_pane_color' => true,
            'navigation_background_color' => true,
            'navigation_link_color' => true,
            'content_link_color' => true,
            'video_link_color' => true,
            'site_title_color' => true,
            'footer_background_color' => true,
            'footer_link_color' => true,
            'content_width' => true,
        ];
        foreach ($input as $field => $_value) {
            if (!isset($knownFields[$field])) {
                return ['form' => 'Die Anfrage enthält eine unbekannte Layout- oder Farbeinstellung.'];
            }
        }

        /** @var array<string, string> $errors */
        $errors = [];
        $themeValue = $input['theme'] ?? null;
        $theme = is_string($themeValue) ? SiteTheme::tryFrom($themeValue) : null;
        if (null === $theme) {
            $errors['theme'] = 'Bitte wählen Sie ein unterstütztes Theme.';
        }

        $layoutValue = $input['layout'] ?? null;
        $layout = is_string($layoutValue) ? SiteLayout::tryFrom($layoutValue) : null;
        if (null === $layout) {
            $errors['layout'] = 'Bitte wählen Sie ein unterstütztes Layout.';
        }

        $primaryColor = self::normalizeThemeColor(
            $input['primary_color'] ?? null,
            self::DEFAULT_PRIMARY_COLOR,
            4.5,
            'primary_color',
            $errors,
        );
        $accentColor = self::normalizeThemeColor(
            $input['accent_color'] ?? null,
            self::DEFAULT_ACCENT_COLOR,
            3.0,
            'accent_color',
            $errors,
        );

        $headingColor = self::normalizeAppearanceColor(
            $input['heading_color'] ?? $this->headingColor,
            self::DEFAULT_HEADING_COLOR,
            'heading_color',
            $errors,
        );
        $contentBackgroundColor = self::normalizeAppearanceColor(
            $input['content_background_color'] ?? $this->contentBackgroundColor,
            self::DEFAULT_CONTENT_BACKGROUND_COLOR,
            'content_background_color',
            $errors,
        );
        $contentTextColor = self::normalizeAppearanceColor(
            $input['content_text_color'] ?? $this->contentTextColor,
            self::DEFAULT_CONTENT_TEXT_COLOR,
            'content_text_color',
            $errors,
        );
        $bodyBackgroundColor = self::normalizeAppearanceColor(
            $input['body_background_color'] ?? $this->bodyBackgroundColor,
            self::DEFAULT_BODY_BACKGROUND_COLOR,
            'body_background_color',
            $errors,
        );
        $headerBackgroundColor = self::normalizeAppearanceColor(
            $input['header_background_color'] ?? $this->headerBackgroundColor,
            self::DEFAULT_HEADER_BACKGROUND_COLOR,
            'header_background_color',
            $errors,
        );
        $headerPaneColor = self::normalizeAppearanceColor(
            $input['header_pane_color'] ?? $this->headerPaneColor,
            self::DEFAULT_HEADER_PANE_COLOR,
            'header_pane_color',
            $errors,
        );
        $navigationBackgroundColor = self::normalizeAppearanceColor(
            $input['navigation_background_color'] ?? $this->navigationBackgroundColor,
            self::DEFAULT_NAVIGATION_BACKGROUND_COLOR,
            'navigation_background_color',
            $errors,
        );
        $navigationLinkColor = self::normalizeAppearanceColor(
            $input['navigation_link_color'] ?? $this->navigationLinkColor,
            self::DEFAULT_NAVIGATION_LINK_COLOR,
            'navigation_link_color',
            $errors,
        );
        $contentLinkColor = self::normalizeAppearanceColor(
            $input['content_link_color'] ?? $this->contentLinkColor,
            self::DEFAULT_CONTENT_LINK_COLOR,
            'content_link_color',
            $errors,
        );
        $videoLinkColor = self::normalizeAppearanceColor(
            $input['video_link_color'] ?? $this->videoLinkColor,
            self::DEFAULT_VIDEO_LINK_COLOR,
            'video_link_color',
            $errors,
        );
        $siteTitleColor = self::normalizeAppearanceColor(
            $input['site_title_color'] ?? $this->siteTitleColor,
            self::DEFAULT_SITE_TITLE_COLOR,
            'site_title_color',
            $errors,
        );
        $footerBackgroundColor = self::normalizeAppearanceColor(
            $input['footer_background_color'] ?? $this->footerBackgroundColor,
            self::DEFAULT_FOOTER_BACKGROUND_COLOR,
            'footer_background_color',
            $errors,
        );
        $footerLinkColor = self::normalizeAppearanceColor(
            $input['footer_link_color'] ?? $this->footerLinkColor,
            self::DEFAULT_FOOTER_LINK_COLOR,
            'footer_link_color',
            $errors,
        );
        $contentWidth = self::normalizeContentWidth($input['content_width'] ?? $this->contentWidth, $errors);

        self::requireAppearanceContrast($headingColor, $contentBackgroundColor, 3.0, 'heading_color', $errors);
        self::requireAppearanceContrast($headingColor, $bodyBackgroundColor, 3.0, 'heading_color', $errors);
        self::requireAppearanceContrast($contentTextColor, $contentBackgroundColor, 4.5, 'content_text_color', $errors);
        self::requireAppearanceContrast($contentTextColor, $bodyBackgroundColor, 4.5, 'content_text_color', $errors);
        self::requireAppearanceContrast($contentLinkColor, $contentBackgroundColor, 4.5, 'content_link_color', $errors);
        self::requireAppearanceContrast($contentLinkColor, $bodyBackgroundColor, 4.5, 'content_link_color', $errors);
        self::requireAppearanceContrast($videoLinkColor, $contentBackgroundColor, 4.5, 'video_link_color', $errors);
        self::requireAppearanceContrast($headerPaneColor, $headerBackgroundColor, 4.5, 'header_pane_color', $errors);
        self::requireAppearanceContrast($siteTitleColor, $headerBackgroundColor, 4.5, 'site_title_color', $errors);
        self::requireAppearanceContrast($navigationLinkColor, $navigationBackgroundColor, 4.5, 'navigation_link_color', $errors);
        self::requireAppearanceContrast($footerLinkColor, $footerBackgroundColor, 4.5, 'footer_link_color', $errors);
        self::requireAppearanceContrast($accentColor, $contentBackgroundColor, 3.0, 'accent_color', $errors);
        self::requireAppearanceContrast($accentColor, $bodyBackgroundColor, 3.0, 'accent_color', $errors);
        self::requireAppearanceContrast($accentColor, $navigationBackgroundColor, 3.0, 'accent_color', $errors);
        self::requireAppearanceContrast($accentColor, $footerBackgroundColor, 3.0, 'accent_color', $errors);

        if ([] !== $errors) {
            return $errors;
        }

        if (!$theme instanceof SiteTheme || !$layout instanceof SiteLayout) {
            throw new \LogicException('Die geprüften Theme- und Layoutwerte sind unvollständig.');
        }

        $this->publicTheme = $theme->value;
        $this->publicLayout = $layout->value;
        $this->primaryColor = $primaryColor;
        $this->accentColor = $accentColor;
        $this->headingColor = $headingColor;
        $this->contentBackgroundColor = $contentBackgroundColor;
        $this->contentTextColor = $contentTextColor;
        $this->bodyBackgroundColor = $bodyBackgroundColor;
        $this->headerBackgroundColor = $headerBackgroundColor;
        $this->headerPaneColor = $headerPaneColor;
        $this->navigationBackgroundColor = $navigationBackgroundColor;
        $this->navigationLinkColor = $navigationLinkColor;
        $this->contentLinkColor = $contentLinkColor;
        $this->videoLinkColor = $videoLinkColor;
        $this->siteTitleColor = $siteTitleColor;
        $this->footerBackgroundColor = $footerBackgroundColor;
        $this->footerLinkColor = $footerLinkColor;
        $this->contentWidth = $contentWidth;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [];
    }

    /** @param array<string, string> $errors */
    private static function normalizeThemeColor(
        mixed $value,
        string $fallback,
        float $minimumContrast,
        string $field,
        array &$errors,
    ): string {
        if (!is_string($value) || !self::isHexColor($value)) {
            $errors[$field] = 'Bitte wählen Sie eine gültige sechsstellige Hex-Farbe.';

            return $fallback;
        }

        $color = strtoupper($value);
        if (self::contrastAgainstWhite($color) < $minimumContrast) {
            $errors[$field] = 'Die Farbe ist zu hell und bietet auf einer hellen Fläche nicht genügend Kontrast.';

            return $fallback;
        }

        return $color;
    }

    private static function isHexColor(string $value): bool
    {
        return 1 === preg_match('/\A#[a-fA-F0-9]{6}\z/', $value);
    }

    /** @param array<string, string> $errors */
    private static function normalizeAppearanceColor(
        mixed $value,
        string $fallback,
        string $field,
        array &$errors,
    ): string {
        if (!is_string($value) || !self::isHexColor($value)) {
            $errors[$field] = 'Bitte wählen Sie eine gültige sechsstellige Hex-Farbe.';

            return $fallback;
        }

        return strtoupper($value);
    }

    /** @param array<string, string> $errors */
    private static function normalizeContentWidth(mixed $value, array &$errors): int
    {
        if ((!is_int($value) && !is_string($value))
            || 1 !== preg_match('/\A[0-9]{3,4}\z/', (string) $value)
        ) {
            $errors['content_width'] = 'Die Inhaltsbreite muss eine ganze Pixelzahl zwischen 640 und 1920 sein.';

            return self::DEFAULT_CONTENT_WIDTH;
        }

        $width = (int) $value;
        if ($width < self::MIN_CONTENT_WIDTH || $width > self::MAX_CONTENT_WIDTH) {
            $errors['content_width'] = 'Die Inhaltsbreite muss eine ganze Pixelzahl zwischen 640 und 1920 sein.';

            return self::DEFAULT_CONTENT_WIDTH;
        }

        return $width;
    }

    /** @param array<string, string> $errors */
    private static function requireAppearanceContrast(
        string $foreground,
        string $background,
        float $minimum,
        string $field,
        array &$errors,
    ): void {
        if (self::contrastAgainst($foreground, $background) < $minimum) {
            $errors[$field] = 'Diese Farbe bietet auf dem gewählten Hintergrund nicht genügend Kontrast.';
        }
    }

    private static function contrastAgainst(string $foreground, string $background): float
    {
        $foregroundLuminance = self::relativeLuminance($foreground);
        $backgroundLuminance = self::relativeLuminance($background);
        $lighter = max($foregroundLuminance, $backgroundLuminance);
        $darker = min($foregroundLuminance, $backgroundLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function relativeLuminance(string $color): float
    {
        $red = self::linearColorChannel(hexdec(substr($color, 1, 2)));
        $green = self::linearColorChannel(hexdec(substr($color, 3, 2)));
        $blue = self::linearColorChannel(hexdec(substr($color, 5, 2)));

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }

    private static function contrastAgainstWhite(string $hexColor): float
    {
        $red = self::linearColorChannel(hexdec(substr($hexColor, 1, 2)));
        $green = self::linearColorChannel(hexdec(substr($hexColor, 3, 2)));
        $blue = self::linearColorChannel(hexdec(substr($hexColor, 5, 2)));
        $luminance = 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;

        return 1.05 / ($luminance + 0.05);
    }

    private static function linearColorChannel(int|float $channel): float
    {
        $normalized = $channel / 255;

        return $normalized <= 0.04045
            ? $normalized / 12.92
            : (($normalized + 0.055) / 1.055) ** 2.4;
    }

    public function getEmailFromAddress(): string
    {
        return $this->emailFromAddress;
    }

    public function getPublicTheme(): SiteTheme
    {
        return SiteTheme::tryFrom($this->publicTheme) ?? SiteTheme::Arcanorum;
    }

    public function getPublicLayout(): SiteLayout
    {
        return SiteLayout::tryFrom($this->publicLayout) ?? SiteLayout::Grid;
    }

    public function getPrimaryColor(): string
    {
        if (!self::isHexColor($this->primaryColor) || self::contrastAgainstWhite($this->primaryColor) < 4.5) {
            return self::DEFAULT_PRIMARY_COLOR;
        }

        return strtoupper($this->primaryColor);
    }

    public function getAccentColor(): string
    {
        if (!self::isHexColor($this->accentColor) || self::contrastAgainstWhite($this->accentColor) < 3.0) {
            return self::DEFAULT_ACCENT_COLOR;
        }

        return strtoupper($this->accentColor);
    }

    public function getHeadingColor(): string
    {
        return $this->safeAppearanceColor($this->headingColor, self::DEFAULT_HEADING_COLOR);
    }

    public function getContentBackgroundColor(): string
    {
        return $this->safeAppearanceColor($this->contentBackgroundColor, self::DEFAULT_CONTENT_BACKGROUND_COLOR);
    }

    public function getContentTextColor(): string
    {
        return $this->safeAppearanceColor($this->contentTextColor, self::DEFAULT_CONTENT_TEXT_COLOR);
    }

    public function getBodyBackgroundColor(): string
    {
        return $this->safeAppearanceColor($this->bodyBackgroundColor, self::DEFAULT_BODY_BACKGROUND_COLOR);
    }

    public function getHeaderBackgroundColor(): string
    {
        return $this->safeAppearanceColor($this->headerBackgroundColor, self::DEFAULT_HEADER_BACKGROUND_COLOR);
    }

    public function getHeaderPaneColor(): string
    {
        return $this->safeAppearanceColor($this->headerPaneColor, self::DEFAULT_HEADER_PANE_COLOR);
    }

    public function getNavigationBackgroundColor(): string
    {
        return $this->safeAppearanceColor($this->navigationBackgroundColor, self::DEFAULT_NAVIGATION_BACKGROUND_COLOR);
    }

    public function getNavigationLinkColor(): string
    {
        return $this->safeAppearanceColor($this->navigationLinkColor, self::DEFAULT_NAVIGATION_LINK_COLOR);
    }

    public function getContentLinkColor(): string
    {
        return $this->safeAppearanceColor($this->contentLinkColor, self::DEFAULT_CONTENT_LINK_COLOR);
    }

    public function getVideoLinkColor(): string
    {
        return $this->safeAppearanceColor($this->videoLinkColor, self::DEFAULT_VIDEO_LINK_COLOR);
    }

    public function getSiteTitleColor(): string
    {
        return $this->safeAppearanceColor($this->siteTitleColor, self::DEFAULT_SITE_TITLE_COLOR);
    }

    public function getFooterBackgroundColor(): string
    {
        return $this->safeAppearanceColor($this->footerBackgroundColor, self::DEFAULT_FOOTER_BACKGROUND_COLOR);
    }

    public function getFooterLinkColor(): string
    {
        return $this->safeAppearanceColor($this->footerLinkColor, self::DEFAULT_FOOTER_LINK_COLOR);
    }

    public function getContentWidth(): int
    {
        return $this->contentWidth >= self::MIN_CONTENT_WIDTH && $this->contentWidth <= self::MAX_CONTENT_WIDTH
            ? $this->contentWidth
            : self::DEFAULT_CONTENT_WIDTH;
    }

    private function safeAppearanceColor(string $value, string $fallback): string
    {
        return self::isHexColor($value) ? strtoupper($value) : $fallback;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
