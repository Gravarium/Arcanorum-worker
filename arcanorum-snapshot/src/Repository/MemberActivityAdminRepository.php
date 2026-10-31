<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminUser;
use App\Entity\CommentContentType;
use App\Entity\ContentComment;
use App\Entity\MemberActivityModeration;
use App\Entity\MemberActivityModerationStatus;
use App\Entity\MemberActivityType;
use App\Entity\Playlist;
use App\Entity\VideoSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemberActivityModeration>
 */
final class MemberActivityAdminRepository extends ServiceEntityRepository
{
    private const int MAX_BATCH_SIZE = 100;

    private const string PUBLIC_ACTIVITY_CTE = <<<'SQL'
        WITH public_activities AS (
            SELECT 'video_submission' AS kind, submission.reviewed_at AS occurred_at,
                   member.handle AS member_handle, member.display_name AS member_name,
                   submission.id AS event_id, video.id AS target_id,
                   NULL::VARCHAR(200) AS target_slug, video.title AS target_title
            FROM video_submissions submission
            INNER JOIN videos video ON video.id = submission.video_id
            INNER JOIN member_accounts member ON member.id = submission.submitter_id
            WHERE submission.status = 'approved' AND video.status = 'published' AND video.published_at IS NOT NULL
              AND member.is_active = TRUE AND member.email_verified = TRUE AND member.public_profile = TRUE
            UNION ALL
            SELECT 'playlist' AS kind, playlist.updated_at AS occurred_at,
                   member.handle AS member_handle, member.display_name AS member_name,
                   playlist.id AS event_id, playlist.id AS target_id,
                   NULL::VARCHAR(200) AS target_slug, playlist.name AS target_title
            FROM member_playlists playlist
            INNER JOIN member_accounts member ON member.id = playlist.owner_id
            WHERE playlist.is_public = TRUE
              AND member.is_active = TRUE AND member.email_verified = TRUE AND member.public_profile = TRUE
            UNION ALL
            SELECT 'video_comment' AS kind, comment.created_at AS occurred_at,
                   member.handle AS member_handle, member.display_name AS member_name,
                   comment.id AS event_id, video.id AS target_id,
                   NULL::VARCHAR(200) AS target_slug, video.title AS target_title
            FROM content_comments comment
            INNER JOIN videos video ON comment.content_type = 'video' AND video.id = comment.content_id
            INNER JOIN member_accounts member ON member.id = comment.member_id
            WHERE comment.status = 'approved' AND video.status = 'published' AND video.published_at IS NOT NULL AND video.comments_enabled = TRUE
              AND member.is_active = TRUE AND member.email_verified = TRUE AND member.public_profile = TRUE
            UNION ALL
            SELECT 'news_comment' AS kind, comment.created_at AS occurred_at,
                   member.handle AS member_handle, member.display_name AS member_name,
                   comment.id AS event_id, article.id AS target_id,
                   article.slug AS target_slug, article.title AS target_title
            FROM content_comments comment
            INNER JOIN news_articles article ON comment.content_type = 'news' AND article.id = comment.content_id
            INNER JOIN member_accounts member ON member.id = comment.member_id
            WHERE comment.status = 'approved' AND article.status = 'published' AND article.published_at IS NOT NULL AND article.comments_enabled = TRUE
              AND member.is_active = TRUE AND member.email_verified = TRUE AND member.public_profile = TRUE
        )
        SQL;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberActivityModeration::class);
    }

    /**
     * @return list<array{type: MemberActivityType, event_id: non-empty-string, member_handle: non-empty-string, member_name: non-empty-string, target_title: non-empty-string, occurred_at: \DateTimeImmutable, status: MemberActivityModerationStatus}>
     */
    public function findForAdmin(
        string $search,
        ?MemberActivityType $type,
        ?MemberActivityModerationStatus $status,
        int $limit,
        int $offset,
    ): array {
        [$conditions, $parameters] = $this->filters($search, $type, $status);
        $sql = self::PUBLIC_ACTIVITY_CTE.' '
            .'SELECT activity.kind, activity.event_id, activity.member_handle, activity.member_name, activity.target_title, '
            .'activity.occurred_at, COALESCE(moderation.status, \'visible\') AS moderation_status '
            .'FROM public_activities activity '
            .'LEFT JOIN member_activity_moderations moderation ON moderation.activity_type = activity.kind AND ('
            .'(activity.kind = \'video_submission\' AND moderation.video_submission_id = activity.event_id) OR '
            .'(activity.kind = \'playlist\' AND moderation.playlist_id = activity.event_id) OR '
            .'(activity.kind IN (\'video_comment\', \'news_comment\') AND moderation.comment_id = activity.event_id)) '
            .$this->whereClause($conditions)
            .' ORDER BY activity.occurred_at DESC, activity.event_id ASC, activity.kind ASC LIMIT :limit OFFSET :offset';
        $parameters['limit'] = max(1, min(self::MAX_BATCH_SIZE, $limit));
        $parameters['offset'] = max(0, $offset);

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            $parameters,
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        )->fetchAllAssociative();

        $activities = [];
        foreach ($rows as $row) {
            $kind = is_string($row['kind'] ?? null) ? MemberActivityType::tryFrom($row['kind']) : null;
            $moderationStatus = is_string($row['moderation_status'] ?? null)
                ? MemberActivityModerationStatus::tryFrom($row['moderation_status'])
                : null;
            if (null === $kind || null === $moderationStatus
                || !is_string($row['event_id'] ?? null) || '' === $row['event_id']
                || !is_string($row['member_handle'] ?? null) || '' === $row['member_handle']
                || !is_string($row['member_name'] ?? null) || '' === $row['member_name']
                || !is_string($row['target_title'] ?? null) || '' === $row['target_title']
                || !is_string($row['occurred_at'] ?? null)
            ) {
                continue;
            }

            $activities[] = [
                'type' => $kind,
                'event_id' => $row['event_id'],
                'member_handle' => $row['member_handle'],
                'member_name' => $row['member_name'],
                'target_title' => $row['target_title'],
                'occurred_at' => new \DateTimeImmutable($row['occurred_at'], new \DateTimeZone('UTC')),
                'status' => $moderationStatus,
            ];
        }

        return $activities;
    }

    public function countForAdmin(
        string $search,
        ?MemberActivityType $type,
        ?MemberActivityModerationStatus $status,
    ): int {
        [$conditions, $parameters] = $this->filters($search, $type, $status);
        $sql = self::PUBLIC_ACTIVITY_CTE.' '
            .'SELECT COUNT(*) FROM public_activities activity '
            .'LEFT JOIN member_activity_moderations moderation ON moderation.activity_type = activity.kind AND ('
            .'(activity.kind = \'video_submission\' AND moderation.video_submission_id = activity.event_id) OR '
            .'(activity.kind = \'playlist\' AND moderation.playlist_id = activity.event_id) OR '
            .'(activity.kind IN (\'video_comment\', \'news_comment\') AND moderation.comment_id = activity.event_id)) '
            .$this->whereClause($conditions);

        $result = $this->getEntityManager()->getConnection()->fetchOne($sql, $parameters);
        if (!is_int($result) && !is_string($result)) {
            throw new \UnexpectedValueException('Die Anzahl der Mitgliederaktivitäten konnte nicht gelesen werden.');
        }

        $count = filter_var($result, FILTER_VALIDATE_INT);
        if (!is_int($count) || $count < 0) {
            throw new \UnexpectedValueException('Die Anzahl der Mitgliederaktivitäten ist ungültig.');
        }

        return $count;
    }

    /**
     * @param list<array{type: MemberActivityType, id: string}> $activities
     *
     * @throws \InvalidArgumentException                                       when an activity is malformed or no longer public
     * @throws \Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException when a source is concurrently deleted
     * @throws \Doctrine\DBAL\Exception\UniqueConstraintViolationException     when a concurrent request creates a duplicate moderation record
     */
    public function moderateMany(
        array $activities,
        MemberActivityModerationStatus $status,
        AdminUser $moderator,
    ): int {
        if ([] === $activities || count($activities) > self::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException('Die Auswahl der Mitgliederaktivitäten ist ungültig.');
        }

        $entityManager = $this->getEntityManager();
        $connection = $entityManager->getConnection();
        $connection->transactional(function () use ($activities, $status, $moderator, $entityManager): void {
            $identifiers = [];
            $validated = [];

            foreach ($activities as $activity) {
                $type = $activity['type'];
                $id = $activity['id'];
                if (1 !== preg_match('/\A[a-f0-9]{32}\z/D', $id) || !$this->isPublicActivity($type, $id)) {
                    throw new \InvalidArgumentException('Die ausgewählte Mitgliederaktivität ist nicht mehr verfügbar.');
                }

                $identity = $type->value.':'.$id;
                if (isset($identifiers[$identity])) {
                    throw new \InvalidArgumentException('Die Auswahl enthält doppelte Mitgliederaktivitäten.');
                }
                $identifiers[$identity] = true;
                $validated[] = $activity;
            }

            foreach ($validated as $activity) {
                $type = $activity['type'];
                $id = $activity['id'];
                $source = match ($type) {
                    MemberActivityType::VideoSubmission => $entityManager->find(VideoSubmission::class, $id),
                    MemberActivityType::Playlist => $entityManager->find(Playlist::class, $id),
                    MemberActivityType::VideoComment, MemberActivityType::NewsComment => $entityManager->find(ContentComment::class, $id),
                };
                if (!$source instanceof VideoSubmission && !$source instanceof Playlist && !$source instanceof ContentComment) {
                    throw new \InvalidArgumentException('Die ausgewählte Mitgliederaktivität ist nicht mehr verfügbar.');
                }
                if ($source instanceof ContentComment && !$this->commentMatchesActivityType($type, $source)) {
                    throw new \InvalidArgumentException('Der Kommentartyp der Aktivität ist ungültig.');
                }

                $sourceField = match ($type) {
                    MemberActivityType::VideoSubmission => 'videoSubmission',
                    MemberActivityType::Playlist => 'playlist',
                    MemberActivityType::VideoComment, MemberActivityType::NewsComment => 'comment',
                };
                $existing = $this->findOneBy([$sourceField => $source]);
                if ($existing instanceof MemberActivityModeration) {
                    $existing->updateStatus($status, $moderator);
                } else {
                    $entityManager->persist(new MemberActivityModeration($type, $status, $moderator, $source));
                }
            }

            $entityManager->flush();
        });

        return count($activities);
    }

    private function isPublicActivity(MemberActivityType $type, string $id): bool
    {
        $result = $this->getEntityManager()->getConnection()->fetchOne(
            self::PUBLIC_ACTIVITY_CTE.' SELECT 1 FROM public_activities WHERE kind = :kind AND event_id = :event_id LIMIT 1',
            ['kind' => $type->value, 'event_id' => $id],
        );

        return 1 === $result || '1' === $result;
    }

    private function commentMatchesActivityType(MemberActivityType $type, ContentComment $comment): bool
    {
        $expectedTypes = [
            MemberActivityType::VideoComment->value => CommentContentType::Video,
            MemberActivityType::NewsComment->value => CommentContentType::News,
        ];
        $expectedType = $expectedTypes[$type->value] ?? null;

        return null !== $expectedType && $comment->getContentType() === $expectedType;
    }

    /**
     * @return array{0: list<string>, 1: array<string, string|int>}
     */
    private function filters(
        string $search,
        ?MemberActivityType $type,
        ?MemberActivityModerationStatus $status,
    ): array {
        $conditions = [];
        $parameters = [];

        if ('' !== $search) {
            $conditions[] = '(LOWER(activity.member_handle) LIKE :search ESCAPE \'!\' OR LOWER(activity.member_name) LIKE :search ESCAPE \'!\' OR LOWER(activity.target_title) LIKE :search ESCAPE \'!\')';
            $literalSearch = strtr(mb_strtolower($search, 'UTF-8'), ['!' => '!!', '%' => '!%', '_' => '!_']);
            $parameters['search'] = '%'.$literalSearch.'%';
        }
        if (null !== $type) {
            $conditions[] = 'activity.kind = :activity_type';
            $parameters['activity_type'] = $type->value;
        }
        if (null !== $status) {
            $conditions[] = MemberActivityModerationStatus::Visible === $status
                ? "(moderation.status IS NULL OR moderation.status = 'visible')"
                : 'moderation.status = :moderation_status';
            if (MemberActivityModerationStatus::Visible !== $status) {
                $parameters['moderation_status'] = $status->value;
            }
        }

        return [$conditions, $parameters];
    }

    /** @param list<string> $conditions */
    private function whereClause(array $conditions): string
    {
        return [] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions);
    }
}
