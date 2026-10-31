<?php

declare(strict_types=1);

namespace App\Platform\Security;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;

final readonly class AdminMfaLoginVerifier
{
    public function __construct(
        private AdminMfaSecretCipher $cipher,
        private AdminUserRepository $users,
        private TotpVerifier $totpVerifier,
    ) {
    }

    public function verifyAndConsume(AdminUser $user, string $providedCode): bool
    {
        $encryptedSecret = $user->getMfaSecretCiphertext();
        if (strlen($providedCode) > 64 || null === $encryptedSecret) {
            return false;
        }

        try {
            $secret = $this->cipher->decrypt($encryptedSecret);
        } catch (\Throwable) {
            return false;
        }

        $normalized = strtoupper(str_replace(['-', ' '], '', trim($providedCode)));
        if (1 === preg_match('/\A[A-Z2-7]{26}\z/', $normalized)) {
            try {
                return $this->users->consumeRecoveryCode($user, $encryptedSecret, $this->cipher->hashRecoveryCode($normalized));
            } catch (\Throwable) {
                return false;
            }
        }

        $counter = $this->totpVerifier->matchingCounter($secret, $providedCode, time());
        if (null === $counter) {
            return false;
        }

        try {
            return $this->users->advanceTotpCounter($user, $counter, $encryptedSecret);
        } catch (\Throwable) {
            return false;
        }
    }
}
