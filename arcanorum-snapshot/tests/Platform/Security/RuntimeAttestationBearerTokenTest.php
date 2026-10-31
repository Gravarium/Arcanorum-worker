<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\RuntimeAttestationBearerToken;
use PHPUnit\Framework\TestCase;

final class RuntimeAttestationBearerTokenTest extends TestCase
{
    public function testAcceptsTokenAtTotalLengthLimitIncludingPadding(): void
    {
        $token = str_repeat('A', RuntimeAttestationBearerToken::MAX_TOTAL_LENGTH - 2).'==';

        self::assertSame(RuntimeAttestationBearerToken::MAX_TOTAL_LENGTH, strlen($token));
        self::assertTrue(RuntimeAttestationBearerToken::isValid($token));
    }

    public function testRejectsTokenOverTotalLengthLimitIncludingPadding(): void
    {
        $token = str_repeat('A', RuntimeAttestationBearerToken::MAX_TOTAL_LENGTH).str_repeat('=', 17);

        self::assertSame(8209, strlen($token));
        self::assertFalse(RuntimeAttestationBearerToken::isValid($token));
    }
}
