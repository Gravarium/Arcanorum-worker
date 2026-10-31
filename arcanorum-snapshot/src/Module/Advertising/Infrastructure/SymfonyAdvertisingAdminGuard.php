<?php

declare(strict_types=1);

namespace App\Module\Advertising\Infrastructure;

use App\Module\Advertising\Application\AdvertisingAccessDenied;
use App\Module\Advertising\Application\AdvertisingAdminGuard;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class SymfonyAdvertisingAdminGuard implements AdvertisingAdminGuard
{
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function assertCanManageAdvertising(): void
    {
        if (!$this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            throw new AdvertisingAccessDenied('Administrator access is required.');
        }
    }

    public function assertValidCsrfToken(string $token): void
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('admin_advertising_mutation', $token))) {
            throw new AdvertisingAccessDenied('A valid advertising CSRF token is required.');
        }
    }
}
