<?php

declare(strict_types=1);

namespace App\Module\Advertising\Application;

use App\Module\Advertising\Domain\AdFormat;
use App\Module\Advertising\Domain\Advertisement;
use App\Module\Advertising\Domain\Placement;
use App\Module\Advertising\Domain\SafeAssetPath;
use App\Module\Advertising\Domain\SafeHtml;

final readonly class AdvertisingRenderer
{
    public function __construct(private ClickTokenSigner $tokens)
    {
    }

    public function render(Advertisement $advertisement, \DateTimeImmutable $at): string
    {
        $clickUrl = '/advertising/click/'.rawurlencode($advertisement->id).'?token='.rawurlencode($this->tokens->issue($advertisement->id, $at));
        $safeClickUrl = htmlspecialchars($clickUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return match ($advertisement->format) {
            AdFormat::HtmlBanner => $this->renderHtmlBanner($advertisement, $safeClickUrl),
            AdFormat::StaticPlayer => $this->renderStaticPlayer($advertisement, $safeClickUrl),
            AdFormat::Video => $this->renderVideo($advertisement, $safeClickUrl),
        };
    }

    private function renderHtmlBanner(Advertisement $advertisement, string $clickUrl): string
    {
        $html = SafeHtml::sanitize($advertisement->creative['html'] ?? '')->html;

        return '<aside class="cms-ad cms-ad--html" aria-label="Anzeige"><a href="'.$clickUrl.'" rel="sponsored nofollow noopener noreferrer">'.$html.'</a></aside>';
    }

    private function renderStaticPlayer(Advertisement $advertisement, string $clickUrl): string
    {
        $imagePath = $this->asset($advertisement, 'image_path');
        $alt = htmlspecialchars($advertisement->creative['alt'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $caption = htmlspecialchars($advertisement->creative['caption'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<aside class="cms-ad cms-ad--player" aria-label="Anzeige"><a href="'.$clickUrl.'" rel="sponsored nofollow noopener noreferrer">'
            .'<img src="'.htmlspecialchars($imagePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" alt="'.$alt.'" loading="lazy"><span>'.$caption.'</span></a></aside>';
    }

    private function renderVideo(Advertisement $advertisement, string $clickUrl): string
    {
        if ('local' !== ($advertisement->creative['provider'] ?? null)) {
            throw new \InvalidArgumentException('Video provider is not allowed.');
        }

        $videoPath = htmlspecialchars($this->asset($advertisement, 'video_path'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $posterPath = htmlspecialchars($this->asset($advertisement, 'poster_path'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $caption = htmlspecialchars($advertisement->creative['caption'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $preRoll = Placement::VIDEO_PREROLL_ID === $advertisement->placementId;
        $preRollMarker = $preRoll ? ' data-video-preroll' : '';
        $videoMarker = $preRoll ? ' data-video-preroll-ad' : '';

        return '<aside class="cms-ad cms-ad--video" aria-label="Anzeige"'.$preRollMarker.'><video controls preload="none" playsinline poster="'.$posterPath.'"'.$videoMarker.'>'
            .'<source src="'.$videoPath.'" type="video/mp4"></video><p>'.$caption.'</p>'
            .'<a href="'.$clickUrl.'" rel="sponsored nofollow noopener noreferrer">Anzeige öffnen</a></aside>';
    }

    private function asset(Advertisement $advertisement, string $field): string
    {
        return SafeAssetPath::fromString($advertisement->creative[$field] ?? '')->path;
    }
}
