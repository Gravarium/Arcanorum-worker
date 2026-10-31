<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommentFilterAction;
use App\Entity\CommentFilterTerm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommentFilterTerm>
 */
final class CommentFilterTermRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommentFilterTerm::class);
    }

    public function findByNormalizedTermAndAction(
        string $normalizedTerm,
        CommentFilterAction $action,
    ): ?CommentFilterTerm {
        $term = $this->findOneBy(['normalizedTerm' => $normalizedTerm, 'action' => $action]);

        return $term instanceof CommentFilterTerm ? $term : null;
    }

    /** @return list<CommentFilterTerm> */
    public function findForAdmin(): array
    {
        /** @var list<CommentFilterTerm> $terms */
        $terms = $this->createQueryBuilder('term')
            ->orderBy('term.normalizedTerm', \SortDirection::Ascending)
            ->addOrderBy('term.action', \SortDirection::Ascending)
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        return $terms;
    }

    /** @return list<CommentFilterTerm> */
    public function findForMatching(): array
    {
        return $this->findForAdmin();
    }

    public function countForAdmin(): int
    {
        return (int) $this->createQueryBuilder('term')
            ->select('COUNT(term.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
