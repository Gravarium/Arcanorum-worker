<?php

declare(strict_types=1);

namespace App\Entity;

use App\Content\CustomFieldSet;
use App\Repository\NewsArticleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NewsArticleRepository::class)]
#[ORM\Table(name: 'news_articles')]
#[ORM\UniqueConstraint(name: 'uniq_news_articles_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_news_articles_thumbnail_object_id', columns: ['thumbnail_object_id'])]
#[ORM\Index(name: 'idx_news_articles_status_created', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_news_articles_status_published', columns: ['status', 'published_at'])]
final class NewsArticle
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(length: 200)]
    private string $slug;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $summary;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(name: 'author_name', length: 120, options: ['default' => 'Redaktion'])]
    private string $authorName = 'Redaktion';

    #[ORM\Column(length: 16, enumType: PublishingStatus::class)]
    private PublishingStatus $status;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $featured = false;

    #[ORM\Column(name: 'comments_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $commentsEnabled = true;

    #[ORM\Column(name: 'members_only', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $membersOnly = false;

    #[ORM\Column(name: 'thumbnail_object_id', length: 64, nullable: true)]
    private ?string $thumbnailObjectId = null;

    #[ORM\Column(name: 'thumbnail_mime_type', length: 32, nullable: true)]
    private ?string $thumbnailMimeType = null;

    #[ORM\Column(name: 'thumbnail_size', type: Types::INTEGER, nullable: true)]
    private ?int $thumbnailSize = null;

    /** @var list<array{key: string, value: string}> */
    #[ORM\Column(name: 'custom_fields', type: Types::JSON, options: ['default' => '[]'])]
    private array $customFields = [];

    /** @var Collection<int, ContentTag> */
    #[ORM\ManyToMany(targetEntity: ContentTag::class)]
    #[ORM\JoinTable(name: 'news_article_tags')]
    #[ORM\JoinColumn(name: 'article_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
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
        string $slug,
        ?string $category,
        ?string $summary,
        string $body,
        PublishingStatus $status,
        bool $featured,
        bool $commentsEnabled,
        string $authorName = 'Redaktion',
        bool $membersOnly = false,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->tags = new ArrayCollection();

        $this->revise($title, $slug, $category, $summary, $body, $status, $featured, $commentsEnabled, $authorName, $membersOnly);
    }

    public function revise(
        string $title,
        string $slug,
        ?string $category,
        ?string $summary,
        string $body,
        PublishingStatus $status,
        bool $featured,
        bool $commentsEnabled,
        ?string $authorName = null,
        ?bool $membersOnly = null,
    ): void {
        $validatedAuthorName = null === $authorName ? null : self::validatedAuthorName($authorName);

        $this->title = $title;
        $this->slug = $slug;
        $this->category = $category;
        $this->summary = $summary;
        $this->body = $body;
        $this->status = $status;
        $this->featured = $featured;
        $this->commentsEnabled = $commentsEnabled;
        if (null !== $membersOnly) {
            $this->membersOnly = $membersOnly;
        }
        if (null !== $validatedAuthorName) {
            $this->authorName = $validatedAuthorName;
        }
        $this->updatedAt = new \DateTimeImmutable();

        if (PublishingStatus::Published === $status) {
            $this->publishedAt ??= $this->updatedAt;
        } else {
            $this->publishedAt = null;
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    private static function validatedAuthorName(string $authorName): string
    {
        if (false === preg_match('//u', $authorName)) {
            throw new \InvalidArgumentException('Die Autorenangabe ist ungültig.');
        }

        $authorName = trim($authorName);
        if ('' === $authorName
            || mb_strlen($authorName, 'UTF-8') > 120
            || 1 === preg_match('/\p{Cc}/u', $authorName)
        ) {
            throw new \InvalidArgumentException('Die Autorenangabe ist ungültig.');
        }

        return $authorName;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getBody(): string
    {
        return $this->body;
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
            throw new \InvalidArgumentException('Die benutzerdefinierten News-Felder sind ungültig.');
        }

        $this->customFields = $normalized;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
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
        }
    }

    public function setPublicationAt(?\DateTimeImmutable $publicationAt): void
    {
        if (PublishingStatus::Published !== $this->status) {
            $this->publishedAt = null;

            return;
        }

        $this->publishedAt = $publicationAt ?? $this->updatedAt;
    }

    public function setFeatured(bool $featured): void
    {
        $this->featured = $featured;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isCommentsEnabled(): bool
    {
        return $this->commentsEnabled;
    }

    public function isMembersOnly(): bool
    {
        return $this->membersOnly;
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

    public function attachThumbnail(string $objectId, string $mimeType, int $size): void
    {
        if (1 !== preg_match('/\\A[a-f0-9]{64}\\z/D', $objectId)
            || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $size < 1
            || $size > 4194304
        ) {
            throw new \InvalidArgumentException('Das News-Vorschaubild ist ungültig.');
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
