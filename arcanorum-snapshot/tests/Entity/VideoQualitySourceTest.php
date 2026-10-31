<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoQuality;
use App\Entity\VideoQualitySource;
use App\Entity\VideoSourceType;
use PHPUnit\Framework\TestCase;

final class VideoQualitySourceTest extends TestCase
{
    public function testFixedQualityValuesHaveAscendingPixelHeights(): void
    {
        self::assertSame(
            [240, 360, 480, 720, 1080, 1440, 2160],
            array_map(static fn (VideoQuality $quality): int => $quality->height(), VideoQuality::cases()),
        );
    }

    public function testAlternateSourceRequiresAndStoresASafeHttpsAddress(): void
    {
        $video = $this->video();
        $source = new VideoQualitySource($video, VideoQuality::P720, ' https://media.example.org/video-720.mp4 ');

        self::assertSame('https://media.example.org/video-720.mp4', $source->getSourceUrl());
        self::assertSame(VideoQuality::P720, $source->getResolution());
        self::assertTrue(VideoQualitySource::isValidSourceUrl('https://media.example.org/video.mp4'));
        self::assertFalse(VideoQualitySource::isValidSourceUrl('http://media.example.org/video.mp4'));
        self::assertFalse(VideoQualitySource::isValidSourceUrl('https://user@media.example.org/video.mp4'));
        self::assertFalse(VideoQualitySource::isValidSourceUrl('https://media.example.org/'.str_repeat('x', 2050)));
        self::assertFalse(VideoQualitySource::isValidSourceUrl('https://media.example.org/'.str_repeat('漢', 800)));
    }

    public function testRevisionChangesQualityAndSourceWithoutChangingVideoOwnership(): void
    {
        $video = $this->video();
        $source = new VideoQualitySource($video, VideoQuality::P720, 'https://media.example.org/video-720.mp4');
        $source->revise(VideoQuality::P1080, 'https://media.example.org/video-1080.mp4');

        self::assertSame($video, $source->getVideo());
        self::assertSame(VideoQuality::P1080, $source->getResolution());
        self::assertSame('https://media.example.org/video-1080.mp4', $source->getSourceUrl());
    }

    private function video(): Video
    {
        return new Video(
            'Quality test video',
            'A test video.',
            null,
            VideoSourceType::Stream,
            'https://media.example.org/video.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
    }
}
