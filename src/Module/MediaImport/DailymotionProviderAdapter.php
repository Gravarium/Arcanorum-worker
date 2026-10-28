<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class DailymotionProviderAdapter implements ProviderAdapter
{
    public function __construct(private ProviderTransport $transport)
    {
    }

    public function provider(): MediaProvider
    {
        return MediaProvider::Dailymotion;
    }

    public function fetch(ImportSource $source, ?string $cursor, int $pageSize): ProviderPage
    {
        if (MediaProvider::Dailymotion !== $source->provider || $pageSize < 1 || $pageSize > 100) {
            throw new \InvalidArgumentException('The Dailymotion request is invalid.');
        }
        if (SourceKind::Search === $source->kind) {
            throw new ProviderException(ProviderError::Unsupported);
        }

        $page = self::pageNumber($cursor);
        $endpoint = SourceKind::Creator === $source->kind
            ? ProviderEndpoint::DailymotionProfileVideos
            : ProviderEndpoint::DailymotionPlaylistVideos;
        $payload = $this->transport->send(new ProviderRequest(
            $endpoint,
            ['page' => $page, 'page_size' => $pageSize, 'fields' => 'video_id,title,description'],
            $source->reference,
        ))->json();

        $rows = $payload['items'] ?? null;
        $hasMore = $payload['has_more'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || !is_bool($hasMore)) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        $videos = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || !is_string($row['video_id'] ?? null)
                || !is_string($row['title'] ?? null)
                || !is_string($row['description'] ?? '')) {
                throw new ProviderException(ProviderError::InvalidResponse);
            }

            try {
                $videos[] = new VideoCandidate(
                    MediaProvider::Dailymotion,
                    $row['video_id'],
                    $row['title'],
                    $row['description'] ?? '',
                );
            } catch (\InvalidArgumentException) {
                throw new ProviderException(ProviderError::InvalidResponse);
            }
        }

        return new ProviderPage($videos, $hasMore && $page < 10 ? (string) ($page + 1) : null);
    }

    private static function pageNumber(?string $cursor): int
    {
        if (null === $cursor) {
            return 1;
        }
        if (1 !== preg_match('/\A[1-9]\d?\z/D', $cursor) || (int) $cursor > 10) {
            throw new \InvalidArgumentException('The Dailymotion page cursor is invalid.');
        }

        return (int) $cursor;
    }
}
