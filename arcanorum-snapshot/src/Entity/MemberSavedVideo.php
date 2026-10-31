<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MemberSavedVideoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MemberSavedVideoRepository::class)]
#[ORM\Table(name: 'member_saved_videos')]
#[ORM\UniqueConstraint(name: 'uniq_member_saved_videos_collection', columns: ['member_id', 'video_id', 'collection'])]
#[ORM\Index(name: 'idx_member_saved_videos_member_collection', columns: ['member_id', 'collection', 'created_at'])]
final class MemberSavedVideo
{
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

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
        #[ORM\Column(length: 16, enumType: MemberVideoCollection::class)]
        private MemberVideoCollection $collection,
    ) {
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $this->id)) {
            throw new \InvalidArgumentException('Die Kennung des gespeicherten Videos ist ungültig.');
        }

        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function create(MemberAccount $member, Video $video, MemberVideoCollection $collection): self
    {
        return new self(bin2hex(random_bytes(16)), $member, $video, $collection);
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

    public function getCollection(): MemberVideoCollection
    {
        return $this->collection;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
