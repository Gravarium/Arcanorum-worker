<?php

declare(strict_types=1);

namespace App\Entity;

use App\Content\CustomFieldSet;
use App\Repository\NewsArticleRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NewsArticleRevisionRepository::class)]
#[ORM\Table(name: 'news_article_revisions')]
#[ORM\UniqueConstraint(name: 'uniq_news_article_revisions_version', columns: ['article_id', 'version'])]
final class NewsArticleRevision
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(type: Types::INTEGER)]
    private int $version;

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

    #[ORM\Column(length: 16, enumType: PublishingStatus::class)]
    private PublishingStatus $status;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $featured;

    #[ORM\Column(name: 'comments_enabled', type: Types::BOOLEAN)]
    private bool $commentsEnabled;

    #[ORM\Column(name: 'members_only', type: Types::BOOLEAN)]
    private bool $membersOnly;

    #[ORM\Column(name: 'thumbnail_object_id', length: 64, nullable: true)]
    private ?string $thumbnailObjectId;

    #[ORM\Column(name: 'thumbnail_mime_type', length: 32, nullable: true)]
    private ?string $thumbnailMimeType;

    #[ORM\Column(name: 'thumbnail_size', type: Types::INTEGER, nullable: true)]
    private ?int $thumbnailSize;

    #[ORM\Column(name: 'author_name', length: 120)]
    private string $authorName;

    #[ORM\Column(name: 'tag_names', type: Types::TEXT)]
    private string $tagNames;

    /** @var list<array{key: string, value: string}> */
    #[ORM\Column(name: 'custom_fields', type: Types::JSON, options: ['default' => '[]'])]
    private array $customFields;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: NewsArticle::class)]
        #[ORM\JoinColumn(name: 'article_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private NewsArticle $article,
        int $version,
        #[ORM\ManyToOne(targetEntity: AdminUser::class)]
        #[ORM\JoinColumn(name: 'editor_admin_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
        private ?AdminUser $editor,
    ) {
        if ($version < 1) {
            throw new \InvalidArgumentException('Die Versionsnummer muss positiv sein.');
        }
        if (null === $editor) {
            throw new \InvalidArgumentException('Eine News-Version benötigt beim Erstellen eine verantwortliche Person.');
        }

        $this->id = bin2hex(random_bytes(16));
        $this->version = $version;
        $this->title = $article->getTitle();
        $this->slug = $article->getSlug();
        $this->category = $article->getCategory();
        $this->summary = $article->getSummary();
        $this->body = $article->getBody();
        $this->status = $article->getStatus();
        $this->featured = $article->isFeatured();
        $this->commentsEnabled = $article->isCommentsEnabled();
        $this->membersOnly = $article->isMembersOnly();
        $this->thumbnailObjectId = $article->getThumbnailObjectId();
        $this->thumbnailMimeType = $article->getThumbnailMimeType();
        $this->thumbnailSize = $article->getThumbnailSize();
        $this->authorName = $article->getAuthorName();
        $this->tagNames = self::canonicalTagNames($article);
        $this->customFields = $article->getCustomFields();
        $this->publishedAt = $article->getPublishedAt();
        $this->createdAt = $article->getUpdatedAt();
    }

    public function matches(NewsArticle $article): bool
    {
        return $this->title === $article->getTitle()
            && $this->slug === $article->getSlug()
            && $this->category === $article->getCategory()
            && $this->summary === $article->getSummary()
            && $this->body === $article->getBody()
            && $this->status === $article->getStatus()
            && $this->featured === $article->isFeatured()
            && $this->commentsEnabled === $article->isCommentsEnabled()
            && $this->membersOnly === $article->isMembersOnly()
            && $this->thumbnailObjectId === $article->getThumbnailObjectId()
            && $this->thumbnailMimeType === $article->getThumbnailMimeType()
            && $this->thumbnailSize === $article->getThumbnailSize()
            && $this->authorName === $article->getAuthorName()
            && $this->tagNames === self::canonicalTagNames($article)
            && $this->customFields === $article->getCustomFields()
            && ($this->publishedAt?->format('Y-m-d H:i:s.u') ?? null)
                === ($article->getPublishedAt()?->format('Y-m-d H:i:s.u') ?? null);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getArticle(): NewsArticle
    {
        return $this->article;
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

    public function getStatus(): PublishingStatus
    {
        return $this->status;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
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

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function getTagNames(): string
    {
        return $this->tagNames;
    }

    /** @return list<array{key: string, value: string}> */
    public function getCustomFields(): array
    {
        return CustomFieldSet::normalize($this->customFields) ?? [];
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getEditor(): ?AdminUser
    {
        return $this->editor;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private static function canonicalTagNames(NewsArticle $article): string
    {
        $tags = $article->getTags()->toArray();
        usort($tags, static fn (ContentTag $left, ContentTag $right): int => strcmp(
            $left->getNormalizedName(),
            $right->getNormalizedName(),
        ));

        return implode(', ', array_map(static fn (ContentTag $tag): string => $tag->getName(), $tags));
    }
}
