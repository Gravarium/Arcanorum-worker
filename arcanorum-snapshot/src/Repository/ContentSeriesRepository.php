<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentSeries;
use App\Entity\PublishingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentSeries>
 */
final class ContentSeriesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentSeries::class);
    }

    /** @return list<ContentSeries> */
    public function findForAdmin(string $search = '', ?PublishingStatus $status = null): array
    {
        $queryBuilder = $this->createQueryBuilder('series');
        if ('' !== $search) {
            $queryBuilder
                ->andWhere('(series.title LIKE :search OR series.description LIKE :search)')
                ->setParameter('search', '%'.$search.'%');
        }
        if (null !== $status) {
            $queryBuilder->andWhere('series.status = :status')->setParameter('status', $status);
        }

        /** @var list<ContentSeries> $series */
        $series = $queryBuilder
            ->orderBy('series.updatedAt', \SortDirection::Descending)
            ->setMaxResults(1000)
            ->getQuery()
            ->getResult();

        return $series;
    }

    public function countForAdmin(?PublishingStatus $status = null): int
    {
        $queryBuilder = $this->createQueryBuilder('series')
            ->select('COUNT(series.id)');

        if (null !== $status) {
            $queryBuilder
                ->andWhere('series.status = :status')
                ->setParameter('status', $status);
        }

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /** @return list<ContentSeries> */
    public function findPublished(int $limit = 24, int $offset = 0, string $search = ''): array
    {
        $queryBuilder = $this->createQueryBuilder('series')
            ->andWhere('series.status = :status')
            ->andWhere('series.publishedAt IS NOT NULL')
            ->setParameter('status', PublishingStatus::Published);
        if ('' !== $search) {
            $queryBuilder
                ->andWhere('(series.title LIKE :search OR series.description LIKE :search)')
                ->setParameter('search', '%'.$search.'%');
        }

        /** @var list<ContentSeries> $series */
        $series = $queryBuilder
            ->orderBy('series.publishedAt', \SortDirection::Descending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $series;
    }

    /** @return list<ContentSeries> */
    public function findPublishedByGenre(string $genre, int $limit = 24, int $offset = 0): array
    {
        /** @var list<ContentSeries> $series */
        $series = $this->createQueryBuilder('series')
            ->innerJoin('series.genres', 'genre')
            ->andWhere('genre.normalizedName = :genre')
            ->andWhere('series.status = :status')
            ->andWhere('series.publishedAt IS NOT NULL')
            ->setParameter('genre', mb_strtolower($genre, 'UTF-8'))
            ->setParameter('status', PublishingStatus::Published)
            ->orderBy('series.publishedAt', \SortDirection::Descending)
            ->addOrderBy('series.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $series;
    }

    public function findPublishedBySlug(string $slug): ?ContentSeries
    {
        $series = $this->findOneBy([
            'slug' => $slug,
            'status' => PublishingStatus::Published,
        ]);

        return $series instanceof ContentSeries && null !== $series->getPublishedAt()
            ? $series
            : null;
    }

    public function findOneBySlug(string $slug): ?ContentSeries
    {
        $series = $this->findOneBy(['slug' => $slug]);

        return $series instanceof ContentSeries ? $series : null;
    }
}
