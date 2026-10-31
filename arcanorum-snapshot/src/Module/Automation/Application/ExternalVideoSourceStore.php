<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

use App\Entity\Video;

interface ExternalVideoSourceStore
{
    /** @return list<Video> */
    public function findExternalSourcesNeedingCheck(\DateTimeImmutable $cutoff, int $limit = 25): array;
}
