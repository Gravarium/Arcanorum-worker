<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Playlist;
use App\Entity\PlaylistItem;
use App\Entity\PublishingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlaylistItem>
 */
final class PlaylistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlaylistItem::class);
    }

    /** @return list<PlaylistItem> */
    public function findPublishedForPlaylist(Playlist $playlist): array
    {
        /** @var list<PlaylistItem> $items */
        $items = $this->createQueryBuilder('playlistItem')
            ->innerJoin('playlistItem.video', 'listedVideo')
            ->addSelect('listedVideo')
            ->andWhere('playlistItem.playlist = :playlist')
            ->andWhere('listedVideo.status = :status')
            ->andWhere('listedVideo.publishedAt IS NOT NULL')
            ->setParameter('playlist', $playlist)
            ->setParameter('status', PublishingStatus::Published)
            ->orderBy('playlistItem.position', \SortDirection::Ascending)
            ->addOrderBy('playlistItem.addedAt', \SortDirection::Ascending)
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        return $items;
    }
}
