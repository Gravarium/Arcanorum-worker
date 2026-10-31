<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentSeries;
use App\Entity\PublishingStatus;
use App\Entity\SeriesEpisode;
use App\Entity\SeriesGenre;
use App\Entity\Video;
use App\Entity\VideoQuality;
use App\Entity\VideoQualitySource;
use App\Entity\VideoSourceType;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicSeriesFlowTest extends WebTestCase
{
    public function testOnlyPublishedSeriesAndEpisodesAppearOnPublicPages(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(VideoStreamSourceResolver::class, new VideoStreamSourceResolver(['media.example.org', 'cdn.example.net']));
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        $settingsProvider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
        self::assertInstanceOf(SiteSettingsProvider::class, $settingsProvider);
        $originalPlayerRatio = $settingsProvider->current()->getVideoPlayerAspectRatio()->value;
        $originalPlayerWidth = $settingsProvider->current()->getVideoPlayerMaxWidth()->value;
        $originalPlayerColorScheme = $settingsProvider->current()->getVideoPlayerColorScheme()->value;
        $originalPlayerWatermark = $settingsProvider->current()->isVideoPlayerWatermarkEnabled() ? '1' : '0';

        $suffix = bin2hex(random_bytes(8));
        $seriesSlug = 'series-'.$suffix;
        $draftSeriesSlug = 'draft-series-'.$suffix;
        $seriesTitle = 'Series '.$suffix;
        $draftSeriesTitle = 'Draft Series '.$suffix;
        $videoTitle = 'Episode Video '.$suffix;
        $draftVideoTitle = 'Draft Episode Video '.$suffix;
        $draftStatusTitle = 'Draft Status Episode '.$suffix;
        $fallbackTitle = 'Fallback Episode '.$suffix;

        $dramaGenre = new SeriesGenre('Drama '.$suffix);
        $documentaryGenre = new SeriesGenre('Documentary '.$suffix);
        $series = new ContentSeries($seriesTitle, $seriesSlug, 'Published series description', PublishingStatus::Published, [$dramaGenre, $documentaryGenre]);
        $draftSeries = new ContentSeries($draftSeriesTitle, $draftSeriesSlug, 'Draft series description', PublishingStatus::Draft);
        $artwork = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        self::assertIsString($artwork);
        $publishedArtworkId = str_repeat('c', 64);
        $draftArtworkId = str_repeat('d', 64);
        $series->attachArtwork($publishedArtworkId, 'image/png', strlen($artwork));
        $draftSeries->attachArtwork($draftArtworkId, 'image/png', strlen($artwork));

        $artworkStorage = $this->createMock(PrivateObjectStorageInterface::class);
        $artworkStorage
            ->expects($this->once())
            ->method('read')
            ->with($publishedArtworkId)
            ->willReturn($artwork);
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $artworkStorage);
        $video = new Video(
            $videoTitle,
            'Published episode description',
            null,
            VideoSourceType::Url,
            'https://video.example.test/episode-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
        $draftVideo = new Video(
            $draftVideoTitle,
            'Draft episode description',
            null,
            VideoSourceType::Url,
            'https://video.example.test/draft-episode-'.$suffix.'.mp4',
            PublishingStatus::Draft,
            false,
            false,
        );
        $videoInDraftSeries = new Video(
            'Video in '.$draftSeriesTitle,
            'This belongs to an unpublished series.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/draft-series-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
        $draftStatusVideo = new Video(
            $draftStatusTitle,
            'This episode has a draft visibility status.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/draft-status-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
        $fallbackVideo = new Video(
            $fallbackTitle,
            'Fallback episode description.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/fallback-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
        $streamVideo = new Video(
            'Stream Episode '.$suffix,
            'Direct stream source.',
            null,
            VideoSourceType::Stream,
            'https://media.example.org/episodes/stream-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
        $liveVideo = new Video(
            'Live Stream Episode '.$suffix,
            'HLS live playlist.',
            null,
            VideoSourceType::Stream,
            'https://media.example.org/episodes/live-'.$suffix.'.m3u8',
            PublishingStatus::Published,
            false,
            false,
        );
        $embeddedVideo = new Video(
            'Embedded Episode '.$suffix,
            'Allowlisted embed source.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            PublishingStatus::Published,
            false,
            false,
        );

        $publishedEpisode = new SeriesEpisode($series, $video, 1, 1, 'Series episode title', 'Series episode description');
        $draftStatusEpisode = new SeriesEpisode($series, $draftStatusVideo, 1, 3, null, null, PublishingStatus::Draft);
        $fallbackEpisode = new SeriesEpisode($series, $fallbackVideo, 1, 4);
        $draftVideoEpisode = new SeriesEpisode($series, $draftVideo, 1, 2);
        $draftSeriesEpisode = new SeriesEpisode($draftSeries, $videoInDraftSeries, 1, 1);
        $streamEpisode = new SeriesEpisode($series, $streamVideo, 1, 5);
        $streamQuality = new VideoQualitySource(
            $streamVideo,
            VideoQuality::P720,
            'https://cdn.example.net/episodes/stream-'.$suffix.'-720.mp4',
        );
        $embeddedEpisode = new SeriesEpisode($series, $embeddedVideo, 1, 6);
        $liveEpisode = new SeriesEpisode($series, $liveVideo, 1, 7);

        try {
            self::assertSame([], $settingsManager->updateContentOptions([
                'video_player_aspect_ratio' => '9:16',
                'video_player_max_width' => '800',
                'video_player_color_scheme' => 'forest',
                'video_player_watermark_enabled' => '1',
            ]));
            foreach ([
                $dramaGenre,
                $documentaryGenre,
                $series,
                $draftSeries,
                $video,
                $draftVideo,
                $videoInDraftSeries,
                $draftStatusVideo,
                $fallbackVideo,
                $streamVideo,
                $liveVideo,
                $embeddedVideo,
                $publishedEpisode,
                $draftVideoEpisode,
                $draftSeriesEpisode,
                $draftStatusEpisode,
                $fallbackEpisode,
                $streamEpisode,
                $streamQuality,
                $liveEpisode,
                $embeddedEpisode,
            ] as $content) {
                $entityManager->persist($content);
            }
            $entityManager->flush();

            $client->request('GET', '/series/'.$seriesSlug.'/artwork');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'image/png');
            self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
            self::assertSame('max-age=300, public', $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame($artwork, $client->getResponse()->getContent());

            $client->request('GET', '/series/'.$draftSeriesSlug.'/artwork');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/series');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString($seriesTitle, $client->getResponse()->getContent());
            self::assertStringNotContainsString($draftSeriesTitle, $client->getResponse()->getContent());
            self::assertSelectorExists('main img.video-thumbnail');

            $client->request('GET', '/series/'.$seriesSlug);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Drama', $client->getResponse()->getContent());
            self::assertStringContainsString('Series episode title', $client->getResponse()->getContent());
            self::assertStringContainsString('Series episode description', $client->getResponse()->getContent());
            self::assertStringContainsString($fallbackTitle, $client->getResponse()->getContent());
            self::assertStringNotContainsString($draftVideoTitle, $client->getResponse()->getContent());
            self::assertStringNotContainsString($draftStatusTitle, $client->getResponse()->getContent());

            $client->request('GET', '/series/'.$seriesSlug.'/season/1/episode/1');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Series episode title', $client->getResponse()->getContent());
            self::assertStringContainsString('Series episode description', $client->getResponse()->getContent());

            $client->request('GET', '/series/'.$seriesSlug.'/season/1/episode/2');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/series/'.$seriesSlug.'/season/1/episode/3');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/series/'.$seriesSlug.'/season/1/episode/4');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString($fallbackTitle, $client->getResponse()->getContent());
            self::assertStringContainsString('Fallback episode description.', $client->getResponse()->getContent());

            $client->request('GET', '/series/'.$seriesSlug.'/season/1/episode/5');
            self::assertResponseIsSuccessful();
            $episodeStreamPlayer = $client->getCrawler()->filter('video[data-testid="episode-video-stream"]');
            self::assertSame('https://media.example.org/episodes/stream-'.$suffix.'.mp4', $episodeStreamPlayer->filter('source')->attr('src'));
            self::assertSame('9:16', $episodeStreamPlayer->attr('data-player-aspect-ratio'));
            self::assertSame('800', $episodeStreamPlayer->attr('data-player-max-width'));
            self::assertSelectorExists('select[data-video-quality-selector] option[value="https://cdn.example.net/episodes/stream-'.$suffix.'-720.mp4"]');
            self::assertSelectorExists('.video-stage[data-player-color-scheme="forest"] [data-testid="video-player-watermark"]');
            self::assertStringContainsString("media-src 'self' https://media.example.org", (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertStringContainsString('https://cdn.example.net', (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));

            $client->request('GET', '/series/'.$seriesSlug.'/season/1/episode/7');
            self::assertResponseIsSuccessful();
            $episodeHlsPlayer = $client->getCrawler()->filter('video[data-testid="episode-video-stream"][data-hls-url]');
            self::assertSame('9:16', $episodeHlsPlayer->attr('data-player-aspect-ratio'));
            self::assertSame('800', $episodeHlsPlayer->attr('data-player-max-width'));
            self::assertSame('https://media.example.org/episodes/live-'.$suffix.'.m3u8', $episodeHlsPlayer->attr('data-hls-url'));
            self::assertContains($episodeHlsPlayer->attr('data-hls-message-error'), [
                'Der Live-Stream ist derzeit nicht verfügbar. Bitte versuche es später erneut.',
                'This live stream is currently unavailable. Please try again later.',
            ]);
            self::assertStringContainsString("connect-src 'self' https://media.example.org", (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));

            $client->request('GET', '/series/'.$seriesSlug.'/season/1/episode/6');
            self::assertResponseIsSuccessful();
            self::assertSame(
                'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
                $client->getCrawler()->filter('iframe[data-testid="episode-video-embed"]')->attr('src'),
            );
            self::assertSelectorNotExists('[data-testid="video-player-watermark"]');
            self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));

            $client->request('GET', '/series/'.$draftSeriesSlug);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $settingsManager->updateContentOptions([
                'video_player_aspect_ratio' => $originalPlayerRatio,
                'video_player_max_width' => $originalPlayerWidth,
                'video_player_color_scheme' => $originalPlayerColorScheme,
                'video_player_watermark_enabled' => $originalPlayerWatermark,
            ]);
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $cleanupManager);
            $connection = $cleanupManager->getConnection();
            $connection->executeStatement('DELETE FROM videos WHERE id IN (:published, :draft, :draft_series, :draft_status, :fallback, :stream, :live, :embedded)', [
                'published' => $video->getId(),
                'draft' => $draftVideo->getId(),
                'draft_series' => $videoInDraftSeries->getId(),
                'draft_status' => $draftStatusVideo->getId(),
                'fallback' => $fallbackVideo->getId(),
                'stream' => $streamVideo->getId(),
                'live' => $liveVideo->getId(),
                'embedded' => $embeddedVideo->getId(),
            ]);
            $connection->executeStatement('DELETE FROM content_series WHERE id IN (:published, :draft)', [
                'published' => $series->getId(),
                'draft' => $draftSeries->getId(),
            ]);
            $connection->executeStatement('DELETE FROM series_genres WHERE id IN (:drama, :documentary)', [
                'drama' => $dramaGenre->getId(),
                'documentary' => $documentaryGenre->getId(),
            ]);
        }
    }
}
