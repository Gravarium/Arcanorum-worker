<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Module\Admin\Security\EditorialContentRequestMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class EditorialContentRequestMatcherTest extends TestCase
{
    #[DataProvider('routeCases')]
    public function testOnlyEditorialRoutesAreMatched(string $route, ?string $contentType, bool $expected): void
    {
        $request = Request::create('/unrelated-path');
        $request->attributes->set('_route', $route);
        if (null !== $contentType) {
            $request->attributes->set('type', $contentType);
        }

        self::assertSame($expected, new EditorialContentRequestMatcher()->matches($request));
    }

    /** @return iterable<string, array{string, ?string, bool}> */
    public static function routeCases(): iterable
    {
        yield 'news list' => ['admin_news_index', null, true];
        yield 'news history' => ['admin_news_history', null, true];
        yield 'news category list' => ['admin_category_index', null, true];
        yield 'news category deletion' => ['admin_category_delete', null, true];
        yield 'news inline image upload' => ['admin_content_inline_image_upload', 'news', true];
        yield 'news category inline image upload' => ['admin_content_inline_image_upload', 'news-category', true];
        yield 'news category image preview' => ['admin_content_inline_image_preview', 'news-category', true];
        yield 'video category image preview is admin-only' => ['admin_content_inline_image_preview', 'video-category', false];
        yield 'video category inline image upload is admin-only' => ['admin_content_inline_image_upload', 'video-category', false];
        yield 'page inline image upload is excluded' => ['admin_content_inline_image_upload', 'page', false];
        yield 'future news route is excluded until explicitly reviewed' => ['admin_news_import', null, false];
        yield 'future category route is excluded until explicitly reviewed' => ['admin_category_import', null, false];
        yield 'staff management is excluded' => ['admin_staff_index', null, false];
        yield 'unknown route is excluded' => ['unrelated', null, false];
    }
}
