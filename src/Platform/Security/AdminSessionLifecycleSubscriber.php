<?php

declare(strict_types=1);

namespace App\Platform\Security;

use App\Entity\AdminUser;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final readonly class AdminSessionLifecycleSubscriber implements EventSubscriberInterface
{
    private const string ISSUED_AT_SESSION_KEY = '_arcanorum_admin_authenticated_at';
    private const string LAST_ACTIVITY_SESSION_KEY = '_arcanorum_admin_last_activity_at';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private AdminSessionLifetime $sessionLifetime,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            KernelEvents::REQUEST => ['onKernelRequest', 0],
            // Vor Symfony's SessionListener ausführen, damit die Änderung gespeichert wird.
            KernelEvents::RESPONSE => ['onKernelResponse', 10],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$event->getUser() instanceof AdminUser) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $now = time();

        $session->set(self::ISSUED_AT_SESSION_KEY, $now);
        $session->set(self::LAST_ACTIVITY_SESSION_KEY, $now);
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $rawPath = $request->getPathInfo();
        // Symfony's UrlMatcher decodes before matching. Mirror that behavior for encoded admin
        // paths so they cannot bypass the session guard.
        $path = AdminAreaRequestPath::decode($rawPath);

        $isLogoutRequest = '/admin/logout' === $rawPath
            && $request->isMethod(Request::METHOD_POST);

        if (
            !AdminAreaRequestPath::isAdminArea($path)
            || '/admin/login' === $path
            || $isLogoutRequest
        ) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (null === $token || !($token->getUser() instanceof AdminUser)) {
            return;
        }

        if (!$request->hasSession()) {
            $this->expireSession($event);

            return;
        }

        $session = $request->getSession();
        $issuedAt = $session->get(self::ISSUED_AT_SESSION_KEY);
        $lastActivityAt = $session->get(self::LAST_ACTIVITY_SESSION_KEY);
        $now = time();

        if (
            !is_int($issuedAt)
            || !is_int($lastActivityAt)
            || $this->sessionLifetime->isExpired($issuedAt, $lastActivityAt, $now)
        ) {
            $this->expireSession($event);

            return;
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getResponse()->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            return;
        }

        $request = $event->getRequest();
        $rawPath = $request->getPathInfo();
        $path = AdminAreaRequestPath::decode($rawPath);
        $isLogoutRequest = '/admin/logout' === $rawPath
            && $request->isMethod(Request::METHOD_POST);

        if (
            !AdminAreaRequestPath::isAdminArea($path)
            || '/admin/login' === $path
            || $isLogoutRequest
        ) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (null === $token || !($token->getUser() instanceof AdminUser)) {
            return;
        }

        $session = $request->getSession();
        $issuedAt = $session->get(self::ISSUED_AT_SESSION_KEY);
        $lastActivityAt = $session->get(self::LAST_ACTIVITY_SESSION_KEY);
        $now = time();

        if (
            !is_int($issuedAt)
            || !is_int($lastActivityAt)
            || $this->sessionLifetime->isExpired($issuedAt, $lastActivityAt, $now)
        ) {
            return;
        }

        $session->set(self::LAST_ACTIVITY_SESSION_KEY, $now);
    }

    private function expireSession(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        $this->tokenStorage->setToken(null);
        $event->setResponse(new RedirectResponse('/admin/login'));
    }
}
