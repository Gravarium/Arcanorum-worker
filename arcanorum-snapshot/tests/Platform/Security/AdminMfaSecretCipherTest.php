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

    public function testCryptoOperationsFailClosedForMissingOrMalformedEncryptionKeys(): void
    {
        foreach (['', 'not-base64!', base64_encode(str_repeat('K', 31))] as $key) {
            $cipher = new AdminMfaSecretCipher($key);

            self::assertFalse($cipher->isConfigured());

            try {
                $cipher->encrypt(random_bytes(20));
                self::fail('Encryption must remain disabled without a valid key.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'ADMIN_MFA_ENCRYPTION_KEY muss ein Base64-kodierter 32-Byte-Schlüssel sein.',
                    $exception->getMessage(),
                );
            }

            try {
                $cipher->hashRecoveryCode($cipher->generateRecoveryCode());
                self::fail('Recovery-code verification must remain disabled without a valid key.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'ADMIN_MFA_ENCRYPTION_KEY muss ein Base64-kodierter 32-Byte-Schlüssel sein.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testValidEncryptionKeyIsReportedAsConfigured(): void
    {
        self::assertTrue((new AdminMfaSecretCipher(base64_encode(str_repeat('K', 32))))->isConfigured());
    }

    public function testDecryptionFailsClosedWithoutAnEncryptionKey(): void
    {
        $cipher = new AdminMfaSecretCipher('');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ADMIN_MFA_ENCRYPTION_KEY muss ein Base64-kodierter 32-Byte-Schlüssel sein.');
        $cipher->decrypt('v1.invalid');
    }

    public function testRecoveryCodesAreKeyedAndFormattingIndependent(): void
    {
        $cipher = new AdminMfaSecretCipher(base64_encode(str_repeat('K', 32)));
        $code = $cipher->generateRecoveryCode();

        self::assertSame($cipher->hashRecoveryCode($code), $cipher->hashRecoveryCode(str_replace('-', ' ', $code)));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $cipher->hashRecoveryCode($code));
    }
}
