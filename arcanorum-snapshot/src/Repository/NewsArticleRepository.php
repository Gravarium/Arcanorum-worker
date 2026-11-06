<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommentContentType;
use App\Entity\CommentStatus;
use App\Entity\ContentComment;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Module\News\NewsArchiveSort;
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
        NewsArchiveSort $sort = NewsArchiveSort::Newest,
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

        if (NewsArchiveSort::Comments === $sort) {
            $approvedComments = $this->getEntityManager()->createQueryBuilder()
                ->select('COUNT(comment.id)')
                ->from(ContentComment::class, 'comment')
                ->where('comment.contentType = :newsContentType')
                ->andWhere('comment.contentId = article.id')
                ->andWhere('comment.status = :approvedCommentStatus')
                ->andWhere('article.commentsEnabled = true');

            $queryBuilder
                ->addSelect('('.$approvedComments->getDQL().') AS HIDDEN approvedCommentCount')
                ->setParameter('newsContentType', CommentContentType::News)
                ->setParameter('approvedCommentStatus', CommentStatus::Approved)
                ->orderBy('approvedCommentCount', \SortDirection::Descending);
        } elseif (NewsArchiveSort::Popular === $sort) {
            $queryBuilder->orderBy('article.viewCount', \SortDirection::Descending);
        } else {
            $queryBuilder->orderBy('article.publishedAt', \SortDirection::Descending);
        }

        /** @var list<NewsArticle> $articles */
        $articles = $queryBuilder
            ->addOrderBy('article.publishedAt', \SortDirection::Descending)
            ->addOrderBy('article.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $articles;
    }

    public function recordPublicView(string $id): bool
    {
        $updated = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE news_articles SET view_count = view_count + 1 '
                .'WHERE id = :id AND status = :status AND published_at IS NOT NULL '
                .'AND published_at <= CURRENT_TIMESTAMP AND members_only = FALSE '
                .'AND view_count < 2147483647',
            ['id' => $id, 'status' => PublishingStatus::Published->value],
        );

        return 1 === $updated;
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

    /** @return list<NewsArticle> */
    public function findRelatedPublished(NewsArticle $source, int $limit = 3): array
    {
        if ($limit <= 0) {
            return [];
        }

        $tagIds = [];
        foreach ($source->getTags() as $tag) {
            $tagIds[] = $tag->getId();
        }
        $tagIds = array_values(array_unique($tagIds));
        $category = mb_strtolower(trim($source->getCategory() ?? ''));
        if ([] === $tagIds && '' === $category) {
            return [];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $queryBuilder = $this->createQueryBuilder('related')
            ->andWhere('related.status = :status')
            ->andWhere('related.publishedAt IS NOT NULL')
            ->andWhere('related.publishedAt <= :now')
            ->andWhere('related.membersOnly = false')
            ->andWhere('related.id <> :sourceId')
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', $now)
            ->setParameter('sourceId', $source->getId());

        $conditions = [];
        if ('' !== $category) {
            $conditions[] = 'LOWER(TRIM(related.category)) = :category';
            $queryBuilder->setParameter('category', $category);
        }
        if ([] !== $tagIds) {
            $taggedArticles = $this->getEntityManager()->createQueryBuilder()
                ->select('taggedArticle.id')
                ->from(NewsArticle::class, 'taggedArticle')
                ->innerJoin('taggedArticle.tags', 'sharedTag')
                ->andWhere('sharedTag.id IN (:tagIds)');
            $conditions[] = 'related.id IN ('.$taggedArticles->getDQL().')';
            $queryBuilder->setParameter('tagIds', $tagIds);
        }
        $queryBuilder->andWhere('('.implode(' OR ', $conditions).')');

        /** @var list<NewsArticle> $articles */
        $articles = $queryBuilder
            ->orderBy('related.publishedAt', \SortDirection::Descending)
            ->addOrderBy('related.id', \SortDirection::Ascending)
            ->setMaxResults(min(3, $limit))
            ->getQuery()
            ->getResult();

        return $articles;
    }

    public function findOneBySlug(string $slug): ?NewsArticle
    {
        $article = $this->findOneBy(['slug' => $slug]);

        return $article instanceof NewsArticle ? $article : null;
    }
}
