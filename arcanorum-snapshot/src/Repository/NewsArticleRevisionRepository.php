<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NewsArticle;
use App\Entity\NewsArticleRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<NewsArticleRevision> */
final class NewsArticleRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsArticleRevision::class);
    }

    public function findLatestForArticle(NewsArticle $article): ?NewsArticleRevision
    {
        $revision = $this->findOneBy(['article' => $article], ['version' => 'DESC']);

        return $revision instanceof NewsArticleRevision ? $revision : null;
    }

    /** @return list<NewsArticleRevision> */
    public function findForArticle(NewsArticle $article, int $limit = 25, int $offset = 0): array
    {
        /** @var list<NewsArticleRevision> $revisions */
        $revisions = $this->createQueryBuilder('revision')
            ->andWhere('revision.article = :article')
            ->setParameter('article', $article)
            ->orderBy('revision.version', \SortDirection::Descending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $revisions;
    }

    public function countForArticle(NewsArticle $article): int
    {
        return (int) $this->createQueryBuilder('revision')
            ->select('COUNT(revision.id)')
            ->andWhere('revision.article = :article')
            ->setParameter('article', $article)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param list<string> $articleIds
     * @return list<string>
     */
    public function findThumbnailObjectIdsForArticles(array $articleIds): array
    {
        $articleIds = array_values(array_unique($articleIds));
        if ([] === $articleIds) {
            return [];
        }

        /** @var list<array{thumbnailObjectId: mixed}> $rows */
        $rows = $this->createQueryBuilder('revision')
            ->select('revision.thumbnailObjectId')
            ->andWhere('IDENTITY(revision.article) IN (:articleIds)')
            ->setParameter('articleIds', $articleIds)
            ->getQuery()
            ->getScalarResult();

        $objectIds = [];
        foreach ($rows as $row) {
            if (is_string($row['thumbnailObjectId'] ?? null)) {
                $objectIds[] = $row['thumbnailObjectId'];
            }
        }

        return array_values(array_unique($objectIds));
    }
}
