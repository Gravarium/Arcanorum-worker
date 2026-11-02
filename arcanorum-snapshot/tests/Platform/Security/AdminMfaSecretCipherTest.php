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
                    'Die Admin-MFA-Verschlüsselung ist nicht sicher konfiguriert.',
                    $exception->getMessage(),
                );
            }

            try {
                $cipher->hashRecoveryCode($cipher->generateRecoveryCode());
                self::fail('Recovery-code verification must remain disabled without a valid key.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'Die Admin-MFA-Verschlüsselung ist nicht sicher konfiguriert.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testApplicationSecretProvidesDefaultKeysWhenDedicatedKeyIsOmitted(): void
    {
        $applicationSecret = base64_encode(random_bytes(32));
        $cipher = new AdminMfaSecretCipher('', $applicationSecret);
        $secret = random_bytes(20);
        $recoveryCode = $cipher->generateRecoveryCode();

        self::assertTrue($cipher->isConfigured());
        $encrypted = $cipher->encrypt($secret);
        $secondCipher = new AdminMfaSecretCipher('', $applicationSecret);

        self::assertSame($secret, $cipher->decrypt($encrypted));
        self::assertSame($secret, $secondCipher->decrypt($encrypted));
        self::assertSame($cipher->hashRecoveryCode($recoveryCode), $secondCipher->hashRecoveryCode($recoveryCode));
        self::assertMatchesRegularExpression('/\\A[a-f0-9]{64}\\z/', $cipher->hashRecoveryCode($recoveryCode));
    }

    public function testDedicatedInvalidKeyDoesNotFallBackToApplicationSecret(): void
    {
        $cipher = new AdminMfaSecretCipher('not-base64!', base64_encode(random_bytes(32)));

        self::assertFalse($cipher->isConfigured());
    }

    public function testKnownApplicationSecretPlaceholdersRemainUnavailable(): void
    {
        $cipher = new AdminMfaSecretCipher('', 'development-placeholder-not-a-secret-change-before-deployment');

        self::assertFalse($cipher->isConfigured());
    }

    public function testValidEncryptionKeyIsReportedAsConfigured(): void
    {
        self::assertTrue(new AdminMfaSecretCipher(base64_encode(str_repeat('K', 32)))->isConfigured());
    }

    public function testDecryptionFailsClosedWithoutAnEncryptionKey(): void
    {
        $cipher = new AdminMfaSecretCipher('');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Die Admin-MFA-Verschlüsselung ist nicht sicher konfiguriert.');
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
