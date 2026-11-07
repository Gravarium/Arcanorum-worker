<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MemberAccount;
use App\Entity\MemberActivityType;
use App\Entity\MemberFollow;
use App\Entity\MemberFollowResult;
use Doctrine\DBAL\Connection;
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
    public function follow(MemberAccount $follower, MemberAccount $followed, \DateTimeImmutable $now, int $maximumFollowing): MemberFollowResult
    {
        if ($follower->getId() === $followed->getId()) {
            return MemberFollowResult::Unavailable;
        }

        if ($maximumFollowing < 0 || $maximumFollowing > 5000) {
            throw new \InvalidArgumentException('Die konfigurierte Folgegrenze liegt außerhalb des erlaubten Bereichs.');
        }

        $connection = $this->getEntityManager()->getConnection();

        return $connection->transactional(function (Connection $connection) use ($follower, $followed, $now, $maximumFollowing): MemberFollowResult {
            $followerId = $follower->getId();
            $followedId = $followed->getId();
            // Serialize new edges per follower without locking an account row that may
            // simultaneously be the target of a reciprocal follow.
            $connection->fetchOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(:follower_id, 0))',
                ['follower_id' => $followerId],
                ['follower_id' => Types::STRING],
            );
            if (false === $connection->fetchOne(
                'SELECT 1 FROM member_accounts WHERE id = :follower_id AND is_active = TRUE AND email_verified = TRUE',
                ['follower_id' => $followerId],
                ['follower_id' => Types::STRING],
            )) {
                return MemberFollowResult::Unavailable;
            }

            if (false !== $connection->fetchOne(
                'SELECT 1 FROM member_follows WHERE follower_id = :follower_id AND followed_id = :followed_id',
                ['follower_id' => $followerId, 'followed_id' => $followedId],
                ['follower_id' => Types::STRING, 'followed_id' => Types::STRING],
            )) {
                return MemberFollowResult::AlreadyFollowing;
            }

            if (0 === $maximumFollowing || (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM member_follows relation '
                .'INNER JOIN member_accounts followed ON followed.id = relation.followed_id '
                .'WHERE relation.follower_id = :follower_id AND followed.is_active = TRUE '
                .'AND followed.email_verified = TRUE AND followed.public_profile = TRUE',
                ['follower_id' => $followerId],
                ['follower_id' => Types::STRING],
            ) >= $maximumFollowing) {
                return MemberFollowResult::LimitReached;
            }

            $affectedRows = $connection->executeStatement(
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
                    'follower_id' => $followerId,
                    'followed_id' => $followedId,
                ],
                [
                    'id' => Types::STRING,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                    'follower_id' => Types::STRING,
                    'followed_id' => Types::STRING,
                ],
            );

            if (1 === $affectedRows) {
                return MemberFollowResult::Added;
            }

            return false !== $connection->fetchOne(
                'SELECT 1 FROM member_follows WHERE follower_id = :follower_id AND followed_id = :followed_id',
                ['follower_id' => $followerId, 'followed_id' => $followedId],
                ['follower_id' => Types::STRING, 'followed_id' => Types::STRING],
            ) ? MemberFollowResult::AlreadyFollowing : MemberFollowResult::Unavailable;
        });
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
    /** @param list<MemberActivityType>|null $enabledTypes */
    public function findPublicActivityFor(MemberAccount $viewer, int $limit, int $offset, ?array $enabledTypes = null): array
    {
        $enabledTypes ??= MemberActivityType::cases();
        $enabledTypeValues = [];
        foreach ($enabledTypes as $enabledType) {
            if (!$enabledType instanceof MemberActivityType) {
                throw new \InvalidArgumentException('Unbekannter Aktivitätstyp für den Mitgliederfeed.');
            }
            $enabledTypeValues[$enabledType->value] = true;
        }

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
                WHERE :show_video_submission = TRUE AND submission.status = 'approved' AND video.status = 'published' AND video.published_at IS NOT NULL
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
                WHERE :show_playlist = TRUE AND playlist.is_public = TRUE
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
                WHERE :show_video_comment = TRUE AND comment.status = 'approved' AND video.status = 'published' AND video.published_at IS NOT NULL AND video.comments_enabled = TRUE
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
                WHERE :show_news_comment = TRUE AND comment.status = 'approved' AND article.status = 'published' AND article.published_at IS NOT NULL AND article.comments_enabled = TRUE
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
                'show_video_submission' => isset($enabledTypeValues[MemberActivityType::VideoSubmission->value]),
                'show_playlist' => isset($enabledTypeValues[MemberActivityType::Playlist->value]),
                'show_video_comment' => isset($enabledTypeValues[MemberActivityType::VideoComment->value]),
                'show_news_comment' => isset($enabledTypeValues[MemberActivityType::NewsComment->value]),
                'limit' => max(1, min(100, $limit)),
                'offset' => max(0, $offset),
            ],
            [
                'viewer_video_submission' => Types::STRING,
                'viewer_playlist' => Types::STRING,
                'viewer_video_comment' => Types::STRING,
                'viewer_news_comment' => Types::STRING,
                'show_video_submission' => Types::BOOLEAN,
                'show_playlist' => Types::BOOLEAN,
                'show_video_comment' => Types::BOOLEAN,
                'show_news_comment' => Types::BOOLEAN,
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
