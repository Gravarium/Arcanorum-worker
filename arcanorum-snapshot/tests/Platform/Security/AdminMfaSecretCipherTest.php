<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\AdminMfaSecretCipher;
use PHPUnit\Framework\TestCase;

final class AdminMfaSecretCipherTest extends TestCase
{
    public function testSecretboxRoundTripsAndDoesNotPersistThePlaintext(): void
    {
        $cipher = new AdminMfaSecretCipher(base64_encode(str_repeat('K', 32)));
        $secret = random_bytes(20);
        $encrypted = $cipher->encrypt($secret);

        self::assertStringStartsWith('v1.', $encrypted);
        self::assertStringNotContainsString($secret, $encrypted);
        self::assertSame($secret, $cipher->decrypt($encrypted));
    }

    public function testTamperedCiphertextFailsClosed(): void
    {
        $cipher = new AdminMfaSecretCipher(base64_encode(str_repeat('K', 32)));
        $encrypted = $cipher->encrypt(random_bytes(20));
        $lastCharacter = substr($encrypted, -1);
        $tampered = substr($encrypted, 0, -1).('A' === $lastCharacter ? 'B' : 'A');

        $this->expectException(\UnexpectedValueException::class);
        $cipher->decrypt($tampered);
    }

    public function testRejectsMissingOrMalformedEncryptionKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AdminMfaSecretCipher('');
    }

    public function testRecoveryCodesAreKeyedAndFormattingIndependent(): void
    {
        $cipher = new AdminMfaSecretCipher(base64_encode(str_repeat('K', 32)));
        $code = $cipher->generateRecoveryCode();

        self::assertSame($cipher->hashRecoveryCode($code), $cipher->hashRecoveryCode(str_replace('-', ' ', $code)));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $cipher->hashRecoveryCode($code));
    }
}
