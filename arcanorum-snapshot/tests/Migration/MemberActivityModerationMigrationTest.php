<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\AdminUser;
use App\Entity\MemberAccount;
use App\Entity\MemberActivityModeration;
use App\Entity\MemberActivityModerationStatus;
use App\Entity\MemberActivityType;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubmission;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030203000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MemberActivityModerationMigrationTest extends KernelTestCase
{
    public function testDatabaseRejectsRowsWithoutAnActivitySource(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->assertDatabaseRejectsInvalidSource($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testSourceDeletionCascadesAndDowngradePreservesModerationState(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        $suffix = bin2hex(random_bytes(6));
        $moderator = new AdminUser('moderation-migration-'.$suffix.'@example.test');
        $moderator->setPassword(password_hash('migration-fixture-'.$suffix, PASSWORD_DEFAULT));
        $member = new MemberAccount('activity-migration-'.$suffix.'@example.test', 'amigration'.$suffix, 'Migration activity member');
        $video = new Video(
            'Moderation migration '.$suffix,
            'Migration test video.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/migration-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $submission = new VideoSubmission($video, $member);
        $moderation = new MemberActivityModeration(
            MemberActivityType::VideoSubmission,
            MemberActivityModerationStatus::Hidden,
            $moderator,
            $submission,
        );

        try {
            foreach ([$moderator, $member, $video, $submission, $moderation] as $entity) {
                $entityManager->persist($entity);
            }
            $entityManager->flush();

            try {
                new Version20261030203000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve member activity moderation decisions.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Aktivitätsmoderationen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.',
                    $exception->getMessage(),
                );
            }

            $connection->executeStatement('DELETE FROM video_submissions WHERE id = :id', ['id' => $submission->getId()]);
            $remainingModerations = $connection->fetchOne(
                'SELECT COUNT(*) FROM member_activity_moderations WHERE id = :id',
                ['id' => $moderation->getId()],
            );
            self::assertTrue(0 === $remainingModerations || '0' === $remainingModerations);
            new Version20261030203000($connection, new NullLogger())->down(new Schema());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    private function assertDatabaseRejectsInvalidSource(Connection $connection): void
    {
        try {
            $connection->executeStatement(
                "INSERT INTO member_activity_moderations (id, activity_type, status, created_at, updated_at) VALUES (:id, 'video_submission', 'hidden', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                ['id' => bin2hex(random_bytes(16))],
            );
            self::fail('A moderation row must reference exactly one source event.');
        } catch (DriverException $exception) {
            self::assertSame('23514', $exception->getSQLState());
        }
    }
}
