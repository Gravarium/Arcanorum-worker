<?php

declare(strict_types=1);

namespace App\Platform\Security;

final readonly class AdminMfaSecretCipher
{
    private const string CIPHER_PREFIX = 'v1.';
    private const int MASTER_KEY_BYTES = 32;
    private const string INVALID_KEY_MESSAGE = 'Die Admin-MFA-Verschlüsselung ist nicht sicher konfiguriert.';

    private ?string $encryptionKey;
    private ?string $recoveryHashKey;

    public function __construct(string $base64MasterKey, string $applicationSecret = '')
    {
        $configuredKey = trim($base64MasterKey);
        if ('' !== $configuredKey) {
            $key = base64_decode($configuredKey, true);
            if (false === $key || self::MASTER_KEY_BYTES !== strlen($key) || base64_encode($key) !== $configuredKey) {
                $this->encryptionKey = null;
                $this->recoveryHashKey = null;

                return;
            }
        } else {
            $applicationSecret = trim($applicationSecret);
            if (!self::isUsableApplicationSecret($applicationSecret)) {
                $this->encryptionKey = null;
                $this->recoveryHashKey = null;

                return;
            }

            $key = hash_hkdf(
                'sha256',
                $applicationSecret,
                self::MASTER_KEY_BYTES,
                'arcanorum/admin-mfa/master-key/v1',
            );
        }

        $this->encryptionKey = hash_hkdf(
            'sha256',
            $key,
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            'arcanorum/admin-mfa/totp-secret/v1',
        );
        $this->recoveryHashKey = hash_hkdf(
            'sha256',
            $key,
            32,
            'arcanorum/admin-mfa/recovery-code-hmac/v1',
        );
    }

    private static function isUsableApplicationSecret(string $secret): bool
    {
        if (32 > strlen($secret)) {
            return false;
        }

        $normalized = strtolower($secret);
        foreach (['placeholder', 'change-me', 'changeme', 'replace-me', 'example'] as $marker) {
            if (str_contains($normalized, $marker)) {
                return false;
            }
        }

        return true;
    }

    public function encrypt(string $secret): string
    {
        if (strlen($secret) < 20 || strlen($secret) > 64) {
            throw new \InvalidArgumentException('Das TOTP-Geheimnis hat eine ungültige Länge.');
        }

        $encryptionKey = $this->requireEncryptionKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($secret, $nonce, $encryptionKey);

        return self::CIPHER_PREFIX.base64_encode($nonce.$ciphertext);
    }

    public function decrypt(string $encryptedSecret): string
    {
        $encryptionKey = $this->requireEncryptionKey();
        if (!str_starts_with($encryptedSecret, self::CIPHER_PREFIX)) {
            throw new \UnexpectedValueException('Das gespeicherte TOTP-Geheimnis hat eine unbekannte Version.');
        }

        $encoded = substr($encryptedSecret, strlen(self::CIPHER_PREFIX));
        $payload = base64_decode($encoded, true);
        if (false === $payload
            || base64_encode($payload) !== $encoded
            || strlen($payload) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + 20
        ) {
            throw new \UnexpectedValueException('Das gespeicherte TOTP-Geheimnis ist beschädigt.');
        }

        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $secret = sodium_crypto_secretbox_open($ciphertext, $nonce, $encryptionKey);
        if (false === $secret || strlen($secret) < 20 || strlen($secret) > 64) {
            throw new \UnexpectedValueException('Das gespeicherte TOTP-Geheimnis kann nicht entschlüsselt werden.');
        }

        return $secret;
    }

    public function generateRecoveryCode(): string
    {
        return implode('-', str_split(Base32Encoder::encode(random_bytes(16)), 4));
    }

    /** @return non-empty-string */
    public function hashRecoveryCode(string $code): string
    {
        $normalized = strtoupper(str_replace(['-', ' '], '', trim($code)));
        if (1 !== preg_match('/\A[A-Z2-7]{26}\z/', $normalized)) {
            throw new \InvalidArgumentException('Der Wiederherstellungscode hat ein ungültiges Format.');
        }

        return hash_hmac('sha256', $normalized, $this->requireRecoveryHashKey());
    }

    public function isConfigured(): bool
    {
        return null !== $this->encryptionKey && null !== $this->recoveryHashKey;
    }

    private function requireEncryptionKey(): string
    {
        return $this->encryptionKey
            ?? throw new \InvalidArgumentException(self::INVALID_KEY_MESSAGE);
    }

    private function requireRecoveryHashKey(): string
    {
        return $this->recoveryHashKey
            ?? throw new \InvalidArgumentException(self::INVALID_KEY_MESSAGE);
    }
}
