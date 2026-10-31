<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\DecodedAdminAreaRequestMatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DecodedAdminAreaRequestMatcherTest extends TestCase
{
    public function testMatchesAdminPathsAfterUrlDecoding(): void
    {
        $matcher = new DecodedAdminAreaRequestMatcher();

        foreach (['/admin', '/admin/dashboard', '/%61dmin', '/%61dmin/dashboard', '/admin%2Fdashboard'] as $path) {
            self::assertTrue($matcher->matches(Request::create($path)), $path);
        }
    }

    public function testKeepsDecodedLoginRoutePublic(): void
    {
        $matcher = new DecodedAdminAreaRequestMatcher();

        foreach (['/admin/login', '/%61dmin/login', '/admin%2Flogin'] as $path) {
            self::assertFalse($matcher->matches(Request::create($path)), $path);
        }
    }

    public function testDoesNotMatchPathsOutsideAdminArea(): void
    {
        $matcher = new DecodedAdminAreaRequestMatcher();

        foreach (['/', '/administer', '/public/admin'] as $path) {
            self::assertFalse($matcher->matches(Request::create($path)), $path);
        }
    }
}
