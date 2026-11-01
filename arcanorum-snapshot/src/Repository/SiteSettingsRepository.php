<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SiteSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SiteSettings>
 */
final class SiteSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteSettings::class);
    }

    public function findCurrent(): ?SiteSettings
    {
        $settings = $this->find(SiteSettings::SINGLETON_ID);

        return $settings instanceof SiteSettings ? $settings : null;
    }

    public function findCurrentForUpdate(): ?SiteSettings
    {
        $settings = $this->createQueryBuilder('settings')
            ->andWhere('settings.id = :id')
            ->setParameter('id', SiteSettings::SINGLETON_ID)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        if ($settings instanceof SiteSettings) {
            $this->getEntityManager()->refresh($settings, LockMode::PESSIMISTIC_WRITE);
        }

        return $settings instanceof SiteSettings ? $settings : null;
    }
}
