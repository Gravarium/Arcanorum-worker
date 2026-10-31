<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;
use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\VideoRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class VideoViewCounter
{
    public function __construct(
        private VideoRepository $videos,
        #[Autowire(service: 'limiter.video_view')]
        private RateLimiterFactory $limiter,
        private RateLimitKeyFactory $rateLimitKeys,
        private SiteSettingsProvider $siteSettings,
        private LoggerInterface $logger,
    ) {
    }

    public function record(Video $video, Request $request): void
    {
        try {
            if ($this->videos->recordPublicWatchPulse($video->getId())) {
                $video->markPubliclyViewedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            }
        } catch (\Exception $exception) {
            $this->logger->warning('Der datensparsame Video-Aktivitätszeitpunkt konnte nicht aktualisiert werden.', [
                'failure_type' => $exception::class,
            ]);
        }

        $session = $request->getSession();
        $day = gmdate('Y-m-d');
        $history = $session->get('public_video_viewed');
        $ids = is_array($history) && ($history['day'] ?? null) === $day && is_array($history['ids'] ?? null)
            ? $history['ids']
            : [];
        if (isset($ids[$video->getId()]) || count($ids) >= 100) {
            return;
        }

        $ip = $request->getClientIp();
        if (null === $ip) {
            return;
        }

        $key = $this->rateLimitKeys->forIdentityAndIp($video->getId(), $request, 'video-view');
        try {
            if ($this->limiter->create($key)->consume()->isAccepted()) {
                $this->videos->recordPublicView($video->getId(), $this->siteSettings->current()->getAutoFeatureViewThreshold());
            }
        } catch (\Exception) {
            $this->logger->warning('Ein öffentlicher Videoaufruf konnte nicht gezählt werden.');

            return;
        }

        $ids[$video->getId()] = true;
        $session->set('public_video_viewed', ['day' => $day, 'ids' => $ids]);
    }
}
