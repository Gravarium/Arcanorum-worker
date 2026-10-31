<?php

declare(strict_types=1);

namespace App\Entity;

enum CommentSortOrder: string
{
    case Added = 'added';
    case Score = 'score';
}
