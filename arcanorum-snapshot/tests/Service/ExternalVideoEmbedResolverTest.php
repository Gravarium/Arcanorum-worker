<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ExternalVideoEmbedResolver;
use PHPUnit\Framework\TestCase;

final class ExternalVideoEmbedResolverTest extends TestCase
{
    public function testCanonicalizesSupportedYoutubeUrlShapes(): void
    {
        $resolver = new ExternalVideoEmbedResolver();
        $sources = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=share-token',
            'https://youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        ];

        foreach ($sources as $source) {
            self::assertSame(
                'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
                $resolver->resolve($source),
            );
        }
    }

    public function testCanonicalizesVimeoAndKeepsOnlyAValidPrivacyHash(): void
    {
        $resolver = new ExternalVideoEmbedResolver();

        self::assertSame(
            'https://player.vimeo.com/video/123456789',
            $resolver->resolve('https://vimeo.com/123456789'),
        );
        self::assertSame(
            'https://player.vimeo.com/video/123456789?h=abc123',
            $resolver->resolve('https://player.vimeo.com/video/123456789?h=abc123&autoplay=1'),
        );
    }

    public function testCanonicalizesDailymotionVideoOnlyWithConfiguredPlayerId(): void
    {
        $resolver = new ExternalVideoEmbedResolver('x8lr5');

        self::assertSame(
            'https://geo.dailymotion.com/player/x8lr5.html?video=x84sh87',
            $resolver->resolve('https://www.dailymotion.com/video/x84sh87'),
        );
        self::assertNull(new ExternalVideoEmbedResolver()->resolve('https://www.dailymotion.com/video/x84sh87'));
    }

    public function testCanonicalizesAdditionalVideoProvidersWithoutForwardingShareParameters(): void
    {
        $resolver = new ExternalVideoEmbedResolver();
        $googleDriveId = '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms';

        self::assertSame(
            'https://www.facebook.com/video/embed?video_id=20531316728',
            $resolver->resolve('https://www.facebook.com/watch/?v=20531316728&ref=share'),
        );
        self::assertSame(
            'https://www.facebook.com/video/embed?video_id=20531316728',
            $resolver->resolve('https://facebook.com/some.creator/videos/20531316728/'),
        );
        self::assertSame(
            'https://drive.google.com/file/d/'.$googleDriveId.'/preview',
            $resolver->resolve('https://drive.google.com/file/d/'.$googleDriveId.'/view?usp=sharing'),
        );
        self::assertSame(
            'https://drive.google.com/file/d/'.$googleDriveId.'/preview?resourcekey=0-example_resource-1',
            $resolver->resolve('https://drive.google.com/open?id='.$googleDriveId.'&resourcekey=0-example_resource-1&usp=sharing'),
        );
        self::assertSame(
            'https://rutube.ru/play/embed/852e974534e3527f16810a7a19c418b0',
            $resolver->resolve('https://rutube.ru/video/852E974534E3527F16810A7A19C418B0/'),
        );
        self::assertSame(
            'https://vk.com/video_ext.php?oid=-123456&id=456239017',
            $resolver->resolve('https://vkvideo.ru/video-123456_456239017?list=shared-list'),
        );
        self::assertSame(
            'https://vk.com/video_ext.php?oid=-123456&id=456239017&hash=abc_123',
            $resolver->resolve('https://vk.com/video_ext.php?oid=-123456&id=456239017&hash=abc_123'),
        );
        self::assertSame(
            'https://player.youku.com/embed/XNDI0MDAwMDAwMA==',
            $resolver->resolve('https://v.youku.com/v_show/id_XNDI0MDAwMDAwMA==.html'),
        );
        self::assertSame(
            [
                'https://www.youtube-nocookie.com',
                'https://player.vimeo.com',
                'https://geo.dailymotion.com',
                'https://www.facebook.com',
                'https://drive.google.com',
                'https://rutube.ru',
                'https://vk.com',
                'https://player.youku.com',
            ],
            $resolver->allowedFrameOrigins(),
        );
    }

