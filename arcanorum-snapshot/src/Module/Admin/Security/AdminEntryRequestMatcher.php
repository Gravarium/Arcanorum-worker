<?php

declare(strict_types=1);

namespace App\Module\Admin\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

final readonly class AdminEntryRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        return 'admin_dashboard' === $request->attributes->get('_route');
    }
}
