<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\HttpFoundation\Request;

final readonly class RuntimeAttestationRequestPolicy
{
    /** @var list<string> */
    private const array ADMIN_ACCESS_CONTROL_IDS = [
        'INV-AUTH-001',
        'INV-SESSION-001',
        'INV-APP-INTEGRITY-001',
        'INV-WEBSERVER-CONFIG-001',
    ];

    /** @var list<string> */
    private const array STATE_CHANGING_CONTROL_IDS = [
        'INV-CSRF-001',
        'INV-INPUT-OUTPUT-001',
    ];

    /** @var list<string> */
    private const array TRUSTED_PROXY_CONTROL_IDS = [
        'INV-TRUSTED-PROXY-001',
    ];

    /**
     * @return list<string>
     */
    public function allAttestedControlIds(): array
    {
        return array_merge(
            self::ADMIN_ACCESS_CONTROL_IDS,
            self::STATE_CHANGING_CONTROL_IDS,
            self::TRUSTED_PROXY_CONTROL_IDS,
        );
    }

    /**
     * @return list<string>|null null bedeutet, dass die Anfrage nicht von dieser Richtlinie erfasst wird
     */
    public function requiredControlIdsFor(Request $request): ?array
    {
        $rawPath = $request->getPathInfo();
        // Symfony's UrlMatcher decodes the path before matching; mirror it so encoded admin paths cannot bypass this guard.
        $path = AdminAreaRequestPath::decode($rawPath);
        $isAdminPath = AdminAreaRequestPath::isAdminArea($path);

        if (!$isAdminPath) {
            return null;
        }

        if (
            '/admin/login' === $path
            && in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD], true)
        ) {
            return null;
        }

        if ('/admin/logout' === $rawPath && Request::METHOD_POST === $request->getMethod()) {
            return null;
        }

        $requiredControlIds = self::ADMIN_ACCESS_CONTROL_IDS;

        if (!in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD, Request::METHOD_OPTIONS], true)) {
            $requiredControlIds = array_merge($requiredControlIds, self::STATE_CHANGING_CONTROL_IDS);
        }

        if ('/admin/login' === $path && Request::METHOD_POST === $request->getMethod()) {
            $requiredControlIds = array_merge($requiredControlIds, self::TRUSTED_PROXY_CONTROL_IDS);
        }

        return $requiredControlIds;
    }
}
