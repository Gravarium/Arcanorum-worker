<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

final readonly class DecodedAdminAreaRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        $path = AdminAreaRequestPath::decode($request->getPathInfo());

        return AdminAreaRequestPath::isAdminArea($path)
            && '/admin/login' !== $path;
    }
}
