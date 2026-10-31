<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MemberAccount;
use App\Entity\VideoSubmission;
use App\Entity\VideoSubmissionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoSubmission>
 */
final class VideoSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoSubmission::class);
    }

    public function countForStatus(VideoSubmissionStatus $status): int
    {
        return (int) $this->createQueryBuilder('submission')
            ->select('COUNT(submission.id)')
            ->andWhere('submission.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<VideoSubmission> */
    public function findPendingForDashboard(int $limit = 5): array
    {
        /** @var list<VideoSubmission> $submissions */
        $submissions = $this->createQueryBuilder('submission')
            ->innerJoin('submission.video', 'video')
            ->addSelect('video')
            ->andWhere('submission.status = :status')
            ->setParameter('status', VideoSubmissionStatus::Pending)
            ->orderBy('submission.createdAt', \SortDirection::Descending)
            ->addOrderBy('submission.id', \SortDirection::Descending)
            ->setMaxResults(max(1, min(5, $limit)))
            ->getQuery()
            ->getResult();

        return $submissions;
    }

    /**
     * @return list<VideoSubmission>
     */
    public function findPending(): array
    {
        /** @var list<VideoSubmission> $submissions */
        $submissions = $this->createQueryBuilder('submission')
            ->innerJoin('submission.video', 'video')
            ->addSelect('video')
            ->andWhere('submission.status = :status')
            ->setParameter('status', VideoSubmissionStatus::Pending)
            ->orderBy('submission.createdAt', \SortDirection::Ascending)
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        return $submissions;
    }

    /**
     * @return list<VideoSubmission>
     */
    public function findForSubmitter(MemberAccount $submitter): array
    {
        /** @var list<VideoSubmission> $submissions */
        $submissions = $this->createQueryBuilder('submission')
            ->innerJoin('submission.video', 'video')
            ->addSelect('video')
            ->andWhere('submission.submitter = :submitter')
            ->setParameter('submitter', $submitter)
            ->orderBy('submission.createdAt', \SortDirection::Descending)
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        return $submissions;
    }

    public function findForSubmitterById(MemberAccount $submitter, string $id): ?VideoSubmission
    {
        $submission = $this->createQueryBuilder('submission')
            ->innerJoin('submission.video', 'video')
            ->addSelect('video')
            ->andWhere('submission.submitter = :submitter')
            ->andWhere('submission.id = :id')
            ->setParameter('submitter', $submitter)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $submission instanceof VideoSubmission ? $submission : null;
    }
}
