<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final readonly class RedisCapabilityProbe implements CapabilityProbeInterface
{
    public function __construct(
        private string $redisDsn,
        private RedisServerVersionProviderInterface $versionProvider,
    ) {
    }

    /**
     * @return iterable<CapabilityEvidence>
     */
    public function probe(): iterable
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $privateEndpoint = $this->privateEndpointClassification();
        $version = null;

        if ('' !== trim($this->redisDsn)) {
            try {
                $version = $this->versionProvider->serverVersion($this->redisDsn);
            } catch (\Throwable) {
                $version = null;
            }
        }

        if (null === $version) {
            yield new CapabilityEvidence(
                HostingCapability::RedisServer8,
                CapabilityStatus::NotAvailable,
                self::class,
                'Der Redis-Dienst oder seine Versionsabfrage ist nicht verfügbar.',
                $checkedAt,
            );

            yield new CapabilityEvidence(
                HostingCapability::RedisPrivateEndpoint,
                $this->privateEndpointStatus($privateEndpoint, false),
                self::class,
                $this->privateEndpointDetails($privateEndpoint, false),
                $checkedAt,
            );

            return;
        }

        $isSupported = 1 === preg_match('/\A8(?:\.|-|\z)/', $version);

        yield new CapabilityEvidence(
            HostingCapability::RedisServer8,
            $isSupported ? CapabilityStatus::Verified : CapabilityStatus::Failed,
            self::class,
            $isSupported
                ? sprintf('Der Redis-Dienst meldet Version %s und erfüllt Redis 8.', $version)
                : 'Der Redis-Dienst erfüllt die Projektvorgabe Redis 8 nicht.',
            $checkedAt,
        );

        yield new CapabilityEvidence(
            HostingCapability::RedisPrivateEndpoint,
            $this->privateEndpointStatus($privateEndpoint, true),
            self::class,
            $this->privateEndpointDetails($privateEndpoint, true),
            $checkedAt,
        );
    }

    private function privateEndpointClassification(): ?bool
    {
        if ('' === trim($this->redisDsn)) {
            return null;
        }

        try {
            $parts = parse_url($this->redisDsn);
        } catch (\ValueError) {
            return null;
        }
        if (false === $parts
            || !in_array($parts['scheme'] ?? null, ['redis', 'rediss'], true)
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        $path = $parts['path'] ?? '';
        if ('' !== $path && 1 !== preg_match('/\A\/(?:0|[1-9][0-9]*)\z/', $path)) {
            return null;
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host) || '' === $host) {
            return null;
        }

        $address = trim($host, '[]');
        if (false === filter_var($address, FILTER_VALIDATE_IP)) {
            return null;
        }

        $packedAddress = inet_pton($address);
        if (false === $packedAddress) {
            return null;
        }

        if (4 === strlen($packedAddress)) {
            $firstOctet = ord($packedAddress[0]);
            $secondOctet = ord($packedAddress[1]);

            return 10 === $firstOctet
                || 127 === $firstOctet
                || (172 === $firstOctet && $secondOctet >= 16 && $secondOctet <= 31)
                || (192 === $firstOctet && 168 === $secondOctet);
        }

        if (16 !== strlen($packedAddress)) {
            return false;
        }

        $loopbackAddress = str_repeat("\0", 15)."\1";
        $firstByte = ord($packedAddress[0]);

        return $loopbackAddress === $packedAddress || 0xFC === ($firstByte & 0xFE);
    }

    private function privateEndpointStatus(?bool $isPrivate, bool $isReachable): CapabilityStatus
    {
        if (false === $isPrivate) {
            return CapabilityStatus::Failed;
        }

        if (null === $isPrivate) {
            return CapabilityStatus::Unknown;
        }

        return $isReachable ? CapabilityStatus::Verified : CapabilityStatus::NotAvailable;
    }

    private function privateEndpointDetails(?bool $isPrivate, bool $isReachable): string
    {
        if (false === $isPrivate) {
            return 'Der Redis-Endpunkt verwendet keine private oder lokale IP-Adresse.';
        }

        if (null === $isPrivate) {
            return 'Für diese Redis-DSN ist kein DNS-unabhängiger Nachweis einer privaten Zieladresse verfügbar.';
        }

        return $isReachable
            ? 'Die erfolgreiche Redis-Abfrage nutzte eine private IP-Adresse oder den lokalen Loopback-Endpunkt.'
            : 'Die konfigurierte private Redis-Zieladresse konnte nicht erfolgreich abgefragt werden.';
    }
}
