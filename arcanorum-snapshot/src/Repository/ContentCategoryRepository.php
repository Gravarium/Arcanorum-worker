<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\PublishingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentCategory>
 */
final class ContentCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentCategory::class);
    }

    /** @return list<ContentCategory> */
    public function findForAdmin(?ContentCategoryType $contentType = null): array
    {
        $queryBuilder = $this->createQueryBuilder('category');

        if (null !== $contentType) {
            $queryBuilder
                ->andWhere('category.contentType = :contentType')
                ->setParameter('contentType', $contentType);
        }

        /** @var list<ContentCategory> $categories */
        $categories = $queryBuilder
            ->leftJoin('category.parent', 'parent')
            ->addSelect('parent')
            ->orderBy('category.contentType', \SortDirection::Ascending)
            ->addOrderBy('category.sortOrder', \SortDirection::Ascending)
            ->addOrderBy('category.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        /** @var array<string, list<ContentCategory>> $children */
        $children = [];
        /** @var list<ContentCategory> $roots */
        $roots = [];
        foreach ($categories as $category) {
            $parent = $category->getParent();
            if (null === $parent) {
                $roots[] = $category;
                continue;
            }

            $children[$parent->getId()][] = $category;
        }

        /** @var list<ContentCategory> $ordered */
        $ordered = [];
        /** @var array<string, true> $visited */
        $visited = [];
        foreach ($roots as $root) {
            self::appendCategoryTree($root, $children, $visited, $ordered);
        }
        foreach ($categories as $category) {
            self::appendCategoryTree($category, $children, $visited, $ordered);
        }

        return $ordered;
    }

    public function findForTypeAndName(ContentCategoryType $contentType, string $name): ?ContentCategory
    {
        $category = $this->findOneBy([
            'contentType' => $contentType,
            'normalizedName' => mb_strtolower(trim($name)),
        ]);

        return $category instanceof ContentCategory ? $category : null;
    }

    public function findForTypeAndSlug(ContentCategoryType $contentType, string $slug): ?ContentCategory
    {
        $category = $this->findOneBy([
            'contentType' => $contentType,
            'slug' => $slug,
        ]);

        return $category instanceof ContentCategory ? $category : null;
    }

    public function findForUpdate(string $id): ?ContentCategory
    {
        $category = $this->createQueryBuilder('category')
            ->andWhere('category.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        if ($category instanceof ContentCategory) {
            $this->getEntityManager()->refresh($category, LockMode::PESSIMISTIC_WRITE);
        }

        return $category instanceof ContentCategory ? $category : null;
    }

    /** @return list<ContentCategory> */
    public function findVideoRootCategories(): array
    {
        /** @var list<ContentCategory> $categories */
        $categories = $this->createQueryBuilder('category')
            ->andWhere('category.contentType = :contentType')
            ->andWhere('category.parent IS NULL')
            ->setParameter('contentType', ContentCategoryType::Video)
            ->orderBy('category.sortOrder', \SortDirection::Ascending)
            ->addOrderBy('category.name', \SortDirection::Ascending)
            ->addOrderBy('category.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $categories;
    }

    /** @return list<ContentCategory> */
    public function findVideoChildren(ContentCategory $parent): array
    {
        if (ContentCategoryType::Video !== $parent->getContentType()) {
            return [];
        }

        return $this->findChildren($parent);
    }

    /** @return list<ContentCategory> */
    public function findNewsChildren(ContentCategory $parent): array
    {
        if (ContentCategoryType::News !== $parent->getContentType()) {
            return [];
        }

        return $this->findChildren($parent);
    }

    /** @return list<ContentCategory> */
    private function findChildren(ContentCategory $parent): array
    {
        /** @var list<ContentCategory> $categories */
        $categories = $this->createQueryBuilder('category')
            ->andWhere('category.contentType = :contentType')
            ->andWhere('category.parent = :parent')
            ->setParameter('contentType', $parent->getContentType())
            ->setParameter('parent', $parent)
            ->orderBy('category.sortOrder', \SortDirection::Ascending)
            ->addOrderBy('category.name', \SortDirection::Ascending)
            ->addOrderBy('category.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $categories;
    }

    /** @return list<ContentCategory> */
    public function findFeaturedVideoCategories(int $limit = 10): array
    {
        $limit = max(1, min(10, $limit));
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT category.id FROM content_categories AS category '
            .'WHERE category.content_type = :content_type '
            .'AND category.is_featured = TRUE '
            .'AND EXISTS (SELECT 1 FROM videos AS video '
            .'WHERE LOWER(BTRIM(video.category)) = category.normalized_name '
            .'AND video.status = :status '
            .'AND video.published_at IS NOT NULL '
            .'AND video.published_at <= :now) '
            .'ORDER BY category.sort_order ASC, category.name ASC, category.id ASC LIMIT '.$limit,
            [
                'content_type' => ContentCategoryType::Video->value,
                'status' => PublishingStatus::Published->value,
                'now' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
        $categories = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }
            $category = $this->find($id);
            if ($category instanceof ContentCategory) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    /** @return list<ContentCategory> */
    public function findPotentialParents(ContentCategoryType $contentType, ?ContentCategory $category = null): array
    {
        $categories = $this->findForAdmin($contentType);
        $parents = [];
        foreach ($categories as $candidate) {
            if (null === $category
                ? $candidate->getDepth() < ContentCategory::MAX_DEPTH
                : self::canMoveToParentInCategories($category, $candidate, $categories)
            ) {
                $parents[] = $candidate;
            }
        }

        return $parents;
    }

    public function canMoveToParent(ContentCategory $category, ?ContentCategory $parent): bool
    {
        return self::canMoveToParentInCategories(
            $category,
            $parent,
            $this->findForAdmin($category->getContentType()),
        );
    }

    /** @param list<ContentCategory> $categories */
    private static function canMoveToParentInCategories(ContentCategory $category, ?ContentCategory $parent, array $categories): bool
    {
        if (!$category->canUseAsParent($parent)) {
            return false;
        }

        /** @var array<string, list<ContentCategory>> $children */
        $children = [];
        foreach ($categories as $candidate) {
            $candidateParent = $candidate->getParent();
            if (null !== $candidateParent) {
                $children[$candidateParent->getId()][] = $candidate;
            }
        }

        $path = [];
        $subtreeDepth = self::maximumChildDepth($category->getId(), $children, $path);
        $newDepth = null === $parent ? 0 : $parent->getDepth() + 1;

        return $newDepth + $subtreeDepth <= ContentCategory::MAX_DEPTH;
    }

    public function countChildren(ContentCategory $category): int
    {
        $count = $this->createQueryBuilder('child')
            ->select('COUNT(child.id)')
            ->andWhere('child.parent = :parent')
            ->setParameter('parent', $category)
            ->getQuery()
            ->getSingleScalarResult();

        if (is_int($count)) {
            return max(0, $count);
        }

        if (is_string($count) && ctype_digit($count)) {
            return (int) $count;
        }

        throw new \UnexpectedValueException('Die Anzahl der Unterkategorien wurde nicht als Zahl zurückgegeben.');
    }

    public function countUsage(ContentCategory $category): int
    {
        $table = self::tableFor($category->getContentType());
        $count = $this->getEntityManager()->getConnection()->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE LOWER(BTRIM(category)) = :normalized_name', $table),
            ['normalized_name' => $category->getNormalizedName()],
        );
        if (is_int($count)) {
            return max(0, $count);
        }

        if (is_string($count) && ctype_digit($count)) {
            return (int) $count;
        }

        throw new \UnexpectedValueException('Die Anzahl der verwendeten Kategorien wurde nicht als Zahl zurückgegeben.');
    }

    public function renameAssignedContent(ContentCategory $category, string $newName): void
    {
        $table = self::tableFor($category->getContentType());
        $this->getEntityManager()->getConnection()->executeStatement(
            sprintf('UPDATE %s SET category = :new_name WHERE LOWER(BTRIM(category)) = :normalized_name', $table),
            [
                'new_name' => trim($newName),
                'normalized_name' => $category->getNormalizedName(),
            ],
        );
    }

    private static function tableFor(ContentCategoryType $contentType): string
    {
        return match ($contentType) {
            ContentCategoryType::News => 'news_articles',
            ContentCategoryType::Video => 'videos',
        };
    }

    /**
     * @param array<string, list<ContentCategory>> $children
     * @param array<string, true>                  $visited
     * @param list<ContentCategory>                $ordered
     */
    private static function appendCategoryTree(ContentCategory $category, array $children, array &$visited, array &$ordered): void
    {
        $id = $category->getId();
        if (isset($visited[$id])) {
            return;
        }

        $visited[$id] = true;
        $ordered[] = $category;
        foreach ($children[$id] ?? [] as $child) {
            self::appendCategoryTree($child, $children, $visited, $ordered);
        }
    }

    /**
     * @param array<string, list<ContentCategory>> $children
     * @param array<string, true>                  $path
     */
    private static function maximumChildDepth(string $id, array $children, array &$path): int
    {
        if (isset($path[$id]) || count($path) > ContentCategory::MAX_DEPTH) {
            return ContentCategory::MAX_DEPTH + 1;
        }

        $path[$id] = true;
        $maximum = 0;
        foreach ($children[$id] ?? [] as $child) {
            $maximum = max($maximum, 1 + self::maximumChildDepth($child->getId(), $children, $path));
        }
        unset($path[$id]);

        return $maximum;
    }
}
