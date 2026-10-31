<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;
use App\Entity\VideoThumbnailResolution;
use App\Platform\Security\StrictJsonObjectDecoder;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class VideoProviderImportService
{
    private const string YOUTUBE_API_ENDPOINT = 'https://www.googleapis.com/youtube/v3/search';
    private const string YOUTUBE_PLAYLIST_ITEMS_ENDPOINT = 'https://www.googleapis.com/youtube/v3/playlistItems';
    private const string YOUTUBE_VIDEOS_ENDPOINT = 'https://www.googleapis.com/youtube/v3/videos';
    private const string YOUTUBE_OAUTH_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const string VIMEO_API_ENDPOINT = 'https://api.vimeo.com';
    private const string DAILYMOTION_SEARCH_ENDPOINT = 'https://api.dailymotion.com/videos';
    private const string DAILYMOTION_PLATFORM_ENDPOINT = 'https://api.dailymotion.com';
    private const string DAILYMOTION_API_ENDPOINT = 'https://api.dailymotion.com/v2';
    private const string DAILYMOTION_TOKEN_ENDPOINT = 'https://oauth2.dailymotion.com/v2/token';
    private const int MAX_RESULTS = 25;
    private const int MAX_RESPONSE_BYTES = 1048576;
    private const int MAX_DESCRIPTION_LENGTH = 20000;

    private ProviderThumbnailUrlPolicy $thumbnailUrlPolicy;

    /** @var array<string, string> */
    private const array PROVIDERS = [
        'youtube' => 'YouTube',
        'vimeo' => 'Vimeo',
        'dailymotion' => 'Dailymotion',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private ExternalVideoEmbedResolver $embedResolver,
        private string $youtubeApiKey,
        private string $vimeoAccessToken = '',
        private string $dailymotionClientId = '',
        private string $dailymotionClientSecret = '',
        private string $youtubeOAuthClientId = '',
        private string $youtubeOAuthClientSecret = '',
        private string $youtubeOAuthRefreshToken = '',
        ?ProviderThumbnailUrlPolicy $thumbnailUrlPolicy = null,
    ) {
        $this->thumbnailUrlPolicy = $thumbnailUrlPolicy ?? new ProviderThumbnailUrlPolicy();
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
        return match ($provider) {
            'vimeo' => 'Vimeo-Nutzer-ID',
            'dailymotion' => 'Dailymotion-Profil-ID',
            default => 'YouTube-Kanal-ID',
        };
    }

    public function isConfigured(string $provider): bool
    {
        return match ($provider) {
            'youtube' => 1 === preg_match('/\A[\x21-\x7E]{1,200}\z/D', $this->youtubeApiKey),
            'vimeo' => strlen($this->vimeoAccessToken) <= 512
                && 1 === preg_match('/\A[A-Za-z0-9\-._~+\/]+=*\z/D', $this->vimeoAccessToken),
            'dailymotion' => $this->embedResolver->isDailymotionConfigured(),
            default => false,
        };
    }

    public function isConfiguredForMode(string $provider, string $mode): bool
    {
        if (!self::isSupportedProvider($provider)) {
            return false;
        }

        if ('favorites' === $mode) {
            return match ($provider) {
                'youtube' => self::isValidCredential($this->youtubeOAuthClientId)
                    && self::isValidCredential($this->youtubeOAuthClientSecret)
                    && self::isValidCredential($this->youtubeOAuthRefreshToken),
                'vimeo', 'dailymotion' => $this->isConfigured($provider),
                default => false,
            };
        }

        if (!$this->isConfigured($provider)) {
            return false;
        }

        if (!in_array($mode, ['search', 'channel', 'playlist'], true)) {
            return false;
        }

        return !('dailymotion' === $provider && in_array($mode, ['channel', 'playlist'], true))
            || (self::isValidCredential($this->dailymotionClientId)
                && self::isValidCredential($this->dailymotionClientSecret));
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
            'dailymotion' => 1 === preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $channelId),
            default => false,
        };
    }

    public static function isValidPlaylistId(string $provider, string $playlistId): bool
    {
        return match ($provider) {
            'youtube' => 1 === preg_match('/\A[A-Za-z0-9_-]{10,64}\z/D', $playlistId),
            'vimeo' => 1 === preg_match('/\A[1-9][0-9]{0,11}\z/D', $playlistId),
            'dailymotion' => 1 === preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $playlistId),
            default => false,
        };
    }

    /**
     * @return list<array{title: string, description: string, source_url: string, thumbnail_url?: string}>
     */
    public function search(
        string $provider,
        string $query,
        ?string $channelId = null,
        VideoThumbnailResolution $thumbnailResolution = VideoThumbnailResolution::Medium,
    ): array {
        if (!self::isSupportedProvider($provider)) {
            throw new \InvalidArgumentException('Der Videoanbieter wird nicht unterstützt.');
        }
        $query = trim($query);
        if (null === $channelId && !self::isValidSearchTerm($query)) {
            throw new \InvalidArgumentException('Der Suchbegriff muss 2 bis 100 gültige Zeichen enthalten.');
        }
        if (null !== $channelId && !self::isValidChannelId($provider, $channelId)) {
            throw new \InvalidArgumentException(sprintf('Die %s ist ungültig.', self::channelLabel($provider)));
        }
        if (!$this->isConfiguredForMode($provider, null === $channelId ? 'search' : 'channel')) {
            throw new VideoProviderImportException(sprintf('Der %s-Import ist nicht konfiguriert.', self::providerLabel($provider)));
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
        } elseif ('vimeo' === $provider) {
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
                'fields' => 'uri,name,description,link,privacy.view,pictures.sizes',
            ];
            if (null === $channelId) {
                $parameters['query'] = $query;
            } else {
                $parameters['filter_embeddable'] = true;
            }
        } elseif (null === $channelId) {
            $endpoint = self::DAILYMOTION_SEARCH_ENDPOINT;
            $headers = ['Accept' => 'application/json'];
            $parameters = [
                'search' => $query,
                'limit' => self::MAX_RESULTS,
                'fields' => 'id,title,description,url,allow_embed,thumbnail_url,thumbnail_120_url,thumbnail_180_url,thumbnail_240_url,thumbnail_360_url,thumbnail_480_url,thumbnail_720_url,thumbnail_1080_url',
            ];
        } else {
            $token = $this->dailymotionAccessToken();
            $endpoint = self::DAILYMOTION_API_ENDPOINT.'/profiles/'.rawurlencode($channelId).'/videos';
            $headers = [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ];
            $parameters = [
                'page' => 1,
                'page_size' => self::MAX_RESULTS,
                'fields' => 'video_id,title,description,video_url,visibility,embedding.enable_embed,thumbnail_url,thumbnail_120_url,thumbnail_180_url,thumbnail_240_url,thumbnail_360_url,thumbnail_480_url,thumbnail_720_url,thumbnail_1080_url',
            ];
        }

        $payload = $this->requestJson('GET', $endpoint, [
            'headers' => $headers,
            'query' => $parameters,
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 10,
        ]);

        return $this->readItems($payload, $provider, $thumbnailResolution, 'dailymotion' === $provider && null !== $channelId);
    }

    /**
     * Prüft eine gespeicherte Einbettungsquelle ausschließlich über die festen Provider-APIs.
     * `null` bedeutet, dass die URL keinem unterstützten Provider zugeordnet werden kann.
     */
    public function checkSourceAvailability(string $sourceUrl): ?bool
    {
        $source = $this->providerSourceIdentity($sourceUrl);
        if (null === $source) {
            return null;
        }

        if (!$this->isConfigured($source['provider'])) {
            throw new VideoProviderImportException('Die Providerprüfung ist nicht konfiguriert.');
        }

        if ('youtube' === $source['provider']) {
            $payload = $this->requestJson('GET', self::YOUTUBE_VIDEOS_ENDPOINT, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Goog-Api-Key' => $this->youtubeApiKey,
                ],
                'query' => [
                    'part' => 'status',
                    'id' => $source['id'],
                    'maxResults' => 1,
                ],
                'max_redirects' => 0,
                'timeout' => 5,
                'max_duration' => 10,
            ]);
            $items = $payload->items ?? null;
            if (!is_array($items) || !array_is_list($items) || count($items) > 1) {
                throw new VideoProviderImportException('YouTube hat eine ungültige Statusantwort geliefert.');
            }
            if ([] === $items) {
                return false;
            }

            $item = $items[0];
            $status = $item instanceof \stdClass ? ($item->status ?? null) : null;
            if (!$item instanceof \stdClass
                || ($item->id ?? null) !== $source['id']
                || !$status instanceof \stdClass
                || !is_string($status->privacyStatus ?? null)
                || !is_bool($status->embeddable ?? null)
            ) {
                throw new VideoProviderImportException('YouTube hat eine unvollständige Statusantwort geliefert.');
            }

            return in_array($status->privacyStatus, ['public', 'unlisted'], true) && true === $status->embeddable;
        }

        if ('vimeo' === $source['provider']) {
            $payload = $this->requestJsonOrMissing('GET', self::VIMEO_API_ENDPOINT.'/videos/'.rawurlencode($source['id']), [
                'headers' => [
                    'Accept' => 'application/vnd.vimeo.video+json',
                    'Authorization' => 'Bearer '.$this->vimeoAccessToken,
                ],
                'query' => ['fields' => 'uri,privacy.view'],
                'max_redirects' => 0,
                'timeout' => 5,
                'max_duration' => 10,
            ]);
            if (null === $payload) {
                return false;
            }

            $privacy = $payload->privacy ?? null;
            if (!$privacy instanceof \stdClass || !is_string($privacy->view ?? null)) {
                throw new VideoProviderImportException('Vimeo hat eine unvollständige Statusantwort geliefert.');
            }

            return 'anybody' === $privacy->view
                || ('unlisted' === $privacy->view && $source['has_privacy_hash']);
        }

        $payload = $this->requestJsonOrMissing('GET', self::DAILYMOTION_PLATFORM_ENDPOINT.'/video/'.rawurlencode($source['id']), [
            'headers' => ['Accept' => 'application/json'],
            'query' => ['fields' => 'id,allow_embed,status'],
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 10,
        ]);
        if (null === $payload) {
            return false;
        }
        if (($payload->id ?? null) !== $source['id'] || !is_bool($payload->allow_embed ?? null)) {
            throw new VideoProviderImportException('Dailymotion hat eine unvollständige Statusantwort geliefert.');
        }
        $status = $payload->status ?? null;
        if (!is_string($status)) {
            throw new VideoProviderImportException('Dailymotion hat einen ungültigen Veröffentlichungsstatus geliefert.');
        }

        return true === $payload->allow_embed && 'published' === $status;
    }

    /**
     * Read metadata for one supported provider URL through that provider's fixed API.
     * Direct URLs and unrecognized hosts are never fetched.
     *
     * @return array{provider: 'youtube'|'vimeo'|'dailymotion', source_url: string, title: string, description: string, duration_seconds: ?int, thumbnail_url?: string}|null
     */
    public function metadataForSourceUrl(
        string $sourceUrl,
        VideoThumbnailResolution $thumbnailResolution = VideoThumbnailResolution::Medium,
    ): ?array {
        $source = $this->providerSourceIdentity($sourceUrl);
        if (null === $source) {
            return null;
        }

        $provider = $source['provider'];
        $videoId = $source['id'];
        if (!$this->isConfigured($provider)) {
            throw new VideoProviderImportException(sprintf('Der %s-Metadatenabruf ist nicht konfiguriert.', self::providerLabel($provider)));
        }

        if ('youtube' === $provider) {
            $payload = $this->requestJson('GET', self::YOUTUBE_VIDEOS_ENDPOINT, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Goog-Api-Key' => $this->youtubeApiKey,
                ],
                'query' => [
                    'part' => 'snippet,contentDetails,status',
                    'id' => $videoId,
                    'maxResults' => 1,
                ],
                'max_redirects' => 0,
                'timeout' => 5,
                'max_duration' => 10,
            ]);
            $items = $payload->items ?? null;
            if (!is_array($items) || !array_is_list($items) || count($items) > 1) {
                throw new VideoProviderImportException('YouTube hat ungültige Metadaten geliefert.');
            }
            if ([] === $items) {
                return null;
            }

            $item = $items[0];
            $snippet = $item instanceof \stdClass ? ($item->snippet ?? null) : null;
            $details = $item instanceof \stdClass ? ($item->contentDetails ?? null) : null;
            $status = $item instanceof \stdClass ? ($item->status ?? null) : null;
            if (!$item instanceof \stdClass
                || ($item->id ?? null) !== $videoId
                || !$snippet instanceof \stdClass
                || !$details instanceof \stdClass
                || !$status instanceof \stdClass
                || !is_string($status->privacyStatus ?? null)
                || !in_array($status->privacyStatus, ['public', 'unlisted'], true)
                || true !== ($status->embeddable ?? null)
                || !is_string($details->duration ?? null)
            ) {
                return null;
            }

            return $this->normalizedSourceMetadata(
                'youtube',
                'https://www.youtube.com/watch?v='.$videoId,
                $videoId,
                $snippet->title ?? null,
                $snippet->description ?? '',
                self::youtubeDurationSeconds($details->duration),
                $snippet,
                $thumbnailResolution,
            );
        }

        if ('vimeo' === $provider) {
            $payload = $this->requestJsonOrMissing('GET', self::VIMEO_API_ENDPOINT.'/videos/'.rawurlencode($videoId), [
                'headers' => [
                    'Accept' => 'application/vnd.vimeo.video+json',
                    'Authorization' => 'Bearer '.$this->vimeoAccessToken,
                ],
                'query' => ['fields' => 'uri,name,description,link,duration,privacy.view,pictures.sizes'],
                'max_redirects' => 0,
                'timeout' => 5,
                'max_duration' => 10,
            ]);
            if (null === $payload) {
                return null;
            }

            $privacy = $payload->privacy ?? null;
            $duration = $payload->duration ?? null;
            if (!$privacy instanceof \stdClass
                || !is_string($privacy->view ?? null)
                || !in_array($privacy->view, ['anybody', 'unlisted'], true)
                || ('unlisted' === $privacy->view && !$source['has_privacy_hash'])
                || !is_int($duration)
                || $duration < 0
                || $duration > 2147483647
            ) {
                return null;
            }

            $canonicalSourceUrl = $this->canonicalVimeoSourceUrl($payload->link ?? null, $videoId);
            if (null === $canonicalSourceUrl) {
                return null;
            }

            return $this->normalizedSourceMetadata(
                'vimeo',
                $canonicalSourceUrl,
                $videoId,
                $payload->name ?? null,
                $payload->description ?? '',
                $duration,
                $payload,
                $thumbnailResolution,
            );
        }

        $payload = $this->requestJsonOrMissing('GET', self::DAILYMOTION_PLATFORM_ENDPOINT.'/video/'.rawurlencode($videoId), [
            'headers' => ['Accept' => 'application/json'],
            'query' => [
                'fields' => 'id,title,description,duration,allow_embed,status,url,thumbnail_url,thumbnail_120_url,thumbnail_180_url,thumbnail_240_url,thumbnail_360_url,thumbnail_480_url,thumbnail_720_url,thumbnail_1080_url',
            ],
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 10,
        ]);
        if (null === $payload) {
            return null;
        }

        $duration = $payload->duration ?? null;
        if (($payload->id ?? null) !== $videoId
            || true !== ($payload->allow_embed ?? null)
            || 'published' !== ($payload->status ?? null)
            || !is_int($duration)
            || $duration < 0
            || $duration > 2147483647
        ) {
            return null;
        }

        $canonicalSourceUrl = $this->canonicalDailymotionSourceUrl($payload->url ?? null, $videoId);
        if (null === $canonicalSourceUrl) {
            return null;
        }

        return $this->normalizedSourceMetadata(
            'dailymotion',
            $canonicalSourceUrl,
            $videoId,
            $payload->title ?? null,
            $payload->description ?? '',
            $duration,
            $payload,
            $thumbnailResolution,
        );
    }

    /**
     * Importiert ausschließlich öffentliche, einbettbare Videos aus einer Anbieter-Wiedergabeliste.
     *
     * @return list<array{title: string, description: string, source_url: string, thumbnail_url?: string}>
     */
    public function playlist(
        string $provider,
        string $playlistId,
        VideoThumbnailResolution $thumbnailResolution = VideoThumbnailResolution::Medium,
    ): array {
        if (!self::isSupportedProvider($provider)) {
            throw new \InvalidArgumentException('Der Videoanbieter wird nicht unterstützt.');
        }
        if (!self::isValidPlaylistId($provider, $playlistId)) {
            throw new \InvalidArgumentException(sprintf('Die %s ist ungültig.', self::playlistLabel($provider)));
        }
        if (!$this->isConfiguredForMode($provider, 'playlist')) {
            throw new VideoProviderImportException(sprintf('Der %s-Playlistimport ist nicht konfiguriert.', self::providerLabel($provider)));
        }

        if ('youtube' === $provider) {
            return $this->youtubePlaylist($playlistId, $thumbnailResolution);
        }

        if ('vimeo' === $provider) {
            $payload = $this->requestJson('GET', self::VIMEO_API_ENDPOINT.'/me/albums/'.rawurlencode($playlistId).'/videos', [
                'headers' => [
                    'Accept' => 'application/vnd.vimeo.video+json',
                    'Authorization' => 'Bearer '.$this->vimeoAccessToken,
                ],
                'query' => [
                    'per_page' => self::MAX_RESULTS,
                    'filter' => 'embeddable',
                    'fields' => 'uri,name,description,link,privacy.view,pictures.sizes',
                ],
                'max_redirects' => 0,
                'timeout' => 5,
                'max_duration' => 10,
            ]);

            return $this->readVimeoPlaylistItems($payload, $thumbnailResolution);
        }

        $token = $this->dailymotionAccessToken('playlist.read');
        $payload = $this->requestJson('GET', self::DAILYMOTION_API_ENDPOINT.'/playlists/'.rawurlencode($playlistId).'/videos', [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
            'query' => [
                'page' => 1,
                'page_size' => self::MAX_RESULTS,
                'fields' => 'video_id,title,description,video_url,visibility,embedding.enable_embed,thumbnail_url,thumbnail_120_url,thumbnail_180_url,thumbnail_240_url,thumbnail_360_url,thumbnail_480_url,thumbnail_720_url,thumbnail_1080_url',
            ],
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 10,
        ]);

        return $this->readItems($payload, 'dailymotion', $thumbnailResolution, true);
    }

    /**
     * Importiert ausschließlich öffentliche, einbettbare Videos aus den Likes des verbundenen Kontos.
     * Dailymotion verwendet stattdessen die öffentliche Favoritenliste einer validierten Profil-ID.
     *
     * @return list<array{title: string, description: string, source_url: string, thumbnail_url?: string}>
     */
    public function favorites(
        string $provider,
        ?string $profileId = null,
        VideoThumbnailResolution $thumbnailResolution = VideoThumbnailResolution::Medium,
    ): array {
        if (!self::isSupportedProvider($provider)) {
            throw new \InvalidArgumentException('Der Videoanbieter wird nicht unterstützt.');
        }
        if ('dailymotion' === $provider && !self::isValidChannelId($provider, (string) $profileId)) {
            throw new \InvalidArgumentException(sprintf('Die %s ist ungültig.', self::channelLabel($provider)));
        }
        if (!$this->isConfiguredForMode($provider, 'favorites')) {
            throw new VideoProviderImportException(sprintf('Der %s-Favoritenimport ist nicht konfiguriert.', self::providerLabel($provider)));
        }

        if ('youtube' === $provider) {
            $token = $this->youtubeOAuthAccessToken();
            $payload = $this->requestJson('GET', self::YOUTUBE_VIDEOS_ENDPOINT, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                ],
                'query' => [
                    'part' => 'snippet,status',
                    'myRating' => 'like',
                    'maxResults' => self::MAX_RESULTS,
                ],
                'max_redirects' => 0,
                'timeout' => 5,
                'max_duration' => 10,
            ]);
            $videoIds = self::youtubeFavoriteVideoIds($payload);

            return $this->readYouTubePlaylistVideos($payload, $videoIds, $thumbnailResolution);
        }

        if ('vimeo' === $provider) {
            $payload = $this->requestJson('GET', self::VIMEO_API_ENDPOINT.'/me/likes', [
                'headers' => [
                    'Accept' => 'application/vnd.vimeo.video+json',
                    'Authorization' => 'Bearer '.$this->vimeoAccessToken,
                ],
                'query' => [
                    'per_page' => self::MAX_RESULTS,
                    'filter' => 'embeddable',
                    'filter_embeddable' => 'true',
                    'fields' => 'uri,name,description,link,privacy.view,pictures.sizes',
                ],
                'max_redirects' => 0,
                'timeout' => 5,
                'max_duration' => 10,
            ]);

            return $this->readVimeoPlaylistItems($payload, $thumbnailResolution);
        }

        $payload = $this->requestJson('GET', self::DAILYMOTION_PLATFORM_ENDPOINT.'/user/'.rawurlencode((string) $profileId).'/favorites', [
            'headers' => ['Accept' => 'application/json'],
            'query' => [
                'limit' => self::MAX_RESULTS,
                'fields' => 'id,title,description,url,allow_embed,thumbnail_url,thumbnail_120_url,thumbnail_180_url,thumbnail_240_url,thumbnail_360_url,thumbnail_480_url,thumbnail_720_url,thumbnail_1080_url',
            ],
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 10,
        ]);

        return $this->readItems($payload, 'dailymotion', $thumbnailResolution);
    }

    public static function playlistLabel(string $provider): string
    {
        return match ($provider) {
            'vimeo' => 'Vimeo-Showcase-ID',
            'dailymotion' => 'Dailymotion-Playlist-ID',
            default => 'YouTube-Playlist-ID',
        };
    }

    /** @return list<array{title: string, description: string, source_url: string, thumbnail_url?: string}> */
    private function youtubePlaylist(string $playlistId, VideoThumbnailResolution $thumbnailResolution): array
    {
        $headers = [
            'Accept' => 'application/json',
            'X-Goog-Api-Key' => $this->youtubeApiKey,
        ];
        $playlistPayload = $this->requestJson('GET', self::YOUTUBE_PLAYLIST_ITEMS_ENDPOINT, [
            'headers' => $headers,
            'query' => [
                'part' => 'contentDetails',
                'maxResults' => self::MAX_RESULTS,
                'playlistId' => $playlistId,
            ],
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 10,
        ]);
        $videoIds = self::youtubePlaylistVideoIds($playlistPayload);
        if ([] === $videoIds) {
            return [];
        }

        $videosPayload = $this->requestJson('GET', self::YOUTUBE_VIDEOS_ENDPOINT, [
            'headers' => $headers,
            'query' => [
                'part' => 'snippet,status',
                'id' => implode(',', $videoIds),
            ],
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 10,
        ]);

        return $this->readYouTubePlaylistVideos($videosPayload, $videoIds, $thumbnailResolution);
    }

    /** @return list<string> */
    private static function youtubeFavoriteVideoIds(mixed $payload): array
    {
        if (!$payload instanceof \stdClass
            || !is_array($payload->items ?? null)
            || !array_is_list($payload->items)
            || count($payload->items) > self::MAX_RESULTS
        ) {
            throw new VideoProviderImportException('Der Videoanbieter hat eine ungültige Ergebnisliste geliefert.');
        }

        $videoIds = [];
        foreach ($payload->items as $item) {
            if (!$item instanceof \stdClass) {
                throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
            }
            $videoId = $item->id ?? null;
            if (!is_string($videoId) || 1 !== preg_match('/\A[A-Za-z0-9_-]{11}\z/D', $videoId)) {
                throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
            }
            if (!in_array($videoId, $videoIds, true)) {
                $videoIds[] = $videoId;
            }
        }

        return $videoIds;
    }

    private function youtubeOAuthAccessToken(): string
    {
        $payload = $this->requestJson('POST', self::YOUTUBE_OAUTH_TOKEN_ENDPOINT, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'refresh_token',
                'client_id' => $this->youtubeOAuthClientId,
                'client_secret' => $this->youtubeOAuthClientSecret,
                'refresh_token' => $this->youtubeOAuthRefreshToken,
            ],
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 10,
        ]);

        $token = $payload->access_token ?? null;
        $tokenType = $payload->token_type ?? null;
        $expiresIn = $payload->expires_in ?? null;
        if (!is_string($token)
            || 1 !== preg_match('/\A[\x21-\x7E]{1,4096}\z/D', $token)
            || !is_string($tokenType)
            || 0 !== strcasecmp($tokenType, 'Bearer')
            || !is_int($expiresIn)
            || $expiresIn < 1
            || $expiresIn > 7200
        ) {
            throw new VideoProviderImportException('YouTube hat keine gültige Leseberechtigung bereitgestellt.');
        }

        return $token;
    }

    /** @return list<string> */
    private static function youtubePlaylistVideoIds(mixed $payload): array
    {
        if (!$payload instanceof \stdClass
            || !is_array($payload->items ?? null)
            || !array_is_list($payload->items)
            || count($payload->items) > self::MAX_RESULTS
        ) {
            throw new VideoProviderImportException('Der Videoanbieter hat eine ungültige Ergebnisliste geliefert.');
        }

        $videoIds = [];
        foreach ($payload->items as $item) {
            if (!$item instanceof \stdClass) {
                throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
            }

            $details = $item->contentDetails ?? null;
            if (!$details instanceof \stdClass) {
                throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
            }
            $videoId = $details->videoId ?? null;
            if (null === $videoId) {
                continue;
            }
            if (!is_string($videoId) || 1 !== preg_match('/\A[A-Za-z0-9_-]{11}\z/D', $videoId)) {
                throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
            }
            if (!in_array($videoId, $videoIds, true)) {
                $videoIds[] = $videoId;
            }
        }

        return $videoIds;
    }

    /**
     * @param list<string> $requestedIds
     *
     * @return list<array{title: string, description: string, source_url: string, thumbnail_url?: string}>
     */
    private function readYouTubePlaylistVideos(
        mixed $payload,
        array $requestedIds,
        VideoThumbnailResolution $thumbnailResolution,
    ): array {
        if (!$payload instanceof \stdClass
            || !is_array($payload->items ?? null)
            || !array_is_list($payload->items)
            || count($payload->items) > count($requestedIds)
        ) {
            throw new VideoProviderImportException('Der Videoanbieter hat eine ungültige Ergebnisliste geliefert.');
        }

        $results = [];
        $seenIds = [];
        foreach ($payload->items as $item) {
            if (!$item instanceof \stdClass) {
                throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
            }
            $videoId = $item->id ?? null;
            $snippet = $item->snippet ?? null;
            $status = $item->status ?? null;
            if (!is_string($videoId)
                || 1 !== preg_match('/\A[A-Za-z0-9_-]{11}\z/D', $videoId)
                || !in_array($videoId, $requestedIds, true)
                || !$snippet instanceof \stdClass
                || !$status instanceof \stdClass
                || !is_string($status->privacyStatus ?? null)
                || !is_bool($status->embeddable ?? null)
            ) {
                throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
            }
            if ('public' !== $status->privacyStatus || true !== $status->embeddable || isset($seenIds[$videoId])) {
                continue;
            }

            $sourceUrl = 'https://www.youtube.com/watch?v='.$videoId;
            if (null === $this->embedResolver->resolve($sourceUrl)) {
                throw new VideoProviderImportException('Der Videoanbieter hat eine ungültige Videoquelle geliefert.');
            }
            $metadata = self::normalizeMetadata($snippet->title ?? null, $snippet->description ?? '');
            if (null === $metadata) {
                throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
            }

            $seenIds[$videoId] = true;
            $result = [
                'title' => $metadata['title'],
                'description' => $metadata['description'],
                'source_url' => $sourceUrl,
            ];
            $thumbnailUrl = $this->thumbnailUrlPolicy->select('youtube', $snippet, $thumbnailResolution, $videoId);
            if (null !== $thumbnailUrl) {
                $result['thumbnail_url'] = $thumbnailUrl;
            }
            $results[] = $result;
        }

        return $results;
    }

    /** @return list<array{title: string, description: string, source_url: string, thumbnail_url?: string}> */
    private function readVimeoPlaylistItems(mixed $payload, VideoThumbnailResolution $thumbnailResolution): array
    {
        if (!$payload instanceof \stdClass
            || !is_array($payload->data ?? null)
            || !array_is_list($payload->data)
            || count($payload->data) > self::MAX_RESULTS
        ) {
            throw new VideoProviderImportException('Der Videoanbieter hat eine ungültige Ergebnisliste geliefert.');
        }

        $results = [];
        $seenIds = [];
        foreach ($payload->data as $item) {
            if (!$item instanceof \stdClass) {
                throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
            }
            $videoId = self::vimeoVideoId($item->uri ?? null);
            $privacy = $item->privacy ?? null;
            if (null === $videoId || !$privacy instanceof \stdClass || !is_string($privacy->view ?? null)) {
                throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
            }
            if ('anybody' !== $privacy->view || isset($seenIds[$videoId])) {
                continue;
            }

            $sourceUrl = $this->canonicalVimeoSourceUrl($item->link ?? null, $videoId);
            if (null === $sourceUrl) {
                throw new VideoProviderImportException('Der Videoanbieter hat eine ungültige Videoquelle geliefert.');
            }
            $metadata = self::normalizeMetadata($item->name ?? null, $item->description ?? '');
            if (null === $metadata) {
                throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
            }

            $seenIds[$videoId] = true;
            $result = [
                'title' => $metadata['title'],
                'description' => $metadata['description'],
                'source_url' => $sourceUrl,
            ];
            $thumbnailUrl = $this->thumbnailUrlPolicy->select('vimeo', $item, $thumbnailResolution, $videoId);
            if (null !== $thumbnailUrl) {
                $result['thumbnail_url'] = $thumbnailUrl;
            }
            $results[] = $result;
        }

        return $results;
    }

    private function dailymotionAccessToken(string $requiredScope = 'video.read'): string
    {
        $payload = $this->requestJson('POST', self::DAILYMOTION_TOKEN_ENDPOINT, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->dailymotionClientId,
                'client_secret' => $this->dailymotionClientSecret,
                'scope' => $requiredScope,
            ],
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 10,
        ]);

        $token = $payload->access_token ?? null;
        $tokenType = $payload->token_type ?? null;
        $expiresIn = $payload->expires_in ?? null;
        $scope = $payload->scope ?? null;
        $scopes = is_string($scope) ? preg_split('/\s+/', trim($scope)) : false;
        if (!is_string($token)
            || 1 !== preg_match('/\A[\x21-\x7E]{1,4096}\z/D', $token)
            || !is_string($tokenType)
            || 0 !== strcasecmp($tokenType, 'Bearer')
            || !is_int($expiresIn)
            || $expiresIn < 1
            || $expiresIn > 1800
            || !is_array($scopes)
            || !in_array($requiredScope, $scopes, true)
        ) {
            throw new VideoProviderImportException('Dailymotion hat keine gültige Leseberechtigung bereitgestellt.');
        }

        return $token;
    }

    /** @param array<string, mixed> $options */
    private function requestJson(string $method, string $endpoint, array $options): \stdClass
    {
        $response = null;
        try {
            $response = $this->httpClient->request($method, $endpoint, $options);
            if (200 !== $response->getStatusCode()) {
                throw new VideoProviderImportException('Der Videoanbieter hat die Anfrage nicht erfolgreich beantwortet.');
            }

            $body = $this->readBody($response);
            if (null === StrictJsonObjectDecoder::decode($body, 32)) {
                throw new VideoProviderImportException('Der Videoanbieter hat ungültige oder mehrdeutige Daten geliefert.');
            }
            $payload = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
            if (!$payload instanceof \stdClass) {
                throw new VideoProviderImportException('Der Videoanbieter hat ungültige Daten geliefert.');
            }

            return $payload;
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

    /** @param array<string, mixed> $options */
    private function requestJsonOrMissing(string $method, string $endpoint, array $options): ?\stdClass
    {
        $response = null;
        try {
            $response = $this->httpClient->request($method, $endpoint, $options);
            $status = $response->getStatusCode();
            if (in_array($status, [404, 410], true)) {
                return null;
            }
            if (200 !== $status) {
                throw new VideoProviderImportException('Der Videoanbieter hat die Statusanfrage nicht erfolgreich beantwortet.');
            }

            $body = $this->readBody($response);
            if (null === StrictJsonObjectDecoder::decode($body, 32)) {
                throw new VideoProviderImportException('Der Videoanbieter hat ungültige oder mehrdeutige Daten geliefert.');
            }
            $payload = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
            if (!$payload instanceof \stdClass) {
                throw new VideoProviderImportException('Der Videoanbieter hat ungültige Daten geliefert.');
            }

            return $payload;
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

    /** @return array{provider: 'youtube'|'vimeo'|'dailymotion', id: string, has_privacy_hash: bool}|null */
    private function providerSourceIdentity(string $sourceUrl): ?array
    {
        $embedUrl = $this->embedResolver->resolve($sourceUrl);
        if (null === $embedUrl) {
            return null;
        }

        $parts = parse_url($embedUrl);
        if (!is_array($parts) || 'https' !== ($parts['scheme'] ?? null) || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        if ('www.youtube-nocookie.com' === $host && 1 === preg_match('/\A\/embed\/([A-Za-z0-9_-]{11})\z/D', $path, $matches)) {
            return ['provider' => 'youtube', 'id' => $matches[1], 'has_privacy_hash' => false];
        }
        if ('player.vimeo.com' === $host && 1 === preg_match('/\A\/video\/([0-9]{1,12})\z/D', $path, $matches)) {
            return [
                'provider' => 'vimeo',
                'id' => $matches[1],
                'has_privacy_hash' => 1 === preg_match('/\Ah=[A-Za-z0-9]{1,64}\z/D', $parts['query'] ?? ''),
            ];
        }
        if ('geo.dailymotion.com' === $host
            && 1 === preg_match('/\A\/player\/[A-Za-z0-9_-]{1,32}\.html\z/D', $path)
            && 1 === preg_match('/\Avideo=([A-Za-z0-9]{1,32})\z/D', $parts['query'] ?? '', $matches)
        ) {
            return ['provider' => 'dailymotion', 'id' => $matches[1], 'has_privacy_hash' => false];
        }

        return null;
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
     * @return list<array{title: string, description: string, source_url: string, thumbnail_url?: string}>
     */
    private function readItems(
        mixed $payload,
        string $provider,
        VideoThumbnailResolution $thumbnailResolution,
        bool $dailymotionChannel = false,
    ): array {
        if (!$payload instanceof \stdClass) {
            throw new VideoProviderImportException('Der Videoanbieter hat eine ungültige Ergebnisliste geliefert.');
        }

        $items = match ($provider) {
            'youtube' => $payload->items ?? null,
            'vimeo' => $payload->data ?? null,
            'dailymotion' => $dailymotionChannel ? ($payload->data ?? null) : ($payload->list ?? null),
            default => null,
        };
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
                $thumbnailMetadata = $snippet;
            } elseif ('vimeo' === $provider) {
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
                $thumbnailMetadata = $item;
            } elseif ($dailymotionChannel) {
                $visibility = $item->visibility ?? null;
                $embedding = $item->embedding ?? null;
                if (!is_string($visibility) || !$embedding instanceof \stdClass || !is_bool($embedding->enable_embed ?? null)) {
                    throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
                }
                if ('public' !== $visibility || true !== $embedding->enable_embed) {
                    continue;
                }

                $sourceUrl = $this->canonicalDailymotionSourceUrl($item->video_url ?? null);
                if (null === $sourceUrl) {
                    throw new VideoProviderImportException('Der Videoanbieter hat eine ungültige Videoquelle geliefert.');
                }
                $videoId = self::dailymotionVideoId($sourceUrl);
                if (null === $videoId || !is_string($item->video_id ?? null) || $item->video_id !== $videoId) {
                    throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
                }
                $title = $item->title ?? null;
                $description = $item->description ?? '';
                $thumbnailMetadata = $item;
            } else {
                $videoId = $item->id ?? null;
                $allowEmbed = $item->allow_embed ?? null;
                if (!is_string($videoId)
                    || 1 !== preg_match('/\A[A-Za-z0-9]{1,32}\z/D', $videoId)
                    || !is_bool($allowEmbed)
                ) {
                    throw new VideoProviderImportException('Der Videoanbieter hat ein ungültiges Video geliefert.');
                }
                if (!$allowEmbed) {
                    continue;
                }

                $sourceUrl = $this->canonicalDailymotionSourceUrl($item->url ?? null, $videoId);
                if (null === $sourceUrl) {
                    throw new VideoProviderImportException('Der Videoanbieter hat eine ungültige Videoquelle geliefert.');
                }
                $title = $item->title ?? null;
                $description = $item->description ?? '';
                $thumbnailMetadata = $item;
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
            $result = [
                'title' => $metadata['title'],
                'description' => $metadata['description'],
                'source_url' => $sourceUrl,
            ];
            $thumbnailUrl = $this->thumbnailUrlPolicy->select($provider, $thumbnailMetadata, $thumbnailResolution, $videoId);
            if (null !== $thumbnailUrl) {
                $result['thumbnail_url'] = $thumbnailUrl;
            }
            $results[] = $result;
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

    /**
     * @param 'youtube'|'vimeo'|'dailymotion' $provider
     *
     * @return array{provider: 'youtube'|'vimeo'|'dailymotion', source_url: string, title: string, description: string, duration_seconds: ?int, thumbnail_url?: string}|null
     */
    private function normalizedSourceMetadata(
        string $provider,
        string $canonicalSourceUrl,
        string $videoId,
        mixed $title,
        mixed $description,
        ?int $durationSeconds,
        object $thumbnailMetadata,
        VideoThumbnailResolution $thumbnailResolution,
    ): ?array {
        $metadata = self::normalizeMetadata($title, $description);
        if (null === $metadata || null === $this->embedResolver->resolve($canonicalSourceUrl)) {
            return null;
        }

        $result = [
            'provider' => $provider,
            'source_url' => $canonicalSourceUrl,
            'title' => $metadata['title'],
            'description' => $metadata['description'],
            'duration_seconds' => $durationSeconds,
        ];
        $thumbnailUrl = $this->thumbnailUrlPolicy->select($provider, $thumbnailMetadata, $thumbnailResolution, $videoId);
        if (null !== $thumbnailUrl) {
            $result['thumbnail_url'] = $thumbnailUrl;
        }

        return $result;
    }

    private static function youtubeDurationSeconds(string $duration): ?int
    {
        if (1 !== preg_match('/\AP(?:(\d{1,6})D)?(?:T(?:(\d{1,6})H)?(?:(\d{1,6})M)?(?:(\d{1,6})S)?)?\z/D', $duration, $matches)
            || str_ends_with($duration, 'T')
            || [] === array_filter(array_slice($matches, 1), static fn (string $part): bool => '' !== $part)
        ) {
            return null;
        }

        $days = (int) ($matches[1] ?? 0);
        $hours = (int) ($matches[2] ?? 0);
        $minutes = (int) ($matches[3] ?? 0);
        $seconds = (int) ($matches[4] ?? 0);
        $durationSeconds = $days * 86400 + $hours * 3600 + $minutes * 60 + $seconds;

        return $durationSeconds <= 2147483647 ? $durationSeconds : null;
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

    private function canonicalDailymotionSourceUrl(mixed $url, ?string $expectedId = null): ?string
    {
        if (!is_string($url) || !Video::isSafeHttpsSource($url)) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? null;
        if (!is_string($host)
            || !in_array(strtolower($host), ['dailymotion.com', 'www.dailymotion.com'], true)
            || !is_string($path)
            || isset($parts['query'])
            || isset($parts['fragment'])
            || 1 !== preg_match('/\A\/video\/([A-Za-z0-9]{1,32})\/?\z/D', $path, $matches)
        ) {
            return null;
        }

        $videoId = $matches[1];
        if (null !== $expectedId && $videoId !== $expectedId) {
            return null;
        }

        $canonicalUrl = 'https://www.dailymotion.com/video/'.$videoId;

        return null !== $this->embedResolver->resolve($canonicalUrl) ? $canonicalUrl : null;
    }

    private static function dailymotionVideoId(string $sourceUrl): ?string
    {
        $parts = parse_url($sourceUrl);
        if (!is_array($parts) || !is_string($parts['path'] ?? null)) {
            return null;
        }

        return 1 === preg_match('/\A\/video\/([A-Za-z0-9]{1,32})\z/D', $parts['path'], $matches)
            ? $matches[1]
            : null;
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

    private static function isValidCredential(string $credential): bool
    {
        return 1 === preg_match('/\A[\x21-\x7E]{1,200}\z/D', $credential);
    }
}
