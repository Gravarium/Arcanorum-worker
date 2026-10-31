<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentComment;
use App\Entity\ContentRating;
use App\Entity\ContentRatingValue;
use App\Entity\MemberAccount;
use App\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentRating>
 */
final class ContentRatingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentRating::class);
    }

    public function setForVideo(MemberAccount $member, Video $video, ContentRatingValue $value): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO content_ratings (id, member_id, video_id, comment_id, value, created_at, updated_at) '
                .'VALUES (:id, :member_id, :video_id, NULL, :value, :created_at, :updated_at) '
                .'ON CONFLICT (member_id, video_id) DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at',
            [
                'id' => bin2hex(random_bytes(16)),
                'member_id' => $member->getId(),
                'video_id' => $video->getId(),
                'value' => $value->value,
                'created_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'updated_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ],
            [
                'id' => Types::STRING,
                'member_id' => Types::STRING,
                'video_id' => Types::STRING,
                'value' => Types::STRING,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }

    public function setForComment(MemberAccount $member, ContentComment $comment, ContentRatingValue $value): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO content_ratings (id, member_id, video_id, comment_id, value, created_at, updated_at) '
                .'VALUES (:id, :member_id, NULL, :comment_id, :value, :created_at, :updated_at) '
                .'ON CONFLICT (member_id, comment_id) DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at',
            [
                'id' => bin2hex(random_bytes(16)),
                'member_id' => $member->getId(),
                'comment_id' => $comment->getId(),
                'value' => $value->value,
                'created_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'updated_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ],
            [
                'id' => Types::STRING,
                'member_id' => Types::STRING,
                'comment_id' => Types::STRING,
                'value' => Types::STRING,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }

    public function withdrawFromVideo(MemberAccount $member, Video $video): void
    {
        $this->getEntityManager()->getConnection()->delete('content_ratings', [
            'member_id' => $member->getId(),
            'video_id' => $video->getId(),
        ]);
    }

    public function withdrawFromComment(MemberAccount $member, ContentComment $comment): void
    {
        $this->getEntityManager()->getConnection()->delete('content_ratings', [
            'member_id' => $member->getId(),
            'comment_id' => $comment->getId(),
        ]);
    }

    /**
     * @return array{like_count: int, dislike_count: int, member_value: ?string}
     */
    public function summaryForVideo(string $videoId, ?string $memberId): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            "SELECT COUNT(*) FILTER (WHERE value = 'like') AS like_count, "
                ."COUNT(*) FILTER (WHERE value = 'dislike') AS dislike_count, "
                .'MAX(value) FILTER (WHERE member_id = :member_id) AS member_value '
                .'FROM content_ratings WHERE video_id = :video_id',
            ['video_id' => $videoId, 'member_id' => $memberId],
            ['video_id' => Types::STRING, 'member_id' => Types::STRING],
        );
        if (!is_array($row)) {
            return ['like_count' => 0, 'dislike_count' => 0, 'member_value' => null];
        }

        return [
            'like_count' => self::countValue($row['like_count'] ?? null),
            'dislike_count' => self::countValue($row['dislike_count'] ?? null),
            'member_value' => self::ratingValue($row['member_value'] ?? null),
        ];
    }

    /** @return list<ContentRating> */
    public function findPublishedVideoRatingsForMember(MemberAccount $member, int $limit, int $offset): array
    {
        /** @var list<ContentRating> $items */
        $items = $this->createQueryBuilder('rating')
            ->innerJoin('rating.video', 'video')
            ->addSelect('video')
            ->andWhere('rating.member = :member')
            ->andWhere('video.status = :status')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->setParameter('member', $member)
            ->setParameter('status', \App\Entity\PublishingStatus::Published)
            ->orderBy('rating.updatedAt', \SortDirection::Descending)
            ->addOrderBy('video.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return array_values(array_filter($items, static fn (ContentRating $rating): bool => null !== $rating->getVideo()));
    }

    /**
     * @param list<string> $commentIds
     *
     * @return array<string, array{like_count: int, dislike_count: int, member_value: ?string}>
     */
    public function summariesForComments(array $commentIds, ?string $memberId): array
    {
        if ([] === $commentIds) {
            return [];
        }

        $summaries = [];
        foreach ($commentIds as $commentId) {
            $summaries[$commentId] = [
                'like_count' => 0,
                'dislike_count' => 0,
                'member_value' => null,
            ];
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT comment_id, COUNT(*) FILTER (WHERE value = 'like') AS like_count, "
                ."COUNT(*) FILTER (WHERE value = 'dislike') AS dislike_count, "
                .'MAX(value) FILTER (WHERE member_id = :member_id) AS member_value '
                .'FROM content_ratings WHERE comment_id IN (:comment_ids) GROUP BY comment_id',
            ['comment_ids' => $commentIds, 'member_id' => $memberId],
            ['comment_ids' => ArrayParameterType::STRING, 'member_id' => Types::STRING],
        )->fetchAllAssociative();

        foreach ($rows as $row) {
            $commentId = $row['comment_id'] ?? null;
            if (!is_string($commentId)) {
                continue;
            }
            $summaries[$commentId] = [
                'like_count' => self::countValue($row['like_count'] ?? null),
                'dislike_count' => self::countValue($row['dislike_count'] ?? null),
                'member_value' => self::ratingValue($row['member_value'] ?? null),
            ];
        }

        return $summaries;
    }

    private static function countValue(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    private static function ratingValue(mixed $value): ?string
    {
        return is_string($value) ? ContentRatingValue::tryFrom($value)?->value : null;
    }
}
