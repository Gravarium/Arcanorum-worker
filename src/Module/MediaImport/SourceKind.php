<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

enum SourceKind: string
{
    case Search = 'search';
    case Creator = 'creator';
    case Collection = 'collection';
}
