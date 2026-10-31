<?php

declare(strict_types=1);

namespace App\Entity;

enum VideoPreloadMode: string
{
    case None = 'none';
    case Metadata = 'metadata';
    case Auto = 'auto';
}
