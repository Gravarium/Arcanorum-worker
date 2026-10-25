<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\MemberAccount;
use App\Entity\MemberExternalIdentity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261022210500;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MemberExternalIdentityMigrationTest extends KernelTestCase
{
    public function testDowngradeRefusesToDiscardLinkedIdentitiesOrAnEnabledProvider(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $suffix = bin2hex(random_bytes(6));
        $member = new MemberAccount('oauth-migration-'.$suffix.'@example.test', 'oauth-migration-'.$suffix, 'OAuth migration');
        $identity = new MemberExternalIdentity($member, 'google', 'migration-subject-'.$suffix);
        $connection->beginTransaction();

        try {
            $entityManager->persist($member);
            $entityManager->persist($identity);
            $entityManager->flush();

            try {
                new Version20261022210500($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve linked external identities.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Verknüpfte Mitgliedsidentitäten dürfen durch den Migrationsrückbau nicht verloren gehen.',
                    $exception->getMessage(),
                );
            }

            $connection->executeStatement('DELETE FROM member_external_identities WHERE member_id = :id', ['id' => $member->getId()]);
            $connection->executeStatement('UPDATE site_settings SET social_google_enabled = TRUE WHERE id = 1');

            try {
                new Version20261022210500($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve an enabled social sign-in provider.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Eine aktivierte Google-Anmeldung darf durch den Migrationsrückbau nicht verloren gehen.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseEnforcesUniqueProviderSubjectsAndOneProviderPerMember(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $suffix = bin2hex(random_bytes(6));
        $firstMember = new MemberAccount('oauth-first-'.$suffix.'@example.test', 'oauth-first-'.$suffix, 'OAuth first');
        $secondMember = new MemberAccount('oauth-second-'.$suffix.'@example.test', 'oauth-second-'.$suffix, 'OAuth second');
        $subject = 'unique-oauth-subject-'.$suffix;
        $identity = new MemberExternalIdentity($firstMember, 'google', $subject);
        $connection->beginTransaction();

        try {
            $entityManager->persist($firstMember);
            $entityManager->persist($secondMember);
            $entityManager->persist($identity);
            $entityManager->flush();

            $this->assertUniqueInsertRejected($connection, [
                'id' => bin2hex(random_bytes(16)),
                'member_id' => $firstMember->getId(),
                'provider' => 'google',
                'subject' => 'second-subject-'.$suffix,
                'created_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ], 'uniq_member_external_member_provider');

            $this->assertUniqueInsertRejected($connection, [
                'id' => bin2hex(random_bytes(16)),
                'member_id' => $secondMember->getId(),
                'provider' => 'google',
                'subject' => $subject,
                'created_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ], 'uniq_member_external_provider_subject');

            $connection->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $firstMember->getId()]);
            $identityCount = $connection->fetchOne('SELECT COUNT(*) FROM member_external_identities WHERE member_id = :id', ['id' => $firstMember->getId()]);
            self::assertTrue(in_array($identityCount, [0, '0'], true));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    /** @param array{id: string, member_id: string, provider: string, subject: string, created_at: string} $identity */
    private function assertUniqueInsertRejected(Connection $connection, array $identity, string $constraintName): void
    {
        $savepoint = 'oauth_identity_constraint_'.bin2hex(random_bytes(8));
        $connection->createSavepoint($savepoint);

        try {
            $connection->insert('member_external_identities', $identity);
            self::fail('The database must enforce the external identity uniqueness rule.');
        } catch (UniqueConstraintViolationException $exception) {
            self::assertStringContainsString($constraintName, $exception->getMessage());
        } finally {
            $connection->rollbackSavepoint($savepoint);
        }
    }
}
