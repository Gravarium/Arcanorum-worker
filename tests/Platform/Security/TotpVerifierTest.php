<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\Base32Encoder;
use App\Platform\Security\TotpVerifier;
use PHPUnit\Framework\TestCase;

final class TotpVerifierTest extends TestCase
{
    public function testBase32EncodingForAuthenticatorSecrets(): void
    {
        self::assertSame('MZXW6YTBOI', Base32Encoder::encode('foobar'));
    }

    public function testRfc6238Sha1SixDigitVectors(): void
    {
        $verifier = new TotpVerifier();
        $secret = '12345678901234567890';

        self::assertSame('287082', $verifier->codeAtTime($secret, 59));
        self::assertSame('081804', $verifier->codeAtTime($secret, 1_111_111_109));
        self::assertSame('050471', $verifier->codeAtTime($secret, 1_111_111_111));
        self::assertSame('005924', $verifier->codeAtTime($secret, 1_234_567_890));
        self::assertSame('279037', $verifier->codeAtTime($secret, 2_000_000_000));
    }

    public function testAcceptsOnlyTheAdjacentThirtySecondSteps(): void
    {
        $verifier = new TotpVerifier();
        $secret = random_bytes(20);
        $code = $verifier->codeAtTime($secret, 60);

        self::assertSame(2, $verifier->matchingCounter($secret, $code, 30));
        self::assertSame(2, $verifier->matchingCounter($secret, $code, 90));
        self::assertNull($verifier->matchingCounter($secret, $code, 120));
        self::assertNull($verifier->matchingCounter($secret, 'not-a-code', 60));
    }

    public function testRejectsNegativeTimeAndEmptySecret(): void
    {
        $verifier = new TotpVerifier();

        $this->expectException(\InvalidArgumentException::class);
        $verifier->codeAtTime('', 0);
    }
}
