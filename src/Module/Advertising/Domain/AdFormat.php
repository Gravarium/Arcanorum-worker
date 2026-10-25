<?php

declare(strict_types=1);

namespace App\Module\Advertising\Domain;

enum AdFormat: string
{
    case HtmlBanner = 'html_banner';
    case StaticPlayer = 'static_player';
    case Video = 'video';
}
