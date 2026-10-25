<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final class RuntimeCapabilityProbe implements CapabilityProbeInterface
{
    /**
     * @return iterable<CapabilityEvidence>
     */
    public function probe(): iterable
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $phpIsSupported = PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 90000;

        yield new CapabilityEvidence(
            HostingCapability::PhpRuntime,
            $phpIsSupported ? CapabilityStatus::Verified : CapabilityStatus::Failed,
            self::class,
            $phpIsSupported
                ? sprintf('Die aktive PHP-Version %s erfüllt die Projektvorgabe.', PHP_VERSION)
                : sprintf('Die aktive PHP-Version %s erfüllt die Vorgabe PHP 8.5 oder neuer nicht.', PHP_VERSION),
            $checkedAt,
        );

        $extensions = [
            [HostingCapability::CtypeExtension, 'ctype'],
            [HostingCapability::IconvExtension, 'iconv'],
            [HostingCapability::IntlExtension, 'intl'],
            [HostingCapability::JsonExtension, 'json'],
            [HostingCapability::MbstringExtension, 'mbstring'],
            [HostingCapability::PdoExtension, 'PDO'],
            [HostingCapability::PdoPgsqlExtension, 'pdo_pgsql'],
            [HostingCapability::SodiumExtension, 'sodium'],
            [HostingCapability::ZipExtension, 'zip'],
        ];

        foreach ($extensions as [$capability, $extension]) {
            $isLoaded = extension_loaded($extension);

            yield new CapabilityEvidence(
                $capability,
                $isLoaded ? CapabilityStatus::Verified : CapabilityStatus::NotAvailable,
                self::class,
                $isLoaded
                    ? sprintf('Die erforderliche PHP-Erweiterung %s ist geladen.', $extension)
                    : sprintf('Die erforderliche PHP-Erweiterung %s ist nicht geladen.', $extension),
                $checkedAt,
            );
        }
    }
}
