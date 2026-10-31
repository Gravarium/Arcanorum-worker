<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[ORM\Entity(repositoryClass: ContentCategoryRepository::class)]
#[ORM\Table(name: 'content_categories')]
#[ORM\UniqueConstraint(
    name: 'uniq_content_categories_type_normalized_name',
    columns: ['content_type', 'normalized_name'],
)]
#[ORM\UniqueConstraint(name: 'uniq_content_categories_type_slug', columns: ['content_type', 'slug'])]
#[ORM\Index(name: 'idx_content_categories_type_name', columns: ['content_type', 'name'])]
#[ORM\Index(name: 'idx_content_categories_parent_order', columns: ['parent_id', 'sort_order', 'name'])]
final class ContentCategory
{
    /** Root categories are depth zero, so 7 edges allow exactly eight category levels. */
    public const int MAX_DEPTH = 7;

    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(name: 'normalized_name', length: 100)]
    private string $normalizedName;

    #[ORM\Column(length: 120)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $description = '';

    #[ORM\Column(name: 'meta_title', length: 160, nullable: true)]
    private ?string $metaTitle = null;

    #[ORM\Column(name: 'meta_description', length: 500, nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column(name: 'meta_keywords', length: 255, nullable: true)]
    private ?string $metaKeywords = null;

    #[ORM\Column(name: 'sort_order', type: Types::SMALLINT, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_featured', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $featured = false;

    #[ORM\Column(name: 'artwork_object_id', length: 64, nullable: true)]
    private ?string $artworkObjectId = null;

    #[ORM\Column(name: 'artwork_mime_type', length: 32, nullable: true)]
    private ?string $artworkMimeType = null;

    #[ORM\Column(name: 'artwork_size', type: Types::INTEGER, nullable: true)]
    private ?int $artworkSize = null;

    #[ORM\Column(name: 'artwork_sha256', length: 64, nullable: true)]
    private ?string $artworkSha256 = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?self $parent = null;

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
        $this->slug = self::slugFromName($this->name);
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ('' === $name
            || 1 !== preg_match('//u', $name)
            || mb_strlen($name, 'UTF-8') > 100
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $name)
        ) {
            throw new \InvalidArgumentException('Der Kategoriename ist ungültig.');
        }

        $this->name = $name;
        $this->normalizedName = mb_strtolower($this->name);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function slugFromName(string $name): string
    {
        if (1 !== preg_match('//u', $name)) {
            throw new \InvalidArgumentException('Der Kategoriename muss gültiges UTF-8 enthalten.');
        }

        $slug = new AsciiSlugger('en')->slug(trim($name))->lower()->toString();

        $slug = rtrim(mb_substr($slug, 0, 120), '-');

        return '' === $slug ? 'category' : $slug;
    }

    public function updateTaxonomy(
        string $slug,
        string $description,
        ?string $metaTitle,
        ?string $metaDescription,
        ?string $metaKeywords,
        int $sortOrder,
    ): void {
        $slug = trim($slug);
        $description = trim($description);
        $metaTitle = self::nullableText($metaTitle);
        $metaDescription = self::nullableText($metaDescription);
        $metaKeywords = self::nullableText($metaKeywords);

        if (1 !== preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug)
            || mb_strlen($slug) > 120
            || self::hasInvalidText($description)
            || mb_strlen($description) > 2000
            || (null !== $metaTitle && self::hasInvalidText($metaTitle))
            || (null !== $metaTitle && mb_strlen($metaTitle) > 160)
            || (null !== $metaDescription && self::hasInvalidText($metaDescription))
            || (null !== $metaDescription && mb_strlen($metaDescription) > 500)
            || (null !== $metaKeywords && self::hasInvalidText($metaKeywords))
            || (null !== $metaKeywords && mb_strlen($metaKeywords) > 255)
            || $sortOrder < 0
            || $sortOrder > 10000
        ) {
            throw new \InvalidArgumentException('Die Kategorien-Metadaten sind ungültig.');
        }

        $this->slug = $slug;
        $this->description = $description;
        $this->metaTitle = $metaTitle;
        $this->metaDescription = $metaDescription;
        $this->metaKeywords = $metaKeywords;
        $this->sortOrder = $sortOrder;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function canUseAsParent(?self $parent): bool
    {
        if (null === $parent) {
            return true;
        }

        if ($parent->contentType !== $this->contentType || $parent->id === $this->id) {
            return false;
        }

        $seen = [];
        for ($ancestor = $parent; null !== $ancestor; $ancestor = $ancestor->parent) {
            if ($ancestor->id === $this->id || isset($seen[$ancestor->id]) || count($seen) >= self::MAX_DEPTH) {
                return false;
            }

            $seen[$ancestor->id] = true;
        }

        return true;
    }

    public function setParent(?self $parent): void
    {
        if (!$this->canUseAsParent($parent)) {
            throw new \InvalidArgumentException('Die übergeordnete Kategorie ist ungültig.');
        }

        $this->parent = $parent;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getDepth(): int
    {
        $depth = 0;
        $seen = [];
        for ($ancestor = $this->parent; null !== $ancestor; $ancestor = $ancestor->parent) {
            if (isset($seen[$ancestor->id]) || count($seen) >= self::MAX_DEPTH) {
                return self::MAX_DEPTH;
            }

            $seen[$ancestor->id] = true;
            ++$depth;
        }

        return $depth;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function getMetaKeywords(): ?string
    {
        return $this->metaKeywords;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): void
    {
        if ($featured && ContentCategoryType::Video !== $this->contentType) {
            throw new \InvalidArgumentException('Nur Videokategorien können auf der Startseite hervorgehoben werden.');
        }

        $this->featured = $featured;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function attachArtwork(string $objectId, string $mimeType, int $size, string $sha256): void
    {
        if (ContentCategoryType::Video !== $this->contentType
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)
            || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $size < 1
            || $size > 1048576
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $sha256)
        ) {
            throw new \InvalidArgumentException('Die Daten des Kategorie-Artworks sind ungültig.');
        }

        $this->artworkObjectId = $objectId;
        $this->artworkMimeType = $mimeType;
        $this->artworkSize = $size;
        $this->artworkSha256 = $sha256;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function removeArtwork(): void
    {
        $this->artworkObjectId = null;
        $this->artworkMimeType = null;
        $this->artworkSize = null;
        $this->artworkSha256 = null;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getArtworkSha256(): ?string
    {
        return $this->artworkSha256;
    }

    public function getParent(): ?self
    {
        return $this->parent;
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

    private static function nullableText(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }

    private static function hasInvalidText(string $value): bool
    {
        return 1 !== preg_match('//u', $value)
            || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value);
    }
}
