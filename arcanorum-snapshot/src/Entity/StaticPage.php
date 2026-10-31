<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StaticPageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StaticPageRepository::class)]
#[ORM\Table(name: 'static_pages')]
#[ORM\UniqueConstraint(name: 'uniq_static_pages_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_static_pages_status_published', columns: ['status', 'published_at'])]
final class StaticPage
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(length: 200)]
    private string $slug;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $summary;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(name: 'meta_keywords', length: 500, nullable: true)]
    private ?string $metaKeywords = null;

    #[ORM\Column(name: 'meta_description', length: 500, nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column(name: 'author_name', length: 120, options: ['default' => 'Redaktion'])]
    private string $authorName = 'Redaktion';

    #[ORM\Column(name: 'show_in_menu', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $showInMenu = false;

    #[ORM\Column(length: 16, enumType: PublishingStatus::class)]
    private PublishingStatus $status;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function __construct(
        string $title,
        string $slug,
        ?string $summary,
        string $body,
        PublishingStatus $status,
        ?string $metaKeywords = null,
        ?string $metaDescription = null,
        string $authorName = 'Redaktion',
        bool $showInMenu = false,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;

        $this->revise($title, $slug, $summary, $body, $status, $metaKeywords, $metaDescription, $authorName, $showInMenu);
    }

    public function revise(
        string $title,
        string $slug,
        ?string $summary,
        string $body,
        PublishingStatus $status,
        ?string $metaKeywords = null,
        ?string $metaDescription = null,
        ?string $authorName = null,
        ?bool $showInMenu = null,
    ): void {
        $this->title = $title;
        $this->slug = $slug;
        $this->summary = $summary;
        $this->body = $body;
        $this->status = $status;
        $this->metaKeywords = $metaKeywords;
        $this->metaDescription = $metaDescription;
        if (null !== $authorName) {
            $this->authorName = $authorName;
        }
        if (null !== $showInMenu) {
            $this->showInMenu = $showInMenu;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getMetaKeywords(): ?string
    {
        return $this->metaKeywords;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function isShownInMenu(): bool
    {
        return $this->showInMenu;
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

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }
}
