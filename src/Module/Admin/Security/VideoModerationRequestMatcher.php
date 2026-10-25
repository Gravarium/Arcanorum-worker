<?php

declare(strict_types=1);

namespace App\Module\Admin\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

final readonly class VideoModerationRequestMatcher implements RequestMatcherInterface
{
    private const array ROUTES = [
        'admin_video_report_index',
        'admin_video_report_moderate',
        'admin_video_submission_index',
        'admin_video_submission_review',
        'admin_video_submission_preview',
    ];

    public function matches(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        return is_string($route) && in_array($route, self::ROUTES, true);
    }
}
