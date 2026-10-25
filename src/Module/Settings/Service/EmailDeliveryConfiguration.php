<?php

declare(strict_types=1);

namespace App\Module\Settings\Service;

use App\Service\SiteSettingsProvider;
use Symfony\Component\Mailer\Transport;

final readonly class EmailDeliveryConfiguration
{
    private const array SUPPORTED_TRANSPORT_SCHEMES = ['smtp', 'smtps', 'sendmail'];

    public function __construct(
        private string $mailerDsn,
        private string $fallbackFromAddress,
        private SiteSettingsProvider $siteSettings,
    ) {
    }

    public function hasTransport(): bool
    {
        $dsn = trim($this->mailerDsn);
        if ('' === $dsn || 1 === preg_match('/[\x00-\x1F\x7F]/', $dsn)) {
            return false;
        }

        try {
            // Symfony parses composite failover/round-robin DSNs itself. Constructing
            // this transport validates configuration but does not open a connection.
            $transport = Transport::fromDsn($dsn);
        } catch (\Throwable) {
            return false;
        }

        $normalizedDsn = (string) $transport;
        $schemeCount = preg_match_all('/(?<![A-Za-z0-9.+-])([a-z][a-z0-9+.-]*):\/\//i', $normalizedDsn, $matches);
        if (false === $schemeCount || 0 === $schemeCount) {
            return false;
        }

        return array_all(
            $matches[1],
            static fn (string $scheme): bool => in_array(strtolower($scheme), self::SUPPORTED_TRANSPORT_SCHEMES, true),
        );
    }

    public function fromAddress(): ?string
    {
        $configured = $this->siteSettings->current()->getEmailFromAddress();
        $address = '' !== $configured ? $configured : trim($this->fallbackFromAddress);
        if ('' === $address
            || strlen($address) > 254
            || 1 !== preg_match('//u', $address)
            || 1 === preg_match('/[\x00-\x20\x7F]/', $address)
            || false === filter_var($address, FILTER_VALIDATE_EMAIL)
        ) {
            return null;
        }

        return $address;
    }

    public function isReady(): bool
    {
        return $this->hasTransport() && null !== $this->fromAddress();
    }
}
