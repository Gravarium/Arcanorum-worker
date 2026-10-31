<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final readonly class MemberCommentMigrationGuard
{
    public function __construct(private Connection $connection)
    {
    }

    public function assertNoAttributedComments(): void
    {
        $this->connection->executeStatement('LOCK TABLE content_comments IN SHARE ROW EXCLUSIVE MODE');
        $attributedComment = $this->connection->fetchOne(
            'SELECT id FROM content_comments WHERE member_id IS NOT NULL LIMIT 1',
        );

        if (false !== $attributedComment) {
            throw new \RuntimeException('Der Rückbau würde die Konto-Zuordnung bestehender Kommentare verlieren.');
        }
    }
}
