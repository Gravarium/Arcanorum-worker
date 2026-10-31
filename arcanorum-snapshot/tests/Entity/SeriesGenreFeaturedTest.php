<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SeriesGenre;
use PHPUnit\Framework\TestCase;

final class SeriesGenreFeaturedTest extends TestCase
{
    public function testGenreStartsUnfeaturedAndAcceptsTheFullSortRange(): void
    {
        $genre = new SeriesGenre('Featured test');

        self::assertFalse($genre->isFeatured());
        self::assertSame(0, $genre->getFeaturedSortOrder());

        $genre->setFeatured(true, 10_000);

        self::assertTrue($genre->isFeatured());
        self::assertSame(10_000, $genre->getFeaturedSortOrder());

        $genre->setFeatured(false, 0);

        self::assertFalse($genre->isFeatured());
        self::assertSame(0, $genre->getFeaturedSortOrder());
    }

    public function testSortOrderBelowZeroIsRejected(): void
    {
        $genre = new SeriesGenre('Negative sort order');

        $this->expectException(\InvalidArgumentException::class);
        $genre->setFeatured(true, -1);
    }

    public function testSortOrderAboveTheDatabaseLimitIsRejected(): void
    {
        $genre = new SeriesGenre('Excessive sort order');

        $this->expectException(\InvalidArgumentException::class);
        $genre->setFeatured(true, 10_001);
    }
}
