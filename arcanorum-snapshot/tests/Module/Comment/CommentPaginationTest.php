<?php

declare(strict_types=1);

namespace App\Tests\Module\Comment;

use App\Module\Comment\CommentPagination;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CommentPaginationTest extends TestCase
{
    public function testPageCountIncludesLargeCommentCollections(): void
    {
        self::assertSame(5001, CommentPagination::pageCount(50_001, 10));
    }

    public function testRequestedPageIsBoundedByTheAvailablePageCount(): void
    {
        $request = Request::create('/news/example?comment_page=5001');

        self::assertSame(5001, CommentPagination::selectedPage($request, 5001));
        self::assertSame(3, CommentPagination::selectedPage($request, 3));
        self::assertSame(1, CommentPagination::selectedPage($request, 0));
    }

    public function testMalformedOrOutOfRangePageParametersAreIgnored(): void
    {
        foreach ([
            '/news/example?comment_page[]=2',
            '/news/example?comment_page=0',
            '/news/example?comment_page=-2',
            '/news/example?comment_page='.str_repeat('9', strlen((string) PHP_INT_MAX)),
        ] as $uri) {
            self::assertNull(CommentPagination::pageParameter(Request::create($uri)), $uri);
        }
    }
}
