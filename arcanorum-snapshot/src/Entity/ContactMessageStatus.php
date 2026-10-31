<?php

declare(strict_types=1);

namespace App\Entity;

enum ContactMessageStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
}
