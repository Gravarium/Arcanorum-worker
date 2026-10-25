<?php

declare(strict_types=1);

namespace App\Tests\Module\Member\EventSubscriber;

use App\Module\Member\EventSubscriber\MemberSensitiveReferrerPolicySubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class MemberSensitiveReferrerPolicySubscriberTest extends TestCase
{
    public function testSensitiveMemberRoutesSuppressReferrersAfterTheGlobalDefault(): void
    {
        $request = Request::create('/konto/passwort-zuruecksetzen/'.str_repeat('a', 43));
        $request->attributes->set('_route', 'member_password_reset');
        $response = new Response();
        $event = new ResponseEvent($this->testKernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);

        new MemberSensitiveReferrerPolicySubscriber()->onKernelResponse($event);

        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function testOtherRoutesAndSubrequestsKeepTheGlobalPolicy(): void
    {
        $request = Request::create('/konto/profil');
        $request->attributes->set('_route', 'member_profile');
        $response = new Response('', Response::HTTP_OK, ['Referrer-Policy' => 'strict-origin-when-cross-origin']);
        $event = new ResponseEvent($this->testKernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber = new MemberSensitiveReferrerPolicySubscriber();
        $subscriber->onKernelResponse($event);
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));

        $subrequest = new ResponseEvent($this->testKernel(), $request, HttpKernelInterface::SUB_REQUEST, new Response());
        $subscriber->onKernelResponse($subrequest);
        self::assertFalse($subrequest->getResponse()->headers->has('Referrer-Policy'));
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
