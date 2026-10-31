<?php

declare(strict_types=1);

namespace App\Module\Comment\Twig;

use App\Module\Feed\Service\CanonicalPublicUrlGenerator;
use App\Service\CookieConsentService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CommentProviderExtension extends AbstractExtension
{
    public function __construct(
        private readonly CookieConsentService $consent,
        private readonly CanonicalPublicUrlGenerator $canonicalUrls,
    ) {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('comment_provider_external_allowed', $this->consent->allowsOptionalServices(...)),
            new TwigFunction('comment_provider_public_url', $this->publicUrl(...)),
        ];
    }

    /** @param array<string, mixed> $parameters */
    public function publicUrl(string $route, array $parameters): ?string
    {
        if (!$this->canonicalUrls->isConfigured()) {
            return null;
        }

        $validParameters = match ($route) {
            'news_show' => is_string($parameters['slug'] ?? null)
                && 1 === preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $parameters['slug']),
            'video_show' => is_string($parameters['id'] ?? null)
                && 1 === preg_match('/\A[a-f0-9]{32}\z/D', $parameters['id']),
            default => false,
        };
        if (!$validParameters) {
            return null;
        }

        try {
            return $this->canonicalUrls->generate($route, $parameters);
        } catch (\LogicException) {
            return null;
        }
    }
}
