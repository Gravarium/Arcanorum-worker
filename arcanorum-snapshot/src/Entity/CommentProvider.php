<?php

declare(strict_types=1);

namespace App\Entity;

enum CommentProvider: string
{
    case Native = 'native';
    case Facebook = 'facebook';
    case Disqus = 'disqus';
}
