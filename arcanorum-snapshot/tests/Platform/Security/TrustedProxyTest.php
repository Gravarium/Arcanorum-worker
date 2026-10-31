<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class TrustedProxyTest extends WebTestCase
{
    public function testUntrustedForwardedForCannotOverrideDirectClientAddress(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertSame([], Request::getTrustedProxies());

        $request = Request::create('https://arcanorum.example/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.25',
        ]);

        self::assertSame('198.51.100.10', $request->getClientIp());
    }

    public function testUntrustedForwardedProtoCannotMakeDirectHttpSecure(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertSame([], Request::getTrustedProxies());

        $request = Request::create('http://arcanorum.example/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertFalse($request->isSecure());
    }
}
