<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VideoQualitySourceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoQualitySourceRepository::class)]
#[ORM\Table(name: 'video_quality_sources')]
#[ORM\UniqueConstraint(name: 'uniq_video_quality_resolution', columns: ['video_id', 'resolution'])]
#[ORM\UniqueConstraint(name: 'uniq_video_quality_source_url', columns: ['video_id', 'source_url'])]
final class VideoQualitySource
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(name: 'source_url', length: 2048)]
    private string $sourceUrl;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Video::class)]
        #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private Video $video,
        #[ORM\Column(length: 8, enumType: VideoQuality::class)]
        private VideoQuality $resolution,
        string $sourceUrl,
    ) {
        self::assertValidSourceUrl($sourceUrl);
        $this->id = bin2hex(random_bytes(16));
        $this->sourceUrl = trim($sourceUrl);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function revise(VideoQuality $resolution, string $sourceUrl): void
    {
        self::assertValidSourceUrl($sourceUrl);
        $this->resolution = $resolution;
        $this->sourceUrl = trim($sourceUrl);
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function isValidSourceUrl(string $sourceUrl): bool
    {
        $sourceUrl = trim($sourceUrl);

        return mb_check_encoding($sourceUrl, 'UTF-8')
            && strlen($sourceUrl) <= 2048
            && Video::isSafeHttpsSource($sourceUrl);
    }

    private static function assertValidSourceUrl(string $sourceUrl): void
    {
        if (!self::isValidSourceUrl($sourceUrl)) {
            throw new \InvalidArgumentException('Die Videoqualitätsquelle muss eine gültige HTTPS-Adresse sein.');
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVideo(): Video
    {
        return $this->video;
    }

    public function getResolution(): VideoQuality
    {
        return $this->resolution;
    }

    public function getSourceUrl(): string
    {
        return $this->sourceUrl;
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
