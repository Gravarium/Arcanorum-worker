<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Video>
 */
final class VideoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Video::class);
    }

    /** @return list<Video> */
    public function findForAdmin(
        string $search,
        ?PublishingStatus $status,
        int $limit = 25,
        int $offset = 0,
        ?string $category = null,
        ?string $tag = null,
        string $sort = 'created',
        string $direction = 'desc',
        ?VideoSourceType $sourceType = null,
    ): array {
        $queryBuilder = $this->createAdminQueryBuilder($search, $status, $category, $tag, $sourceType);
        $sortField = match ($sort) {
            'title' => 'video.title',
            'status' => 'video.status',
            'views' => 'video.viewCount',
            default => 'video.createdAt',
        };
        $sortDirection = 'ASC' === strtoupper($direction)
            ? \SortDirection::Ascending
            : \SortDirection::Descending;

        /** @var list<Video> $items */
        $items = $queryBuilder
            ->orderBy($sortField, $sortDirection)
            ->addOrderBy('video.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function countForAdmin(
        string $search,
        ?PublishingStatus $status,
        ?string $category = null,
        ?string $tag = null,
        ?VideoSourceType $sourceType = null,
    ): int {
        return (int) $this->createAdminQueryBuilder($search, $status, $category, $tag, $sourceType)
            ->select('COUNT(DISTINCT video.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function createAdminQueryBuilder(
        string $search,
        ?PublishingStatus $status,
        ?string $category,
        ?string $tag,
        ?VideoSourceType $sourceType,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('video');

        if ('' !== $search) {
            $queryBuilder
                ->andWhere('(video.title LIKE :search OR video.category LIKE :search)')
                ->setParameter('search', '%'.$search.'%');
        }
        if (null !== $status) {
            $queryBuilder->andWhere('video.status = :status')->setParameter('status', $status);
        }
        if (null !== $sourceType) {
            $queryBuilder->andWhere('video.sourceType = :sourceType')->setParameter('sourceType', $sourceType);
        }
        if (null !== $category && '' !== $category) {
            $queryBuilder->andWhere('video.category = :category')->setParameter('category', $category);
        }
        if (null !== $tag && '' !== $tag) {
            $queryBuilder
                ->innerJoin('video.tags', 'contentTag')
                ->andWhere('contentTag.normalizedName = :tag')
                ->setParameter('tag', mb_strtolower(trim($tag)));
        }

        return $queryBuilder;
    }

    /** @return list<Video> */
    public function findPublished(string $search = '', int $limit = 24, int $offset = 0, ?string $category = null, ?string $tag = null): array
    {
        $queryBuilder = $this->createQueryBuilder('video')
            ->andWhere('video.status = :status')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->setParameter('status', PublishingStatus::Published);

        if ('' !== $search) {
            $queryBuilder
                ->andWhere('(video.title LIKE :search OR video.category LIKE :search OR video.description LIKE :search)')
                ->setParameter('search', '%'.$search.'%');
        }

        if (null !== $category && '' !== $category) {
            $queryBuilder
                ->andWhere('video.category = :category')
                ->setParameter('category', $category);
        }

        if (null !== $tag && '' !== $tag) {
            $queryBuilder
                ->innerJoin('video.tags', 'contentTag')
                ->andWhere('contentTag.normalizedName = :tag')
                ->setParameter('tag', mb_strtolower(trim($tag)));
        }

        /** @var list<Video> $videos */
        $videos = $queryBuilder
            ->orderBy('video.publishedAt', \SortDirection::Descending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $videos;
    }

    /** @return list<Video> */
    public function findPopular(int $limit = 24, int $offset = 0): array
    {
        /** @var list<Video> $videos */
        $videos = $this->createQueryBuilder('video')
            ->andWhere('video.status = :status')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->setParameter('status', PublishingStatus::Published)
            ->orderBy('video.viewCount', \SortDirection::Descending)
            ->addOrderBy('video.publishedAt', \SortDirection::Descending)
            ->addOrderBy('video.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $videos;
    }

    public function recordPublicView(string $id): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE videos SET view_count = view_count + 1 WHERE id = :id AND status = :status AND published_at IS NOT NULL AND view_count < 2147483647',
            ['id' => $id, 'status' => PublishingStatus::Published->value],
        );
    }

    /** @param list<string> $ids
     *
     * @return list<Video>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Video> $items */
        $items = $this->createQueryBuilder('video')
            ->andWhere('video.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('video.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function findBySourceUrl(string $sourceUrl): ?Video
    {
        $video = $this->findOneBy(['sourceUrl' => $sourceUrl]);

        return $video instanceof Video ? $video : null;
    }

    public function findPublishedById(string $id): ?Video
    {
        $video = $this->findOneBy([
            'id' => $id,
            'status' => PublishingStatus::Published,
        ]);

        return $video instanceof Video && null !== $video->getPublishedAt()
            ? $video
            : null;
    }
}
