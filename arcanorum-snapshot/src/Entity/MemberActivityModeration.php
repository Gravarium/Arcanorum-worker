<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MemberActivityAdminRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MemberActivityAdminRepository::class)]
#[ORM\Table(name: 'member_activity_moderations')]
#[ORM\UniqueConstraint(name: 'uniq_member_activity_moderation_submission', columns: ['video_submission_id'])]
#[ORM\UniqueConstraint(name: 'uniq_member_activity_moderation_playlist', columns: ['playlist_id'])]
#[ORM\UniqueConstraint(name: 'uniq_member_activity_moderation_comment', columns: ['comment_id'])]
#[ORM\Index(name: 'idx_member_activity_moderation_status', columns: ['status', 'updated_at'])]
final class MemberActivityModeration
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: VideoSubmission::class)]
    #[ORM\JoinColumn(name: 'video_submission_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?VideoSubmission $videoSubmission = null;

    #[ORM\ManyToOne(targetEntity: Playlist::class)]
    #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Playlist $playlist = null;

    #[ORM\ManyToOne(targetEntity: ContentComment::class)]
    #[ORM\JoinColumn(name: 'comment_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?ContentComment $comment = null;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'moderator_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AdminUser $moderator = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(name: 'activity_type', length: 32, enumType: MemberActivityType::class)]
        private MemberActivityType $activityType,
        #[ORM\Column(length: 16, enumType: MemberActivityModerationStatus::class)]
        private MemberActivityModerationStatus $status,
        AdminUser $moderator,
        VideoSubmission|Playlist|ContentComment $source,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->assignModerator($moderator);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;

        match ($activityType) {
            MemberActivityType::VideoSubmission => $this->assignVideoSubmission($source),
            MemberActivityType::Playlist => $this->assignPlaylist($source),
            MemberActivityType::VideoComment, MemberActivityType::NewsComment => $this->assignComment($source, $activityType),
        };
    }

    public function updateStatus(MemberActivityModerationStatus $status, AdminUser $moderator): void
    {
        $this->status = $status;
        $this->assignModerator($moderator);
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getActivityType(): MemberActivityType
    {
        return $this->activityType;
    }

    public function getStatus(): MemberActivityModerationStatus
    {
        return $this->status;
    }

    public function getModerator(): ?AdminUser
    {
        return $this->moderator;
    }

    public function getVideoSubmission(): ?VideoSubmission
    {
        return $this->videoSubmission;
    }

    public function getPlaylist(): ?Playlist
    {
        return $this->playlist;
    }

    public function getComment(): ?ContentComment
    {
        return $this->comment;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function assignVideoSubmission(VideoSubmission|Playlist|ContentComment $source): void
    {
        if (!$source instanceof VideoSubmission) {
            throw new \InvalidArgumentException('Die Aktivitätsquelle passt nicht zum Typ Videoeinreichung.');
        }

        $this->videoSubmission = $source;
    }

    private function assignModerator(AdminUser $moderator): void
    {
        $this->moderator = $moderator;
    }

    private function assignPlaylist(VideoSubmission|Playlist|ContentComment $source): void
    {
        if (!$source instanceof Playlist) {
            throw new \InvalidArgumentException('Die Aktivitätsquelle passt nicht zum Typ Wiedergabeliste.');
        }

        $this->playlist = $source;
    }

    private function assignComment(VideoSubmission|Playlist|ContentComment $source, MemberActivityType $type): void
    {
        $expectedContentType = match ($type) {
            MemberActivityType::VideoComment => CommentContentType::Video,
            MemberActivityType::NewsComment => CommentContentType::News,
            default => throw new \InvalidArgumentException('Der Aktivitätstyp ist kein Kommentartyp.'),
        };

        if (!$source instanceof ContentComment || $source->getContentType() !== $expectedContentType) {
            throw new \InvalidArgumentException('Die Aktivitätsquelle passt nicht zum Kommentartyp.');
        }

        $this->comment = $source;
    }
}
