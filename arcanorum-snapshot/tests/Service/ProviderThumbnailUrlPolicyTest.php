<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\VideoThumbnailResolution;
use App\Service\ProviderThumbnailUrlPolicy;
use PHPUnit\Framework\TestCase;

final class ProviderThumbnailUrlPolicyTest extends TestCase
{
    public function testYouTubeSelectionUsesRequestedSizeAndRejectsUntrustedThumbnailHosts(): void
    {
        $policy = new ProviderThumbnailUrlPolicy();
        $videoId = 'dQw4w9WgXcQ';
        $metadata = (object) [
            'thumbnails' => (object) [
                'default' => (object) ['url' => 'https://i.ytimg.com/vi/'.$videoId.'/default.jpg'],
                'medium' => (object) ['url' => 'https://i.ytimg.com/vi/'.$videoId.'/mqdefault.jpg'],
                'high' => (object) ['url' => 'https://i.ytimg.com/vi/'.$videoId.'/hqdefault.jpg'],
                'standard' => (object) ['url' => 'https://i.ytimg.com/vi/'.$videoId.'/sddefault.jpg'],
                'maxres' => (object) ['url' => 'https://i.ytimg.com/vi/'.$videoId.'/maxresdefault.jpg'],
            ],
        ];

        self::assertSame(
            'https://i.ytimg.com/vi/'.$videoId.'/mqdefault.jpg',
            $policy->select('youtube', $metadata, VideoThumbnailResolution::Small, $videoId),
        );
        self::assertSame(
            'https://i.ytimg.com/vi/'.$videoId.'/sddefault.jpg',
            $policy->select('youtube', $metadata, VideoThumbnailResolution::Medium, $videoId),
        );
        self::assertSame(
            'https://i.ytimg.com/vi/'.$videoId.'/maxresdefault.jpg',
            $policy->select('youtube', $metadata, VideoThumbnailResolution::Large, $videoId),
        );
        self::assertSame(
            'https://i.ytimg.com/vi/'.$videoId.'/maxresdefault.jpg',
            $policy->select('youtube', $metadata, VideoThumbnailResolution::ExtraLarge, $videoId),
        );
        self::assertSame(
            'https://i.ytimg.com/vi/'.$videoId.'/maxresdefault.jpg',
            $policy->select('youtube', $metadata, VideoThumbnailResolution::Original, $videoId),
        );
        self::assertSame(
            'https://i.ytimg.com/vi/'.$videoId.'/sddefault.jpg',
            $policy->select('youtube', (object) ['thumbnails' => (object) [
                'standard' => (object) ['url' => 'https://i.ytimg.com/vi/'.$videoId.'/sddefault.jpg'],
                'maxres' => (object) ['url' => 'https://attacker.example/vi/'.$videoId.'/maxresdefault.jpg'],
            ]], VideoThumbnailResolution::Original, $videoId),
        );
        self::assertNull($policy->select('youtube', (object) ['thumbnails' => (object) [
            'high' => (object) ['url' => 'https://attacker.example/image.jpg'],
        ]], VideoThumbnailResolution::Large, $videoId));
    }

