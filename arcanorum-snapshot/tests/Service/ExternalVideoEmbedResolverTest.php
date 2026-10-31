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

    public function testDoesNotForwardArbitraryYoutubeQueryParameters(): void
    {
        $resolver = new ExternalVideoEmbedResolver();

        self::assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            $resolver->resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ&autoplay=1&origin=https%3A%2F%2Fevil.example'),
        );
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
