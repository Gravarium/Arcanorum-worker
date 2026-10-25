<?php

declare(strict_types=1);

namespace App\Module\Settings\Service;

use App\Entity\AdminUser;
use App\Service\SiteSettingsProvider;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final readonly class AdminEmailTestSender
{
    public function __construct(
        private MailerInterface $mailer,
        private EmailDeliveryConfiguration $configuration,
        private SiteSettingsProvider $siteSettings,
    ) {
    }

    public function sendToAdministrator(AdminUser $administrator): void
    {
        $fromAddress = $this->configuration->fromAddress();
        $recipient = $administrator->getUserIdentifier();
        if (!$this->configuration->hasTransport()
            || null === $fromAddress
            || false === filter_var($recipient, FILTER_VALIDATE_EMAIL)
        ) {
            throw new \LogicException('Der E-Mail-Versand ist nicht sicher konfiguriert.');
        }

        $siteName = $this->siteSettings->current()->getSiteName();
        $email = new Email()
            ->from(new Address($fromAddress, $siteName))
            ->to($recipient)
            ->subject('Testnachricht für '.$siteName)
            ->text('Diese Testnachricht bestätigt, dass der E-Mail-Versand von '.$siteName." eingerichtet ist.\n\nEs wurden keine Kontodaten oder Anmeldeinformationen angehängt.");

        $this->mailer->send($email);
    }
}
