<?php

declare(strict_types=1);

namespace App\Platform\Security;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

final class AdminMfaLoginAuthenticator extends AbstractLoginFormAuthenticator
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AdminUserRepository $users,
        private readonly AdminMfaLoginVerifier $mfaLoginVerifier,
    ) {
    }

    public function supports(Request $request): bool
    {
        return 'admin_login' === $request->attributes->get('_route') && $request->isMethod(Request::METHOD_POST);
    }

    public function authenticate(Request $request): Passport
    {
        $payload = $request->getPayload();
        $username = $payload->get('_username');
        $password = $payload->get('_password');
        $providedCode = $payload->get('_mfa_code');
        $csrfToken = $payload->get('_csrf_token');

        $username = is_string($username) ? trim($username) : '';
        $password = is_string($password) ? $password : '';
        $providedCode = is_string($providedCode) ? trim($providedCode) : '';
        $csrfToken = is_string($csrfToken) ? $csrfToken : '';

        if (strlen($username) > 180 || strlen($password) > 4096 || strlen($providedCode) > 64) {
            throw new \Symfony\Component\Security\Core\Exception\BadCredentialsException('Ungültige Anmeldedaten.');
        }

        if ('' === $csrfToken || !$this->csrfTokenManager->isTokenValid(new CsrfToken('authenticate', $csrfToken))) {
            throw new InvalidCsrfTokenException('Invalid CSRF token.');
        }

        return new Passport(
            new UserBadge($username),
            new CustomCredentials(
                function (mixed $credentials, UserInterface $user) use ($password): bool {
                    if (!$user instanceof AdminUser || !is_string($credentials)) {
                        return false;
                    }

                    try {
                        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
                            return false;
                        }

                        return $this->mfaLoginVerifier->verifyAndConsume($user, $credentials);
                    } catch (\Throwable) {
                        return false;
                    }
                },
                $providedCode,
            ),
            [
                new CsrfTokenBadge('authenticate', $csrfToken),
                new PasswordUpgradeBadge($password, $this->users),
            ],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return new RedirectResponse($this->urlGenerator->generate('admin_dashboard'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('admin_login');
    }
}
