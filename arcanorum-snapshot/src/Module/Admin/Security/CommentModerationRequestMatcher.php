<?php

declare(strict_types=1);

namespace App\Module\Admin\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

final readonly class CommentModerationRequestMatcher implements RequestMatcherInterface
{
    private const array ROUTES = [
        'admin_comment_index',
        'admin_comment_moderate',
    ];

    public function matches(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        return is_string($route) && in_array($route, self::ROUTES, true);
    }
}
