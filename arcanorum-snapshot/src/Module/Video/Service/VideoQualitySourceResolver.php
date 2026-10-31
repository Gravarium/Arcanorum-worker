<?php

declare(strict_types=1);

namespace App\Module\Video\Service;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Repository\VideoQualitySourceRepository;
use Symfony\Component\HttpFoundation\Request;

final readonly class VideoQualitySourceResolver
{
    public function __construct(
        private VideoQualitySourceRepository $sources,
        private VideoStreamSourceResolver $streamResolver,
    ) {
    }

    /**
     * @return list<array{id: string, resolution: string, url: string, mime_type: string}>
     */
    public function resolveForPublishedVideo(Video $video, Request $request): array
    {
        if (PublishingStatus::Published !== $video->getStatus()
            || !in_array($video->getSourceType(), [VideoSourceType::File, VideoSourceType::Stream], true)
            || $video->isAudio()
        ) {
            return [];
        }

        if (VideoSourceType::File === $video->getSourceType()) {
            $objectId = $video->getUploadedObjectId();
            $mimeType = $video->getUploadedMimeType();
            $size = $video->getUploadedSize();
            if (!is_string($objectId)
                || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)
                || !in_array($mimeType, ['video/mp4', 'video/webm', 'video/ogg'], true)
                || !is_int($size)
                || $size < 1
                || $size > 33554432
            ) {
                return [];
            }
        } else {
            $primaryStream = $this->streamResolver->resolve($video->getSourceUrl());
            if (null === $primaryStream || $primaryStream->isHls() || $primaryStream->isAudio()) {
                return [];
            }
        }

        $resolvedSources = [];
        $origins = [];
        foreach ($this->sources->findForVideo($video) as $source) {
            $resolved = $this->streamResolver->resolve($source->getSourceUrl());
            if (null === $resolved || $resolved->isHls() || $resolved->isAudio()) {
                continue;
            }

            $resolvedSources[] = [
                'id' => $source->getId(),
                'resolution' => $source->getResolution()->value,
                'url' => $resolved->url,
                'mime_type' => $resolved->mimeType,
            ];
            $origins[] = $resolved->origin;
        }

        if ([] !== $origins) {
            $request->attributes->set(VideoStreamSource::QUALITY_ORIGINS_ATTRIBUTE, array_values(array_unique($origins)));
        }

        return $resolvedSources;
    }
}
