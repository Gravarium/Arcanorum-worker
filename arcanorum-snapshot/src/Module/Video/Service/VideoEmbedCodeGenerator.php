<?php

declare(strict_types=1);

namespace App\Module\Video\Service;

use App\Entity\Video;
use App\Entity\VideoPlayerAspectRatio;
use App\Entity\VideoSourceType;
use App\Module\Feed\Service\CanonicalPublicUrlGenerator;
use App\Service\SiteSettingsProvider;

final readonly class VideoEmbedCodeGenerator
{
    public function __construct(
        private CanonicalPublicUrlGenerator $publicUrls,
        private VideoEmbedOriginPolicy $originPolicy,
        private SiteSettingsProvider $settings,
    ) {
    }

    public function generate(Video $video): ?string
    {
        if (!$this->settings->current()->isPublicVideoEmbeddingEnabled()
            || !$video->isExternallyEmbeddable()
            || !$this->publicUrls->isConfigured()
            || null === $this->originPolicy->validateStoredOrigins($video->getEmbedAllowedOrigins())
        ) {
            return null;
        }

        try {
            $url = $this->publicUrls->generate('video_embed_show', ['id' => $video->getId()]);
        } catch (\LogicException) {
            return null;
        }

        $aspectRatio = VideoSourceType::Embed === $video->getSourceType()
            ? VideoPlayerAspectRatio::Widescreen
            : $this->settings->current()->getVideoPlayerAspectRatio();
        $maximumWidth = $this->settings->current()->getVideoPlayerEmbedMaxWidth();
        $dimensions = $aspectRatio->iframeDimensions($maximumWidth);

        return sprintf(
            '<iframe src="%s" title="Arcanorum Video Player" width="%d" height="%d" style="width:100%%;max-width:%dpx;height:auto;border:0" loading="lazy" referrerpolicy="no-referrer" allow="fullscreen; picture-in-picture" allowfullscreen></iframe>',
            htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $dimensions['width'],
            $dimensions['height'],
            $maximumWidth->iframeWidth(),
        );
    }
}
