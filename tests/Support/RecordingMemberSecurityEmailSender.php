<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\MemberAccount;
use App\Module\Member\Security\MemberEmailChallengePurpose;
use App\Module\Member\Security\MemberSecurityEmailSenderInterface;

final class RecordingMemberSecurityEmailSender implements MemberSecurityEmailSenderInterface
{
    /** @var list<array{email: string, purpose: MemberEmailChallengePurpose, token: string}> */
    private array $messages = [];

    private bool $failDelivery = false;

    public function send(MemberAccount $member, MemberEmailChallengePurpose $purpose, string $token): void
    {
        if ($this->failDelivery) {
            throw new \RuntimeException('Test mail delivery failure.');
        }

        $this->messages[] = ['email' => $member->getEmail(), 'purpose' => $purpose, 'token' => $token];
    }

    /** @return list<array{email: string, purpose: MemberEmailChallengePurpose, token: string}> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function failDelivery(): void
    {
        $this->failDelivery = true;
    }
}
