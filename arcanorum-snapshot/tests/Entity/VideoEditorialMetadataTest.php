<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use PHPUnit\Framework\TestCase;

final class VideoEditorialMetadataTest extends TestCase
{
    public function testMemberOnlyEditorialChangesRemoveExternalAllowlist(): void
    {
        $video = $this->publishedVideo();
        $video->setEmbedAllowedOrigins(['https://example.org']);

        self::assertTrue($video->isExternallyEmbeddable());

        $video->updateEditorialMetadata('staff-video', 'Editorial Desk', true, true);

        self::assertSame('staff-video', $video->getSlug());
        self::assertSame('Editorial Desk', $video->getAuthorName());
        self::assertTrue($video->isMembersOnly());
        self::assertTrue($video->isEmbeddingEnabled());
        self::assertSame([], $video->getEmbedAllowedOrigins());
        self::assertFalse($video->isExternallyEmbeddable());
    }

    public function testEditorialMetadataRejectsMalformedSlugAndAuthor(): void
    {
        $video = $this->publishedVideo();
        $video->updateEditorialMetadata('original-slug', 'Editorial Desk', false, true);

        try {
            $video->updateEditorialMetadata('Not safe', 'Editorial Desk', false, false);
            self::fail('A permalink with spaces must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame('Editorial Desk', $video->getAuthorName());
        }

        $this->expectException(\InvalidArgumentException::class);
        $video->updateEditorialMetadata('safe-slug', "Unsafe\nAuthor", false, false);
    }

    public function testEditorialViewCountIsBounded(): void
    {
        $video = $this->publishedVideo();
        $video->setViewCount(1234);

        self::assertSame(1234, $video->getViewCount());

        $this->expectException(\InvalidArgumentException::class);
        $video->setViewCount(-1);
    }

    public function testEditorialLanguageCodeIsValidatedAndPersisted(): void
    {
        $video = $this->publishedVideo();
        $video->updateEditorialMetadata('safe-video', 'Editorial Desk', false, false, 'de-AT');

        self::assertSame('de-AT', $video->getLanguageCode());

        $this->expectException(\InvalidArgumentException::class);
        $video->updateEditorialMetadata('safe-video', 'Editorial Desk', false, false, 'not a language');
    }

    public function testVideoKeepsLegacyPrimaryCategoryAndSupportsSeveralVideoCategories(): void
    {
        $video = $this->publishedVideo();
        $first = new ContentCategory('Culture', ContentCategoryType::Video);
        $second = new ContentCategory('Interviews', ContentCategoryType::Video);

        $video->replaceCategories([$first, $second]);

        self::assertSame('Culture', $video->getCategory());
        self::assertSame(['Culture', 'Interviews'], $video->getCategoryNames());
        self::assertSame([$first, $second], $video->getCategories());
    }

    public function testVideoRejectsNewsCategories(): void
    {
        $video = $this->publishedVideo();
        $newsCategory = new ContentCategory('News', ContentCategoryType::News);

        $this->expectException(\InvalidArgumentException::class);
        $video->replaceCategories([$newsCategory]);
    }

    private function publishedVideo(): Video
    {
        $video = new Video(
            'Editorial test video',
            'A test video for editorial metadata.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            PublishingStatus::Published,
            false,
            true,
        );
        $video->setPublicationAt(new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')));

        return $video;
    }
}
