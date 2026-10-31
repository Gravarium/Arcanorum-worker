<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

use App\Entity\MemberAccount;
use Doctrine\DBAL\Connection;

final readonly class MemberEmailChallengeManager
{
    public function __construct(private Connection $connection)
    {
    }

    public function issue(
        MemberAccount $member,
        MemberEmailChallengePurpose $purpose,
        \DateTimeImmutable $now,
        int $timeToLiveSeconds,
    ): string {
        if ($timeToLiveSeconds < 60 || $timeToLiveSeconds > 86400) {
            throw new \InvalidArgumentException('Die Ablaufzeit des Sicherheitslinks liegt außerhalb des erlaubten Bereichs.');
        }

        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $expiresAt = $now->modify('+'.$timeToLiveSeconds.' seconds');
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $createdAtValue = $now->format('Y-m-d H:i:s');
        $expiresAtValue = $expiresAt->format('Y-m-d H:i:s');

        $this->connection->transactional(function (Connection $connection) use (
            $member,
            $purpose,
            $token,
            $now,
            $createdAtValue,
            $expiresAtValue,
        ): void {
            $eligibility = MemberEmailChallengePurpose::EmailVerification === $purpose
                ? 'is_active = TRUE AND email_verified = FALSE'
                : 'is_active = TRUE AND email_verified = TRUE';
            $lockedMember = $connection->fetchAssociative(
                'SELECT id, email_version FROM member_accounts WHERE id = :id AND email = :email AND '.$eligibility.' FOR UPDATE',
                ['id' => $member->getId(), 'email' => $member->getEmail()],
            );
            if (!is_array($lockedMember)
                || ($lockedMember['id'] ?? null) !== $member->getId()
                || !is_numeric($lockedMember['email_version'] ?? null)
                || (int) $lockedMember['email_version'] !== $member->getEmailVersion()
            ) {
                throw new \DomainException('Das Mitgliedskonto ist für diesen Sicherheitslink nicht berechtigt.');
            }

            $nowValue = $now->format('Y-m-d H:i:s');
            $retentionCutoff = $now->modify('-7 days')->format('Y-m-d H:i:s');
            $connection->executeStatement(
                'UPDATE member_email_challenges SET consumed_at = :now WHERE member_id = :member AND purpose = :purpose AND consumed_at IS NULL',
                ['now' => $nowValue, 'member' => $member->getId(), 'purpose' => $purpose->value],
            );
            $connection->executeStatement(
                'DELETE FROM member_email_challenges WHERE member_id = :member AND (expires_at < :cutoff OR (consumed_at IS NOT NULL AND consumed_at < :cutoff))',
                ['member' => $member->getId(), 'cutoff' => $retentionCutoff],
            );
            $connection->insert('member_email_challenges', [
                'id' => bin2hex(random_bytes(16)),
                'member_id' => $member->getId(),
                'email_version' => (int) $lockedMember['email_version'],
                'purpose' => $purpose->value,
                'token_hash' => hash('sha256', $token),
                'created_at' => $createdAtValue,
                'expires_at' => $expiresAtValue,
            ]);
        });

        return $token;
    }

    public function findEligibleMemberId(
        string $token,
        MemberEmailChallengePurpose $purpose,
        \DateTimeImmutable $now,
    ): ?string {
        if (!$this->validToken($token)) {
            return null;
        }

        $verificationCondition = MemberEmailChallengePurpose::EmailVerification === $purpose
            ? 'member.email_verified = FALSE'
            : 'member.email_verified = TRUE';
        $memberId = $this->connection->fetchOne(
            'SELECT challenge.member_id FROM member_email_challenges challenge JOIN member_accounts member ON member.id = challenge.member_id '
                .'WHERE challenge.token_hash = :hash AND challenge.purpose = :purpose AND challenge.consumed_at IS NULL '
                .'AND challenge.expires_at > :now AND challenge.email_version = member.email_version AND member.is_active = TRUE AND '.$verificationCondition,
            [
                'hash' => hash('sha256', $token),
                'purpose' => $purpose->value,
                'now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ],
        );

        return is_string($memberId) && 1 === preg_match('/\A[a-f0-9]{32}\z/D', $memberId) ? $memberId : null;
    }

    public function consumeEmailVerification(string $token, \DateTimeImmutable $now): bool
    {
        if (!$this->validToken($token)) {
            return false;
        }

        return $this->connection->transactional(function (Connection $connection) use ($token, $now): bool {
            $nowValue = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $memberId = $connection->fetchOne(
                'SELECT member.id FROM member_email_challenges challenge JOIN member_accounts member ON member.id = challenge.member_id '
                    .'WHERE challenge.token_hash = :hash AND challenge.purpose = :purpose AND challenge.consumed_at IS NULL '
                    .'AND challenge.expires_at > :now AND challenge.email_version = member.email_version AND member.is_active = TRUE AND member.email_verified = FALSE FOR UPDATE OF member',
                ['hash' => hash('sha256', $token), 'purpose' => MemberEmailChallengePurpose::EmailVerification->value, 'now' => $nowValue],
            );
            if (!is_string($memberId)) {
                return false;
            }

            $memberId = $connection->fetchOne(
                'UPDATE member_email_challenges SET consumed_at = :now WHERE token_hash = :hash AND purpose = :purpose '
                    .'AND member_id = :member AND consumed_at IS NULL AND expires_at > :now '
                    .'RETURNING member_id',
                ['now' => $nowValue, 'hash' => hash('sha256', $token), 'purpose' => MemberEmailChallengePurpose::EmailVerification->value, 'member' => $memberId],
            );
            if (!is_string($memberId)) {
                return false;
            }

            $updated = $connection->executeStatement(
                'UPDATE member_accounts SET email_verified = TRUE WHERE id = :id AND is_active = TRUE AND email_verified = FALSE',
                ['id' => $memberId],
            );
            if (1 !== $updated) {
                throw new \RuntimeException('Die E-Mail-Verifizierung konnte nicht atomar abgeschlossen werden.');
            }
            $connection->executeStatement(
                'UPDATE member_email_challenges SET consumed_at = :now WHERE member_id = :member AND purpose = :purpose AND consumed_at IS NULL',
                ['now' => $nowValue, 'member' => $memberId, 'purpose' => MemberEmailChallengePurpose::EmailVerification->value],
            );

            return true;
        });
    }

    public function consumePasswordReset(string $token, string $passwordHash, \DateTimeImmutable $now): bool
    {
        if (!$this->validToken($token) || '' === $passwordHash || strlen($passwordHash) > 255) {
            return false;
        }

        return $this->connection->transactional(function (Connection $connection) use ($token, $passwordHash, $now): bool {
            $nowValue = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $memberId = $connection->fetchOne(
                'SELECT member.id FROM member_email_challenges challenge JOIN member_accounts member ON member.id = challenge.member_id '
                    .'WHERE challenge.token_hash = :hash AND challenge.purpose = :purpose AND challenge.consumed_at IS NULL '
                    .'AND challenge.expires_at > :now AND challenge.email_version = member.email_version AND member.is_active = TRUE AND member.email_verified = TRUE FOR UPDATE OF member',
                ['hash' => hash('sha256', $token), 'purpose' => MemberEmailChallengePurpose::PasswordReset->value, 'now' => $nowValue],
            );
            if (!is_string($memberId)) {
                return false;
            }

            $memberId = $connection->fetchOne(
                'UPDATE member_email_challenges SET consumed_at = :now WHERE token_hash = :hash AND purpose = :purpose '
                    .'AND member_id = :member AND consumed_at IS NULL AND expires_at > :now '
                    .'RETURNING member_id',
                ['now' => $nowValue, 'hash' => hash('sha256', $token), 'purpose' => MemberEmailChallengePurpose::PasswordReset->value, 'member' => $memberId],
            );
            if (!is_string($memberId)) {
                return false;
            }

            $updated = $connection->executeStatement(
                'UPDATE member_accounts SET password = :password WHERE id = :id AND is_active = TRUE AND email_verified = TRUE',
                ['password' => $passwordHash, 'id' => $memberId],
            );
            if (1 !== $updated) {
                throw new \RuntimeException('Das Passwort konnte nicht atomar zurückgesetzt werden.');
            }
            $connection->executeStatement(
                'UPDATE member_email_challenges SET consumed_at = :now WHERE member_id = :member AND purpose = :purpose AND consumed_at IS NULL',
                ['now' => $nowValue, 'member' => $memberId, 'purpose' => MemberEmailChallengePurpose::PasswordReset->value],
            );

            return true;
        });
    }

    public function revoke(string $token, MemberEmailChallengePurpose $purpose, \DateTimeImmutable $now): void
    {
        if (!$this->validToken($token)) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE member_email_challenges SET consumed_at = :now WHERE token_hash = :hash AND purpose = :purpose AND consumed_at IS NULL',
            [
                'now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'hash' => hash('sha256', $token),
                'purpose' => $purpose->value,
            ],
        );
    }

    private function validToken(string $token): bool
    {
        return 1 === preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token);
    }
}
