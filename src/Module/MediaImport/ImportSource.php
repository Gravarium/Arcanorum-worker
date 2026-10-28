<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class ImportSource
{
    private function __construct(
        public MediaProvider $provider,
        public SourceKind $kind,
        public string $reference,
    ) {
    }

    public static function fromInput(MediaProvider $provider, SourceKind $kind, string $input): self
    {
        $input = trim($input);

        if ('' === $input || strlen($input) > 512 || 1 !== preg_match('//u', $input)) {
            throw new \InvalidArgumentException('The import source is invalid.');
        }

        if (SourceKind::Search === $kind) {
            if (strlen($input) > 160 || 1 === preg_match('/[\x00-\x1F\x7F]/u', $input) || str_contains($input, '://')) {
                throw new \InvalidArgumentException('The search query is invalid.');
            }

            return new self($provider, $kind, $input);
        }

        $reference = self::extractReference($provider, $kind, $input);

        return new self($provider, $kind, self::validateReference($provider, $kind, $reference));
    }

    private static function extractReference(MediaProvider $provider, SourceKind $kind, string $input): string
    {
        if (false === filter_var($input, FILTER_VALIDATE_URL) && !str_contains($input, '://')) {
            return $input;
        }

        $parts = parse_url($input);
        if (!is_array($parts)
            || 'https' !== strtolower((string) ($parts['scheme'] ?? ''))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('The provider URL is invalid.');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = match ($provider) {
            MediaProvider::YouTube => ['youtube.com', 'www.youtube.com', 'm.youtube.com'],
            MediaProvider::Vimeo => ['vimeo.com', 'www.vimeo.com'],
            MediaProvider::Dailymotion => ['dailymotion.com', 'www.dailymotion.com'],
        };
        if (!in_array($host, $allowedHosts, true)) {
            throw new \InvalidArgumentException('The provider URL host is not supported.');
        }

        $path = (string) ($parts['path'] ?? '');
        if (str_contains($path, '%')
            || str_contains($path, '\\')
            || 1 === preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new \InvalidArgumentException('The provider URL path is invalid.');
        }

        $query = (string) ($parts['query'] ?? '');
        if (MediaProvider::YouTube === $provider && SourceKind::Collection === $kind && '/playlist' === $path) {
            if (1 !== preg_match('/\Alist=([A-Za-z0-9_-]{10,64})\z/D', $query, $matches)) {
                throw new \InvalidArgumentException('The YouTube playlist URL is invalid.');
            }

            return $matches[1];
        }

        if ('' !== $query) {
            throw new \InvalidArgumentException('The provider URL contains unsupported query parameters.');
        }

        return match ([$provider, $kind]) {
            [MediaProvider::YouTube, SourceKind::Creator] => self::youtubeChannelFromPath($path),
            [MediaProvider::YouTube, SourceKind::Collection] => throw new \InvalidArgumentException('The YouTube playlist URL is invalid.'),
            [MediaProvider::Vimeo, SourceKind::Creator] => self::vimeoCreatorFromPath($path),
            [MediaProvider::Vimeo, SourceKind::Collection] => self::vimeoCollectionFromPath($path),
            [MediaProvider::Dailymotion, SourceKind::Creator] => self::dailymotionCreatorFromPath($path),
            [MediaProvider::Dailymotion, SourceKind::Collection] => self::dailymotionCollectionFromPath($path),
            default => throw new \InvalidArgumentException('The provider source type is unsupported.'),
        };
    }

    private static function validateReference(MediaProvider $provider, SourceKind $kind, string $reference): string
    {
        $valid = match ([$provider, $kind]) {
            [MediaProvider::YouTube, SourceKind::Creator] => 1 === preg_match('/\A(?:UC[A-Za-z0-9_-]{22}|@[A-Za-z0-9._-]{3,30})\z/D', $reference),
            [MediaProvider::YouTube, SourceKind::Collection] => 1 === preg_match('/\A[A-Za-z0-9_-]{10,64}\z/D', $reference),
            [MediaProvider::Vimeo, SourceKind::Creator], [MediaProvider::Vimeo, SourceKind::Collection] => 1 === preg_match('/\A[1-9][0-9]{0,14}\z/D', $reference),
            [MediaProvider::Dailymotion, SourceKind::Creator], [MediaProvider::Dailymotion, SourceKind::Collection] => 1 === preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $reference),
            default => false,
        };

        if (!$valid) {
            throw new \InvalidArgumentException('The provider source identifier is invalid.');
        }

        return $reference;
    }

    private static function youtubeChannelFromPath(string $path): string
    {
        if (1 === preg_match('~\A/channel/(UC[A-Za-z0-9_-]{22})\z~D', $path, $matches)) {
            return $matches[1];
        }

        if (1 === preg_match('~\A/(@[A-Za-z0-9._-]{3,30})\z~D', $path, $matches)) {
            return $matches[1];
        }

        throw new \InvalidArgumentException('The YouTube channel URL is invalid.');
    }

    private static function vimeoCreatorFromPath(string $path): string
    {
        if (1 === preg_match('~\A/user([1-9][0-9]{0,14})\z~D', $path, $matches)
            || 1 === preg_match('~\A/users/([1-9][0-9]{0,14})\z~D', $path, $matches)) {
            return $matches[1];
        }

        throw new \InvalidArgumentException('The Vimeo user URL is invalid.');
    }

    private static function vimeoCollectionFromPath(string $path): string
    {
        if (1 === preg_match('~\A/(?:showcase|albums?)/([1-9][0-9]{0,14})\z~D', $path, $matches)) {
            return $matches[1];
        }

        throw new \InvalidArgumentException('The Vimeo collection URL is invalid.');
    }

    private static function dailymotionCreatorFromPath(string $path): string
    {
        if (1 === preg_match('~\A/(?:user|profile)/([A-Za-z0-9_-]{1,64})\z~D', $path, $matches)) {
            return $matches[1];
        }

        throw new \InvalidArgumentException('The Dailymotion profile URL is invalid.');
    }

    private static function dailymotionCollectionFromPath(string $path): string
    {
        if (1 === preg_match('~\A/playlist/([A-Za-z0-9_-]{1,64})\z~D', $path, $matches)) {
            return $matches[1];
        }

        throw new \InvalidArgumentException('The Dailymotion playlist URL is invalid.');
    }
}
