<?php

declare(strict_types=1);

namespace App\Module\Advertising\Application;

use App\Module\Advertising\Domain\AdStatus;
use App\Module\Advertising\Domain\Advertisement;
use App\Module\Advertising\Domain\Placement;

interface AdvertisingStore
{
    public function findPlacement(string $id): ?Placement;

    /** @return list<Placement> */
    public function listPlacements(): array;

    public function savePlacement(Placement $placement): void;

    public function findAdvertisement(string $id): ?Advertisement;

    /** @return list<Advertisement> */
    public function listAdvertisements(?string $placementId = null): array;

    /** @return list<Advertisement> */
    public function listAdvertisementsByStatus(string $placementId, AdStatus $status): array;

    public function saveAdvertisement(Advertisement $advertisement): void;
}
