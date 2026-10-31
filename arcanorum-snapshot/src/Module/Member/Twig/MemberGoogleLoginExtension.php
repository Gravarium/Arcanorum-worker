<?php

declare(strict_types=1);

namespace App\Module\Member\Twig;

use App\Entity\MemberAccount;
use App\Module\Member\Security\GoogleOAuthClient;
use App\Repository\MemberExternalIdentityRepository;
use App\Service\SiteSettingsProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MemberGoogleLoginExtension extends AbstractExtension
{
    public function __construct(
        private readonly GoogleOAuthClient $google,
        private readonly SiteSettingsProvider $settings,
        private readonly MemberExternalIdentityRepository $identities,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('google_social_login_configured', $this->google->isConfigured(...)),
            new TwigFunction('google_social_login_available', $this->isAvailable(...)),
            new TwigFunction('member_google_identity_linked', $this->memberIdentityLinked(...)),
        ];
    }

    public function isAvailable(): bool
    {
        return $this->google->isConfigured() && $this->settings->current()->isGoogleSocialLoginEnabled();
    }

    public function memberIdentityLinked(): bool
    {
        $member = $this->security->getUser();

        return $member instanceof MemberAccount
            && null !== $this->identities->findForMember($member, 'google');
    }
}
