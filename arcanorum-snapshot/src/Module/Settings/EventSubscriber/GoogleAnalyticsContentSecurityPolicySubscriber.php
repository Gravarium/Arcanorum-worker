<?php

declare(strict_types=1);

namespace App\Module\Settings\EventSubscriber;

use App\Platform\Security\ContentSecurityPolicyReportOnlySubscriber;
use App\Service\GoogleAnalyticsContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class GoogleAnalyticsContentSecurityPolicySubscriber implements EventSubscriberInterface
{
    private const array SOURCES = [
        'script-src' => ['https://www.googletagmanager.com'],
        'connect-src' => ['https://www.google-analytics.com', 'https://region1.google-analytics.com'],
        'img-src' => ['https://www.google-analytics.com', 'https://region1.google-analytics.com'],
    ];

    public function __construct(private GoogleAnalyticsContext $analytics)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -128]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$this->analytics->isConfiguredFor($request)
            || $response->isRedirect()
            || !str_starts_with((string) $response->headers->get('Content-Type', ''), 'text/html')
        ) {
            return;
        }

        $vary = $response->getVary();
        $hasCookieVary = array_any(
            $vary,
            static fn (mixed $varyValue): bool => is_string($varyValue)
                && 0 === strcasecmp($varyValue, 'cookie'),
        );
        if (!$hasCookieVary) {
            $vary[] = 'Cookie';
        }
        $response->setVary($vary);
        $response->headers->set('Cache-Control', 'private, no-store');

        if (null === $this->analytics->current()) {
            return;
        }

        $policy = $response->headers->get(ContentSecurityPolicyReportOnlySubscriber::HEADER);
        if (!is_string($policy) || '' === trim($policy) || false !== strpbrk($policy, "\r\n")) {
            return;
        }

        $extended = self::addSources($policy, self::SOURCES);
        if (null !== $extended) {
            $response->headers->set(ContentSecurityPolicyReportOnlySubscriber::HEADER, $extended);
        }
    }

    /** @param array<string, list<string>> $sourcesByDirective */
    private static function addSources(string $policy, array $sourcesByDirective): ?string
    {
        $directives = explode(';', $policy);
        $matches = array_fill_keys(array_keys($sourcesByDirective), 0);
        foreach ($directives as &$directive) {
            $tokens = preg_split('/\s+/', trim($directive));
            if (!is_array($tokens) || [] === $tokens) {
                continue;
            }

            $name = $tokens[0];
            if (!isset($sourcesByDirective[$name])) {
                continue;
            }
            ++$matches[$name];
            if (!in_array("'self'", array_slice($tokens, 1), true)) {
                return null;
            }

            foreach ($sourcesByDirective[$name] as $source) {
                if (!in_array($source, array_slice($tokens, 1), true)) {
                    $tokens[] = $source;
                }
            }
            $directive = implode(' ', $tokens);
        }
        unset($directive);

        foreach ($matches as $name => $count) {
            if ($count > 1) {
                return null;
            }
            if (0 === $count) {
                $directives[] = $name." 'self' ".implode(' ', $sourcesByDirective[$name]);
            }
        }

        return implode(';', $directives);
    }
}
