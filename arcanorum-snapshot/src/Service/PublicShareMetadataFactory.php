<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\NewsArticle;
use App\Entity\Video;
use App\Module\Feed\Service\CanonicalPublicUrlGenerator;

final readonly class PublicShareMetadataFactory
{
    /** @var list<string> */
    private const array ALLOWED_IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private CanonicalPublicUrlGenerator $publicUrls)
    {
    }

    /**
     * @return array{type: string, title: string, description: string, url: string, image_url: ?string, image_mime_type: ?string, image_width: ?int, image_height: ?int}|null
     */
    public function forVideo(Video $video): ?array
    {
        if (!$this->publicUrls->isConfigured()) {
            return null;
        }

        $image = $this->videoSocialPreview(
            $video->getId(),
            $video->getThumbnailObjectId(),
            $video->getThumbnailMimeType(),
            $video->getThumbnailSize(),
        );

        return [
            'type' => 'video.other',
            'title' => $video->getTitle(),
            'description' => self::description($video->getDescription()),
            'url' => $this->publicUrls->generate('video_show', ['id' => $video->getId()]),
            'image_url' => $image['url'],
            'image_mime_type' => $image['mime_type'],
            'image_width' => $image['width'],
            'image_height' => $image['height'],
        ];
    }

    /**
     * @return array{type: string, title: string, description: string, url: string, image_url: ?string, image_mime_type: ?string, image_width: ?int, image_height: ?int}|null
     */
    public function forNewsArticle(NewsArticle $article): ?array
    {
        if (!$this->publicUrls->isConfigured() || $article->isMembersOnly()) {
            return null;
        }

        $image = $this->thumbnail($article->getThumbnailObjectId(), $article->getThumbnailMimeType(), 'news_thumbnail', ['id' => $article->getId()]);

        return [
            'type' => 'article',
            'title' => $article->getTitle(),
            'description' => self::description($article->getSummary() ?? $article->getBody()),
            'url' => $this->publicUrls->generate('news_show', ['slug' => $article->getSlug()]),
            'image_url' => $image['url'],
            'image_mime_type' => $image['mime_type'],
            'image_width' => null,
            'image_height' => null,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{url: ?string, mime_type: ?string}
     */
    private function thumbnail(?string $objectId, ?string $mimeType, string $route, array $parameters): array
    {
        if (null === $objectId || null === $mimeType || !in_array($mimeType, self::ALLOWED_IMAGE_MIME_TYPES, true)) {
            return ['url' => null, 'mime_type' => null];
        }

        return [
            'url' => $this->publicUrls->generate($route, $parameters),
            'mime_type' => $mimeType,
        ];
    }

    /**
     * @return array{url: ?string, mime_type: ?string, width: ?int, height: ?int}
     */
    private function videoSocialPreview(string $videoId, ?string $objectId, ?string $mimeType, ?int $size): array
    {
        if (null === $objectId
            || null === $mimeType
            || null === $size
            || !in_array($mimeType, self::ALLOWED_IMAGE_MIME_TYPES, true)
            || $size < 1
            || $size > 4194304
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)
        ) {
            return ['url' => null, 'mime_type' => null, 'width' => null, 'height' => null];
        }

        return [
            'url' => $this->publicUrls->generate('video_social_preview', [
                'id' => $videoId,
                'version' => VideoSocialPreviewGenerator::versionForSourceObjectId($objectId),
            ]),
            'mime_type' => 'image/jpeg',
            'width' => 1200,
            'height' => 630,
        ];
    }

    private static function description(string $value): string
    {
        $normalized = preg_replace('/[\p{C}\p{Z}\s]+/u', ' ', $value);
        if (!is_string($normalized)) {
            return '';
        }

        return mb_substr(trim($normalized), 0, 280, 'UTF-8');
    }
}
