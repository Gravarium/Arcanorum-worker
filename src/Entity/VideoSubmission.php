<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VideoSubmissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoSubmissionRepository::class)]
#[ORM\Table(name: 'video_submissions')]
#[ORM\UniqueConstraint(name: 'uniq_video_submissions_video', columns: ['video_id'])]
#[ORM\Index(name: 'idx_video_submissions_status_created', columns: ['status', 'created_at'])]
final class VideoSubmission
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 16, enumType: VideoSubmissionStatus::class, options: ['default' => 'pending'])]
    private VideoSubmissionStatus $status = VideoSubmissionStatus::Pending;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'reviewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Video::class)]
        #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private Video $video,
        #[ORM\ManyToOne(targetEntity: MemberAccount::class)]
        #[ORM\JoinColumn(name: 'submitter_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
        private ?MemberAccount $submitter = null,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function resolve(VideoSubmissionStatus $status): void
    {
        if (VideoSubmissionStatus::Pending !== $this->status || VideoSubmissionStatus::Pending === $status) {
            throw new \InvalidArgumentException('Nur wartende Videoeinreichungen können bearbeitet werden.');
        }

        $this->status = $status;
        $this->reviewedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVideo(): Video
    {
        return $this->video;
    }

    public function getSubmitter(): ?MemberAccount
    {
        return $this->submitter;
    }

    public function getStatus(): VideoSubmissionStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }
}
