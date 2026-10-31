<?php

declare(strict_types=1);

namespace App\Entity;

enum ContentRatingValue: string
{
    case Like = 'like';
    case Dislike = 'dislike';
}
