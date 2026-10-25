<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NewsArticleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NewsArticleRepository::class)]
#[ORM\Table(name: 'news_articles')]
#[ORM\UniqueConstraint(name: 'uniq_news_articles_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_news_articles_status_created', columns: ['status', 'created_at'])]
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

    #[ORM\Column(length: 16, enumType: PublishingStatus::class)]
    private PublishingStatus $status;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $featured = false;

    #[ORM\Column(name: 'comments_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $commentsEnabled = true;

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
    ) {
        $this->id = bin2hex(random_bytes(16));
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->tags = new ArrayCollection();

        $this->revise($title, $slug, $category, $summary, $body, $status, $featured, $commentsEnabled);
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
    ): void {
        $this->title = $title;
        $this->slug = $slug;
        $this->category = $category;
        $this->summary = $summary;
        $this->body = $body;
        $this->status = $status;
        $this->featured = $featured;
        $this->commentsEnabled = $commentsEnabled;
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

    public function setFeatured(bool $featured): void
    {
        $this->featured = $featured;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isCommentsEnabled(): bool
    {
        return $this->commentsEnabled;
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
