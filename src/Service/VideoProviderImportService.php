<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;
use App\Platform\Security\StrictJsonObjectDecoder;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class VideoProviderImportService
{
    private const string YOUTUBE_API_ENDPOINT = 'https://www.googleapis.com/youtube/v3/search';
    private const string VIMEO_API_ENDPOINT = 'https://api.vimeo.com';
    private const int MAX_RESULTS = 25;
    private const int MAX_RESPONSE_BYTES = 1048576;
    private const int MAX_DESCRIPTION_LENGTH = 20000;

    /** @var array<string, string> */
    private const array PROVIDERS = [
        'youtube' => 'YouTube',
        'vimeo' => 'Vimeo',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private ExternalVideoEmbedResolver $embedResolver,
        private string $youtubeApiKey,
        private string $vimeoAccessToken = '',
    ) {
    }

    /** @return array<string, string> */
    public static function providers(): array
    {
        return self::PROVIDERS;
    }

    public static function isSupportedProvider(string $provider): bool
    {
        return array_key_exists($provider, self::PROVIDERS);
    }

    public static function providerLabel(string $provider): string
    {
        return self::PROVIDERS[$provider] ?? 'Videoanbieter';
    }

    public static function channelLabel(string $provider): string
    {
        return 'vimeo' === $provider ? 'Vimeo-Nutzer-ID' : 'YouTube-Kanal-ID';
    }

    public function isConfigured(string $provider): bool
    {
        return match ($provider) {
            'youtube' => 1 === preg_match('/\A[\x21-\x7E]{1,200}\z/D', $this->youtubeApiKey),
            'vimeo' => strlen($this->vimeoAccessToken) <= 512
                && 1 === preg_match('/\A[A-Za-z0-9\-._~+\/]+=*\z/D', $this->vimeoAccessToken),
            default => false,
        };
    }

    public static function isValidSearchTerm(string $query): bool
    {
        $query = trim($query);

        return mb_check_encoding($query, 'UTF-8')
            && 1 !== preg_match('/[\x00-\x1F\x7F]/u', $query)
            && mb_strlen($query, 'UTF-8') >= 2
            && mb_strlen($query, 'UTF-8') <= 100;
    }

    public static function isValidChannelId(string $provider, string $channelId): bool
    {
        return match ($provider) {
            'youtube' => 1 === preg_match('/\AUC[A-Za-z0-9_-]{22}\z/D', $channelId),
            'vimeo' => 1 === preg_match('/\A[1-9][0-9]{0,11}\z/D', $channelId),
            default => false,
        };
    }

    /**
     * @return list<array{title: string, description: string, source_url: string}>
     */
    public function search(string $provider, string $query, ?string $channelId = null): array
    {
        if (!self::isSupportedProvider($provider)) {
            throw new \InvalidArgumentException('Der Videoanbieter wird nicht unterstützt.');
        }
        if (!$this->isConfigured($provider)) {
            throw new VideoProviderImportException(sprintf('Der %s-Import ist nicht konfiguriert.', self::providerLabel($provider)));
        }

        $query = trim($query);
        if (null === $channelId && !self::isValidSearchTerm($query)) {
            throw new \InvalidArgumentException('Der Suchbegriff muss 2 bis 100 gültige Zeichen enthalten.');
        }
        if (null !== $channelId && !self::isValidChannelId($provider, $channelId)) {
            throw new \InvalidArgumentException(sprintf('Die %s ist ungültig.', self::channelLabel($provider)));
        }

        if ('youtube' === $provider) {
            $endpoint = self::YOUTUBE_API_ENDPOINT;
            $headers = [
                'Accept' => 'application/json',
                'X-Goog-Api-Key' => $this->youtubeApiKey,
            ];
            $parameters = [
                'part' => 'snippet',
                'type' => 'video',
                'maxResults' => self::MAX_RESULTS,
                'safeSearch' => 'strict',
                'videoEmbeddable' => 'true',
            ];
            if (null === $channelId) {
                $parameters['q'] = $query;
            } else {
                $parameters['channelId'] = $channelId;
            }
        } else {
            $endpoint = null === $channelId
                ? self::VIMEO_API_ENDPOINT.'/videos'
                : self::VIMEO_API_ENDPOINT.'/users/'.rawurlencode($channelId).'/videos';
            $headers = [
                'Accept' => 'application/vnd.vimeo.video+json',
                'Authorization' => 'Bearer '.$this->vimeoAccessToken,
            ];
            $parameters = [
                'per_page' => self::MAX_RESULTS,
                'filter' => 'embeddable',
            ];
            if (null === $channelId) {
                $parameters['query'] = $query;
            } else {
                $parameters['filter_embeddable'] = true;
            }
        }

        $response = null;
        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'headers' => $headers,
                'query' => $parameters,
                'max_redirects' => 0,
                'timeout' => 5,
                'max_duration' => 10,
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new VideoProviderImportException('Der Videoanbieter hat die Anfrage nicht erfolgreich beantwortet.');
            }

            $body = $this->readBody($response);
            if (null === StrictJsonObjectDecoder::decode($body, 32)) {
                throw new VideoProviderImportException('Der Videoanbieter hat ungültige oder mehrdeutige Daten geliefert.');
            }
            $payload = json_decode($body, false, 32, JSON_THROW_ON_ERROR);

            return $this->readItems($payload, $provider);
        } catch (TransportExceptionInterface $exception) {
            throw new VideoProviderImportException('Der Videoanbieter ist derzeit nicht erreichbar.', 0, $exception);
        } catch (\JsonException $exception) {
            throw new VideoProviderImportException('Der Videoanbieter hat ungültige Daten geliefert.', 0, $exception);
        } finally {
            if ($response instanceof ResponseInterface) {
                $response->cancel();
            }
        }
    }

    /**
     * @throws VideoProviderImportException
     */
    private function readBody(ResponseInterface $response): string
    {
        $body = '';
        foreach ($this->httpClient->stream($response, 10) as $chunk) {
            if ($chunk->isTimeout()) {
                throw new VideoProviderImportException('Der Videoanbieter hat die Antwort nicht rechtzeitig übertragen.');
            }

            $content = $chunk->getContent();
            if (strlen($body) + strlen($content) > self::MAX_RESPONSE_BYTES) {
                throw new VideoProviderImportException('Die Antwort des Videoanbieters ist zu groß.');
            }

            $body .= $content;
        }

        return $body;
    }

    /**
     * @return list<array{title: string, description: string, source_url: string}>
     */
    private function readItems(mixed $payload, string $provider): array
    {
        if (!$payload instanceof \stdClass) {
            throw new VideoProviderImportException('Der Videoanbieter hat eine ungültige Ergebnisliste geliefert.');
        }

        $items = 'youtube' === $provider ? ($payload->items ?? null) : ($payload->data ?? null);
        if (!is_array($items) || !array_is_list($items) || count($items) > self::MAX_RESULTS) {
            throw new VideoProviderImportException('Der Videoanbieter hat eine ungültige Ergebnisliste geliefert.');
        }

        $results = [];
        $seenIds = [];
        foreach ($items as $item) {
            if (!$item instanceof \stdClass) {
                throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
            }

            if ('youtube' === $provider) {
                $id = $item->id ?? null;
                $snippet = $item->snippet ?? null;
                if (!$id instanceof \stdClass || !$snippet instanceof \stdClass) {
                    throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
                }

                $videoId = $id->videoId ?? null;
                if (!is_string($videoId) || 1 !== preg_match('/\A[A-Za-z0-9_-]{11}\z/D', $videoId)) {
                    throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
                }
                $title = $snippet->title ?? null;
                $description = $snippet->description ?? '';
                $sourceUrl = 'https://www.youtube.com/watch?v='.$videoId;
            } else {
                $videoId = self::vimeoVideoId($item->uri ?? null);
                if (null === $videoId) {
                    throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
                }
                $title = $item->name ?? null;
                $description = $item->description ?? '';
                $sourceUrl = $this->canonicalVimeoSourceUrl($item->link ?? null, $videoId);
                if (null === $sourceUrl) {
                    throw new VideoProviderImportException('Der Videoanbieter hat eine ungültige Videoquelle geliefert.');
                }
            }

            if (isset($seenIds[$videoId])) {
                continue;
            }

            $metadata = self::normalizeMetadata($title, $description);
            if (null === $metadata) {
                throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
            }

            if (null === $this->embedResolver->resolve($sourceUrl)) {
                throw new VideoProviderImportException('Der Videoanbieter hat eine ungültige Videoquelle geliefert.');
            }

            $seenIds[$videoId] = true;
            $results[] = [
                'title' => $metadata['title'],
                'description' => $metadata['description'],
                'source_url' => $sourceUrl,
            ];
        }

        return $results;
    }

    /** @return array{title: string, description: string}|null */
    private static function normalizeMetadata(mixed $title, mixed $description): ?array
    {
        $description ??= '';
        if (!is_string($title)
            || !is_string($description)
            || !mb_check_encoding($title, 'UTF-8')
            || !mb_check_encoding($description, 'UTF-8')
        ) {
            return null;
        }

        $title = trim($title);
        if ('' === $title
            || mb_strlen($title, 'UTF-8') > 180
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $title)
        ) {
            return null;
        }

        $description = trim($description);
        $description = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $description);
        if (!is_string($description)) {
            return null;
        }
        if (mb_strlen($description, 'UTF-8') > self::MAX_DESCRIPTION_LENGTH) {
            $description = mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH, 'UTF-8');
        }

        return [
            'title' => $title,
            'description' => $description,
        ];
    }

    private function canonicalVimeoSourceUrl(mixed $link, string $videoId): ?string
    {
        if (!is_string($link) || !Video::isSafeHttpsSource($link)) {
            return null;
        }

        $parts = parse_url($link);
        if (!is_array($parts)) {
            return null;
        }

        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? null;
        if (!is_string($host)
            || !in_array(strtolower($host), ['vimeo.com', 'www.vimeo.com'], true)
            || !is_string($path)
            || 1 !== preg_match('~\A/'.preg_quote($videoId, '~').'/?\z~D', $path)
        ) {
            return null;
        }

        $privacyHashes = self::queryValues($parts['query'] ?? '', 'h');
        if (count($privacyHashes) > 1) {
            return null;
        }

        $sourceUrl = 'https://vimeo.com/'.$videoId;
        if ([] !== $privacyHashes) {
            if (1 !== preg_match('/\A[A-Za-z0-9]{1,64}\z/D', $privacyHashes[0])) {
                return null;
            }
            $sourceUrl .= '?h='.$privacyHashes[0];
        }

        return null !== $this->embedResolver->resolve($sourceUrl) ? $sourceUrl : null;
    }

    private static function vimeoVideoId(mixed $uri): ?string
    {
        if (!is_string($uri) || 1 !== preg_match('/\A\/videos\/([0-9]{1,12})\z/D', $uri, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /** @return list<string> */
    private static function queryValues(string $query, string $key): array
    {
        if ('' === $query) {
            return [];
        }

        $values = [];
        foreach (explode('&', $query) as $pair) {
            $parts = explode('=', $pair, 2);
            if ($key === urldecode($parts[0])) {
                $values[] = urldecode($parts[1] ?? '');
            }
        }

        return $values;
    }
}
