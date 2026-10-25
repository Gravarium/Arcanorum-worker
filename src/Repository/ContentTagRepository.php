<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
