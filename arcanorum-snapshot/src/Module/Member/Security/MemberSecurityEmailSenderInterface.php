<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

use App\Entity\MemberAccount;

interface MemberSecurityEmailSenderInterface
{
    public function send(MemberAccount $member, MemberEmailChallengePurpose $purpose, string $token): void;
}
