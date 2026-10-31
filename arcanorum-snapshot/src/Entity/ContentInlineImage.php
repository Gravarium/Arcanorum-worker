<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentInlineImageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentInlineImageRepository::class)]
#[ORM\Table(name: 'content_inline_images')]
#[ORM\Index(name: 'idx_inline_images_news_owner', columns: ['news_article_id'])]
#[ORM\Index(name: 'idx_inline_images_page_owner', columns: ['static_page_id'])]
#[ORM\Index(name: 'idx_inline_images_category_owner', columns: ['category_id'])]
final class ContentInlineImage
{
    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: NewsArticle::class)]
    #[ORM\JoinColumn(name: 'news_article_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?NewsArticle $newsArticle;

    #[ORM\ManyToOne(targetEntity: StaticPage::class)]
    #[ORM\JoinColumn(name: 'static_page_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?StaticPage $staticPage;

    #[ORM\ManyToOne(targetEntity: ContentCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?ContentCategory $category;

    #[ORM\Column(name: 'mime_type', length: 32)]
    private string $mimeType;

    #[ORM\Column(type: Types::INTEGER)]
    private int $size;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        ?NewsArticle $newsArticle,
        ?StaticPage $staticPage,
        string $mimeType,
        int $size,
        string $sha256,
        ?ContentCategory $category = null,
    ) {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $id)) {
            throw new \InvalidArgumentException('Die Kennung des Inline-Bilds ist ungültig.');
        }
        $ownerCount = (null !== $newsArticle ? 1 : 0) + (null !== $staticPage ? 1 : 0) + (null !== $category ? 1 : 0);
        if (1 !== $ownerCount) {
            throw new \InvalidArgumentException('Ein Inline-Bild muss genau einem News-Beitrag, einer Seite oder einer Kategorie gehören.');
        }
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Das Bildformat des Inline-Bilds ist nicht zulässig.');
        }
        if ($size < 1 || $size > 4_194_304) {
            throw new \InvalidArgumentException('Die Dateigröße des Inline-Bilds ist ungültig.');
        }
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $sha256)) {
            throw new \InvalidArgumentException('Die Prüfsumme des Inline-Bilds ist ungültig.');
        }

        $this->id = $id;
        $this->newsArticle = $newsArticle;
        $this->staticPage = $staticPage;
        $this->category = $category;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->sha256 = $sha256;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getNewsArticle(): ?NewsArticle
    {
        return $this->newsArticle;
    }

    public function getStaticPage(): ?StaticPage
    {
        return $this->staticPage;
    }

    public function getCategory(): ?ContentCategory
    {
        return $this->category;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
