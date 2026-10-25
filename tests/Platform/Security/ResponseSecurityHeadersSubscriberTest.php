<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\ResponseSecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ResponseSecurityHeadersSubscriberTest extends TestCase
{
    public function testMainResponseReceivesDocumentedSecurityHeaders(): void
    {
        $response = new Response();
        $event = new ResponseEvent(
            $this->testKernel(),
            Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        new ResponseSecurityHeadersSubscriber()->onKernelResponse($event);

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame(
            'camera=(), geolocation=(), microphone=()',
            $response->headers->get('Permissions-Policy'),
        );
    }

    public function testSubrequestResponseRemainsUntouched(): void
    {
        $response = new Response();
        $event = new ResponseEvent(
            $this->testKernel(),
            Request::create('/'),
            HttpKernelInterface::SUB_REQUEST,
            $response,
        );

        new ResponseSecurityHeadersSubscriber()->onKernelResponse($event);

        self::assertFalse($response->headers->has('X-Content-Type-Options'));
        self::assertFalse($response->headers->has('X-Frame-Options'));
        self::assertFalse($response->headers->has('Referrer-Policy'));
        self::assertFalse($response->headers->has('Permissions-Policy'));
    }

    private function testKernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(
                Request $request,
                int $type = HttpKernelInterface::MAIN_REQUEST,
                bool $catch = true,
            ): Response {
                return new Response();
            }
        };
    }
}
