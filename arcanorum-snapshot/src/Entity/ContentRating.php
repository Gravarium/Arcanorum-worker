<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentRatingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentRatingRepository::class)]
#[ORM\Table(name: 'content_ratings')]
#[ORM\UniqueConstraint(name: 'uniq_content_ratings_member_video', columns: ['member_id', 'video_id'])]
#[ORM\UniqueConstraint(name: 'uniq_content_ratings_member_comment', columns: ['member_id', 'comment_id'])]
#[ORM\Index(name: 'idx_content_ratings_video_value', columns: ['video_id', 'value'])]
#[ORM\Index(name: 'idx_content_ratings_comment_value', columns: ['comment_id', 'value'])]
final class ContentRating
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Video $video;

    #[ORM\ManyToOne(targetEntity: ContentComment::class)]
    #[ORM\JoinColumn(name: 'comment_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?ContentComment $comment;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MemberAccount::class)]
        #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private MemberAccount $member,
        ?Video $video,
        ?ContentComment $comment,
        #[ORM\Column(length: 8, enumType: ContentRatingValue::class)]
        private ContentRatingValue $value,
    ) {
        if ((null === $video) === (null === $comment)) {
            throw new \InvalidArgumentException('Eine Bewertung muss genau ein öffentliches Ziel besitzen.');
        }

        $this->id = bin2hex(random_bytes(16));
        $this->video = $video;
        $this->comment = $comment;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function changeTo(ContentRatingValue $value): void
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getMember(): MemberAccount
    {
        return $this->member;
    }

    public function getVideo(): ?Video
    {
        return $this->video;
    }

    public function getComment(): ?ContentComment
    {
        return $this->comment;
    }

    public function getValue(): ContentRatingValue
    {
        return $this->value;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
