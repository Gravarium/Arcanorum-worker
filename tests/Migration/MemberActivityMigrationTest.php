<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\CommentContentType;
use App\Entity\ContentComment;
use App\Entity\MemberAccount;
use App\Service\MemberCommentMigrationGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MemberActivityMigrationTest extends KernelTestCase
{
    public function testDowngradeRefusesToRemoveMemberAttribution(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        $suffix = bin2hex(random_bytes(6));
        $member = new MemberAccount(
            'migration-'.$suffix.'@example.test',
            'migration-'.$suffix,
            'Migration member',
        );
        $comment = new ContentComment(
            CommentContentType::Video,
            bin2hex(random_bytes(16)),
            'Migration fixture',
            'Migration member',
            'Comment attribution must survive a blocked downgrade.',
        );
        $comment->attributeToMember($member);
        $connection->beginTransaction();

        try {
            $entityManager->persist($member);
            $entityManager->persist($comment);
            $entityManager->flush();

            $guard = new MemberCommentMigrationGuard($connection);
            try {
                $guard->assertNoAttributedComments();
                self::fail('A downgrade with member-attributed comments must be blocked.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Der Rückbau würde die Konto-Zuordnung bestehender Kommentare verlieren.',
                    $exception->getMessage(),
                );
            }

            self::assertSame($member->getId(), $connection->fetchOne(
                'SELECT member_id FROM content_comments WHERE id = :id',
                ['id' => $comment->getId()],
            ));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
