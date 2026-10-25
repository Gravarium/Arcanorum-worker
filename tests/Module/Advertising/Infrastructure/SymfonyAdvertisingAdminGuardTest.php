<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Infrastructure;

use App\Module\Advertising\Application\AdvertisingAccessDenied;
use App\Module\Advertising\Infrastructure\SymfonyAdvertisingAdminGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[CoversClass(SymfonyAdvertisingAdminGuard::class)]
final class SymfonyAdvertisingAdminGuardTest extends TestCase
{
    public function testNonAdministratorCannotReadOrMutateAdvertisements(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects(self::once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);
        $tokens = $this->createMock(CsrfTokenManagerInterface::class);
        $tokens->expects(self::never())->method('isTokenValid');
        $guard = new SymfonyAdvertisingAdminGuard($authorization, $tokens);

        $this->expectException(AdvertisingAccessDenied::class);
        $guard->assertCanManageAdvertising();
    }

    public function testMutationRequiresAdvertisingCsrfToken(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);
        $tokens = $this->createMock(CsrfTokenManagerInterface::class);
        $tokens->expects(self::once())->method('isTokenValid')->with(self::callback(
            static fn (CsrfToken $token): bool => 'admin_advertising_mutation' === $token->getId() && 'invalid-token' === $token->getValue(),
        ))->willReturn(false);
        $guard = new SymfonyAdvertisingAdminGuard($authorization, $tokens);

        $this->expectException(AdvertisingAccessDenied::class);
        $guard->assertValidCsrfToken('invalid-token');
    }

    public function testAdministratorWithValidCsrfTokenCanManageAdvertisements(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects(self::once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);
        $tokens = $this->createMock(CsrfTokenManagerInterface::class);
        $tokens->expects(self::once())->method('isTokenValid')->with(self::callback(
            static fn (CsrfToken $token): bool => 'admin_advertising_mutation' === $token->getId() && 'valid-token' === $token->getValue(),
        ))->willReturn(true);
        $guard = new SymfonyAdvertisingAdminGuard($authorization, $tokens);

        $guard->assertCanManageAdvertising();
        $guard->assertValidCsrfToken('valid-token');
    }
}
