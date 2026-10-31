<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MemberAccount;
use App\Entity\MemberVideoHistory;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemberVideoHistory>
 */
final class MemberVideoHistoryRepository extends ServiceEntityRepository
{
    private const int MAX_HISTORY = 500;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberVideoHistory::class);
    }

    public function recordPublicVisit(MemberAccount $member, Video $video): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $connection->transactional(function () use ($connection, $member, $video, $now): void {
            $connection->executeQuery(
                'SELECT id FROM member_accounts WHERE id = :member_id FOR UPDATE',
                ['member_id' => $member->getId()],
                ['member_id' => Types::STRING],
            )->free();
            $connection->executeStatement(
                'INSERT INTO member_video_history (id, member_id, video_id, last_watched_at) '
                    .'SELECT :id, :member_id, video.id, :watched_at FROM videos video '
                    ."WHERE video.id = :video_id AND video.status = 'published' AND video.published_at IS NOT NULL "
                    .'ON CONFLICT (member_id, video_id) DO UPDATE '
                    .'SET last_watched_at = EXCLUDED.last_watched_at '
                    .'WHERE member_video_history.last_watched_at < EXCLUDED.last_watched_at - INTERVAL \'1 minute\'',
                [
                    'id' => bin2hex(random_bytes(16)),
                    'member_id' => $member->getId(),
                    'video_id' => $video->getId(),
                    'watched_at' => $now,
                ],
                [
                    'id' => Types::STRING,
                    'member_id' => Types::STRING,
                    'video_id' => Types::STRING,
                    'watched_at' => Types::DATETIME_IMMUTABLE,
                ],
            );
            $connection->executeStatement(
                'DELETE FROM member_video_history WHERE member_id = :member_id AND video_id IN ('
                    .'SELECT video_id FROM member_video_history WHERE member_id = :member_id '
                    .'ORDER BY last_watched_at DESC, video_id ASC OFFSET :max_history)',
                ['member_id' => $member->getId(), 'max_history' => self::MAX_HISTORY],
                ['member_id' => Types::STRING, 'max_history' => Types::INTEGER],
            );
        });
    }

    /** @return list<MemberVideoHistory> */
    public function findVisibleForMember(MemberAccount $member, int $limit, int $offset): array
    {
        /** @var list<MemberVideoHistory> $items */
        $items = $this->createQueryBuilder('history')
            ->innerJoin('history.video', 'video')
            ->addSelect('video')
            ->andWhere('history.member = :member')
            ->andWhere('video.status = :status')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->setParameter('member', $member)
            ->setParameter('status', PublishingStatus::Published)
            ->orderBy('history.lastWatchedAt', \SortDirection::Descending)
            ->addOrderBy('video.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function remove(MemberAccount $member, string $videoId): void
    {
        $this->getEntityManager()->getConnection()->delete('member_video_history', [
            'member_id' => $member->getId(),
            'video_id' => $videoId,
        ]);
    }

    public function clear(MemberAccount $member): void
    {
        $this->getEntityManager()->getConnection()->delete('member_video_history', [
            'member_id' => $member->getId(),
        ]);
    }
}
