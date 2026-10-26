<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
