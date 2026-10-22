<?php

declare(strict_types=1);

namespace App\Module\Advertising\Application;

use App\Module\Advertising\Domain\AdStatus;
use App\Module\Advertising\Domain\SafeDestination;

final readonly class AdvertisingClickService
{
    public function __construct(
        private AdvertisingStore $store,
        private StatisticsStore $statistics,
        private ClickTokenSigner $tokens,
    ) {
    }

    public function destinationForClick(string $advertisementId, string $token, \DateTimeImmutable $at): ?string
    {
        $advertisement = $this->store->findAdvertisement($advertisementId);
        if (null === $advertisement || AdStatus::Active !== $advertisement->status) {
            return null;
        }

        $placement = $this->store->findPlacement($advertisement->placementId);
        if (null === $placement || $placement->archived || !$placement->supports($advertisement->format)) {
            return null;
        }

        $eventDigest = $this->tokens->verify($advertisementId, $token, $at);
        if (null === $eventDigest) {
            return null;
        }

        try {
            $destination = SafeDestination::fromString($advertisement->destinationUrl)->url;
        } catch (\InvalidArgumentException) {
            return null;
        }

        $this->statistics->recordClick($advertisement->id, $placement->id, $eventDigest, $at);

        return $destination;
    }
}
