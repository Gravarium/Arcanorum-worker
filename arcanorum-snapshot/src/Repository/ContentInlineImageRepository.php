<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentInlineImage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContentInlineImage> */
final class ContentInlineImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentInlineImage::class);
    }

    /** @param list<string> $ids */
    public function allBelongToNewsArticle(string $articleId, array $ids): bool
    {
        return $this->allBelongTo('news_article_id', $articleId, $ids);
    }

    /** @param list<string> $ids */
    public function allBelongToStaticPage(string $pageId, array $ids): bool
    {
        return $this->allBelongTo('static_page_id', $pageId, $ids);
    }

    /** @param list<string> $ids */
    public function allBelongToCategory(string $categoryId, array $ids): bool
    {
        return $this->allBelongTo('category_id', $categoryId, $ids);
    }

    /** @param list<string> $ids
     * @return list<string>
     */
    public function ownedIdsForCategory(string $categoryId, array $ids): array
    {
        if ([] === $ids || count($ids) > 20) {
            return [];
        }
        foreach ($ids as $id) {
            if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $id)) {
                return [];
            }
        }

        /** @var list<mixed> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT id FROM content_inline_images WHERE category_id = ? AND id IN (?) ORDER BY id',
            [$categoryId, $ids],
            [\Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::STRING],
        )->fetchFirstColumn();

        $ownedIds = [];
        foreach ($rows as $id) {
            if (is_string($id)) {
                $ownedIds[] = $id;
            }
        }

        return $ownedIds;
    }

    /** @return array{count: int, bytes: int} */
    public function usageForNewsArticle(string $articleId): array
    {
        return $this->usageFor('news_article_id', $articleId);
    }

    /** @return array{count: int, bytes: int} */
    public function usageForStaticPage(string $pageId): array
    {
        return $this->usageFor('static_page_id', $pageId);
    }

    /** @return array{count: int, bytes: int} */
    public function usageForCategory(string $categoryId): array
    {
        return $this->usageFor('category_id', $categoryId);
    }

    /** @return list<ContentInlineImage> */
    public function findForNewsArticle(string $articleId): array
    {
        /** @var list<ContentInlineImage> $images */
        $images = $this->createQueryBuilder('image')
            ->andWhere('IDENTITY(image.newsArticle) = :articleId')
            ->setParameter('articleId', $articleId)
            ->orderBy('image.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $images;
    }

    /** @return list<ContentInlineImage> */
    public function findForStaticPage(string $pageId): array
    {
        /** @var list<ContentInlineImage> $images */
        $images = $this->createQueryBuilder('image')
            ->andWhere('IDENTITY(image.staticPage) = :pageId')
            ->setParameter('pageId', $pageId)
            ->orderBy('image.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $images;
    }

    /** @return list<ContentInlineImage> */
    public function findForCategory(string $categoryId): array
    {
        /** @var list<ContentInlineImage> $images */
        $images = $this->createQueryBuilder('image')
            ->andWhere('IDENTITY(image.category) = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->orderBy('image.createdAt', 'ASC')
            ->addOrderBy('image.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $images;
    }

    /** @param list<string> $articleIds
     * @return list<string>
     */
    public function findIdsForNewsArticles(array $articleIds): array
    {
        if ([] === $articleIds) {
            return [];
        }

        /** @var list<array{id: mixed}> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT id FROM content_inline_images WHERE news_article_id IN (?) ORDER BY id',
            [$articleIds],
            [ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $ids = [];
        foreach ($rows as $row) {
            if (is_string($row['id'] ?? null)) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    public function isReferencedInNewsHistory(string $articleId, string $imageId): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT EXISTS (
                SELECT 1 FROM news_article_revisions
                WHERE article_id = :article_id
                  AND POSITION('/content-inline-images/' || :image_id IN body) > 0
            )",
            ['article_id' => $articleId, 'image_id' => $imageId],
        );
    }

    /** @param list<string> $ids */
    private function allBelongTo(string $ownerColumn, string $ownerId, array $ids): bool
    {
        if ([] === $ids) {
            return true;
        }
        if (count($ids) > 20) {
            return false;
        }

        foreach ($ids as $id) {
            if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $id)) {
                return false;
            }
        }

        $count = $this->getEntityManager()->getConnection()->executeQuery(
            sprintf('SELECT COUNT(*) FROM content_inline_images WHERE %s = ? AND id IN (?)', $ownerColumn),
            [$ownerId, $ids],
            [\Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::STRING],
        )->fetchOne();
        $parsedCount = filter_var($count, FILTER_VALIDATE_INT);

        return is_int($parsedCount) && $parsedCount === count($ids);
    }

    /** @return array{count: int, bytes: int} */
    private function usageFor(string $ownerColumn, string $ownerId): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            sprintf('SELECT COUNT(*) AS image_count, COALESCE(SUM(size), 0) AS total_bytes FROM content_inline_images WHERE %s = :owner_id', $ownerColumn),
            ['owner_id' => $ownerId],
        );
        if (!is_array($row)) {
            throw new \RuntimeException('Der Inline-Bildspeicher konnte nicht gezählt werden.');
        }

        $count = filter_var($row['image_count'] ?? null, FILTER_VALIDATE_INT);
        $bytes = filter_var($row['total_bytes'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($count) || $count < 0 || !is_int($bytes) || $bytes < 0) {
            throw new \RuntimeException('Der Inline-Bildspeicher lieferte ungültige Zählerwerte.');
        }

        return ['count' => $count, 'bytes' => $bytes];
    }
}
