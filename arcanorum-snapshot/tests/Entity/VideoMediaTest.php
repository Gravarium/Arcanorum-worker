<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use PHPUnit\Framework\TestCase;

final class VideoMediaTest extends TestCase
{
    public function testUploadedMp3IsRecordedAsAudioAndNotAsAnExternalSource(): void
    {
        $video = new Video('Audio entry', '', null, VideoSourceType::File, '', PublishingStatus::Draft, false, true);
        $video->attachUploadedMedia(str_repeat('a', 64), 'audio/mpeg', 4096);

        self::assertTrue($video->isAudio());
        self::assertSame('audio/mpeg', $video->getUploadedMimeType());
        self::assertSame(4096, $video->getUploadedSize());

        $video->revise('Audio entry', '', null, VideoSourceType::Stream, 'https://media.example.org/audio.mp3', PublishingStatus::Draft, false, true);

        self::assertFalse($video->isAudio());
        self::assertNull($video->getUploadedMimeType());
    }

    public function testRejectsAudioMimeTypesOutsideTheMp3Format(): void
    {
        $video = new Video('Audio entry', '', null, VideoSourceType::File, '', PublishingStatus::Draft, false, true);

        $this->expectException(\InvalidArgumentException::class);
        $video->attachUploadedMedia(str_repeat('b', 64), 'audio/wav', 4096);
    }
}
