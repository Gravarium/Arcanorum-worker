<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentTag;
use App\Entity\PublishingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentTag>
 */
final class ContentTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentTag::class);
    }

    /** @return list<ContentTag> */
    public function findForAdmin(): array
    {
        /** @var list<ContentTag> $tags */
        $tags = $this->createQueryBuilder('tag')
            ->orderBy('tag.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $tags;
    }

    /** @return list<ContentTag> */
    public function findForNewsAdmin(): array
    {
        $tagIds = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT tag_id FROM news_article_tags ORDER BY tag_id ASC',
        );
        if ([] === $tagIds) {
            return [];
        }

        /** @var list<ContentTag> $tags */
        $tags = $this->createQueryBuilder('tag')
            ->andWhere('tag.id IN (:ids)')
            ->setParameter('ids', $tagIds)
            ->orderBy('tag.name', \SortDirection::Ascending)
            ->addOrderBy('tag.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $tags;
    }

    /** @return list<array{name: string, usage_count: int}> */
    public function findPublicVideoCloud(int $limit, bool $shuffle = false): array
    {
        if ($limit <= 0) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $order = $shuffle
            ? 'md5(candidates.normalized_name || CURRENT_DATE::text) ASC, candidates.name ASC'
            : 'candidates.usage_count DESC, candidates.name ASC';
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'WITH candidates AS (SELECT tag.name, tag.normalized_name, COUNT(DISTINCT video.id) AS usage_count '
            .'FROM content_tags tag INNER JOIN video_tags video_tag ON video_tag.tag_id = tag.id '
            .'INNER JOIN videos video ON video.id = video_tag.video_id '
            .'WHERE video.status = :status AND video.published_at IS NOT NULL AND video.published_at <= :now '
            .'GROUP BY tag.id, tag.name, tag.normalized_name ORDER BY usage_count DESC, tag.normalized_name ASC LIMIT 250) '
            .'SELECT name, usage_count FROM candidates ORDER BY '.$order.' LIMIT :limit',
            [
                'status' => PublishingStatus::Published->value,
                'now' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'limit' => $limit,
            ],
            ['status' => Types::STRING, 'now' => Types::DATETIME_IMMUTABLE, 'limit' => Types::INTEGER],
        );

        $cloud = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            $usage = $row['usage_count'] ?? null;
            if (!is_string($name) || '' === $name || (!is_int($usage) && !(is_string($usage) && ctype_digit($usage)))) {
                continue;
            }
            $cloud[] = ['name' => $name, 'usage_count' => (int) $usage];
        }

        return $cloud;
    }

    public function findByNormalizedName(string $normalizedName): ?ContentTag
    {
        $tag = $this->findOneBy(['normalizedName' => mb_strtolower(trim($normalizedName))]);

        return $tag instanceof ContentTag ? $tag : null;
    }

    public function countUsage(ContentTag $tag): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $newsUsage = $connection->fetchOne(
            'SELECT COUNT(*) FROM news_article_tags WHERE tag_id = :tag_id',
            ['tag_id' => $tag->getId()],
        );
        $videoUsage = $connection->fetchOne(
            'SELECT COUNT(*) FROM video_tags WHERE tag_id = :tag_id',
            ['tag_id' => $tag->getId()],
        );

        return self::countResult($newsUsage) + self::countResult($videoUsage);
    }

    private static function countResult(mixed $count): int
    {
        if (is_int($count)) {
            return $count;
        }
        if (is_string($count) && ctype_digit($count)) {
            return (int) $count;
        }

        throw new \UnexpectedValueException('The tag usage count must be a non-negative integer.');
    }
}
