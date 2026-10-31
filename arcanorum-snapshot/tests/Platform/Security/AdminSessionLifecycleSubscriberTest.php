<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Entity\AdminUser;
use App\Platform\Security\AdminSessionLifecycleSubscriber;
use App\Platform\Security\AdminSessionLifetime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class AdminSessionLifecycleSubscriberTest extends TestCase
{
    private const string ISSUED_AT_SESSION_KEY = '_arcanorum_admin_authenticated_at';

    private const string LAST_ACTIVITY_SESSION_KEY = '_arcanorum_admin_last_activity_at';

    public function testIdleTimeoutInvalidatesSessionAndAuthenticationToken(): void
    {
        $session = $this->session(
            time() - 60,
            time() - AdminSessionLifetime::IDLE_TIMEOUT_SECONDS,
        );
        $tokenStorage = $this->tokenStorage(true);
        $event = $this->requestEvent($session);

        new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime())->onKernelRequest($event);

        $this->assertExpired($event, $session, $tokenStorage);
    }

    public function testExpiredAdminSubrequestInvalidatesSessionAndAuthenticationToken(): void
    {
        $session = $this->session(
            time() - 60,
            time() - AdminSessionLifetime::IDLE_TIMEOUT_SECONDS,
        );
        $tokenStorage = $this->tokenStorage(true);
        $event = $this->requestEvent($session, requestType: HttpKernelInterface::SUB_REQUEST);

        new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime())->onKernelRequest($event);

        $this->assertExpired($event, $session, $tokenStorage);
    }

    public function testGetLogoutPathInvalidatesAnExpiredAdminSession(): void
    {
        $session = $this->session(
            time() - 60,
            time() - AdminSessionLifetime::IDLE_TIMEOUT_SECONDS,
        );
        $tokenStorage = $this->tokenStorage(true);
        $event = $this->requestEvent($session, '/admin/logout', Request::METHOD_GET);

        new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime())->onKernelRequest($event);

        $this->assertExpired($event, $session, $tokenStorage);
    }

    public function testEncodedAdminPathsCannotBypassExpiredSessionCheck(): void
    {
        foreach ([
            ['/admin%2Fdashboard', Request::METHOD_GET],
            ['/%61dmin/dashboard', Request::METHOD_GET],
            ['/admin%2Flogout', Request::METHOD_POST],
        ] as [$path, $method]) {
            $session = $this->session(
                time() - 60,
                time() - AdminSessionLifetime::IDLE_TIMEOUT_SECONDS,
            );
            $tokenStorage = $this->tokenStorage(true);
            $event = $this->requestEvent($session, $path, $method);

            new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime())->onKernelRequest($event);

            $this->assertExpired($event, $session, $tokenStorage);
        }
    }

    public function testPostLogoutLeavesExpiredSessionForCsrfProtectedLogoutHandler(): void
    {
        $issuedAt = time() - 60;
        $lastActivityAt = time() - AdminSessionLifetime::IDLE_TIMEOUT_SECONDS;
        $session = $this->session($issuedAt, $lastActivityAt);
        $tokenStorage = self::createMock(TokenStorageInterface::class);
        $tokenStorage->expects(self::never())->method('getToken');
        $tokenStorage->expects(self::never())->method('setToken');
        $event = $this->requestEvent($session, '/admin/logout', Request::METHOD_POST);

        new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime())->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertSame($issuedAt, $session->get(self::ISSUED_AT_SESSION_KEY));
        self::assertSame($lastActivityAt, $session->get(self::LAST_ACTIVITY_SESSION_KEY));
    }

    public function testAbsoluteTimeoutInvalidatesSessionAndAuthenticationToken(): void
    {
        $session = $this->session(
            time() - AdminSessionLifetime::ABSOLUTE_TIMEOUT_SECONDS,
            time() - 1,
        );
        $tokenStorage = $this->tokenStorage(true);
        $event = $this->requestEvent($session);

        new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime())->onKernelRequest($event);

        $this->assertExpired($event, $session, $tokenStorage);
    }

    public function testMissingSessionTimestampsInvalidateSessionAndAuthenticationToken(): void
    {
        $session = $this->session(null, null);
        $tokenStorage = $this->tokenStorage(true);
        $event = $this->requestEvent($session);

        new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime())->onKernelRequest($event);

        $this->assertExpired($event, $session, $tokenStorage);
    }

    public function testNonIntegerSessionTimestampsInvalidateSessionAndAuthenticationToken(): void
    {
        $now = time();

        foreach ([
            [self::ISSUED_AT_SESSION_KEY, (string) ($now - 60)],
            [self::LAST_ACTIVITY_SESSION_KEY, (string) ($now - 10)],
        ] as [$key, $value]) {
            $session = $this->session($now - 60, $now - 10);
            $session->set($key, $value);
            $tokenStorage = $this->tokenStorage(true);
            $event = $this->requestEvent($session);

            new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime())->onKernelRequest($event);

            $this->assertExpired($event, $session, $tokenStorage);
        }
    }

    public function testMissingRequestSessionClearsAuthenticationTokenAndRedirects(): void
    {
        $tokenStorage = $this->tokenStorage(true);
        $event = $this->requestEvent(null);

        new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime())->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/login', $response->getTargetUrl());
    }

    public function testRejectedAdminRequestDoesNotRefreshIdleTimestamp(): void
    {
        $issuedAt = time() - 60;
        $lastActivityAt = time() - 10;
        $session = $this->session($issuedAt, $lastActivityAt);
        $tokenStorage = $this->tokenStorage(false);
        $event = $this->requestEvent($session);
        $blockedResponse = new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
        $event->setResponse($blockedResponse);

        new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime())->onKernelRequest($event);

        self::assertSame($blockedResponse, $event->getResponse());
        self::assertSame($issuedAt, $session->get(self::ISSUED_AT_SESSION_KEY));
        self::assertSame($lastActivityAt, $session->get(self::LAST_ACTIVITY_SESSION_KEY));
    }

    public function testActiveAdminResponseRefreshesIdleTimestampOnlyAfterItCompletes(): void
    {
        $issuedAt = time() - 60;
        $lastActivityAt = time() - 10;
        $session = $this->session($issuedAt, $lastActivityAt);
        $tokenStorage = $this->tokenStorage(false, 2);
        $subscriber = new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime());
        $requestEvent = $this->requestEvent($session);

        $subscriber->onKernelRequest($requestEvent);

        self::assertNull($requestEvent->getResponse());
        self::assertSame($issuedAt, $session->get(self::ISSUED_AT_SESSION_KEY));
        self::assertSame($lastActivityAt, $session->get(self::LAST_ACTIVITY_SESSION_KEY));

        $subscriber->onKernelResponse($this->responseEvent($session, new Response()));

        self::assertSame($issuedAt, $session->get(self::ISSUED_AT_SESSION_KEY));
        $updatedLastActivityAt = $session->get(self::LAST_ACTIVITY_SESSION_KEY);
        self::assertIsInt($updatedLastActivityAt);
        self::assertGreaterThanOrEqual($lastActivityAt, $updatedLastActivityAt);
    }

    public function testAdminErrorResponsesDoNotRefreshIdleTimestamp(): void
    {
        foreach ([
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNAUTHORIZED,
            Response::HTTP_FORBIDDEN,
            Response::HTTP_NOT_FOUND,
            Response::HTTP_TOO_MANY_REQUESTS,
            Response::HTTP_INTERNAL_SERVER_ERROR,
            Response::HTTP_SERVICE_UNAVAILABLE,
        ] as $statusCode) {
            $issuedAt = time() - 60;
            $lastActivityAt = time() - 10;
            $session = $this->session($issuedAt, $lastActivityAt);
            $tokenStorage = self::createMock(TokenStorageInterface::class);
            $tokenStorage->expects(self::never())->method('getToken');
            $subscriber = new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime());

            $subscriber->onKernelResponse($this->responseEvent(
                $session,
                new Response('', $statusCode),
            ));

            self::assertSame($issuedAt, $session->get(self::ISSUED_AT_SESSION_KEY));
            self::assertSame($lastActivityAt, $session->get(self::LAST_ACTIVITY_SESSION_KEY));
        }
    }

    public function testResponseAfterAbsoluteSessionExpiryDoesNotReviveIdleTimestamp(): void
    {
        $issuedAt = time() - AdminSessionLifetime::ABSOLUTE_TIMEOUT_SECONDS;
        $lastActivityAt = time() - 10;
        $session = $this->session($issuedAt, $lastActivityAt);
        $tokenStorage = $this->tokenStorage(false);
        $subscriber = new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime());

        $subscriber->onKernelResponse($this->responseEvent($session, new Response()));

        self::assertSame($issuedAt, $session->get(self::ISSUED_AT_SESSION_KEY));
        self::assertSame($lastActivityAt, $session->get(self::LAST_ACTIVITY_SESSION_KEY));
    }

    public function testSuccessfulAdminRedirectCountsAsActivity(): void
    {
        $issuedAt = time() - 60;
        $lastActivityAt = time() - 10;
        $session = $this->session($issuedAt, $lastActivityAt);
        $tokenStorage = $this->tokenStorage(false);
        $subscriber = new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime());

        $subscriber->onKernelResponse($this->responseEvent(
            $session,
            new RedirectResponse('/admin/dashboard'),
            '/admin/settings',
            Request::METHOD_POST,
        ));

        $updatedLastActivityAt = $session->get(self::LAST_ACTIVITY_SESSION_KEY);
        self::assertIsInt($updatedLastActivityAt);
        self::assertGreaterThanOrEqual($lastActivityAt, $updatedLastActivityAt);
        self::assertSame($issuedAt, $session->get(self::ISSUED_AT_SESSION_KEY));
    }

    public function testSubrequestDoesNotRefreshIdleTimestamp(): void
    {
        $issuedAt = time() - 60;
        $lastActivityAt = time() - 10;
        $session = $this->session($issuedAt, $lastActivityAt);
        $tokenStorage = self::createMock(TokenStorageInterface::class);
        $tokenStorage->expects(self::never())->method('getToken');
        $subscriber = new AdminSessionLifecycleSubscriber($tokenStorage, new AdminSessionLifetime());

        $subscriber->onKernelResponse($this->responseEvent(
            $session,
            new Response(),
            requestType: HttpKernelInterface::SUB_REQUEST,
        ));

        self::assertSame($issuedAt, $session->get(self::ISSUED_AT_SESSION_KEY));
        self::assertSame($lastActivityAt, $session->get(self::LAST_ACTIVITY_SESSION_KEY));
    }

    private function session(?int $issuedAt, ?int $lastActivityAt): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        if (null !== $issuedAt) {
            $session->set(self::ISSUED_AT_SESSION_KEY, $issuedAt);
        }
        if (null !== $lastActivityAt) {
            $session->set(self::LAST_ACTIVITY_SESSION_KEY, $lastActivityAt);
        }

        return $session;
    }

    private function requestEvent(
        ?Session $session,
        string $path = '/admin/dashboard',
        string $method = Request::METHOD_GET,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): RequestEvent {
        $request = Request::create($path, $method);
        if ($session instanceof Session) {
            $request->setSession($session);
        }

        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            $requestType,
        );
    }

    private function responseEvent(
        ?Session $session,
        Response $response,
        string $path = '/admin/dashboard',
        string $method = Request::METHOD_GET,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): ResponseEvent {
        $request = Request::create($path, $method);
        if ($session instanceof Session) {
            $request->setSession($session);
        }

        return new ResponseEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            $requestType,
            $response,
        );
    }

    private function tokenStorage(bool $willExpire, int $expectedTokenReads = 1): TokenStorageInterface
    {
        $admin = new AdminUser('session-owner@example.test');
        $token = self::createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($admin);

        $tokenStorage = self::createMock(TokenStorageInterface::class);
        $tokenStorage->expects(self::exactly($expectedTokenReads))->method('getToken')->willReturn($token);
        if ($willExpire) {
            $tokenStorage->expects(self::once())->method('setToken')->with(null);
        } else {
            $tokenStorage->expects(self::never())->method('setToken');
        }

        return $tokenStorage;
    }

    private function assertExpired(RequestEvent $event, Session $session, TokenStorageInterface $tokenStorage): void
    {
        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/login', $response->getTargetUrl());
        self::assertNull($session->get(self::ISSUED_AT_SESSION_KEY));
        self::assertNull($session->get(self::LAST_ACTIVITY_SESSION_KEY));
    }
}
