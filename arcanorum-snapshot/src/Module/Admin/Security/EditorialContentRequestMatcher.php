<?php

declare(strict_types=1);

namespace App\Module\Admin\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

final readonly class EditorialContentRequestMatcher implements RequestMatcherInterface
{
    /** @var list<string> */
    private const array EDITORIAL_ROUTES = [
        'admin_news_index',
        'admin_news_bulk',
        'admin_news_new',
        'admin_news_edit',
        'admin_news_history',
        'admin_news_thumbnail_preview',
        'admin_category_index',
        'admin_category_new',
        'admin_category_edit',
        'admin_category_delete',
    ];

    public function matches(Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if (!is_string($route)) {
            return false;
        }

        if (in_array($route, self::EDITORIAL_ROUTES, true)) {
            return true;
        }

        if ('admin_content_inline_image_upload' === $route) {
            return in_array($request->attributes->get('type'), ['news', 'news-category'], true);
        }

        return 'admin_content_inline_image_preview' === $route
            && 'news-category' === $request->attributes->get('type');
    }
}
