<?php

declare(strict_types=1);

namespace App\Module\Video\Service;

final readonly class VideoStreamSource
{
    public const string REQUEST_ATTRIBUTE = '_arcanorum_video_stream_origin';

    public const string HLS_REQUEST_ATTRIBUTE = '_arcanorum_video_stream_hls';

    public const string HLS_MIME_TYPE = 'application/vnd.apple.mpegurl';

    public function __construct(
        public string $url,
        public string $mimeType,
        public string $origin,
    ) {
    }

    public function isHls(): bool
    {
        return self::HLS_MIME_TYPE === $this->mimeType;
    }
}
