<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** @extends ServiceEntityRepository<AdminUser> */
final class AdminUserRepository extends ServiceEntityRepository implements UserLoaderInterface, PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminUser::class);
    }

    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        $user = $this->createQueryBuilder('adminUser')
            ->andWhere('LOWER(adminUser.email) = LOWER(:identifier)')
            ->setParameter('identifier', trim($identifier))
            ->getQuery()
            ->getOneOrNullResult();

        return $user instanceof UserInterface ? $user : null;
    }

    public function findOneByEmail(string $email): ?AdminUser
    {
        $user = $this->findOneBy(['email' => mb_strtolower(trim($email))]);

        return $user instanceof AdminUser ? $user : null;
    }

    /** @return list<AdminUser> */
    public function findForRoleManagement(int $limit, int $offset): array
    {
        $accounts = $this->createQueryBuilder('adminUser')
            ->orderBy('adminUser.email', \SortDirection::Ascending)
            ->addOrderBy('adminUser.id', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        if (!is_array($accounts)) {
            throw new \UnexpectedValueException('Die Mitarbeiterkonten konnten nicht als Liste gelesen werden.');
        }
        foreach ($accounts as $account) {
            if (!$account instanceof AdminUser) {
                throw new \UnexpectedValueException('Die Mitarbeiterliste enthält einen ungültigen Kontodatensatz.');
            }
        }

        return array_values($accounts);
    }

    public function countForRoleManagement(): int
    {
        return $this->count([]);
    }

    public function countAdministratorsForRoleManagement(): int
    {
        $result = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM admin_users WHERE CAST(roles AS JSONB) @> CAST(:role AS JSONB)',
            ['role' => json_encode([AdminRole::Administrator->value], JSON_THROW_ON_ERROR)],
            ['role' => ParameterType::STRING],
        );

        if (!is_int($result) && (!is_string($result) || !ctype_digit($result))) {
            throw new \UnexpectedValueException('Die Zahl der Administratorkonten konnte nicht sicher gelesen werden.');
        }

        return (int) $result;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof AdminUser) {
            throw new \InvalidArgumentException('Der Passwort-Upgrader unterstützt nur Administratorkonten.');
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    public function advanceTotpCounter(AdminUser $user, int $counter, string $expectedSecretCiphertext): bool
    {
        if ($counter < 0 || '' === $expectedSecretCiphertext) {
            return false;
        }

        $updatedRows = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE admin_users SET mfa_last_totp_counter = :counter WHERE id = :id AND mfa_secret_ciphertext = :expected_secret_ciphertext AND (mfa_last_totp_counter IS NULL OR mfa_last_totp_counter < :counter)',
            ['counter' => $counter, 'id' => $user->getId(), 'expected_secret_ciphertext' => $expectedSecretCiphertext],
            ['counter' => ParameterType::INTEGER, 'id' => ParameterType::STRING, 'expected_secret_ciphertext' => ParameterType::STRING],
        );

        if (1 !== $updatedRows) {
            return false;
        }

        $user->setMfaLastTotpCounter($counter);

        return true;
    }

    /** @param list<non-empty-string> $hashes */
    public function replaceRecoveryCodeHashes(AdminUser $user, array $hashes): void
    {
        foreach ($hashes as $hash) {
            if (1 !== preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
                throw new \InvalidArgumentException('Wiederherstellungscode-Hashes müssen aus 64 Hex-Zeichen bestehen.');
            }
        }

        $connection = $this->getEntityManager()->getConnection();
        $connection->delete('admin_user_recovery_codes', ['admin_user_id' => $user->getId()]);

        foreach ($hashes as $hash) {
            $connection->insert('admin_user_recovery_codes', [
                'admin_user_id' => $user->getId(),
                'code_hash' => $hash,
            ]);
        }
    }

    public function consumeRecoveryCode(AdminUser $user, string $expectedSecretCiphertext, string $hash): bool
    {
        if ('' === $expectedSecretCiphertext || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
            return false;
        }

        $connection = $this->getEntityManager()->getConnection();
        $userId = $user->getId();

        return $connection->transactional(function (Connection $connection) use ($userId, $expectedSecretCiphertext, $hash): bool {
            $activeSecretCiphertext = $connection->fetchOne(
                'SELECT mfa_secret_ciphertext FROM admin_users WHERE id = :id FOR UPDATE',
                ['id' => $userId],
                ['id' => ParameterType::STRING],
            );
            if (!is_string($activeSecretCiphertext) || !hash_equals($expectedSecretCiphertext, $activeSecretCiphertext)) {
                return false;
            }

            $deletedRows = $connection->executeStatement(
                'DELETE FROM admin_user_recovery_codes WHERE admin_user_id = :id AND code_hash = :hash',
                ['id' => $userId, 'hash' => $hash],
                ['id' => ParameterType::STRING, 'hash' => ParameterType::STRING],
            );

            return 1 === $deletedRows;
        });
    }
}
