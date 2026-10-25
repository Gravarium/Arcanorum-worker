<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublishingStatus;
use App\Entity\StaticPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    /** @return list<StaticPage> */
    public function findPublished(int $limit = 100, int $offset = 0): array
    {
        /** @var list<StaticPage> $pages */
        $pages = $this->createQueryBuilder('page')
            ->andWhere('page.status = :status')
            ->andWhere('page.publishedAt IS NOT NULL')
            ->setParameter('status', PublishingStatus::Published)
            ->orderBy('page.title', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $pages;
    }

    public function findPublishedBySlug(string $slug): ?StaticPage
    {
        $page = $this->findOneBy([
            'slug' => $slug,
            'status' => PublishingStatus::Published,
        ]);

        return $page instanceof StaticPage && null !== $page->getPublishedAt()
            ? $page
            : null;
    }

    public function findOneBySlug(string $slug): ?StaticPage
    {
        $page = $this->findOneBy(['slug' => $slug]);

        return $page instanceof StaticPage ? $page : null;
    }
}
