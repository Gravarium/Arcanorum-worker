<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

enum Provider: string
{
    case GOOGLE = 'google';
    case FACEBOOK = 'facebook';
    case TWITTER = 'twitter';
}
