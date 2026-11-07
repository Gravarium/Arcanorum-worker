<?php

declare(strict_types=1);

namespace App\Entity;

enum FeatureModule: string
{
    case News = 'news';
    case Series = 'series';
    case Comments = 'comments';
    case Contact = 'contact';
    case Pages = 'pages';
    case Search = 'search';
    case Social = 'social';

    public function inputName(): string
    {
        return 'module_'.$this->value.'_enabled';
    }
}
