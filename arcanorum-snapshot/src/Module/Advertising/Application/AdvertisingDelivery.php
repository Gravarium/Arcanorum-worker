<?php

declare(strict_types=1);

namespace App\Module\Advertising\Application;

use App\Module\Advertising\Domain\AdStatus;
use App\Module\Advertising\Domain\Placement;

final readonly class AdvertisingDelivery
{
    public function __construct(
        private AdvertisingStore $store,
        private StatisticsStore $statistics,
        private AdvertisingRenderer $renderer,
    ) {
    }

    public function renderPlacement(string $placementId, \DateTimeImmutable $at): string
    {
        $placement = $this->store->findPlacement($placementId);
        if (null === $placement || $placement->archived) {
            throw new \DomainException('Advertising placement is unavailable.');
        }
        if ($placement->paused) {
            return '';
        }

        $output = '';
        foreach ($this->store->listAdvertisementsByStatus($placementId, AdStatus::Active) as $advertisement) {
            if (!$placement->supports($advertisement->format)) {
                continue;
            }

            $rendered = $this->renderer->render($advertisement, $at);
            $this->statistics->recordImpression($advertisement->id, $placement->id, $at);
            $output .= $rendered;

            if (Placement::VIDEO_PREROLL_ID === $placementId) {
                break;
            }
        }

        return $output;
    }
}
