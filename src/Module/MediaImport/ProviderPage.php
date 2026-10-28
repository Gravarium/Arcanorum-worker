<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class ProviderPage
{
    /**
     * @param list<VideoCandidate> $videos
     */
    public function __construct(
        public array $videos,
        public ?string $nextCursor,
    ) {
        if (null !== $nextCursor && ('' === $nextCursor || strlen($nextCursor) > 1024 || 1 === preg_match('/[\x00-\x1F\x7F]/', $nextCursor))) {
            throw new \InvalidArgumentException('The provider cursor is invalid.');
        }
    }
}
