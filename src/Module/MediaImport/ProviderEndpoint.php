<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

enum ProviderEndpoint: string
{
    case YouTubeSearch = 'youtube.search';
    case YouTubeChannels = 'youtube.channels';
    case YouTubePlaylistItems = 'youtube.playlist-items';
    case VimeoSearch = 'vimeo.search';
    case VimeoUserVideos = 'vimeo.user-videos';
    case VimeoAlbumVideos = 'vimeo.album-videos';
    case DailymotionProfileVideos = 'dailymotion.profile-videos';
    case DailymotionPlaylistVideos = 'dailymotion.playlist-videos';

    public function provider(): MediaProvider
    {
        return match ($this) {
            self::YouTubeSearch, self::YouTubeChannels, self::YouTubePlaylistItems => MediaProvider::YouTube,
            self::VimeoSearch, self::VimeoUserVideos, self::VimeoAlbumVideos => MediaProvider::Vimeo,
            self::DailymotionProfileVideos, self::DailymotionPlaylistVideos => MediaProvider::Dailymotion,
        };
    }

    public function path(?string $resourceId = null): string
    {
        if (null !== $resourceId && 1 !== preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $resourceId)) {
            throw new \InvalidArgumentException('The provider resource identifier is invalid.');
        }

        return match ($this) {
            self::YouTubeSearch => '/youtube/v3/search',
            self::YouTubeChannels => '/youtube/v3/channels',
            self::YouTubePlaylistItems => '/youtube/v3/playlistItems',
            self::VimeoSearch => '/videos',
            self::VimeoUserVideos => '/users/' . self::requiredId($resourceId) . '/videos',
            self::VimeoAlbumVideos => '/albums/' . self::requiredId($resourceId) . '/videos',
            self::DailymotionProfileVideos => '/v2/profiles/' . self::requiredId($resourceId) . '/videos',
            self::DailymotionPlaylistVideos => '/v2/playlists/' . self::requiredId($resourceId) . '/videos',
        };
    }

    private static function requiredId(?string $resourceId): string
    {
        if (null === $resourceId || 1 !== preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $resourceId)) {
            throw new \InvalidArgumentException('The provider resource identifier is required.');
        }

        return rawurlencode($resourceId);
    }
}