    public function testDoesNotForwardArbitraryYoutubeQueryParameters(): void
    {
        $resolver = new ExternalVideoEmbedResolver();

        self::assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            $resolver->resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ&autoplay=1&origin=https%3A%2F%2Fevil.example'),
        );
    }

    public function testCatalogLegacyAndGenericMediaEntriesAreNeverInterpretedAsEmbeds(): void
    {
        $legacySources = [
            'https://localhost/video.flv',
            'https://media.example.test/video.flv',
            'https://www.metacafe.com/watch/123/video-title/',
            'https://myspace.com/video/channel/video-title/123456781',
            'https://www.veoh.com/watch/v123456',
            'https://www.break.com/index/video-title.html',
            'https://www.myvideo.de/watch/123/video-title/',
            'https://www.nhaccuatui.com/mv4u/xem-clip/abc123/video.html',
            'https://www.kure.tv/otomobil/test/87652/',
            'https://media.example.test/video.wmv',
            'https://veevr.com/videos/videoID',
            'https://www.123video.nl/playvideos.asp?MovieID=1234567',
            'https://www.trilulilu.ro/user/videoID',
            'https://blip.tv/user/video-title-123',
            'https://en.sevenload.com/videos/123-title',
            'https://www.funnyordie.com/videos/123',
            'https://clip.vn/watch/Video-title,videoID',
            'https://www.filebox.ro/video/play_video.php?key=videoID',
            'https://www.tudou.com/programs/view/video-id/',
            'https://www.publicdomainflicks.com/0123-video-title/',
            'https://www.liveleak.com/view?i=videoID',
            'https://video.balsas.lt/video/123',
            'https://www.peteava.ro/id-123456-video-title',
            'https://www.musicme.com/video/123',
            'https://www.spike.com/video/cinemassacre-top-10/123',
            'https://www.videozer.com/video/abcde',
            'https://en.musicplayon.com/play?v=123-title',
            'https://media.example.test/video.mov',
            'https://new.music.yahoo.com/videos/artist/title-123',
            'https://www.5min.com/Video/Video-Title-123',
            'https://vplay.ro/watch/abcdef/',
            'https://smotri.com/video/view/?id=123',
            'https://media.example.test/video.avi',
            'https://vbox7.com/play:videoID',
            'https://www.cloudy.ec/v/videoID',
            'https://www.livestream.com/channel_name',
            'https://www.ustream.tv/channel/user',
            'https://media.example.test/audio.mp3',
            'https://video.mynet.com/user/title/video-id/',
            'https://www.vidivodo.com/video-id/title',
            'https://www.izlesene.com/video/title/video-id',
            'https://www.videobb.com/video/video-id',
            'https://my.mail.ru/video/mail/user/176/254.html',
            'https://www.novamov.com/video/video-id',
            'https://www.myvideo.ge/?video_id=123',
            'https://www.vevo.com/watch/123',
            'https://imgur.com/abcde',
            'https://gfycat.com/animated-name',
        ];
        self::assertCount(48, $legacySources);

        $resolver = new ExternalVideoEmbedResolver('x8lr5');
        foreach ($legacySources as $source) {
            self::assertNull($resolver->resolve($source), $source);
        }
    }

    public function testRejectsUntrustedHostsIdentifiersAndAmbiguousQueryValues(): void
    {
        $resolver = new ExternalVideoEmbedResolver();
        $unsafeSources = [
            'http://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
            'https://evil.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=short',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&v=aaaaaaaaaaa',
            'https://www.youtube.com/embed/%2F%2Fevil',
            'https://vimeo.com.evil.example/123456789',
            'https://player.vimeo.com/video/123456789?h=valid1&h=valid2',
            'https://player.vimeo.com/video/123456789?h=%2F%2Fevil',
            'https://www.dailymotion.com.evil.example/video/x84sh87',
            'https://www.dailymotion.com/video/x84sh87?player=evil',
            'https://www.dailymotion.com/video/x84sh87#fragment',
            'https://geo.dailymotion.com/player/x8lr5.html?video=https%3A%2F%2Fevil.example',
            'https://www.facebook.com.evil.example/watch/?v=20531316728',
            'https://www.facebook.com/watch/?v=20531316728&v=20531316729',
            'https://www.facebook.com/video/embed?video_id=20531316728#fragment',
            'https://drive.google.com.evil.example/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/view',
            'https://drive.google.com/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/view?id=another-file',
            'https://drive.google.com/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/view?resourcekey=%2F%2Fevil',
            'https://drive.google.com/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/view?usp=sharing&unexpected=1',
            'https://rutube.ru.evil.example/video/852e974534e3527f16810a7a19c418b0',
            'https://rutube.ru/video/not-a-video-id',
            'https://vk.com.evil.example/video-123456_456239017',
            'https://vk.com/video_ext.php?oid=-123456&id=456239017&id=456239018',
            'https://vk.com/video_ext.php?oid=-123456&id=456239017&hash=%2F%2Fevil',
            'https://player.youku.com.evil.example/embed/XNDI0MDAwMDAwMA==',
            'https://v.youku.com/v_show/id_short.html',
            'https://rutube.ru/video/852e974534e3527f16810a7a19c418b0#fragment',
            'https://youtu.be:99999/dQw4w9WgXcQ',
            'https://user@youtu.be/dQw4w9WgXcQ',
            'https://youtu.be:444/dQw4w9WgXcQ',
            'https://127.0.0.1/embed/dQw4w9WgXcQ',
            'javascript:alert(1)',
        ];

        foreach ($unsafeSources as $source) {
            self::assertNull($resolver->resolve($source), $source);
        }
    }
}
