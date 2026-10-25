<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VideoReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoReportRepository::class)]
#[ORM\Table(name: 'video_reports')]
#[ORM\Index(name: 'idx_video_reports_status_created', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_video_reports_video_created', columns: ['video_id', 'created_at'])]
final class VideoReport
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(type: Types::TEXT)]
    private string $details;

    #[ORM\Column(length: 16, enumType: VideoReportStatus::class, options: ['default' => 'pending'])]
    private VideoReportStatus $status = VideoReportStatus::Pending;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'reviewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    public function __construct(#[ORM\ManyToOne(targetEntity: Video::class)]
        #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private Video $video, #[ORM\Column(length: 24, enumType: VideoReportReason::class)]
        private VideoReportReason $reason, string $details = '')
    {
        if (1 !== preg_match('//u', $details)
            || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $details)
            || mb_strlen($details, 'UTF-8') > 1000
        ) {
            throw new \InvalidArgumentException('Die Begründung der Videomeldung ist ungültig.');
        }

        $this->id = bin2hex(random_bytes(16));
        $this->details = trim($details);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function resolve(VideoReportStatus $status): void
    {
        if (VideoReportStatus::Pending !== $this->status || VideoReportStatus::Pending === $status) {
            throw new \InvalidArgumentException('Nur offene Videomeldungen können abgeschlossen werden.');
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

    public function getReason(): VideoReportReason
    {
        return $this->reason;
    }

    public function getDetails(): string
    {
        return $this->details;
    }

    public function getStatus(): VideoReportStatus
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
