<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\MemberAccount;
use App\Entity\PrivateObjectDeletionJob;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261020090000;
use DoctrineMigrations\Version20261020100000;
use DoctrineMigrations\Version20261020110000;
use DoctrineMigrations\Version20261025140000;
use DoctrineMigrations\Version20261026100000;
use DoctrineMigrations\Version20261026110000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RetainedMemberDataMigrationRollbackTest extends KernelTestCase
{
    public function testVideoSubmissionMigrationRefusesToDiscardMemberAttribution(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $video = $this->createVideo($entityManager);
            $member = $this->createMember();
            $entityManager->persist($member);
            $entityManager->flush();
            $connection->insert('video_submissions', [
                'id' => bin2hex(random_bytes(16)),
                'video_id' => $video->getId(),
                'submitter_id' => $member->getId(),
                'status' => 'pending',
                'created_at' => '2026-10-31 09:00:00',
                'reviewed_at' => null,
            ]);

            $this->assertRollbackBlocked(
                new Version20261020090000($connection, new NullLogger()),
                'Die Mitgliedszuordnung von Videoeinreichungen muss vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testMemberAccountMigrationRefusesToReactivateInactiveAccounts(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $member = $this->createMember();
            $entityManager->persist($member);
            $entityManager->flush();
            $connection->executeStatement('UPDATE member_accounts SET is_active = FALSE WHERE id = :id', ['id' => $member->getId()]);

            $this->assertRollbackBlocked(
                new Version20261020100000($connection, new NullLogger()),
                'Deaktivierte Mitgliedskonten dürfen durch den Migrationsrückbau nicht versehentlich reaktiviert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testInvitationMigrationRefusesToDiscardIssuedInvitationTokens(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $member = $this->createMember();
            $entityManager->persist($member);
            $entityManager->flush();
            $connection->update('member_accounts', [
                'invitation_token_hash' => hash('sha256', 'invitation-token'),
                'invitation_expires_at' => '2026-10-31 10:00:00',
            ], ['id' => $member->getId()]);

            $this->assertRollbackBlocked(
                new Version20261020110000($connection, new NullLogger()),
                'Mitgliedereinladungen müssen vor dem Migrationsrückbau erhalten oder neu ausgestellt werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testRatingMigrationRefusesToDiscardMemberRatings(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $video = $this->createVideo($entityManager);
            $member = $this->createMember();
            $entityManager->persist($member);
            $entityManager->flush();
            $now = '2026-10-31 09:00:00';
            $connection->insert('content_ratings', [
                'id' => bin2hex(random_bytes(16)),
                'member_id' => $member->getId(),
                'video_id' => $video->getId(),
                'comment_id' => null,
                'value' => 'like',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->assertRollbackBlocked(
                new Version20261025140000($connection, new NullLogger()),
                'Mitgliedsbewertungen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testMemberBanMigrationRefusesToDiscardBanHistory(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $member = $this->createMember();
            $entityManager->persist($member);
            $entityManager->flush();
            $connection->executeStatement(
                'INSERT INTO member_account_bans (id, member_id, banned_by_admin_id, revoked_by_admin_id, reason, was_active, created_at, revoked_at)
                 VALUES (:id, :member_id, NULL, NULL, :reason, TRUE, :created_at, NULL)',
                [
                    'id' => bin2hex(random_bytes(16)),
                    'member_id' => $member->getId(),
                    'reason' => 'Documented policy violation',
                    'created_at' => '2026-10-31 09:00:00',
                ],
            );

            $this->assertRollbackBlocked(
                new Version20261026100000($connection, new NullLogger()),
                'Mitgliedersperren und Authentifizierungswiderrufe müssen vor dem Migrationsrückbau erhalten bleiben.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testMemberBanMigrationRefusesToDiscardAuthenticationRevocations(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $member = $this->createMember();
            $entityManager->persist($member);
            $entityManager->flush();
            $connection->update('member_accounts', ['auth_version' => 4], ['id' => $member->getId()]);

            $this->assertRollbackBlocked(
                new Version20261026100000($connection, new NullLogger()),
                'Mitgliedersperren und Authentifizierungswiderrufe müssen vor dem Migrationsrückbau erhalten bleiben.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    public function testPrivateObjectDeletionMigrationRefusesToDiscardPendingJobs(): void
    {
        [$entityManager, $connection] = $this->beginTransaction();

        try {
            $entityManager->persist(new PrivateObjectDeletionJob(str_repeat('f', 64)));
            $entityManager->flush();

            $this->assertRollbackBlocked(
                new Version20261026110000($connection, new NullLogger()),
                'Aufträge zur sicheren Löschung privater Speicherobjekte müssen vor dem Migrationsrückbau erhalten bleiben.',
            );
        } finally {
            $this->rollbackTransaction($entityManager, $connection);
        }
    }

    /** @return array{EntityManagerInterface, Connection} */
    private function beginTransaction(): array
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        return [$entityManager, $connection];
    }

    private function rollbackTransaction(EntityManagerInterface $entityManager, Connection $connection): void
    {
        $entityManager->clear();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }

    private function assertRollbackBlocked(AbstractMigration $migration, string $message): void
    {
        try {
            $migration->down(new Schema());
            self::fail('The downgrade must not reactivate accounts or discard member data and pending private-data work.');
        } catch (\RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function createVideo(EntityManagerInterface $entityManager): Video
    {
        $suffix = bin2hex(random_bytes(8));
        $video = new Video(
            'Member data rollback video '.$suffix,
            'Description',
            null,
            VideoSourceType::Url,
            'https://media.example.test/'.$suffix,
            PublishingStatus::Draft,
            false,
            false,
        );
        $entityManager->persist($video);
        $entityManager->flush();

        return $video;
    }

    private function createMember(): MemberAccount
    {
        $suffix = bin2hex(random_bytes(8));

        return new MemberAccount('member-'.$suffix.'@example.test', 'user-'.$suffix, 'Member '.$suffix);
    }
}
