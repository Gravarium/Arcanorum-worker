<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

interface ProviderAdapter
{
    public function provider(): MediaProvider;

    public function fetch(ImportSource $source, ?string $cursor, int $pageSize): ProviderPage;
}
