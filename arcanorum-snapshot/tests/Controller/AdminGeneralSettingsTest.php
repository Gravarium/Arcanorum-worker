<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\MemberAccount;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\SiteSettings;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Settings\Service\BuiltInModuleCatalog;
use App\Module\Settings\Service\ModuleAvailability;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Platform\Hosting\CapabilityProbeInterface;
use App\Platform\Hosting\HostingProfileDetector;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Twig\Environment;
use Twig\Extension\CoreExtension;

final class AdminGeneralSettingsTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $testKey = base64_encode(str_repeat('S', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$testKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;

        parent::setUpBeforeClass();
    }

    public function testAdministratorCanSaveSettingsThatAffectPublicPagesAndDateRendering(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'general-settings-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $email, 'GeneralSettings!2026', [AdminRole::Administrator->value]);
            $this->submitLogin($client, $email, 'GeneralSettings!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/settings/general');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorExists('form[action="/admin/settings/general"]');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            self::assertNotSame('', $token);

            $client->request('POST', '/admin/settings/general', [
                'token' => $token,
                'site_name' => '<script>alert(1)</script>',
                'tagline' => 'Community News',
                'locale' => 'de',
                'timezone' => 'America/Los_Angeles',
                'seo_description' => '<script>alert(1)</script>',
                'search_indexing_enabled' => '0',
                'maintenance_mode_enabled' => '0',
                'maintenance_message' => SiteSettings::DEFAULT_MAINTENANCE_MESSAGE,
                'cookie_consent_enabled' => '0',
                'cookie_consent_message' => SiteSettings::DEFAULT_COOKIE_CONSENT_MESSAGE,
                'google_analytics_measurement_id' => 'G-ABC1234567',
            ]);
            self::assertResponseRedirects('/admin/settings/general');

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            $home = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('<html lang="de" dir="ltr" data-public-theme="arcanorum" data-public-layout="grid" data-public-view="site">', $home);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $home);
            self::assertStringContainsString('Community News', $home);
            self::assertStringContainsString('<meta name="description" content="&lt;script&gt;alert(1)&lt;/script&gt;">', $home);
            self::assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $home);
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertStringNotContainsString('<script>alert(1)</script>', $home);

            $client->request('GET', '/news');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Community News', (string) $client->getResponse()->getContent());
            self::assertSelectorTextContains('.public-brand-tagline', 'Community News');

            $client->request('GET', '/admin/settings/general');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.admin-sidebar .brand-tagline', 'Community News');

            $client->request('GET', '/robots.txt');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString("User-agent: *\nDisallow: /", (string) $client->getResponse()->getContent());

            $client->request('GET', '/news.rss');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; News', (string) $client->getResponse()->getContent());

            $client->request('GET', '/videos.rss');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Videos', (string) $client->getResponse()->getContent());

            $twig = $client->getContainer()->get(Environment::class);
            self::assertInstanceOf(Environment::class, $twig);
            self::assertSame('America/Los_Angeles', $twig->getExtension(CoreExtension::class)->getTimezone()->getName());
            self::assertSame('2026-01-15 00:00', $twig->createTemplate(
                '{{ sample|date("Y-m-d H:i") }}',
            )->render(['sample' => new \DateTimeImmutable('2026-01-15T08:00:00+00:00')]));

            $settings = $client->getContainer()->get(SiteSettingsProvider::class);
            self::assertInstanceOf(SiteSettingsProvider::class, $settings);
            self::assertSame('America/Los_Angeles', $settings->current()->getTimezone());
            self::assertSame('<script>alert(1)</script>', $settings->current()->getSeoDescription());
            self::assertFalse($settings->current()->isSearchIndexingEnabled());
            self::assertFalse($settings->current()->isCookieConsentEnabled());
            self::assertSame('G-ABC1234567', $settings->current()->getGoogleAnalyticsMeasurementId());
            $client->request('GET', '/admin/settings/general');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('input[name="google_analytics_measurement_id"][value="G-ABC1234567"]');
            self::assertSelectorNotExists('script[data-measurement-id="G-ABC1234567"]');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $activityEntry = $entityManager->getConnection()->fetchOne(
                "SELECT id FROM admin_activity_entries WHERE route_name = 'admin_general_settings_update' LIMIT 1",
            );
            self::assertNotFalse($activityEntry);

            // The Twig environment is reused by long-running workers; each request must apply the latest setting.
            $client->request('GET', '/admin/settings/general');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/general', [
                'token' => $token,
                'site_name' => 'Arcanorum',
                'tagline' => '',
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'seo_description' => '',
                'search_indexing_enabled' => '1',
                'maintenance_mode_enabled' => '0',
                'maintenance_message' => SiteSettings::DEFAULT_MAINTENANCE_MESSAGE,
                'cookie_consent_enabled' => '1',
                'cookie_consent_message' => SiteSettings::DEFAULT_COOKIE_CONSENT_MESSAGE,
                'google_analytics_measurement_id' => '',
            ]);
            self::assertResponseRedirects('/admin/settings/general');
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSame('2026-01-15 09:00', $twig->createTemplate(
                '{{ sample|date("Y-m-d H:i") }}',
            )->render(['sample' => new \DateTimeImmutable('2026-01-15T08:00:00+00:00')]));
        } finally {
            $this->restoreDefaults($client, $email);
        }
    }

    public function testGeneralSettingsRejectUnknownFieldsWithoutSavingValidFields(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'general-settings-unknown-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $email, 'UnknownSettings!2026', [AdminRole::Administrator->value]);
            $this->submitLogin($client, $email, 'UnknownSettings!2026');
            self::assertResponseRedirects('/admin');

            $settings = $client->getContainer()->get(SiteSettingsProvider::class);
            self::assertInstanceOf(SiteSettingsProvider::class, $settings);
            $originalName = $settings->current()->getSiteName();

            $client->request('GET', '/admin/settings/general');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/general', [
                'token' => $token,
                'google_analytics_measurement_id' => 'G-ABC1234567-extra',
                'site_name' => 'Must remain unchanged',
            ]);

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('body', 'gültige GA4-Mess-ID');
            self::assertSelectorExists('input[name="google_analytics_measurement_id"][value=""]');
            self::assertSame('', $settings->current()->getGoogleAnalyticsMeasurementId());
            self::assertSame($originalName, $settings->current()->getSiteName());

            $client->request('GET', '/admin/settings/general');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/general', [
                'token' => $token,
                'site_name' => 'Should not be saved',
                'tagline' => '',
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'seo_description' => 'Should not be saved either',
                'search_indexing_enabled' => '0',
                'maintenance_mode_enabled' => '0',
                'maintenance_message' => SiteSettings::DEFAULT_MAINTENANCE_MESSAGE,
                'cookie_consent_enabled' => '1',
                'cookie_consent_message' => SiteSettings::DEFAULT_COOKIE_CONSENT_MESSAGE,
                'htmlcode' => '<script>fetch("https://attacker.invalid")</script>',
            ]);

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('body', 'unbekannte Einstellungsfelder');
            self::assertSame($originalName, $settings->current()->getSiteName());
            self::assertSame('', $settings->current()->getSeoDescription());
            self::assertTrue($settings->current()->isSearchIndexingEnabled());
        } finally {
            $this->restoreDefaults($client, $email);
        }
    }

    public function testAdministratorCanConfigureExternalCommentProviderWithValidConsent(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'comment-provider-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $email, 'CommentProvider!2026', [AdminRole::Administrator->value]);
            $this->submitLogin($client, $email, 'CommentProvider!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/settings/general');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('select[name="comment_provider"] option[value="native"][selected]');
            self::assertSelectorExists('input[name="facebook_comments_app_id"]');
            self::assertSelectorExists('input[name="disqus_comments_shortname"]');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');

            $client->request('POST', '/admin/settings/general', ['token' => 'invalid', 'comment_provider' => 'disqus']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('POST', '/admin/settings/general', [
                'token' => $token,
                'site_name' => 'Arcanorum',
                'tagline' => '',
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'comment_provider' => 'disqus',
                'disqus_comments_shortname' => 'arcanorum-community',
            ]);
            self::assertResponseRedirects('/admin/settings/general');

            $siteSettings = $client->getContainer()->get(SiteSettingsProvider::class);
            self::assertInstanceOf(SiteSettingsProvider::class, $siteSettings);
            self::assertSame('disqus', $siteSettings->current()->getCommentProvider()->value);
            self::assertSame('arcanorum-community', $siteSettings->current()->getDisqusCommentsShortname());

            $client->request('GET', '/admin/settings/general');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/general', [
                'token' => $token,
                'site_name' => 'Arcanorum',
                'tagline' => '',
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'comment_provider' => 'disqus',
                'disqus_comments_shortname' => 'https://attacker.example/embed.js',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSame('arcanorum-community', $siteSettings->current()->getDisqusCommentsShortname());

            $client->request('GET', '/admin/settings/general');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/general', [
                'token' => $token,
                'site_name' => 'Arcanorum',
                'tagline' => '',
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'comment_provider' => 'disqus',
                'disqus_comments_shortname' => 'arcanorum-community',
                'cookie_consent_enabled' => '0',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSame('disqus', $siteSettings->current()->getCommentProvider()->value);
            self::assertTrue($siteSettings->current()->isCookieConsentEnabled());
        } finally {
            $this->restoreDefaults($client, $email);
        }
    }

    public function testContentOptionsAreAuthorizedValidatedAndAppliedToPublicAndAdminPages(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(VideoStreamSourceResolver::class, new VideoStreamSourceResolver(['media.example.org']));
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'content-settings-'.bin2hex(random_bytes(6)).'@example.test';
        $title = 'Content options video '.bin2hex(random_bytes(6));
        $newsTitle = 'Content options news '.bin2hex(random_bytes(6));
        $adminVideoTitle = 'Admin content options video '.bin2hex(random_bytes(6));
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $settings = $client->getContainer()->get(SiteSettingsManager::class);
        self::assertInstanceOf(SiteSettingsManager::class, $settings);
        $video = null;

        try {
            self::assertSame([], $settings->updateContentOptions([
                'video_player_engine' => 'native',
                'video_preload_mode' => 'metadata',
                'video_player_aspect_ratio' => '16:9',
                'video_player_max_width' => 'fluid',
                'video_player_embed_max_width' => 'fluid',
                'video_player_home_max_width' => 'fluid',
                'player_featured_autoplay_enabled' => '0',
                'auto_feature_view_threshold' => '0',
                'video_player_color_scheme' => 'midnight',
                'video_player_watermark_enabled' => '0',
                'public_video_embedding_enabled' => '1',
                'player_autoplay_enabled' => '0',
                'player_autoplay_muted' => '1',
                'default_comments_enabled' => '1',
                'guest_comments_enabled' => '1',
                'public_video_submissions_enabled' => '1',
            ]));
            $this->persistUser($client, $email, 'ContentSettings!2026', [AdminRole::Administrator->value]);
            $this->submitLogin($client, $email, 'ContentSettings!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/settings/content');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorExists('form[action="/admin/settings/content"]');
            self::assertSelectorExists('select[name="video_player_engine"] option[value="native"][selected]');
            self::assertSelectorExists('select[name="comment_default_sort"] option[value="added"][selected]');
            self::assertSelectorExists('input[name="comment_rating_hide_threshold"][value="0"]');
            self::assertSelectorExists('input[name="comments_per_page"][value="10"]');
            self::assertSelectorExists('input[name="auto_feature_view_threshold"][type="number"][min="0"][max="2147483647"][value="0"]');
            self::assertSelectorExists('select[name="provider_thumbnail_resolution"] option[value="medium"][selected]');
            self::assertSelectorExists('select[name="video_preload_mode"] option[value="metadata"][selected]');
            self::assertSelectorExists('select[name="video_player_aspect_ratio"] option[value="16:9"][selected]');
            self::assertSelectorExists('select[name="video_player_max_width"] option[value="fluid"][selected]');
            self::assertSelectorExists('select[name="video_player_embed_max_width"] option[value="fluid"][selected]');
            self::assertSelectorExists('select[name="video_player_home_max_width"] option[value="fluid"][selected]');
            self::assertSelectorExists('input[name="player_featured_autoplay_enabled"][type="checkbox"]:not(:checked)');
            self::assertSelectorExists('select[name="video_player_color_scheme"] option[value="midnight"][selected]');
            self::assertSelectorExists('input[name="video_player_watermark_enabled"][type="checkbox"]:not(:checked)');
            self::assertSelectorExists('input[name="public_video_embedding_enabled"][type="checkbox"]');
            self::assertSelectorExists('[data-admin-shortcuts-toggle][aria-pressed="false"]');
            self::assertSelectorExists('[data-admin-shortcuts-help]');
            self::assertSelectorExists('script[src*="admin-shortcuts.js"]');
            self::assertSelectorExists('a[data-admin-shortcut="g d"]');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            self::assertNotSame('', $token);

            $client->request('POST', '/admin/settings/content', [
                'token' => $token,
                'video_player_engine' => 'videojs',
                'provider_thumbnail_resolution' => 'not-a-resolution',
                'video_player_embed_max_width' => '777',
                'video_player_home_max_width' => '777',
                'player_autoplay_enabled' => '1',
                'player_autoplay_muted' => '0',
                'default_comments_enabled' => '0',
                'guest_comments_enabled' => '0',
                'comment_default_sort' => 'unsupported',
                'comment_rating_hide_threshold' => '1001',
                'comments_per_page' => '101',
                'auto_feature_view_threshold' => '2147483648',
                'public_video_submissions_enabled' => '0',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('body', 'nur mit stummgeschaltetem Player');
            self::assertSelectorTextContains('body', 'maximale Breite für Einbettungen');
            self::assertSelectorTextContains('body', 'maximale Breite für die Startseitenwiedergabe');
            self::assertSelectorTextContains('body', 'Vorschaubild-Auflösung');
            self::assertSelectorTextContains('body', 'unterstützte Kommentarsortierung');
            self::assertSelectorTextContains('body', 'Schwelle muss eine ganze Zahl von 0 bis 1000 sein');
            self::assertSelectorTextContains('body', 'Zahl der Kommentare pro Seite muss zwischen 1 und 100 liegen');
            self::assertSelectorTextContains('body', 'Schwelle für die automatische Hervorhebung muss eine ganze Zahl von 0 bis 2147483647 sein');
            self::assertSelectorExists('input[name="auto_feature_view_threshold"][value="2147483648"]');
            self::assertSame('false', $entityManager->getConnection()->fetchOne('SELECT player_autoplay_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('fluid', $entityManager->getConnection()->fetchOne('SELECT video_player_embed_max_width FROM site_settings WHERE id = 1'));
            self::assertSame('fluid', $entityManager->getConnection()->fetchOne('SELECT video_player_home_max_width FROM site_settings WHERE id = 1'));
            self::assertSame('false', $entityManager->getConnection()->fetchOne('SELECT player_featured_autoplay_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('0', $entityManager->getConnection()->fetchOne('SELECT auto_feature_view_threshold::text FROM site_settings WHERE id = 1'));

            $client->request('POST', '/admin/settings/content', [
                'token' => $token,
                'video_player_engine' => 'videojs',
                'provider_thumbnail_resolution' => 'large',
                'video_preload_mode' => 'auto',
                'video_player_aspect_ratio' => '4:3',
                'video_player_max_width' => '800',
                'video_player_embed_max_width' => '1024',
                'video_player_home_max_width' => '800',
                'video_player_color_scheme' => 'ocean',
                'video_player_watermark_enabled' => '1',
                'public_video_embedding_enabled' => '0',
                'player_autoplay_enabled' => '1',
                'player_autoplay_muted' => '1',
                'player_featured_autoplay_enabled' => '1',
                'auto_feature_view_threshold' => '2',
                'default_comments_enabled' => '0',
                'guest_comments_enabled' => '0',
                'comment_default_sort' => 'score',
                'comment_rating_hide_threshold' => '3',
                'comments_per_page' => '2',
                'public_video_submissions_enabled' => '0',
            ]);
            self::assertResponseRedirects('/admin/settings/content');
            self::assertSame('videojs', $entityManager->getConnection()->fetchOne('SELECT video_player_engine FROM site_settings WHERE id = 1'));
            self::assertSame('large', $entityManager->getConnection()->fetchOne('SELECT provider_thumbnail_resolution FROM site_settings WHERE id = 1'));
            self::assertSame('auto', $entityManager->getConnection()->fetchOne('SELECT video_preload_mode FROM site_settings WHERE id = 1'));
            self::assertSame('4:3', $entityManager->getConnection()->fetchOne('SELECT video_player_aspect_ratio FROM site_settings WHERE id = 1'));
            self::assertSame('800', $entityManager->getConnection()->fetchOne('SELECT video_player_max_width FROM site_settings WHERE id = 1'));
            self::assertSame('1024', $entityManager->getConnection()->fetchOne('SELECT video_player_embed_max_width FROM site_settings WHERE id = 1'));
            self::assertSame('800', $entityManager->getConnection()->fetchOne('SELECT video_player_home_max_width FROM site_settings WHERE id = 1'));
            self::assertSame('true', $entityManager->getConnection()->fetchOne('SELECT player_featured_autoplay_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('2', $entityManager->getConnection()->fetchOne('SELECT auto_feature_view_threshold::text FROM site_settings WHERE id = 1'));
            self::assertSame('ocean', $entityManager->getConnection()->fetchOne('SELECT video_player_color_scheme FROM site_settings WHERE id = 1'));
            self::assertSame('true', $entityManager->getConnection()->fetchOne('SELECT video_player_watermark_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('false', $entityManager->getConnection()->fetchOne('SELECT public_video_embedding_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('false', $entityManager->getConnection()->fetchOne('SELECT guest_comments_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('score', $entityManager->getConnection()->fetchOne('SELECT comment_default_sort FROM site_settings WHERE id = 1'));
            self::assertSame('3', $entityManager->getConnection()->fetchOne('SELECT comment_rating_hide_threshold::text FROM site_settings WHERE id = 1'));
            self::assertSame('2', $entityManager->getConnection()->fetchOne('SELECT comments_per_page::text FROM site_settings WHERE id = 1'));
            self::assertNotFalse($entityManager->getConnection()->fetchOne(
                "SELECT id FROM admin_activity_entries WHERE route_name = 'admin_content_settings_update' LIMIT 1",
            ));

            $client->request('GET', '/admin/settings/content');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="/admin/settings/content"] input[type="hidden"][name="video_player_embed_max_width"][value="1024"]');
            self::assertSelectorExists('form[action="/admin/settings/content"] input[type="hidden"][name="video_player_home_max_width"][value="800"]');
            self::assertSelectorExists('form[action="/admin/settings/content"] input[type="hidden"][name="player_featured_autoplay_enabled"][value="1"]');
            self::assertSelectorExists('form[action="/admin/settings/content"] input[type="hidden"][name="auto_feature_view_threshold"][value="2"]');
            self::assertSelectorExists('form[action="/admin/settings/content"] input[type="hidden"][name="provider_thumbnail_resolution"][value="large"]');
            self::assertSelectorExists('form[action="/admin/settings/content"] input[type="hidden"][name="guest_comments_enabled"][value="0"]');
            self::assertSelectorExists('form[action="/admin/settings/content"] select[name="comment_default_sort"] option[value="score"][selected]');
            self::assertSelectorExists('form[action="/admin/settings/content"] input[name="comment_rating_hide_threshold"][value="3"]');
            self::assertSelectorExists('form[action="/admin/settings/content"] input[name="comments_per_page"][value="2"]');
            $client->submitForm('Inhaltseinstellungen speichern');
            self::assertResponseRedirects('/admin/settings/content');
            self::assertSame('1024', $entityManager->getConnection()->fetchOne('SELECT video_player_embed_max_width FROM site_settings WHERE id = 1'));
            self::assertSame('800', $entityManager->getConnection()->fetchOne('SELECT video_player_home_max_width FROM site_settings WHERE id = 1'));
            self::assertSame('true', $entityManager->getConnection()->fetchOne('SELECT player_featured_autoplay_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('2', $entityManager->getConnection()->fetchOne('SELECT auto_feature_view_threshold::text FROM site_settings WHERE id = 1'));
            self::assertSame('large', $entityManager->getConnection()->fetchOne('SELECT provider_thumbnail_resolution FROM site_settings WHERE id = 1'));
            self::assertSame('false', $entityManager->getConnection()->fetchOne('SELECT guest_comments_enabled::text FROM site_settings WHERE id = 1'));

            $client->request('GET', '/admin/news/new');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('input[name="comments_enabled"]:not(:checked)');
            $client->submitForm('News speichern', [
                'title' => $newsTitle,
                'author_name' => 'Redaktion',
                'category' => '',
                'tags' => '',
                'summary' => '',
                'body' => 'A new editorial draft with the configured comment default.',
                'status' => 'draft',
            ]);
            self::assertResponseRedirects('/admin/news');
            self::assertSame('false', $entityManager->getConnection()->fetchOne(
                'SELECT comments_enabled::text FROM news_articles WHERE title = :title',
                ['title' => $newsTitle],
            ));

            $client->request('GET', '/admin/videos/new');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('input[name="comments_enabled"]:not(:checked)');
            $client->submitForm('Video speichern', [
                'title' => $adminVideoTitle,
                'description' => 'A new editorial video draft with the configured comment default.',
                'category' => '',
                'tags' => '',
                'source_type' => 'url',
                'source_url' => 'https://video.example.test/admin-created.mp4',
                'status' => 'draft',
            ]);
            self::assertResponseRedirects('/admin/videos');
            self::assertSame('false', $entityManager->getConnection()->fetchOne(
                'SELECT comments_enabled::text FROM videos WHERE title = :title',
                ['title' => $adminVideoTitle],
            ));

            $video = new Video(
                $title,
                'Published with safe muted autoplay options.',
                null,
                VideoSourceType::Stream,
                'https://media.example.org/content-settings.mp4',
                PublishingStatus::Published,
                false,
                true,
            );
            $entityManager->persist($video);
            $entityManager->flush();

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('video[data-testid="video-stream"][autoplay][muted][preload="auto"]');
            self::assertSelectorExists('video[data-testid="video-stream"][data-player-aspect-ratio="4:3"]');
            self::assertSelectorExists('video[data-testid="video-stream"][data-player-max-width="800"]');
            self::assertSelectorExists('.video-stage[data-player-color-scheme="ocean"] [data-testid="video-player-watermark"][href="/"]');
            self::assertSelectorExists('video[data-testid="video-stream"][data-video-player-engine="videojs"]');
            self::assertSelectorExists('link[rel="stylesheet"][href*="video-js.min"]');
            self::assertSelectorExists('script[src*="video.min"]');
            self::assertSelectorExists('script[src*="videojs-player"]');
            self::assertStringNotContainsString('https://vjs.zencdn.net', (string) $client->getResponse()->getContent());
            self::assertStringContainsString(
                "font-src 'self'",
                (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'),
            );
            $videoJsStylesheetPath = dirname(__DIR__, 2).'/assets/third-party/video.js-8.23.4/video-js.min.css';
            $videoJsStylesheet = file_get_contents($videoJsStylesheetPath);
            self::assertIsString($videoJsStylesheet);
            self::assertStringContainsString('url("./video-js.woff")', $videoJsStylesheet);
            self::assertStringNotContainsString('data:application/font-woff', $videoJsStylesheet);
            self::assertFileExists(dirname($videoJsStylesheetPath).'/video-js.woff');
            self::assertSelectorExists('#comments');
            self::assertSelectorNotExists('nav a[href="/video-submissions"]');
            self::assertSelectorNotExists('form[action="/video-submissions"]');

            $client->request('GET', '/videos');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="/video-submissions"]');
            $client->request('GET', '/video-submissions');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        } finally {
            foreach ([NewsArticle::class => $newsTitle, Video::class => $adminVideoTitle] as $entityClass => $entityTitle) {
                $entity = $entityManager->getRepository($entityClass)->findOneBy(['title' => $entityTitle]);
                if ($entity instanceof NewsArticle || $entity instanceof Video) {
                    $entityManager->remove($entity);
                }
            }
            $entityManager->flush();
            if ($video instanceof Video) {
                $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
                if ($storedVideo instanceof Video) {
                    $entityManager->remove($storedVideo);
                    $entityManager->flush();
                }
            }
            $this->restoreDefaults($client, $email);
        }
    }

    public function testAdministratorCanDisablePublicRegistrationWithoutAffectingExistingMemberAccess(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'account-settings-'.bin2hex(random_bytes(6)).'@example.test';
        $memberEmail = 'retained-member-'.bin2hex(random_bytes(6)).'@example.test';
        $memberHandle = 'retained-'.bin2hex(random_bytes(5));
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $manager = $client->getContainer()->get(SiteSettingsManager::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $manager);
        $member = new MemberAccount($memberEmail, $memberHandle, 'Retained member');
        $entityManager->persist($member);
        $entityManager->flush();
        $memberId = $member->getId();

        try {
            $settingsInput = [
                'site_name' => SiteSettings::DEFAULT_SITE_NAME,
                'tagline' => '',
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'seo_description' => '',
                'search_indexing_enabled' => '1',
                'maintenance_mode_enabled' => '0',
                'maintenance_message' => SiteSettings::DEFAULT_MAINTENANCE_MESSAGE,
                'cookie_consent_enabled' => '1',
                'cookie_consent_message' => SiteSettings::DEFAULT_COOKIE_CONSENT_MESSAGE,
                'member_registration_enabled' => '1',
                'social_google_enabled' => '0',
                'social_facebook_enabled' => '0',
                'social_twitter_enabled' => '0',
            ];
            self::assertSame([], $manager->update($settingsInput));
            self::assertSame('true', $entityManager->getConnection()->fetchOne('SELECT member_registration_enabled::text FROM site_settings WHERE id = 1'));
            $this->persistUser($client, $email, 'AccountSettings!2026', [AdminRole::Administrator->value]);
            $this->submitLogin($client, $email, 'AccountSettings!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/settings/general');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorExists('form[action="/admin/settings/general"]');
            self::assertSelectorExists('input[name="member_registration_enabled"]:checked');
            self::assertSelectorExists('input[name="social_google_enabled"]');
            self::assertSelectorExists('input[name="social_facebook_enabled"]');
            self::assertSelectorExists('input[name="social_twitter_enabled"]');
            self::assertStringContainsString('privaten OAuth-Zugangsdaten', (string) $client->getResponse()->getContent());
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');

            $client->request('POST', '/admin/settings/general', ['token' => 'invalid', 'member_registration_enabled' => '0']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('POST', '/admin/settings/general', [
                ...$settingsInput,
                'token' => $token,
                'social_google_enabled' => 'invalid',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('body', 'Ein/Aus-Wert');
            self::assertSame('true', $entityManager->getConnection()->fetchOne('SELECT member_registration_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('false', $entityManager->getConnection()->fetchOne('SELECT social_google_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('false', $entityManager->getConnection()->fetchOne('SELECT social_facebook_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('false', $entityManager->getConnection()->fetchOne('SELECT social_twitter_enabled::text FROM site_settings WHERE id = 1'));

            $client->request('POST', '/admin/settings/general', [
                ...$settingsInput,
                'token' => $token,
                'member_registration_enabled' => '0',
                'social_google_enabled' => '1',
                'social_facebook_enabled' => '1',
                'social_twitter_enabled' => '1',
            ]);
            self::assertResponseRedirects('/admin/settings/general');
            self::assertSame('false', $entityManager->getConnection()->fetchOne('SELECT member_registration_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('true', $entityManager->getConnection()->fetchOne('SELECT social_google_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('true', $entityManager->getConnection()->fetchOne('SELECT social_facebook_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('true', $entityManager->getConnection()->fetchOne('SELECT social_twitter_enabled::text FROM site_settings WHERE id = 1'));
            self::assertNotFalse($entityManager->getConnection()->fetchOne(
                "SELECT id FROM admin_activity_entries WHERE route_name = 'admin_general_settings_update' LIMIT 1",
            ));
            self::assertNotFalse($entityManager->getConnection()->fetchOne(
                'SELECT id FROM member_accounts WHERE id = :id AND email = :email',
                ['id' => $memberId, 'email' => $memberEmail],
            ));

            $client->request('GET', '/konto/registrieren');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            $client->request('POST', '/konto/registrieren', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

            $client->request('GET', '/konto/anmelden');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="/konto/anmelden"]');
            self::assertSelectorNotExists('a[href="/konto/registrieren"]');
            $client->request('GET', '/news');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('nav.public-navigation a[href="/konto/anmelden"]');
            self::assertSelectorNotExists('nav.public-navigation a[href="/konto/registrieren"]');

            $client->request('GET', '/admin/settings/general');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/general', [...$settingsInput, 'token' => $token]);
            self::assertResponseRedirects('/admin/settings/general');
            $client->request('GET', '/news');
            self::assertSelectorExists('nav.public-navigation a[href="/konto/registrieren"]');
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $memberId]);
            $this->restoreDefaults($client, $email);
        }
    }

    public function testInvalidCsrfAndUnsupportedValuesCannotChangeSettings(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'invalid-settings-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $email, 'InvalidSettings!2026', [AdminRole::Administrator->value]);
            $this->submitLogin($client, $email, 'InvalidSettings!2026');
            self::assertResponseRedirects('/admin');

            $client->request('POST', '/admin/settings/general', [
                'token' => 'invalid',
                'site_name' => 'Unzulässiger Name',
                'tagline' => '',
                'locale' => 'de',
                'timezone' => 'UTC',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('GET', '/admin/settings/general');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/general', [
                'token' => $token,
                'site_name' => 'Unzulässiger Name',
                'tagline' => '',
                'locale' => 'fr',
                'timezone' => '../../etc/passwd',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('body', 'installierte Sprache');
            self::assertSelectorTextContains('body', 'gültige IANA-Zeitzone');

            $settings = $client->getContainer()->get(SiteSettingsProvider::class);
            self::assertInstanceOf(SiteSettingsProvider::class, $settings);
            self::assertSame(SiteSettings::DEFAULT_SITE_NAME, $settings->current()->getSiteName());
            self::assertSame(SiteSettings::DEFAULT_TIMEZONE, $settings->current()->getTimezone());

            $client->request('GET', '/admin/settings/general');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('select#locale option[value="en"]');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/general', [
                'token' => $token,
                'site_name' => 'Arcanorum',
                'tagline' => '',
                'locale' => 'en',
                'timezone' => 'UTC',
            ]);
            self::assertResponseRedirects('/admin/settings/general');

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('<html lang="en"', (string) $client->getResponse()->getContent());
            self::assertSelectorTextContains('.eyebrow', 'Gaming news and videos');

            $client->request('GET', '/admin/settings/general');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('select#locale option[value="en"][selected]');
            self::assertSelectorTextContains('#seitenueberschrift', 'General settings');

            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            self::assertSelectorExists('select#locale option[value="ar"]');
            $client->request('POST', '/admin/settings/general', [
                'token' => $token,
                'site_name' => 'Arcanorum',
                'tagline' => '',
                'locale' => 'ar',
                'timezone' => 'UTC',
            ]);
            self::assertResponseRedirects('/admin/settings/general');

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            $arabicHome = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('<html lang="ar" dir="rtl"', $arabicHome);
            self::assertStringContainsString('الفيديوهات', $arabicHome);

            $client->request('GET', '/admin/settings/general');
            self::assertResponseIsSuccessful();
            $arabicSettings = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('<html lang="ar" dir="rtl"', $arabicSettings);
            self::assertSelectorExists('select#locale option[value="ar"][selected]');
            self::assertStringContainsString('Allgemeine Einstellungen', $arabicSettings);
        } finally {
            $this->restoreDefaults($client, $email);
        }
    }

    public function testAnonymousAndModeratorCannotOpenGeneralSettings(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'settings-moderator-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $client->request('GET', '/admin/settings/general');
            self::assertResponseRedirects('/admin/login');
            $client->request('GET', '/admin/settings/modules');
            self::assertResponseRedirects('/admin/login');
            $client->request('GET', '/admin/settings/content');
            self::assertResponseRedirects('/admin/login');

            $this->persistUser($client, $email, 'SettingsModerator!2026', [AdminRole::CommentModerator->value]);
            $this->submitLogin($client, $email, 'SettingsModerator!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/settings/general');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $client->request('GET', '/admin/settings/modules');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $client->request('GET', '/admin/settings/content');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $client->request('POST', '/admin/settings/content/watermark', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $client->request('GET', '/admin/settings/content/watermark-image/'.str_repeat('a', 64));
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $this->restoreDefaults($client, $email);
        }
    }

    public function testMaintenanceModeBlocksVisitorsButKeepsAdminRecoveryAvailable(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'maintenance-settings-'.bin2hex(random_bytes(6)).'@example.test';
        $message = 'Geplante Arbeiten &amp; Aktualisierung.';

        try {
            $this->persistUser($client, $email, 'MaintenanceSettings!2026', [AdminRole::Administrator->value]);
            $this->submitLogin($client, $email, 'MaintenanceSettings!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/settings/general');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/general', [
                'token' => $token,
                'site_name' => 'Arcanorum',
                'tagline' => '',
                'locale' => 'ar',
                'timezone' => 'Europe/Berlin',
                'seo_description' => '',
                'search_indexing_enabled' => '1',
                'maintenance_mode_enabled' => '1',
                'maintenance_message' => $message,
                'cookie_consent_enabled' => '0',
                'cookie_consent_message' => SiteSettings::DEFAULT_COOKIE_CONSENT_MESSAGE,
            ]);
            self::assertResponseRedirects('/admin/settings/general');

            $client->request('GET', '/');
            self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertStringContainsString('<html lang="ar" dir="rtl">', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('Geplante Arbeiten &amp;amp; Aktualisierung.', (string) $client->getResponse()->getContent());

            $client->request('GET', '/robots.txt');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString("User-agent: *\nDisallow: /", (string) $client->getResponse()->getContent());

            $client->request('GET', '/admin/settings/general');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/general', [
                'token' => $token,
                'site_name' => 'Arcanorum',
                'tagline' => '',
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'seo_description' => '',
                'search_indexing_enabled' => '1',
                'maintenance_mode_enabled' => '0',
                'maintenance_message' => $message,
                'cookie_consent_enabled' => '0',
                'cookie_consent_message' => SiteSettings::DEFAULT_COOKIE_CONSENT_MESSAGE,
            ]);
            self::assertResponseRedirects('/admin/settings/general');
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
        } finally {
            $this->restoreDefaults($client, $email);
        }
    }

    public function testAnonymousAdministratorLoginRemainsAvailableDuringMaintenance(): void
    {
        $setupClient = static::createClient();
        $manager = $setupClient->getContainer()->get(SiteSettingsManager::class);
        self::assertInstanceOf(SiteSettingsManager::class, $manager);
        self::assertSame([], $manager->update([
            'site_name' => 'Arcanorum',
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
            'maintenance_mode_enabled' => '1',
            'maintenance_message' => SiteSettings::DEFAULT_MAINTENANCE_MESSAGE,
        ]));
        static::ensureKernelShutdown();

        $client = static::createClient();
        try {
            $client->request('GET', '/admin/login');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="/admin/login"]');
            $client->request('GET', '/');
            self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        } finally {
            $this->restoreDefaults($client, '');
        }
    }

    public function testAdministratorCanDisableOptionalModuleWithoutDisablingCoreAccessOrDeletingSettings(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'module-settings-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $email, 'ModuleSettings!2026', [AdminRole::Administrator->value]);
            $this->submitLogin($client, $email, 'ModuleSettings!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/settings/modules');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSelectorExists('form[action="/admin/settings/modules"]');
            self::assertSelectorExists('#module-admin-name');
            self::assertSelectorTextContains('#module-admin-name + p', 'immer verfügbar');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');

            $client->request('POST', '/admin/settings/modules', [
                'token' => $token,
                'module_news_enabled' => '0',
                'module_series_enabled' => '0',
                'module_comments_enabled' => '1',
                'module_contact_enabled' => '1',
                'module_pages_enabled' => '1',
                'module_search_enabled' => '1',
            ]);
            self::assertResponseRedirects('/admin/settings/modules');

            $client->request('GET', '/news');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            $client->request('GET', '/admin/news');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            $client->request('GET', '/series');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            $client->request('GET', '/news.rss');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.public-navigation a[href="/news"]');
            self::assertSelectorNotExists('#home-news-heading');
            $client->request('GET', '/search?q=visible');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('#search-news-heading');
            self::assertSelectorNotExists('#search-series-heading');
            self::assertSelectorExists('#search-videos-heading');

            $client->request('GET', '/sitemap.xml');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('/news/', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('/series/', (string) $client->getResponse()->getContent());

            $client->request('GET', '/admin/settings/modules');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('#module-news-enabled:not(:checked)');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/modules', [
                'token' => $token,
                'module_news_enabled' => '0',
                'module_series_enabled' => '0',
                'module_comments_enabled' => '0',
                'module_contact_enabled' => '0',
                'module_pages_enabled' => '0',
                'module_search_enabled' => '0',
            ]);
            self::assertResponseRedirects('/admin/settings/modules');

            foreach (['/contact', '/pages', '/search?q=disabled', '/hilfe/emoji', '/hilfe/emoji/katalog.json', '/admin/contact-messages', '/admin/pages', '/admin/comments', '/admin/search-statistics'] as $disabledRoute) {
                $client->request('GET', $disabledRoute);
                self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, sprintf('Expected %s to be disabled.', $disabledRoute));
                self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            }

            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            foreach (['news', 'series', 'comments', 'contact_messages', 'search_terms'] as $disabledMetric) {
                self::assertSelectorNotExists(sprintf('[data-dashboard-metric="%s"]', $disabledMetric));
            }
            self::assertSelectorExists('[data-dashboard-metric="videos"]');
            self::assertSelectorNotExists('a[href="/admin/news"]');
            self::assertSelectorNotExists('a[href="/admin/series"]');
            self::assertSelectorNotExists('a[href="/admin/contact-messages"]');
            self::assertSelectorNotExists('a[href="/admin/comments"]');
            self::assertSelectorNotExists('a[href="/admin/search-statistics"]');

            $client->request('GET', '/admin/settings/general');
            self::assertResponseIsSuccessful();

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            self::assertSame('false', $entityManager->getConnection()->fetchOne('SELECT module_news_enabled::text FROM site_settings WHERE id = 1'));
            self::assertSame('false', $entityManager->getConnection()->fetchOne('SELECT module_series_enabled::text FROM site_settings WHERE id = 1'));
            self::assertNotFalse($entityManager->getConnection()->fetchOne(
                "SELECT id FROM admin_activity_entries WHERE route_name = 'admin_module_settings_update' LIMIT 1",
            ));
        } finally {
            $this->restoreDefaults($client, $email);
        }
    }

    public function testModuleSettingsRejectUnknownInputAndUnknownCapabilitiesFailClosed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'module-fail-closed-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistUser($client, $email, 'ModuleFailClosed!2026', [AdminRole::Administrator->value]);
            $this->submitLogin($client, $email, 'ModuleFailClosed!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/settings/modules');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/modules', ['token' => 'invalid']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('GET', '/admin/settings/modules');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/modules', [
                'token' => $token,
                'module_news_enabled' => '1',
                'module_series_enabled' => '1',
                'module_comments_enabled' => '1',
                'module_contact_enabled' => '1',
                'module_pages_enabled' => '1',
                'module_search_enabled' => '1',
                'module_future_enabled' => '1',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('body', 'unbekannte Moduleinstellung');

            $manager = $client->getContainer()->get(SiteSettingsManager::class);
            self::assertInstanceOf(SiteSettingsManager::class, $manager);
            $allModulesEnabled = [
                'module_news_enabled' => '1',
                'module_series_enabled' => '1',
                'module_comments_enabled' => '1',
                'module_contact_enabled' => '1',
                'module_pages_enabled' => '1',
                'module_search_enabled' => '1',
            ];
            self::assertSame([], $manager->updateFeatureModules($allModulesEnabled));

            $siteSettings = $client->getContainer()->get(SiteSettingsProvider::class);
            self::assertInstanceOf(SiteSettingsProvider::class, $siteSettings);
            $availability = new ModuleAvailability(
                new BuiltInModuleCatalog(),
                new HostingProfileDetector([]),
                $siteSettings,
                new \Symfony\Component\HttpFoundation\RequestStack(),
                new NullLogger(),
            );
            self::assertFalse($availability->isEnabled('news'));
            self::assertTrue($availability->isEnabled('admin'));
            self::assertTrue($availability->controllerIsEnabled('App\\Module\\Automation\\Controller\\AdminScheduledTaskController::index'));
            self::assertTrue($availability->controllerIsEnabled('App\\Module\\Content\\Controller\\AdminContentInlineImageController::upload'));
            self::assertTrue($availability->controllerIsEnabled('App\\Module\\Content\\Controller\\ContentInlineImageController::show'));
            self::assertFalse($availability->controllerIsEnabled('App\\Module\\Content\\Controller\\UnknownController::index'));
            self::assertFalse($availability->controllerIsEnabled('App\\Module\\News\\Controller\\NewsPublicController::index'));
            self::assertFalse($availability->controllerIsEnabled('App\\Module\\Future\\Controller\\UnknownController::index'));

            $throwingProbe = new class implements CapabilityProbeInterface {
                public function probe(): iterable
                {
                    throw new \RuntimeException('Capability probe unavailable.');
                }
            };
            $failedAvailability = new ModuleAvailability(
                new BuiltInModuleCatalog(),
                new HostingProfileDetector([$throwingProbe]),
                $siteSettings,
                new \Symfony\Component\HttpFoundation\RequestStack(),
                new NullLogger(),
            );
            self::assertFalse($failedAvailability->isEnabled('news'));

            self::assertSame([], $manager->updateFeatureModules([
                ...$allModulesEnabled,
                'module_news_enabled' => '0',
            ]));
            self::assertArrayHasKey('module_news_enabled', $availability->unavailableEnableErrors(['module_news_enabled' => '1']));
        } finally {
            $this->restoreDefaults($client, $email);
        }
    }

    public function testAdministratorCanUploadPreviewAndRemovePrivatePlayerWatermark(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'player-watermark-'.bin2hex(random_bytes(6)).'@example.test';
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        self::assertIsString($contents);
        $objectId = null;
        $objectContents = null;
        $deletedObjects = 0;
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::once())->method('write')->willReturnCallback(
            static function (string $storedId, string $storedContents) use (&$objectId, &$objectContents, $contents): void {
                self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $storedId);
                self::assertSame($contents, $storedContents);
                $objectId = $storedId;
                $objectContents = $storedContents;
            },
        );
        $storage->expects(self::exactly(2))->method('read')->willReturnCallback(
            static function (string $requestedId, int $maximumBytes) use (&$objectId, &$objectContents, $contents): string {
                self::assertSame($objectId, $requestedId);
                self::assertIsString($objectContents);
                self::assertSame(strlen($contents), $maximumBytes);

                return $objectContents;
            },
        );
        $storage->expects(self::once())->method('delete')->willReturnCallback(
            static function (string $deletedId) use (&$objectId, &$deletedObjects): void {
                self::assertSame($objectId, $deletedId);
                ++$deletedObjects;
            },
        );
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $storage);
        $path = tempnam(sys_get_temp_dir(), 'arcanorum-player-watermark-');
        self::assertIsString($path);

        try {
            self::assertSame(strlen($contents), file_put_contents($path, $contents));
            $this->persistUser($client, $email, 'PlayerWatermark!2026', [AdminRole::Administrator->value]);
            $this->submitLogin($client, $email, 'PlayerWatermark!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/settings/content');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form[action="/admin/settings/content/watermark"] input[name="_token"]')->attr('value');
            self::assertNotSame('', $token);
            $client->request('POST', '/admin/settings/content/watermark', ['_token' => 'invalid-csrf-token']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            $client->request(
                'POST',
                '/admin/settings/content/watermark',
                ['_token' => $token],
                ['watermark_file' => new UploadedFile($path, 'watermark.png', 'image/png', UPLOAD_ERR_OK, true)],
            );
            self::assertResponseRedirects('/admin/settings/content');

            $settingsProvider = $client->getContainer()->get(SiteSettingsProvider::class);
            self::assertInstanceOf(SiteSettingsProvider::class, $settingsProvider);
            $settings = $settingsProvider->current();
            self::assertInstanceOf(SiteSettings::class, $settings);
            $storedHash = $settings->getVideoPlayerWatermarkSha256();
            self::assertNotNull($settings->getVideoPlayerWatermarkObjectId());
            self::assertSame(hash('sha256', $contents), $storedHash);
            $client->request('GET', '/admin/settings/content');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('img.player-watermark-preview');

            $client->request('GET', '/admin/settings/content/watermark-image/'.$storedHash);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame($contents, $client->getResponse()->getContent());

            $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
            self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
            self::assertSame([], $settingsManager->updateContentOptions(['video_player_watermark_enabled' => '1']));
            $client->request('GET', '/_assets/player-watermark/'.$storedHash);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('public', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('max-age=300', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

            $client->request('POST', '/admin/settings/content/watermark/remove', ['_token' => $token]);
            self::assertResponseRedirects('/admin/settings/content');
            $entityManagerAfterRemoval = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManagerAfterRemoval);
            $entityManagerAfterRemoval->clear();
            $removedSettings = $entityManagerAfterRemoval->getRepository(SiteSettings::class)->find(SiteSettings::SINGLETON_ID);
            self::assertInstanceOf(SiteSettings::class, $removedSettings);
            self::assertNull($removedSettings->getVideoPlayerWatermarkObjectId());
            self::assertNull($removedSettings->getVideoPlayerWatermarkSha256());
            self::assertSame(1, $deletedObjects);
            $client->request('GET', '/_assets/player-watermark/'.$storedHash);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($entityManager instanceof EntityManagerInterface) {
                $entityManager->getConnection()->executeStatement(
                    'UPDATE site_settings SET video_player_watermark_object_id = NULL, video_player_watermark_mime_type = NULL, video_player_watermark_size = NULL, video_player_watermark_sha256 = NULL WHERE id = 1',
                );
            }
            $this->restoreDefaults($client, $email);
        }
    }

    public function testAdministratorCanConfigurePublicDisplayWithoutExposingPrivateSettings(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'public-display-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $client->request('GET', '/admin/settings/public-display');
            self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

            $this->persistUser($client, $email, 'PublicDisplay!2026', [AdminRole::Administrator->value]);
            $this->submitLogin($client, $email, 'PublicDisplay!2026');
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/settings/public-display');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            $shareSettingsForm = $client->getCrawler()->filterXPath(
                '//form[.//input[@type="checkbox" and @name="video_share_widget_enabled"]]',
            );
            self::assertCount(1, $shareSettingsForm);
            self::assertStringContainsString(
                'Teilen-Bereich für Videos, News und Serienfolgen anzeigen',
                $shareSettingsForm->text(),
            );
            $token = (string) $shareSettingsForm->filter('input[name="token"]')->attr('value');
            self::assertNotSame('', $token);

            $client->request('POST', '/admin/settings/public-display', [
                'token' => $token,
                'homepage_featured_limit' => '4',
                'homepage_video_limit' => '12',
                'homepage_news_limit' => '3',
                'public_video_page_limit' => '36',
                'popular_video_sort' => 'recent_views',
                'popular_video_period_days' => '7',
                'currently_watching_limit' => '4',
                'public_statistics_enabled' => '1',
                'tag_cloud_enabled' => '1',
                'tag_cloud_limit' => '10',
                'tag_cloud_shuffle' => '1',
                'related_video_limit' => '3',
                'top_rated_video_limit' => '3',
                'video_share_widget_enabled' => '0',
            ]);
            self::assertResponseRedirects('/admin/settings/public-display');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            self::assertEquals(4, $entityManager->getConnection()->fetchOne('SELECT homepage_featured_limit FROM site_settings WHERE id = 1'));
            self::assertSame('recent_views', $entityManager->getConnection()->fetchOne('SELECT popular_video_sort FROM site_settings WHERE id = 1'));

            $client->request('POST', '/admin/settings/public-display', [
                'token' => 'invalid-csrf-token',
                'homepage_video_limit' => '24',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertEquals(12, $entityManager->getConnection()->fetchOne('SELECT homepage_video_limit FROM site_settings WHERE id = 1'));

            $client->request('GET', '/admin/settings/public-display');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/public-display', [
                'token' => $token,
                'homepage_video_limit' => '25',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorExists('[role="alert"]');
            self::assertEquals(12, $entityManager->getConnection()->fetchOne('SELECT homepage_video_limit FROM site_settings WHERE id = 1'));

            $client->request('GET', '/admin/settings/public-display');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/public-display', [
                'token' => $token,
                'homepage_video_limit' => ['24'],
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertEquals(12, $entityManager->getConnection()->fetchOne('SELECT homepage_video_limit FROM site_settings WHERE id = 1'));

            $client->request('GET', '/admin/settings/public-display');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/settings/public-display', [
                'token' => $token,
                'homepage_video_limit' => '24',
                'unexpected_private_option' => '1',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('body', 'unbekannte Anzeigeeinstellung');
            self::assertEquals(12, $entityManager->getConnection()->fetchOne('SELECT homepage_video_limit FROM site_settings WHERE id = 1'));
            self::assertNotFalse($entityManager->getConnection()->fetchOne(
                "SELECT id FROM admin_activity_entries WHERE route_name = 'admin_public_display_settings_update' LIMIT 1",
            ));
        } finally {
            $this->restoreDefaults($client, $email);
        }
    }

    /** @param list<string> $roles */
    private function persistUser(KernelBrowser $client, string $email, string $password, array $roles): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $user = new AdminUser($email);
        $user->setRoles($roles);
        $user->setPassword($passwordHasher->hashPassword($user, $password));
        $mfaProvisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $mfaProvisioner);
        $mfaProvisioner->provision($user);
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $user = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(AdminUser::class, $user);
        $encryptedSecret = $user->getMfaSecretCiphertext();
        self::assertNotNull($encryptedSecret);
        $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
        self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
        $totp = $client->getContainer()->get(TotpVerifier::class);
        self::assertInstanceOf(TotpVerifier::class, $totp);
        $mfaCode = $totp->codeAtTime($cipher->decrypt($encryptedSecret), time());

        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => $email,
            '_password' => $password,
            '_mfa_code' => $mfaCode,
        ]);
    }

    private function restoreDefaults(KernelBrowser $client, string $email): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $connection = $entityManager->getConnection();
        $connection->executeStatement(
            "UPDATE site_settings SET site_name = 'Arcanorum', tagline = '', default_locale = 'de', timezone = 'Europe/Berlin', seo_description = '', search_indexing_enabled = TRUE, maintenance_mode_enabled = FALSE, maintenance_message = 'Die Website wird gewartet. Bitte versuchen Sie es später erneut.', cookie_consent_enabled = TRUE, cookie_consent_message = 'Wir verwenden notwendige Cookies für Anmeldung, Sicherheit und die Ansichtspräferenz in diesem Browser. Optionale Analyse- und Werbedienste werden nur nach Ihrer Zustimmung geladen.', google_analytics_measurement_id = '', module_news_enabled = TRUE, module_series_enabled = TRUE, module_comments_enabled = TRUE, module_contact_enabled = TRUE, module_pages_enabled = TRUE, module_search_enabled = TRUE, video_player_engine = 'native', provider_thumbnail_resolution = 'medium', video_preload_mode = 'metadata', video_player_aspect_ratio = '16:9', video_player_max_width = 'fluid', video_player_embed_max_width = 'fluid', video_player_home_max_width = 'fluid', player_featured_autoplay_enabled = FALSE, auto_feature_view_threshold = 0, video_player_color_scheme = 'midnight', video_player_watermark_enabled = FALSE, video_player_watermark_object_id = NULL, video_player_watermark_mime_type = NULL, video_player_watermark_size = NULL, video_player_watermark_sha256 = NULL, public_video_embedding_enabled = TRUE, player_autoplay_enabled = FALSE, player_autoplay_muted = TRUE, default_comments_enabled = TRUE, guest_comments_enabled = TRUE, comment_default_sort = 'added', comment_rating_hide_threshold = 0, comments_per_page = 10, public_video_submissions_enabled = TRUE, homepage_featured_limit = 1, homepage_video_limit = 6, homepage_news_limit = 6, public_video_page_limit = 24, popular_video_sort = 'views', popular_video_period_days = 30, currently_watching_limit = 0, public_statistics_enabled = FALSE, tag_cloud_enabled = FALSE, tag_cloud_limit = 20, tag_cloud_shuffle = FALSE, related_video_limit = 6, top_rated_video_limit = 0, video_share_widget_enabled = TRUE, member_registration_enabled = TRUE, social_google_enabled = FALSE, social_facebook_enabled = FALSE, social_twitter_enabled = FALSE, comment_provider = 'native', facebook_comments_app_id = '', disqus_comments_shortname = '' WHERE id = 1",
        );
        $user = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        if ($user instanceof AdminUser) {
            $connection->executeStatement('DELETE FROM admin_activity_entries WHERE actor_id = :actor_id', ['actor_id' => $user->getId()]);
            $entityManager->remove($user);
            $entityManager->flush();
        }
    }
}
