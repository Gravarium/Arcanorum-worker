<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class YouTubeProviderAdapter implements ProviderAdapter
{
    public function __construct(private ProviderTransport $transport)
    {
    }

    public function provider(): MediaProvider
    {
        return MediaProvider::YouTube;
    }

    public function fetch(ImportSource $source, ?string $cursor, int $pageSize): ProviderPage
    {
        if (MediaProvider::YouTube !== $source->provider || $pageSize < 1 || $pageSize > 50) {
            throw new \InvalidArgumentException('The YouTube request is invalid.');
        }

        return match ($source->kind) {
            SourceKind::Search => $this->fetchSearch($source, $cursor, $pageSize),
            SourceKind::Creator => $this->fetchCreator($source, $cursor, $pageSize),
            SourceKind::Collection => $this->fetchCollection($source, $cursor, $pageSize),
        };
    }

    private function fetchSearch(ImportSource $source, ?string $cursor, int $pageSize): ProviderPage
    {
        $query = ['part' => 'snippet', 'q' => $source->reference, 'type' => 'video', 'maxResults' => $pageSize];
        if (null !== $cursor) {
            $query['pageToken'] = self::pageToken($cursor);
        }

        $payload = $this->request(ProviderEndpoint::YouTubeSearch, $query);

        return new ProviderPage($this->videos($payload, 'search'), self::nextPageToken($payload));
    }

    private function fetchCreator(ImportSource $source, ?string $cursor, int $pageSize): ProviderPage
    {
        if (null === $cursor) {
            $query = str_starts_with($source->reference, '@')
                ? ['part' => 'contentDetails', 'forHandle' => substr($source->reference, 1)]
                : ['part' => 'contentDetails', 'id' => $source->reference];
            $channelPayload = $this->request(ProviderEndpoint::YouTubeChannels, $query);
            $channels = self::rows($channelPayload, 'items');
            $channel = $channels[0] ?? null;
            $details = is_array($channel) ? ($channel['contentDetails'] ?? null) : null;
            $playlists = is_array($details) ? ($details['relatedPlaylists'] ?? null) : null;
            $playlistId = is_array($playlists) ? ($playlists['uploads'] ?? null) : null;
            if (!is_string($playlistId) || 1 !== preg_match('/\A[A-Za-z0-9_-]{10,64}\z/D', $playlistId)) {
                throw new ProviderException(ProviderError::NotFound);
            }
            $pageToken = null;
        } else {
            [$playlistId, $pageToken] = $this->decodeCreatorCursor($source, $cursor);
        }

        $query = [
            'part' => 'snippet,contentDetails',
            'playlistId' => $playlistId,
            'maxResults' => $pageSize,
        ];
        if (null !== $pageToken) {
            $query['pageToken'] = $pageToken;
        }
        $payload = $this->request(ProviderEndpoint::YouTubePlaylistItems, $query);
        $nextToken = self::nextPageToken($payload);

        return new ProviderPage(
            $this->videos($payload, 'playlist'),
            null === $nextToken ? null : $this->encodeCreatorCursor($source, $playlistId, $nextToken),
        );
    }

    private function fetchCollection(ImportSource $source, ?string $cursor, int $pageSize): ProviderPage
    {
        $query = [
            'part' => 'snippet,contentDetails',
            'playlistId' => $source->reference,
            'maxResults' => $pageSize,
        ];
        if (null !== $cursor) {
            $query['pageToken'] = self::pageToken($cursor);
        }
        $payload = $this->request(ProviderEndpoint::YouTubePlaylistItems, $query);

        return new ProviderPage($this->videos($payload, 'playlist'), self::nextPageToken($payload));
    }

    /**
     * @param array<string, int|string|bool> $query
     *
     * @return array<string, mixed>
     */
    private function request(ProviderEndpoint $endpoint, array $query): array
    {
        return $this->transport->send(new ProviderRequest($endpoint, $query))->json();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<VideoCandidate>
     */
    private function videos(array $payload, string $shape): array
    {
        $rows = self::rows($payload, 'items');
        $videos = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_array($row['snippet'] ?? null)) {
                throw new ProviderException(ProviderError::InvalidResponse);
            }

            $snippet = $row['snippet'];
            if ('search' === $shape) {
                $resource = $row['id'] ?? null;
                $videoId = is_array($resource) ? ($resource['videoId'] ?? null) : null;
            } else {
                $details = $row['contentDetails'] ?? null;
                $resource = $snippet['resourceId'] ?? null;
                $videoId = is_array($details)
                    ? ($details['videoId'] ?? null)
                    : (is_array($resource) ? ($resource['videoId'] ?? null) : null);
            }
            $description = $snippet['description'] ?? '';
            if (!is_string($videoId) || !is_string($snippet['title'] ?? null) || !is_string($description)) {
                throw new ProviderException(ProviderError::InvalidResponse);
            }

            try {
                $videos[] = new VideoCandidate(MediaProvider::YouTube, $videoId, $snippet['title'], $description);
            } catch (\InvalidArgumentException) {
                throw new ProviderException(ProviderError::InvalidResponse);
            }
        }

        return $videos;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<mixed>
     */
    private static function rows(array $payload, string $key): array
    {
        $rows = $payload[$key] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function nextPageToken(array $payload): ?string
    {
        $token = $payload['nextPageToken'] ?? null;
        if (null === $token || '' === $token) {
            return null;
        }
        if (!is_string($token) || strlen($token) > 256 || 1 === preg_match('/[\x00-\x20\x7F]/', $token)) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        return $token;
    }

    private static function pageToken(string $cursor): string
    {
        if (strlen($cursor) > 256 || 1 === preg_match('/[\x00-\x20\x7F]/', $cursor)) {
            throw new \InvalidArgumentException('The YouTube page cursor is invalid.');
        }

        return $cursor;
    }

    private function encodeCreatorCursor(ImportSource $source, string $playlistId, string $pageToken): string
    {
        try {
            $json = json_encode([$source->reference, $playlistId, $pageToken], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{string, string}
     */
    private function decodeCreatorCursor(ImportSource $source, string $cursor): array
    {
        if (strlen($cursor) > 1024 || 1 !== preg_match('/\A[A-Za-z0-9_-]+\z/D', $cursor)) {
            throw new \InvalidArgumentException('The YouTube creator cursor is invalid.');
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new \InvalidArgumentException('The YouTube creator cursor is invalid.');
        }

        try {
            $state = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('The YouTube creator cursor is invalid.');
        }

        if (!is_array($state)
            || 3 !== count($state)
            || ($state[0] ?? null) !== $source->reference
            || !is_string($state[1] ?? null)
            || 1 !== preg_match('/\A[A-Za-z0-9_-]{10,64}\z/D', $state[1])
            || !is_string($state[2] ?? null)) {
            throw new \InvalidArgumentException('The YouTube creator cursor is invalid.');
        }

        return [$state[1], self::pageToken($state[2])];
    }
}
