<?php

declare(strict_types=1);

namespace App\Module\Admin\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

final readonly class AdminLogoutRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        return 'admin_logout' === $request->attributes->get('_route');
    }
}
