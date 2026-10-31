<?php

declare(strict_types=1);

namespace App\Module\Comment\EventSubscriber;

use App\Entity\CommentProvider;
use App\Platform\Security\ContentSecurityPolicyReportOnlySubscriber;
use App\Service\CookieConsentService;
use App\Service\SiteSettingsProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ExternalCommentContentSecurityPolicySubscriber implements EventSubscriberInterface
{
    private const array ROUTES = ['news_show', 'video_show'];

    public function __construct(
        private CookieConsentService $consent,
        private SiteSettingsProvider $settings,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['extendReportOnlyPolicyAfterConsent', -128]];
    }

    public function extendReportOnlyPolicyAfterConsent(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()
            || !in_array($event->getRequest()->attributes->get('_route'), self::ROUTES, true)
            || !$this->consent->allowsOptionalServices()
        ) {
            return;
        }

        $settings = $this->settings->current();
        $sources = match ($settings->getCommentProvider()) {
            CommentProvider::Native => null,
            CommentProvider::Facebook => [
                'script-src' => ['https://connect.facebook.net'],
                'connect-src' => ['https://connect.facebook.net', 'https://www.facebook.com', 'https://graph.facebook.com'],
                'frame-src' => ['https://www.facebook.com', 'https://web.facebook.com'],
                'img-src' => ['https://static.xx.fbcdn.net', 'https://*.fbcdn.net'],
                'style-src' => ['https://static.xx.fbcdn.net'],
            ],
            CommentProvider::Disqus => [
                'script-src' => ['https://'.$settings->getDisqusCommentsShortname().'.disqus.com'],
                'connect-src' => ['https://disqus.com', 'https://*.disqus.com'],
                'frame-src' => ['https://disqus.com', 'https://*.disqus.com'],
                'img-src' => ['https://referrer.disqus.com', 'https://*.disquscdn.com'],
                'style-src' => ['https://*.disquscdn.com'],
            ],
        };
        if (null === $sources) {
            return;
        }

        $response = $event->getResponse();
        $policy = $response->headers->get(ContentSecurityPolicyReportOnlySubscriber::HEADER);
        if (!is_string($policy)) {
            return;
        }

        $extended = self::addSources($policy, $sources);
        if (null !== $extended) {
            $response->headers->set(ContentSecurityPolicyReportOnlySubscriber::HEADER, $extended);
        }
    }

    /** @param array<string, list<string>> $sourcesByDirective */
    private static function addSources(string $policy, array $sourcesByDirective): ?string
    {
        if ('' === trim($policy) || false !== strpbrk($policy, "\r\n")) {
            return null;
        }

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
