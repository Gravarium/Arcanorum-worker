<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\RuntimeAttestationRequestPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RuntimeAttestationRequestPolicyTest extends TestCase
{
    public function testPublicLoginPageDoesNotRequireRuntimeAttestation(): void
    {
        self::assertNull(new RuntimeAttestationRequestPolicy()->requiredControlIdsFor(Request::create('/admin/login')));
    }

    public function testCsrfProtectedPostLogoutDoesNotRequireRuntimeAttestation(): void
    {
        self::assertNull(
            new RuntimeAttestationRequestPolicy()->requiredControlIdsFor(
                Request::create('/admin/logout', Request::METHOD_POST),
            ),
        );
    }

    public function testOtherLogoutMethodsStillRequireRuntimeAttestation(): void
    {
        self::assertSame(
            [
                'INV-AUTH-001',
                'INV-SESSION-001',
                'INV-APP-INTEGRITY-001',
                'INV-WEBSERVER-CONFIG-001',
            ],
            new RuntimeAttestationRequestPolicy()->requiredControlIdsFor(
                Request::create('/admin/logout', Request::METHOD_GET),
            ),
        );
    }

    public function testLogoutNearMissPathStillRequiresRuntimeAttestation(): void
    {
        self::assertSame(
            [
                'INV-AUTH-001',
                'INV-SESSION-001',
                'INV-APP-INTEGRITY-001',
                'INV-WEBSERVER-CONFIG-001',
                'INV-CSRF-001',
                'INV-INPUT-OUTPUT-001',
            ],
            new RuntimeAttestationRequestPolicy()->requiredControlIdsFor(
                Request::create('/admin/logout/', Request::METHOD_POST),
            ),
        );
    }

    public function testEncodedAdminPathStillRequiresRuntimeAttestation(): void
    {
        self::assertSame(
            [
                'INV-AUTH-001',
                'INV-SESSION-001',
                'INV-APP-INTEGRITY-001',
                'INV-WEBSERVER-CONFIG-001',
            ],
            new RuntimeAttestationRequestPolicy()->requiredControlIdsFor(
                Request::create('/admin%2Fdashboard', Request::METHOD_GET),
            ),
        );
    }

    public function testEncodedAdminPrefixStillRequiresRuntimeAttestation(): void
    {
        self::assertSame(
            [
                'INV-AUTH-001',
                'INV-SESSION-001',
                'INV-APP-INTEGRITY-001',
                'INV-WEBSERVER-CONFIG-001',
            ],
            new RuntimeAttestationRequestPolicy()->requiredControlIdsFor(
                Request::create('/%61dmin/dashboard', Request::METHOD_GET),
            ),
        );
    }

    public function testEncodedLogoutPathDoesNotUseExactPostExemption(): void
    {
        self::assertSame(
            [
                'INV-AUTH-001',
                'INV-SESSION-001',
                'INV-APP-INTEGRITY-001',
                'INV-WEBSERVER-CONFIG-001',
                'INV-CSRF-001',
                'INV-INPUT-OUTPUT-001',
            ],
            new RuntimeAttestationRequestPolicy()->requiredControlIdsFor(
                Request::create('/admin%2Flogout', Request::METHOD_POST),
            ),
        );
    }

    public function testAdminReadRequiresAuthenticationSessionAndIntegrityControls(): void
    {
        self::assertSame(
            [
                'INV-AUTH-001',
                'INV-SESSION-001',
                'INV-APP-INTEGRITY-001',
                'INV-WEBSERVER-CONFIG-001',
            ],
            new RuntimeAttestationRequestPolicy()->requiredControlIdsFor(Request::create('/admin')),
        );
    }

    public function testAdminMutationAlsoRequiresCsrfAndInputOutputControls(): void
    {
        self::assertSame(
            [
                'INV-AUTH-001',
                'INV-SESSION-001',
                'INV-APP-INTEGRITY-001',
                'INV-WEBSERVER-CONFIG-001',
                'INV-CSRF-001',
                'INV-INPUT-OUTPUT-001',
            ],
            new RuntimeAttestationRequestPolicy()->requiredControlIdsFor(
                Request::create('/admin/settings', Request::METHOD_POST),
            ),
        );
    }

    public function testLoginSubmissionAlsoRequiresTrustedProxyControlForClientIpRateLimits(): void
    {
        self::assertSame(
            [
                'INV-AUTH-001',
                'INV-SESSION-001',
                'INV-APP-INTEGRITY-001',
                'INV-WEBSERVER-CONFIG-001',
                'INV-CSRF-001',
                'INV-INPUT-OUTPUT-001',
                'INV-TRUSTED-PROXY-001',
            ],
            new RuntimeAttestationRequestPolicy()->requiredControlIdsFor(
                Request::create('/admin/login', Request::METHOD_POST),
            ),
        );
    }

    public function testAttestorReceivesEveryControlUsedByTheAdminRequestPolicy(): void
    {
        self::assertSame(
            [
                'INV-AUTH-001',
                'INV-SESSION-001',
                'INV-APP-INTEGRITY-001',
                'INV-WEBSERVER-CONFIG-001',
                'INV-CSRF-001',
                'INV-INPUT-OUTPUT-001',
                'INV-TRUSTED-PROXY-001',
            ],
            new RuntimeAttestationRequestPolicy()->allAttestedControlIds(),
        );
    }

    public function testRequestOutsideAdminAreaIsNotMatched(): void
    {
        self::assertNull(
            new RuntimeAttestationRequestPolicy()->requiredControlIdsFor(Request::create('/')),
        );
    }
}
