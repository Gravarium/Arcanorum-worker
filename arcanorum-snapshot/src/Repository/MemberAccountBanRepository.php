<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MemberAccount;
use App\Entity\MemberAccountBan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemberAccountBan>
 */
final class MemberAccountBanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberAccountBan::class);
    }

    public function findActiveForMember(MemberAccount $member, bool $lock = false): ?MemberAccountBan
    {
        $query = $this->createQueryBuilder('ban')
            ->andWhere('ban.member = :member')
            ->andWhere('ban.revokedAt IS NULL')
            ->setParameter('member', $member)
            ->setMaxResults(1)
            ->getQuery();

        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        $ban = $query->getOneOrNullResult();

        return $ban instanceof MemberAccountBan ? $ban : null;
    }

    /** @return list<MemberAccountBan> */
    public function findActiveForAdmin(string $search, int $limit = 25, int $offset = 0): array
    {
        /** @var list<MemberAccountBan> $bans */
        $bans = $this->createActiveAdminQueryBuilder($search)
            ->leftJoin('ban.bannedBy', 'banAdmin')
            ->addSelect('bannedMember', 'banAdmin')
            ->orderBy('ban.createdAt', \SortDirection::Descending)
            ->addOrderBy('ban.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $bans;
    }

    public function countActiveForAdmin(string $search): int
    {
        return (int) $this->createActiveAdminQueryBuilder($search)
            ->select('COUNT(ban.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function createActiveAdminQueryBuilder(string $search): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('ban')
            ->innerJoin('ban.member', 'bannedMember')
            ->andWhere('ban.revokedAt IS NULL');
        $normalizedSearch = mb_strtolower(trim($search), 'UTF-8');

        if ('' !== $normalizedSearch) {
            $queryBuilder
                ->andWhere('(LOWER(bannedMember.email) LIKE :search OR LOWER(bannedMember.handle) LIKE :search OR LOWER(bannedMember.displayName) LIKE :search)')
                ->setParameter('search', '%'.$normalizedSearch.'%');
        }

        return $queryBuilder;
    }
}
