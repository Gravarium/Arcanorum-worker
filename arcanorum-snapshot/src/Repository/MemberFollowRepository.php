<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MemberAccount;
use App\Entity\MemberFollow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemberFollow>
 */
final class MemberFollowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberFollow::class);
    }

    public function hasFollowed(MemberAccount $follower, MemberAccount $followed): bool
    {
        return 1 === (int) $this->createQueryBuilder('follow')
            ->select('COUNT(follow.id)')
            ->andWhere('follow.follower = :follower')
            ->andWhere('follow.followed = :followed')
            ->setParameter('follower', $follower)
            ->setParameter('followed', $followed)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Insert is atomic and idempotent. Eligibility is checked again inside the
     * statement so a profile made private between the page request and POST
     * cannot be followed.
     */
    public function follow(MemberAccount $follower, MemberAccount $followed, \DateTimeImmutable $now): bool
    {
        if ($follower->getId() === $followed->getId()) {
            return false;
        }

        $affectedRows = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO member_follows (id, follower_id, followed_id, created_at) '
            .'SELECT :id, account.id, target.id, :created_at '
            .'FROM member_accounts account CROSS JOIN member_accounts target '
            .'WHERE account.id = :follower_id AND target.id = :followed_id '
            .'AND account.is_active = TRUE AND account.email_verified = TRUE '
            .'AND target.is_active = TRUE AND target.email_verified = TRUE AND target.public_profile = TRUE '
            .'ON CONFLICT (follower_id, followed_id) DO NOTHING',
            [
                'id' => bin2hex(random_bytes(16)),
                'created_at' => $now,
                'follower_id' => $follower->getId(),
                'followed_id' => $followed->getId(),
            ],
            [
                'id' => Types::STRING,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'follower_id' => Types::STRING,
                'followed_id' => Types::STRING,
            ],
        );

        return 1 === $affectedRows;
    }

    public function unfollow(MemberAccount $follower, MemberAccount $followed): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM member_follows WHERE follower_id = :follower_id AND followed_id = :followed_id',
            ['follower_id' => $follower->getId(), 'followed_id' => $followed->getId()],
            ['follower_id' => Types::STRING, 'followed_id' => Types::STRING],
        );
    }

    /** @return list<MemberAccount> */
    public function findFollowing(MemberAccount $follower, int $limit, int $offset): array
    {
        /** @var list<MemberFollow> $relations */
        $relations = $this->createQueryBuilder('follow')
            ->innerJoin('follow.followed', 'followed')
            ->addSelect('followed')
            ->andWhere('follow.follower = :follower')
            ->andWhere('followed.active = :active')
            ->andWhere('followed.emailVerified = :verified')
            ->andWhere('followed.publicProfile = :public')
            ->setParameter('follower', $follower)
            ->setParameter('active', true)
            ->setParameter('verified', true)
            ->setParameter('public', true)
            ->orderBy('follow.createdAt', \SortDirection::Descending)
            ->addOrderBy('follow.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return array_map(static fn (MemberFollow $follow): MemberAccount => $follow->getFollowed(), $relations);
    }

    /** @return list<MemberAccount> */
    public function findFollowers(MemberAccount $followed, int $limit, int $offset): array
    {
        /** @var list<MemberFollow> $relations */
        $relations = $this->createQueryBuilder('follow')
            ->innerJoin('follow.follower', 'follower')
            ->addSelect('follower')
            ->andWhere('follow.followed = :followed')
            ->andWhere('follower.active = :active')
            ->andWhere('follower.emailVerified = :verified')
            ->andWhere('follower.publicProfile = :public')
            ->setParameter('followed', $followed)
            ->setParameter('active', true)
            ->setParameter('verified', true)
            ->setParameter('public', true)
            ->orderBy('follow.createdAt', \SortDirection::Descending)
            ->addOrderBy('follow.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return array_map(static fn (MemberFollow $follow): MemberAccount => $follow->getFollower(), $relations);
    }

    /** @return list<MemberAccount> */
    public function findSuggestions(MemberAccount $viewer, int $limit, int $offset): array
    {
        $followedByViewer = $this->getEntityManager()->createQueryBuilder()
            ->select('existingFollow.id')
            ->from(MemberFollow::class, 'existingFollow')
            ->where('existingFollow.follower = :viewer')
            ->andWhere('existingFollow.followed = candidate');

        /** @var list<MemberAccount> $members */
        $members = $this->getEntityManager()->createQueryBuilder()
            ->select('candidate')
            ->from(MemberAccount::class, 'candidate')
            ->where('candidate.active = :active')
            ->andWhere('candidate.emailVerified = :verified')
            ->andWhere('candidate.publicProfile = :public')
            ->andWhere('candidate != :viewer')
            ->andWhere('NOT EXISTS ('.$followedByViewer->getDQL().')')
            ->setParameter('active', true)
            ->setParameter('verified', true)
            ->setParameter('public', true)
            ->setParameter('viewer', $viewer)
            ->orderBy('candidate.createdAt', \SortDirection::Descending)
            ->addOrderBy('candidate.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $members;
    }

    public function countVisibleFollowers(MemberAccount $followed): int
    {
        return (int) $this->createQueryBuilder('follow')
            ->select('COUNT(follower.id)')
            ->innerJoin('follow.follower', 'follower')
            ->andWhere('follow.followed = :followed')
            ->andWhere('follower.active = :active')
            ->andWhere('follower.emailVerified = :verified')
            ->andWhere('follower.publicProfile = :public')
            ->setParameter('followed', $followed)
            ->setParameter('active', true)
            ->setParameter('verified', true)
            ->setParameter('public', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countVisibleFollowing(MemberAccount $follower): int
    {
        return (int) $this->createQueryBuilder('follow')
            ->select('COUNT(followed.id)')
            ->innerJoin('follow.followed', 'followed')
            ->andWhere('follow.follower = :follower')
            ->andWhere('followed.active = :active')
            ->andWhere('followed.emailVerified = :verified')
            ->andWhere('followed.publicProfile = :public')
            ->setParameter('follower', $follower)
            ->setParameter('active', true)
            ->setParameter('verified', true)
            ->setParameter('public', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<array{
     *     kind: string,
     *     occurred_at: \DateTimeImmutable,
     *     member_handle: string,
     *     member_name: string,
     *     event_id: string,
     *     target_id: string,
     *     target_slug: ?string,
     *     target_title: string
     * }>
     */
    public function findPublicActivityFor(MemberAccount $viewer, int $limit, int $offset): array
    {
        $sql = <<<'SQL'
            SELECT activity.kind, activity.occurred_at, activity.member_handle, activity.member_name,
                   activity.event_id, activity.target_id, activity.target_slug, activity.target_title
            FROM (
                SELECT 'video_submission' AS kind, submission.reviewed_at AS occurred_at,
                       member.handle AS member_handle, member.display_name AS member_name,
                       submission.id AS event_id, video.id AS target_id,
                       NULL::VARCHAR(200) AS target_slug, video.title AS target_title
                FROM video_submissions submission
                INNER JOIN videos video ON video.id = submission.video_id
                INNER JOIN member_accounts member ON member.id = submission.submitter_id
                INNER JOIN member_follows follow ON follow.followed_id = member.id AND follow.follower_id = :viewer_video_submission
                WHERE submission.status = 'approved' AND video.status = 'published' AND video.published_at IS NOT NULL
                  AND member.is_active = TRUE AND member.email_verified = TRUE AND member.public_profile = TRUE
                  AND NOT EXISTS (SELECT 1 FROM member_activity_moderations moderation WHERE moderation.activity_type = 'video_submission' AND moderation.video_submission_id = submission.id AND moderation.status IN ('hidden', 'removed'))
                UNION ALL
                SELECT 'playlist' AS kind, playlist.updated_at AS occurred_at,
                       member.handle AS member_handle, member.display_name AS member_name,
                       playlist.id AS event_id, playlist.id AS target_id,
                       NULL::VARCHAR(200) AS target_slug, playlist.name AS target_title
                FROM member_playlists playlist
                INNER JOIN member_accounts member ON member.id = playlist.owner_id
                INNER JOIN member_follows follow ON follow.followed_id = member.id AND follow.follower_id = :viewer_playlist
                WHERE playlist.is_public = TRUE
                  AND member.is_active = TRUE AND member.email_verified = TRUE AND member.public_profile = TRUE
                  AND NOT EXISTS (SELECT 1 FROM member_activity_moderations moderation WHERE moderation.activity_type = 'playlist' AND moderation.playlist_id = playlist.id AND moderation.status IN ('hidden', 'removed'))
                UNION ALL
                SELECT 'video_comment' AS kind, comment.created_at AS occurred_at,
                       member.handle AS member_handle, member.display_name AS member_name,
                       comment.id AS event_id, video.id AS target_id,
                       NULL::VARCHAR(200) AS target_slug, video.title AS target_title
                FROM content_comments comment
                INNER JOIN videos video ON comment.content_type = 'video' AND video.id = comment.content_id
                INNER JOIN member_accounts member ON member.id = comment.member_id
                INNER JOIN member_follows follow ON follow.followed_id = member.id AND follow.follower_id = :viewer_video_comment
                WHERE comment.status = 'approved' AND video.status = 'published' AND video.published_at IS NOT NULL AND video.comments_enabled = TRUE
                  AND member.is_active = TRUE AND member.email_verified = TRUE AND member.public_profile = TRUE
                  AND NOT EXISTS (SELECT 1 FROM member_activity_moderations moderation WHERE moderation.activity_type = 'video_comment' AND moderation.comment_id = comment.id AND moderation.status IN ('hidden', 'removed'))
                UNION ALL
                SELECT 'news_comment' AS kind, comment.created_at AS occurred_at,
                       member.handle AS member_handle, member.display_name AS member_name,
                       comment.id AS event_id, article.id AS target_id,
                       article.slug AS target_slug, article.title AS target_title
                FROM content_comments comment
                INNER JOIN news_articles article ON comment.content_type = 'news' AND article.id = comment.content_id
                INNER JOIN member_accounts member ON member.id = comment.member_id
                INNER JOIN member_follows follow ON follow.followed_id = member.id AND follow.follower_id = :viewer_news_comment
                WHERE comment.status = 'approved' AND article.status = 'published' AND article.published_at IS NOT NULL AND article.comments_enabled = TRUE
                  AND member.is_active = TRUE AND member.email_verified = TRUE AND member.public_profile = TRUE
                  AND NOT EXISTS (SELECT 1 FROM member_activity_moderations moderation WHERE moderation.activity_type = 'news_comment' AND moderation.comment_id = comment.id AND moderation.status IN ('hidden', 'removed'))
            ) activity
            ORDER BY activity.occurred_at DESC, activity.event_id ASC, activity.kind ASC
            LIMIT :limit OFFSET :offset
            SQL;

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'viewer_video_submission' => $viewer->getId(),
                'viewer_playlist' => $viewer->getId(),
                'viewer_video_comment' => $viewer->getId(),
                'viewer_news_comment' => $viewer->getId(),
                'limit' => max(1, min(100, $limit)),
                'offset' => max(0, $offset),
            ],
            [
                'viewer_video_submission' => Types::STRING,
                'viewer_playlist' => Types::STRING,
                'viewer_video_comment' => Types::STRING,
                'viewer_news_comment' => Types::STRING,
                'limit' => Types::INTEGER,
                'offset' => Types::INTEGER,
            ],
        )->fetchAllAssociative();

        $activities = [];
        foreach ($rows as $row) {
            $targetSlug = $row['target_slug'] ?? null;
            if (!is_string($row['kind'] ?? null)
                || !is_string($row['occurred_at'] ?? null)
                || !is_string($row['member_handle'] ?? null)
                || !is_string($row['member_name'] ?? null)
                || !is_string($row['event_id'] ?? null)
                || !is_string($row['target_id'] ?? null)
                || (!is_string($targetSlug) && null !== $targetSlug)
                || !is_string($row['target_title'] ?? null)
            ) {
                continue;
            }

            $activities[] = [
                'kind' => $row['kind'],
                'occurred_at' => new \DateTimeImmutable($row['occurred_at'], new \DateTimeZone('UTC')),
                'member_handle' => $row['member_handle'],
                'member_name' => $row['member_name'],
                'event_id' => $row['event_id'],
                'target_id' => $row['target_id'],
                'target_slug' => $targetSlug,
                'target_title' => $row['target_title'],
            ];
        }

        return $activities;
    }
}
