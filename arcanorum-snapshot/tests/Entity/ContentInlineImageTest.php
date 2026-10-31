<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\ContentInlineImage;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\StaticPage;
use PHPUnit\Framework\TestCase;

final class ContentInlineImageTest extends TestCase
{
    public function testStoresOnlyValidImageMetadataWithExactlyOneOwner(): void
    {
        $article = new NewsArticle('News', 'news', null, null, 'Body', PublishingStatus::Draft, false, false);
        $page = new StaticPage('Page', 'page', null, 'Body', PublishingStatus::Draft);
        $category = new ContentCategory('Category', ContentCategoryType::News);
        $id = str_repeat('a', 64);

        $image = new ContentInlineImage($id, $article, null, 'image/png', 128, str_repeat('b', 64));

        self::assertSame($id, $image->getId());
        self::assertSame($article, $image->getNewsArticle());
        self::assertNull($image->getStaticPage());
        self::assertNull($image->getCategory());
        self::assertSame('image/png', $image->getMimeType());
        self::assertSame(128, $image->getSize());
        self::assertSame(str_repeat('b', 64), $image->getSha256());
        self::assertGreaterThan(0, $image->getCreatedAt()->getTimestamp());
        self::assertNotSame($article->getId(), $page->getId());

        $categoryImage = new ContentInlineImage(str_repeat('c', 64), null, null, 'image/webp', 256, str_repeat('d', 64), $category);
        self::assertNull($categoryImage->getNewsArticle());
        self::assertNull($categoryImage->getStaticPage());
        self::assertSame($category, $categoryImage->getCategory());
    }

    public function testRejectsMissingOrMultipleOwners(): void
    {
        $article = new NewsArticle('News', 'news', null, null, 'Body', PublishingStatus::Draft, false, false);
        $page = new StaticPage('Page', 'page', null, 'Body', PublishingStatus::Draft);
        $category = new ContentCategory('Category', ContentCategoryType::News);

        $this->assertInvalidOwner($article, $page);
        $this->assertInvalidOwner(null, null);
        $this->assertInvalidThreeWayOwner($article, $page, $category);
    }

    public function testRejectsUnsafeOrInvalidImageMetadata(): void
    {
        $article = new NewsArticle('News', 'news', null, null, 'Body', PublishingStatus::Draft, false, false);
        $validId = str_repeat('a', 64);

        $digest = str_repeat('b', 64);
        $this->assertInvalidImage($article, $validId, 'image/svg+xml', 128, $digest);
        $this->assertInvalidImage($article, $validId, 'image/png', 0, $digest);
        $this->assertInvalidImage($article, $validId, 'image/png', 4_194_305, $digest);
        $this->assertInvalidImage($article, $validId, 'image/png', 128, 'not-a-digest');
        $this->assertInvalidImage($article, '../'.str_repeat('a', 62), 'image/png', 128, $digest);
    }

    private function assertInvalidOwner(?NewsArticle $article, ?StaticPage $page): void
    {
        try {
            new ContentInlineImage(str_repeat('a', 64), $article, $page, 'image/png', 128, str_repeat('b', 64));
            self::fail('Exactly one content owner is required.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('genau einem', $exception->getMessage());
        }
    }

    private function assertInvalidThreeWayOwner(NewsArticle $article, StaticPage $page, ContentCategory $category): void
    {
        try {
            new ContentInlineImage(str_repeat('a', 64), $article, null, 'image/png', 128, str_repeat('b', 64), $category);
            self::fail('An inline image cannot have two owners.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('genau einem', $exception->getMessage());
        }

        try {
            new ContentInlineImage(str_repeat('a', 64), null, $page, 'image/png', 128, str_repeat('b', 64), $category);
            self::fail('An inline image cannot have two owners.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('genau einem', $exception->getMessage());
        }
    }

    private function assertInvalidImage(
        NewsArticle $article,
        string $id,
        string $mimeType,
        int $size,
        string $digest,
    ): void {
        try {
            new ContentInlineImage($id, $article, null, $mimeType, $size, $digest);
            self::fail('Invalid image metadata must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Inline-Bild', $exception->getMessage());
        }
    }
}
