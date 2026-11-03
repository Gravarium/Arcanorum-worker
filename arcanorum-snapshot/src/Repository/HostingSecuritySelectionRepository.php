<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HostingSecuritySelection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HostingSecuritySelection>
 */
final class HostingSecuritySelectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HostingSecuritySelection::class);
    }

    public function findCurrent(): ?HostingSecuritySelection
    {
        $selection = $this->find(HostingSecuritySelection::SINGLETON_ID);

        if ($selection instanceof HostingSecuritySelection) {
            $this->getEntityManager()->refresh($selection);
        }

        return $selection instanceof HostingSecuritySelection ? $selection : null;
    }

    public function findCurrentForUpdate(): ?HostingSecuritySelection
    {
        $selection = $this->createQueryBuilder('selection')
            ->andWhere('selection.id = :id')
            ->setParameter('id', HostingSecuritySelection::SINGLETON_ID)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        if ($selection instanceof HostingSecuritySelection) {
            $this->getEntityManager()->refresh($selection, LockMode::PESSIMISTIC_WRITE);
        }

        return $selection instanceof HostingSecuritySelection ? $selection : null;
    }
}