    public function testVimeoSelectionChoosesClosestApprovedSizeAndLargestOriginal(): void
    {
        $policy = new ProviderThumbnailUrlPolicy();
        $metadata = (object) ['pictures' => (object) ['sizes' => [
            (object) ['width' => 200, 'link' => 'https://i.vimeocdn.com/video/123456789_200x150.jpg'],
            (object) ['width' => 320, 'link' => 'https://i.vimeocdn.com/video/123456789_320x180.jpg'],
            (object) ['width' => 640, 'link' => 'https://i.vimeocdn.com/video/123456789_640x360.jpg'],
            (object) ['width' => 1280, 'link' => 'https://i.vimeocdn.com/video/123456789_1280x720.jpg'],
            (object) ['width' => 1920, 'link' => 'http://i.vimeocdn.com/video/123456789_1920x1080.jpg'],
        ]]];

        self::assertSame(
            'https://i.vimeocdn.com/video/123456789_320x180.jpg',
            $policy->select('vimeo', $metadata, VideoThumbnailResolution::Small, '123'),
        );
        self::assertSame(
            'https://i.vimeocdn.com/video/123456789_640x360.jpg',
            $policy->select('vimeo', $metadata, VideoThumbnailResolution::Medium, '123'),
        );
        self::assertSame(
            'https://i.vimeocdn.com/video/123456789_1280x720.jpg',
            $policy->select('vimeo', $metadata, VideoThumbnailResolution::Large, '123'),
        );
        self::assertSame(
            'https://i.vimeocdn.com/video/123456789_1280x720.jpg',
            $policy->select('vimeo', $metadata, VideoThumbnailResolution::ExtraLarge, '123'),
        );
        self::assertSame(
            'https://i.vimeocdn.com/video/123456789_1280x720.jpg',
            $policy->select('vimeo', $metadata, VideoThumbnailResolution::Original, '123'),
        );
    }

    public function testDailymotionUsesProviderSizedFieldAndRejectsUnapprovedCdnShapes(): void
    {
        $policy = new ProviderThumbnailUrlPolicy();
        $metadata = (object) [
            'thumbnail_120_url' => 'https://s1.dmcdn.net/v/ABCD123/x120',
            'thumbnail_180_url' => 'https://s1.dmcdn.net/v/ABCD123/x180',
            'thumbnail_240_url' => 'https://s1.dmcdn.net/v/ABCD123/x240',
            'thumbnail_360_url' => 'https://s2.dmcdn.net/v/ABCD123/x360',
            'thumbnail_480_url' => 'https://s2.dmcdn.net/v/ABCD123/x480',
            'thumbnail_720_url' => 'https://s2.dmcdn.net/v/ABCD123/x720',
            'thumbnail_1080_url' => 'https://s3.dmcdn.net/v/ABCD123/x1080',
        ];

        self::assertSame(
            'https://s2.dmcdn.net/v/ABCD123/x360',
            $policy->select('dailymotion', $metadata, VideoThumbnailResolution::Small, 'ABCD123'),
        );
        self::assertSame(
            'https://s2.dmcdn.net/v/ABCD123/x720',
            $policy->select('dailymotion', $metadata, VideoThumbnailResolution::Medium, 'ABCD123'),
        );
        self::assertSame(
            'https://s3.dmcdn.net/v/ABCD123/x1080',
            $policy->select('dailymotion', $metadata, VideoThumbnailResolution::Large, 'ABCD123'),
        );
        self::assertSame(
            'https://s3.dmcdn.net/v/ABCD123/x1080',
            $policy->select('dailymotion', $metadata, VideoThumbnailResolution::ExtraLarge, 'ABCD123'),
        );
        self::assertSame(
            'https://s3.dmcdn.net/v/ABCD123/x1080',
            $policy->select('dailymotion', $metadata, VideoThumbnailResolution::Original, 'ABCD123'),
        );
        self::assertFalse($policy->isAllowedUrl('dailymotion', 'https://dmcdn.net/v/ABCD123/x720'));
        self::assertFalse($policy->isAllowedUrl('dailymotion', 'https://s1.dmcdn.net/v/../private'));
        self::assertFalse($policy->isAllowedUrl('youtube', 'https://i.ytimg.com:443/vi/dQw4w9WgXcQ/hqdefault.jpg', 'dQw4w9WgXcQ'));
        self::assertFalse($policy->isAllowedUrl('youtube', 'https://user@i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', 'dQw4w9WgXcQ'));
        self::assertFalse($policy->isAllowedUrl('youtube', 'https://i.ytimg.com/vi/anotherId/hqdefault.jpg', 'dQw4w9WgXcQ'));
    }
}
