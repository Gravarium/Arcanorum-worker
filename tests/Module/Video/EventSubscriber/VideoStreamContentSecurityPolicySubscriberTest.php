<?php

declare(strict_types=1);

namespace App\Tests\Module\Video\EventSubscriber;

use App\Module\Video\EventSubscriber\VideoStreamContentSecurityPolicySubscriber;
use App\Module\Video\Service\VideoStreamSource;
use App\Module\Video\Service\VideoStreamSourceResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class VideoStreamContentSecurityPolicySubscriberTest extends TestCase
{
    private const string VIDEO_POLICY = "default-src 'self'; connect-src 'self'; media-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com";

    public function testApprovedStreamAddsNarrowReportOnlyMediaPolicyWithoutEnforcement(): void
    {
        $request = Request::create('/videos/'.str_repeat('a', 32));
        $request->attributes->set('_route', 'video_show');
        $request->attributes->set(VideoStreamSource::REQUEST_ATTRIBUTE, 'https://media.example.org');
        $response = new Response('', Response::HTTP_OK, [
            'Content-Security-Policy-Report-Only' => self::VIDEO_POLICY,
        ]);
        $event = new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber = new VideoStreamContentSecurityPolicySubscriber(
            new VideoStreamSourceResolver(['media.example.org']),
            ['video_show' => self::VIDEO_POLICY],
        );

        $subscriber->onKernelResponse($event);

        $expectedPolicy = str_replace("media-src 'self'", "media-src 'self' https://media.example.org", self::VIDEO_POLICY);
        $reportOnlyPolicy = $response->headers->get('Content-Security-Policy-Report-Only');
        self::assertSame($expectedPolicy, $reportOnlyPolicy);
        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertStringContainsString("frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com", $reportOnlyPolicy);
        self::assertStringNotContainsString('*', $reportOnlyPolicy);
    }

    public function testUnapprovedOriginAndSubrequestsDoNotChangeCsp(): void
    {
        $request = Request::create('/videos/'.str_repeat('b', 32));
        $request->attributes->set('_route', 'video_show');
        $request->attributes->set(VideoStreamSource::REQUEST_ATTRIBUTE, 'https://unapproved.example.org');
        $response = new Response('', Response::HTTP_OK, [
            'Content-Security-Policy-Report-Only' => "default-src 'self'; media-src 'self'",
        ]);
        $subscriber = new VideoStreamContentSecurityPolicySubscriber(
            new VideoStreamSourceResolver(['media.example.org']),
            ['video_show' => self::VIDEO_POLICY],
        );

        $subscriber->onKernelResponse(new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertSame("default-src 'self'; media-src 'self'", $response->headers->get('Content-Security-Policy-Report-Only'));

        $subrequest = Request::create('/videos/'.str_repeat('b', 32));
        $subrequest->attributes->set('_route', 'video_show');
        $subrequest->attributes->set(VideoStreamSource::REQUEST_ATTRIBUTE, 'https://media.example.org');
        $subresponse = new Response();
        $subscriber->onKernelResponse(new ResponseEvent($this->kernel(), $subrequest, HttpKernelInterface::SUB_REQUEST, $subresponse));
        self::assertFalse($subresponse->headers->has('Content-Security-Policy'));
    }

    public function testHlsPlaybackAddsOnlyTheConfiguredOriginsAndRequiredBrowserSources(): void
    {
        $request = Request::create('/videos/'.str_repeat('d', 32));
        $request->attributes->set('_route', 'video_show');
        $request->attributes->set(VideoStreamSource::REQUEST_ATTRIBUTE, 'https://live.example.org');
        $request->attributes->set(VideoStreamSource::HLS_REQUEST_ATTRIBUTE, true);
        $response = new Response();
        $subscriber = new VideoStreamContentSecurityPolicySubscriber(
            new VideoStreamSourceResolver(['live.example.org', 'segments.example.org']),
            ['video_show' => self::VIDEO_POLICY],
        );

        $subscriber->onKernelResponse(new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        $policy = $response->headers->get('Content-Security-Policy-Report-Only');
        self::assertIsString($policy);
        self::assertStringContainsString("connect-src 'self' https://live.example.org https://segments.example.org", $policy);
        self::assertStringContainsString("media-src 'self' https://live.example.org blob: https://segments.example.org", $policy);
        self::assertStringContainsString("worker-src 'self' blob:", $policy);
        self::assertStringNotContainsString('*', $policy);
        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function testHlsFailsClosedWhenRoutePolicyLacksConnectRestriction(): void
    {
        $request = Request::create('/videos/'.str_repeat('e', 32));
        $request->attributes->set('_route', 'video_show');
        $request->attributes->set(VideoStreamSource::REQUEST_ATTRIBUTE, 'https://live.example.org');
        $request->attributes->set(VideoStreamSource::HLS_REQUEST_ATTRIBUTE, true);
        $response = new Response();
        $subscriber = new VideoStreamContentSecurityPolicySubscriber(
            new VideoStreamSourceResolver(['live.example.org']),
            ['video_show' => "default-src 'self'; media-src 'self'"],
        );

        $subscriber->onKernelResponse(new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testMalformedRoutePolicyDoesNotCreateAnEnforcedPolicy(): void
    {
        $request = Request::create('/videos/'.str_repeat('c', 32));
        $request->attributes->set('_route', 'video_show');
        $request->attributes->set(VideoStreamSource::REQUEST_ATTRIBUTE, 'https://media.example.org');
        $response = new Response();
        $subscriber = new VideoStreamContentSecurityPolicySubscriber(
            new VideoStreamSourceResolver(['media.example.org']),
            ['video_show' => "default-src 'self'; media-src https://other.example.org"],
        );

        $subscriber->onKernelResponse(new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    private function kernel(): HttpKernelInterface
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
