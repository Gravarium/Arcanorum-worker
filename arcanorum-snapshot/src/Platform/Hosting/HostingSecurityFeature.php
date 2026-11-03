<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

enum HostingSecurityFeature: string
{
    case PrivateStorage = 'private_storage';
    case DatabaseRoleSeparation = 'database_role_separation';
    case PrivateRedisEndpoint = 'private_redis_endpoint';
    case ProcessIsolation = 'process_isolation';
    case OutboundNetworkPolicy = 'outbound_network_policy';
    case SecretStore = 'secret_store';
    case VerifiedBackupRestore = 'verified_backup_restore';

    public function label(): string
    {
        return match ($this) {
            self::PrivateStorage => 'Privater Dateispeicher',
            self::DatabaseRoleSeparation => 'Getrennte Datenbankrollen',
            self::PrivateRedisEndpoint => 'Private Redis-Anbindung',
            self::ProcessIsolation => 'Prozessisolation',
            self::OutboundNetworkPolicy => 'Regeln für ausgehende Verbindungen',
            self::SecretStore => 'Externer Geheimnisspeicher',
            self::VerifiedBackupRestore => 'Geprüfte Sicherung und Wiederherstellung',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PrivateStorage => 'Private Uploads außerhalb des öffentlichen Webpfads ablegen.',
            self::DatabaseRoleSeparation => 'Laufzeit, Verwaltung und Migrationen mit getrennten Datenbankrollen ausführen.',
            self::PrivateRedisEndpoint => 'Redis über einen nachweislich privaten Endpunkt verwenden.',
            self::ProcessIsolation => 'Öffentliche Anfragen, Verwaltung und Hintergrundarbeit auf Prozessebene trennen.',
            self::OutboundNetworkPolicy => 'Ausgehende Verbindungen des Hosts auf benötigte Ziele begrenzen.',
            self::SecretStore => 'Zugangsdaten aus einem getrennten Geheimnisspeicher beziehen.',
            self::VerifiedBackupRestore => 'Sicherungen getrennt speichern und eine Wiederherstellung regelmäßig prüfen.',
        };
    }

    /**
     * @return non-empty-list<HostingCapability>
     */
    public function requiredCapabilities(): array
    {
        return match ($this) {
            self::PrivateStorage => [HostingCapability::PrivateStorage],
            self::DatabaseRoleSeparation => [HostingCapability::DatabaseRoleSeparation],
            self::PrivateRedisEndpoint => [HostingCapability::RedisServer8, HostingCapability::RedisPrivateEndpoint],
            self::ProcessIsolation => [HostingCapability::ProcessIsolation],
            self::OutboundNetworkPolicy => [HostingCapability::OutboundNetworkPolicy],
            self::SecretStore => [HostingCapability::SecretStore],
            self::VerifiedBackupRestore => [HostingCapability::VerifiedBackupRestore],
        };
    }
}
