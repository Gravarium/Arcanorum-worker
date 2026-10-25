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

    private const array SUPPORTED_ROUTES = ['video_show', 'series_episode_show'];

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
            || !is_string($origin)
        ) {
            return;
        }

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
            return;
        }

        $validatedOrigin = $this->streamResolver->resolve($origin.'/__arcanorum-csp-check__.mp4');
        if (null === $validatedOrigin || $validatedOrigin->origin !== $origin) {
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

        $streamPolicy = self::withAllowedMediaOrigin($policy, $origin);
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

    private static function withAllowedMediaOrigin(string $policy, string $origin): ?string
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

            if (!in_array($origin, $allowedSources, true)) {
                $directive = rtrim($directive).' '.$origin;
            }
        }
        unset($directive);

        return 1 === $mediaDirectiveCount ? implode(';', $directives) : null;
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
