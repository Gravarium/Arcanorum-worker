<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

enum Flow: string
{
    case LOGIN = 'login';
    case REGISTRATION = 'registration';
    case LINK = 'link';
}
