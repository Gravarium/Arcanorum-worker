<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ExternalVideoSourceStatus;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use PHPUnit\Framework\TestCase;

final class VideoExternalSourceStatusTest extends TestCase
{
    public function testSourceHealthIsStoredSeparatelyAndResetWhenSourceChanges(): void
    {
        $video = new Video('Titel', '', null, VideoSourceType::Embed, 'https://youtu.be/dQw4w9WgXcQ', PublishingStatus::Draft, false, true);
        $checkedAt = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $video->markExternalSourceStatus(ExternalVideoSourceStatus::Unavailable, $checkedAt);

        self::assertSame(ExternalVideoSourceStatus::Unavailable, $video->getExternalSourceStatus());
        self::assertSame($checkedAt, $video->getExternalSourceCheckedAt());
        $video->markExternalSourceCheckFailed($checkedAt->modify('+1 hour'));

        $video->revise('Titel', '', null, VideoSourceType::Embed, 'https://youtu.be/abcdefghijk', PublishingStatus::Draft, false, true);

        self::assertNull($video->getExternalSourceStatus());
        self::assertNull($video->getExternalSourceCheckedAt());
        self::assertFalse($video->isExternalSourceCheckFailed());
    }

    public function testSourceHealthCannotBeAppliedToUploadedVideo(): void
    {
        $video = new Video('Titel', '', null, VideoSourceType::File, '', PublishingStatus::Draft, false, true);

        $this->expectException(\LogicException::class);
        $video->markExternalSourceStatus(ExternalVideoSourceStatus::Available, new \DateTimeImmutable());
    }

    public function testFailedCheckIsRecordedWithoutDiscardingTheLastKnownStatus(): void
    {
        $video = new Video('Titel', '', null, VideoSourceType::Embed, 'https://youtu.be/dQw4w9WgXcQ', PublishingStatus::Draft, false, true);
        $previousCheck = new \DateTimeImmutable('2026-10-27T12:00:00+00:00');
        $failedAttempt = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $video->markExternalSourceStatus(ExternalVideoSourceStatus::Unavailable, $previousCheck);

        $video->markExternalSourceCheckFailed($failedAttempt);

        self::assertSame(ExternalVideoSourceStatus::Unavailable, $video->getExternalSourceStatus());
        self::assertSame($failedAttempt, $video->getExternalSourceCheckedAt());
        self::assertTrue($video->isExternalSourceCheckFailed());

        $nextCheck = $failedAttempt->modify('+1 hour');
        $video->markExternalSourceStatus(ExternalVideoSourceStatus::Available, $nextCheck);

        self::assertSame(ExternalVideoSourceStatus::Available, $video->getExternalSourceStatus());
        self::assertSame($nextCheck, $video->getExternalSourceCheckedAt());
        self::assertFalse($video->isExternalSourceCheckFailed());
    }
}
