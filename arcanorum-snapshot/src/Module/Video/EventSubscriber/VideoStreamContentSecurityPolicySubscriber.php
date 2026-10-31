<?php

declare(strict_types=1);

namespace App\Module\Video\EventSubscriber;

use App\Module\Video\Service\VideoStreamSource;
use App\Module\Video\Service\VideoStreamSourceResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class VideoStreamContentSecurityPolicySubscriber implements EventSubscriberInterface
{
    private const string HEADER = 'Content-Security-Policy-Report-Only';

    private const array SUPPORTED_ROUTES = ['platform_home', 'video_show', 'series_episode_show', 'video_embed_show'];

    /**
     * @param array<string, string> $routePolicies
     */
    public function __construct(
        private VideoStreamSourceResolver $streamResolver,
        private array $routePolicies,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -64]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        $origin = $request->attributes->get(VideoStreamSource::REQUEST_ATTRIBUTE);
        if (!is_string($route)
            || !in_array($route, self::SUPPORTED_ROUTES, true)
        ) {
            return;
        }

        $origins = [];
        if (is_string($origin)) {
            $validatedOrigin = self::validateOrigin($origin, $this->streamResolver);
            if (null !== $validatedOrigin) {
                $origins[] = $validatedOrigin;
            }
        }

        $qualityOrigins = $request->attributes->get(VideoStreamSource::QUALITY_ORIGINS_ATTRIBUTE);
        if (is_array($qualityOrigins) && array_is_list($qualityOrigins)) {
            foreach ($qualityOrigins as $qualityOrigin) {
                if (!is_string($qualityOrigin)) {
                    continue;
                }

                $validatedQualityOrigin = self::validateOrigin($qualityOrigin, $this->streamResolver);
                if (null !== $validatedQualityOrigin) {
                    $origins[] = $validatedQualityOrigin;
                }
            }
        }
        $origins = array_values(array_unique($origins));
        if ([] === $origins) {
            return;
        }

        $isHls = true === $request->attributes->get(VideoStreamSource::HLS_REQUEST_ATTRIBUTE);

        $policy = $this->routePolicies[$route] ?? null;
        if (!is_string($policy)
            || '' === trim($policy)
            || false !== strpbrk($policy, "\r\n")
        ) {
            return;
        }

        $streamPolicy = self::withAllowedMediaOrigins($policy, $origins);
        if ($isHls) {
            $streamPolicy = null === $streamPolicy
                ? null
                : self::withAllowedHlsSources($streamPolicy, $this->streamResolver->getAllowedOrigins());
        }
        if (null === $streamPolicy) {
            return;
        }

        $event->getResponse()->headers->set(self::HEADER, $streamPolicy);
    }

    /** @param list<string> $origins */
    private static function withAllowedMediaOrigins(string $policy, array $origins): ?string
    {
        $directives = explode(';', $policy);
        $mediaDirectiveCount = 0;
        foreach ($directives as &$directive) {
            $sources = preg_split('/\s+/', trim($directive));
            if (!is_array($sources) || 'media-src' !== ($sources[0] ?? null)) {
                continue;
            }

            ++$mediaDirectiveCount;
            $allowedSources = array_slice($sources, 1);
            if (!in_array("'self'", $allowedSources, true)) {
                return null;
            }

            foreach ($origins as $origin) {
                if (!in_array($origin, $allowedSources, true)) {
                    $directive = rtrim($directive).' '.$origin;
                    $allowedSources[] = $origin;
                }
            }
        }
        unset($directive);

        return 1 === $mediaDirectiveCount ? implode(';', $directives) : null;
    }

    private static function validateOrigin(string $origin, VideoStreamSourceResolver $resolver): ?string
    {
        $parts = parse_url($origin);
        if (!is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['path'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !is_string($parts['host'] ?? null)
            || 'https://'.strtolower($parts['host']) !== $origin
        ) {
            return null;
        }

        $validatedOrigin = $resolver->resolve($origin.'/__arcanorum-csp-check__.mp4');

        return null !== $validatedOrigin && $validatedOrigin->origin === $origin ? $origin : null;
    }

    /** @param list<string> $allowedOrigins */
    private static function withAllowedHlsSources(string $policy, array $allowedOrigins): ?string
    {
        $directives = explode(';', $policy);
        $requiredSources = [
            'media-src' => array_merge(['blob:'], $allowedOrigins),
            'connect-src' => $allowedOrigins,
            'worker-src' => ['blob:'],
        ];

        foreach ($requiredSources as $name => $sourcesToAdd) {
            $found = false;
            foreach ($directives as &$directive) {
                $sources = preg_split('/\s+/', trim($directive));
                if (!is_array($sources) || $name !== ($sources[0] ?? null)) {
                    continue;
                }

                if ($found || !in_array("'self'", array_slice($sources, 1), true)) {
                    return null;
                }

                $found = true;
                foreach ($sourcesToAdd as $source) {
                    if (!in_array($source, array_slice($sources, 1), true)) {
                        $directive = rtrim($directive).' '.$source;
                    }
                }
            }
            unset($directive);

            if (!$found) {
                if ('worker-src' !== $name) {
                    return null;
                }

                $directives[] = " worker-src 'self' ".implode(' ', $sourcesToAdd);
            }
        }

        return implode(';', $directives);
    }
}
