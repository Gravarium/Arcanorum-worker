<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

enum MemberEmailChallengePurpose: string
{
    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';
}
