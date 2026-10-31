<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsArticle>
 */
final class NewsArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsArticle::class);
    }

    /** @return list<NewsArticle> */
    public function findForAdmin(
        string $search,
        ?PublishingStatus $status,
        int $limit = 25,
        int $offset = 0,
        ?string $category = null,
        ?string $tag = null,
        string $sort = 'created',
        string $direction = 'desc',
    ): array {
        $queryBuilder = $this->createAdminQueryBuilder($search, $status, $category, $tag);
        $sortField = match ($sort) {
            'title' => 'article.title',
            'status' => 'article.status',
            default => 'article.createdAt',
        };
        $sortDirection = 'ASC' === strtoupper($direction)
            ? \SortDirection::Ascending
            : \SortDirection::Descending;

        /** @var list<NewsArticle> $items */
        $items = $queryBuilder
            ->orderBy($sortField, $sortDirection)
            ->addOrderBy('article.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function countForAdmin(
        string $search,
        ?PublishingStatus $status,
        ?string $category = null,
        ?string $tag = null,
    ): int {
        return (int) $this->createAdminQueryBuilder($search, $status, $category, $tag)
            ->select('COUNT(DISTINCT article.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function createAdminQueryBuilder(
        string $search,
        ?PublishingStatus $status,
        ?string $category,
        ?string $tag,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('article');

        if ('' !== $search) {
            $queryBuilder
                ->andWhere('(article.title LIKE :search OR article.category LIKE :search)')
                ->setParameter('search', '%'.$search.'%');
        }
        if (null !== $status) {
            $queryBuilder->andWhere('article.status = :status')->setParameter('status', $status);
        }
        if (null !== $category && '' !== $category) {
            $queryBuilder->andWhere('article.category = :category')->setParameter('category', $category);
        }
        if (null !== $tag && '' !== $tag) {
            $queryBuilder
                ->innerJoin('article.tags', 'contentTag')
                ->andWhere('contentTag.normalizedName = :tag')
                ->setParameter('tag', mb_strtolower(trim($tag)));
        }

        return $queryBuilder;
    }

    /** @return list<NewsArticle> */
    public function findPublished(
        string $search = '',
        int $limit = 24,
        int $offset = 0,
        ?string $category = null,
        ?string $tag = null,
        bool $includeMemberOnlyBodyMatches = true,
        bool $excludeMembersOnly = false,
    ): array {
        $queryBuilder = $this->createQueryBuilder('article')
            ->andWhere('article.status = :status')
            ->andWhere('article.publishedAt IS NOT NULL')
            ->andWhere('article.publishedAt <= :now')
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        if ('' !== $search) {
            $bodyPredicate = $includeMemberOnlyBodyMatches
                ? 'article.body LIKE :search'
                : '(article.membersOnly = false AND article.body LIKE :search)';
            $queryBuilder
                ->andWhere('(article.title LIKE :search OR article.category LIKE :search OR article.summary LIKE :search OR '.$bodyPredicate.')')
                ->setParameter('search', '%'.$search.'%');
        }

        if ($excludeMembersOnly) {
            $queryBuilder->andWhere('article.membersOnly = false');
        }

        if (null !== $category && '' !== $category) {
            $queryBuilder
                ->andWhere('article.category = :category')
                ->setParameter('category', $category);
        }

        if (null !== $tag && '' !== $tag) {
            $queryBuilder
                ->innerJoin('article.tags', 'contentTag')
                ->andWhere('contentTag.normalizedName = :tag')
                ->setParameter('tag', mb_strtolower(trim($tag)));
        }

        /** @var list<NewsArticle> $articles */
        $articles = $queryBuilder
            ->orderBy('article.publishedAt', \SortDirection::Descending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $articles;
    }

    public function countPublicPublished(): int
    {
        return (int) $this->createQueryBuilder('article')
            ->select('COUNT(article.id)')
            ->andWhere('article.status = :status')
            ->andWhere('article.publishedAt IS NOT NULL')
            ->andWhere('article.publishedAt <= :now')
            ->andWhere('article.membersOnly = false')
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param list<string> $ids
     *
     * @return list<NewsArticle>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<NewsArticle> $items */
        $items = $this->createQueryBuilder('article')
            ->andWhere('article.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('article.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $items;
    }

    /** @param list<string> $ids
     *
     * @return list<NewsArticle>
     */
    public function findByIdsForUpdate(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<NewsArticle> $items */
        $items = $this->createQueryBuilder('article')
            ->andWhere('article.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('article.id', \SortDirection::Ascending)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $items;
    }

    public function findByIdForUpdate(string $id): ?NewsArticle
    {
        $article = $this->createQueryBuilder('article')
            ->andWhere('article.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $article instanceof NewsArticle ? $article : null;
    }

    public function findPublishedBySlug(string $slug): ?NewsArticle
    {
        $article = $this->createQueryBuilder('article')
            ->andWhere('article.slug = :slug')
            ->andWhere('article.status = :status')
            ->andWhere('article.publishedAt IS NOT NULL')
            ->andWhere('article.publishedAt <= :now')
            ->setParameter('slug', $slug)
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->getQuery()
            ->getOneOrNullResult();

        return $article instanceof NewsArticle ? $article : null;
    }

    public function findPublishedById(string $id): ?NewsArticle
    {
        $article = $this->createQueryBuilder('article')
            ->andWhere('article.id = :id')
            ->andWhere('article.status = :status')
            ->andWhere('article.publishedAt IS NOT NULL')
            ->andWhere('article.publishedAt <= :now')
            ->setParameter('id', $id)
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->getQuery()
            ->getOneOrNullResult();

        return $article instanceof NewsArticle ? $article : null;
    }

    public function findOneBySlug(string $slug): ?NewsArticle
    {
        $article = $this->findOneBy(['slug' => $slug]);

        return $article instanceof NewsArticle ? $article : null;
    }
}
