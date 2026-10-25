<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MemberAccount;
use App\Entity\Playlist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Playlist>
 */
final class PlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Playlist::class);
    }

    /** @return list<Playlist> */
    public function findOwnedBy(MemberAccount $owner): array
    {
        /** @var list<Playlist> $playlists */
        $playlists = $this->createQueryBuilder('playlist')
            ->andWhere('playlist.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('playlist.updatedAt', \SortDirection::Descending)
            ->addOrderBy('playlist.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $playlists;
    }

    /** @return list<Playlist> */
    public function findRecentForOwner(MemberAccount $owner, int $limit): array
    {
        /** @var list<Playlist> $playlists */
        $playlists = $this->createQueryBuilder('playlist')
            ->andWhere('playlist.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('playlist.updatedAt', \SortDirection::Descending)
            ->addOrderBy('playlist.id', \SortDirection::Ascending)
            ->setMaxResults(max(1, min(10, $limit)))
            ->getQuery()
            ->getResult();

        return $playlists;
    }

    /** @return list<Playlist> */
    public function findPublicForOwner(MemberAccount $owner): array
    {
        /** @var list<Playlist> $playlists */
        $playlists = $this->createQueryBuilder('playlist')
            ->andWhere('playlist.owner = :owner')
            ->andWhere('playlist.visibilityPublic = :public')
            ->setParameter('owner', $owner)
            ->setParameter('public', true)
            ->orderBy('playlist.updatedAt', \SortDirection::Descending)
            ->addOrderBy('playlist.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $playlists;
    }

    public function findVisibleById(string $id): ?Playlist
    {
        $playlist = $this->createQueryBuilder('playlist')
            ->innerJoin('playlist.owner', 'account')
            ->andWhere('playlist.id = :id')
            ->andWhere('playlist.visibilityPublic = :public')
            ->andWhere('account.publicProfile = :profile_public')
            ->andWhere('account.active = :active')
            ->setParameter('id', $id)
            ->setParameter('public', true)
            ->setParameter('profile_public', true)
            ->setParameter('active', true)
            ->getQuery()
            ->getOneOrNullResult();

        return $playlist instanceof Playlist ? $playlist : null;
    }
}
