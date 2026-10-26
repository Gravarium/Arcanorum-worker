<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Support;

use App\Module\Advertising\Application\AdvertisingStore;
use App\Module\Advertising\Domain\AdStatus;
use App\Module\Advertising\Domain\Advertisement;
use App\Module\Advertising\Domain\Placement;

final class InMemoryAdvertisingStore implements AdvertisingStore
{
    /** @var array<string, Placement> */
    private array $placements = [];

    /** @var array<string, Advertisement> */
    private array $advertisements = [];

    public function findPlacement(string $id): ?Placement
    {
        return $this->placements[$id] ?? null;
    }

    public function listPlacements(): array
    {
        return array_values($this->placements);
    }

    public function savePlacement(Placement $placement): void
    {
        $this->placements[$placement->id] = $placement;
    }

    public function findAdvertisement(string $id): ?Advertisement
    {
        return $this->advertisements[$id] ?? null;
    }

    public function listAdvertisements(?string $placementId = null): array
    {
        return array_values(array_filter(
            $this->advertisements,
            static fn (Advertisement $advertisement): bool => null === $placementId || $advertisement->placementId === $placementId,
        ));
    }

    public function listAdvertisementsByStatus(string $placementId, AdStatus $status): array
    {
        return array_values(array_filter(
            $this->advertisements,
            static fn (Advertisement $advertisement): bool => $advertisement->placementId === $placementId && $advertisement->status === $status,
        ));
    }

    public function saveAdvertisement(Advertisement $advertisement): void
    {
        $this->advertisements[$advertisement->id] = $advertisement;
    }
}
