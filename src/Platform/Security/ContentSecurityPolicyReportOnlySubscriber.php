<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ContentSecurityPolicyReportOnlySubscriber implements EventSubscriberInterface
{
    public const string HEADER = 'Content-Security-Policy-Report-Only';

    /**
     * @var array<string, string>
     */
    private array $routePolicies;

    /**
     * @param array<string, string> $routePolicies
     */
    public function __construct(array $routePolicies)
    {
        foreach ($routePolicies as $routeName => $policy) {
            if ('' === $routeName || '' === trim($policy) || false !== strpbrk($policy, "\r\n")) {
                throw new \InvalidArgumentException('CSP-Routenrichtlinien müssen nichtleer und einzeilig sein.');
            }
        }

        $this->routePolicies = $routePolicies;
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $routeName = $event->getRequest()->attributes->get('_route');
        if (!is_string($routeName)) {
            return;
        }

        $policy = $this->routePolicies[$routeName] ?? null;
        if (!is_string($policy)) {
            return;
        }

        $event->getResponse()->headers->set(self::HEADER, $policy, false);
    }
}
