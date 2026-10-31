<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentCommentRepository::class)]
#[ORM\Table(name: 'content_comments')]
#[ORM\Index(name: 'idx_content_comments_status_created', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_content_comments_target_status', columns: ['content_type', 'content_id', 'status'])]
#[ORM\Index(name: 'idx_content_comments_member_created', columns: ['member_id', 'created_at'])]
final class ContentComment
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(name: 'author_name', length: 80)]
    private string $authorName;

    #[ORM\ManyToOne(targetEntity: MemberAccount::class)]
    #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?MemberAccount $member = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(name: 'filtered_by_term', length: 100, nullable: true)]
    private ?string $filteredByTerm = null;

    #[ORM\Column(length: 16, enumType: CommentStatus::class)]
    private CommentStatus $status = CommentStatus::Pending;

    #[ORM\Column(name: 'report_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $reportCount = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'moderated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $moderatedAt = null;

    public function __construct(
        #[ORM\Column(name: 'content_type', length: 16, enumType: CommentContentType::class)]
        private CommentContentType $contentType,
        #[ORM\Column(name: 'content_id', length: 32)]
        private string $contentId,
        #[ORM\Column(name: 'content_title', length: 180)]
        private string $contentTitle,
        string $authorName,
        string $body,
    ) {
        $now = new \DateTimeImmutable();
        $this->id = bin2hex(random_bytes(16));
        $this->authorName = trim($authorName);
        $this->body = trim($body);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function markForWordFilterReview(string $term): void
    {
        $this->filteredByTerm = trim($term);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function attributeToMember(MemberAccount $member): void
    {
        $this->member = $member;
    }

    public function getMember(): ?MemberAccount
    {
        return $this->member;
    }

    public function approve(): void
    {
        $this->status = CommentStatus::Approved;
        $this->moderatedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->moderatedAt;
    }

    public function reject(): void
    {
        $this->status = CommentStatus::Rejected;
        $this->moderatedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->moderatedAt;
    }

    public function report(): void
    {
        ++$this->reportCount;
        if (CommentStatus::Approved === $this->status && $this->reportCount >= 3) {
            $this->status = CommentStatus::Reported;
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function clearReports(): void
    {
        $this->reportCount = 0;
        if (CommentStatus::Reported === $this->status) {
            $this->status = CommentStatus::Approved;
        }
        $this->moderatedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->moderatedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getContentType(): CommentContentType
    {
        return $this->contentType;
    }

    public function getContentId(): string
    {
        return $this->contentId;
    }

    public function getContentTitle(): string
    {
        return $this->contentTitle;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getFilteredByTerm(): ?string
    {
        return $this->filteredByTerm;
    }

    public function getStatus(): CommentStatus
    {
        return $this->status;
    }

    public function getReportCount(): int
    {
        return $this->reportCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getModeratedAt(): ?\DateTimeImmutable
    {
        return $this->moderatedAt;
    }
}
