<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\VideoThumbnailResolution;

final class ProviderThumbnailUrlPolicy
{
    /** @var array<string, int> */
    private const array YOUTUBE_THUMBNAIL_WIDTHS = [
        'default' => 120,
        'medium' => 320,
        'high' => 480,
        'standard' => 640,
        'maxres' => 1280,
    ];

    /** @var array<string, int> */
    private const array DAILYMOTION_THUMBNAIL_WIDTHS = [
        'thumbnail_120_url' => 120,
        'thumbnail_180_url' => 180,
        'thumbnail_240_url' => 240,
        'thumbnail_360_url' => 360,
        'thumbnail_480_url' => 480,
        'thumbnail_720_url' => 720,
        'thumbnail_1080_url' => 1080,
    ];

    /**
     * Pick the closest available provider image to the requested width. Invalid or absent provider
     * thumbnail data is treated as an optional missing image, never as an import failure.
     */
    public function select(string $provider, mixed $metadata, VideoThumbnailResolution $resolution, string $videoId): ?string
    {
        if (!$metadata instanceof \stdClass) {
            return null;
        }

        return match ($provider) {
            'youtube' => $this->selectYouTube($metadata, $resolution, $videoId),
            'vimeo' => $this->selectVimeo($metadata, $resolution),
            'dailymotion' => $this->selectDailymotion($metadata, $resolution),
            default => null,
        };
    }

    public function isAllowedUrl(string $provider, string $url, ?string $videoId = null): bool
    {
        if (strlen($url) > 2048 || 1 === preg_match('/[\x00-\x20\x7F]/', $url)) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || 'https' !== strtolower($parts['scheme'] ?? '')
            || null === ($host = $parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])
            || isset($parts['query']) && (strlen($parts['query']) > 512 || 1 === preg_match('/[^A-Za-z0-9._~!$&\'()*+,;=:@%\/?-]/', $parts['query']))
        ) {
            return false;
        }

        $host = strtolower($host);
        $path = $parts['path'] ?? '';
        if ('' === $path || str_contains($path, '%') || str_contains($path, '..')) {
            return false;
        }

        return match ($provider) {
            'youtube' => in_array($host, ['i.ytimg.com', 'img.youtube.com'], true)
                && 1 === preg_match(
                    '/\A\/vi(?:_webp)?\/'.(is_string($videoId) ? preg_quote($videoId, '/') : '[A-Za-z0-9_-]{11}').'\/[A-Za-z0-9_-]{2,24}\.(?:jpe?g|png|webp)\z/iD',
                    $path,
                ),
            'vimeo' => 'i.vimeocdn.com' === $host
                && 1 === preg_match('/\A\/video\/[A-Za-z0-9_-]{1,128}(?:_[0-9]{2,5}x[0-9]{2,5})?\.(?:jpe?g|png|webp)\z/iD', $path),
            'dailymotion' => in_array($host, [
                's1.dmcdn.net',
                's2.dmcdn.net',
                's3.dmcdn.net',
                's4.dmcdn.net',
                's5.dmcdn.net',
            ], true)
                && 1 === preg_match('/\A\/v\/[A-Za-z0-9_-][A-Za-z0-9._\/-]{0,190}\z/D', $path),
            default => false,
        };
    }

    private function selectYouTube(\stdClass $metadata, VideoThumbnailResolution $resolution, string $videoId): ?string
    {
        $thumbnails = $metadata->thumbnails ?? null;
        if (!$thumbnails instanceof \stdClass) {
            return null;
        }

        $candidates = [];
        foreach (self::YOUTUBE_THUMBNAIL_WIDTHS as $key => $defaultWidth) {
            $entry = $thumbnails->{$key} ?? null;
            $url = $entry instanceof \stdClass ? ($entry->url ?? null) : null;
            if (!is_string($url) || !$this->isAllowedUrl('youtube', $url, $videoId)) {
                continue;
            }

            $width = $entry->width ?? $defaultWidth;
            $width = is_int($width) && $width > 0 && $width <= 10000 ? $width : $defaultWidth;
            $candidates[$width] ??= $url;
        }

        return $this->closestWidth($candidates, $resolution->targetWidth());
    }

    private function selectVimeo(\stdClass $metadata, VideoThumbnailResolution $resolution): ?string
    {
        $pictures = $metadata->pictures ?? null;
        $sizes = $pictures instanceof \stdClass ? ($pictures->sizes ?? null) : null;
        if (!is_array($sizes) || !array_is_list($sizes) || count($sizes) > 32) {
            return null;
        }

        $candidates = [];
        foreach ($sizes as $size) {
            if (!$size instanceof \stdClass || !is_int($size->width ?? null) || $size->width < 1 || $size->width > 10000) {
                continue;
            }
            $url = $size->link ?? null;
            if (is_string($url) && $this->isAllowedUrl('vimeo', $url)) {
                $candidates[$size->width] ??= $url;
            }
        }

        return $this->closestWidth($candidates, $resolution->targetWidth());
    }

    private function selectDailymotion(\stdClass $metadata, VideoThumbnailResolution $resolution): ?string
    {
        $candidates = [];
        foreach (self::DAILYMOTION_THUMBNAIL_WIDTHS as $key => $width) {
            $url = $metadata->{$key} ?? null;
            if (is_string($url) && $this->isAllowedUrl('dailymotion', $url)) {
                $candidates[$width] ??= $url;
            }
        }

        $selectedUrl = $this->closestWidth($candidates, $resolution->targetWidth());
        if (null !== $selectedUrl) {
            return $selectedUrl;
        }

        $fallbackUrl = $metadata->thumbnail_url ?? null;

        return is_string($fallbackUrl) && $this->isAllowedUrl('dailymotion', $fallbackUrl) ? $fallbackUrl : null;
    }

    /** @param array<int, string> $candidates */
    private function closestWidth(array $candidates, ?int $targetWidth): ?string
    {
        if ([] === $candidates) {
            return null;
        }

        ksort($candidates, SORT_NUMERIC);
        if (null === $targetWidth) {
            return array_pop($candidates);
        }

        $selectedUrl = null;
        $selectedDistance = PHP_INT_MAX;
        foreach ($candidates as $width => $url) {
            $distance = abs($width - $targetWidth);
            if ($distance < $selectedDistance) {
                $selectedUrl = $url;
                $selectedDistance = $distance;
            }
        }

        return $selectedUrl;
    }
}
