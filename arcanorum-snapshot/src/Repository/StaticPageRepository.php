<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublishingStatus;
use App\Entity\StaticPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaticPage>
 */
final class StaticPageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaticPage::class);
    }

    /** @return list<StaticPage> */
    public function findForAdmin(string $search, ?PublishingStatus $status): array
    {
        $queryBuilder = $this->createQueryBuilder('page');

        if ('' !== $search) {
            $queryBuilder
                ->andWhere('(page.title LIKE :search OR page.slug LIKE :search)')
                ->setParameter('search', '%'.$search.'%');
        }

        if (null !== $status) {
            $queryBuilder->andWhere('page.status = :status')->setParameter('status', $status);
        }

        /** @var list<StaticPage> $pages */
        $pages = $queryBuilder
            ->orderBy('page.updatedAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();

        return $pages;
    }

    public function countForAdmin(): int
    {
        return (int) $this->createQueryBuilder('page')
            ->select('COUNT(page.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<StaticPage> */
    public function findPublished(int $limit = 100, int $offset = 0, bool $menuOnly = false): array
    {
        $queryBuilder = $this->createQueryBuilder('page')
            ->andWhere('page.status = :status')
            ->andWhere('page.publishedAt IS NOT NULL')
            ->andWhere('page.publishedAt <= :now')
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        if ($menuOnly) {
            $queryBuilder->andWhere('page.showInMenu = true');
        }

        /** @var list<StaticPage> $pages */
        $pages = $queryBuilder
            ->orderBy('page.title', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $pages;
    }

    /** @return list<StaticPage> */
    public function findPublishedForMenu(): array
    {
        return $this->findPublished(limit: 100, menuOnly: true);
    }

    public function findPublishedBySlug(string $slug): ?StaticPage
    {
        $page = $this->createQueryBuilder('page')
            ->andWhere('page.slug = :slug')
            ->andWhere('page.status = :status')
            ->andWhere('page.publishedAt IS NOT NULL')
            ->andWhere('page.publishedAt <= :now')
            ->setParameter('slug', $slug)
            ->setParameter('status', PublishingStatus::Published)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->getQuery()
            ->getOneOrNullResult();

        return $page instanceof StaticPage ? $page : null;
    }

    public function findOneBySlug(string $slug): ?StaticPage
    {
        $page = $this->findOneBy(['slug' => $slug]);

        return $page instanceof StaticPage ? $page : null;
    }

    public function findByIdForUpdate(string $id): ?StaticPage
    {
        $page = $this->createQueryBuilder('page')
            ->andWhere('page.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $page instanceof StaticPage ? $page : null;
    }
}
