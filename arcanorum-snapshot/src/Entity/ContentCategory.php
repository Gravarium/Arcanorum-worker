<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentCategoryRepository::class)]
#[ORM\Table(name: 'content_categories')]
#[ORM\UniqueConstraint(
    name: 'uniq_content_categories_type_normalized_name',
    columns: ['content_type', 'normalized_name'],
)]
#[ORM\Index(name: 'idx_content_categories_type_name', columns: ['content_type', 'name'])]
final class ContentCategory
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(name: 'normalized_name', length: 100)]
    private string $normalizedName;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $name,
        #[ORM\Column(name: 'content_type', length: 16, enumType: ContentCategoryType::class)]
        private ContentCategoryType $contentType,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;

        $this->rename($name);
    }

    public function rename(string $name): void
    {
        $this->name = trim($name);
        $this->normalizedName = mb_strtolower($this->name);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function getContentType(): ContentCategoryType
    {
        return $this->contentType;
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
