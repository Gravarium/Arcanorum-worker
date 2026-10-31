<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use PHPUnit\Framework\TestCase;

final class ContentCategoryTest extends TestCase
{
    public function testSlugIsGeneratedOnceAndTaxonomyMetadataIsBounded(): void
    {
        $category = new ContentCategory('News and Culture', ContentCategoryType::News);
        self::assertSame('news-and-culture', $category->getSlug());

        $category->updateTaxonomy(
            'culture-news',
            'Arts, events, and local stories.',
            'Culture news',
            'News about arts and culture.',
            'culture, arts, events',
            25,
        );
        $category->rename('Culture and events');

        self::assertSame('culture-news', $category->getSlug());
        self::assertSame('Arts, events, and local stories.', $category->getDescription());
        self::assertSame('Culture news', $category->getMetaTitle());
        self::assertSame('News about arts and culture.', $category->getMetaDescription());
        self::assertSame('culture, arts, events', $category->getMetaKeywords());
        self::assertSame(25, $category->getSortOrder());
    }

    public function testRejectsMalformedSlugAndOutOfRangeSortOrder(): void
    {
        $category = new ContentCategory('News', ContentCategoryType::News);

        try {
            $category->updateTaxonomy('News/2026', '', null, null, null, 0);
            self::fail('A category slug must be an ASCII path segment.');
        } catch (\InvalidArgumentException) {
            self::assertSame('news', $category->getSlug());
        }

        $this->expectException(\InvalidArgumentException::class);
        $category->updateTaxonomy('news', '', null, null, null, 10001);
    }

    public function testRejectsInvalidUtf8AndControlCharacters(): void
    {
        $category = new ContentCategory('News', ContentCategoryType::News);

        try {
            $category->updateTaxonomy('news', "invalid\xFF", null, null, null, 0);
            self::fail('Category descriptions must contain valid UTF-8.');
        } catch (\InvalidArgumentException) {
            self::assertSame('', $category->getDescription());
        }

        $this->expectException(\InvalidArgumentException::class);
        $category->rename("bad\nname");
    }

    public function testParentMustUseTheSameContentTypeAndCannotCreateACycle(): void
    {
        $root = new ContentCategory('News', ContentCategoryType::News);
        $child = new ContentCategory('Local news', ContentCategoryType::News);
        $video = new ContentCategory('Video news', ContentCategoryType::Video);
        $child->setParent($root);

        self::assertSame(0, $root->getDepth());
        self::assertSame(1, $child->getDepth());

        try {
            $child->setParent($video);
            self::fail('A category cannot have a parent from another content area.');
        } catch (\InvalidArgumentException) {
            self::assertSame($root, $child->getParent());
        }

        $this->expectException(\InvalidArgumentException::class);
        $root->setParent($child);
    }

    public function testParentDepthIsLimited(): void
    {
        $parent = new ContentCategory('Level 0', ContentCategoryType::Video);
        for ($level = 1; $level <= ContentCategory::MAX_DEPTH; ++$level) {
            $child = new ContentCategory('Level '.$level, ContentCategoryType::Video);
            $child->setParent($parent);
            $parent = $child;
        }

        self::assertSame(ContentCategory::MAX_DEPTH, $parent->getDepth());
        $tooDeep = new ContentCategory('Too deep', ContentCategoryType::Video);
        $this->expectException(\InvalidArgumentException::class);
        $tooDeep->setParent($parent);
    }

    public function testOnlyVideoCategoriesCanBeFeatured(): void
    {
        $video = new ContentCategory('Featured videos', ContentCategoryType::Video);
        $video->setFeatured(true);
        self::assertTrue($video->isFeatured());
        $video->setFeatured(false);
        self::assertFalse($video->isFeatured());

        $news = new ContentCategory('Featured news', ContentCategoryType::News);
        $this->expectException(\InvalidArgumentException::class);
        $news->setFeatured(true);
    }

    public function testArtworkMetadataIsBoundedAndCanBeRemoved(): void
    {
        $category = new ContentCategory('Artwork', ContentCategoryType::Video);
        $objectId = str_repeat('a', 64);
        $sha256 = str_repeat('b', 64);
        $category->attachArtwork($objectId, 'image/webp', 512, $sha256);

        self::assertSame($objectId, $category->getArtworkObjectId());
        self::assertSame('image/webp', $category->getArtworkMimeType());
        self::assertSame(512, $category->getArtworkSize());
        self::assertSame($sha256, $category->getArtworkSha256());

        $category->removeArtwork();
        self::assertNull($category->getArtworkObjectId());
        self::assertNull($category->getArtworkMimeType());
        self::assertNull($category->getArtworkSize());
        self::assertNull($category->getArtworkSha256());
    }

    public function testRejectsArtworkForNewsCategoriesAndInvalidMetadata(): void
    {
        $news = new ContentCategory('News artwork', ContentCategoryType::News);
        try {
            $news->attachArtwork(str_repeat('a', 64), 'image/png', 8, str_repeat('b', 64));
            self::fail('News categories must not store video artwork.');
        } catch (\InvalidArgumentException) {
            self::assertNull($news->getArtworkObjectId());
        }

        $video = new ContentCategory('Video artwork', ContentCategoryType::Video);
        $this->expectException(\InvalidArgumentException::class);
        $video->attachArtwork(str_repeat('a', 64), 'image/svg+xml', 8, str_repeat('b', 64));
    }
}
