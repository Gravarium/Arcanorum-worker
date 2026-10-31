<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\RateLimitKeyFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RateLimitKeyFactoryTest extends TestCase
{
    public function testIpKeysAreStableScopedAndKeyedByTheApplicationSecret(): void
    {
        $request = self::requestFrom('192.0.2.25');
        $keys = new RateLimitKeyFactory('test-application-secret');

        $loginKey = $keys->forIp($request, 'member-social-login-start');

        self::assertSame($loginKey, $keys->forIp($request, 'member-social-login-start'));
        self::assertNotSame($loginKey, $keys->forIp($request, 'member-social-login-callback'));
        self::assertStringNotContainsString('192.0.2.25', $loginKey);
        self::assertNotSame(
            $loginKey,
            new RateLimitKeyFactory('another-secret')->forIp($request, 'member-social-login-start'),
        );
    }

    public function testIdentityKeysAreStableScopedAndDoNotExposeTheIdentifier(): void
    {
        $keys = new RateLimitKeyFactory('test-application-secret');
        $identifier = 'administrator@example.test';
        $key = $keys->forIdentity($identifier, 'video-provider-import');

        self::assertSame($key, $keys->forIdentity($identifier, 'video-provider-import'));
        self::assertNotSame($key, $keys->forIdentity($identifier, 'another-purpose'));
        self::assertNotSame($key, $keys->forIdentity('another@example.test', 'video-provider-import'));
        self::assertStringNotContainsString($identifier, $key);
    }

    public function testIdentityAndIpKeysAreStableAndSeparateIdentityAddressAndPurpose(): void
    {
        $keys = new RateLimitKeyFactory('test-application-secret');
        $request = self::requestFrom('192.0.2.25');
        $key = $keys->forIdentityAndIp('admin-identifier', $request, 'admin-email-test');

        self::assertSame($key, $keys->forIdentityAndIp('admin-identifier', $request, 'admin-email-test'));
        self::assertNotSame($key, $keys->forIdentityAndIp('another-admin', $request, 'admin-email-test'));
        self::assertNotSame($key, $keys->forIdentityAndIp('admin-identifier', self::requestFrom('192.0.2.26'), 'admin-email-test'));
        self::assertNotSame($key, $keys->forIdentityAndIp('admin-identifier', $request, 'another-purpose'));
        self::assertStringNotContainsString('admin-identifier', $key);
        self::assertStringNotContainsString('192.0.2.25', $key);
    }

    private static function requestFrom(string $ip): Request
    {
        $request = Request::create('/');
        $request->server->set('REMOTE_ADDR', $ip);

        return $request;
    }
}
