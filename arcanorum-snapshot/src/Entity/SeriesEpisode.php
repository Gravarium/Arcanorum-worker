<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SeriesEpisodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeriesEpisodeRepository::class)]
#[ORM\Table(name: 'series_episodes')]
#[ORM\UniqueConstraint(
    name: 'uniq_series_episodes_position',
    columns: ['series_id', 'season_number', 'episode_number'],
)]
#[ORM\UniqueConstraint(name: 'uniq_series_episodes_video', columns: ['video_id'])]
#[ORM\Index(name: 'idx_series_episodes_order', columns: ['series_id', 'season_number', 'episode_number'])]
final class SeriesEpisode
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ContentSeries::class)]
    #[ORM\JoinColumn(name: 'series_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ContentSeries $series;

    #[ORM\OneToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\Column(name: 'season_number', type: Types::SMALLINT)]
    private int $seasonNumber;

    #[ORM\Column(name: 'episode_number', type: Types::SMALLINT)]
    private int $episodeNumber;

    #[ORM\Column(name: 'title_override', length: 180, nullable: true)]
    private ?string $titleOverride = null;

    #[ORM\Column(name: 'description_override', type: Types::TEXT, nullable: true)]
    private ?string $descriptionOverride = null;

    #[ORM\Column(length: 16, enumType: PublishingStatus::class)]
    private PublishingStatus $status = PublishingStatus::Published;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        ContentSeries $series,
        Video $video,
        int $seasonNumber,
        int $episodeNumber,
        ?string $titleOverride = null,
        ?string $descriptionOverride = null,
        PublishingStatus $status = PublishingStatus::Published,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->revise(
            $series,
            $video,
            $seasonNumber,
            $episodeNumber,
            $titleOverride,
            $descriptionOverride,
            $status,
        );
    }

    public function revise(
        ContentSeries $series,
        Video $video,
        int $seasonNumber,
        int $episodeNumber,
        ?string $titleOverride = null,
        ?string $descriptionOverride = null,
        PublishingStatus $status = PublishingStatus::Published,
    ): void {
        $this->series = $series;
        $this->video = $video;
        $this->seasonNumber = $seasonNumber;
        $this->episodeNumber = $episodeNumber;
        $this->titleOverride = null === $titleOverride || '' === trim($titleOverride)
            ? null
            : trim($titleOverride);
        $this->descriptionOverride = null === $descriptionOverride || '' === trim($descriptionOverride)
            ? null
            : trim($descriptionOverride);
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSeries(): ContentSeries
    {
        return $this->series;
    }

    public function getVideo(): Video
    {
        return $this->video;
    }

    public function getSeasonNumber(): int
    {
        return $this->seasonNumber;
    }

    public function getEpisodeNumber(): int
    {
        return $this->episodeNumber;
    }

    public function getTitle(): string
    {
        return $this->titleOverride ?? $this->video->getTitle();
    }

    public function getTitleOverride(): ?string
    {
        return $this->titleOverride;
    }

    public function getDescription(): string
    {
        return $this->descriptionOverride ?? $this->video->getDescription();
    }

    public function getDescriptionOverride(): ?string
    {
        return $this->descriptionOverride;
    }

    public function getStatus(): PublishingStatus
    {
        return $this->status;
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
