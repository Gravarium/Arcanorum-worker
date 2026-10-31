<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Module\Video\Service\VideoEmbedOriginPolicy;
use App\Module\Video\Service\VideoStreamSource;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Platform\Security\ContentSecurityPolicyReportOnlySubscriber;
use App\Service\ExternalVideoEmbedResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class VideoEmbedSecuritySubscriber implements EventSubscriberInterface
{
    public const string FRAME_ANCESTORS_ATTRIBUTE = '_arcanorum_video_embed_frame_ancestors';

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -128]];
    }

    public function __construct(
        private VideoEmbedOriginPolicy $originPolicy,
        private VideoStreamSourceResolver $streamResolver,
        private ExternalVideoEmbedResolver $externalEmbedResolver,
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ('video_embed_show' !== $request->attributes->get('_route')
            || 200 !== $event->getResponse()->getStatusCode()
        ) {
            return;
        }

        $origins = $this->originPolicy->validateStoredOrigins(
            $request->attributes->get(self::FRAME_ANCESTORS_ATTRIBUTE),
        );
        if (null === $origins || [] === $origins) {
            return;
        }

        $streamOrigin = $request->attributes->get(VideoStreamSource::REQUEST_ATTRIBUTE);
        if (null !== $streamOrigin && !is_string($streamOrigin)) {
            return;
        }
        $validatedStreamOrigin = is_string($streamOrigin)
            ? self::validateMediaOrigin($streamOrigin, $this->streamResolver)
            : null;
        if (is_string($streamOrigin) && null === $validatedStreamOrigin) {
            return;
        }

        $mediaOrigins = [];
        if (null !== $validatedStreamOrigin) {
            $mediaOrigins[] = $validatedStreamOrigin;
        }
        $qualityOrigins = $request->attributes->get(VideoStreamSource::QUALITY_ORIGINS_ATTRIBUTE);
        if (is_array($qualityOrigins) && array_is_list($qualityOrigins)) {
            foreach ($qualityOrigins as $qualityOrigin) {
                if (!is_string($qualityOrigin)) {
                    continue;
                }

                $validatedQualityOrigin = self::validateMediaOrigin($qualityOrigin, $this->streamResolver);
                if (null !== $validatedQualityOrigin) {
                    $mediaOrigins[] = $validatedQualityOrigin;
                }
            }
        }
        $mediaOrigins = array_values(array_unique($mediaOrigins));

        $frameAncestors = implode(' ', array_merge(["'self'"], $origins));
        $frameSources = implode(' ', array_merge(["'self'"], $this->externalEmbedResolver->allowedFrameOrigins()));
        $mediaSources = array_merge(["'self'"], $mediaOrigins);
        $connectSources = ["'self'"];
        $workerDirective = '';
        if (null !== $validatedStreamOrigin) {
            if (true === $request->attributes->get(VideoStreamSource::HLS_REQUEST_ATTRIBUTE)) {
                $mediaSources[] = 'blob:';
                $connectSources[] = $validatedStreamOrigin;
                $workerDirective = "; worker-src 'self' blob:";
            }
        }

        $policy = sprintf(
            "default-src 'none'; base-uri 'none'; object-src 'none'; form-action 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src %s; media-src %s%s; frame-src %s; frame-ancestors %s",
            implode(' ', $connectSources),
            implode(' ', $mediaSources),
            $workerDirective,
            $frameSources,
            $frameAncestors,
        );

        $headers = $event->getResponse()->headers;
        $headers->remove('X-Frame-Options');
        $headers->set('Content-Security-Policy', $policy);
        $headers->set(ContentSecurityPolicyReportOnlySubscriber::HEADER, $policy);
        $headers->set('Referrer-Policy', 'no-referrer');
    }

    private static function validateMediaOrigin(string $origin, VideoStreamSourceResolver $resolver): ?string
    {
        try {
            $parts = parse_url($origin);
        } catch (\ValueError) {
            return null;
        }

        if (!is_array($parts)
            || 'https' !== ($parts['scheme'] ?? null)
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['path'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || 'https://'.strtolower($parts['host']) !== $origin
        ) {
            return null;
        }

        $resolvedOrigin = $resolver->resolve($origin.'/__arcanorum-csp-check__.mp4');

        return null !== $resolvedOrigin && $resolvedOrigin->origin === $origin ? $origin : null;
    }
}
