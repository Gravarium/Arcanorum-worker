<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SeriesGenreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeriesGenreRepository::class)]
#[ORM\Table(name: 'series_genres')]
#[ORM\UniqueConstraint(name: 'uniq_series_genres_normalized_name', columns: ['normalized_name'])]
final class SeriesGenre
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 40)]
    private string $name;

    #[ORM\Column(name: 'normalized_name', length: 160)]
    private string $normalizedName;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name)
    {
        $this->id = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->rename($name);
    }

    public function rename(string $name): void
    {
        $names = \App\Service\SeriesGenreParser::parseNames($name);
        if (1 !== count($names)) {
            throw new \InvalidArgumentException('Bitte genau einen gültigen Genrenamen angeben.');
        }
        $this->name = $names[0];
        $this->normalizedName = mb_strtolower($this->name, 'UTF-8');
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
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
