<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

use DateTimeImmutable;

interface ImportClock
{
    public function now(): DateTimeImmutable;
}
