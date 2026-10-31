<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoQuality;
use App\Entity\VideoQualitySource;
use App\Entity\VideoSourceType;
use App\Module\Video\Service\VideoEmbedCodeGenerator;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class VideoEmbedFlowTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $mfaKey = base64_encode(str_repeat('V', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$mfaKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;

        parent::setUpBeforeClass();
    }

    public function testPublicPlayerNeedsExplicitOriginGrantAndRevocationIsImmediate(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            VideoStreamSourceResolver::class,
            new VideoStreamSourceResolver(['media.example.org']),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $enabled = new Video(
            'Embeddable video '.$suffix,
            'Public video with an explicit embedding grant.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            PublishingStatus::Published,
            false,
            false,
        );
        $enabled->setEmbedAllowedOrigins(['https://publisher.example.org']);
        $disabled = new Video(
            'Embedding disabled '.$suffix,
            'Published video without an embedding grant.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=9bZkp7q19f0',
            PublishingStatus::Published,
            false,
            false,
        );
        $draft = new Video(
            'Embeddable draft '.$suffix,
            'A draft must stay private.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
            PublishingStatus::Published,
            false,
            false,
        );
        $draft->setEmbedAllowedOrigins(['https://publisher.example.org']);
        $draft->setPublishingStatus(PublishingStatus::Draft);

        try {
            foreach ([$enabled, $disabled, $draft] as $video) {
                $entityManager->persist($video);
            }
            $entityManager->flush();

            $client->request('GET', '/embed/video/'.$enabled->getId(), [], [], [
                'HTTP_ORIGIN' => 'https://attacker.example.net',
                'HTTP_REFERER' => 'https://attacker.example.net/path?private=token',
            ]);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-testid="video-embed-provider"] button[data-provider-embed-load]');
            self::assertSelectorNotExists('iframe[data-testid="video-embed-provider"]');
            self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $client->getCrawler()->filter('[data-testid="video-embed-provider"]')->attr('data-embed-url'));
            self::assertStringContainsString('provider-embed-consent.js', (string) $client->getResponse()->getContent());
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
            self::assertFalse($client->getResponse()->headers->has('X-Frame-Options'));
            $policy = (string) $client->getResponse()->headers->get('Content-Security-Policy');
            self::assertStringContainsString("frame-ancestors 'self' https://publisher.example.org", $policy);
            self::assertStringNotContainsString('attacker.example.net', $policy);
            self::assertStringContainsString("frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com https://geo.dailymotion.com https://www.facebook.com https://drive.google.com https://rutube.ru https://vk.com https://player.youku.com", $policy);

            $client->request('GET', '/embed/video/'.$enabled->getId(), [], [], [
                'HTTP_ORIGIN' => 'https://publisher.example.org.evil.test',
            ]);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString("frame-ancestors 'self' https://publisher.example.org", (string) $client->getResponse()->headers->get('Content-Security-Policy'));
            self::assertStringNotContainsString('publisher.example.org.evil.test', (string) $client->getResponse()->headers->get('Content-Security-Policy'));

            $client->request('GET', '/embed/video/'.$disabled->getId());
            self::assertResponseStatusCodeSame(404);
            self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
            $client->request('GET', '/embed/video/'.$draft->getId());
            self::assertResponseStatusCodeSame(404);

            $revocationEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $revocationEntityManager);
            $videoToRevoke = $revocationEntityManager->find(Video::class, $enabled->getId());
            self::assertInstanceOf(Video::class, $videoToRevoke);
            $videoToRevoke->setEmbedAllowedOrigins([]);
            $revocationEntityManager->flush();
            $revocationEntityManager->clear();
            $reloadedVideo = $revocationEntityManager->find(Video::class, $enabled->getId());
            self::assertInstanceOf(Video::class, $reloadedVideo);
            self::assertSame([], $reloadedVideo->getEmbedAllowedOrigins());
            $client->request('GET', '/embed/video/'.$enabled->getId());
            self::assertSame(404, $client->getResponse()->getStatusCode(), 'A revoked embedding grant must take effect on the next request.');
            self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM videos WHERE id IN (:enabled, :disabled, :draft)',
                ['enabled' => $enabled->getId(), 'disabled' => $disabled->getId(), 'draft' => $draft->getId()],
                ['enabled' => \Doctrine\DBAL\ParameterType::STRING, 'disabled' => \Doctrine\DBAL\ParameterType::STRING, 'draft' => \Doctrine\DBAL\ParameterType::STRING],
            );
        }
    }

    public function testConfiguredDailymotionEmbedRemainsGatedUntilTheVisitorChooses(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(ExternalVideoEmbedResolver::class, new ExternalVideoEmbedResolver('x8lr5'));

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $video = new Video(
            'Dailymotion consent check '.bin2hex(random_bytes(6)),
            'A configured provider embed is still lazy and consent gated.',
            null,
            VideoSourceType::Embed,
            'https://www.dailymotion.com/video/x84sh87',
            PublishingStatus::Published,
            false,
            false,
        );
        $video->setEmbedAllowedOrigins(['https://publisher.example.org']);

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            $client->request('GET', '/embed/video/'.$video->getId());

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-provider-embed] button[data-provider-embed-load]');
            self::assertSelectorNotExists('iframe[data-testid="video-embed-provider"]');
            self::assertSame(
                'https://geo.dailymotion.com/player/x8lr5.html?video=x84sh87',
                $client->getCrawler()->filter('[data-testid="video-embed-provider"]')->attr('data-embed-url'),
            );
            self::assertStringContainsString(
                'https://geo.dailymotion.com',
                (string) $client->getResponse()->headers->get('Content-Security-Policy'),
            );
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM videos WHERE id = :id',
                ['id' => $video->getId()],
                ['id' => \Doctrine\DBAL\ParameterType::STRING],
            );
        }
    }

    public function testAdditionalVideoProvidersUseCanonicalConsentGatedEmbeds(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $providers = [
            [
                'source' => 'https://www.facebook.com/watch/?v=20531316728&ref=share',
                'embed' => 'https://www.facebook.com/video/embed?video_id=20531316728',
                'origin' => 'https://www.facebook.com',
            ],
            [
                'source' => 'https://drive.google.com/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/view?usp=sharing',
                'embed' => 'https://drive.google.com/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/preview',
                'origin' => 'https://drive.google.com',
            ],
            [
                'source' => 'https://rutube.ru/video/852e974534e3527f16810a7a19c418b0/',
                'embed' => 'https://rutube.ru/play/embed/852e974534e3527f16810a7a19c418b0',
                'origin' => 'https://rutube.ru',
            ],
            [
                'source' => 'https://vkvideo.ru/video-123456_456239017?list=shared-list',
                'embed' => 'https://vk.com/video_ext.php?oid=-123456&id=456239017',
                'origin' => 'https://vk.com',
            ],
            [
                'source' => 'https://v.youku.com/v_show/id_XNDI0MDAwMDAwMA==.html',
                'embed' => 'https://player.youku.com/embed/XNDI0MDAwMDAwMA==',
                'origin' => 'https://player.youku.com',
            ],
        ];
        $videos = [];

        try {
            foreach ($providers as $provider) {
                $video = new Video(
                    'Consent-gated provider '.bin2hex(random_bytes(8)),
                    'The provider URL is canonicalized and loaded only after explicit visitor action.',
                    null,
                    VideoSourceType::Embed,
                    $provider['source'],
                    PublishingStatus::Published,
                    false,
                    false,
                );
                $videos[] = [$video, $provider];
                $entityManager->persist($video);
            }
            $entityManager->flush();

            foreach ($videos as [$video, $provider]) {
                $client->request('GET', '/videos/'.$video->getId());

                self::assertResponseIsSuccessful();
                self::assertSame($provider['embed'], $client->getCrawler()->filter('[data-testid="video-embed-provider"]')->attr('data-embed-url'));
                self::assertSelectorExists('[data-provider-embed] button[data-provider-embed-load]');
                self::assertSelectorNotExists('iframe[data-testid="video-embed-provider"]');
                self::assertStringContainsString(
                    $provider['origin'],
                    (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'),
                );
            }
        } finally {
            foreach ($videos as [$video]) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM videos WHERE id = :id',
                    ['id' => $video->getId()],
                    ['id' => \Doctrine\DBAL\ParameterType::STRING],
                );
            }
        }
    }

    public function testLegacyVideoProviderRemainsAnExternalLinkWithoutExecutingItsEmbed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $video = new Video(
            'Legacy provider reference '.bin2hex(random_bytes(8)),
            'Legacy providers remain available as external references without executing old embed markup.',
            null,
            VideoSourceType::Url,
            'https://www.metacafe.com/watch/123/video-title/',
            PublishingStatus::Published,
            false,
            false,
        );

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            $client->request('GET', '/videos/'.$video->getId());

            self::assertResponseIsSuccessful();
            self::assertSame(
                'https://www.metacafe.com/watch/123/video-title/',
                $client->getCrawler()->filter('[data-testid="video-source"]')->attr('href'),
            );
            self::assertSame('_blank', $client->getCrawler()->filter('[data-testid="video-source"]')->attr('target'));
            self::assertSame('noopener noreferrer', $client->getCrawler()->filter('[data-testid="video-source"]')->attr('rel'));
            self::assertSame('no-referrer', $client->getCrawler()->filter('[data-testid="video-source"]')->attr('referrerpolicy'));
            self::assertSelectorNotExists('iframe');
            self::assertSelectorNotExists('[data-provider-embed-load]');
            self::assertStringNotContainsString('provider-embed-consent.js', (string) $client->getResponse()->getContent());
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM videos WHERE id = :id',
                ['id' => $video->getId()],
                ['id' => \Doctrine\DBAL\ParameterType::STRING],
            );
        }
    }

    public function testSiteWideEmbeddingSwitchAndPlayerOptionsApplyToArcanorumPlayer(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            VideoStreamSourceResolver::class,
            new VideoStreamSourceResolver(['media.example.org', 'cdn.example.net']),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $manager = $client->getContainer()->get(SiteSettingsManager::class);
        $provider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsManager::class, $manager);
        self::assertInstanceOf(SiteSettingsProvider::class, $provider);
        $original = $provider->current();
        $originalOptions = [
            'video_player_engine' => $original->getVideoPlayerEngine()->value,
            'video_preload_mode' => $original->getVideoPreloadMode()->value,
            'video_player_aspect_ratio' => $original->getVideoPlayerAspectRatio()->value,
            'video_player_max_width' => $original->getVideoPlayerMaxWidth()->value,
            'video_player_embed_max_width' => $original->getVideoPlayerEmbedMaxWidth()->value,
            'video_player_color_scheme' => $original->getVideoPlayerColorScheme()->value,
            'video_player_watermark_enabled' => $original->isVideoPlayerWatermarkEnabled() ? '1' : '0',
            'public_video_embedding_enabled' => $original->isPublicVideoEmbeddingEnabled() ? '1' : '0',
            'player_autoplay_enabled' => $original->isPlayerAutoplayEnabled() ? '1' : '0',
            'player_autoplay_muted' => $original->isPlayerAutoplayMuted() ? '1' : '0',
        ];
        $originalWatermark = [
            'object_id' => $original->getVideoPlayerWatermarkObjectId(),
            'mime_type' => $original->getVideoPlayerWatermarkMimeType(),
            'size' => $original->getVideoPlayerWatermarkSize(),
            'sha256' => $original->getVideoPlayerWatermarkSha256(),
        ];
        $watermarkSha256 = hash('sha256', 'synthetic-player-watermark');
        $original->attachVideoPlayerWatermark(bin2hex(random_bytes(32)), 'image/png', 1, $watermarkSha256);
        $entityManager->flush();
        $video = new Video(
            'Configurable embedded stream '.bin2hex(random_bytes(6)),
            'Verifies public video player settings on the dedicated embed route.',
            null,
            VideoSourceType::Stream,
            'https://media.example.org/player-options.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
        $video->setEmbedAllowedOrigins(['https://publisher.example.org']);
        $qualitySource = new VideoQualitySource(
            $video,
            VideoQuality::P1080,
            'https://cdn.example.net/player-options-1080.mp4',
        );

        try {
            self::assertSame([], $manager->updateContentOptions([
                'video_player_engine' => 'videojs',
                'video_preload_mode' => 'auto',
                'video_player_aspect_ratio' => '4:3',
                'video_player_max_width' => '800',
                'video_player_embed_max_width' => '1024',
                'video_player_color_scheme' => 'ocean',
                'video_player_watermark_enabled' => '1',
                'public_video_embedding_enabled' => '1',
                'player_autoplay_enabled' => '0',
                'player_autoplay_muted' => '1',
            ]));
            $entityManager->persist($video);
            $entityManager->persist($qualitySource);
            $entityManager->flush();

            $client->request('GET', '/embed/video/'.$video->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('main.video-stage[data-player-color-scheme="ocean"]');
            self::assertSelectorExists('[data-testid="video-player-watermark"][href="/"]');
            self::assertSelectorExists('img[data-testid="video-player-watermark-image"][src="/_assets/player-watermark/'.$watermarkSha256.'"]');
            self::assertSelectorExists('video[data-testid="video-embed-stream"][data-player-aspect-ratio="4:3"][data-player-max-width="1024"][preload="auto"]');
            self::assertSelectorExists('video[data-testid="video-embed-stream"][data-video-player-engine="videojs"]');
            self::assertSelectorExists('link[rel="stylesheet"][href*="video-js.min"]');
            self::assertSelectorExists('script[src*="video.min"]');
            self::assertSelectorExists('script[src*="videojs-player"]');
            self::assertSelectorExists('select[data-video-quality-selector] option[value="https://cdn.example.net/player-options-1080.mp4"]');
            self::assertStringContainsString('https://cdn.example.net', (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertStringContainsString('https://cdn.example.net', (string) $client->getResponse()->headers->get('Content-Security-Policy'));

            $unapprovedQuality = new VideoQualitySource(
                $video,
                VideoQuality::P720,
                'https://unapproved.example.net/player-options-720.mp4',
            );
            $entityManager->persist($unapprovedQuality);
            $entityManager->flush();
            $client->request('GET', '/embed/video/'.$video->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('select[data-video-quality-selector] option[value="https://unapproved.example.net/player-options-720.mp4"]');
            self::assertStringNotContainsString('https://unapproved.example.net', (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertStringNotContainsString('https://unapproved.example.net', (string) $client->getResponse()->headers->get('Content-Security-Policy'));

            $codeGenerator = $client->getContainer()->get(VideoEmbedCodeGenerator::class);
            self::assertInstanceOf(VideoEmbedCodeGenerator::class, $codeGenerator);
            $embedCode = $codeGenerator->generate($video);
            self::assertIsString($embedCode);
            self::assertStringContainsString('width="1024" height="768"', $embedCode);
            self::assertStringContainsString('style="width:100%;max-width:1024px;height:auto;border:0"', $embedCode);

            self::assertSame([], $manager->updateContentOptions(['public_video_embedding_enabled' => '0']));
            self::assertNull($codeGenerator->generate($video));
            $client->request('GET', '/embed/video/'.$video->getId());
            self::assertResponseStatusCodeSame(404);
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
        } finally {
            $manager->updateContentOptions($originalOptions);
            $original->removeVideoPlayerWatermark();
            if (is_string($originalWatermark['object_id'])
                && is_string($originalWatermark['mime_type'])
                && is_int($originalWatermark['size'])
                && is_string($originalWatermark['sha256'])
            ) {
                $original->attachVideoPlayerWatermark(
                    $originalWatermark['object_id'],
                    $originalWatermark['mime_type'],
                    $originalWatermark['size'],
                    $originalWatermark['sha256'],
                );
            }
            $entityManager->flush();
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
        }
    }

    public function testProviderEmbedsIgnoreSiteWideVideoJsEngineAndRemainConsentGated(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $manager = $client->getContainer()->get(SiteSettingsManager::class);
        $provider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsManager::class, $manager);
        self::assertInstanceOf(SiteSettingsProvider::class, $provider);
        $originalEngine = $provider->current()->getVideoPlayerEngine()->value;
        $video = new Video(
            'Provider embed with Video.js selected '.bin2hex(random_bytes(6)),
            'Provider embeds keep their fixed consent-gated adapter when the site-wide player is Video.js.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            PublishingStatus::Published,
            false,
            false,
        );

        try {
            self::assertSame([], $manager->updateContentOptions(['video_player_engine' => 'videojs']));
            $entityManager->persist($video);
            $entityManager->flush();

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            self::assertSame(
                'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
                $client->getCrawler()->filter('[data-testid="video-embed-provider"]')->attr('data-embed-url'),
            );
            self::assertSelectorExists('[data-testid="video-embed-provider"] button[data-provider-embed-load]');
            self::assertSelectorNotExists('iframe[data-testid="video-embed-provider"]');
            self::assertSelectorNotExists('[data-video-player-engine="videojs"]');
            self::assertSelectorNotExists('link[rel="stylesheet"][href*="video-js.min"]');
            self::assertSelectorNotExists('script[src*="videojs-player"]');
            self::assertSelectorExists('script[src*="provider-embed-consent.js"]');
        } finally {
            try {
                $manager->updateContentOptions(['video_player_engine' => $originalEngine]);
            } finally {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM videos WHERE id = :id',
                    ['id' => $video->getId()],
                    ['id' => \Doctrine\DBAL\ParameterType::STRING],
                );
            }
        }
    }

    public function testAdministratorCanGrantAndRevokeEmbedOriginsWithCsrfAndCanonicalCode(): void
    {
        $client = static::createClient([], ['HTTP_HOST' => 'untrusted.example.net']);
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $video = new Video(
            'Admin embed test '.$suffix,
            'Used to verify administrator-owned embed grants.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            PublishingStatus::Published,
            false,
            true,
        );
        $admin = new AdminUser('video-embed-admin-'.$suffix.'@example.test');
        $admin->setRoles([AdminRole::Administrator->value]);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $admin->setPassword($hasher->hashPassword($admin, 'VideoEmbed-Admin-'.$suffix.'!2026'));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($admin);
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        try {
            $entityManager->persist($video);
            $entityManager->persist($admin);
            $entityManager->flush();
            $this->submitLogin($client, $admin, 'VideoEmbed-Admin-'.$suffix.'!2026');

            $editPath = '/admin/videos/'.$video->getId().'/edit';
            $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            $form = $client->getCrawler()->filter('form.admin-form')->first();
            $token = (string) $form->filter('input[name="_token"]')->attr('value');
            self::assertNotSame('', $token);

            $payload = [
                '_token' => $token,
                'title' => $video->getTitle(),
                'description' => $video->getDescription(),
                'category' => '',
                'tags' => '',
                'source_type' => 'embed',
                'source_url' => $video->getSourceUrl(),
                'status' => 'published',
                'embed_origins' => "https://publisher.example.org\nhttps://second.example.net",
                'comments_enabled' => '1',
            ];
            $client->request('POST', $editPath, $payload);
            self::assertResponseRedirects('/admin/videos');

            $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            $embedCode = $client->getCrawler()->filter('[data-testid="video-embed-code"]')->text();
            self::assertStringContainsString('https://arcanorum.example.test/embed/video/'.$video->getId(), $embedCode);
            self::assertStringNotContainsString('untrusted.example.net', $embedCode);
            $responseContent = $client->getResponse()->getContent();
            self::assertIsString($responseContent);
            self::assertStringContainsString('https://publisher.example.org', $responseContent);

            $client->request('GET', '/embed/video/'.$video->getId(), [], [], [
                'HTTP_ORIGIN' => 'https://attacker.example.net',
            ]);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('attacker.example.net', (string) $client->getResponse()->headers->get('Content-Security-Policy'));

            $client->request('GET', $editPath);
            $token = (string) $client->getCrawler()->filter('form.admin-form')->first()->filter('input[name="_token"]')->attr('value');
            $payload['_token'] = $token;
            $payload['embed_origins'] = '';
            $client->request('POST', $editPath, $payload);
            self::assertResponseRedirects('/admin/videos');
            $client->request('GET', '/embed/video/'.$video->getId());
            self::assertResponseStatusCodeSame(404);

            $payload['_token'] = 'invalid-csrf-token';
            $client->request('POST', $editPath, $payload);
            self::assertResponseStatusCodeSame(403);
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $entityManager->getConnection()->executeStatement('DELETE FROM admin_users WHERE id = :id', ['id' => $admin->getId()]);
        }
    }

    private function submitLogin(KernelBrowser $client, AdminUser $admin, string $password): void
    {
        $encryptedSecret = $admin->getMfaSecretCiphertext();
        self::assertNotNull($encryptedSecret);
        $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
        self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
        $totp = $client->getContainer()->get(TotpVerifier::class);
        self::assertInstanceOf(TotpVerifier::class, $totp);

        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => $admin->getUserIdentifier(),
            '_password' => $password,
            '_mfa_code' => $totp->codeAtTime($cipher->decrypt($encryptedSecret), time()),
        ]);
        self::assertResponseRedirects('/admin');
    }

    public function testAllowlistedStreamOriginIsAppliedToMediaAndHlsPolicies(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            VideoStreamSourceResolver::class,
            new VideoStreamSourceResolver(['media.example.org']),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $video = new Video(
            'Embeddable HLS '.$suffix,
            'HLS player uses only its configured stream host.',
            null,
            VideoSourceType::Stream,
            'https://media.example.org/live/channel-'.$suffix.'.m3u8',
            PublishingStatus::Published,
            false,
            false,
        );
        $video->setEmbedAllowedOrigins(['https://publisher.example.org']);

        try {
            $entityManager->persist($video);
            $entityManager->flush();
            $client->request('GET', '/embed/video/'.$video->getId());

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('video[data-testid="video-embed-stream"][data-hls-url]');
            $policy = (string) $client->getResponse()->headers->get('Content-Security-Policy');
            self::assertStringContainsString('media-src \'self\' https://media.example.org blob:', $policy);
            self::assertStringContainsString('connect-src \'self\' https://media.example.org', $policy);
            self::assertStringContainsString("worker-src 'self' blob:", $policy);
            self::assertStringContainsString('hls.js-1.7.3/hls.min.js', (string) $client->getResponse()->getContent());
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
        }
    }
}
