<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentTagRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentTagRepository::class)]
#[ORM\Table(name: 'content_tags')]
#[ORM\UniqueConstraint(name: 'uniq_content_tags_normalized_name', columns: ['normalized_name'])]
#[ORM\Index(name: 'idx_content_tags_name', columns: ['name'])]
final class ContentTag
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

    public function __construct(string $name)
    {
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
