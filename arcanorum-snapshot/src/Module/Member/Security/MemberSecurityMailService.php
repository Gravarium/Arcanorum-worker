<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

use App\Entity\MemberAccount;
use Psr\Log\LoggerInterface;

final readonly class MemberSecurityMailService
{
    public function __construct(
        private MemberEmailChallengeManager $challenges,
        private MemberSecurityEmailSenderInterface $sender,
        private LoggerInterface $logger,
    ) {
    }

    public function send(MemberAccount $member, MemberEmailChallengePurpose $purpose): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $timeToLiveSeconds = match ($purpose) {
            MemberEmailChallengePurpose::EmailVerification => 3600,
            MemberEmailChallengePurpose::PasswordReset => 1800,
        };
        $token = null;
        try {
            $token = $this->challenges->issue($member, $purpose, $now, $timeToLiveSeconds);
            $this->sender->send($member, $purpose, $token);

            return true;
        } catch (\Throwable) {
            if (null !== $token) {
                $this->challenges->revoke($token, $purpose, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            }
            $this->logger->warning('Eine Mitglieds-Sicherheitsmail konnte nicht zugestellt werden.');

            return false;
        }
    }
}
