<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\ContentSecurityPolicyReportOnlySubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ContentSecurityPolicyReportOnlySubscriberTest extends TestCase
{
    private const string POLICY = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; media-src 'self'";

    public function testMainResponseReceivesPolicyForExplicitlyConfiguredRoute(): void
    {
        $response = new Response();
        $event = $this->responseEvent($response, 'platform_home', HttpKernelInterface::MAIN_REQUEST);

        $subscriber = new ContentSecurityPolicyReportOnlySubscriber([
            'platform_home' => self::POLICY,
        ]);
        $subscriber->onKernelResponse($event);

        self::assertSame([self::POLICY], $response->headers->all(ContentSecurityPolicyReportOnlySubscriber::HEADER));
    }

    public function testUnlistedRouteReceivesNoGenericPolicy(): void
    {
        $response = new Response();
        $event = $this->responseEvent($response, 'new_video_route', HttpKernelInterface::MAIN_REQUEST);

        $subscriber = new ContentSecurityPolicyReportOnlySubscriber([
            'platform_home' => self::POLICY,
        ]);
        $subscriber->onKernelResponse($event);

        self::assertFalse($response->headers->has(ContentSecurityPolicyReportOnlySubscriber::HEADER));
    }

    public function testSubrequestDoesNotReceivePolicy(): void
    {
        $response = new Response();
        $event = $this->responseEvent($response, 'platform_home', HttpKernelInterface::SUB_REQUEST);

        $subscriber = new ContentSecurityPolicyReportOnlySubscriber([
            'platform_home' => self::POLICY,
        ]);
        $subscriber->onKernelResponse($event);

        self::assertFalse($response->headers->has(ContentSecurityPolicyReportOnlySubscriber::HEADER));
    }

    public function testExistingPolicyIsPreservedAlongsideConfiguredPolicy(): void
    {
        $response = new Response();
        $response->headers->set(ContentSecurityPolicyReportOnlySubscriber::HEADER, "default-src 'none'");
        $event = $this->responseEvent($response, 'platform_home', HttpKernelInterface::MAIN_REQUEST);

        $subscriber = new ContentSecurityPolicyReportOnlySubscriber([
            'platform_home' => self::POLICY,
        ]);
        $subscriber->onKernelResponse($event);

        self::assertSame(
            ["default-src 'none'", self::POLICY],
            $response->headers->all(ContentSecurityPolicyReportOnlySubscriber::HEADER),
        );
    }

    public function testPolicyWithLineBreakIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentSecurityPolicyReportOnlySubscriber([
            'platform_home' => self::POLICY."\r\nX-Injected: value",
        ]);
    }

    private function responseEvent(Response $response, string $routeName, int $requestType): ResponseEvent
    {
        $request = Request::create('/');
        $request->attributes->set('_route', $routeName);

        return new ResponseEvent($this->testKernel(), $request, $requestType, $response);
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
