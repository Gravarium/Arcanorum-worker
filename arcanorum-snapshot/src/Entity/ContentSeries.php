<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentSeriesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentSeriesRepository::class)]
#[ORM\Table(name: 'content_series')]
#[ORM\UniqueConstraint(name: 'uniq_content_series_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_content_series_artwork_object_id', columns: ['artwork_object_id'])]
#[ORM\Index(name: 'idx_content_series_status_created', columns: ['status', 'created_at'])]
final class ContentSeries
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(length: 200)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    /** @var Collection<int, SeriesGenre> */
    #[ORM\ManyToMany(targetEntity: SeriesGenre::class)]
    #[ORM\JoinTable(name: 'content_series_genres')]
    #[ORM\JoinColumn(name: 'series_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'genre_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $genres;

    #[ORM\Column(name: 'artwork_object_id', length: 64, nullable: true)]
    private ?string $artworkObjectId = null;

    #[ORM\Column(name: 'artwork_mime_type', length: 32, nullable: true)]
    private ?string $artworkMimeType = null;

    #[ORM\Column(name: 'artwork_size', type: Types::INTEGER, nullable: true)]
    private ?int $artworkSize = null;

    #[ORM\Column(length: 16, enumType: PublishingStatus::class)]
    private PublishingStatus $status;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /**
     * @param list<SeriesGenre> $genres
     */
    public function __construct(
        string $title,
        string $slug,
        string $description,
        PublishingStatus $status,
        array $genres = [],
    ) {
        $this->id = bin2hex(random_bytes(16));
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->genres = new ArrayCollection();

        $this->revise($title, $slug, $description, $status, $genres);
    }

    /**
     * @param list<SeriesGenre> $genres
     */
    public function revise(
        string $title,
        string $slug,
        string $description,
        PublishingStatus $status,
        array $genres = [],
    ): void {
        $this->title = $title;
        $this->slug = $slug;
        $this->description = $description;
        $this->replaceGenres($genres);
        $this->status = $status;
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

    public function getDescription(): string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function getGenres(): array
    {
        $names = [];
        foreach ($this->genres as $genre) {
            $names[] = $genre->getName();
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /** @param list<SeriesGenre> $genres */
    public function replaceGenres(array $genres): void
    {
        if (count($genres) > 12) {
            throw new \InvalidArgumentException('Pro Serie sind höchstens 12 Genres erlaubt.');
        }
        $this->genres->clear();
        foreach ($genres as $genre) {
            if (!$this->genres->contains($genre)) {
                $this->genres->add($genre);
            }
        }
    }

    public function getArtworkObjectId(): ?string
    {
        return $this->artworkObjectId;
    }

    public function getArtworkMimeType(): ?string
    {
        return $this->artworkMimeType;
    }

    public function getArtworkSize(): ?int
    {
        return $this->artworkSize;
    }

    public function attachArtwork(string $objectId, string $mimeType, int $size): void
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/', $objectId)
            || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $size < 1
            || $size > 4194304
        ) {
            throw new \InvalidArgumentException('Das private Serienbild ist ungültig.');
        }

        $this->artworkObjectId = $objectId;
        $this->artworkMimeType = $mimeType;
        $this->artworkSize = $size;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function removeArtwork(): ?string
    {
        $objectId = $this->artworkObjectId;
        $this->artworkObjectId = null;
        $this->artworkMimeType = null;
        $this->artworkSize = null;
        $this->updatedAt = new \DateTimeImmutable();

        return $objectId;
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
