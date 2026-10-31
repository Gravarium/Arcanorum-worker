<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;

final readonly class ExternalVideoEmbedResolver
{
    /** @var list<string> */
    private const array YOUTUBE_WATCH_HOSTS = ['youtube.com', 'www.youtube.com'];

    /** @var list<string> */
    private const array YOUTUBE_EMBED_HOSTS = ['youtube-nocookie.com', 'www.youtube-nocookie.com'];

    /** @var list<string> */
    private const array VIMEO_VIDEO_HOSTS = ['vimeo.com', 'www.vimeo.com'];

    /** @var list<string> */
    private const array DAILYMOTION_VIDEO_HOSTS = ['dailymotion.com', 'www.dailymotion.com'];

    /** @var list<string> */
    private const array FACEBOOK_VIDEO_HOSTS = ['facebook.com', 'www.facebook.com'];

    /** @var list<string> */
    private const array GOOGLE_DRIVE_HOSTS = ['drive.google.com'];

    /** @var list<string> */
    private const array RUTUBE_VIDEO_HOSTS = ['rutube.ru', 'www.rutube.ru'];

    /** @var list<string> */
    private const array VK_VIDEO_HOSTS = ['vk.com', 'www.vk.com', 'vk.ru', 'www.vk.ru', 'vkvideo.ru', 'www.vkvideo.ru'];

    /** @var list<string> */
    private const array YOUKU_VIDEO_HOSTS = ['youku.com', 'www.youku.com', 'v.youku.com', 'player.youku.com'];

    public function __construct(private string $dailymotionPlayerId = '')
    {
    }

    public function resolve(string $sourceUrl): ?string
    {
        try {
            if (!Video::isSafeHttpsSource($sourceUrl)) {
                return null;
            }

            $parts = parse_url($sourceUrl);
        } catch (\ValueError) {
            return null;
        }

        if (!is_array($parts)) {
            return null;
        }

        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';
        if (null === $host || str_contains($path, '%')) {
            return null;
        }

        $host = strtolower($host);
        $youtubeId = $this->youtubeId($host, $path, $query);
        if (null !== $youtubeId) {
            return 'https://www.youtube-nocookie.com/embed/'.$youtubeId;
        }

        $facebookId = $this->facebookId($host, $path, $query, isset($parts['fragment']));
        if (null !== $facebookId) {
            return 'https://www.facebook.com/video/embed?video_id='.$facebookId;
        }

        $googleDriveEmbed = $this->googleDriveEmbed($host, $path, $query, isset($parts['fragment']));
        if (null !== $googleDriveEmbed) {
            return $googleDriveEmbed;
        }

        $rutubeId = $this->rutubeId($host, $path, isset($parts['fragment']));
        if (null !== $rutubeId) {
            return 'https://rutube.ru/play/embed/'.$rutubeId;
        }

        $vkEmbed = $this->vkEmbed($host, $path, $query, isset($parts['fragment']));
        if (null !== $vkEmbed) {
            return $vkEmbed;
        }

        $youkuId = $this->youkuId($host, $path, isset($parts['fragment']));
        if (null !== $youkuId) {
            return 'https://player.youku.com/embed/'.$youkuId;
        }

        $vimeoId = $this->vimeoId($host, $path);
        if (null !== $vimeoId) {
            $privacyHashes = self::queryValues($query, 'h');
            if (count($privacyHashes) > 1) {
                return null;
            }
            if ([] === $privacyHashes) {
                return 'https://player.vimeo.com/video/'.$vimeoId;
            }

            $privacyHash = $privacyHashes[0];

            return 1 === preg_match('/\A[A-Za-z0-9]{1,64}\z/D', $privacyHash)
                ? 'https://player.vimeo.com/video/'.$vimeoId.'?h='.$privacyHash
                : null;
        }

        $dailymotionId = isset($parts['fragment']) ? null : $this->dailymotionId($host, $path, $query);
        if (null === $dailymotionId || !$this->isDailymotionConfigured()) {
            return null;
        }

        return 'https://geo.dailymotion.com/player/'.$this->dailymotionPlayerId.'.html?video='.$dailymotionId;
    }

    /** @return list<string> */
    public function allowedFrameOrigins(): array
    {
        return [
            'https://www.youtube-nocookie.com',
            'https://player.vimeo.com',
            'https://geo.dailymotion.com',
            'https://www.facebook.com',
            'https://drive.google.com',
            'https://rutube.ru',
            'https://vk.com',
            'https://player.youku.com',
        ];
    }

    public function isDailymotionConfigured(): bool
    {
        return 1 === preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $this->dailymotionPlayerId);
    }

    private function youtubeId(string $host, string $path, string $query): ?string
    {
        if ('youtu.be' === $host) {
            return self::captureId($path, '/\A\/([A-Za-z0-9_-]{11})\/?\z/D');
        }

        if (in_array($host, self::YOUTUBE_EMBED_HOSTS, true)) {
            return self::captureId($path, '/\A\/embed\/([A-Za-z0-9_-]{11})\/?\z/D');
        }

        if (!in_array($host, self::YOUTUBE_WATCH_HOSTS, true)) {
            return null;
        }

        if ('/watch' === $path) {
            $videoIds = self::queryValues($query, 'v');
            if (1 !== count($videoIds)) {
                return null;
            }

            return 1 === preg_match('/\A[A-Za-z0-9_-]{11}\z/D', $videoIds[0])
                ? $videoIds[0]
                : null;
        }

        return self::captureId($path, '/\A\/(?:embed|shorts|live)\/([A-Za-z0-9_-]{11})\/?\z/D');
    }

    private function vimeoId(string $host, string $path): ?string
    {
        if ('player.vimeo.com' === $host) {
            return self::captureId($path, '/\A\/video\/([0-9]{1,12})\/?\z/D');
        }

        if (in_array($host, self::VIMEO_VIDEO_HOSTS, true)) {
            return self::captureId($path, '/\A\/([0-9]{1,12})\/?\z/D');
        }

        return null;
    }

    private function dailymotionId(string $host, string $path, string $query): ?string
    {
        if (!in_array($host, self::DAILYMOTION_VIDEO_HOSTS, true) || '' !== $query) {
            return null;
        }

        return self::captureId($path, '/\A\/video\/([A-Za-z0-9]{1,32})\/?\z/D');
    }

    private function facebookId(string $host, string $path, string $query, bool $hasFragment): ?string
    {
        if (!in_array($host, self::FACEBOOK_VIDEO_HOSTS, true) || $hasFragment) {
            return null;
        }

        if (in_array($path, ['/watch', '/watch/', '/video.php'], true)) {
            return self::singleQueryIdentifier($query, 'v', '/\A[0-9]{1,32}\z/D');
        }

        if ('/video/embed' === $path) {
            return self::singleQueryIdentifier($query, 'video_id', '/\A[0-9]{1,32}\z/D');
        }

        return self::captureId($path, '/\A\/(?:[A-Za-z0-9._-]{1,100}\/)?videos\/([0-9]{1,32})\/?\z/D');
    }

    private function googleDriveEmbed(string $host, string $path, string $query, bool $hasFragment): ?string
    {
        if (!in_array($host, self::GOOGLE_DRIVE_HOSTS, true) || $hasFragment) {
            return null;
        }

        $parameters = self::queryParameterMap($query, ['id', 'resourcekey', 'usp']);
        if (null === $parameters
            || (isset($parameters['usp']) && !in_array($parameters['usp'], ['sharing', 'drive_link', 'drive_open'], true))
            || (isset($parameters['resourcekey']) && 1 !== preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $parameters['resourcekey']))
        ) {
            return null;
        }

        if ('/open' === $path) {
            $id = $parameters['id'] ?? null;
            if (!is_string($id) || 1 !== preg_match('/\A[A-Za-z0-9_-]{10,128}\z/D', $id)) {
                return null;
            }
        } else {
            if (isset($parameters['id'])) {
                return null;
            }
            $id = self::captureId($path, '/\A\/file\/d\/([A-Za-z0-9_-]{10,128})\/(?:view|preview)\/?\z/D');
            if (null === $id) {
                return null;
            }
        }

        $resourceKey = $parameters['resourcekey'] ?? null;

        return 'https://drive.google.com/file/d/'.$id.'/preview'.(is_string($resourceKey) ? '?resourcekey='.$resourceKey : '');
    }

    private function rutubeId(string $host, string $path, bool $hasFragment): ?string
    {
        if (!in_array($host, self::RUTUBE_VIDEO_HOSTS, true) || $hasFragment) {
            return null;
        }

        $id = self::captureId(
            $path,
            '/\A\/(?:video|play\/embed)\/([a-f0-9]{32}|[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12})\/?\z/Di',
        );

        return null === $id ? null : strtolower($id);
    }

    private function vkEmbed(string $host, string $path, string $query, bool $hasFragment): ?string
    {
        if (!in_array($host, self::VK_VIDEO_HOSTS, true) || $hasFragment) {
            return null;
        }

        if (1 === preg_match('/\A\/video(-?[0-9]{1,20})_([0-9]{1,20})\/?\z/D', $path, $matches)) {
            $ownerId = $matches[1];
            $videoId = $matches[2];
            $hash = null;
        } elseif ('/video_ext.php' === $path) {
            $parameters = self::queryParameterMap($query, ['oid', 'id', 'hash']);
            if (null === $parameters
                || !isset($parameters['oid'], $parameters['id'])
                || 1 !== preg_match('/\A-?[0-9]{1,20}\z/D', $parameters['oid'])
                || 1 !== preg_match('/\A[0-9]{1,20}\z/D', $parameters['id'])
                || (isset($parameters['hash']) && 1 !== preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $parameters['hash']))
            ) {
                return null;
            }
            $ownerId = $parameters['oid'];
            $videoId = $parameters['id'];
            $hash = $parameters['hash'] ?? null;
        } else {
            return null;
        }

        return 'https://vk.com/video_ext.php?oid='.$ownerId.'&id='.$videoId.(is_string($hash) ? '&hash='.$hash : '');
    }

    private function youkuId(string $host, string $path, bool $hasFragment): ?string
    {
        if (!in_array($host, self::YOUKU_VIDEO_HOSTS, true) || $hasFragment) {
            return null;
        }

        if ('player.youku.com' === $host) {
            return self::captureId($path, '/\A\/embed\/([A-Za-z0-9_-]{6,64}={0,2})\/?\z/D');
        }

        return self::captureId($path, '/\A\/v_show\/id_([A-Za-z0-9_-]{6,64}={0,2})\.html\/?\z/D');
    }

    private static function captureId(string $value, string $pattern): ?string
    {
        if (1 !== preg_match($pattern, $value, $matches)) {
            return null;
        }

        $id = $matches[1] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * @return list<string>
     */
    private static function queryValues(string $query, string $key): array
    {
        if ('' === $query) {
            return [];
        }

        $values = [];
        foreach (explode('&', $query) as $pair) {
            $parts = explode('=', $pair, 2);
            $rawName = $parts[0];
            if ($key === urldecode($rawName)) {
                $values[] = urldecode($parts[1] ?? '');
            }
        }

        return $values;
    }

    private static function singleQueryIdentifier(string $query, string $key, string $pattern): ?string
    {
        $values = self::queryValues($query, $key);
        if (1 !== count($values) || 1 !== preg_match($pattern, $values[0])) {
            return null;
        }

        return $values[0];
    }

    /**
     * @param list<string> $allowedKeys
     *
     * @return array<string, string>|null
     */
    private static function queryParameterMap(string $query, array $allowedKeys): ?array
    {
        if ('' === $query) {
            return [];
        }

        $parameters = [];
        foreach (explode('&', $query) as $pair) {
            if ('' === $pair) {
                return null;
            }

            [$rawName, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($rawName);
            if ('' === $name
                || !in_array($name, $allowedKeys, true)
                || array_key_exists($name, $parameters)
            ) {
                return null;
            }

            $parameters[$name] = urldecode($rawValue);
        }

        return $parameters;
    }
}
