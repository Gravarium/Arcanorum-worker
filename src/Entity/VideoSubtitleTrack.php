<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VideoSubtitleTrackRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoSubtitleTrackRepository::class)]
#[ORM\Table(name: 'video_subtitle_tracks')]
#[ORM\UniqueConstraint(name: 'uniq_video_subtitle_language', columns: ['video_id', 'language_code'])]
#[ORM\UniqueConstraint(name: 'uniq_video_subtitle_object', columns: ['storage_object_id'])]
final class VideoSubtitleTrack
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\Column(name: 'language_code', length: 35)]
    private string $languageCode;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(name: 'storage_object_id', length: 64)]
    private string $storageObjectId;

    #[ORM\Column(type: Types::INTEGER)]
    private int $size;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Video $video,
        string $languageCode,
        string $label,
        string $storageObjectId,
        int $size,
        string $sha256,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->revise($video, $languageCode, $label, $storageObjectId, $size, $sha256);
    }

    public function revise(
        Video $video,
        string $languageCode,
        string $label,
        string $storageObjectId,
        int $size,
        string $sha256,
    ): ?string {
        if (!self::isValidLanguageCode($languageCode)
            || !self::isValidLabel($label)
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $storageObjectId)
            || $size < 1
            || $size > 1048576
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $sha256)
        ) {
            throw new \InvalidArgumentException('Die Untertitelangaben sind ungültig.');
        }

        $previousObjectId = isset($this->storageObjectId) ? $this->storageObjectId : null;
        $this->video = $video;
        $this->languageCode = self::normalizeLanguageCode($languageCode);
        $this->label = trim($label);
        $this->storageObjectId = $storageObjectId;
        $this->size = $size;
        $this->sha256 = $sha256;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $previousObjectId;
    }

    public static function isValidLanguageCode(string $languageCode): bool
    {
        return mb_strlen($languageCode) <= 35
            && 1 === preg_match('/\A[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*\z/D', $languageCode);
    }

    public static function isValidLabel(string $label): bool
    {
        $label = trim($label);

        return '' !== $label
            && mb_strlen($label) <= 120
            && mb_check_encoding($label, 'UTF-8')
            && 1 !== preg_match('/[\x00-\x1F\x7F]/u', $label);
    }

    public static function normalizeLanguageCode(string $languageCode): string
    {
        if (!self::isValidLanguageCode($languageCode)) {
            throw new \InvalidArgumentException('Bitte wähle einen gültigen Sprachcode.');
        }

        $parts = explode('-', $languageCode);
        foreach ($parts as $index => $part) {
            if (0 === $index) {
                $parts[$index] = strtolower($part);
            } elseif (4 === strlen($part) && ctype_alpha($part)) {
                $parts[$index] = ucfirst(strtolower($part));
            } elseif ((2 === strlen($part) && ctype_alpha($part)) || (3 === strlen($part) && ctype_digit($part))) {
                $parts[$index] = strtoupper($part);
            } else {
                $parts[$index] = strtolower($part);
            }
        }

        return implode('-', $parts);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVideo(): Video
    {
        return $this->video;
    }

    public function getLanguageCode(): string
    {
        return $this->languageCode;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getStorageObjectId(): string
    {
        return $this->storageObjectId;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getSha256(): string
    {
        return $this->sha256;
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
