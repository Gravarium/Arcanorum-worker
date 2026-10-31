<?php

declare(strict_types=1);

namespace App\Module\Member\Twig;

use App\Entity\MemberAccount;
use App\Module\Member\Security\FacebookOAuthClient;
use App\Module\Member\Security\TwitterOAuthClient;
use App\Repository\MemberExternalIdentityRepository;
use App\Service\SiteSettingsProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MemberExternalLoginExtension extends AbstractExtension
{
    public function __construct(
        private readonly FacebookOAuthClient $facebook,
        private readonly TwitterOAuthClient $twitter,
        private readonly SiteSettingsProvider $settings,
        private readonly MemberExternalIdentityRepository $identities,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('facebook_social_login_configured', $this->facebook->isConfigured(...)),
            new TwigFunction('twitter_social_login_configured', $this->twitter->isConfigured(...)),
            new TwigFunction('social_login_available', $this->isAvailable(...)),
            new TwigFunction('member_social_identity_linked', $this->memberIdentityLinked(...)),
        ];
    }

    public function isAvailable(string $provider): bool
    {
        $current = $this->settings->current();

        return match ($provider) {
            'facebook' => $this->facebook->isConfigured() && $current->isFacebookSocialLoginEnabled(),
            'twitter' => $this->twitter->isConfigured() && $current->isTwitterSocialLoginEnabled(),
            default => false,
        };
    }

    public function memberIdentityLinked(string $provider): bool
    {
        if (!in_array($provider, ['facebook', 'twitter'], true)) {
            return false;
        }

        $member = $this->security->getUser();

        return $member instanceof MemberAccount
            && null !== $this->identities->findForMember($member, $provider);
    }
}
