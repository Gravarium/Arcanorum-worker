<?php

declare(strict_types=1);

namespace App\Entity;

use App\Content\CustomFieldSet;
use App\Repository\VideoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoRepository::class)]
#[ORM\Table(name: 'videos')]
#[ORM\UniqueConstraint(name: 'uniq_videos_uploaded_object_id', columns: ['uploaded_object_id'])]
#[ORM\UniqueConstraint(name: 'uniq_videos_thumbnail_object_id', columns: ['thumbnail_object_id'])]
#[ORM\Index(name: 'idx_videos_status_created', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_videos_status_views', columns: ['status', 'view_count', 'published_at'])]
#[ORM\Index(name: 'idx_videos_status_last_viewed', columns: ['status', 'last_viewed_at'])]
final class Video
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category;

    #[ORM\Column(length: 16, enumType: VideoSourceType::class)]
    private VideoSourceType $sourceType = VideoSourceType::Url;

    #[ORM\Column(name: 'source_url', length: 2048)]
    private string $sourceUrl = '';

    #[ORM\Column(name: 'uploaded_object_id', length: 128, nullable: true)]
    private ?string $uploadedObjectId = null;

    #[ORM\Column(name: 'uploaded_mime_type', length: 100, nullable: true)]
    private ?string $uploadedMimeType = null;

    #[ORM\Column(name: 'uploaded_size', type: Types::INTEGER, nullable: true)]
    private ?int $uploadedSize = null;

    #[ORM\Column(name: 'thumbnail_object_id', length: 64, nullable: true)]
    private ?string $thumbnailObjectId = null;

    #[ORM\Column(name: 'thumbnail_mime_type', length: 32, nullable: true)]
    private ?string $thumbnailMimeType = null;

    #[ORM\Column(name: 'thumbnail_size', type: Types::INTEGER, nullable: true)]
    private ?int $thumbnailSize = null;

    #[ORM\Column(name: 'duration_seconds', type: Types::INTEGER, nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(length: 16, enumType: PublishingStatus::class)]
    private PublishingStatus $status;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $featured = false;

    #[ORM\Column(name: 'comments_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $commentsEnabled = true;

    /** @var list<mixed> Persisted JSON is validated again before use. */
    #[ORM\Column(name: 'embed_allowed_origins', type: Types::JSON, options: ['default' => '[]'])]
    private array $embedAllowedOrigins = [];

    /** @var list<array{key: string, value: string}> */
    #[ORM\Column(name: 'custom_fields', type: Types::JSON, options: ['default' => '[]'])]
    private array $customFields = [];

    #[ORM\Column(name: 'view_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $viewCount = 0;

    #[ORM\Column(name: 'last_viewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastViewedAt = null;

    #[ORM\Column(name: 'external_source_status', length: 16, enumType: ExternalVideoSourceStatus::class, nullable: true)]
    private ?ExternalVideoSourceStatus $externalSourceStatus = null;

    #[ORM\Column(name: 'external_source_check_failed', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $externalSourceCheckFailed = false;

    #[ORM\Column(name: 'external_source_checked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $externalSourceCheckedAt = null;

    /** @var Collection<int, ContentTag> */
    #[ORM\ManyToMany(targetEntity: ContentTag::class)]
    #[ORM\JoinTable(name: 'video_tags')]
    #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $tags;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function __construct(
        string $title,
        string $description,
        ?string $category,
        VideoSourceType $sourceType,
        string $sourceUrl,
        PublishingStatus $status,
        bool $featured,
        bool $commentsEnabled,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->tags = new ArrayCollection();

        $this->revise($title, $description, $category, $sourceType, $sourceUrl, $status, $featured, $commentsEnabled);
    }

    public function revise(
        string $title,
        string $description,
        ?string $category,
        VideoSourceType $sourceType,
        string $sourceUrl,
        PublishingStatus $status,
        bool $featured,
        bool $commentsEnabled,
    ): void {
        $sourceChanged = $this->sourceUrl !== (VideoSourceType::File === $sourceType ? '' : $sourceUrl)
            || $this->sourceType !== $sourceType;
        $this->title = $title;
        $this->description = $description;
        $this->category = $category;
        $this->sourceType = $sourceType;
        $this->sourceUrl = VideoSourceType::File === $sourceType ? '' : $sourceUrl;
        if (VideoSourceType::File !== $sourceType) {
            $this->uploadedObjectId = null;
            $this->uploadedMimeType = null;
            $this->uploadedSize = null;
        }
        $this->status = $status;
        $this->featured = $featured;
        $this->commentsEnabled = $commentsEnabled;
        $this->updatedAt = new \DateTimeImmutable();

        if ($sourceChanged) {
            $this->externalSourceStatus = null;
            $this->externalSourceCheckedAt = null;
            $this->externalSourceCheckFailed = false;
        }

        if (PublishingStatus::Published === $status) {
            $this->publishedAt ??= $this->updatedAt;
        } else {
            $this->publishedAt = null;
            $this->embedAllowedOrigins = [];
        }
    }

    public static function isSafeHttpsSource(string $url): bool
    {
        if ('' === trim($url)
            || mb_strlen($url) > 2048
            || str_contains($url, '\\')
            || 1 === preg_match('/[\x00-\x20\x7f]/', $url)
        ) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || 'https' !== strtolower($parts['scheme'] ?? '')
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && 443 !== $parts['port'])
        ) {
            return false;
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host)
            || false !== filter_var($host, FILTER_VALIDATE_IP)
            || false === filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        ) {
            return false;
        }

        return true;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /** @return list<array{key: string, value: string}> */
    public function getCustomFields(): array
    {
        return CustomFieldSet::normalize($this->customFields) ?? [];
    }

    /** @param list<array{key: string, value: string}> $fields */
    public function replaceCustomFields(array $fields): void
    {
        $normalized = CustomFieldSet::normalize($fields);
        if (null === $normalized) {
            throw new \InvalidArgumentException('Die benutzerdefinierten Videofelder sind ungültig.');
        }

        $this->customFields = $normalized;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function getSourceType(): VideoSourceType
    {
        return $this->sourceType;
    }

    public function getSourceUrl(): string
    {
        return $this->sourceUrl;
    }

    public function markExternalSourceStatus(ExternalVideoSourceStatus $status, \DateTimeImmutable $checkedAt): void
    {
        if (VideoSourceType::Embed !== $this->sourceType) {
            throw new \LogicException('Nur externe Einbettungsquellen können geprüft werden.');
        }

        $this->externalSourceStatus = $status;
        $this->externalSourceCheckedAt = $checkedAt;
        $this->externalSourceCheckFailed = false;
    }

    public function markExternalSourceCheckFailed(\DateTimeImmutable $attemptedAt): void
    {
        if (VideoSourceType::Embed !== $this->sourceType) {
            throw new \LogicException('Nur externe Einbettungsquellen können geprüft werden.');
        }

        $this->externalSourceCheckedAt = $attemptedAt;
        $this->externalSourceCheckFailed = true;
    }

    public function getExternalSourceStatus(): ?ExternalVideoSourceStatus
    {
        return $this->externalSourceStatus;
    }

    public function getExternalSourceCheckedAt(): ?\DateTimeImmutable
    {
        return $this->externalSourceCheckedAt;
    }

    public function isExternalSourceCheckFailed(): bool
    {
        return $this->externalSourceCheckFailed;
    }

    public function getUploadedObjectId(): ?string
    {
        return $this->uploadedObjectId;
    }

    public function getUploadedMimeType(): ?string
    {
        return $this->uploadedMimeType;
    }

    public function getUploadedSize(): ?int
    {
        return $this->uploadedSize;
    }

    public function isAudio(): bool
    {
        return VideoSourceType::File === $this->sourceType && 'audio/mpeg' === $this->uploadedMimeType;
    }

    public function attachUploadedMedia(string $objectId, string $mimeType, int $size): void
    {
        if (VideoSourceType::File !== $this->sourceType
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $objectId)
            || !in_array($mimeType, ['video/mp4', 'video/webm', 'video/ogg', 'audio/mpeg'], true)
            || $size < 1
            || $size > 33554432
        ) {
            throw new \InvalidArgumentException('Die private Medien-Datei ist ungültig.');
        }

        $this->uploadedObjectId = $objectId;
        $this->uploadedMimeType = $mimeType;
        $this->uploadedSize = $size;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getThumbnailObjectId(): ?string
    {
        return $this->thumbnailObjectId;
    }

    public function getThumbnailMimeType(): ?string
    {
        return $this->thumbnailMimeType;
    }

    public function getThumbnailSize(): ?int
    {
        return $this->thumbnailSize;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(?int $durationSeconds): void
    {
        if (null !== $durationSeconds && ($durationSeconds < 0 || $durationSeconds > 2147483647)) {
            throw new \InvalidArgumentException('Die Medienlaufzeit muss zwischen 0 und 2.147.483.647 Sekunden liegen.');
        }

        $this->durationSeconds = $durationSeconds;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function attachThumbnail(string $objectId, string $mimeType, int $size): void
    {
        if (1 !== preg_match('/\\A[a-f0-9]{64}\\z/', $objectId)
            || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $size < 1
            || $size > 4194304
        ) {
            throw new \InvalidArgumentException('Das private Video-Vorschaubild ist ungültig.');
        }

        $this->thumbnailObjectId = $objectId;
        $this->thumbnailMimeType = $mimeType;
        $this->thumbnailSize = $size;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function removeThumbnail(): ?string
    {
        $objectId = $this->thumbnailObjectId;
        $this->thumbnailObjectId = null;
        $this->thumbnailMimeType = null;
        $this->thumbnailSize = null;
        $this->updatedAt = new \DateTimeImmutable();

        return $objectId;
    }

    public function getSourceHost(): string
    {
        if (VideoSourceType::File === $this->sourceType) {
            return 'Datei-Upload';
        }

        $host = parse_url($this->sourceUrl, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    public function getStatus(): PublishingStatus
    {
        return $this->status;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setPublishingStatus(PublishingStatus $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        if (PublishingStatus::Published === $status) {
            $this->publishedAt ??= $this->updatedAt;
        } else {
            $this->publishedAt = null;
            $this->embedAllowedOrigins = [];
        }
    }

    /** @return list<mixed> */
    public function getEmbedAllowedOrigins(): array
    {
        return $this->embedAllowedOrigins;
    }

    /** @param list<string> $origins */
    public function setEmbedAllowedOrigins(array $origins): void
    {
        if (PublishingStatus::Published !== $this->status && [] !== $origins) {
            throw new \LogicException('Nur veröffentlichte Videos können extern freigegeben werden.');
        }

        $this->embedAllowedOrigins = array_values(array_unique($origins));
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isExternallyEmbeddable(): bool
    {
        return PublishingStatus::Published === $this->status
            && null !== $this->publishedAt
            && [] !== $this->embedAllowedOrigins;
    }

    public function setFeatured(bool $featured): void
    {
        $this->featured = $featured;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setCategory(?string $category): void
    {
        if (null !== $category) {
            $category = trim($category);
            if ('' === $category
                || mb_strlen($category, 'UTF-8') > 100
                || 1 !== preg_match('//u', $category)
                || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $category)
            ) {
                throw new \InvalidArgumentException('Die Videokategorie ist ungültig.');
            }
        }

        $this->category = $category;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isCommentsEnabled(): bool
    {
        return $this->commentsEnabled;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function getLastViewedAt(): ?\DateTimeImmutable
    {
        return $this->lastViewedAt;
    }

    public function markPubliclyViewedAt(\DateTimeImmutable $viewedAt): void
    {
        $this->lastViewedAt = $viewedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /** @return Collection<int, ContentTag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    /** @param list<ContentTag> $tags */
    public function replaceTags(array $tags): void
    {
        $this->tags->clear();
        foreach ($tags as $tag) {
            $this->tags->add($tag);
        }
    }

    public function getTagNames(): string
    {
        $names = [];
        foreach ($this->tags as $tag) {
            $names[] = $tag->getName();
        }

        return implode(', ', $names);
    }
}
