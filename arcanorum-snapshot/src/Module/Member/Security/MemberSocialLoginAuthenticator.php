<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/** Used only after a social provider's state, PKCE, and profile checks complete. */
final class MemberSocialLoginAuthenticator extends AbstractAuthenticator implements InteractiveAuthenticatorInterface
{
    public function supports(Request $request): ?bool
    {
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        throw new \LogicException('Die soziale Anmeldung wird ausschließlich über den geprüften manuellen Ablauf ausgelöst.');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new Response('', Response::HTTP_UNAUTHORIZED);
    }

    public function isInteractive(): bool
    {
        return true;
    }
}
