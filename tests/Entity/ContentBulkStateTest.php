<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use PHPUnit\Framework\TestCase;

final class ContentBulkStateTest extends TestCase
{
    public function testNewsBulkableStateMethodsUpdatePublicationAndHighlight(): void
    {
        $article = new NewsArticle(
            'Bulk state article',
            'bulk-state-article',
            null,
            null,
            'Body',
            PublishingStatus::Draft,
            false,
            false,
        );

        $article->setPublishingStatus(PublishingStatus::Published);
        $article->setFeatured(true);

        self::assertSame(PublishingStatus::Published, $article->getStatus());
        self::assertNotNull($article->getPublishedAt());
        self::assertTrue($article->isFeatured());

        $article->setPublishingStatus(PublishingStatus::Draft);

        self::assertSame(PublishingStatus::Draft, $article->getStatus());
        self::assertNull($article->getPublishedAt());
    }

    public function testVideoBulkableStateMethodsUpdatePublicationAndHighlight(): void
    {
        $video = new Video(
            'Bulk state video',
            'Description',
            null,
            VideoSourceType::Url,
            'https://video.example.test/bulk.mp4',
            PublishingStatus::Draft,
            false,
            false,
        );

        $video->setPublishingStatus(PublishingStatus::Published);
        $video->setFeatured(true);

        self::assertSame(PublishingStatus::Published, $video->getStatus());
        self::assertNotNull($video->getPublishedAt());
        self::assertTrue($video->isFeatured());

        $video->setPublishingStatus(PublishingStatus::Draft);

        self::assertSame(PublishingStatus::Draft, $video->getStatus());
        self::assertNull($video->getPublishedAt());
    }
}
