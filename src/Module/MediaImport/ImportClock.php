<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

interface ImportClock
{
    public function now(): \DateTimeImmutable;
}
