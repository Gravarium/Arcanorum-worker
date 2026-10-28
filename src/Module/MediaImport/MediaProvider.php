<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

enum MediaProvider: string
{
    case YouTube = 'youtube';
    case Vimeo = 'vimeo';
    case Dailymotion = 'dailymotion';

    public function apiBaseUri(): string
    {
        return match ($this) {
            self::YouTube => 'https://www.googleapis.com',
            self::Vimeo => 'https://api.vimeo.com',
            self::Dailymotion => 'https://api.dailymotion.com',
        };
    }

    public function acceptsVideoId(string $videoId): bool
    {
        return match ($this) {
            self::YouTube => 1 === preg_match('/\A[A-Za-z0-9_-]{6,20}\z/D', $videoId),
            self::Vimeo => 1 === preg_match('/\A[1-9][0-9]{0,14}\z/D', $videoId),
            self::Dailymotion => 1 === preg_match('/\A[A-Za-z0-9]{1,32}\z/D', $videoId),
        };
    }

    public function videoPageUrl(string $videoId): string
    {
        if (!$this->acceptsVideoId($videoId)) {
            throw new \InvalidArgumentException('The provider video identifier is invalid.');
        }

        return match ($this) {
            self::YouTube => 'https://www.youtube.com/watch?v='.rawurlencode($videoId),
            self::Vimeo => 'https://vimeo.com/'.rawurlencode($videoId),
            self::Dailymotion => 'https://www.dailymotion.com/video/'.rawurlencode($videoId),
        };
    }
}
