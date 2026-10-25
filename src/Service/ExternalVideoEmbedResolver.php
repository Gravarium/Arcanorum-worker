<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;

final class ExternalVideoEmbedResolver
{
    /** @var list<string> */
    private const array YOUTUBE_WATCH_HOSTS = ['youtube.com', 'www.youtube.com'];

    /** @var list<string> */
    private const array YOUTUBE_EMBED_HOSTS = ['youtube-nocookie.com', 'www.youtube-nocookie.com'];

    /** @var list<string> */
    private const array VIMEO_VIDEO_HOSTS = ['vimeo.com', 'www.vimeo.com'];

    public function resolve(string $sourceUrl): ?string
    {
        if (!Video::isSafeHttpsSource($sourceUrl)) {
            return null;
        }

        $parts = parse_url($sourceUrl);
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

        $vimeoId = $this->vimeoId($host, $path);
        if (null === $vimeoId) {
            return null;
        }

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
}
