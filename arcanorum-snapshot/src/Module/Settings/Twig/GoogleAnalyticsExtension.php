<?php

declare(strict_types=1);

namespace App\Module\Settings\Twig;

use App\Service\GoogleAnalyticsContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class GoogleAnalyticsExtension extends AbstractExtension
{
    public function __construct(private readonly GoogleAnalyticsContext $analytics)
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [new TwigFunction('google_analytics_context', $this->analytics->current(...))];
    }
}
