<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExternalVideoSourceStatus;
use App\Entity\PublishingStatus;
use App\Entity\SiteSettings;
use App\Entity\Video;
use App\Entity\VideoPopularitySort;
use App\Entity\VideoSourceType;
use App\Module\Automation\Application\ExternalVideoSourceStore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Video>
 */
final class VideoRepository extends ServiceEntityRepository implements ExternalVideoSourceStore
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
            ->andWhere('video.publishedAt <= :now')
            ->setParameter('status', PublishingStatus::Published);
        $queryBuilder->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

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
            ->addOrderBy('video.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $videos;
    }

    /** @return list<Video> */
    public function findPublishedForCategory(string $category, int $limit = 10): array
    {
        $normalizedCategory = mb_strtolower(trim($category));
        if ('' === $normalizedCategory) {
            return [];
        }

        /** @var list<Video> $videos */
        $videos = $this->createQueryBuilder('video')
            ->andWhere('video.status = :status')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->andWhere('LOWER(TRIM(video.category)) = :category')
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setParameter('category', $normalizedCategory)
            ->orderBy('video.publishedAt', \SortDirection::Descending)
            ->addOrderBy('video.id', \SortDirection::Ascending)
            ->setMaxResults(max(1, min(10, $limit)))
            ->getQuery()
            ->getResult();

        return $videos;
    }

    public function findFeaturedPublished(): ?Video
    {
        $video = $this->createQueryBuilder('video')
            ->andWhere('video.status = :status')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->andWhere('video.featured = :featured')
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setParameter('featured', true)
            ->orderBy('video.publishedAt', \SortDirection::Descending)
            ->addOrderBy('video.id', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $video instanceof Video ? $video : null;
    }

    /** @return list<Video> */
    public function findFeaturedPublishedList(int $limit): array
    {
        /** @var list<Video> $videos */
        $videos = $this->createQueryBuilder('video')
            ->andWhere('video.status = :status')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->andWhere('video.featured = :featured')
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setParameter('featured', true)
            ->orderBy('video.publishedAt', \SortDirection::Descending)
            ->addOrderBy('video.id', \SortDirection::Ascending)
            ->setMaxResults(max(1, min(8, $limit)))
            ->getQuery()
            ->getResult();

        return $videos;
    }

    public function countPublicPublished(): int
    {
        return (int) $this->createQueryBuilder('video')
            ->select('COUNT(video.id)')
            ->andWhere('video.status = :status')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<Video> */
    public function findPopular(
        int $limit = 24,
        int $offset = 0,
        VideoPopularitySort $sort = VideoPopularitySort::TotalViews,
        int $periodDays = 30,
    ): array {
        $sortExpression = match ($sort) {
            VideoPopularitySort::TotalViews => 'video.view_count DESC',
            VideoPopularitySort::RecentViews => 'COALESCE(recent.view_count, 0) DESC',
            VideoPopularitySort::MemberRatings => 'COALESCE(ratings.likes, 0) - COALESCE(ratings.dislikes, 0) DESC, COALESCE(ratings.likes, 0) DESC',
        };
        $periodDays = in_array($periodDays, [7, 30, 90], true) ? $periodDays : 30;
        $connection = $this->getEntityManager()->getConnection();
        $ids = $connection->fetchFirstColumn(
            'WITH recent AS (SELECT video_id, SUM(view_count) AS view_count FROM video_daily_views WHERE view_date >= CURRENT_DATE - CAST(:period_days AS INTEGER) GROUP BY video_id), '
            .'ratings AS (SELECT video_id, COUNT(*) FILTER (WHERE value = \'like\') AS likes, COUNT(*) FILTER (WHERE value = \'dislike\') AS dislikes FROM content_ratings WHERE video_id IS NOT NULL GROUP BY video_id) '
            .'SELECT video.id FROM videos video LEFT JOIN recent ON recent.video_id = video.id LEFT JOIN ratings ON ratings.video_id = video.id '
            .'WHERE video.status = :status AND video.published_at IS NOT NULL AND video.published_at <= :now '
            .'ORDER BY '.$sortExpression.', video.published_at DESC, video.id ASC LIMIT :limit OFFSET :offset',
            [
                'period_days' => $periodDays,
                'status' => PublishingStatus::Published->value,
                'now' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'limit' => max(1, min(100, $limit)),
                'offset' => max(0, $offset),
            ],
            [
                'period_days' => Types::INTEGER,
                'status' => Types::STRING,
                'now' => Types::DATETIME_IMMUTABLE,
                'limit' => Types::INTEGER,
                'offset' => Types::INTEGER,
            ],
        );

        return $this->findVisibleVideosInIdOrder($ids);
    }

    /** @return list<Video> */
    public function findCurrentlyWatchedPublished(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        /** @var list<Video> $videos */
        $videos = $this->createQueryBuilder('video')
            ->andWhere('video.status = :status')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->andWhere('video.lastViewedAt >= :cutoff')
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', $now)
            ->setParameter('cutoff', $now->sub(new \DateInterval('PT5M')))
            ->orderBy('video.lastViewedAt', \SortDirection::Descending)
            ->addOrderBy('video.id', \SortDirection::Ascending)
            ->setMaxResults(max(1, min(12, $limit)))
            ->getQuery()
            ->getResult();

        return $videos;
    }

    /** @return list<Video> */
    public function findRelatedPublished(Video $source, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $sourceTagIds = [];
        foreach ($source->getTags() as $tag) {
            $sourceTagIds[] = $tag->getId();
        }

        $sourceCategory = $source->getCategory();
        if ([] === $sourceTagIds && (null === $sourceCategory || '' === trim($sourceCategory))) {
            return [];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $queryBuilder = $this->createQueryBuilder('video')
            ->andWhere('video.status = :status')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->andWhere('video.id <> :sourceId')
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', $now)
            ->setParameter('sourceId', $source->getId());

        if ([] !== $sourceTagIds && null !== $sourceCategory && '' !== trim($sourceCategory)) {
            $taggedVideos = $this->getEntityManager()->createQueryBuilder()
                ->select('taggedVideo.id')
                ->from(Video::class, 'taggedVideo')
                ->innerJoin('taggedVideo.tags', 'sharedTag')
                ->andWhere('sharedTag.id IN (:tagIds)');
            $queryBuilder
                ->andWhere('(video.category = :category OR video.id IN ('.$taggedVideos->getDQL().'))')
                ->setParameter('category', $sourceCategory)
                ->setParameter('tagIds', $sourceTagIds);
        } elseif ([] !== $sourceTagIds) {
            $taggedVideos = $this->getEntityManager()->createQueryBuilder()
                ->select('taggedVideo.id')
                ->from(Video::class, 'taggedVideo')
                ->innerJoin('taggedVideo.tags', 'sharedTag')
                ->andWhere('sharedTag.id IN (:tagIds)');
            $queryBuilder
                ->andWhere('video.id IN ('.$taggedVideos->getDQL().')')
                ->setParameter('tagIds', $sourceTagIds);
        } elseif ('' !== trim($sourceCategory)) {
            $queryBuilder
                ->andWhere('video.category = :category')
                ->setParameter('category', $sourceCategory);
        }

        /** @var list<Video> $videos */
        $videos = $queryBuilder
            ->orderBy('video.publishedAt', \SortDirection::Descending)
            ->addOrderBy('video.id', \SortDirection::Ascending)
            ->setMaxResults(max(1, min(12, $limit)))
            ->getQuery()
            ->getResult();

        return $videos;
    }

    /** @return list<Video> */
    public function findTopRatedPublished(int $limit, ?string $category = null, ?string $excludeId = null): array
    {
        if ($limit <= 0) {
            return [];
        }

        $where = ['video.status = :status', 'video.published_at IS NOT NULL', 'video.published_at <= :now'];
        $parameters = [
            'status' => PublishingStatus::Published->value,
            'now' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'limit' => max(1, min(12, $limit)),
        ];
        $types = ['status' => Types::STRING, 'now' => Types::DATETIME_IMMUTABLE, 'limit' => Types::INTEGER];
        if (null !== $category && '' !== trim($category)) {
            $where[] = 'video.category = :category';
            $parameters['category'] = $category;
            $types['category'] = Types::STRING;
        }
        if (null !== $excludeId) {
            $where[] = 'video.id <> :exclude_id';
            $parameters['exclude_id'] = $excludeId;
            $types['exclude_id'] = Types::STRING;
        }
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'WITH ratings AS (SELECT video_id, COUNT(*) FILTER (WHERE value = \'like\') AS likes, COUNT(*) FILTER (WHERE value = \'dislike\') AS dislikes '
            .'FROM content_ratings WHERE video_id IS NOT NULL GROUP BY video_id) '
            .'SELECT video.id FROM videos video LEFT JOIN ratings ON ratings.video_id = video.id WHERE '.implode(' AND ', $where).' '
            .'ORDER BY COALESCE(ratings.likes, 0) - COALESCE(ratings.dislikes, 0) DESC, COALESCE(ratings.likes, 0) DESC, video.published_at DESC, video.id ASC LIMIT :limit',
            $parameters,
            $types,
        );

        return $this->findVisibleVideosInIdOrder($ids);
    }

    /** @param list<mixed> $ids
     * @return list<Video>
     */
    private function findVisibleVideosInIdOrder(array $ids): array
    {
        $validIds = array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id) && 1 === preg_match('/\A[a-f0-9]{32}\z/D', $id)));
        if ([] === $validIds) {
            return [];
        }

        /** @var list<Video> $videos */
        $videos = $this->createQueryBuilder('video')
            ->andWhere('video.id IN (:ids)')
            ->andWhere('video.status = :status')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->setParameter('ids', $validIds)
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->getQuery()
            ->getResult();
        $byId = [];
        foreach ($videos as $video) {
            $byId[$video->getId()] = $video;
        }

        $ordered = [];
        foreach ($validIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    public function recordPublicView(string $id, int $autoFeatureViewThreshold = 0): void
    {
        if ($autoFeatureViewThreshold < 0 || $autoFeatureViewThreshold > SiteSettings::MAX_AUTO_FEATURE_VIEW_THRESHOLD) {
            throw new \InvalidArgumentException('Die Schwelle für automatische Video-Hervorhebung liegt außerhalb des gültigen Ganzzahlbereichs.');
        }

        $connection = $this->getEntityManager()->getConnection();
        $connection->transactional(function () use ($connection, $id, $autoFeatureViewThreshold): void {
            $updated = $connection->executeStatement(
                'UPDATE videos SET view_count = CASE WHEN view_count < 2147483647 THEN view_count + 1 ELSE 2147483647 END, featured = CASE WHEN view_count = :auto_feature_view_threshold - 1 THEN TRUE ELSE featured END, last_viewed_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :status AND published_at IS NOT NULL AND published_at <= CURRENT_TIMESTAMP',
                [
                    'id' => $id,
                    'status' => PublishingStatus::Published->value,
                    'auto_feature_view_threshold' => $autoFeatureViewThreshold,
                ],
            );
            if (1 !== $updated) {
                return;
            }

            $connection->executeStatement(
                'INSERT INTO video_daily_views (video_id, view_date, view_count) VALUES (:id, :view_date, 1) '
                    .'ON CONFLICT (video_id, view_date) DO UPDATE SET view_count = CASE WHEN video_daily_views.view_count < 2147483647 THEN video_daily_views.view_count + 1 ELSE 2147483647 END',
                [
                    'id' => $id,
                    'view_date' => new \DateTimeImmutable('today', new \DateTimeZone('UTC')),
                ],
                ['view_date' => Types::DATE_IMMUTABLE],
            );
        });
    }

    public function recordPublicWatchPulse(string $id): bool
    {
        $updated = $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE videos SET last_viewed_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :status AND published_at IS NOT NULL AND published_at <= CURRENT_TIMESTAMP AND (last_viewed_at IS NULL OR last_viewed_at < CURRENT_TIMESTAMP - INTERVAL '1 minute')",
            ['id' => $id, 'status' => PublishingStatus::Published->value],
        );

        return 1 === $updated;
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

    /** @return list<Video> */
    public function findExternalSourcesNeedingCheck(\DateTimeImmutable $cutoff, int $limit = 25): array
    {
        /** @var list<Video> $videos */
        $videos = $this->createQueryBuilder('video')
            ->andWhere('video.sourceType = :sourceType')
            ->andWhere('(video.externalSourceCheckedAt IS NULL OR video.externalSourceCheckedAt <= :cutoff)')
            ->setParameter('sourceType', VideoSourceType::Embed)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('video.externalSourceCheckedAt', 'ASC')
            ->addOrderBy('video.id', 'ASC')
            ->setMaxResults(max(1, min(25, $limit)))
            ->getQuery()
            ->getResult();

        return $videos;
    }

    /** @return list<Video> */
    public function findUnavailableExternalSources(int $limit = 100): array
    {
        /** @var list<Video> $videos */
        $videos = $this->createQueryBuilder('video')
            ->andWhere('video.externalSourceStatus = :status')
            ->setParameter('status', ExternalVideoSourceStatus::Unavailable)
            ->orderBy('video.externalSourceCheckedAt', 'DESC')
            ->addOrderBy('video.id', 'ASC')
            ->setMaxResults(max(1, min(250, $limit)))
            ->getQuery()
            ->getResult();

        return $videos;
    }

    public function findPublishedById(string $id): ?Video
    {
        $video = $this->createQueryBuilder('video')
            ->andWhere('video.id = :id')
            ->andWhere('video.status = :status')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->setParameter('id', $id)
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->getQuery()
            ->getOneOrNullResult();

        return $video instanceof Video && null !== $video->getPublishedAt()
            ? $video
            : null;
    }
}
