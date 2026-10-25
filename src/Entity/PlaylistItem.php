<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlaylistItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlaylistItemRepository::class)]
#[ORM\Table(name: 'playlist_items')]
#[ORM\UniqueConstraint(name: 'uniq_playlist_items_playlist_video', columns: ['playlist_id', 'video_id'])]
#[ORM\Index(name: 'idx_playlist_items_playlist_position', columns: ['playlist_id', 'position'])]
final class PlaylistItem
{
    /** @var non-empty-string */
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position;

    #[ORM\Column(name: 'added_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $addedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Playlist::class, inversedBy: 'items')]
        #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private Playlist $playlist,
        #[ORM\ManyToOne(targetEntity: Video::class)]
        #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private Video $video,
        int $position,
    ) {
        if ($position < 0 || $position > 10000) {
            throw new \InvalidArgumentException('Die Position des Wiedergabelisteneintrags ist ungültig.');
        }

        $this->id = bin2hex(random_bytes(16));
        $this->position = $position;
        $this->addedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @return non-empty-string */
    public function getId(): string
    {
        return $this->id;
    }

    public function getPlaylist(): Playlist
    {
        return $this->playlist;
    }

    public function getVideo(): Video
    {
        return $this->video;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }
}
