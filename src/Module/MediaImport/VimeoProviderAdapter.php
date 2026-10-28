<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class VimeoProviderAdapter implements ProviderAdapter
{
    public function __construct(private ProviderTransport $transport)
    {
    }

    public function provider(): MediaProvider
    {
        return MediaProvider::Vimeo;
    }

    public function fetch(ImportSource $source, ?string $cursor, int $pageSize): ProviderPage
    {
        if (MediaProvider::Vimeo !== $source->provider || $pageSize < 1 || $pageSize > 100) {
            throw new \InvalidArgumentException('The Vimeo request is invalid.');
        }

        $page = self::pageNumber($cursor);
        $endpoint = match ($source->kind) {
            SourceKind::Search => ProviderEndpoint::VimeoSearch,
            SourceKind::Creator => ProviderEndpoint::VimeoUserVideos,
            SourceKind::Collection => ProviderEndpoint::VimeoAlbumVideos,
        };
        $resourceId = SourceKind::Search === $source->kind ? null : $source->reference;
        $query = [
            'per_page' => $pageSize,
            'page' => $page,
            'fields' => 'uri,name,description',
        ];
        if (SourceKind::Search === $source->kind) {
            $query['query'] = $source->reference;
        }

        $payload = $this->transport->send(new ProviderRequest($endpoint, $query, $resourceId))->json();
        $rows = $payload['data'] ?? null;
        $paging = $payload['paging'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || !is_array($paging)) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        $videos = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['uri'] ?? null) || !is_string($row['name'] ?? null)) {
                throw new ProviderException(ProviderError::InvalidResponse);
            }
            $description = $row['description'] ?? '';
            if (!is_string($description) || 1 !== preg_match('~\A/videos/([1-9][0-9]{0,14})\z~D', $row['uri'], $matches)) {
                throw new ProviderException(ProviderError::InvalidResponse);
            }

            try {
                $videos[] = new VideoCandidate(MediaProvider::Vimeo, $matches[1], $row['name'], $description);
            } catch (\InvalidArgumentException) {
                throw new ProviderException(ProviderError::InvalidResponse);
            }
        }

        $next = $paging['next'] ?? null;
        if (null !== $next && !is_string($next)) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        return new ProviderPage($videos, '' !== $next && null !== $next ? (string) ($page + 1) : null);
    }

    private static function pageNumber(?string $cursor): int
    {
        if (null === $cursor) {
            return 1;
        }
        if (1 !== preg_match('/\A[1-9][0-9]{0,2}\z/D', $cursor)) {
            throw new \InvalidArgumentException('The Vimeo page cursor is invalid.');
        }

        return (int) $cursor;
    }
}
