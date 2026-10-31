<?php

declare(strict_types=1);

namespace App\Service;

final readonly class CommentWordFilterResult
{
    public function __construct(
        private string $body,
        private bool $rejected,
        private ?string $matchedTerm,
    ) {
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isRejected(): bool
    {
        return $this->rejected;
    }

    public function getMatchedTerm(): ?string
    {
        return $this->matchedTerm;
    }
}
