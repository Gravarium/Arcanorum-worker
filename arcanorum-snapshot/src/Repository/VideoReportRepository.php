<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VideoReport;
use App\Entity\VideoReportStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoReport>
 */
final class VideoReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoReport::class);
    }

    public function countForStatus(VideoReportStatus $status): int
    {
        return (int) $this->createQueryBuilder('report')
            ->select('COUNT(report.id)')
            ->andWhere('report.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<VideoReport>
     */
    public function findForModeration(?VideoReportStatus $status, string $search): array
    {
        $queryBuilder = $this->createQueryBuilder('report')
            ->innerJoin('report.video', 'video')
            ->addSelect('video');

        if (null !== $status) {
            $queryBuilder->andWhere('report.status = :status')->setParameter('status', $status);
        }

        if ('' !== $search) {
            $queryBuilder
                ->andWhere('(video.title LIKE :search OR report.details LIKE :search)')
                ->setParameter('search', '%'.$search.'%');
        }

        /** @var list<VideoReport> $reports */
        $reports = $queryBuilder
            ->orderBy('report.createdAt', \SortDirection::Descending)
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        return $reports;
    }
}
