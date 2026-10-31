<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MemberVideoHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MemberVideoHistoryRepository::class)]
#[ORM\Table(name: 'member_video_history')]
#[ORM\UniqueConstraint(name: 'uniq_member_video_history_pair', columns: ['member_id', 'video_id'])]
#[ORM\Index(name: 'idx_member_video_history_member_watched', columns: ['member_id', 'last_watched_at'])]
final class MemberVideoHistory
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 32)]
        private string $id,
        #[ORM\ManyToOne(targetEntity: MemberAccount::class)]
        #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private MemberAccount $member,
        #[ORM\ManyToOne(targetEntity: Video::class)]
        #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private Video $video,
        #[ORM\Column(name: 'last_watched_at', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $lastWatchedAt,
    ) {
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $this->id)) {
            throw new \InvalidArgumentException('Die Kennung des Videoverlaufs ist ungültig.');
        }

        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $this->member->getId())
            || 1 !== preg_match('/\A[a-f0-9]{32}\z/', $this->video->getId())
        ) {
            throw new \InvalidArgumentException('Der Eintrag im Videoverlauf enthält eine ungültige Referenz.');
        }
    }

    public static function create(MemberAccount $member, Video $video, ?\DateTimeImmutable $lastWatchedAt = null): self
    {
        return new self(
            bin2hex(random_bytes(16)),
            $member,
            $video,
            $lastWatchedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getMember(): MemberAccount
    {
        return $this->member;
    }

    public function getVideo(): Video
    {
        return $this->video;
    }

    public function getLastWatchedAt(): \DateTimeImmutable
    {
        return $this->lastWatchedAt;
    }

    public function record(\DateTimeImmutable $watchedAt): void
    {
        if ($watchedAt > $this->lastWatchedAt) {
            $this->lastWatchedAt = $watchedAt;
        }
    }
}
