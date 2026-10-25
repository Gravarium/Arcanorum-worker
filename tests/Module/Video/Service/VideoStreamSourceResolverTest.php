<?php

declare(strict_types=1);

namespace App\Tests\Module\Video\Service;

use App\Module\Video\Service\VideoStreamSource;
use App\Module\Video\Service\VideoStreamSourceResolver;
use PHPUnit\Framework\TestCase;

final class VideoStreamSourceResolverTest extends TestCase
{
    public function testResolvesDirectMediaOnAnExactApprovedHost(): void
    {
        $resolver = new VideoStreamSourceResolver(['Media.Example.org']);

        $source = $resolver->resolve('https://MEDIA.EXAMPLE.ORG/library/lesson.m4v?signature=public-test');

        self::assertInstanceOf(VideoStreamSource::class, $source);
        self::assertSame('https://MEDIA.EXAMPLE.ORG/library/lesson.m4v?signature=public-test', $source->url);
        self::assertSame('video/mp4', $source->mimeType);
        self::assertSame('https://media.example.org', $source->origin);
        self::assertTrue($resolver->isConfigured());
    }

    public function testResolvesSupportedDirectVideoFormats(): void
    {
        $resolver = new VideoStreamSourceResolver(['media.example.org']);
        $sources = [
            'https://media.example.org/video.mp4' => 'video/mp4',
            'https://media.example.org/video.webm' => 'video/webm',
            'https://media.example.org/video.ogv' => 'video/ogg',
            'https://media.example.org/video.ogg' => 'video/ogg',
        ];

        foreach ($sources as $url => $mimeType) {
            self::assertSame($mimeType, $resolver->resolve($url)?->mimeType, $url);
        }
    }

    public function testResolvesHlsLivePlaylists(): void
    {
        $resolver = new VideoStreamSourceResolver(['live.example.org', 'media.example.org']);

        $source = $resolver->resolve('https://live.example.org/channel/index.m3u8?token=public-test');

        self::assertInstanceOf(VideoStreamSource::class, $source);
        self::assertSame(VideoStreamSource::HLS_MIME_TYPE, $source->mimeType);
        self::assertSame('https://live.example.org', $source->origin);
        self::assertTrue($source->isHls());
        self::assertSame(['https://live.example.org', 'https://media.example.org'], $resolver->getAllowedOrigins());
    }

    public function testRejectsUnapprovedHostsUnsafeUrlsAndUnsupportedOrIndirectMedia(): void
    {
        $resolver = new VideoStreamSourceResolver(['media.example.org']);
        $rejected = [
            'http://media.example.org/video.mp4',
            'https://user@media.example.org/video.mp4',
            'https://media.example.org:444/video.mp4',
            'https://127.0.0.1/video.mp4',
            'https://localhost/video.mp4',
            'https://media.example.local/private.mp4',
            'https://unapproved.example.org/video.mp4',
            'https://media.example.org/video.mp4.exe',
            'https://media.example.org/page.html',
            'https://media.example.org/video.mp4#fragment',
            'javascript:alert(1)',
        ];

        foreach ($rejected as $url) {
            self::assertNull($resolver->resolve($url), $url);
        }
    }

    public function testUnconfiguredResolverFailsClosedAndConfigurationRejectsUnsafeHosts(): void
    {
        self::assertFalse(new VideoStreamSourceResolver([])->isConfigured());
        self::assertNull(new VideoStreamSourceResolver([])->resolve('https://media.example.org/video.mp4'));

        foreach ([['*.example.org'], ['media.example.test'], ['https://media.example.org'], ['media.example.org:443'], ['media.example.org', 'MEDIA.EXAMPLE.ORG']] as $hosts) {
            try {
                new VideoStreamSourceResolver($hosts);
            } catch (\InvalidArgumentException) {
                continue;
            }

            self::fail('Invalid or duplicate stream host configuration must be rejected.');
        }
    }
}
