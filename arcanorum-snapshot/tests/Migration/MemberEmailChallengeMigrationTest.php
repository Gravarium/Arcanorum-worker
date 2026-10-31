<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\MemberAccount;
use App\Module\Member\Security\MemberEmailChallengeManager;
use App\Module\Member\Security\MemberEmailChallengePurpose;
use App\Service\MemberEmailChallengeMigrationGuard;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MemberEmailChallengeMigrationTest extends KernelTestCase
{
    public function testDowngradeRefusesToRemoveUnverifiedAccountAndItsChallenge(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $challengeManager = static::getContainer()->get(MemberEmailChallengeManager::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(MemberEmailChallengeManager::class, $challengeManager);
        $connection = $entityManager->getConnection();
        $suffix = bin2hex(random_bytes(6));
        $member = new MemberAccount('challenge-'.$suffix.'@example.test', 'challenge-'.$suffix, 'Challenge member');
        $member->requireEmailVerification();
        $memberId = $member->getId();
        $connection->beginTransaction();

        try {
            $entityManager->persist($member);
            $entityManager->flush();
            $token = $challengeManager->issue(
                $member,
                MemberEmailChallengePurpose::EmailVerification,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                3600,
            );

            try {
                new MemberEmailChallengeMigrationGuard($connection)->assertSafeToRemove();
                self::fail('The migration downgrade must preserve unverified accounts and live security links.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Der Rückbau würde unbestätigte Mitgliedskonten, gültige Sicherheitslinks oder aktive Drosselungsdaten verlieren.', $exception->getMessage());
            }

            self::assertSame(hash('sha256', $token), $connection->fetchOne(
                'SELECT token_hash FROM member_email_challenges WHERE member_id = :member',
                ['member' => $memberId],
            ));
            self::assertSame($memberId, $connection->fetchOne('SELECT id FROM member_accounts WHERE id = :id', ['id' => $memberId]));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testEmailVersionCannotBeNegative(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $member = new MemberAccount('email-version-'.bin2hex(random_bytes(6)).'@example.test', 'version-'.bin2hex(random_bytes(4)), 'Version member');
        $memberId = $member->getId();
        $entityManager->persist($member);
        $entityManager->flush();

        try {
            try {
                $entityManager->getConnection()->executeStatement(
                    'UPDATE member_accounts SET email_version = -1 WHERE id = :id',
                    ['id' => $memberId],
                );
                self::fail('Negative email versions must be rejected by the database.');
            } catch (Exception $exception) {
                self::assertStringContainsString('chk_member_accounts_email_version', $exception->getMessage());
            }
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $memberId]);
        }
    }

    public function testDowngradeRefusesToRemoveActiveRateLimitState(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $keyHash = hash('sha256', random_bytes(32));
        $connection->beginTransaction();

        try {
            $connection->insert('member_security_rate_limits', [
                'key_hash' => $keyHash,
                'window_started_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'request_count' => 1,
            ]);

            try {
                new MemberEmailChallengeMigrationGuard($connection)->assertSafeToRemove();
                self::fail('The migration downgrade must preserve active abuse-limit state.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Der Rückbau würde unbestätigte Mitgliedskonten, gültige Sicherheitslinks oder aktive Drosselungsdaten verlieren.', $exception->getMessage());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
