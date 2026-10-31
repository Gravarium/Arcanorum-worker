<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CommentFilterTermRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentFilterTermRepository::class)]
#[ORM\Table(name: 'comment_filter_terms')]
#[ORM\UniqueConstraint(name: 'uniq_comment_filter_terms_normalized_action', columns: ['normalized_term', 'action'])]
#[ORM\Index(name: 'idx_comment_filter_terms_created', columns: ['created_at'])]
final class CommentFilterTerm
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 100)]
        private string $term,
        #[ORM\Column(name: 'normalized_term', length: 100)]
        private string $normalizedTerm,
        #[ORM\Column(length: 16, enumType: CommentFilterAction::class, options: ['default' => 'review'])]
        private CommentFilterAction $action = CommentFilterAction::Review,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    public function getNormalizedTerm(): string
    {
        return $this->normalizedTerm;
    }

    public function getAction(): CommentFilterAction
    {
        return $this->action;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
