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
    ];
    public const string DEFAULT_TIMEZONE = 'Europe/Berlin';
    public const string DEFAULT_MAINTENANCE_MESSAGE = 'Die Website wird gewartet. Bitte versuchen Sie es später erneut.';
    public const string DEFAULT_COOKIE_CONSENT_MESSAGE = 'Wir verwenden notwendige Cookies für Anmeldung und Sicherheit. Derzeit setzen wir keine Analyse- oder Werbecookies.';
    public const string DEFAULT_PRIMARY_COLOR = '#24365E';
    public const string DEFAULT_ACCENT_COLOR = '#2F6E68';

    /** @var array<string, true>|null */
    private static ?array $availableTimezones = null;

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(name: 'site_name', length: 80)]
    private string $siteName;

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

    #[ORM\Column(name: 'video_preload_mode', length: 16, enumType: VideoPreloadMode::class, options: ['default' => 'metadata'])]
    private VideoPreloadMode $videoPreloadMode = VideoPreloadMode::Metadata;

    #[ORM\Column(name: 'player_autoplay_muted', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $playerAutoplayMuted;

    #[ORM\Column(name: 'default_comments_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $defaultCommentsEnabled;

    #[ORM\Column(name: 'public_video_submissions_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $publicVideoSubmissionsEnabled;

    #[ORM\Column(name: 'member_registration_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $memberRegistrationEnabled = true;

    #[ORM\Column(name: 'social_google_enabled', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $googleSocialLoginEnabled = false;

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
        $this->publicVideoSubmissionsEnabled = true;
        $this->memberRegistrationEnabled = true;
        $this->googleSocialLoginEnabled = false;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $input
     * @return array<string, string> field name to safe user-facing error
     */
    public function updateContentOptions(array $input): array
    {
        $knownFields = [
            'video_player_engine' => true,
            'video_preload_mode' => true,
            'player_autoplay_enabled' => true,
            'player_autoplay_muted' => true,
            'default_comments_enabled' => true,
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
        $videoPreloadModeValue = $input['video_preload_mode'] ?? $this->videoPreloadMode->value;
        $videoPreloadMode = is_string($videoPreloadModeValue)
            ? VideoPreloadMode::tryFrom($videoPreloadModeValue)
            : null;
        if (null === $videoPreloadMode) {
            $errors['video_preload_mode'] = 'Bitte wähle eine unterstützte Einstellung für das Vorladen.';
        }
        $autoplayEnabled = self::normalizeBoolean(
            $input['player_autoplay_enabled'] ?? null,
            $this->playerAutoplayEnabled,
            'player_autoplay_enabled',
            $errors,
        );
        $autoplayMuted = self::normalizeBoolean(
            $input['player_autoplay_muted'] ?? null,
            $this->playerAutoplayMuted,
            'player_autoplay_muted',
            $errors,
        );
        $defaultCommentsEnabled = self::normalizeBoolean(
            $input['default_comments_enabled'] ?? null,
            $this->defaultCommentsEnabled,
            'default_comments_enabled',
            $errors,
        );
        $videoSubmissionsEnabled = self::normalizeBoolean(
            $input['public_video_submissions_enabled'] ?? null,
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
        $this->videoPreloadMode = $videoPreloadMode ?? $this->videoPreloadMode;
        $this->playerAutoplayEnabled = $autoplayEnabled;
        $this->playerAutoplayMuted = $autoplayMuted;
        $this->defaultCommentsEnabled = $defaultCommentsEnabled;
        $this->publicVideoSubmissionsEnabled = $videoSubmissionsEnabled;
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
        $this->memberRegistrationEnabled = $memberRegistrationEnabled;
        $this->googleSocialLoginEnabled = $googleSocialLoginEnabled;
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

    public function getId(): int
    {
        return $this->id;
    }

    public function getSiteName(): string
    {
        return $this->siteName;
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

    public function isPlayerAutoplayEnabled(): bool
    {
        return $this->playerAutoplayEnabled && $this->playerAutoplayMuted;
    }

    public function getVideoPlayerEngine(): VideoPlayerEngine
    {
        return $this->videoPlayerEngine;
    }

    public function getVideoPreloadMode(): VideoPreloadMode
    {
        return $this->videoPreloadMode;
    }

    public function isPlayerAutoplayMuted(): bool
    {
        return $this->playerAutoplayMuted;
    }

    public function isDefaultCommentsEnabled(): bool
    {
        return $this->defaultCommentsEnabled;
    }

    public function isPublicVideoSubmissionsEnabled(): bool
    {
        return $this->publicVideoSubmissionsEnabled;
    }

    public function isMemberRegistrationEnabled(): bool
    {
        return $this->memberRegistrationEnabled;
    }

    public function isGoogleSocialLoginEnabled(): bool
    {
        return $this->googleSocialLoginEnabled;
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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
