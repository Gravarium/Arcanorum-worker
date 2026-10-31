<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CommentContentType;
use App\Entity\CommentStatus;
use App\Entity\ContentComment;
use PHPUnit\Framework\TestCase;

final class ContentCommentTest extends TestCase
{
    public function testThreeReportsHideAnApprovedCommentAndClearingReportsRestoresIt(): void
    {
        $comment = new ContentComment(
            CommentContentType::News,
            str_repeat('a', 32),
            'Test article',
            'Test visitor',
            'Test comment body',
        );
        $comment->approve();

        $comment->report();
        self::assertSame(CommentStatus::Approved, $comment->getStatus());
        self::assertSame(1, $comment->getReportCount());

        $comment->report();
        self::assertSame(CommentStatus::Approved, $comment->getStatus());
        self::assertSame(2, $comment->getReportCount());

        $comment->report();
        self::assertSame(CommentStatus::Reported, $comment->getStatus());
        self::assertSame(3, $comment->getReportCount());

        $comment->clearReports();
        self::assertSame(CommentStatus::Approved, $comment->getStatus());
        self::assertSame(0, $comment->getReportCount());
        self::assertNotNull($comment->getModeratedAt());
    }
}
