<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommentContentType;
use App\Entity\CommentStatus;
use App\Entity\ContentComment;
use App\Entity\MemberAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentComment>
 */
final class ContentCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentComment::class);
    }

    /** @return list<ContentComment> */
    public function findApprovedForContent(CommentContentType $type, string $contentId): array
    {
        /** @var list<ContentComment> $comments */
        $comments = $this->createQueryBuilder('comment')
            ->andWhere('comment.contentType = :type')
            ->andWhere('comment.contentId = :contentId')
            ->andWhere('comment.status = :status')
            ->setParameter('type', $type)
            ->setParameter('contentId', $contentId)
            ->setParameter('status', CommentStatus::Approved)
            ->orderBy('comment.createdAt', \SortDirection::Ascending)
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        return $comments;
    }

    public function deleteForContent(CommentContentType $type, string $contentId): void
    {
        $this->getEntityManager()->getConnection()->delete('content_comments', [
            'content_type' => $type->value,
            'content_id' => $contentId,
        ]);
    }

    /** @return list<ContentComment> */
    public function findForMember(MemberAccount $member, int $limit, int $offset): array
    {
        /** @var list<ContentComment> $comments */
        $comments = $this->createQueryBuilder('comment')
            ->andWhere('comment.member = :member')
            ->setParameter('member', $member)
            ->orderBy('comment.createdAt', \SortDirection::Descending)
            ->addOrderBy('comment.id', \SortDirection::Descending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(51, $limit)))
            ->getQuery()
            ->getResult();

        return $comments;
    }

    /** @return list<ContentComment> */
    public function findForModeration(string $search, ?CommentStatus $status): array
    {
        $queryBuilder = $this->createQueryBuilder('comment');

        if ('' !== $search) {
            $queryBuilder
                ->andWhere('(comment.authorName LIKE :search OR comment.body LIKE :search OR comment.contentTitle LIKE :search OR comment.filteredByTerm LIKE :search)')
                ->setParameter('search', '%'.$search.'%');
        }

        if (null !== $status) {
            $queryBuilder->andWhere('comment.status = :status')->setParameter('status', $status);
        }

        /** @var list<ContentComment> $comments */
        $comments = $queryBuilder
            ->orderBy('comment.createdAt', \SortDirection::Descending)
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        return $comments;
    }

    public function countForStatus(CommentStatus $status): int
    {
        return (int) $this->createQueryBuilder('comment')
            ->select('COUNT(comment.id)')
            ->andWhere('comment.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
