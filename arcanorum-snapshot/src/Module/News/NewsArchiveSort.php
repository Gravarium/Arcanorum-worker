<?php

declare(strict_types=1);

namespace App\Module\News;

enum NewsArchiveSort: string
{
    case Newest = 'newest';
    case Popular = 'popular';
    case Comments = 'comments';

    public static function fromQuery(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::Newest : self::Newest;
    }
}
