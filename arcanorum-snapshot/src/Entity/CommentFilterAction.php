<?php

declare(strict_types=1);

namespace App\Entity;

enum CommentFilterAction: string
{
    case Review = 'review';
    case Reject = 'reject';
    case Censor = 'censor';
}
