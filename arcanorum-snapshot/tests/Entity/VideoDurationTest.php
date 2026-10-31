<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use PHPUnit\Framework\TestCase;

final class VideoDurationTest extends TestCase
{
    public function testStoresAndClearsAValidDuration(): void
    {
        $video = $this->video();

        $video->setDurationSeconds(120);
        self::assertSame(120, $video->getDurationSeconds());

        $video->setDurationSeconds(null);
        self::assertNull($video->getDurationSeconds());
    }

    public function testRejectsDurationOutsideTheDatabaseIntegerRange(): void
    {
        $video = $this->video();

        foreach ([-1, 2147483648] as $duration) {
            try {
                $video->setDurationSeconds($duration);
                self::fail('The duration setter must reject values outside the database range.');
            } catch (\InvalidArgumentException) {
                self::assertNull($video->getDurationSeconds());
            }
        }
    }

    private function video(): Video
    {
        return new Video(
            'Duration test video',
            'Duration test fixture.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/duration.mp4',
            PublishingStatus::Draft,
            false,
            false,
        );
    }
}
