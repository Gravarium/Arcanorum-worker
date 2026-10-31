<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlaylistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlaylistRepository::class)]
#[ORM\Table(name: 'member_playlists')]
#[ORM\Index(name: 'idx_member_playlists_owner_updated', columns: ['owner_id', 'updated_at'])]
final class Playlist
{
    /** @var non-empty-string */
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'is_public', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $visibilityPublic = false;

    /** @var Collection<int, PlaylistItem> */
    #[ORM\OneToMany(mappedBy: 'playlist', targetEntity: PlaylistItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'addedAt' => 'ASC'])]
    private Collection $items;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MemberAccount::class)]
        #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private MemberAccount $owner,
        string $name,
        string $description,
        bool $public = false,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->items = new ArrayCollection();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->revise($name, $description, $public);
    }

    public function revise(string $name, string $description, bool $public): void
    {
        $normalizedName = trim($name);
        $normalizedDescription = trim($description);

        if (!$this->validText($normalizedName)
            || '' === $normalizedName
            || mb_strlen($normalizedName, 'UTF-8') > 80
            || !$this->validText($normalizedDescription)
            || mb_strlen($normalizedDescription, 'UTF-8') > 500
        ) {
            throw new \InvalidArgumentException('Die Wiedergabeliste enthält ungültige Angaben.');
        }

        $this->name = $normalizedName;
        $this->description = '' === $normalizedDescription ? null : $normalizedDescription;
        $this->visibilityPublic = $public;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function addVideo(Video $video): bool
    {
        if (PublishingStatus::Published !== $video->getStatus() || null === $video->getPublishedAt()) {
            throw new \InvalidArgumentException('Nur veröffentlichte Videos können gespeichert werden.');
        }

        foreach ($this->items as $item) {
            if ($item->getVideo()->getId() === $video->getId()) {
                return false;
            }
        }

        if (count($this->items) >= 200) {
            throw new \InvalidArgumentException('Eine Wiedergabeliste kann höchstens 200 Videos enthalten.');
        }

        $position = 0;
        foreach ($this->items as $item) {
            $position = max($position, $item->getPosition() + 1);
        }
        $this->items->add(new PlaylistItem($this, $video, $position));
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return true;
    }

    public function removeVideoById(string $videoId): void
    {
        foreach ($this->items as $item) {
            if ($item->getVideo()->getId() === $videoId) {
                $this->items->removeElement($item);
                $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                return;
            }
        }
    }

    /** @return non-empty-string */
    public function getId(): string
    {
        return $this->id;
    }

    public function getOwner(): MemberAccount
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isPublic(): bool
    {
        return $this->visibilityPublic;
    }

    /** @return Collection<int, PlaylistItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function validText(string $value): bool
    {
        return 1 === preg_match('//u', $value)
            && 1 !== preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value);
    }
}
