<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TrustedProxyPolicyTest extends TestCase
{
    public function testExplicitTrustedProxyUsesOnlyForwardedIpAndScheme(): void
    {
        $this->withTrustedProxyConfiguration(function (): void {
            $request = Request::create(
                'http://app.example.test/admin/login',
                Request::METHOD_GET,
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => '192.0.2.10',
                    'HTTP_X_FORWARDED_FOR' => '203.0.113.15',
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'HTTP_X_FORWARDED_HOST' => 'spoofed.example.test',
                ],
            );

            self::assertSame('203.0.113.15', $request->getClientIp());
            self::assertSame('https', $request->getScheme());
            self::assertSame('app.example.test', $request->getHost());
        });
    }

    public function testUntrustedSourceCannotOverrideForwardedIpSchemeOrHost(): void
    {
        $this->withTrustedProxyConfiguration(function (): void {
            $request = Request::create(
                'http://app.example.test/admin/login',
                Request::METHOD_GET,
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => '192.0.2.11',
                    'HTTP_X_FORWARDED_FOR' => '198.51.100.25',
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'HTTP_X_FORWARDED_HOST' => 'spoofed.example.test',
                ],
            );

            self::assertSame('192.0.2.11', $request->getClientIp());
            self::assertSame('http', $request->getScheme());
            self::assertSame('app.example.test', $request->getHost());
        });
    }

    public function testMalformedForwardedIpFallsBackToTheTrustedProxyAddress(): void
    {
        $this->withTrustedProxyConfiguration(function (): void {
            $request = Request::create(
                'http://app.example.test/admin/login',
                Request::METHOD_GET,
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => '192.0.2.10',
                    'HTTP_X_FORWARDED_FOR' => 'not-an-ip',
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'HTTP_X_FORWARDED_HOST' => 'spoofed.example.test',
                ],
            );

            self::assertSame('192.0.2.10', $request->getClientIp());
            self::assertSame('https', $request->getScheme());
            self::assertSame('app.example.test', $request->getHost());
        });
    }

    /** @param callable(): void $test */
    private function withTrustedProxyConfiguration(callable $test): void
    {
        $previousTrustedProxies = Request::getTrustedProxies();
        /** @var int<0, 63> $previousTrustedHeaders */
        $previousTrustedHeaders = Request::getTrustedHeaderSet();

        Request::setTrustedProxies(
            ['192.0.2.10'],
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        try {
            $test();
        } finally {
            Request::setTrustedProxies($previousTrustedProxies, $previousTrustedHeaders);
        }
    }
}
