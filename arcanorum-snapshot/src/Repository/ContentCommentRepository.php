<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommentContentType;
use App\Entity\CommentSortOrder;
use App\Entity\CommentStatus;
use App\Entity\ContentComment;
use App\Entity\ContentRating;
use App\Entity\ContentRatingValue;
use App\Entity\MemberAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
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
    public function findApprovedForContent(
        CommentContentType $type,
        string $contentId,
        CommentSortOrder $sortOrder = CommentSortOrder::Added,
        int $limit = 100,
        int $offset = 0,
    ): array {
        $query = $this->createQueryBuilder('comment')
            ->andWhere('comment.contentType = :type')
            ->andWhere('comment.contentId = :contentId')
            ->andWhere('comment.status = :status')
            ->setParameter('type', $type)
            ->setParameter('contentId', $contentId)
            ->setParameter('status', CommentStatus::Approved)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)));

        if (CommentSortOrder::Score === $sortOrder) {
            $query
                ->leftJoin(ContentRating::class, 'rating', Join::WITH, 'rating.comment = comment')
                ->addSelect(
                    'COALESCE(SUM(CASE WHEN rating.value = :like THEN 1 WHEN rating.value = :dislike THEN -1 ELSE 0 END), 0) AS HIDDEN commentScore',
                )
                ->setParameter('like', ContentRatingValue::Like->value)
                ->setParameter('dislike', ContentRatingValue::Dislike->value)
                ->groupBy('comment.id')
                ->orderBy('commentScore', \SortDirection::Descending);
        } else {
            $query->orderBy('comment.createdAt', \SortDirection::Descending);
        }

        /** @var list<ContentComment> $comments */
        $comments = $query
            ->addOrderBy('comment.createdAt', \SortDirection::Descending)
            ->addOrderBy('comment.id', \SortDirection::Descending)
            ->getQuery()
            ->getResult();

        return $comments;
    }

    public function countApprovedForContent(CommentContentType $type, string $contentId): int
    {
        return (int) $this->createQueryBuilder('comment')
            ->select('COUNT(comment.id)')
            ->andWhere('comment.contentType = :type')
            ->andWhere('comment.contentId = :contentId')
            ->andWhere('comment.status = :status')
            ->setParameter('type', $type)
            ->setParameter('contentId', $contentId)
            ->setParameter('status', CommentStatus::Approved)
            ->getQuery()
            ->getSingleScalarResult();
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

    /** @return list<ContentComment> */
    public function findLatestForAdmin(): array
    {
        /** @var list<ContentComment> $comments */
        $comments = $this->createQueryBuilder('comment')
            ->orderBy('comment.createdAt', \SortDirection::Descending)
            ->addOrderBy('comment.id', \SortDirection::Descending)
            ->setMaxResults(5)
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

    public function countForAdmin(): int
    {
        return (int) $this->createQueryBuilder('comment')
            ->select('COUNT(comment.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
