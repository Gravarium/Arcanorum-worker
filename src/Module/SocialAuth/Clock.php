<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
