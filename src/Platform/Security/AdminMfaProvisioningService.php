<?php

declare(strict_types=1);

namespace App\Platform\Security;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminMfaProvisioningService
{
    private const int RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdminUserRepository $users,
        private AdminMfaSecretCipher $cipher,
    ) {
    }

    public function provision(AdminUser $user): AdminMfaProvisioningResult
    {
        $expectedSecretCiphertext = $user->getMfaSecretCiphertext();
        $provisioningConflict = false;

        $secret = random_bytes(20);
        $secretBase32 = Base32Encoder::encode($secret);
        $recoveryCodes = [];
        $recoveryHashes = [];

        for ($index = 0; $index < self::RECOVERY_CODE_COUNT; ++$index) {
            $code = $this->cipher->generateRecoveryCode();
            $recoveryCodes[] = $code;
            $recoveryHashes[] = $this->cipher->hashRecoveryCode($code);
        }

        $encryptedSecret = $this->cipher->encrypt($secret);

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($user, $encryptedSecret, $recoveryHashes, $expectedSecretCiphertext, &$provisioningConflict): void {
            if (!$entityManager->contains($user)) {
                $entityManager->persist($user);
            }

            if (!$entityManager->getUnitOfWork()->isScheduledForInsert($user)) {
                $entityManager->refresh($user, LockMode::PESSIMISTIC_WRITE);
                if ($user->getMfaSecretCiphertext() !== $expectedSecretCiphertext) {
                    $provisioningConflict = true;

                    return;
                }
            }

            $user->setMfaSecretCiphertext($encryptedSecret);
            $user->setMfaLastTotpCounter(null);
            $entityManager->flush();

            $this->users->replaceRecoveryCodeHashes($user, $recoveryHashes);
        });

        if ($provisioningConflict) {
            throw new AdminMfaProvisioningConflict('Das MFA-Geheimnis wurde zwischenzeitlich geändert; starte die Provisionierung erneut.');
        }

        $label = rawurlencode('Arcanorum:'.$user->getUserIdentifier());
        $otpauthUri = sprintf(
            'otpauth://totp/%s?secret=%s&issuer=Arcanorum&algorithm=SHA1&digits=6&period=30',
            $label,
            $secretBase32,
        );

        return new AdminMfaProvisioningResult($secretBase32, $otpauthUri, $recoveryCodes);
    }
}
