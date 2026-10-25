<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

use App\Entity\MemberAccount;
use App\Module\Settings\Service\EmailDeliveryConfiguration;
use App\Service\SiteSettingsProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class SymfonyMemberSecurityEmailSender implements MemberSecurityEmailSenderInterface
{
    public function __construct(
        #[AutowireServiceClosure(service: 'mailer.mailer')]
        private \Closure $mailerFactory,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(string:PUBLIC_BASE_URL)%')]
        private string $publicBaseUrl,
        private EmailDeliveryConfiguration $deliveryConfiguration,
        private SiteSettingsProvider $siteSettings,
        private TranslatorInterface $translator,
    ) {
    }

    public function send(MemberAccount $member, MemberEmailChallengePurpose $purpose, string $token): void
    {
        if (1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token)) {
            throw new \InvalidArgumentException('Der Sicherheitslink ist ungültig.');
        }

        $route = match ($purpose) {
            MemberEmailChallengePurpose::EmailVerification => 'member_email_verify',
            MemberEmailChallengePurpose::PasswordReset => 'member_password_reset',
        };
        $path = $this->urlGenerator->generate($route, ['token' => $token], UrlGeneratorInterface::ABSOLUTE_PATH);
        $link = $this->publicOrigin().$path;
        $site = $this->siteSettings->current();
        $siteName = $site->getSiteName();
        $locale = $site->getDefaultLocale();
        $tagline = trim($site->getTagline());
        $brandLine = '' === $tagline ? '' : $tagline."\n\n";
        $fromAddress = $this->deliveryConfiguration->fromAddress();
        if (!$this->deliveryConfiguration->hasTransport() || null === $fromAddress) {
            throw new \RuntimeException('Der E-Mail-Versand ist nicht sicher konfiguriert.');
        }
        $email = match ($purpose) {
            MemberEmailChallengePurpose::EmailVerification => new Email()
                ->subject($this->translator->trans('E-Mail-Adresse für %site_name% bestätigen', ['%site_name%' => $siteName], 'messages', $locale))
                ->text($brandLine.$this->translator->trans('Öffne diesen Link, um deine E-Mail-Adresse zu bestätigen:', [], 'messages', $locale)."\n\n".$link."\n\n".$this->translator->trans('Der Link läuft nach einer Stunde ab und kann nur einmal verwendet werden.', [], 'messages', $locale)),
            MemberEmailChallengePurpose::PasswordReset => new Email()
                ->subject($this->translator->trans('Passwort für %site_name% zurücksetzen', ['%site_name%' => $siteName], 'messages', $locale))
                ->text($brandLine.$this->translator->trans('Öffne diesen Link, um ein neues Passwort zu setzen:', [], 'messages', $locale)."\n\n".$link."\n\n".$this->translator->trans('Der Link läuft nach 30 Minuten ab und kann nur einmal verwendet werden. Falls du diese Anfrage nicht gestellt hast, kannst du diese Nachricht ignorieren.', [], 'messages', $locale)),
        };

        $email
            ->from(new Address($fromAddress, $siteName))
            ->to($member->getEmail());

        $mailer = ($this->mailerFactory)();
        if (!$mailer instanceof MailerInterface) {
            throw new \RuntimeException('Der Mailversand ist nicht verfügbar.');
        }

        $mailer->send($email);
    }

    private function publicOrigin(): string
    {
        if ('' === $this->publicBaseUrl
            || str_contains($this->publicBaseUrl, '\\')
            || 1 === preg_match('/[\x00-\x20]/', $this->publicBaseUrl)
            || false === filter_var($this->publicBaseUrl, FILTER_VALIDATE_URL)
        ) {
            throw new \RuntimeException('Die öffentliche HTTPS-Basisadresse ist nicht gültig konfiguriert.');
        }

        $parts = parse_url($this->publicBaseUrl);
        if (!is_array($parts)) {
            throw new \RuntimeException('Die öffentliche HTTPS-Basisadresse ist nicht gültig konfiguriert.');
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;
        if (!is_string($scheme)
            || 'https' !== strtolower($scheme)
            || !is_string($host)
            || '' === $host
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (!is_int($port) || $port < 1))
        ) {
            throw new \RuntimeException('Die öffentliche HTTPS-Basisadresse ist nicht gültig konfiguriert.');
        }

        return rtrim($this->publicBaseUrl, '/');
    }
}
