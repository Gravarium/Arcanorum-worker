<?php

declare(strict_types=1);

namespace App\Module\Member\Service;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Module\Feed\Service\CanonicalPublicUrlGenerator;
use App\Module\Settings\Service\EmailDeliveryConfiguration;
use App\Service\SiteSettingsProvider;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class VideoEmailShareSender
{
    public function __construct(
        #[AutowireServiceClosure(service: 'mailer.mailer')]
        private \Closure $mailerFactory,
        private EmailDeliveryConfiguration $deliveryConfiguration,
        private CanonicalPublicUrlGenerator $publicUrls,
        private SiteSettingsProvider $siteSettings,
        private TranslatorInterface $translator,
    ) {
    }

    public function isReady(): bool
    {
        return $this->deliveryConfiguration->isReady() && $this->publicUrls->isConfigured();
    }

    public function send(Video $video, string $recipient): void
    {
        if (!$this->isReady()) {
            throw new \RuntimeException('Der Video-E-Mail-Versand ist nicht verfügbar.');
        }
        if (PublishingStatus::Published !== $video->getStatus() || null === $video->getPublishedAt()) {
            throw new \LogicException('Nur veröffentlichte Videos können per E-Mail geteilt werden.');
        }
        if (strlen($recipient) > 254
            || 1 !== preg_match('//u', $recipient)
            || 1 === preg_match('/[\x00-\x20\x7F]/', $recipient)
            || false === filter_var($recipient, FILTER_VALIDATE_EMAIL)
        ) {
            throw new \InvalidArgumentException('Die Empfängeradresse ist ungültig.');
        }

        $site = $this->siteSettings->current();
        $fromAddress = $this->deliveryConfiguration->fromAddress();
        if (null === $fromAddress) {
            throw new \RuntimeException('Der Video-E-Mail-Versand ist nicht verfügbar.');
        }

        $videoUrl = $this->publicUrls->generate('video_show', ['id' => $video->getId()]);
        $locale = $site->getDefaultLocale();
        $email = new Email()
            ->from(new Address($fromAddress, $site->getSiteName()))
            ->to(new Address($recipient))
            ->subject($this->translator->trans('Ein Video wurde mit dir geteilt', [], 'messages', $locale))
            ->text(
                $this->translator->trans('Ein Mitglied hat ein öffentliches Video mit dir geteilt:', [], 'messages', $locale)
                ."\n\n"
                .$videoUrl
                ."\n\n"
                .$this->translator->trans('Diese Nachricht wurde auf deine ausdrückliche Anfrage hin versendet. Die Empfängeradresse wird nicht im CMS gespeichert.', [], 'messages', $locale),
            );

        $mailer = ($this->mailerFactory)();
        if (!$mailer instanceof MailerInterface) {
            throw new \RuntimeException('Der Video-E-Mail-Versand ist nicht verfügbar.');
        }

        $mailer->send($email);
    }
}
