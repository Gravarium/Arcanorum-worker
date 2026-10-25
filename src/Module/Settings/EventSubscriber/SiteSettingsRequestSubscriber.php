<?php

declare(strict_types=1);

namespace App\Module\Settings\EventSubscriber;

use App\Service\SiteSettingsProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use Twig\Extension\CoreExtension;

final readonly class SiteSettingsRequestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SiteSettingsProvider $settings,
        private Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Run after routing (32) and before Symfony's locale initialization (16).
        return [
            KernelEvents::REQUEST => ['applySiteDefaults', 17],
            KernelEvents::RESPONSE => ['applySearchIndexingPolicy', -10],
        ];
    }

    public function applySiteDefaults(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $settings = $this->settings->current();
        $request = $event->getRequest();
        $request->setDefaultLocale($settings->getDefaultLocale());
        $request->attributes->set('_arcanorum_search_indexing_enabled', $settings->isSearchIndexingEnabled());
        if (!$request->attributes->has('_locale')) {
            $request->setLocale($settings->getDefaultLocale());
        }

        $this->twig->getExtension(CoreExtension::class)->setTimezone($settings->getTimezone());
    }

    public function applySearchIndexingPolicy(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (false === $event->getRequest()->attributes->get('_arcanorum_search_indexing_enabled')) {
            $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}
