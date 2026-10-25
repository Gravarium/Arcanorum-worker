<?php

declare(strict_types=1);

namespace App\Module\Settings\Twig;

use App\Service\CookieConsentService;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CookieConsentExtension extends AbstractExtension
{
    public function __construct(
        private readonly CookieConsentService $consent,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cookie_consent_needs_choice', $this->consent->needsChoice(...)),
            new TwigFunction('cookie_consent_csrf_token', $this->csrfToken(...)),
        ];
    }

    public function csrfToken(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            throw new \LogicException('Für Cookie-Einwilligungstoken muss ein HTTP-Request aktiv sein.');
        }

        return $this->consent->csrfToken($request);
    }
}
