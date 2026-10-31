<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MemberAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemberAccount>
 */
final class MemberAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberAccount::class);
    }

    /**
     * @return list<MemberAccount>
     */
    public function findForAdmin(
        string $search,
        int $limit = 25,
        int $offset = 0,
        ?bool $active = null,
        ?bool $banned = null,
    ): array {
        $queryBuilder = $this->createAdminQueryBuilder($search, $active, $banned);
        $queryBuilder
            ->leftJoin('account.bans', 'activeBan', 'WITH', 'activeBan.revokedAt IS NULL')
            ->addSelect('activeBan');

        /** @var list<MemberAccount> $members */
        $members = $queryBuilder
            ->orderBy('account.createdAt', \SortDirection::Descending)
            ->addOrderBy('account.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $members;
    }

    public function findByInvitationTokenHash(string $tokenHash): ?MemberAccount
    {
        $account = $this->findOneBy(['invitationTokenHash' => $tokenHash]);

        return $account instanceof MemberAccount ? $account : null;
    }

    public function consumeInvitation(string $tokenHash, string $passwordHash, \DateTimeImmutable $now): bool
    {
        $affectedRows = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE member_accounts SET password = :password, is_active = :active, email_verified = TRUE, auth_version = auth_version + 1, invitation_token_hash = NULL, invitation_expires_at = NULL '
                .'WHERE invitation_token_hash = :token_hash AND is_active = :inactive AND invitation_expires_at > :now',
            ['password' => $passwordHash, 'active' => true, 'inactive' => false, 'token_hash' => $tokenHash, 'now' => $now],
            ['password' => Types::STRING, 'active' => Types::BOOLEAN, 'inactive' => Types::BOOLEAN, 'token_hash' => Types::STRING, 'now' => Types::DATETIME_IMMUTABLE],
        );

        return 1 === $affectedRows;
    }

    public function countForAdmin(string $search, ?bool $active = null, ?bool $banned = null): int
    {
        return (int) $this->createAdminQueryBuilder($search, $active, $banned)
            ->select('COUNT(account.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<MemberAccount> */
    public function findPublic(string $search = '', int $limit = 24, int $offset = 0): array
    {
        /** @var list<MemberAccount> $members */
        $members = $this->createPublicQueryBuilder($search)
            ->orderBy('account.displayName', \SortDirection::Ascending)
            ->addOrderBy('account.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $members;
    }

    public function countPublic(string $search = ''): int
    {
        return (int) $this->createPublicQueryBuilder($search)
            ->select('COUNT(account.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPublicProfiles(): int
    {
        return $this->countPublic();
    }

    public function findPublicByHandle(string $handle): ?MemberAccount
    {
        $member = $this->findOneBy([
            'handle' => $handle,
            'active' => true,
            'emailVerified' => true,
            'publicProfile' => true,
        ]);

        return $member instanceof MemberAccount ? $member : null;
    }

    private function createPublicQueryBuilder(string $search): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('account')
            ->andWhere('account.active = :active')
            ->andWhere('account.emailVerified = :email_verified')
            ->andWhere('account.publicProfile = :public')
            ->setParameter('active', true)
            ->setParameter('email_verified', true)
            ->setParameter('public', true);
        $normalizedSearch = mb_strtolower(trim($search), 'UTF-8');

        if ('' !== $normalizedSearch) {
            $queryBuilder
                ->andWhere('(LOCATE(:search, LOWER(account.handle)) > 0 OR LOCATE(:search, LOWER(account.displayName)) > 0)')
                ->setParameter('search', $normalizedSearch);
        }

        return $queryBuilder;
    }

    private function createAdminQueryBuilder(string $search, ?bool $active, ?bool $banned): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('account');
        $normalizedSearch = mb_strtolower(trim($search), 'UTF-8');

        if ('' !== $normalizedSearch) {
            $queryBuilder
                ->andWhere('(LOWER(account.email) LIKE :search OR LOWER(account.handle) LIKE :search OR LOWER(account.displayName) LIKE :search)')
                ->setParameter('search', '%'.$normalizedSearch.'%');
        }

        if (null !== $active) {
            $queryBuilder
                ->andWhere('account.active = :active')
                ->setParameter('active', $active);
        }

        if (null !== $banned) {
            $banQuery = $this->getEntityManager()->createQueryBuilder()
                ->select('activeBanFilter.id')
                ->from(\App\Entity\MemberAccountBan::class, 'activeBanFilter')
                ->andWhere('IDENTITY(activeBanFilter.member) = account.id')
                ->andWhere('activeBanFilter.revokedAt IS NULL');
            $exists = 'EXISTS ('.$banQuery->getDQL().')';
            $queryBuilder->andWhere($banned ? $exists : 'NOT '.$exists);
        }

        return $queryBuilder;
    }
}
