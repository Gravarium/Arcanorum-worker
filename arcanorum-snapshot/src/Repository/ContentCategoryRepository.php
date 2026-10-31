<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
            ->orderBy('category.contentType', \SortDirection::Ascending)
            ->addOrderBy('category.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $categories;
    }

    public function findForTypeAndName(ContentCategoryType $contentType, string $name): ?ContentCategory
    {
        $category = $this->findOneBy([
            'contentType' => $contentType,
            'normalizedName' => mb_strtolower(trim($name)),
        ]);

        return $category instanceof ContentCategory ? $category : null;
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

        return is_string($count) && ctype_digit($count) ? (int) $count : 0;
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
}
