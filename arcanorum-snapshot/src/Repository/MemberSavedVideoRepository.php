<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MemberAccount;
use App\Entity\MemberSavedVideo;
use App\Entity\MemberVideoCollection;
use App\Entity\PublishingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemberSavedVideo>
 */
final class MemberSavedVideoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberSavedVideo::class);
    }

    /** @return list<MemberSavedVideo> */
    public function findVisibleForMember(MemberAccount $member, MemberVideoCollection $collection, int $limit, int $offset): array
    {
        /** @var list<MemberSavedVideo> $items */
        $items = $this->createQueryBuilder('saved')
            ->innerJoin('saved.video', 'video')
            ->addSelect('video')
            ->andWhere('saved.member = :member')
            ->andWhere('saved.collection = :collection')
            ->andWhere('video.status = :status')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->setParameter('member', $member)
            ->setParameter('collection', $collection)
            ->setParameter('status', PublishingStatus::Published)
            ->orderBy('saved.createdAt', \SortDirection::Descending)
            ->addOrderBy('saved.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function contains(MemberAccount $member, string $videoId, MemberVideoCollection $collection): bool
    {
        return 1 === (int) $this->createQueryBuilder('saved')
            ->select('COUNT(saved.id)')
            ->andWhere('saved.member = :member')
            ->andWhere('IDENTITY(saved.video) = :video_id')
            ->andWhere('saved.collection = :collection')
            ->setParameter('member', $member)
            ->setParameter('video_id', $videoId)
            ->setParameter('collection', $collection)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function add(MemberAccount $member, string $videoId, MemberVideoCollection $collection): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO member_saved_videos (id, member_id, video_id, collection, created_at) '
                .'SELECT :id, :member_id, video.id, :collection, :created_at FROM videos video '
                ."WHERE video.id = :video_id AND video.status = 'published' AND video.published_at IS NOT NULL "
                .'ON CONFLICT (member_id, video_id, collection) DO NOTHING',
            [
                'id' => bin2hex(random_bytes(16)),
                'member_id' => $member->getId(),
                'video_id' => $videoId,
                'collection' => $collection->value,
                'created_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ],
            [
                'id' => Types::STRING,
                'member_id' => Types::STRING,
                'video_id' => Types::STRING,
                'collection' => Types::STRING,
                'created_at' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }

    public function remove(MemberAccount $member, string $videoId, MemberVideoCollection $collection): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM member_saved_videos WHERE member_id = :member_id AND video_id = :video_id AND collection = :collection',
            ['member_id' => $member->getId(), 'video_id' => $videoId, 'collection' => $collection->value],
            ['member_id' => Types::STRING, 'video_id' => Types::STRING, 'collection' => Types::STRING],
        );
    }
}
