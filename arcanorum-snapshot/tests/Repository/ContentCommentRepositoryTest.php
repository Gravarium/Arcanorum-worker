<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CommentContentType;
use App\Entity\ContentComment;
use App\Repository\ContentCommentRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContentCommentRepositoryTest extends KernelTestCase
{
    public function testBatchCountOnlyIncludesApprovedCommentsOfTheRequestedType(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $comments = static::getContainer()->get(ContentCommentRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(ContentCommentRepository::class, $comments);

        $contentId = bin2hex(random_bytes(16));
        $otherContentId = bin2hex(random_bytes(16));
        $approvedOne = $this->comment(CommentContentType::News, $contentId, 'Approved one.');
        $approvedOne->approve();
        $approvedTwo = $this->comment(CommentContentType::News, $contentId, 'Approved two.');
        $approvedTwo->approve();
        $pending = $this->comment(CommentContentType::News, $contentId, 'Pending.');
        $reported = $this->comment(CommentContentType::News, $contentId, 'Reported.');
        $reported->approve();
        $reported->report();
        $reported->report();
        $reported->report();
        $rejected = $this->comment(CommentContentType::News, $contentId, 'Rejected.');
        $rejected->reject();
        $otherNews = $this->comment(CommentContentType::News, $otherContentId, 'Other news.');
        $otherNews->approve();
        $sameIdVideo = $this->comment(CommentContentType::Video, $contentId, 'A video comment.');
        $sameIdVideo->approve();
        $entities = [$approvedOne, $approvedTwo, $pending, $reported, $rejected, $otherNews, $sameIdVideo];
        $connection = $entityManager->getConnection();

        try {
            foreach ($entities as $comment) {
                $entityManager->persist($comment);
            }
            $entityManager->flush();

            $expectedCounts = [$contentId => 2, $otherContentId => 1];
            $actualCounts = $comments->countApprovedForContents(CommentContentType::News, [$contentId, $otherContentId, $contentId]);
            ksort($expectedCounts);
            ksort($actualCounts);
            self::assertSame(
                $expectedCounts,
                $actualCounts,
            );
            self::assertSame([], $comments->countApprovedForContents(CommentContentType::News, []));
        } finally {
            $connection->executeStatement(
                'DELETE FROM content_comments WHERE content_id IN (:ids)',
                ['ids' => [$contentId, $otherContentId]],
                ['ids' => ArrayParameterType::STRING],
            );
        }
    }

    public function testBatchCountRejectsMoreThanOneHundredContentIds(): void
    {
        self::bootKernel();
        $comments = static::getContainer()->get(ContentCommentRepository::class);
        self::assertInstanceOf(ContentCommentRepository::class, $comments);
        $contentIds = array_map(
            static fn (int $value): string => str_pad(dechex($value), 32, '0', STR_PAD_LEFT),
            range(1, 101),
        );

        $this->expectException(\InvalidArgumentException::class);
        $comments->countApprovedForContents(CommentContentType::News, $contentIds);
    }

    private function comment(CommentContentType $type, string $contentId, string $body): ContentComment
    {
        return new ContentComment($type, $contentId, 'Comment-count test', 'Reviewer', $body);
    }
}
